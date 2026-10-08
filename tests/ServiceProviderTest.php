<?php

declare(strict_types=1);

namespace Puntjes\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Puntjes\Auth\TokenStore;
use Puntjes\Config;
use Puntjes\Enum\CustomerStatus;
use Puntjes\Enum\Period;
use Puntjes\Exception\ApiException;
use Puntjes\Exception\AuthenticationException;
use Puntjes\Exception\ConfigurationException;
use Puntjes\Laravel\CacheTokenStore;
use Puntjes\Laravel\Facades\Puntjes as PuntjesFacade;
use Puntjes\Puntjes;
use Puntjes\Request\AdjustWallet;
use Puntjes\Request\UpsertProduct;
use Puntjes\Resource\Customers;
use Puntjes\Resource\Products;
use Puntjes\Resource\Vouchers;

final class ServiceProviderTest extends TestCase
{
    public function test_it_resolves_a_configured_client(): void
    {
        $client = $this->app->make(Puntjes::class);

        self::assertInstanceOf(Puntjes::class, $client);
        self::assertSame('https://app.puntjes.test', $client->config()->baseUrl);
        self::assertSame('test-client', $client->config()->clientId);
    }

    public function test_the_client_is_a_singleton(): void
    {
        self::assertSame($this->app->make(Puntjes::class), $this->app->make(Puntjes::class));
    }

    public function test_it_binds_the_cache_backed_token_store(): void
    {
        self::assertInstanceOf(CacheTokenStore::class, $this->app->make(TokenStore::class));
    }

    public function test_config_retries_reach_the_sdk(): void
    {
        config()->set('puntjes.http.retries', 5);

        self::assertSame(5, $this->app->make(Config::class)->maxRetries);
    }

    public function test_config_retry_base_delay_reaches_the_sdk(): void
    {
        config()->set('puntjes.http.retry_base_delay', 1.5);

        self::assertSame(1.5, $this->app->make(Config::class)->retryBaseDelay);
    }

    public function test_configured_headers_reach_the_sdk(): void
    {
        config()->set('puntjes.http.default_headers', ['X-Trace-Id' => 'abc123']);

        self::assertSame('abc123', $this->app->make(Config::class)->defaultHeaders['X-Trace-Id'] ?? null);
    }

    public function test_the_package_user_agent_survives_configured_headers(): void
    {
        config()->set('puntjes.http.default_headers', ['X-Trace-Id' => 'abc123']);

        $headers = $this->app->make(Config::class)->defaultHeaders;

        self::assertArrayHasKey('User-Agent', $headers);
        self::assertStringStartsWith('puntjes-laravel/', $headers['User-Agent']);
    }

    public function test_a_configured_user_agent_wins(): void
    {
        config()->set('puntjes.http.default_headers', ['User-Agent' => 'acme-pos/2.1']);

        self::assertSame('acme-pos/2.1', $this->app->make(Config::class)->defaultHeaders['User-Agent']);
    }

    /**
     * The facade is a hand-maintained copy of the client's surface, so it drifts the
     * moment the SDK grows or drops a method. That drift is invisible: `__callStatic`
     * forwards the call anyway, so the only thing lost is what an IDE and a static
     * analyser can see, which nothing else here exercises. Both directions are checked,
     * because a facade advertising a method the SDK removed fails just as quietly.
     */
    public function test_the_facade_documents_every_method_on_the_client(): void
    {
        $missing = array_diff(
            $this->clientMethods(),
            $this->documentedMethods(),
            $this->facadeAccessors(),
        );

        self::assertSame([], array_values($missing), 'The facade does not mention: '.implode(', ', $missing));
    }

    public function test_the_facade_documents_nothing_the_client_lacks(): void
    {
        $ghosts = array_diff($this->documentedMethods(), $this->clientMethods());

        self::assertSame([], array_values($ghosts), 'The facade advertises what the SDK does not have: '.implode(', ', $ghosts));
    }

    /** @return array<int, string> */
    private function documentedMethods(): array
    {
        preg_match_all(
            '/@method\s+static\s+\S+\s+(\w+)\(/',
            (string) (new \ReflectionClass(PuntjesFacade::class))->getDocComment(),
            $matches,
        );

        return $matches[1];
    }

    /**
     * Only what this facade declares. `getMethods()` also returns everything public on
     * `Illuminate\Support\Facades\Facade` (`swap`, `resolved`, `spy` and eleven more),
     * and letting those through would excuse the SDK from documenting a method that
     * happened to share one of their names.
     *
     * @return array<int, string>
     */
    private function facadeAccessors(): array
    {
        $facade = new \ReflectionClass(PuntjesFacade::class);

        return array_values(array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $facade->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $facade->getName(),
            ),
        ));
    }

    /** @return array<int, string> */
    private function clientMethods(): array
    {
        return array_diff(
            array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                (new \ReflectionClass(Puntjes::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            ),
            // Constructors, not surface a facade forwards.
            ['__construct', 'make', 'fromConfig'],
        );
    }

    public function test_a_negative_retry_delay_is_refused(): void
    {
        config()->set('puntjes.http.retry_base_delay', -1);

        $this->expectException(ConfigurationException::class);

        $this->app->make(Config::class);
    }

    public function test_the_documented_base_url_including_the_api_prefix_is_accepted(): void
    {
        // What an integrator pastes out of the API docs. Taken verbatim it would send
        // the token request to /api/v1/oauth/token, which does not exist.
        config()->set('puntjes.base_url', 'https://app.puntjes.test/api/v1');

        $config = $this->app->make(Config::class);

        self::assertSame('https://app.puntjes.test/oauth/token', $config->tokenUrl());
        self::assertSame('https://app.puntjes.test/api/v1/me', $config->apiUrl('/me'));
    }

    public function test_the_bare_host_resolves_identically(): void
    {
        config()->set('puntjes.base_url', 'https://app.puntjes.test');

        $config = $this->app->make(Config::class);

        self::assertSame('https://app.puntjes.test/oauth/token', $config->tokenUrl());
        self::assertSame('https://app.puntjes.test/api/v1/me', $config->apiUrl('/me'));
    }

    public function test_the_shipped_default_base_url_is_usable(): void
    {
        // The default in config/puntjes.php ships to every install, so it must itself
        // be a form the SDK accepts — and must not double up the /api/v1 prefix.
        //
        // env() reads the ambient process environment, so a PUNTJES_BASE_URL exported
        // in the developer's shell (e.g. for the SDK's contract suite) would silently
        // replace the default under test. Clear it for the duration.
        $backup = [
            'env' => $_ENV['PUNTJES_BASE_URL'] ?? null,
            'server' => $_SERVER['PUNTJES_BASE_URL'] ?? null,
            'getenv' => getenv('PUNTJES_BASE_URL'),
        ];

        unset($_ENV['PUNTJES_BASE_URL'], $_SERVER['PUNTJES_BASE_URL']);
        putenv('PUNTJES_BASE_URL');

        try {
            $shipped = require __DIR__.'/../config/puntjes.php';

            $config = new Config('id', 'secret', $shipped['base_url']);

            self::assertSame('https://puntjes.app/api/v1/me', $config->apiUrl('/me'));
            self::assertSame('https://puntjes.app/oauth/token', $config->tokenUrl());
        } finally {
            if ($backup['env'] !== null) {
                $_ENV['PUNTJES_BASE_URL'] = $backup['env'];
            }

            if ($backup['server'] !== null) {
                $_SERVER['PUNTJES_BASE_URL'] = $backup['server'];
            }

            if ($backup['getenv'] !== false) {
                putenv('PUNTJES_BASE_URL='.$backup['getenv']);
            }
        }
    }

    public function test_missing_credentials_fail_on_resolution_not_at_boot(): void
    {
        // Booting succeeded — proven by getting this far with the provider registered.
        config()->set('puntjes.client_secret', null);
        $this->app->forgetInstance(Config::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('PUNTJES_CLIENT_ID and PUNTJES_CLIENT_SECRET');

        $this->app->make(Config::class);
    }

    public function test_the_facade_exposes_the_resource_groups(): void
    {
        self::assertInstanceOf(Customers::class, PuntjesFacade::customers());
        self::assertInstanceOf(Products::class, PuntjesFacade::products());
        self::assertInstanceOf(Vouchers::class, PuntjesFacade::vouchers());
        self::assertSame($this->app->make(Puntjes::class), PuntjesFacade::client());
    }

    public function test_the_redemptions_group_lists_a_customers_redemptions(): void
    {
        self::assertTrue(
            method_exists(PuntjesFacade::redemptions(), 'forCustomer'),
            'puntjes/php-sdk is older than 1.1.0, the release that added Redemptions::forCustomer().',
        );
    }

    /**
     * The API sends `whole` or `whole_purchase` as a campaign's scope, both the whole purchase, and an older
     * schedule can store Sunday as 7. The client this package builds hands both over as the API sent them.
     */
    public function test_a_campaign_reaches_the_app_with_either_scope_word_and_a_sunday_seven(): void
    {
        $this->app->instance(ClientInterface::class, new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $body = str_ends_with($request->getUri()->getPath(), '/oauth/token')
                    ? ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'test-token']
                    : ['data' => [
                        'data' => [
                            self::campaign(1, ['scope' => 'whole'], ['days' => [6, 7]]),
                            self::campaign(2, ['scope' => 'whole_purchase'], ['days' => [6, 0]]),
                        ],
                        'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
                        'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 2],
                    ]];

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body));
            }

            /**
             * @param  array<string, mixed>  $config
             * @param  array<string, mixed>  $recurrenceConfig
             * @return array<string, mixed>
             */
            private static function campaign(int $id, array $config, array $recurrenceConfig): array
            {
                return [
                    'id' => $id, 'name' => 'Weekend', 'family' => 'purchase', 'moment' => null,
                    'config' => $config, 'version' => 1,
                    'multiplier' => 2, 'recurrence_type' => 'days_of_week',
                    'recurrence_config' => $recurrenceConfig,
                    'schedule_summary' => 'Elk weekend', 'starts_at' => '2026-08-01', 'ends_at' => null,
                    'status' => ['value' => 'active', 'label' => 'Actief'],
                    'min_transaction_amount' => null,
                    'created_at' => null, 'updated_at' => null,
                ];
            }
        });

        [$builderMade, $older] = PuntjesFacade::campaigns()->list()->firstPage()->items;

        self::assertSame(['scope' => 'whole'], $builderMade->config);
        self::assertSame(['days' => [6, 7]], $builderMade->recurrenceConfig);
        self::assertSame(['scope' => 'whole_purchase'], $older->config);
        self::assertSame(['days' => [6, 0]], $older->recurrenceConfig);
    }

    /**
     * Puntjes adds four import fields to the statistics loyalty block and keeps every other field as it was. The
     * client this package builds still reads that answer, with the old fields unchanged, on the core it requires.
     */
    public function test_a_statistics_answer_with_the_import_fields_reaches_the_app_unchanged(): void
    {
        $this->app->instance(ClientInterface::class, new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $body = str_ends_with($request->getUri()->getPath(), '/oauth/token')
                    ? ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'test-token']
                    : ['data' => [
                        'period' => [
                            'preset' => '30d', 'from' => '', 'to' => '', 'timezone' => 'Europe/Brussels',
                            'granularity' => 'daily',
                        ],
                        'commerce' => [
                            'orders' => 0, 'revenue_cents' => 0, 'average_order_value_cents' => 0,
                            'itemized' => [], 'volume_trend' => [],
                        ],
                        'loyalty' => [
                            'points_issued' => 600, 'points_redeemed' => 2000, 'points_expired' => 500,
                            'net_adjustments' => 0, 'redemption_rate' => 3.333, 'breakage_rate' => 0.8333,
                            'points_redeemed_from_import' => 1500, 'points_expired_from_import' => 400,
                            'redemption_rate_excluding_import' => 0.833, 'breakage_rate_excluding_import' => 0.1667,
                        ],
                    ]];

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body));
            }
        });

        $loyalty = PuntjesFacade::statistics()->get(Period::ThirtyDays)->loyalty;

        self::assertNotNull($loyalty);
        self::assertSame(600, $loyalty->pointsIssued);
        self::assertSame(2000, $loyalty->pointsRedeemed);
        self::assertSame(500, $loyalty->pointsExpired);
        self::assertSame(3.333, $loyalty->redemptionRate);
        self::assertSame(0.8333, $loyalty->breakageRate);
    }

    /**
     * Puntjes lets a discount count on one product and adds `product_reference` to a redemption's discount block and to
     * a verified voucher's discount. The old fields reach the app unchanged; the voucher's item number waits for the core.
     */
    public function test_a_discount_on_one_product_reaches_the_app_with_its_old_fields_unchanged(): void
    {
        $this->app->instance(ClientInterface::class, new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $path = $request->getUri()->getPath();
                $body = match (true) {
                    str_ends_with($path, '/oauth/token') => ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'test-token'],
                    str_ends_with($path, '/verify') => ['data' => [
                        'voucher_code' => 'BON-KT',
                        'discount' => ['kind' => 'percentage', 'percentage' => 20, 'product_reference' => 'KT-10234'],
                        'valid_until' => null, 'consumed_at' => '2026-10-06T10:00:00+00:00',
                        'campaign_id' => 4, 'kind' => 'discount', 'products' => null,
                    ]],
                    default => ['data' => [
                        'redemption_id' => 12, 'confirmation_code' => 'PNTJ-KT000001', 'status' => 'valid',
                        'reward' => ['name' => 'Thermometer korting', 'type' => 'discount'],
                        'customer' => ['name' => 'Ada'], 'points_deducted' => 150,
                        'redeemed_at' => '2026-10-06T10:00:00+00:00', 'verified_at' => null, 'expires_at' => null,
                        'type_specific_data' => ['discount_value' => 20, 'discount_type' => 'percentage', 'product_reference' => 'KT-10234'],
                    ]],
                };

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body));
            }
        });

        $voucher = PuntjesFacade::vouchers()->verify('BON-KT');
        $redemption = PuntjesFacade::redemptions()->find('PNTJ-KT000001');

        self::assertSame('percentage', $voucher->discount?->kind);
        self::assertSame(20, $voucher->discount?->percentage);
        self::assertSame(20, $redemption->typeSpecificData['discount_value']);
        self::assertSame('percentage', $redemption->typeSpecificData['discount_type']);
        self::assertSame('KT-10234', $redemption->typeSpecificData['product_reference']);
    }

    /**
     * The API answers `400 INVALID_JSON` when it cannot read a request body, and it changes nothing. This package
     * adds no mapping of its own: the error reaches the app as the core's ApiException, and a PUT, which the core
     * normally replays, goes out once, because the same body would fail the same way.
     */
    public function test_an_unreadable_body_reaches_the_app_as_an_api_exception_and_is_sent_once(): void
    {
        $http = new class implements ClientInterface
        {
            public int $apiRequests = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if (str_ends_with($request->getUri()->getPath(), '/oauth/token')) {
                    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                        'token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'test-token',
                    ]));
                }

                $this->apiRequests++;

                return new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
                    'error' => [
                        'code' => 'INVALID_JSON',
                        'message' => 'The request body is not valid JSON.',
                        'status' => 400,
                        'request_id' => 'req_1',
                    ],
                ]));
            }
        };
        $this->app->instance(ClientInterface::class, $http);

        try {
            PuntjesFacade::products()->upsert('SKU-1', new UpsertProduct(name: 'Brood'));
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame('INVALID_JSON', $e->code());
            self::assertSame(400, $e->status());
        }

        self::assertSame(1, $http->apiRequests);
    }

    /**
     * Puntjes answers `401 UNAUTHENTICATED` for a token it no longer accepts. The core gets one new token and tries
     * again, and the new token lands in the Laravel cache, so the next client in another request uses it without a grant.
     */
    public function test_a_refused_token_is_replaced_in_the_laravel_cache(): void
    {
        $http = $this->fakeApi(static fn (RequestInterface $request): array => $request->getHeaderLine('Authorization') === 'Bearer token-2'
            ? [200, ['data' => [['id' => 3, 'name' => 'Koffie', 'type' => 'product', 'point_cost' => 100, 'remaining_stock' => 4]]]]
            : [401, self::error('UNAUTHENTICATED', 401)]);

        self::assertCount(1, PuntjesFacade::rewards()->list());
        self::assertSame(2, $http->grants);
        self::assertSame(2, $http->apiRequests);

        $this->app->forgetInstance(Puntjes::class);
        PuntjesFacade::clearResolvedInstances();
        PuntjesFacade::rewards()->list();

        self::assertSame(2, $http->grants);
        self::assertSame(3, $http->apiRequests);
    }

    /**
     * `401 INVALID_CLIENT` now means the client itself is wrong, so a new token does not fix it. The core still tries one
     * new token, because an older Puntjes sent this code for an expired token, and then hands the code to the app.
     */
    public function test_an_invalid_client_reaches_the_app_after_one_new_token(): void
    {
        $http = $this->fakeApi(static fn (): array => [401, self::error('INVALID_CLIENT', 401)]);

        try {
            PuntjesFacade::rewards()->list();
            self::fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            self::assertSame('INVALID_CLIENT', $e->code());
            self::assertSame(401, $e->status());
        }

        self::assertSame(2, $http->grants);
        self::assertSame(2, $http->apiRequests);
    }

    /**
     * The API answers `415 UNSUPPORTED_MEDIA_TYPE` when a body is not JSON or a form. The core always sends JSON, so
     * something between the app and the API changed the request. The error reaches the app once, also on a PUT.
     */
    public function test_an_unsupported_media_type_reaches_the_app_as_an_api_exception_and_is_sent_once(): void
    {
        $http = $this->fakeApi(static fn (): array => [415, self::error('UNSUPPORTED_MEDIA_TYPE', 415)]);

        try {
            PuntjesFacade::products()->upsert('SKU-1', new UpsertProduct(name: 'Brood'));
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame('UNSUPPORTED_MEDIA_TYPE', $e->code());
            self::assertSame(415, $e->status());
        }

        self::assertSame(1, $http->apiRequests);
    }

    /** `find()` now returns a deactivated customer, where it was a 404, and the app can tell it is deactivated. */
    public function test_find_returns_a_deactivated_customer(): void
    {
        $this->fakeApi(static fn (): array => [200, ['data' => [
            'id' => 7, 'status' => 'deactivated', 'deactivated_at' => '2026-10-01T09:00:00+00:00', 'is_deactivated' => true,
        ]]]);

        $customer = PuntjesFacade::customers()->find(7);

        self::assertTrue($customer->isDeactivated);
        self::assertSame(CustomerStatus::Deactivated, $customer->status);
    }

    /**
     * A shop can merge two accounts of one person, and the closed account answers `422 CUSTOMER_DEACTIVATED` on a
     * wallet adjustment. The refusal reaches the app once, although the adjustment carries a key the core may replay.
     */
    public function test_an_adjustment_of_a_merged_customer_reaches_the_app_once(): void
    {
        $http = $this->fakeApi(static fn (): array => [422, self::error('CUSTOMER_DEACTIVATED', 422)]);

        try {
            PuntjesFacade::wallets()->adjust(7, AdjustWallet::credit(100, 'Goodwill', 'adjust-1'));
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame('CUSTOMER_DEACTIVATED', $e->code());
            self::assertSame(422, $e->status());
        }

        self::assertSame(1, $http->apiRequests);
    }

    /** The wallet pass of a merged customer answers `422 CUSTOMER_DEACTIVATED`, on both platforms, and is sent once. */
    public function test_a_wallet_pass_of_a_merged_customer_reaches_the_app_once(): void
    {
        $http = $this->fakeApi(static fn (): array => [422, self::error('CUSTOMER_DEACTIVATED', 422)]);

        foreach (['applePass', 'googlePassUrl'] as $method) {
            try {
                PuntjesFacade::wallets()->{$method}(7);
                self::fail('Expected an ApiException.');
            } catch (ApiException $e) {
                self::assertSame(ApiException::class, $e::class);
                self::assertSame('CUSTOMER_DEACTIVATED', $e->code());
                self::assertSame(422, $e->status());
            }
        }

        self::assertSame(2, $http->apiRequests);
    }

    /**
     * A reward with no stock limit has `remaining_stock` 0, and Puntjes adds `is_unlimited` to say so. On a core
     * older than 1.5.0 the app tells it from a sold-out reward by `totalStock`, which is null only for the unlimited one.
     */
    public function test_an_unlimited_reward_reaches_the_app_apart_from_a_sold_out_one(): void
    {
        $this->fakeApi(static fn (): array => [200, ['data' => [
            ['id' => 1, 'name' => 'Koffie', 'type' => 'product', 'point_cost' => 100,
                'total_stock' => null, 'remaining_stock' => 0, 'is_unlimited' => true],
            ['id' => 2, 'name' => 'Taart', 'type' => 'product', 'point_cost' => 300,
                'total_stock' => 5, 'remaining_stock' => 0, 'is_unlimited' => false],
        ]]]);

        [$unlimited, $soldOut] = PuntjesFacade::rewards()->list();

        self::assertSame(0, $unlimited->remainingStock);
        self::assertNull($unlimited->totalStock);
        self::assertSame(0, $soldOut->remainingStock);
        self::assertSame(5, $soldOut->totalStock);
    }

    public function test_a_rewards_discount_kind_reaches_the_app_before_the_redemption(): void
    {
        $this->fakeApi(static fn (): array => [200, ['data' => [
            ['id' => 1, 'name' => 'Tien procent', 'type' => 'discount', 'point_cost' => 100,
                'total_stock' => null, 'remaining_stock' => 0, 'is_unlimited' => true,
                'discount_type' => 'percentage', 'discount_value' => 10],
            ['id' => 2, 'name' => 'Vijf euro', 'type' => 'discount', 'point_cost' => 200,
                'total_stock' => null, 'remaining_stock' => 0, 'is_unlimited' => true,
                'discount_type' => 'fixed_amount', 'discount_value' => 500],
        ]]]);

        [$percentage, $fixed] = PuntjesFacade::rewards()->list();

        self::assertTrue($percentage->isPercentageDiscount());
        self::assertSame(10, $percentage->discountValue);
        self::assertTrue($fixed->isFixedAmountDiscount());
        self::assertSame(500, $fixed->discountValue);
    }

    /**
     * Bind a PSR-18 client that grants `token-1`, `token-2`, ... and answers every API call with `$answer`.
     *
     * @param  callable(RequestInterface): array{0: int, 1: array<string, mixed>}  $answer
     */
    private function fakeApi(callable $answer): object
    {
        $http = new class($answer) implements ClientInterface
        {
            public int $grants = 0;

            public int $apiRequests = 0;

            /** @var callable(RequestInterface): array{0: int, 1: array<string, mixed>} */
            private $answer;

            /** @param  callable(RequestInterface): array{0: int, 1: array<string, mixed>}  $answer */
            public function __construct(callable $answer)
            {
                $this->answer = $answer;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if (str_ends_with($request->getUri()->getPath(), '/oauth/token')) {
                    $this->grants++;
                    [$status, $body] = [200, ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'token-'.$this->grants]];
                } else {
                    $this->apiRequests++;
                    [$status, $body] = ($this->answer)($request);
                }

                return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
            }
        };
        $this->app->instance(ClientInterface::class, $http);

        return $http;
    }

    /** @return array<string, mixed> */
    private static function error(string $code, int $status): array
    {
        return ['error' => ['code' => $code, 'message' => $code, 'status' => $status, 'request_id' => 'req_1']];
    }

    public function test_the_config_file_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'puntjes-config'])->assertSuccessful();

        self::assertFileExists(config_path('puntjes.php'));

        @unlink(config_path('puntjes.php'));
    }
}
