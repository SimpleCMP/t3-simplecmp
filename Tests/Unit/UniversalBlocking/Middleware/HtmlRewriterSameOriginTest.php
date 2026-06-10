<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\UniversalBlocking\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\AllowedStylesheetHostRepository;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceService;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Middleware\HtmlRewriter;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Service\HostMatcher;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Same-origin exemption for HtmlRewriter.
 *
 * Regression guard: sameOriginHosts used to be only the request host, so a
 * multi-domain / multi-language site's absolute first-party asset URLs
 * (served from a configured base / language host) were treated as
 * third-party and neutralised to about:blank. The exemption now covers the
 * site's configured base + per-language hosts, and matches case-insensitively
 * (DNS hosts are case-insensitive; parse_url leaves the case as-written).
 */
final class HtmlRewriterSameOriginTest extends TestCase
{
    private function rewriter(): HtmlRewriter
    {
        return new HtmlRewriter(
            $this->createMock(DetectionRepository::class),
            $this->createMock(StoragePidResolver::class),
            $this->createMock(BridgeNonceService::class),
            $this->createMock(AllowedStylesheetHostRepository::class),
        );
    }

    /** @param list<string> $sameOriginHosts */
    private function rewrite(string $html, array $sameOriginHosts): string
    {
        $rewriter = $this->rewriter();
        $ref = new \ReflectionClass($rewriter);
        $ref->getProperty('sameOriginHosts')->setValue($rewriter, $sameOriginHosts);
        // blockAllThirdParty=true → any non-allowlisted host resolves, so a
        // "not rewritten" result is attributable to the same-origin exemption.
        $matcher = new HostMatcher([], true);
        $stats = ['scanned' => 0, 'rewritten' => 0];
        $method = $ref->getMethod('rewriteHtml');

        return (string) $method->invokeArgs($rewriter, [$html, $matcher, &$stats]);
    }

    private function scriptPage(string ...$srcs): string
    {
        $tags = '';
        foreach ($srcs as $src) {
            $tags .= "<script src=\"{$src}\"></script>";
        }
        return "<!DOCTYPE html><html><head></head><body>{$tags}</body></html>";
    }

    #[Test]
    public function sameOriginAbsoluteUrlsAreNotRewritten(): void
    {
        $result = $this->rewrite(
            $this->scriptPage('https://assets.example.com/app.js'),
            ['assets.example.com'],
        );

        self::assertStringNotContainsString('data-name', $result);
        self::assertStringNotContainsString('about:blank', $result);
        self::assertStringContainsString('https://assets.example.com/app.js', $result);
    }

    #[Test]
    public function sameOriginMatchIsCaseInsensitive(): void
    {
        // sameOriginHosts is lowercased; the URL host is upper/mixed case.
        $result = $this->rewrite(
            $this->scriptPage('https://ASSETS.Example.com/x.js'),
            ['assets.example.com'],
        );

        // data-name is the neutralisation marker; its absence proves the
        // upper/mixed-case first-party URL was exempted, not rewritten.
        self::assertStringNotContainsString('data-name', $result);
        self::assertStringContainsString('https://ASSETS.Example.com/x.js', $result);
    }

    #[Test]
    public function thirdPartyIsStillRewrittenAlongsideSameOrigin(): void
    {
        $result = $this->rewrite(
            $this->scriptPage(
                'https://assets.example.com/app.js',
                'https://third-party-tracker.test/t.js',
            ),
            ['assets.example.com'],
        );

        // First-party preserved, third-party neutralised — exactly one rewrite
        // (scripts are marked via data-name/data-src + type=text/plain, not
        // about:blank — that's iframe/img/link only).
        self::assertStringContainsString('https://assets.example.com/app.js', $result);
        self::assertStringNotContainsString('data-src="https://assets.example.com/app.js"', $result);
        self::assertSame(1, substr_count($result, 'data-name'));
        self::assertStringContainsString('data-src="https://third-party-tracker.test/t.js"', $result);
    }

    #[Test]
    public function siteHostsCollectsRequestBaseAndLanguageHostsLowercasedAndDeduped(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn(new Uri('https://www.example.com/'));

        $site = $this->createMock(Site::class);
        $site->method('getBase')->willReturn(new Uri('https://www.example.com/')); // dup of request
        $de = $this->createMock(SiteLanguage::class);
        $de->method('getBase')->willReturn(new Uri('https://de.example.com/'));
        $upper = $this->createMock(SiteLanguage::class);
        $upper->method('getBase')->willReturn(new Uri('https://WWW.Example.com/')); // case-dup
        $relative = $this->createMock(SiteLanguage::class);
        $relative->method('getBase')->willReturn(new Uri('/fr/')); // hostless → dropped
        $site->method('getLanguages')->willReturn([$de, $upper, $relative]);

        $rewriter = $this->rewriter();
        $ref = new \ReflectionClass($rewriter);
        $method = $ref->getMethod('siteHosts');
        $hosts = $method->invoke($rewriter, $site, $request);

        self::assertSame(['www.example.com', 'de.example.com'], $hosts);
    }
}
