<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\LibraryCacheRepository;
use SimpleCMP\T3SimpleCmp\Service\BundledLibraryInfo;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamClient;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamHealth;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamStats;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Locks the LibraryUpstreamClient behavior:
 *
 *   - null upstream URL → returns null (tier-skip)
 *   - cache hit → returns cached value, no HTTP, no stats recorded
 *   - cache miss → HTTP call, persisted to cache, returned, stats recorded
 *   - network error → returns [] (negative cache), no exception, failure stat
 *   - non-2xx → returns [], failure stat
 *   - malformed JSON → returns [], success stat (server responded)
 *   - upstream returns no match → returns [] (cached as negative)
 *   - daily budget reached → returns null (tier-skip), no HTTP, no cache write
 *   - bundle in sync with upstream → returns null (tier-skip), no HTTP, no
 *     cache write, no stats — the optimization that skips the call when
 *     bundle/upstream dataHash matches (provably wasted work)
 *   - sync gate disabled via ext-config → upstream call proceeds even when
 *     in-sync (debug knob)
 *   - cold health cache → sync gate degrades to today's behavior
 *
 * Caching identity is tested by exercising hit + miss paths and
 * verifying HTTP call counts.
 */
final class LibraryUpstreamClientTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;
    private LibraryCacheRepository&MockObject $cache;
    private LibraryUpstreamStats&MockObject $stats;
    private BundledLibraryInfo&MockObject $bundle;
    private LibraryUpstreamHealth&MockObject $health;
    private ExtensionConfiguration&MockObject $extConfig;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->cache = $this->createMock(LibraryCacheRepository::class);
        $this->stats = $this->createMock(LibraryUpstreamStats::class);
        $this->bundle = $this->createMock(BundledLibraryInfo::class);
        $this->health = $this->createMock(LibraryUpstreamHealth::class);
        $this->extConfig = $this->createMock(ExtensionConfiguration::class);

        // Defaults: bundle reports a fixed hash; health says not-in-sync;
        // ext-config has the skip-when-in-sync flag ON. Existing tests
        // exercise the upstream call path so cachedInSync MUST return
        // false by default — otherwise the gate would short-circuit every
        // test before it reaches the network mock.
        $this->bundle->method('dataHash')->willReturn(str_repeat('a', 64));
        $this->health->method('cachedInSync')->willReturn(false);
        $this->extConfig->method('get')->willReturn(['libraryUpstreamSkipWhenInSync' => true]);
    }

    private function buildClient(): LibraryUpstreamClient
    {
        return new LibraryUpstreamClient(
            $this->requestFactory,
            $this->cache,
            $this->stats,
            $this->bundle,
            $this->health,
            $this->extConfig,
        );
    }

    #[Test]
    public function returnsNullWhenUpstreamUrlIsEmpty(): void
    {
        $this->requestFactory->expects(self::never())->method('request');
        $this->cache->expects(self::never())->method('get');
        $this->stats->expects(self::never())->method('recordCall');

        $client = $this->buildClient();

        self::assertNull($client->lookup(null, '_ga', null));
        self::assertNull($client->lookup('', '_ga', null));
    }

    #[Test]
    public function returnsCachedValueWithoutHttpCallOnCacheHit(): void
    {
        $cached = [['id' => 'google-analytics', 'name' => 'Google Analytics']];
        $this->cache->expects(self::once())
            ->method('get')
            ->with('cookie', '_ga', self::isType('int'))
            ->willReturn($cached);
        $this->cache->expects(self::never())->method('put');
        $this->requestFactory->expects(self::never())->method('request');
        $this->stats->expects(self::never())->method('recordCall');
        $this->stats->expects(self::never())->method('getTodayCalls');

        $client = $this->buildClient();

        $result = $client->lookup('https://lib.example/v1', '_ga', null);
        self::assertSame($cached, $result);
    }

    #[Test]
    public function fetchesFromUpstreamAndCachesPositiveResult(): void
    {
        $match = ['id' => 'google-analytics', 'name' => 'Google Analytics', 'purposes' => ['analytics']];
        $body = json_encode(['items' => [['query' => ['cookie' => '_ga'], 'matches' => [$match]]]]);
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())
            ->method('put')
            ->with('cookie', '_ga', [$match], self::isType('int'), self::isType('int'));
        $this->stats->expects(self::once())->method('recordCall')->with(true, self::isType('int'));
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with('https://lib.example/v1/lookup', 'POST', self::isType('array'))
            ->willReturn($this->httpResponse(200, (string) $body));

        $client = $this->buildClient();

        $result = $client->lookup('https://lib.example/v1', '_ga', null);
        self::assertSame([$match], $result);
    }

    #[Test]
    public function fetchesFromUpstreamAndCachesNegativeResult(): void
    {
        $body = json_encode(['items' => [['query' => ['cookie' => '_unknown'], 'matches' => []]]]);
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())
            ->method('put')
            ->with('cookie', '_unknown', [], self::isType('int'), self::isType('int'));
        $this->stats->expects(self::once())->method('recordCall')->with(true, self::isType('int'));
        $this->requestFactory->method('request')->willReturn($this->httpResponse(200, (string) $body));

        $client = $this->buildClient();

        $result = $client->lookup('https://lib.example/v1', '_unknown', null);
        self::assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyOnNetworkFailure(): void
    {
        $this->cache->method('get')->willReturn(null);
        // A network failure must NOT cache a 24h negative row for the query
        // (that would poison a real cookie's answer for a day). Instead it
        // opens the short circuit breaker.
        $this->cache->expects(self::once())
            ->method('put')
            ->with('__health__', 'lookup-backoff', [], self::isType('int'), self::isType('int'));
        $this->stats->expects(self::once())->method('recordCall')->with(false, self::isType('int'));
        $this->requestFactory->method('request')
            ->willThrowException(new \RuntimeException('boom'));

        $client = $this->buildClient();

        $result = $client->lookup('https://lib.example/v1', '_ga', null);
        self::assertSame([], $result);
    }

    #[Test]
    public function openCircuitBreakerSkipsUpstream(): void
    {
        // Breaker row present (a recent failure) → lookup must skip the
        // network entirely and return [] fast, so a down upstream can't
        // hang each visitor's distinct unknown cookie for the full timeout.
        $this->cache->method('get')->willReturnCallback(
            static fn (string $type, string $value, int $now): ?array =>
                $type === '__health__' ? [] : null,
        );
        $this->cache->expects(self::never())->method('put');
        $this->stats->expects(self::never())->method('recordCall');
        $this->requestFactory->expects(self::never())->method('request');

        $client = $this->buildClient();

        self::assertSame([], $client->lookup('https://lib.example/v1', '_ga', null));
    }

    #[Test]
    public function returnsEmptyOnNon2xxResponse(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())->method('put');
        $this->stats->expects(self::once())->method('recordCall')->with(false, self::isType('int'));
        $this->requestFactory->method('request')->willReturn($this->httpResponse(503, ''));

        $client = $this->buildClient();

        self::assertSame([], $client->lookup('https://lib.example/v1', '_ga', null));
    }

    #[Test]
    public function returnsEmptyOnMalformedJson(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())->method('put');
        // Server responded fine (200), the malformed body is the server's
        // problem, not the network's — count as a success.
        $this->stats->expects(self::once())->method('recordCall')->with(true, self::isType('int'));
        $this->requestFactory->method('request')->willReturn($this->httpResponse(200, 'not json'));

        $client = $this->buildClient();

        self::assertSame([], $client->lookup('https://lib.example/v1', '_ga', null));
    }

    #[Test]
    public function skipsUpstreamWhenDailyBudgetExhausted(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::never())->method('put');
        $this->stats->method('getTodayCalls')->willReturn(50);
        $this->stats->expects(self::never())->method('recordCall');
        $this->requestFactory->expects(self::never())->method('request');

        $client = $this->buildClient();

        // Budget = 50, today's count = 50 → skip (returns null).
        self::assertNull($client->lookup('https://lib.example/v1', '_ga', null, 50));
    }

    #[Test]
    public function budgetZeroMeansUnlimited(): void
    {
        $match = ['id' => 'x'];
        $body = json_encode(['items' => [['matches' => [$match]]]]);
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())->method('put');
        $this->stats->method('getTodayCalls')->willReturn(999999);
        $this->stats->expects(self::once())->method('recordCall')->with(true, self::isType('int'));
        $this->requestFactory->expects(self::once())->method('request')
            ->willReturn($this->httpResponse(200, (string) $body));

        $client = $this->buildClient();

        self::assertSame([$match], $client->lookup('https://lib.example/v1', '_ga', null, 0));
    }

    #[Test]
    public function budgetCheckSkippedWhenCacheHits(): void
    {
        // A budget-exhausted client should still serve cache hits — the
        // whole point of caching is to avoid upstream traffic. Budget
        // gating only kicks in on miss.
        $cached = [['id' => 'google-analytics']];
        $this->cache->expects(self::once())
            ->method('get')
            ->with('cookie', '_ga', self::isType('int'))
            ->willReturn($cached);
        $this->cache->expects(self::never())->method('put');
        // Should never even ask the stats whether the budget is reached.
        $this->stats->expects(self::never())->method('getTodayCalls');
        $this->stats->expects(self::never())->method('recordCall');
        $this->requestFactory->expects(self::never())->method('request');

        $client = $this->buildClient();

        self::assertSame($cached, $client->lookup('https://lib.example/v1', '_ga', null, 1));
    }

    #[Test]
    public function postsSingleItemArrayToUpstream(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->method('put');
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->willReturnCallback(function (string $url, string $method, array $opts): ResponseInterface {
                self::assertSame('https://lib.example/v1/lookup', $url);
                self::assertSame('POST', $method);
                self::assertSame('application/json', $opts['headers']['Content-Type'] ?? null);
                $body = json_decode((string) ($opts['body'] ?? ''), true);
                self::assertIsArray($body);
                self::assertArrayHasKey('items', $body);
                self::assertCount(1, $body['items']);
                self::assertSame(['origin' => 'doubleclick.net'], $body['items'][0]);
                return $this->httpResponse(200, '{"items":[{"query":{"origin":"doubleclick.net"},"matches":[]}]}');
            });

        $client = $this->buildClient();
        $client->lookup('https://lib.example/v1', null, 'doubleclick.net');
    }

    // -- new tests for the in-sync skip-gate -------------------------------

    #[Test]
    public function syncGateSkipsUpstreamWhenBundleInSync(): void
    {
        // Override the default "not in sync" return so the gate fires.
        $this->health = $this->createMock(LibraryUpstreamHealth::class);
        $this->health->expects(self::once())
            ->method('cachedInSync')
            ->with('https://lib.example/v1', str_repeat('a', 64))
            ->willReturn(true);

        // Cache miss to bypass the cache short-circuit and reach the gate.
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::never())->method('put');

        // The gate fires BEFORE the budget check, so stats must not be touched.
        $this->stats->expects(self::never())->method('getTodayCalls');
        $this->stats->expects(self::never())->method('recordCall');

        // No network call.
        $this->requestFactory->expects(self::never())->method('request');

        $client = $this->buildClient();
        self::assertNull($client->lookup('https://lib.example/v1', '_ga', null));
    }

    #[Test]
    public function syncGateIsSkippedWhenExtConfigDisablesIt(): void
    {
        // Even if bundle IS in sync, the admin's ext-config OFF must force
        // upstream calls (debug path).
        $this->health = $this->createMock(LibraryUpstreamHealth::class);
        $this->health->expects(self::never())->method('cachedInSync');

        $this->extConfig = $this->createMock(ExtensionConfiguration::class);
        $this->extConfig->method('get')->willReturn(['libraryUpstreamSkipWhenInSync' => false]);

        $body = json_encode(['items' => [['matches' => []]]]);
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())->method('put');
        $this->stats->expects(self::once())->method('recordCall')->with(true, self::isType('int'));
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->willReturn($this->httpResponse(200, (string) $body));

        $client = $this->buildClient();
        self::assertSame([], $client->lookup('https://lib.example/v1', '_ga', null));
    }

    #[Test]
    public function syncGateFallsThroughOnColdHealthCache(): void
    {
        // cachedInSync returns false when the health cache is cold (fresh
        // deploy, BE Bibliothek tab never opened). The upstream call must
        // proceed as today — no functional regression for the cold case.
        $this->health = $this->createMock(LibraryUpstreamHealth::class);
        $this->health->expects(self::once())->method('cachedInSync')->willReturn(false);

        $body = json_encode(['items' => [['matches' => []]]]);
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())->method('put');
        $this->stats->expects(self::once())->method('recordCall')->with(true, self::isType('int'));
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->willReturn($this->httpResponse(200, (string) $body));

        $client = $this->buildClient();
        self::assertSame([], $client->lookup('https://lib.example/v1', '_ga', null));
    }

    private function httpResponse(int $status, string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        return $response;
    }
}
