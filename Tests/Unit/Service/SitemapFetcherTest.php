<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\RequestFactory;
use SimpleCMP\T3SimpleCmp\Service\SitemapFetcher;

/**
 * Parser-level coverage for SitemapFetcher. The network path is
 * exercised by the Playwright BE spec, which hits a real ddev FE.
 *
 * `parseXml()` is the unit-testable surface; tests build SimpleXML
 * trees directly without going through the RequestFactory.
 */
final class SitemapFetcherTest extends TestCase
{
    private SitemapFetcher $fetcher;

    protected function setUp(): void
    {
        $this->fetcher = new SitemapFetcher(
            $this->createStub(RequestFactory::class),
        );
    }

    #[Test]
    public function parsesPlainUrlset(): void
    {
        $xml = $this->fetcher->parseXml(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.test/about</loc></url>
                <url><loc>https://example.test/contact</loc></url>
            </urlset>
            XML);
        self::assertNotNull($xml);
        self::assertSame('urlset', $xml->getName());
    }

    #[Test]
    public function recognisesSitemapIndex(): void
    {
        $xml = $this->fetcher->parseXml(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <sitemap><loc>https://example.test/sitemap-pages.xml</loc></sitemap>
            </sitemapindex>
            XML);
        self::assertNotNull($xml);
        self::assertSame('sitemapindex', $xml->getName());
    }

    #[Test]
    public function returnsNullForEmptyBody(): void
    {
        self::assertNull($this->fetcher->parseXml(''));
    }

    #[Test]
    public function returnsNullForMalformedXml(): void
    {
        $xml = $this->fetcher->parseXml('<urlset><url><loc>oops');
        self::assertNull($xml);
    }

    #[Test]
    public function defaultSitemapUrlAppendsSitemapXml(): void
    {
        self::assertSame(
            'https://example.test/sitemap.xml',
            $this->fetcher->defaultSitemapUrl('https://example.test/'),
        );
        self::assertSame(
            'https://example.test/sitemap.xml',
            $this->fetcher->defaultSitemapUrl('https://example.test'),
        );
    }

    #[Test]
    public function fetchReturnsEmptyListForUnreachableUrl(): void
    {
        // No mocked HTTP — RequestFactory stub throws on request().
        // The internal handler catches and returns an empty list.
        self::assertSame([], $this->fetcher->fetch('https://invalid.invalid/sitemap.xml'));
    }
}
