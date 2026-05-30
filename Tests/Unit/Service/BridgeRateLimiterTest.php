<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use SimpleCMP\T3SimpleCmp\Service\BridgeRateLimiter;

final class BridgeRateLimiterTest extends TestCase
{
    #[Test]
    public function allowsWhenLimitIsZero(): void
    {
        $cache = $this->fakeCache();
        $limiter = new BridgeRateLimiter(
            $this->cacheManagerReturning($cache),
            $this->siteFinder('dev14.ddev.site', 0),
        );
        $result = $limiter->check($this->request('dev14.ddev.site', '127.0.0.1'));
        self::assertTrue($result['allowed']);
        self::assertSame(0, $result['limit']);
    }

    #[Test]
    public function allowsWhenUnderLimitAndIncrements(): void
    {
        $cache = $this->fakeCache();
        $limiter = new BridgeRateLimiter(
            $this->cacheManagerReturning($cache),
            $this->siteFinder('dev14.ddev.site', 3),
        );
        $req = $this->request('dev14.ddev.site', '203.0.113.1');

        $r1 = $limiter->check($req);
        $r2 = $limiter->check($req);
        $r3 = $limiter->check($req);
        self::assertTrue($r1['allowed']);
        self::assertTrue($r2['allowed']);
        self::assertTrue($r3['allowed']);
        self::assertSame(1, $r1['count']);
        self::assertSame(2, $r2['count']);
        self::assertSame(3, $r3['count']);
    }

    #[Test]
    public function rejectsAtLimit(): void
    {
        $cache = $this->fakeCache();
        $limiter = new BridgeRateLimiter(
            $this->cacheManagerReturning($cache),
            $this->siteFinder('dev14.ddev.site', 2),
        );
        $req = $this->request('dev14.ddev.site', '203.0.113.5');

        $limiter->check($req);
        $limiter->check($req);
        $third = $limiter->check($req);
        self::assertFalse($third['allowed']);
        self::assertSame(2, $third['count']);
        self::assertGreaterThan(0, $third['retryAfter']);
    }

    #[Test]
    public function separatesByClientIp(): void
    {
        $cache = $this->fakeCache();
        $limiter = new BridgeRateLimiter(
            $this->cacheManagerReturning($cache),
            $this->siteFinder('dev14.ddev.site', 1),
        );

        $a = $limiter->check($this->request('dev14.ddev.site', '10.0.0.1'));
        $b = $limiter->check($this->request('dev14.ddev.site', '10.0.0.2'));
        self::assertTrue($a['allowed']);
        self::assertTrue($b['allowed']);
    }

    #[Test]
    public function allowsWhenIpIsMissing(): void
    {
        $cache = $this->fakeCache();
        $limiter = new BridgeRateLimiter(
            $this->cacheManagerReturning($cache),
            $this->siteFinder('dev14.ddev.site', 1),
        );
        $result = $limiter->check($this->request('dev14.ddev.site', ''));
        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function checkLookupHasSeparateBudgetFromWebhook(): void
    {
        $cache = $this->fakeCache();
        $limiter = new BridgeRateLimiter(
            $this->cacheManagerReturning($cache),
            $this->siteFinderBoth('dev14.ddev.site', 1, 1),
        );
        $req = $this->request('dev14.ddev.site', '203.0.113.9');

        // Exhaust the webhook counter.
        self::assertTrue($limiter->check($req)['allowed']);
        self::assertFalse($limiter->check($req)['allowed']);
        // The lookup counter is untouched — different cache-key prefix.
        self::assertTrue($limiter->checkLookup($req)['allowed']);
        self::assertFalse($limiter->checkLookup($req)['allowed']);
    }

    #[Test]
    public function checkLookupDefaultsToLooseLimitWhenUnset(): void
    {
        $cache = $this->fakeCache();
        // Site sets only the webhook limit; the lookup limit falls back to
        // the loose default (5000).
        $limiter = new BridgeRateLimiter(
            $this->cacheManagerReturning($cache),
            $this->siteFinder('dev14.ddev.site', 7),
        );
        $result = $limiter->checkLookup($this->request('dev14.ddev.site', '203.0.113.10'));
        self::assertTrue($result['allowed']);
        self::assertSame(5000, $result['limit']);
    }

    #[Test]
    public function checkLookupRejectsAtConfiguredLimit(): void
    {
        $cache = $this->fakeCache();
        $limiter = new BridgeRateLimiter(
            $this->cacheManagerReturning($cache),
            $this->siteFinderBoth('dev14.ddev.site', 500, 2),
        );
        $req = $this->request('dev14.ddev.site', '203.0.113.11');
        $limiter->checkLookup($req);
        $limiter->checkLookup($req);
        $third = $limiter->checkLookup($req);
        self::assertFalse($third['allowed']);
        self::assertSame(2, $third['limit']);
    }

    private function cacheManagerReturning(FrontendInterface $cache): CacheManager
    {
        $cm = $this->createMock(CacheManager::class);
        $cm->method('getCache')->willReturn($cache);
        return $cm;
    }

    private function fakeCache(): FrontendInterface
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
        return $cache;
    }

    private function siteFinder(string $host, int $limit): SiteFinder
    {
        $site = new Site($host, 1, [
            'base' => 'https://' . $host . '/',
            'settings' => ['simplecmp' => ['bridgeRateLimit' => $limit]],
        ]);
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getAllSites')->willReturn([$site]);
        return $finder;
    }

    private function siteFinderBoth(string $host, int $webhookLimit, int $lookupLimit): SiteFinder
    {
        $site = new Site($host, 1, [
            'base' => 'https://' . $host . '/',
            'settings' => ['simplecmp' => [
                'bridgeRateLimit' => $webhookLimit,
                'serviceDbRateLimit' => $lookupLimit,
            ]],
        ]);
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getAllSites')->willReturn([$site]);
        return $finder;
    }

    private function request(string $host, string $ip): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn($host);
        $req = $this->createMock(ServerRequestInterface::class);
        $req->method('getUri')->willReturn($uri);
        $req->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);
        return $req;
    }
}
