<?php

declare(strict_types=1);

namespace LogRelay\LogRelay;

use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use WeakMap;

use function Illuminate\Support\defer;

class HttpHandler extends AbstractProcessingHandler
{
    private string $url;

    /** @var WeakMap<DeferredCallbackCollection, bool> Presence means warned; true also suppresses later POSTs. */
    private WeakMap $failures;

    public function __construct(
        string $url = '',
        private string $token = '',
        private string $app = '',
        private bool $async = true,
        private int $timeout = 1,
        Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        $this->url = trim($url);
        $this->failures = new WeakMap;
    }

    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        // The wire payload uses structured fields, never Monolog's formatted line.
        if ($this->url !== '') {
            $this->write($this->processRecord($record));
        }

        return ! $this->bubble;
    }

    protected function write(LogRecord $record): void
    {
        $scope = defer();

        if ($this->failures[$scope] ?? false) {
            return;
        }

        if ($this->async) {
            defer(fn () => $this->send($record, $scope), always: true);

            return;
        }

        $this->send($record, $scope);
    }

    private function send(LogRecord $record, DeferredCallbackCollection $scope): void
    {
        if ($this->failures[$scope] ?? false) {
            return;
        }

        try {
            $request = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson();

            if ($this->token !== '') {
                $request = $request->withToken($this->token);
            }

            $response = $request->post($this->url, $this->payload($record));

            if ($response->successful()) {
                return;
            }

            $status = $response->status();
            $this->failed($scope, "HTTP {$status}", $response->serverError() || in_array($status, [401, 403, 429], true));
        } catch (ConnectionException) {
            $this->failed($scope, 'connection failure or timeout', true);
        } catch (Throwable) {
            $this->failed($scope, 'could not send record', false);
        }
    }

    private function failed(DeferredCallbackCollection $scope, string $reason, bool $stop): void
    {
        $warned = isset($this->failures[$scope]);
        $this->failures[$scope] = $stop;

        if (! $warned) {
            // Do not recurse through the host's log stack or disclose the payload or token.
            error_log("Log relay: {$reason}. Check the local application log for original records.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(LogRecord $record): array
    {
        $normalizer = new NormalizerFormatter;

        return [
            'app' => $this->appName(),
            'message' => $record->message,
            'level' => $record->level->toPsrLogLevel(),
            'channel' => $record->channel,
            'datetime' => $record->datetime->format(DateTimeInterface::ATOM),
            'context' => (array) $normalizer->normalizeValue($record->context),
            'extra' => (array) $normalizer->normalizeValue($record->extra),
        ];
    }

    private function appName(): string
    {
        if ($this->app !== '') {
            return $this->app;
        }

        return config()->string('app.name');
    }
}
