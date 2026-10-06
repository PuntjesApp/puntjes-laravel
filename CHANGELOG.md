# Changelog

Notable changes to `puntjes/laravel`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package follows
[semantic versioning](https://semver.org/). From 1.0.0 that promise is the ordinary
one: a breaking change waits for the next major, so `^1.0` is safe to pin and leave.

## Unreleased

### Added

- **Four voucher additions arrive through the core SDK 1.3.0, with no change here.**
  `Puntjes::vouchers()` returns the core's `Vouchers` resource, so this package gets them
  when you run `composer update puntjes/php-sdk`:
  - `Puntjes::vouchers()->find($code)` reads a campaign bon without spending it. It returns a
    `VoucherLookup` with a `status` of `VoucherStatus::Valid`, `Used` or `Expired`. An expired
    bon is an answer, not an error. An unknown code throws `NotFoundException`.
  - `Puntjes::vouchers()->verify($code, idempotencyKey: …)` takes an optional key. The core
    sends it only when you give one, and then retries the call safely. A repeat with the same
    key on the same bon answers the first success again. Without a key, the call sends the
    same body as before and is never retried.
  - Each free product of a bon carries `productReference`, the vendor's item number, copied
    when the bon was issued. It is null when the product had none.
  - A discount bon's `discount` carries `productReference`, the item number of the one product
    the discount is for, or null for a discount on the whole purchase.

### Changed

- **The statistics loyalty block gains four import fields, through the core SDK.** After a shop
  moves its customers over from another loyalty system, the points they brought along never count
  as issued but did count when they were spent or expired, so the redemption and breakage rates
  could pass 1.0. The Puntjes API keeps every existing field as it was and adds
  `points_redeemed_from_import`, `points_expired_from_import`, `redemption_rate_excluding_import`
  and `breakage_rate_excluding_import`. This package has no statistics model of its own: the
  answer arrives as the core's `LoyaltyStatistics`, and on `^1.1` the old fields read as before.
  Run `composer update puntjes/php-sdk` once the core's next release is out to read the four new
  properties, and prefer `redemptionRateExcludingImport` over `redemptionRate`. A test now pins
  that the new answer reaches your app with the old fields unchanged.
- **The error `INVALID_JSON` (400) reaches this package through the core SDK.** The Puntjes API
  now answers it when it cannot read a request body: the JSON is cut off, or it is not valid
  UTF-8. The API creates, changes and sends nothing, and the same body fails the same way
  again, so the core never replays it. Before, such a body was read as empty. This package
  maps no error codes of its own: the error arrives as the core's `ApiException`, and `^1.1`
  already lets it through as `ApiException::code()`. Run `composer update puntjes/php-sdk`
  once the core's next release is out to get `ErrorCode::InvalidJson` on the enum. A test
  now pins that the error reaches your app once and untouched.
- **Two more answers change, with no change here.** Text that is not valid UTF-8 in a query
  value or form field now answers 422 `VALIDATION_ERROR` and names the field, so it arrives as
  a `ValidationException`. Before, it answered 500. A path with a NUL byte or invalid UTF-8
  now answers 404 `ROUTE_NOT_FOUND`, so it arrives as a `NotFoundException`.
- **A campaign's `config['scope']` reaches your app as `whole` or `whole_purchase`, and both mean
  the whole purchase.** An older `days_of_week` schedule can also carry Sunday as `7` next to `0`.
  The client this package builds hands both over as the API sends them, and a test now pins that.
- **The send-card refusal `CUSTOMER_EMAIL_SUPPRESSED` (422) reaches this package through
  the core SDK.** The Puntjes API now refuses to email a loyalty card when earlier mail to
  the customer's address bounced or was marked as spam; nothing is queued and the
  per-customer cooldown is not spent. This package maps no error codes of its own: every
  `ApiException` comes from `puntjes/php-sdk`, and `^1.1` already lets the code through as
  `ApiException::code()`. Run `composer update puntjes/php-sdk` once the core's next release
  is out to get `ErrorCode::CustomerEmailSuppressed` on the enum, and catch it where you
  call `Puntjes::customers()->sendCard()` to ask the customer for an address that works.

## 1.1.0 — 2026-09-30

### Changed

- **Requires `puntjes/php-sdk` `^1.1`.** The core's 1.1.0 adds
  `Redemptions::forCustomer()`, so `Puntjes::redemptions()->forCustomer($customerId,
  RedemptionStatus::Valid)` lists the rewards a customer still has to collect. A till whose
  customer comes without the confirmation code finds the reward that way and verifies it
  as usual. The raised floor makes sure an app that installs this package gets the method.

## 1.0.0 — 2026-08-30

The first stable release, tracking `puntjes/php-sdk` 1.0.0.

### Changed

- **Requires `puntjes/php-sdk` `^1.0`.** The core's 1.0.0 fixes the customer
  registration contract: a loyalty card read from the API used to decode with a null
  type, and a customer could not be registered without one. Nobody got that fix through
  this package while it pinned `^0.2`. See the core's changelog for the rename an
  upgrade needs.

### Added

- **`http.retry_base_delay`**, seconds before the first retry, doubling after each. The
  core has always accepted it; this package had no key for it, so the only way to change
  backoff was to rebind `Puntjes\Config` yourself. Binding your own PSR-18 client, which
  is this package's documented extension point, gives no control over it at all: the
  backoff lives in the SDK's transport, around whatever client you bind. A negative value
  is now refused at resolution rather than reaching `usleep()` mid-retry.
- **`http.default_headers`**, sent on every request. Same story. The package's own
  `User-Agent` is still set, and listing `User-Agent` yourself replaces it rather than
  being ignored.

### Upgrading

If you published `config/puntjes.php` under an earlier version, add `retry_base_delay`
and `default_headers` to the `http` array in your copy, or re-publish it. Laravel merges
a package config only at the top level, so a published `http` array replaces the
package's whole `http` block: without the edit the two new settings are silently absent
and `PUNTJES_RETRY_BASE_DELAY` does nothing.

### Fixed

- **`Puntjes::config()` is visible to an IDE and to static analysis**, for the first
  time. It always worked through `__callStatic`, but it had never been in the facade's
  `@method` block, so both were told it did not exist. Two tests now compare the facade's
  documented method names against the client's real ones, in both directions, because
  that drift is invisible at runtime: a missing line loses only tooling, and a line for a
  method the SDK has removed still forwards to nothing.

This package is Laravel wiring only — config, provider, facade and a cache-backed
token store. Changes to the API surface itself live in
[`puntjes/php-sdk`'s changelog](https://github.com/PuntjesApp/puntjes-php-sdk/blob/main/CHANGELOG.md).

## 0.2.0 — 2026-08-28

### Changed

- **Requires `puntjes/php-sdk` `^0.2`.** Under composer's caret rules a `0.x` release
  is not compatible with the one before it, so the previous `^0.1` constraint excluded
  the new SDK outright — this package could not have installed it at all. The SDK
  release brings branches, campaign bonnen, external-id linking, loyalty-card sends and
  marketing consent, and narrows two types to match what the API sends. Read its
  changelog before upgrading: `Campaign::$multiplier` and `Statistics::$loyalty` are now
  nullable.

### Added

- `Puntjes::vouchers()` — the facade accessor for the SDK's new campaign-bon resource.
  The SDK exposes its endpoint groups as readonly properties, which a facade cannot
  proxy, so every group needs one of these.

### Note

This release also carries the previously untagged `chore: resolve the core SDK from
Packagist`, which removed the temporary path repository pointing at a sibling checkout.

## 0.1.1 — 2026-07-30

### Fixed

- Default `PUNTJES_BASE_URL` to the documented base URL,
  `https://puntjes.app/api/v1`.

### Changed

- Isolate the shipped-default test from the ambient environment, so a developer with
  `PUNTJES_BASE_URL` exported no longer sees a passing test for the wrong reason.

## 0.1.0 — 2026-07-30

Initial release: the service provider, the `Puntjes` facade with an accessor per
endpoint group, a publishable config file, and `CacheTokenStore` — the part that
matters in production, so an app grants one access token per hour rather than one per
web request.
