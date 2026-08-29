# puntjes/laravel

Laravel integration for the [Puntjes](https://puntjes.app) loyalty API.

A thin layer over [`puntjes/php-sdk`](https://github.com/PuntjesApp/puntjes-php-sdk):
config, a service provider, a facade, and — the part that actually matters in
production — a **cache-backed token store** so your app grants one access token per
hour instead of one per web request.

All the API surface, retry rules and error types live in the core SDK. Read its
[README](https://github.com/PuntjesApp/puntjes-php-sdk#readme) for those; this one
covers only the Laravel wiring.

## Install

```bash
composer require puntjes/laravel
```

Add your credentials from **Puntjes → Settings → API clients**:

```dotenv
PUNTJES_CLIENT_ID=…
PUNTJES_CLIENT_SECRET=…
PUNTJES_BASE_URL=https://puntjes.app/api/v1
```

That is the whole setup — the provider and facade are auto-discovered.

Publish the config only if you need to change something in it:

```bash
php artisan vendor:publish --tag=puntjes-config
```

## Use

```php
use Puntjes\Laravel\Facades\Puntjes;
use Puntjes\Request\SubmitTransaction;

$customer = Puntjes::customers()->lookup(identifier: $card);

Puntjes::transactions()->submit(new SubmitTransaction(
    identifier:     $card,
    totalAmount:    $order->total_cents,
    idempotencyKey: 'order-'.$order->id,
));
```

Or inject the client — preferable in domain code, and trivially fakeable in tests:

```php
use Puntjes\Puntjes;

final class AwardLoyaltyPointsAction
{
    public function __construct(private readonly Puntjes $puntjes) {}

    public function handle(Order $order): void
    {
        $this->puntjes->transactions->submit(new SubmitTransaction(
            identifier:     $order->loyalty_card,
            totalAmount:    $order->total_cents,
            idempotencyKey: 'order-'.$order->id,
        ));
    }
}
```

The facade exposes each endpoint group as a method — `Puntjes::customers()`,
`transactions()`, `wallets()`, `rewards()`, `redemptions()`, `products()`,
`campaigns()`, `statistics()`, `vouchers()` — plus `Puntjes::me()`, `Puntjes::ping()`
and `Puntjes::client()` for the underlying SDK instance.

> The SDK exposes those groups as readonly *properties*, which a facade cannot proxy
> (`__callStatic` only forwards method calls). Hence the accessor methods.

## Configuration

| Key | Env | Default | Notes |
|---|---|---|---|
| `client_id` | `PUNTJES_CLIENT_ID` | — | Required |
| `client_secret` | `PUNTJES_CLIENT_SECRET` | — | Required. Never commit it |
| `base_url` | `PUNTJES_BASE_URL` | `https://puntjes.app/api/v1` | As documented; the bare host also works |
| `cache.store` | `PUNTJES_CACHE_STORE` | app default | Use a shared store in production |
| `cache.prefix` | `PUNTJES_CACHE_PREFIX` | `puntjes` | |
| `http.retries` | `PUNTJES_RETRIES` | `2` | Retry-safe requests only |
| `http.retry_base_delay` | `PUNTJES_RETRY_BASE_DELAY` | `0.5` | Seconds before the first retry; doubles after each |
| `http.timeout` | `PUNTJES_TIMEOUT` | `10` | Seconds |
| `http.connect_timeout` | `PUNTJES_CONNECT_TIMEOUT` | `5` | Seconds |
| `http.default_headers` | — | `[]` | Sent on every request |

This package sends a `User-Agent` naming itself and your Laravel version. Listing
`User-Agent` in `http.default_headers` replaces it; any other key is sent alongside it.

`PUNTJES_BASE_URL` takes the base URL exactly as the API docs state it,
`https://puntjes.app/api/v1`. The bare host is accepted as well — the SDK keeps only
the host either way, because the API lives under `/api/v1` while the OAuth token
endpoint sits at `/oauth/token`, off the root.

Missing credentials raise a `ConfigurationException` when the client is first
resolved, not at boot, so an app that has the package installed but not yet
configured still runs `artisan`, migrations and unrelated routes.

## Token caching

Set `PUNTJES_CACHE_STORE` to a **shared** store — `redis`, `database`, `memcached`.
The `array` and `file` drivers are per-process and per-server, so every worker grants
its own token. Correct, just wasteful.

Tokens are stored under a key derived from a hash of the credentials, so the secret
is never written to the cache, and two vendors configured in one app — or one vendor
mid secret-rotation — never read each other's token.

## Custom HTTP client

By default the package builds a Guzzle client honouring the configured timeouts. Bind
your own PSR-18 client to take over completely — for instrumentation, a proxy, or
custom TLS:

```php
$this->app->bind(\Psr\Http\Client\ClientInterface::class, fn () => new MyTracedClient);
```

The `http.timeout` and `http.connect_timeout` settings then no longer apply; configure
them on your client.

## Testing

Bind a fake client in your tests and assert against it, rather than hitting the API:

```php
use Puntjes\Puntjes;

$this->app->instance(Puntjes::class, $fakePuntjes);
```

For request-level control, bind a stub `Psr\Http\Client\ClientInterface` and let the
real SDK run against it — that keeps the auth flow, retries and error mapping in the
test rather than mocking them away.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

The core SDK targets PHP 8.1 so it can reach older WooCommerce hosts; this package
tracks Laravel's own floor instead.

## Roadmap

Webhook routing and signature-verification middleware land here once outbound
webhooks ship on the API.

## Development

```bash
composer install
composer test        # Testbench
composer analyse     # PHPStan level 6
composer format      # Pint
```

The core SDK resolves from Packagist. To develop both packages side by side with SDK
edits visible here immediately, add a path repository **locally, without committing
it**:

```bash
composer config repositories.sdk '{"type": "path", "url": "../puntjes-php-sdk", "options": {"symlink": true}}'
composer update puntjes/php-sdk
```

Revert `composer.json` before committing.

## License

MIT.
