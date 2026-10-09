<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\UniversalBlocking\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Domain\Repository\AllowedStylesheetHostRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceService;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Middleware\HtmlRewriter;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Service\HostMatcher;

/**
 * Inline <script> bodies must survive the DOMDocument round-trip verbatim.
 *
 * libxml's HTML4 parser (< 2.14, e.g. Debian bookworm's 2.9.14) drops every
 * "</tag" inside <script>. A `<script type="application/json">` carrying an
 * HTML template (t3bootstrap's GLightbox config) reached the browser without
 * a single closing tag and the lightbox rendered as a broken, nested modal.
 * These tests fail on such libxml versions without the masking in
 * HtmlRewriter::maskRawTextElements().
 */
final class HtmlRewriterInlineScriptTest extends TestCase
{
    private function rewrite(string $html): string
    {
        $stats = ['scanned' => 0, 'rewritten' => 0];
        $rewriter = new HtmlRewriter(
            $this->createMock(DetectionRepository::class),
            $this->createMock(StoragePidResolver::class),
            $this->createMock(BridgeNonceService::class),
            $this->createMock(AllowedStylesheetHostRepository::class),
        );
        $ref = new \ReflectionClass($rewriter);
        $ref->getProperty('sameOriginHosts')->setValue($rewriter, []);

        return (string) $ref->getMethod('rewriteHtml')
            ->invokeArgs($rewriter, [$html, new HostMatcher([], true), &$stats]);
    }

    #[Test]
    public function closingTagsInsideJsonScriptAreKept(): void
    {
        $json = '{"lightboxHTML": "<div class=\"gcontainer\"><div class=\"gslider\"></div>'
            . '<button class=\"gclose\">{closeSVG}</button></div>"}';
        $html = '<!DOCTYPE html><html><body>'
            . '<script type="application/json" id="cfg">' . $json . '</script>'
            . '</body></html>';

        self::assertStringContainsString($json, $this->rewrite($html));
    }

    #[Test]
    public function inlineJavaScriptWithMarkupIsKeptVerbatim(): void
    {
        $js = 'var tpl = "<p>Hi</p>"; if (a < b && c > d) { document.write("<span></span>"); }';
        $html = '<!DOCTYPE html><html><body><script>' . $js . '</script></body></html>';

        self::assertStringContainsString($js, $this->rewrite($html));
    }

    #[Test]
    public function externalScriptIsStillRewrittenNextToMaskedInlineScript(): void
    {
        $html = '<!DOCTYPE html><html><body>'
            . '<script type="application/ld+json">{"description": "<b>bold</b>"}</script>'
            . '<script src="https://tracker.example.com/x.js"></script>'
            . '</body></html>';

        $result = $this->rewrite($html);

        self::assertStringContainsString('{"description": "<b>bold</b>"}', $result);
        self::assertStringContainsString('data-src="https://tracker.example.com/x.js"', $result);
        self::assertStringContainsString('type="text/plain"', $result);
    }

    #[Test]
    public function commentedOutScriptDoesNotSwallowFollowingMarkup(): void
    {
        $html = '<!DOCTYPE html><html><body>'
            . '<!-- <script> -->'
            . '<iframe src="https://video.example.com/embed"></iframe>'
            . '<script>var ok = "</em>";</script>'
            . '</body></html>';

        $result = $this->rewrite($html);

        self::assertStringContainsString('data-src="https://video.example.com/embed"', $result);
        self::assertStringContainsString('var ok = "</em>";', $result);
    }

    #[Test]
    public function emptyScriptBodyDoesNotPairWithTheNextScript(): void
    {
        $html = '<!DOCTYPE html><html><body>'
            . '<script src="https://tracker.example.com/a.js"></script>'
            . '<script src="https://tracker.example.com/b.js"></script>'
            . '</body></html>';

        $result = $this->rewrite($html);

        self::assertStringContainsString('data-src="https://tracker.example.com/a.js"', $result);
        self::assertStringContainsString('data-src="https://tracker.example.com/b.js"', $result);
    }

    #[Test]
    public function noPlaceholderTokenLeaksIntoOutput(): void
    {
        $html = '<!DOCTYPE html><html><body>'
            . '<script>a()</script><script>b()</script><script></script>'
            . '</body></html>';

        self::assertStringNotContainsString('simplecmp-inline-script-', $this->rewrite($html));
    }
}
