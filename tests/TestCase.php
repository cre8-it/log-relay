<?php

declare(strict_types=1);

namespace LogRelay\LogRelay\Tests;

use LogRelay\LogRelay\LogRelayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LogRelayServiceProvider::class,
        ];
    }
}
