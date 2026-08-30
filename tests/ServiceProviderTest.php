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
use Puntjes\Resource\Vouchers;

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

    public function test_config_retry_base_delay_reaches_the_sdk(): void
    {
        config()->set('puntjes.http.retry_base_delay', 1.5);

        self::assertSame(1.5, $this->app->make(Config::class)->retryBaseDelay);
    }

    public function test_configured_headers_reach_the_sdk(): void
    {
        config()->set('puntjes.http.default_headers', ['X-Trace-Id' => 'abc123']);

        self::assertSame('abc123', $this->app->make(Config::class)->defaultHeaders['X-Trace-Id'] ?? null);
    }

    public function test_the_package_user_agent_survives_configured_headers(): void
    {
        config()->set('puntjes.http.default_headers', ['X-Trace-Id' => 'abc123']);

        $headers = $this->app->make(Config::class)->defaultHeaders;

        self::assertArrayHasKey('User-Agent', $headers);
        self::assertStringStartsWith('puntjes-laravel/', $headers['User-Agent']);
    }

    public function test_a_configured_user_agent_wins(): void
    {
        config()->set('puntjes.http.default_headers', ['User-Agent' => 'acme-pos/2.1']);

        self::assertSame('acme-pos/2.1', $this->app->make(Config::class)->defaultHeaders['User-Agent']);
    }

    /**
     * The facade is a hand-maintained copy of the client's surface, so it drifts the
     * moment the SDK grows or drops a method. That drift is invisible: `__callStatic`
     * forwards the call anyway, so the only thing lost is what an IDE and a static
     * analyser can see, which nothing else here exercises. Both directions are checked,
     * because a facade advertising a method the SDK removed fails just as quietly.
     */
    public function test_the_facade_documents_every_method_on_the_client(): void
    {
        $missing = array_diff(
            $this->clientMethods(),
            $this->documentedMethods(),
            $this->facadeAccessors(),
        );

        self::assertSame([], array_values($missing), 'The facade does not mention: '.implode(', ', $missing));
    }

    public function test_the_facade_documents_nothing_the_client_lacks(): void
    {
        $ghosts = array_diff($this->documentedMethods(), $this->clientMethods());

        self::assertSame([], array_values($ghosts), 'The facade advertises what the SDK does not have: '.implode(', ', $ghosts));
    }

    /** @return array<int, string> */
    private function documentedMethods(): array
    {
        preg_match_all(
            '/@method\s+static\s+\S+\s+(\w+)\(/',
            (string) (new \ReflectionClass(PuntjesFacade::class))->getDocComment(),
            $matches,
        );

        return $matches[1];
    }

    /**
     * Only what this facade declares. `getMethods()` also returns everything public on
     * `Illuminate\Support\Facades\Facade` (`swap`, `resolved`, `spy` and eleven more),
     * and letting those through would excuse the SDK from documenting a method that
     * happened to share one of their names.
     *
     * @return array<int, string>
     */
    private function facadeAccessors(): array
    {
        $facade = new \ReflectionClass(PuntjesFacade::class);

        return array_values(array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $facade->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $facade->getName(),
            ),
        ));
    }

    /** @return array<int, string> */
    private function clientMethods(): array
    {
        return array_diff(
            array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                (new \ReflectionClass(Puntjes::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            ),
            // Constructors, not surface a facade forwards.
            ['__construct', 'make', 'fromConfig'],
        );
    }

    public function test_a_negative_retry_delay_is_refused(): void
    {
        config()->set('puntjes.http.retry_base_delay', -1);

        $this->expectException(ConfigurationException::class);

        $this->app->make(Config::class);
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
        //
        // env() reads the ambient process environment, so a PUNTJES_BASE_URL exported
        // in the developer's shell (e.g. for the SDK's contract suite) would silently
        // replace the default under test. Clear it for the duration.
        $backup = [
            'env' => $_ENV['PUNTJES_BASE_URL'] ?? null,
            'server' => $_SERVER['PUNTJES_BASE_URL'] ?? null,
            'getenv' => getenv('PUNTJES_BASE_URL'),
        ];

        unset($_ENV['PUNTJES_BASE_URL'], $_SERVER['PUNTJES_BASE_URL']);
        putenv('PUNTJES_BASE_URL');

        try {
            $shipped = require __DIR__.'/../config/puntjes.php';

            $config = new Config('id', 'secret', $shipped['base_url']);

            self::assertSame('https://puntjes.app/api/v1/me', $config->apiUrl('/me'));
            self::assertSame('https://puntjes.app/oauth/token', $config->tokenUrl());
        } finally {
            if ($backup['env'] !== null) {
                $_ENV['PUNTJES_BASE_URL'] = $backup['env'];
            }

            if ($backup['server'] !== null) {
                $_SERVER['PUNTJES_BASE_URL'] = $backup['server'];
            }

            if ($backup['getenv'] !== false) {
                putenv('PUNTJES_BASE_URL='.$backup['getenv']);
            }
        }
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
        self::assertInstanceOf(Vouchers::class, PuntjesFacade::vouchers());
        self::assertSame($this->app->make(Puntjes::class), PuntjesFacade::client());
    }

    public function test_the_config_file_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'puntjes-config'])->assertSuccessful();

        self::assertFileExists(config_path('puntjes.php'));

        @unlink(config_path('puntjes.php'));
    }
}
