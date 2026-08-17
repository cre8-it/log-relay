<?php

declare(strict_types=1);

namespace LogRelay\LogRelay;

use DateTimeInterface;
use Illuminate\Support\Facades\Http;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

use function Illuminate\Support\defer;

class HttpHandler extends AbstractProcessingHandler
{
    private string $url;

    public function __construct(
        string $url = '',
        private string $token = '',
        private string $app = '',
        private bool $async = true,
        private int $timeout = 5,
        Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        $this->url = trim($url);
    }

    protected function write(LogRecord $record): void
    {
        if ($this->url === '') {
            return;
        }

        if ($this->async) {
            defer(fn () => $this->send($record), always: true);

            return;
        }

        $this->send($record);
    }

    private function send(LogRecord $record): void
    {
        try {
            $request = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson();

            if ($this->token !== '') {
                $request = $request->withToken($this->token);
            }

            $request->post($this->url, $this->payload($record));
        } catch (Throwable) {
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
