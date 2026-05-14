<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\SiteSettingsFactory;
use WapplerSystems\SimpleCmpTypo3\Service\StoragePidResolver;

final class StoragePidResolverTest extends TestCase
{
    #[Test]
    public function resolveDefaultReturnsZeroWhenNoSitesConfigured(): void
    {
        $resolver = new StoragePidResolver($this->siteFinderWithSites([]));
        self::assertSame(0, $resolver->resolveDefault());
    }

    #[Test]
    public function resolveDefaultReturnsFirstSitesStoragePid(): void
    {
        $resolver = new StoragePidResolver($this->siteFinderWithSites([
            $this->site('default', 'https://default.example.com/', ['simplecmp.storagePid' => 42]),
            $this->site('second', 'https://second.example.com/', ['simplecmp.storagePid' => 99]),
        ]));
        self::assertSame(42, $resolver->resolveDefault());
    }

    #[Test]
    public function resolveDefaultReturnsZeroWhenSettingMissing(): void
    {
        $resolver = new StoragePidResolver($this->siteFinderWithSites([
            $this->site('default', 'https://default.example.com/', []),
        ]));
        self::assertSame(0, $resolver->resolveDefault());
    }

    #[Test]
    public function resolveForRequestMatchesByHost(): void
    {
        $resolver = new StoragePidResolver($this->siteFinderWithSites([
            $this->site('one', 'https://a.example.com/', ['simplecmp.storagePid' => 10]),
            $this->site('two', 'https://b.example.com/', ['simplecmp.storagePid' => 20]),
        ]));
        self::assertSame(20, $resolver->resolveForRequest($this->request('b.example.com')));
        self::assertSame(10, $resolver->resolveForRequest($this->request('a.example.com')));
    }

    #[Test]
    public function resolveForRequestFallsBackToDefaultWhenHostUnknown(): void
    {
        $resolver = new StoragePidResolver($this->siteFinderWithSites([
            $this->site('one', 'https://a.example.com/', ['simplecmp.storagePid' => 10]),
        ]));
        self::assertSame(10, $resolver->resolveForRequest($this->request('unknown.example.com')));
    }

    #[Test]
    public function resolveForSourceStripsSimplecmpPrefix(): void
    {
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getSiteByIdentifier')
            ->with('myproject')
            ->willReturn($this->site('myproject', 'https://x/', ['simplecmp.storagePid' => 77]));
        $finder->method('getAllSites')->willReturn([]);
        $resolver = new StoragePidResolver($finder);
        self::assertSame(77, $resolver->resolveForSource('simplecmp-myproject'));
    }

    #[Test]
    public function resolveForSourceAcceptsBareIdentifier(): void
    {
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getSiteByIdentifier')
            ->with('legacy')
            ->willReturn($this->site('legacy', 'https://x/', ['simplecmp.storagePid' => 5]));
        $finder->method('getAllSites')->willReturn([]);
        $resolver = new StoragePidResolver($finder);
        self::assertSame(5, $resolver->resolveForSource('legacy'));
    }

    #[Test]
    public function resolveForSourceFallsBackToDefaultWhenIdentifierMissing(): void
    {
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getSiteByIdentifier')
            ->willThrowException(new SiteNotFoundException('not found'));
        $finder->method('getAllSites')->willReturn([
            $this->site('fallback', 'https://x/', ['simplecmp.storagePid' => 1]),
        ]);
        $resolver = new StoragePidResolver($finder);
        self::assertSame(1, $resolver->resolveForSource('simplecmp-missing'));
    }

    /** @param iterable<Site> $sites */
    private function siteFinderWithSites(iterable $sites): SiteFinder
    {
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getAllSites')->willReturn(is_array($sites) ? $sites : iterator_to_array($sites));
        return $finder;
    }

    /** @param array<string, mixed> $settings */
    private function site(string $identifier, string $base, array $settings): Site
    {
        // Site requires a configuration array; provide the minimum it needs
        // for getBase() + getSettings()->get(...) to work.
        $config = [
            'base' => $base,
            'languages' => [],
            'settings' => $settings,
        ];
        return new Site($identifier, 0, $config);
    }

    private function request(string $host): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getHost')->willReturn($host);
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        return $request;
    }
}
