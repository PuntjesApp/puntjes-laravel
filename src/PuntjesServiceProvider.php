<?php

declare(strict_types=1);

namespace Puntjes\Laravel;

use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Puntjes\Auth\TokenStore;
use Puntjes\Config;
use Puntjes\Exception\ConfigurationException;
use Puntjes\Http\HttpClient;
use Puntjes\Puntjes;

final class PuntjesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/puntjes.php', 'puntjes');

        $this->app->singleton(Config::class, function (): Config {
            $clientId = (string) (config('puntjes.client_id') ?? '');
            $clientSecret = (string) (config('puntjes.client_secret') ?? '');

            if ($clientId === '' || $clientSecret === '') {
                // Raised on first resolution rather than at boot, so an application that
                // has the package installed but not configured still boots — artisan,
                // migrations and unrelated routes keep working.
                throw new ConfigurationException(
                    'Puntjes credentials are missing. Set PUNTJES_CLIENT_ID and PUNTJES_CLIENT_SECRET in your .env.',
                );
            }

            return new Config(
                clientId: $clientId,
                clientSecret: $clientSecret,
                baseUrl: (string) config('puntjes.base_url'),
                maxRetries: (int) config('puntjes.http.retries', 2),
                defaultHeaders: [
                    'User-Agent' => 'puntjes-laravel/'.($this->app->version()),
                ],
            );
        });

        $this->app->singleton(TokenStore::class, function (): TokenStore {
            /** @var CacheFactory $cache */
            $cache = $this->app->make(CacheFactory::class);

            return new CacheTokenStore(
                $cache->store(config('puntjes.cache.store')),
                (string) config('puntjes.cache.prefix', 'puntjes'),
            );
        });

        $this->app->singleton(Puntjes::class, function (): Puntjes {
            // Any PSR-18 client bound in the container wins, so an application can
            // control timeouts, proxies, TLS and instrumentation without this package
            // needing an option for each of them.
            $http = $this->app->bound(ClientInterface::class)
                ? new HttpClient($this->app->make(ClientInterface::class))
                : new HttpClient($this->defaultHttpClient());

            return Puntjes::fromConfig(
                $this->app->make(Config::class),
                $this->app->make(TokenStore::class),
                $http,
            );
        });

        $this->app->alias(Puntjes::class, 'puntjes');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/puntjes.php' => config_path('puntjes.php'),
            ], 'puntjes-config');
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [Puntjes::class, Config::class, TokenStore::class, 'puntjes'];
    }

    /**
     * Build a Guzzle client honouring the configured timeouts.
     *
     * Guzzle is not a dependency of this package — Laravel ships it, so it is
     * normally present, but discovery still covers the case where it is not.
     */
    private function defaultHttpClient(): ?ClientInterface
    {
        if (! class_exists(Client::class)) {
            return null;
        }

        /** @var ClientInterface */
        return new Client([
            'timeout' => (float) config('puntjes.http.timeout', 10),
            'connect_timeout' => (float) config('puntjes.http.connect_timeout', 5),
            // Errors are mapped from the response body by the SDK, so Guzzle must hand
            // 4xx/5xx back rather than throwing its own exception.
            'http_errors' => false,
        ]);
    }
}
