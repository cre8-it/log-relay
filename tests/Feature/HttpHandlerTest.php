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
    Http::fake();
    defer()->invoke();
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
    httpLogger(['async' => true])->warning('Slow query');

    Http::assertNothingSent();
    expect(defer())->toHaveCount(1)
        ->and(defer()->first()->always)->toBeTrue();

    defer()->invoke();

    Http::assertSent(fn (Request $request) => $request['message'] === 'Slow query'
        && $request['level'] === 'warning');
});

it('falls back to the application name when app is omitted', function () {
    config()->set('app.name', 'Workbench');

    httpLogger(['app' => ''])->info('Started');

    Http::assertSent(fn (Request $request) => $request['app'] === 'Workbench');
});

it('does not send when the url is empty', function () {
    httpLogger(['url' => ''])->info('Ignored');
    httpLogger(['url' => '   '])->info('Ignored');

    Http::assertNothingSent();
});

it('omits the bearer token when none is configured', function () {
    httpLogger(['token' => ''])->info('Public');

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization')
        && $request['message'] === 'Public');
});

it('normalizes exceptions in the log context', function () {
    httpLogger()->error('Failed', ['exception' => new RuntimeException('boom')]);

    Http::assertSent(function (Request $request) {
        return is_array($request['context']['exception'])
            && $request['context']['exception']['message'] === 'boom';
    });
});

it('does not throw when the remote endpoint fails', function () {
    Http::fake(fn () => throw new RuntimeException('connection refused'));

    expect(fn () => httpLogger()->critical('Outage'))->not->toThrow(Throwable::class);
});
