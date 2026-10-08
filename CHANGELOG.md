# Changelog

Notable changes to `puntjes/laravel`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this package follows
[semantic versioning](https://semver.org/). From 1.0.0 that promise is the ordinary
one: a breaking change waits for the next major, so `^1.0` is safe to pin and leave.

## Unreleased

### Added

- **The Puntjes API changes of PuntjesApp/Puntjes#1084 arrive through the core SDK 1.5.0, with
  no change here.** This package keeps `^1.1`: it uses no code from 1.5.0. Run
  `composer update puntjes/php-sdk` once the core's 1.5.0 is out to get the new names below.
  - `RewardSummary::$isUnlimited` and `Reward::$isUnlimited`, from `Puntjes::rewards()->list()`
    and `Puntjes::products()->createReward()`. A reward with no stock limit has
    `remainingStock` 0, so a till that reads only that number shows it as sold out. Read
    `isUnlimited` first. On an older core, `totalStock` is null for the same rewards.
  - An optional idempotency key on `Puntjes::products()->createReward()`:
    `new CreateRewardFromProduct(..., idempotencyKey: 'reward-SKU-1')`. With a key the core
    retries a failed call, and a repeat with the same key, product and amounts gives back the
    first reward. The same key with another product or amount answers
    `IDEMPOTENCY_KEY_CONFLICT` (422). The core makes no key for you, so without one nothing
    changes.
  - `ErrorCode::UnsupportedMediaType` (`UNSUPPORTED_MEDIA_TYPE`, 415): the request body is not
    JSON or a form. The core always sends JSON, so this means a proxy or a custom PSR-18
    client you bound changed the request. It arrives as a plain `ApiException` and is never
    retried. On `^1.1` you already read it as `ApiException::code()`.
- **Three voucher additions arrive through the core SDK 1.4.0, with no change here.**
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
- **A cancelled redemption arrives through the core SDK 1.4.0, with no change here.** You get
  it when you run `composer update puntjes/php-sdk`:
  - `Puntjes::redemptions()->verify($code)` on a cancelled code throws `ApiException` with
    `ErrorCode::CodeCancelled` (`CODE_CANCELLED`, 422). The shop cancelled the redemption and the
    customer got the points back, so the till must not hand over the reward.
  - A redemption that you read can now have `RedemptionStatus::Cancelled`.
  - The shop can also cancel a code after the till verified it. That redemption keeps its
    `verifiedAt`, so `isVerified()` stays true. Read `status` first.

### Changed

- **Merged customers arrive through the core SDK, with no change here.** Puntjes lets a shop
  merge two accounts of the same person (PuntjesApp/Puntjes#1105). One account stays and the
  other closes. No route, field or shape changed, and this package keeps `^1.1`: every answer
  below already reaches your app on the core it requires. The core's 1.6.0
  (PuntjesApp/puntjes-php-sdk#28) names the code in the docblocks of the wallet methods.
  - `Puntjes::wallets()->adjust()` on a merged customer throws `ApiException` with
    `CUSTOMER_DEACTIVATED` (422) for a new idempotency key. A retry with a key used before the
    merge still returns that adjustment. A deactivated customer the shop did not merge keeps
    today's answers.
  - `Puntjes::wallets()->applePass()` and `googlePassUrl()` on a merged customer throw the same
    `CUSTOMER_DEACTIVATED` (422).
  - An adjustment's idempotency key also matches the adjustments of the accounts merged into
    the customer. A replay can then return the closed account's entry, with that account's
    `walletId` and `runningBalance`. The same key with another amount answers
    `IDEMPOTENCY_KEY_CONFLICT` (422).
  - A lookup of the closed account answers like any deactivated customer: `isDeactivated` is
    true and `walletBalance` is 0. Its active loyalty cards now find the account that stays.
    Its email address answers `CUSTOMER_NOT_FOUND` (404) when the account that stays has its
    own email address. When both accounts had an external id, the closed account keeps its own,
    and `updateByExternalId()` with it answers `EXTERNAL_ID_NOT_FOUND` (404).
  - Two tests pin that the 422 reaches your app once, on the adjustment and on both pass
    methods, through the facade.
- **A 401 now tells you what to fix, through the core SDK.** `UNAUTHENTICATED` means the token
  is missing, expired or revoked; a new token fixes it. `INVALID_CLIENT` now means the API
  client itself is wrong: it has no vendor, or it cannot use client credentials. A new token
  does not fix that; fix the client in the Puntjes portal. Both arrive as
  `AuthenticationException`. The core still gets one new token on every first 401, because
  an older Puntjes sent `INVALID_CLIENT` for an expired token. That new token goes into the
  Laravel cache as before, so your other workers use it without a grant of their own. Two
  tests now pin both cases through the cache-backed token store.
- **`Puntjes::customers()->find()` returns a deactivated customer, through the core SDK.**
  Before, it threw `NotFoundException`. Now `isDeactivated` is true and `status` is
  `CustomerStatus::Deactivated`; `^1.1` already reads both. `sendCard()` refuses such a
  customer with `CUSTOMER_DEACTIVATED` (422), where it answered 404. An anonymized customer
  is still not found.
- **`codeValidForHours` on `CreateRewardFromProduct` stops at 87600 (ten years).** A larger
  value, or an `availableUntil` before `availableFrom`, answers 422 `VALIDATION_ERROR`.
- Tests now pin that a 415 reaches your app once, that a deactivated customer from `find()`
  reads as deactivated, and that an unlimited reward differs from a sold-out one by
  `totalStock` on the core this package requires.
- **A discount can be on one product, through the core SDK.** Puntjes lets a discount reward,
  or a campaign discount gift, count on one product instead of the whole purchase. The API adds
  `product_reference` (the product's item number, or null for the whole purchase) to a
  discount's `type_specific_data` on the redemption routes and to the discount of a verified
  voucher, and a campaign's config may hold `gift.discount.product_id`. Nothing else changes.
  This package has no model of its own for these answers: they arrive as the core's
  `Redemption` and `VoucherVerification`, and on `^1.1` the old fields read as before, with the
  item number already in `$redemption->typeSpecificData['product_reference']`. Run
  `composer update puntjes/php-sdk` once the core's next release is out to read
  `$redemption->productReference()` and `$voucher->discount?->productReference`. For a discount
  on one product, pass `appliedTo()` the amount it counts on: that product's price, or its line
  total if your till applies it to every unit. A test now pins that the new
  answers reach your app with the old fields unchanged.
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
- **A duplicate email address answers 409, through the core SDK.** Registering or changing a
  customer throws `ConflictException` (`IDENTIFIER_DUPLICATE`, 409) when another customer of the
  same vendor already has that email address, as profile email or as email identifier, a
  deactivated customer included. Only the docs of the core changed. Run
  `composer update puntjes/php-sdk` to read them.

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
