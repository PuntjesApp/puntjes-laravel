# puntjes/laravel

Laravel integration for the [Puntjes](https://puntjes.app) loyalty API.

A thin layer over [`puntjes/php-sdk`](https://github.com/PuntjesApp/puntjes-php-sdk):
config, a service provider, a facade, and the part that matters most in production:
a **cache-backed token store** so your app grants one access token per
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

That is the whole setup. The provider and the facade are auto-discovered.

Publish the config only if you need to change something in it:

```bash
php artisan vendor:publish --tag=puntjes-config
```

## Use

```php
use Puntjes\Generated\Customers\Lookup\LookupRequestBuilderGetRequestConfiguration as Lookup;
use Puntjes\Generated\Models\CreateTransactionData;
use Puntjes\Laravel\Facades\Puntjes;

$customer = Puntjes::customers()->lookup()->get(new Lookup(
    queryParameters: Lookup::createQueryParameters(identifier: $card),
))->wait()->getData();

$body = new CreateTransactionData();
$body->setIdentifier($card);
$body->setTotalAmount($order->total_cents);
$body->setIdempotencyKey('order-'.$order->id);

Puntjes::transactions()->post($body)->wait()->getData();
```

The facade forwards every call to the core's generated client, so it has every call the API
has: `Puntjes::customers()`, `transactions()`, `rewards()`, `products()` and the rest. A call
is a path and an HTTP method, and it returns a promise: end it with `->wait()`, then read the
answer with `->getData()`. The core's [README](https://github.com/PuntjesApp/puntjes-php-sdk#readme)
has the call style, the retry rule and the exceptions.

Or inject the client, which is easier to replace in a test:

```php
use Puntjes\Generated\Models\CreateTransactionData;
use Puntjes\Generated\PuntjesClient;

final class AwardLoyaltyPointsAction
{
    public function __construct(private readonly PuntjesClient $puntjes) {}

    public function handle(Order $order): void
    {
        $body = new CreateTransactionData();
        $body->setIdentifier($order->loyalty_card);
        $body->setTotalAmount($order->total_cents);
        $body->setIdempotencyKey('order-'.$order->id);

        $this->puntjes->transactions()->post($body)->wait();
    }
}
```

`Puntjes::client()` returns that same `PuntjesClient`.

Coming from 1.x? Read [`UPGRADING.md`](UPGRADING.md).

## Configuration

| Key | Env | Default | Notes |
|---|---|---|---|
| `client_id` | `PUNTJES_CLIENT_ID` | — | Required |
| `client_secret` | `PUNTJES_CLIENT_SECRET` | — | Required. Never commit it |
| `base_url` | `PUNTJES_BASE_URL` | `https://puntjes.app/api/v1` | As documented; the bare host also works |
| `cache.store` | `PUNTJES_CACHE_STORE` | app default | Use a shared store in production |
| `cache.prefix` | `PUNTJES_CACHE_PREFIX` | `puntjes` | |
| `http.retries` | `PUNTJES_RETRIES` | `2` | Retry-safe requests only |
| `http.timeout` | `PUNTJES_TIMEOUT` | `10` | Seconds |
| `http.connect_timeout` | `PUNTJES_CONNECT_TIMEOUT` | `5` | Seconds |
| `http.default_headers` | — | `[]` | Sent on every request |

This package sends a `User-Agent` naming itself and your Laravel version. Listing
`User-Agent` in `http.default_headers` replaces it; any other key is sent alongside it.
`Accept`, `Authorization` and `Content-Type` are the exceptions: the SDK sets those per
request and its values win, so setting them here has no effect.

If you published the config file, a later `http` key this package adds does not reach your
copy: `mergeConfigFrom` merges only the top level, so your `http` array replaces the
package's whole `http` block. Add the new key to your copy, or publish the file again.

`PUNTJES_BASE_URL` takes the base URL exactly as the API docs state it,
`https://puntjes.app/api/v1`. The bare host is accepted as well. The SDK keeps only
the host either way, because the API lives under `/api/v1` while the OAuth token
endpoint sits at `/oauth/token`, off the root.

Missing credentials raise a `ConfigurationException` when the client is first
resolved, not at boot, so an app that has the package installed but not yet
configured still runs `artisan`, migrations and unrelated routes.

## Token caching

Set `PUNTJES_CACHE_STORE` to a **shared** store: `redis`, `database` or `memcached`.
The `array` and `file` drivers are per-process and per-server, so every worker grants
its own token. Correct, just wasteful.

Tokens are stored under a key derived from a hash of the credentials, so the secret
is never written to the cache, and two shops configured in one app (or one shop in the
middle of a secret rotation) never read each other's token.

## Custom HTTP client

By default the package builds a Guzzle client honouring the configured timeouts. Bind
your own PSR-18 client to take over completely, for instrumentation, a proxy or
custom TLS:

```php
$this->app->bind(\Psr\Http\Client\ClientInterface::class, fn () => new MyTracedClient);
```

The `http.timeout` and `http.connect_timeout` settings then no longer apply; configure
them on your client.

## Testing

Bind a fake client in your tests and assert against it, rather than hitting the API:

```php
use Puntjes\Generated\PuntjesClient;

$this->app->instance(PuntjesClient::class, $fakePuntjes);
```

For request-level control, bind a stub `Psr\Http\Client\ClientInterface` and let the
real SDK run against it. That keeps the token, retry and error rules in the test
instead of mocking them away.

## Requirements

- PHP 8.2+
- Laravel 12 or 13

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

### Checks on a pull request

Every pull request and every push to `main` runs PHPUnit, PHPStan and Pint in three runs:

| Run | What it installs | What it proves |
|---|---|---|
| `PHP 8.2 · oldest allowed packages` | The oldest version of every package that `composer.json` allows | The lower bounds in `composer.json` are true |
| `PHP 8.2 · newest packages` | The newest versions that install on PHP 8.2 | The package works on its lowest PHP version today |
| `PHP 8.4 · newest packages` | The newest versions of everything | The package works on a current PHP |

The same PHP version appears twice on purpose: the two runs differ in the package versions,
not in PHP. Pint runs once, on PHP 8.4, at a fixed version, so a new Pint release never
turns an unrelated pull request red.

The check named `CI` is green only when all three runs are green. `main` requires that one
check, so a pull request cannot merge while a run is red.

### How a new core reaches this package

Nobody edits this package when the API changes. When `puntjes/php-sdk` releases a new
version, its release workflow waits until Packagist serves it and then sends
`core-released` to this repository. `core-released.yml` opens a pull request that raises
the `puntjes/php-sdk` floor to that version and rewrites the facade's `@method` lines with
`scripts/facade-methods.php`. A minor or patch pull request merges itself when `CI` is
green. A major one waits for a person, because the core's calls may have changed.

The same tag twice does nothing the second time. To run it by hand for a core version that
is already out: **Actions → Core released → Run workflow**, with the tag (`v2.1.0`).

### Releasing

[release-please](https://github.com/googleapis/release-please) keeps a release pull request
open from the conventional commits on `main`. It merges itself when `CI` is green, tags the
version, and Packagist reads the tag. A `feat` commit makes a minor, a `fix` a patch, and a
`!` a major. `CHANGELOG.md` lists this package's own changes; a core bump appears as one line.

## License

MIT.
