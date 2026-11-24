<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogRelay\LogRelay\HttpHandler;
use LogRelay\LogRelay\LogRelayServiceProvider;
use Psr\Log\LoggerInterface;

use function Illuminate\Support\defer;

beforeEach(function () {
    $this->diagnosticLog = tempnam(sys_get_temp_dir(), 'log-relay-');
    $this->originalErrorLog = ini_set('error_log', $this->diagnosticLog);
    Http::preventStrayRequests();
    defer()->invoke();
});

afterEach(function () {
    ini_set('error_log', $this->originalErrorLog);
    unlink($this->diagnosticLog);
});

function httpLogger(array $handlerWith = []): LoggerInterface
{
    return Log::build([
        'driver' => 'monolog',
        'handler' => HttpHandler::class,
        'handler_with' => array_merge([
            'url' => 'https://logs.example.com',
            'token' => 'secret-token',
            'app' => 'demo',
            'async' => false,
        ], $handlerWith),
    ]);
}

it('is provided by the package service provider', function () {
    expect(app()->providerIsLoaded(LogRelayServiceProvider::class))->toBeTrue();
});

it('sends structured log data synchronously through the monolog driver', function () {
    Http::fake();
    httpLogger()->error('Payment failed', ['order' => 42]);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://logs.example.com'
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && $request['app'] === 'demo'
            && $request['message'] === 'Payment failed'
            && $request['level'] === 'error'
            && $request['context']['order'] === 42
            && is_string($request['datetime'])
            && is_string($request['channel'])
            && is_array($request['extra']);
    });
});

it('resolves the channel from logging configuration', function () {
    Http::fake();
    config()->set('logging.channels.http', [
        'driver' => 'monolog',
        'handler' => HttpHandler::class,
        'handler_with' => [
            'url' => 'https://logs.example.com',
            'token' => 'secret-token',
            'app' => 'billing',
            'async' => false,
        ],
    ]);

    Log::forgetChannel('http');
    Log::channel('http')->info('Invoice created');

    Http::assertSent(fn (Request $request) => $request['app'] === 'billing'
        && $request['message'] === 'Invoice created'
        && $request['level'] === 'info');
});

it('defers the http request until after the request is handled', function () {
    Http::fake();
    httpLogger(['async' => true])->warning('Slow query');

    Http::assertNothingSent();
    expect(defer())->toHaveCount(1)
        ->and(defer()->first()->always)->toBeTrue();

    defer()->invoke();

    Http::assertSent(fn (Request $request) => $request['message'] === 'Slow query'
        && $request['level'] === 'warning');
});

it('falls back to the application name when app is omitted', function () {
    Http::fake();
    config()->set('app.name', 'Workbench');

    httpLogger(['app' => ''])->info('Started');

    Http::assertSent(fn (Request $request) => $request['app'] === 'Workbench');
});

it('does not send when the url is empty', function () {
    Http::fake();
    httpLogger(['url' => ''])->info('Ignored');
    httpLogger(['url' => '   '])->info('Ignored');

    Http::assertNothingSent();
});

it('omits the bearer token when none is configured', function () {
    Http::fake();
    httpLogger(['token' => ''])->info('Public');

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization')
        && $request['message'] === 'Public');
});

it('normalizes exceptions in the log context', function () {
    Http::fake();
    httpLogger()->error('Failed', ['exception' => new RuntimeException('boom')]);

    Http::assertSent(function (Request $request) {
        return is_array($request['context']['exception'])
            && $request['context']['exception']['message'] === 'boom';
    });
});

it('skips later deliveries after an outage but resumes in a fresh scope', function (bool $async) {
    $attempts = [];
    Http::fake(function (Request $request) use (&$attempts) {
        $attempts[] = $request['message'];

        return count($attempts) === 1 ? Http::failedConnection() : Http::response(status: 201);
    });
    $logger = httpLogger(['async' => $async]);
    $logger->critical('First');
    $logger->critical('Same scope');
    defer()->invoke();
    app()->forgetScopedInstances();
    $logger->info('Next scope');
    defer()->invoke();

    expect($attempts)->toBe(['First', 'Next scope']);
})->with([true, false]);

it('continues after a rejected record but stops on rate limiting', function () {
    Http::fakeSequence()->pushStatus(422)->pushStatus(429)->pushStatus(201);
    $logger = httpLogger();
    $logger->info('Rejected');
    $logger->info('Rate limited');
    $logger->info('Skipped');

    Http::assertSentCount(2);
    $lines = file($this->diagnosticLog, FILE_IGNORE_NEW_LINES);
    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('HTTP 422')->not->toContain('secret-token', 'Rejected');
});

it('isolates bad context without losing the next record', function () {
    Http::fake();
    $logger = httpLogger();
    $logger->info('Bad', ['object' => new class implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            throw new RuntimeException('bad context');
        }
    }]);
    $logger->info('Good');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['message'] === 'Good');
});
