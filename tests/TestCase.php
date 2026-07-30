<?php

declare(strict_types=1);

namespace Puntjes\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Puntjes\Laravel\PuntjesServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [PuntjesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('puntjes.client_id', 'test-client');
        $app['config']->set('puntjes.client_secret', 'test-secret');
        $app['config']->set('puntjes.base_url', 'https://app.puntjes.test');
        $app['config']->set('cache.default', 'array');
    }
}
