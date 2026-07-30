<?php

declare(strict_types=1);

namespace Puntjes\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Puntjes\Auth\AccessToken;
use Puntjes\Auth\TokenStore;

/**
 * Caches Puntjes access tokens in Laravel's cache.
 *
 * This is the main reason `puntjes/laravel` exists. Without it, the SDK's default
 * in-memory store grants a fresh token on every web request — one wasted round trip
 * per request, and needless load on the token endpoint. With a shared store (redis,
 * database) every process and every worker reuses the same token until it expires.
 *
 * Tokens are stored as plain arrays rather than serialised objects so the cached
 * value stays readable by a future version of this package, and so a cache driver
 * that round-trips through JSON does not choke on it.
 */
final class CacheTokenStore implements TokenStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'puntjes',
    ) {}

    public function get(string $key): ?AccessToken
    {
        return AccessToken::fromArray($this->cache->get($this->key($key)));
    }

    public function put(string $key, AccessToken $token, int $ttl): void
    {
        // A non-positive TTL would be "forever" on most Laravel stores — precisely the
        // wrong reading for an already-expired token. Drop it instead.
        if ($ttl <= 0) {
            $this->forget($key);

            return;
        }

        $this->cache->put($this->key($key), $token->toArray(), $ttl);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->key($key));
    }

    /**
     * The SDK's key already carries a hash of the credentials, never the secret
     * itself, so it is safe to use as a cache key verbatim.
     */
    private function key(string $key): string
    {
        return $this->prefix.':'.$key;
    }
}
