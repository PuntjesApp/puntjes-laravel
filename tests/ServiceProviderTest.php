<?php

declare(strict_types=1);

namespace Puntjes\Laravel\Tests;

use Puntjes\Auth\TokenStore;
use Puntjes\Config;
use Puntjes\Exception\ConfigurationException;
use Puntjes\Laravel\CacheTokenStore;
use Puntjes\Laravel\Facades\Puntjes as PuntjesFacade;
use Puntjes\Puntjes;
use Puntjes\Resource\Customers;
use Puntjes\Resource\Products;

final class ServiceProviderTest extends TestCase
{
    public function test_it_resolves_a_configured_client(): void
    {
        $client = $this->app->make(Puntjes::class);

        self::assertInstanceOf(Puntjes::class, $client);
        self::assertSame('https://app.puntjes.test', $client->config()->baseUrl);
        self::assertSame('test-client', $client->config()->clientId);
    }

    public function test_the_client_is_a_singleton(): void
    {
        self::assertSame($this->app->make(Puntjes::class), $this->app->make(Puntjes::class));
    }

    public function test_it_binds_the_cache_backed_token_store(): void
    {
        self::assertInstanceOf(CacheTokenStore::class, $this->app->make(TokenStore::class));
    }

    public function test_config_retries_reach_the_sdk(): void
    {
        config()->set('puntjes.http.retries', 5);

        self::assertSame(5, $this->app->make(Config::class)->maxRetries);
    }

    public function test_the_documented_base_url_including_the_api_prefix_is_accepted(): void
    {
        // What an integrator pastes out of the API docs. Taken verbatim it would send
        // the token request to /api/v1/oauth/token, which does not exist.
        config()->set('puntjes.base_url', 'https://app.puntjes.test/api/v1');

        $config = $this->app->make(Config::class);

        self::assertSame('https://app.puntjes.test/oauth/token', $config->tokenUrl());
        self::assertSame('https://app.puntjes.test/api/v1/me', $config->apiUrl('/me'));
    }

    public function test_the_bare_host_resolves_identically(): void
    {
        config()->set('puntjes.base_url', 'https://app.puntjes.test');

        $config = $this->app->make(Config::class);

        self::assertSame('https://app.puntjes.test/oauth/token', $config->tokenUrl());
        self::assertSame('https://app.puntjes.test/api/v1/me', $config->apiUrl('/me'));
    }

    public function test_the_shipped_default_base_url_is_usable(): void
    {
        // The default in config/puntjes.php ships to every install, so it must itself
        // be a form the SDK accepts — and must not double up the /api/v1 prefix.
        $shipped = require __DIR__.'/../config/puntjes.php';

        $config = new Config('id', 'secret', $shipped['base_url']);

        self::assertSame('https://puntjes.app/api/v1/me', $config->apiUrl('/me'));
        self::assertSame('https://puntjes.app/oauth/token', $config->tokenUrl());
    }

    public function test_missing_credentials_fail_on_resolution_not_at_boot(): void
    {
        // Booting succeeded — proven by getting this far with the provider registered.
        config()->set('puntjes.client_secret', null);
        $this->app->forgetInstance(Config::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('PUNTJES_CLIENT_ID and PUNTJES_CLIENT_SECRET');

        $this->app->make(Config::class);
    }

    public function test_the_facade_exposes_the_resource_groups(): void
    {
        self::assertInstanceOf(Customers::class, PuntjesFacade::customers());
        self::assertInstanceOf(Products::class, PuntjesFacade::products());
        self::assertSame($this->app->make(Puntjes::class), PuntjesFacade::client());
    }

    public function test_the_config_file_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'puntjes-config'])->assertSuccessful();

        self::assertFileExists(config_path('puntjes.php'));

        @unlink(config_path('puntjes.php'));
    }
}
