<div align="center">
    <h1>Log Relay for Laravel</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/cre8-it/log-relay"><img src="https://img.shields.io/packagist/v/cre8-it/log-relay.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/cre8-it/log-relay"><img src="https://img.shields.io/packagist/php-v/cre8-it/log-relay.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://badge.laravel.cloud/badge/cre8-it/log-relay?style=flat"><img src="https://badge.laravel.cloud/badge/cre8-it/log-relay?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/cre8-it/log-relay/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/cre8-it/log-relay/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/cre8-it/log-relay"><img src="https://img.shields.io/packagist/dt/cre8-it/log-relay.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Minimal Monolog log channel that posts structured log data over HTTP.

## Installation

You can install the package via Composer:

```bash
composer require cre8-it/log-relay
```

Register the channel in `config/logging.php`:

```php
use LogRelay\LogRelay\HttpHandler;

'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'http'],
    ],

    'http' => [
        'driver' => 'monolog',
        'handler' => HttpHandler::class,
        'level' => env('LOG_LEVEL', 'debug'),
        'handler_with' => [
            'url' => env('LOG_RELAY_URL', ''),
            'token' => env('LOG_RELAY_TOKEN', ''),
            'app' => env('LOG_RELAY_APP', ''),
            'async' => true,
        ],
    ],
],
```

Then set the connection values in your `.env` file:

```env
LOG_RELAY_URL=
LOG_RELAY_TOKEN=
LOG_RELAY_APP=
```

`app` is sent with each record so the ingress can tell sources apart. If you omit it, the handler falls back to `config('app.name')`.

## Usage

Write logs as usual. Messages that reach the `http` channel are posted as JSON:

```php
use Illuminate\Support\Facades\Log;

Log::error('Payment failed', ['order' => 42]);
Log::channel('http')->info('Invoice created');
```

Each request includes:

- `app`, `message`, `level`, `channel`, `datetime`
- `context` and `extra` (normalized so exceptions and objects are JSON-safe)
- `Authorization: Bearer {token}` when a token is configured

### Sync and async

`async` defaults to `true`. The HTTP call is deferred with Laravel's `defer()` helper and runs after the request or command has been handled, including failed requests.

Set `async` to `false` (or `sync`) to send immediately:

```php
'async' => false,
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Log Relay for Laravel! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Credits

- [All Contributors](../../contributors)

## License

Log Relay for Laravel is open-sourced software licensed under the [MIT license](LICENSE.md).
