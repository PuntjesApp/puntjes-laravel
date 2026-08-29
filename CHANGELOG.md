# Changelog

Notable changes to `puntjes/laravel`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package follows
[semantic versioning](https://semver.org/). From 1.0.0 that promise is the ordinary
one: a breaking change waits for the next major, so `^1.0` is safe to pin and leave.

## 1.0.0 — 2026-08-29

The first stable release, tracking `puntjes/php-sdk` 1.0.0.

### Changed

- **Requires `puntjes/php-sdk` `^1.0`.** The core's 1.0.0 fixes the customer
  registration contract: a loyalty card read from the API used to decode with a null
  type, and a customer could not be registered without one. Nobody got that fix through
  this package while it pinned `^0.2`. See the core's changelog for the rename an
  upgrade needs.

### Added

- **`http.retry_base_delay`**, seconds before the first retry, doubling after each. The
  core has always accepted it; this package had no key for it, so tuning backoff meant
  binding your own client and giving up the package.
- **`http.default_headers`**, sent on every request. Same story. The package's own
  `User-Agent` is still set, and listing `User-Agent` yourself replaces it rather than
  being ignored.

### Fixed

- **`Puntjes::config()` is visible again to an IDE and to static analysis.** It always
  worked through `__callStatic`, but it was missing from the facade's `@method` block,
  so both were told it did not exist. A test now compares the facade's documented
  surface against the client's real one, because that drift is invisible at runtime.

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
