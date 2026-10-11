<?php

declare(strict_types=1);

namespace Puntjes\Laravel;

use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Puntjes\Auth\TokenStore;
use Puntjes\Exception\ConfigurationException;
use Puntjes\Generated\PuntjesClient;
use Puntjes\Puntjes;

final class PuntjesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/puntjes.php', 'puntjes');

        $this->app->singleton(TokenStore::class, function (): TokenStore {
            /** @var CacheFactory $cache */
            $cache = $this->app->make(CacheFactory::class);

            return new CacheTokenStore(
                $cache->store(config('puntjes.cache.store')),
                (string) config('puntjes.cache.prefix', 'puntjes'),
            );
        });

        $this->app->bindIf('puntjes.http', fn (): ?ClientInterface => $this->defaultHttpClient());

        $this->app->singleton(PuntjesClient::class, function (): PuntjesClient {
            $clientId = (string) (config('puntjes.client_id') ?? '');
            $clientSecret = (string) (config('puntjes.client_secret') ?? '');

            if ($clientId === '' || $clientSecret === '') {
                // Raised on first resolution rather than at boot, so an application that
                // has the package installed but not configured still boots.
                throw new ConfigurationException(
                    'Puntjes credentials are missing. Set PUNTJES_CLIENT_ID and PUNTJES_CLIENT_SECRET in your .env.',
                );
            }

            // Any PSR-18 client bound in the container wins, so an application controls
            // timeouts, proxies, TLS and instrumentation without an option for each.
            return Puntjes::make(
                clientId: $clientId,
                clientSecret: $clientSecret,
                baseUrl: (string) config('puntjes.base_url'),
                tokenStore: $this->app->make(TokenStore::class),
                httpClient: $this->app->bound(ClientInterface::class)
                    ? $this->app->make(ClientInterface::class)
                    : $this->app->make('puntjes.http'),
                maxRetries: (int) config('puntjes.http.retries', 2),
                defaultHeaders: array_merge(
                    ['User-Agent' => 'puntjes-laravel/'.$this->app->version()],
                    (array) config('puntjes.http.default_headers', []),
                ),
            );
        });

        $this->app->alias(PuntjesClient::class, 'puntjes');
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
        return [PuntjesClient::class, TokenStore::class, 'puntjes.http', 'puntjes'];
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
