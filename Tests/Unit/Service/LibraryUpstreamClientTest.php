<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\LibraryCacheRepository;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamClient;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Locks the LibraryUpstreamClient behavior:
 *
 *   - null upstream URL → returns null (tier-skip)
 *   - cache hit → returns cached value, no HTTP
 *   - cache miss → HTTP call, persisted to cache, returned
 *   - network error → returns [] (negative cache), no exception
 *   - non-2xx → returns []
 *   - malformed JSON → returns []
 *   - upstream returns no match → returns [] (cached as negative)
 *
 * Caching identity is tested by exercising hit + miss paths and
 * verifying HTTP call counts.
 */
final class LibraryUpstreamClientTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;
    private LibraryCacheRepository&MockObject $cache;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->cache = $this->createMock(LibraryCacheRepository::class);
    }

    #[Test]
    public function returnsNullWhenUpstreamUrlIsEmpty(): void
    {
        $this->requestFactory->expects(self::never())->method('request');
        $this->cache->expects(self::never())->method('get');

        $client = new LibraryUpstreamClient($this->requestFactory, $this->cache);

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

        $client = new LibraryUpstreamClient($this->requestFactory, $this->cache);

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
        $this->requestFactory->expects(self::once())
            ->method('request')
            ->with('https://lib.example/v1/lookup', 'POST', self::isType('array'))
            ->willReturn($this->httpResponse(200, (string) $body));

        $client = new LibraryUpstreamClient($this->requestFactory, $this->cache);

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
        $this->requestFactory->method('request')->willReturn($this->httpResponse(200, (string) $body));

        $client = new LibraryUpstreamClient($this->requestFactory, $this->cache);

        $result = $client->lookup('https://lib.example/v1', '_unknown', null);
        self::assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyOnNetworkFailure(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())
            ->method('put')
            ->with('cookie', '_ga', [], self::isType('int'), self::isType('int'));
        $this->requestFactory->method('request')
            ->willThrowException(new \RuntimeException('boom'));

        $client = new LibraryUpstreamClient($this->requestFactory, $this->cache);

        $result = $client->lookup('https://lib.example/v1', '_ga', null);
        self::assertSame([], $result);
    }

    #[Test]
    public function returnsEmptyOnNon2xxResponse(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())->method('put');
        $this->requestFactory->method('request')->willReturn($this->httpResponse(503, ''));

        $client = new LibraryUpstreamClient($this->requestFactory, $this->cache);

        self::assertSame([], $client->lookup('https://lib.example/v1', '_ga', null));
    }

    #[Test]
    public function returnsEmptyOnMalformedJson(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects(self::once())->method('put');
        $this->requestFactory->method('request')->willReturn($this->httpResponse(200, 'not json'));

        $client = new LibraryUpstreamClient($this->requestFactory, $this->cache);

        self::assertSame([], $client->lookup('https://lib.example/v1', '_ga', null));
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

        $client = new LibraryUpstreamClient($this->requestFactory, $this->cache);
        $client->lookup('https://lib.example/v1', null, 'doubleclick.net');
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
