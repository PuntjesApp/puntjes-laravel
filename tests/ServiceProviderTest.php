<?php

declare(strict_types=1);

namespace Puntjes\Laravel\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Puntjes\Auth\TokenStore;
use Puntjes\Exception\ApiException;
use Puntjes\Exception\ConfigurationException;
use Puntjes\Exception\ServerException;
use Puntjes\Generated\Models\UpsertProductData;
use Puntjes\Generated\PuntjesClient;
use Puntjes\Generated\Rewards\RewardsRequestBuilder;
use Puntjes\Laravel\CacheTokenStore;
use Puntjes\Laravel\Facades\Puntjes as PuntjesFacade;

final class ServiceProviderTest extends TestCase
{
    public function test_it_resolves_the_generated_client_once(): void
    {
        $client = $this->app->make(PuntjesClient::class);

        self::assertInstanceOf(PuntjesClient::class, $client);
        self::assertSame($client, $this->app->make(PuntjesClient::class));
        self::assertSame($client, $this->app->make('puntjes'));
    }

    public function test_it_binds_the_cache_backed_token_store(): void
    {
        self::assertInstanceOf(CacheTokenStore::class, $this->app->make(TokenStore::class));
    }

    /** @return array<string, array{0: string}> */
    public static function baseUrls(): array
    {
        return [
            'the bare host' => ['https://app.puntjes.test'],
            'the documented base URL with /api/v1' => ['https://app.puntjes.test/api/v1'],
        ];
    }

    #[DataProvider('baseUrls')]
    public function test_a_facade_call_reaches_the_configured_host(string $baseUrl): void
    {
        config()->set('puntjes.base_url', $baseUrl);
        $http = $this->fakeApi(static fn (): array => [200, ['data' => []]]);

        PuntjesFacade::rewards()->get()->wait();

        self::assertSame([
            'https://app.puntjes.test/oauth/token',
            'https://app.puntjes.test/api/v1/rewards',
        ], $http->urls);
    }

    public function test_the_shipped_default_base_url_reaches_puntjes(): void
    {
        $backup = [
            'env' => $_ENV['PUNTJES_BASE_URL'] ?? null,
            'server' => $_SERVER['PUNTJES_BASE_URL'] ?? null,
            'getenv' => getenv('PUNTJES_BASE_URL'),
        ];
        unset($_ENV['PUNTJES_BASE_URL'], $_SERVER['PUNTJES_BASE_URL']);
        putenv('PUNTJES_BASE_URL');

        try {
            $shipped = require __DIR__.'/../config/puntjes.php';
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

        config()->set('puntjes.base_url', $shipped['base_url']);
        $http = $this->fakeApi(static fn (): array => [200, ['data' => []]]);

        PuntjesFacade::rewards()->get()->wait();

        self::assertSame(['https://puntjes.app/oauth/token', 'https://puntjes.app/api/v1/rewards'], $http->urls);
    }

    public function test_the_configured_retries_reach_the_core(): void
    {
        config()->set('puntjes.http.retries', 0);
        $http = $this->fakeApi(static fn (): array => [503, self::error('SERVICE_UNAVAILABLE', 503)]);

        try {
            PuntjesFacade::rewards()->get()->wait();
            self::fail('Expected a ServerException.');
        } catch (ServerException) {
        }

        self::assertSame(1, $http->apiRequests);
    }

    public function test_a_non_default_retry_count_reaches_the_core(): void
    {
        config()->set('puntjes.http.retries', 1);
        $http = $this->fakeApi(static fn (): array => [503, self::error('SERVICE_UNAVAILABLE', 503)]);

        try {
            PuntjesFacade::rewards()->get()->wait();
            self::fail('Expected a ServerException.');
        } catch (ServerException) {
        }

        self::assertSame(2, $http->apiRequests);
    }

    public function test_a_bound_sleeper_replaces_the_wait_between_retries(): void
    {
        config()->set('puntjes.http.retries', 2);
        $slept = [];
        $this->app->instance('puntjes.sleeper', static function (float $seconds) use (&$slept): void {
            $slept[] = $seconds;
        });
        $http = $this->fakeApi(static fn (): array => [503, self::error('SERVICE_UNAVAILABLE', 503)]);
        $started = microtime(true);

        try {
            PuntjesFacade::rewards()->get()->wait();
            self::fail('Expected a ServerException.');
        } catch (ServerException) {
        }

        self::assertSame(3, $http->apiRequests);
        self::assertCount(2, $slept);
        self::assertLessThan(0.5, microtime(true) - $started);
    }

    public function test_without_a_bound_client_the_package_http_client_sends_the_requests(): void
    {
        $http = $this->fakeApi(static fn (): array => [200, ['data' => []]]);
        $this->app->forgetInstance(ClientInterface::class);
        unset($this->app[ClientInterface::class]);
        $this->app->instance('puntjes.http', $http);

        PuntjesFacade::rewards()->get()->wait();

        self::assertSame(1, $http->apiRequests);
    }

    public function test_configured_headers_reach_the_request_beside_the_package_user_agent(): void
    {
        config()->set('puntjes.http.default_headers', ['X-Trace-Id' => 'abc123']);
        $http = $this->fakeApi(static fn (): array => [200, ['data' => []]]);

        PuntjesFacade::rewards()->get()->wait();

        self::assertSame('abc123', $http->lastApiRequest?->getHeaderLine('X-Trace-Id'));
        self::assertStringStartsWith('puntjes-laravel/', (string) $http->lastApiRequest?->getHeaderLine('User-Agent'));
    }

    public function test_a_configured_user_agent_wins(): void
    {
        config()->set('puntjes.http.default_headers', ['User-Agent' => 'acme-pos/2.1']);
        $http = $this->fakeApi(static fn (): array => [200, ['data' => []]]);

        PuntjesFacade::rewards()->get()->wait();

        self::assertSame('acme-pos/2.1', $http->lastApiRequest?->getHeaderLine('User-Agent'));
    }

    public function test_the_package_http_client_uses_the_configured_timeouts(): void
    {
        config()->set('puntjes.http.timeout', 7.5);
        config()->set('puntjes.http.connect_timeout', 2.5);

        $http = $this->app->make('puntjes.http');

        self::assertInstanceOf(Guzzle::class, $http);
        self::assertSame(7.5, $http->getConfig('timeout'));
        self::assertSame(2.5, $http->getConfig('connect_timeout'));
        self::assertFalse($http->getConfig('http_errors'));
    }

    public function test_missing_credentials_fail_on_resolution_not_at_boot(): void
    {
        config()->set('puntjes.client_secret', null);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('PUNTJES_CLIENT_ID and PUNTJES_CLIENT_SECRET');

        $this->app->make(PuntjesClient::class);
    }

    public function test_the_facade_forwards_to_the_generated_client(): void
    {
        self::assertInstanceOf(RewardsRequestBuilder::class, PuntjesFacade::rewards());
        self::assertSame($this->app->make(PuntjesClient::class), PuntjesFacade::client());
    }

    public function test_the_facade_documents_every_method_of_the_client(): void
    {
        $missing = array_diff($this->clientMethods(), $this->documentedMethods());

        self::assertSame([], array_values($missing), 'The facade does not mention: '.implode(', ', $missing));
    }

    public function test_the_facade_documents_nothing_the_client_lacks(): void
    {
        $ghosts = array_diff($this->documentedMethods(), $this->clientMethods());

        self::assertSame([], array_values($ghosts), 'The facade advertises what the client does not have: '.implode(', ', $ghosts));
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

    /** @return array<int, string> */
    private function clientMethods(): array
    {
        $client = new \ReflectionClass(PuntjesClient::class);

        return array_values(array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $client->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $client->getName()
                    && ! $method->isConstructor(),
            ),
        ));
    }

    public function test_a_refused_token_is_replaced_in_the_laravel_cache(): void
    {
        $http = $this->fakeApi(static fn (RequestInterface $request): array => $request->getHeaderLine('Authorization') === 'Bearer token-2'
            ? [200, ['data' => []]]
            : [401, self::error('UNAUTHENTICATED', 401)]);

        PuntjesFacade::rewards()->get()->wait();

        self::assertSame(2, $http->grants);
        self::assertSame(2, $http->apiRequests);

        $this->app->forgetInstance(PuntjesClient::class);
        PuntjesFacade::clearResolvedInstances();
        PuntjesFacade::rewards()->get()->wait();

        self::assertSame(2, $http->grants);
        self::assertSame(3, $http->apiRequests);
    }

    public function test_an_api_error_reaches_the_app_as_the_core_exception_and_is_sent_once(): void
    {
        $http = $this->fakeApi(static fn (): array => [400, self::error('INVALID_JSON', 400)]);
        $body = new UpsertProductData;
        $body->setName('Brood');

        try {
            PuntjesFacade::products()->byExternalId('SKU-1')->put($body)->wait();
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame('INVALID_JSON', $e->code());
            self::assertSame(400, $e->status());
        }

        self::assertSame(1, $http->apiRequests);
    }

    public function test_the_config_file_can_be_published(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'puntjes-config'])->assertSuccessful();

        self::assertFileExists(config_path('puntjes.php'));

        @unlink(config_path('puntjes.php'));
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

            /** @var array<int, string> */
            public array $urls = [];

            public ?RequestInterface $lastApiRequest = null;

            /** @var callable(RequestInterface): array{0: int, 1: array<string, mixed>} */
            private $answer;

            /** @param  callable(RequestInterface): array{0: int, 1: array<string, mixed>}  $answer */
            public function __construct(callable $answer)
            {
                $this->answer = $answer;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->urls[] = (string) $request->getUri();

                if (str_ends_with($request->getUri()->getPath(), '/oauth/token')) {
                    $this->grants++;
                    [$status, $body] = [200, ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'token-'.$this->grants]];
                } else {
                    $this->apiRequests++;
                    $this->lastApiRequest = $request;
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
}
