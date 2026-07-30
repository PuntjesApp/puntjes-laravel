<?php

declare(strict_types=1);

namespace Puntjes\Laravel\Tests;

use Illuminate\Support\Facades\Cache;
use Puntjes\Auth\AccessToken;
use Puntjes\Laravel\CacheTokenStore;

final class CacheTokenStoreTest extends TestCase
{
    private function store(string $prefix = 'puntjes'): CacheTokenStore
    {
        return new CacheTokenStore(Cache::store('array'), $prefix);
    }

    public function test_it_round_trips_a_token(): void
    {
        $store = $this->store();
        $token = new AccessToken('tok-abc', time() + 3600);

        $store->put('key', $token, 3600);
        $read = $store->get('key');

        self::assertNotNull($read);
        self::assertSame('tok-abc', $read->accessToken);
        self::assertSame($token->expiresAt, $read->expiresAt);
        self::assertSame('Bearer', $read->tokenType);
    }

    public function test_an_absent_key_reads_as_null(): void
    {
        self::assertNull($this->store()->get('never-written'));
    }

    public function test_forget_removes_the_token(): void
    {
        $store = $this->store();
        $store->put('key', new AccessToken('tok-abc', time() + 3600), 3600);

        $store->forget('key');

        self::assertNull($store->get('key'));
    }

    public function test_a_non_positive_ttl_drops_the_token_instead_of_caching_it_forever(): void
    {
        // Laravel reads ttl <= 0 as "forever" on most stores — exactly backwards for a
        // token that has already expired.
        $store = $this->store();
        $store->put('key', new AccessToken('good', time() + 3600), 3600);

        $store->put('key', new AccessToken('expired', time() - 10), 0);

        self::assertNull($store->get('key'));
    }

    public function test_the_prefix_namespaces_the_cache_key(): void
    {
        $this->store('app-a')->put('shared-key', new AccessToken('tok-a', time() + 3600), 3600);
        $this->store('app-b')->put('shared-key', new AccessToken('tok-b', time() + 3600), 3600);

        self::assertSame('tok-a', $this->store('app-a')->get('shared-key')?->accessToken);
        self::assertSame('tok-b', $this->store('app-b')->get('shared-key')?->accessToken);
    }

    public function test_it_stores_a_plain_array_not_a_serialised_object(): void
    {
        $this->store()->put('key', new AccessToken('tok-abc', 1800000000), 3600);

        self::assertSame([
            'access_token' => 'tok-abc',
            'expires_at' => 1800000000,
            'token_type' => 'Bearer',
        ], Cache::store('array')->get('puntjes:key'));
    }

    public function test_a_corrupted_cache_entry_reads_as_a_miss_rather_than_crashing(): void
    {
        Cache::store('array')->put('puntjes:key', ['nonsense' => true], 3600);

        self::assertNull($this->store()->get('key'));
    }
}
