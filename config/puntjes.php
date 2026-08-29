<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | The OAuth client id and secret from Puntjes → Settings → API clients.
    | Keep the secret in .env — it is a bearer credential for your whole vendor
    | account and must never be committed.
    |
    */

    'client_id' => env('PUNTJES_CLIENT_ID'),

    'client_secret' => env('PUNTJES_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | The base URL as the API docs state it, https://puntjes.app/api/v1. The bare
    | host works too — both are accepted and equivalent.
    |
    | The SDK keeps only the host internally, because the API lives under /api/v1
    | while the OAuth token endpoint sits at /oauth/token, off the root.
    |
    */

    'base_url' => env('PUNTJES_BASE_URL', 'https://puntjes.app/api/v1'),

    /*
    |--------------------------------------------------------------------------
    | Token cache
    |--------------------------------------------------------------------------
    |
    | Access tokens are cached so a token grant does not happen on every web
    | request. `store` names a cache store from config/cache.php; null uses the
    | application default.
    |
    | Use a SHARED store (redis, database, memcached) in production. The `array`
    | and `file` drivers are per-process and per-server respectively, which means
    | every worker grants its own token — correct, but wasteful.
    |
    */

    'cache' => [
        'store' => env('PUNTJES_CACHE_STORE'),

        'prefix' => env('PUNTJES_CACHE_PREFIX', 'puntjes'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | `retries` is how many times a retry-SAFE request is retried on a 5xx, a
    | rate limit, or a connection failure. Requests that could duplicate a
    | side effect are never retried regardless of this value — see the SDK's
    | Transport for the full policy.
    |
    | `retry_base_delay` is how long the first backoff waits, in seconds. Each
    | further attempt doubles it, so raising this lengthens every retry after it.
    |
    | `timeout` and `connect_timeout` are in seconds and apply to the Guzzle
    | client this package builds. They are ignored if you bind your own PSR-18
    | client into the container.
    |
    | `default_headers` are sent on every request. This package sets a User-Agent
    | naming itself and your Laravel version; listing `User-Agent` here replaces
    | it, and any other key is added alongside it.
    |
    */

    'http' => [
        'retries' => (int) env('PUNTJES_RETRIES', 2),

        'retry_base_delay' => (float) env('PUNTJES_RETRY_BASE_DELAY', 0.5),

        'timeout' => (float) env('PUNTJES_TIMEOUT', 10),

        'connect_timeout' => (float) env('PUNTJES_CONNECT_TIMEOUT', 5),

        'default_headers' => [],
    ],

];
