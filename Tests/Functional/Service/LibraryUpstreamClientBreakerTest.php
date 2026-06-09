<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\LibraryCacheRepository;
use SimpleCMP\T3SimpleCmp\Service\BundledLibraryInfo;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamClient;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamHealth;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamStats;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Exercises the LibraryUpstreamClient circuit breaker against the REAL
 * LibraryCacheRepository (DB-backed) — the TTL / self-heal / no-poison
 * behaviour the mocked unit tests can't reach. Only the network
 * (RequestFactory) is stubbed; the cache rows are genuinely written and
 * read back through the repository.
 *
 * Guards the hardening: a failed upstream call must open a short circuit
 * breaker (not a 24h negative row), an open breaker must skip the network,
 * and an expired breaker must let lookups retry.
 */
final class LibraryUpstreamClientBreakerTest extends FunctionalTestCase
{
    private const string UPSTREAM = 'https://lib.example/v1';

    // Mirrors LibraryUpstreamClient::BREAKER_TYPE / ::BREAKER_KEY (private).
    private const string BREAKER_TYPE = '__health__';
    private const string BREAKER_KEY = 'lookup-backoff';

    protected array $testExtensionsToLoad = ['simplecmp/t3-simplecmp'];

    private LibraryCacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = $this->get(LibraryCacheRepository::class);
    }

    #[Test]
    public function failedCallOpensTheBreakerWithoutPoisoningTheQuery(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willThrowException(new \RuntimeException('boom'));

        $result = $this->client($requestFactory)->lookup(self::UPSTREAM, '_zz_breaker_fail', null);
        self::assertSame([], $result);

        $now = time();
        // The breaker is open ...
        self::assertNotNull(
            $this->cache->get(self::BREAKER_TYPE, self::BREAKER_KEY, $now),
            'a failed upstream call must open the circuit breaker',
        );
        // ... and short-lived — well under the 24h positive TTL, proving it
        // is a backoff window, not a poison row.
        self::assertNull(
            $this->cache->get(self::BREAKER_TYPE, self::BREAKER_KEY, $now + 120),
            'the breaker must expire within ~60s, not persist like a 24h row',
        );
        // The failed query itself must NOT be cached as a no-match.
        self::assertNull(
            $this->cache->get('cookie', '_zz_breaker_fail', $now),
            'a transient failure must not be cached as a 24h no-match',
        );
    }

    #[Test]
    public function openBreakerSkipsTheNetwork(): void
    {
        $now = time();
        // Pre-open the breaker (a recent failure).
        $this->cache->put(self::BREAKER_TYPE, self::BREAKER_KEY, [], $now, $now + 60);

        $requestFactory = $this->createMock(RequestFactory::class);
        // The whole point: no network call while the breaker is open.
        $requestFactory->expects(self::never())->method('request');

        $result = $this->client($requestFactory)->lookup(self::UPSTREAM, '_zz_breaker_skip', null);
        self::assertSame([], $result);
    }

    #[Test]
    public function expiredBreakerAllowsRetry(): void
    {
        $past = time() - 3600;
        // A stale breaker row (written an hour ago, 60s TTL → long expired).
        $this->cache->put(self::BREAKER_TYPE, self::BREAKER_KEY, [], $past, $past + 60);

        $match = ['id' => 'google-analytics', 'name' => 'Google Analytics'];
        $body = json_encode(['items' => [['query' => ['cookie' => '_ga'], 'matches' => [$match]]]]);
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())
            ->method('request')
            ->willReturn($this->jsonResponse(200, (string) $body));

        $result = $this->client($requestFactory)->lookup(self::UPSTREAM, '_ga', null);
        self::assertSame([$match], $result, 'an expired breaker must let the lookup retry upstream');
    }

    #[Test]
    public function genuineNoMatchStillCachesForTheFullTtl(): void
    {
        // A clean 200 with empty matches IS cached long (24h) — the contrast
        // case: only failures open the short breaker; a real no-match sticks.
        $body = json_encode(['items' => [['query' => ['cookie' => '_clean_unknown'], 'matches' => []]]]);
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')->willReturn($this->jsonResponse(200, (string) $body));

        $now = time();
        self::assertSame([], $this->client($requestFactory)->lookup(self::UPSTREAM, '_clean_unknown', null));

        // Still live an hour later → cached for far longer than the 60s
        // breaker window (the full 24h positive TTL).
        self::assertSame(
            [],
            $this->cache->get('cookie', '_clean_unknown', $now + 3600),
            'a genuine no-match must be cached for the full TTL, not the breaker window',
        );
    }

    // --- helpers --------------------------------------------------------

    private function client(RequestFactory $requestFactory): LibraryUpstreamClient
    {
        return new LibraryUpstreamClient(
            $requestFactory,                            // stubbed network
            $this->cache,                               // REAL DB-backed cache
            $this->get(LibraryUpstreamStats::class),
            $this->get(BundledLibraryInfo::class),
            // Real health: its CacheManager cache is cold in the test DB, so
            // cachedInSync() is false → the sync gate never short-circuits.
            $this->get(LibraryUpstreamHealth::class),
            $this->get(ExtensionConfiguration::class),
        );
    }

    private function jsonResponse(int $status, string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        return $response;
    }
}
