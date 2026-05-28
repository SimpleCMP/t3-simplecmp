<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamHealth;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Locks `LibraryUpstreamHealth`:
 *
 *   - null / empty upstream URL → returns null (no HTTP, no cache touch)
 *   - cache hit with matching bundle data hash → returns cached, no HTTP
 *   - cache hit with stale bundle data hash → re-probes (cache
 *     invalidates on composer upgrade)
 *   - cache miss → HTTP, persisted, returned
 *   - network error → returns null, no cache write
 *   - non-2xx → returns null, no cache write
 *   - malformed JSON → returns null
 *   - flush() drops the cache so the next snapshot() re-probes
 */
final class LibraryUpstreamHealthTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;
    private FrontendInterface $fakeCache;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->fakeCache = $this->fakeCacheFrontend();
    }

    #[Test]
    public function returnsNullWhenUpstreamUrlIsEmpty(): void
    {
        $this->requestFactory->expects(self::never())->method('request');
        $health = new LibraryUpstreamHealth($this->requestFactory, $this->cacheManager());
        self::assertNull($health->snapshot(null, 'a'));
        self::assertNull($health->snapshot('', 'a'));
    }

    #[Test]
    public function returnsCachedSnapshotWhenBundleDataHashMatches(): void
    {
        $bundleHash = str_repeat('a', 64);
        $this->fakeCache->set('h_' . sha1('https://lib.example/v1'), [
            'bundleDataHash' => $bundleHash,
            'serviceCount' => 368,
            'sourceSha' => str_repeat('b', 40),
            'dataHash' => str_repeat('a', 64),
            'lastSyncAt' => 1717920000,
            'fetchedAt' => 1717920000,
        ]);
        $this->requestFactory->expects(self::never())->method('request');

        $health = new LibraryUpstreamHealth($this->requestFactory, $this->cacheManager());
        $snap = $health->snapshot('https://lib.example/v1', $bundleHash);

        self::assertNotNull($snap);
        self::assertSame(368, $snap['serviceCount']);
        self::assertArrayNotHasKey('bundleDataHash', $snap, 'bundleDataHash must not leak to the caller');
    }

    #[Test]
    public function reProbesWhenBundleDataHashShifted(): void
    {
        $this->fakeCache->set('h_' . sha1('https://lib.example/v1'), [
            'bundleDataHash' => str_repeat('a', 64),
            'serviceCount' => 100,
            'sourceSha' => str_repeat('c', 40),
            'dataHash' => str_repeat('e', 64),
            'lastSyncAt' => 1,
            'fetchedAt' => 1,
        ]);
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->willReturn($this->httpResponse(200, json_encode([
                'serviceCount' => 999,
                'sourceSha' => str_repeat('d', 40),
                'dataHash' => str_repeat('f', 64),
                'lastSyncAt' => '2026-05-28T08:17:03Z',
            ]) ?: ''));

        $health = new LibraryUpstreamHealth($this->requestFactory, $this->cacheManager());
        $snap = $health->snapshot('https://lib.example/v1', str_repeat('b', 64));

        self::assertNotNull($snap);
        self::assertSame(999, $snap['serviceCount']);
    }

    #[Test]
    public function fetchesFromUpstreamAndCachesOnMiss(): void
    {
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with('https://lib.example/v1/health', 'GET', self::isType('array'))
            ->willReturn($this->httpResponse(200, json_encode([
                'serviceCount' => 368,
                'sourceSha' => str_repeat('a', 40),
                'dataHash' => str_repeat('e', 64),
                'lastSyncAt' => '2026-05-28T08:17:03Z',
            ]) ?: ''));

        $health = new LibraryUpstreamHealth($this->requestFactory, $this->cacheManager());
        $snap = $health->snapshot('https://lib.example/v1', str_repeat('b', 64));

        self::assertNotNull($snap);
        self::assertSame(368, $snap['serviceCount']);
        self::assertSame(str_repeat('a', 40), $snap['sourceSha']);
        self::assertSame(str_repeat('e', 64), $snap['dataHash']);
        self::assertSame(strtotime('2026-05-28T08:17:03Z'), $snap['lastSyncAt']);
        self::assertNotNull($this->fakeCache->get('h_' . sha1('https://lib.example/v1')));
    }

    #[Test]
    public function dataHashIsNullWhenUpstreamOmitsTheField(): void
    {
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->willReturn($this->httpResponse(200, json_encode([
                'serviceCount' => 368,
                'sourceSha' => str_repeat('a', 40),
                'lastSyncAt' => '2026-05-28T08:17:03Z',
            ]) ?: ''));

        $health = new LibraryUpstreamHealth($this->requestFactory, $this->cacheManager());
        $snap = $health->snapshot('https://lib.example/v1', str_repeat('b', 64));

        self::assertNotNull($snap);
        self::assertNull($snap['dataHash'], 'pre-dataHash upstreams must return null for the field');
    }

    #[Test]
    public function returnsNullOnNetworkErrorAndDoesNotCache(): void
    {
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->willThrowException(new \RuntimeException('connection refused'));

        $health = new LibraryUpstreamHealth($this->requestFactory, $this->cacheManager());
        self::assertNull($health->snapshot('https://lib.example/v1', 'a'));
        self::assertFalse($this->fakeCache->get('h_' . sha1('https://lib.example/v1')));
    }

    #[Test]
    public function returnsNullOnNon2xx(): void
    {
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->willReturn($this->httpResponse(503, 'Service Unavailable'));

        $health = new LibraryUpstreamHealth($this->requestFactory, $this->cacheManager());
        self::assertNull($health->snapshot('https://lib.example/v1', 'a'));
        self::assertFalse($this->fakeCache->get('h_' . sha1('https://lib.example/v1')));
    }

    #[Test]
    public function returnsNullOnMalformedJson(): void
    {
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->willReturn($this->httpResponse(200, '<html>not json</html>'));

        $health = new LibraryUpstreamHealth($this->requestFactory, $this->cacheManager());
        self::assertNull($health->snapshot('https://lib.example/v1', 'a'));
    }

    #[Test]
    public function flushDropsCachedSnapshot(): void
    {
        $bundleHash = str_repeat('a', 64);
        $this->fakeCache->set('h_' . sha1('https://lib.example/v1'), [
            'bundleDataHash' => $bundleHash,
            'serviceCount' => 1,
            'sourceSha' => null,
            'dataHash' => null,
            'lastSyncAt' => null,
            'fetchedAt' => 1,
        ]);

        $health = new LibraryUpstreamHealth($this->requestFactory, $this->cacheManager());
        $health->flush();

        self::assertFalse($this->fakeCache->get('h_' . sha1('https://lib.example/v1')));
    }

    // --- helpers ---------------------------------------------------------

    private function cacheManager(): CacheManager
    {
        $cm = $this->createMock(CacheManager::class);
        $cm->method('getCache')->willReturn($this->fakeCache);
        return $cm;
    }

    private function fakeCacheFrontend(): FrontendInterface
    {
        $store = [];
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(
            static function (string $key) use (&$store) {
                return $store[$key] ?? false;
            }
        );
        $cache->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$store): void {
                $store[$key] = $value;
            }
        );
        $cache->method('flush')->willReturnCallback(
            static function () use (&$store): void {
                $store = [];
            }
        );
        return $cache;
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
