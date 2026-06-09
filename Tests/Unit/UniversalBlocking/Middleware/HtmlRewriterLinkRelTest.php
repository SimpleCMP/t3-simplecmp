<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\UniversalBlocking\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceService;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Middleware\HtmlRewriter;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Service\HostMatcher;

/**
 * `<link>` rel-policy tests for HtmlRewriter.
 *
 * Regression guard for the 2026-05-30 fix: the rewriter used to map
 * `link => href` and rewrite EVERY third-party `<link>` to
 * `about:blank` regardless of `rel`. With universal blocking on by
 * default that silently broke third-party stylesheets (CSS dropped,
 * no recovery UI) and poisoned cross-domain `rel="canonical"` (SEO).
 *
 * Policy now: only resource-hint rels (preconnect/dns-prefetch/
 * preload/prefetch/modulepreload/prerender) are rewritten —
 * neutralizing a genuine pre-consent connection is invisible. Every
 * other rel (stylesheet, canonical, alternate, icon, manifest,
 * unknown) is left untouched. See HtmlRewriter::LINK_REWRITABLE_RELS
 * and docs/decisions/2026-05-30-link-rewrite-rel-policy.md for the
 * rationale + caveats.
 */
final class HtmlRewriterLinkRelTest extends TestCase
{
    private function rewrite(string $html): string
    {
        $stats = ['scanned' => 0, 'rewritten' => 0];

        $rewriter = new HtmlRewriter(
            $this->createMock(DetectionRepository::class),
            $this->createMock(StoragePidResolver::class),
            $this->createMock(BridgeNonceService::class),
        );
        $ref = new \ReflectionClass($rewriter);
        $ref->getProperty('sameOriginHosts')->setValue($rewriter, []);

        // blockAllThirdParty=true → every non-allowlisted host resolves,
        // so a "not rewritten" result is attributable to the rel policy,
        // not to a missing library match.
        $matcher = new HostMatcher([], true);

        $method = $ref->getMethod('rewriteHtml');

        return (string) $method->invokeArgs($rewriter, [$html, $matcher, &$stats]);
    }

    #[Test]
    public function collectsRewrittenThirdPartyTagsAsDetections(): void
    {
        // The Discover sweep relies on rewriteHtml() populating the
        // by-ref $detections list — that's what surfaces declaratively-
        // blocked embeds (YouTube, …) that the runtime recorder never sees.
        $rewriter = new HtmlRewriter(
            $this->createMock(DetectionRepository::class),
            $this->createMock(StoragePidResolver::class),
            $this->createMock(BridgeNonceService::class),
        );
        $ref = new \ReflectionClass($rewriter);
        $ref->getProperty('sameOriginHosts')->setValue($rewriter, ['example.com']);
        $matcher = new HostMatcher([], true);

        $stats = ['scanned' => 0, 'rewritten' => 0];
        $detections = [];
        $html = '<!DOCTYPE html><html><head></head><body>'
            . '<iframe src="https://www.youtube.com/embed/abc"></iframe>'
            . '</body></html>';
        $ref->getMethod('rewriteHtml')->invokeArgs($rewriter, [$html, $matcher, &$stats, &$detections]);

        self::assertCount(1, $detections);
        self::assertSame('iframe', $detections[0]['kind']);
        self::assertSame('https://www.youtube.com/embed/abc', $detections[0]['identifier']);
        self::assertSame('www.youtube.com', $detections[0]['origin']);
    }

    private function linkPage(string $linkTag): string
    {
        return "<!DOCTYPE html><html><head>{$linkTag}</head><body><p>x</p></body></html>";
    }

    // --- LEFT UNTOUCHED ---------------------------------------------------

    #[Test]
    public function thirdPartyStylesheetIsNotRewritten(): void
    {
        $result = $this->rewrite(
            $this->linkPage('<link rel="stylesheet" href="https://cdn.example.com/app.css">'),
        );

        self::assertStringNotContainsString('data-name', $result);
        self::assertStringNotContainsString('about:blank', $result);
        self::assertStringContainsString('https://cdn.example.com/app.css', $result);
    }

    #[Test]
    public function crossDomainCanonicalIsNotRewritten(): void
    {
        $result = $this->rewrite(
            $this->linkPage('<link rel="canonical" href="https://www.example.com/the-page">'),
        );

        self::assertStringNotContainsString('data-name', $result);
        self::assertStringContainsString('https://www.example.com/the-page', $result);
    }

    #[Test]
    public function alternateIconAndManifestAreNotRewritten(): void
    {
        $result = $this->rewrite($this->linkPage(
            '<link rel="alternate" hreflang="de" href="https://de.example.com/">'
            . '<link rel="icon" href="https://cdn.example.com/favicon.ico">'
            . '<link rel="manifest" href="https://cdn.example.com/site.webmanifest">',
        ));

        self::assertStringNotContainsString('data-name', $result);
        self::assertStringContainsString('https://cdn.example.com/favicon.ico', $result);
        self::assertStringContainsString('https://cdn.example.com/site.webmanifest', $result);
    }

    #[Test]
    public function shortcutIconTokenListIsNotRewritten(): void
    {
        // `rel` is a token list; "shortcut icon" must not match.
        $result = $this->rewrite(
            $this->linkPage('<link rel="shortcut icon" href="https://cdn.example.com/fav.ico">'),
        );

        self::assertStringNotContainsString('data-name', $result);
        self::assertStringContainsString('https://cdn.example.com/fav.ico', $result);
    }

    #[Test]
    public function linkWithoutRelIsNotRewritten(): void
    {
        $result = $this->rewrite(
            $this->linkPage('<link href="https://cdn.example.com/thing.css">'),
        );

        self::assertStringNotContainsString('data-name', $result);
    }

    // --- NEUTRALIZED ------------------------------------------------------

    #[Test]
    public function thirdPartyPreconnectIsNeutralized(): void
    {
        $result = $this->rewrite(
            $this->linkPage('<link rel="preconnect" href="https://hints.thirdparty.example/">'),
        );

        self::assertStringContainsString('data-name', $result);
        self::assertStringContainsString('about:blank', $result);
        self::assertStringContainsString('data-src="https://hints.thirdparty.example/"', $result);
    }

    #[Test]
    public function thirdPartyPreloadIsNeutralized(): void
    {
        $result = $this->rewrite($this->linkPage(
            '<link rel="preload" as="script" href="https://cdn.thirdparty.example/x.js">',
        ));

        self::assertStringContainsString('data-name', $result);
        self::assertStringContainsString('about:blank', $result);
    }

    #[Test]
    public function relMatchingIsCaseInsensitive(): void
    {
        $result = $this->rewrite(
            $this->linkPage('<link rel="DNS-Prefetch" href="https://hints.thirdparty.example/">'),
        );

        self::assertStringContainsString('data-name', $result);
        self::assertStringContainsString('about:blank', $result);
    }

    // --- the other tags must still rewrite (no collateral regression) -----

    #[Test]
    public function thirdPartyScriptStillRewritten(): void
    {
        $result = $this->rewrite(
            '<!DOCTYPE html><html><body>'
            . '<script src="https://tracker.example.com/x.js"></script>'
            . '</body></html>',
        );

        self::assertStringContainsString('data-name', $result);
        self::assertStringContainsString('data-src="https://tracker.example.com/x.js"', $result);
    }
}
