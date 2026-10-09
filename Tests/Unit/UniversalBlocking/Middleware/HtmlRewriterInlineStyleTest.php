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
 * Inline <style> bodies must survive the DOMDocument round-trip verbatim.
 *
 * With libxml < 2.14 saveHTML() writes every non-ASCII character as an
 * entity, also inside <style>. The browser does not decode entities in a
 * raw-text element: an icon font's `content:"\f10d"` showed up on the page
 * as the text `&#61709;`, and a BOM in front of `:root` became `&#65279;`,
 * which invalidated the rule with all of Bootstrap's custom properties.
 * These tests fail on such libxml versions without the masking in
 * HtmlRewriter::maskRawTextElements().
 */
final class HtmlRewriterInlineStyleTest extends TestCase
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
    public function iconFontGlyphInInlineStyleIsKeptVerbatim(): void
    {
        $css = "a.mail:before{content:\"\u{F10D}\"} .x:after{content:\"\u{2192}\"}";
        $html = '<!DOCTYPE html><html><head><style>' . $css . '</style></head><body></body></html>';

        $result = $this->rewrite($html);

        self::assertStringContainsString($css, $result);
        self::assertStringNotContainsString('&#61709;', $result);
    }

    #[Test]
    public function byteOrderMarkInInlineStyleIsNotTurnedIntoAnEntity(): void
    {
        $css = "/* concatenated */\n\u{FEFF}:root{--bs-blue:#0d6efd}";
        $html = '<!DOCTYPE html><html><head><style media="all">' . $css . '</style></head><body></body></html>';

        $result = $this->rewrite($html);

        self::assertStringContainsString($css, $result);
        self::assertStringNotContainsString('&#65279;', $result);
    }

    #[Test]
    public function thirdPartyScriptNextToInlineStyleIsStillRewritten(): void
    {
        $html = "<!DOCTYPE html><html><head><style>p{content:\"\u{00DC}\"}</style></head><body>"
            . '<script src="https://tracker.example.com/x.js"></script>'
            . '</body></html>';

        $result = $this->rewrite($html);

        self::assertStringContainsString("p{content:\"\u{00DC}\"}", $result);
        self::assertStringContainsString('data-src="https://tracker.example.com/x.js"', $result);
    }

    #[Test]
    public function encodingHintDoesNotLeakIntoTheResponse(): void
    {
        // libxml >= 2.14 serializes the prepended XML encoding hint
        // as a bogus comment; the strip must catch that form too.
        $html = '<!DOCTYPE html><html><head><style>a{}</style></head><body><p>x</p></body></html>';

        self::assertStringNotContainsString('encoding="utf-8"', $this->rewrite($html));
    }
}
