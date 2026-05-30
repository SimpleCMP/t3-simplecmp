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
        // Host is allowed so the fetch is attempted; the RequestFactory
        // stub yields a non-2xx (status 0) response, so the handler
        // returns an empty list.
        self::assertSame(
            [],
            $this->fetcher->fetch('https://invalid.invalid/sitemap.xml', ['invalid.invalid']),
        );
    }

    // --- SSRF host/scheme allowlist (isFetchableUrl) ----------------------

    #[Test]
    public function allowsHttpAndHttpsOnAllowedHost(): void
    {
        self::assertTrue($this->fetcher->isFetchableUrl('https://example.test/sitemap.xml', ['example.test']));
        self::assertTrue($this->fetcher->isFetchableUrl('http://example.test/sitemap.xml', ['example.test']));
    }

    #[Test]
    public function rejectsHostNotInAllowlist(): void
    {
        self::assertFalse($this->fetcher->isFetchableUrl('https://evil.test/sitemap.xml', ['example.test']));
    }

    #[Test]
    public function rejectsInternalAndCloudMetadataHosts(): void
    {
        foreach ([
            'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
            'http://127.0.0.1/sitemap.xml',
            'http://localhost/sitemap.xml',
            'http://[::1]/sitemap.xml',
            'http://10.0.0.5/sitemap.xml',
        ] as $url) {
            self::assertFalse($this->fetcher->isFetchableUrl($url, ['example.test']), $url);
        }
    }

    #[Test]
    public function rejectsNonHttpSchemes(): void
    {
        foreach ([
            'file:///etc/passwd',
            'gopher://example.test/',
            'ftp://example.test/sitemap.xml',
            '//example.test/sitemap.xml',   // protocol-relative: no scheme
            'javascript:alert(1)',
        ] as $url) {
            self::assertFalse($this->fetcher->isFetchableUrl($url, ['example.test']), $url);
        }
    }

    #[Test]
    public function rejectsEmbeddedCredentials(): void
    {
        // Host-confusion: the real host here is 169.254.169.254, with the
        // allowed host smuggled into the userinfo. parse_url resolves
        // host correctly, but we refuse any credentials outright.
        self::assertFalse($this->fetcher->isFetchableUrl('http://example.test@169.254.169.254/', ['example.test']));
        self::assertFalse($this->fetcher->isFetchableUrl('http://user:pass@example.test/', ['example.test']));
    }

    #[Test]
    public function hostMatchIsCaseInsensitive(): void
    {
        self::assertTrue($this->fetcher->isFetchableUrl('https://Example.TEST/sitemap.xml', ['example.test']));
        self::assertTrue($this->fetcher->isFetchableUrl('https://example.test/sitemap.xml', ['EXAMPLE.TEST']));
    }

    #[Test]
    public function emptyAllowlistRejectsEverything(): void
    {
        self::assertFalse($this->fetcher->isFetchableUrl('https://example.test/sitemap.xml', []));
    }

    #[Test]
    public function fetchFailsClosedWithEmptyAllowlist(): void
    {
        // Unresolved site → no host allowlist → nothing is fetched.
        self::assertSame([], $this->fetcher->fetch('https://example.test/sitemap.xml', []));
    }

    #[Test]
    public function fetchRefusesDisallowedHost(): void
    {
        self::assertSame([], $this->fetcher->fetch('http://169.254.169.254/', ['example.test']));
    }

    // --- robots.txt sitemap discovery (parseRobots) -----------------------

    #[Test]
    public function parseRobotsExtractsSitemapUrlsIncludingOffHost(): void
    {
        $urls = $this->fetcher->parseRobots(<<<'TXT'
            User-agent: *
            Disallow: /typo3/
            Sitemap: https://example.test/sitemap.xml
            Sitemap: https://cdn.example-cdn.net/maps/sitemap.xml
            TXT);
        self::assertSame(
            ['https://example.test/sitemap.xml', 'https://cdn.example-cdn.net/maps/sitemap.xml'],
            $urls,
        );
    }

    #[Test]
    public function parseRobotsIsCaseInsensitiveAndWhitespaceTolerant(): void
    {
        self::assertSame(
            ['https://example.test/s.xml'],
            $this->fetcher->parseRobots('  sitemap :   https://example.test/s.xml  '),
        );
    }

    #[Test]
    public function parseRobotsRejectsUnsafeOrIpLiteralSitemaps(): void
    {
        // Only the public-hostname https URL survives; everything that
        // could be an SSRF target via a tampered robots.txt is dropped
        // without needing DNS.
        $urls = $this->fetcher->parseRobots(<<<'TXT'
            Sitemap: file:///etc/passwd
            Sitemap: http://169.254.169.254/sitemap.xml
            Sitemap: http://127.0.0.1/sitemap.xml
            Sitemap: http://[::1]/sitemap.xml
            Sitemap: http://user:pass@example.test/sitemap.xml
            Sitemap: javascript:alert(1)
            Sitemap: https://ok.example.test/sitemap.xml
            TXT);
        self::assertSame(['https://ok.example.test/sitemap.xml'], $urls);
    }

    #[Test]
    public function parseRobotsDedupesAndIgnoresNonSitemapLines(): void
    {
        $urls = $this->fetcher->parseRobots(<<<'TXT'
            # comment
            Host: example.test
            Sitemap: https://example.test/s.xml
            Sitemap: https://example.test/s.xml
            TXT);
        self::assertSame(['https://example.test/s.xml'], $urls);
    }

    #[Test]
    public function parseRobotsReturnsEmptyForNoDirectives(): void
    {
        self::assertSame([], $this->fetcher->parseRobots("User-agent: *\nDisallow: /"));
        self::assertSame([], $this->fetcher->parseRobots(''));
    }

    #[Test]
    public function robotsSitemapUrlsReturnsEmptyForUnusableBase(): void
    {
        // No scheme/host → no robots.txt URL to build → no fetch.
        self::assertSame([], $this->fetcher->robotsSitemapUrls('', ['example.test']));
        self::assertSame([], $this->fetcher->robotsSitemapUrls('not-a-url', ['example.test']));
    }
}
