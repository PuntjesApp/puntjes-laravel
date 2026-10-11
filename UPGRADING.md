# Upgrading from 1.x to 2.0

2.0 runs on `puntjes/php-sdk` 2.x, the core that is generated from the Puntjes OpenAPI spec.
The token cache, the config file and the facade name stay. What you call through them
changes, because the core's calls changed.

```bash
composer require puntjes/laravel:^2.0
```

## What to change

1. **Your calls.** `Puntjes::customers()->lookup(identifier: $card)` becomes
   `Puntjes::customers()->lookup()->get(...)->wait()->getData()`. The core's
   [`UPGRADING.md`](https://github.com/PuntjesApp/puntjes-php-sdk/blob/main/UPGRADING.md)
   shows every 1.x call in 2.0, and the same five behaviour changes apply here. Read the
   one about `idempotency_key`: the core no longer makes one for you.
2. **Type hints.** A constructor argument, a property or a container lookup of
   `Puntjes\Puntjes` becomes `Puntjes\Generated\PuntjesClient`. `Puntjes\Puntjes` is now
   only the factory with `make()`, and the container no longer binds it.
3. **`Puntjes\Config` from the container.** It is gone. Read the values from
   `config('puntjes')`.
4. **Facade calls that 1.x had as helpers.** `Puntjes::ping()` becomes
   `Puntjes::health()->get()->wait()`, and `Puntjes::me()` now returns a request builder:
   `Puntjes::me()->get()->wait()->getData()`. `Puntjes::request()` and `Puntjes::config()`
   are gone.

## The config file

`http.retry_base_delay` (`PUNTJES_RETRY_BASE_DELAY`) is gone. The core waits 0.5 seconds before
the first retry and doubles the wait each time, and a `Retry-After` header from Puntjes wins.
To fail fast instead, set `PUNTJES_RETRIES=0`.

A published `config/puntjes.php` that still has the key keeps working: 2.0 ignores it.
Remove the line when you next edit the file.

## What stays

- `PUNTJES_CLIENT_ID`, `PUNTJES_CLIENT_SECRET`, `PUNTJES_BASE_URL`, the cache keys,
  `PUNTJES_RETRIES`, the timeouts and `http.default_headers`.
- Tokens cached by 1.x. 2.0 reads them, so the upgrade costs no extra token grant.
- A PSR-18 client you bind in the container still replaces the package's Guzzle client.
- Laravel 12 and 13, PHP 8.2 and up.
