<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\UniversalBlocking\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\AllowedStylesheetHostRepository;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceService;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Middleware\HtmlRewriter;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Service\HostMatcher;

/**
 * REQ-N8 Phase B — opt-in third-party stylesheet blocking.
 *
 * With `blockStylesheets` on, a third-party `<link rel="stylesheet">` is
 * neutralised to `data-name + data-href` with the live `href` stripped (the
 * engine reinjects it on consent — see the engine-side
 * stylesheet-block-reinject test). Off by default, same-origin and
 * allowlisted hosts are always left alone.
 */
final class HtmlRewriterStylesheetTest extends TestCase
{
    /**
     * @param list<string> $sameOrigin
     * @param list<string> $allowlist
     */
    private function rewrite(
        string $html,
        bool $blockStylesheets = true,
        array $sameOrigin = [],
        array $allowlist = [],
    ): string {
        $rewriter = new HtmlRewriter(
            $this->createMock(DetectionRepository::class),
            $this->createMock(StoragePidResolver::class),
            $this->createMock(BridgeNonceService::class),
            $this->createMock(AllowedStylesheetHostRepository::class),
        );
        $ref = new \ReflectionClass($rewriter);
        $ref->getProperty('sameOriginHosts')->setValue($rewriter, $sameOrigin);
        $ref->getProperty('blockStylesheets')->setValue($rewriter, $blockStylesheets);
        // blockAllThirdParty=true → any non-allowlisted host resolves.
        $matcher = new HostMatcher($allowlist, true);
        $stats = ['scanned' => 0, 'rewritten' => 0];

        return (string) $ref->getMethod('rewriteHtml')->invokeArgs($rewriter, [$html, $matcher, &$stats]);
    }

    private function page(string $linkTag): string
    {
        return "<!DOCTYPE html><html><head>{$linkTag}</head><body><p>x</p></body></html>";
    }

    private const FONTS = 'https://fonts.googleapis.com/css2?family=Roboto';

    #[Test]
    public function blocksThirdPartyStylesheetWhenOptedIn(): void
    {
        $result = $this->rewrite($this->page('<link rel="stylesheet" href="' . self::FONTS . '">'));

        self::assertStringContainsString('data-name', $result);
        self::assertStringContainsString('data-href="' . self::FONTS . '"', $result);
        // Live href stripped (not about:blank'd — a stylesheet would still
        // try to load about:blank). `data-href="` keeps a `-` before `href`,
        // so ` href="` matches only a real live href attribute.
        self::assertStringNotContainsString(' href="', $result);
        self::assertStringNotContainsString('about:blank', $result);
    }

    #[Test]
    public function leavesThirdPartyStylesheetAloneWhenOptedOut(): void
    {
        $result = $this->rewrite(
            $this->page('<link rel="stylesheet" href="' . self::FONTS . '">'),
            blockStylesheets: false,
        );

        self::assertStringNotContainsString('data-name', $result);
        self::assertStringContainsString(self::FONTS, $result);
    }

    #[Test]
    public function leavesSameOriginStylesheetAlone(): void
    {
        $result = $this->rewrite(
            $this->page('<link rel="stylesheet" href="https://assets.example.com/app.css">'),
            sameOrigin: ['assets.example.com'],
        );

        self::assertStringNotContainsString('data-name', $result);
        self::assertStringContainsString('https://assets.example.com/app.css', $result);
    }

    #[Test]
    public function leavesAllowlistedStylesheetAlone(): void
    {
        $result = $this->rewrite(
            $this->page('<link rel="stylesheet" href="https://cdn.example.com/bootstrap.css">'),
            allowlist: ['cdn.example.com'],
        );

        self::assertStringNotContainsString('data-name', $result);
        self::assertStringContainsString('https://cdn.example.com/bootstrap.css', $result);
    }

    #[Test]
    public function blockedStylesheetIsRecordedWithStylesheetKind(): void
    {
        // The distinct `stylesheet` kind lets the BE surface blocked
        // stylesheets separately from resource-hint <link>s (Phase C review).
        $rewriter = new HtmlRewriter(
            $this->createMock(DetectionRepository::class),
            $this->createMock(StoragePidResolver::class),
            $this->createMock(BridgeNonceService::class),
            $this->createMock(AllowedStylesheetHostRepository::class),
        );
        $ref = new \ReflectionClass($rewriter);
        $ref->getProperty('sameOriginHosts')->setValue($rewriter, []);
        $ref->getProperty('blockStylesheets')->setValue($rewriter, true);
        $matcher = new HostMatcher([], true);
        $stats = ['scanned' => 0, 'rewritten' => 0];
        $detections = [];

        $ref->getMethod('rewriteHtml')->invokeArgs(
            $rewriter,
            [$this->page('<link rel="stylesheet" href="' . self::FONTS . '">'), $matcher, &$stats, &$detections],
        );

        self::assertCount(1, $detections);
        self::assertSame('stylesheet', $detections[0]['kind']);
        self::assertSame(self::FONTS, $detections[0]['identifier']);
        self::assertSame('fonts.googleapis.com', $detections[0]['origin']);
    }

    #[Test]
    public function allowlistedStylesheetHostIsScopedToStylesheetsOnly(): void
    {
        // Phase C2: the per-host fast-allow (stylesheetAllowHosts) exempts only
        // the host's *stylesheets*. A script from the very same host must still
        // be neutralised — the allow is deliberately not host-wide.
        $rewriter = new HtmlRewriter(
            $this->createMock(DetectionRepository::class),
            $this->createMock(StoragePidResolver::class),
            $this->createMock(BridgeNonceService::class),
            $this->createMock(AllowedStylesheetHostRepository::class),
        );
        $ref = new \ReflectionClass($rewriter);
        $ref->getProperty('sameOriginHosts')->setValue($rewriter, []);
        $ref->getProperty('blockStylesheets')->setValue($rewriter, true);
        $ref->getProperty('stylesheetAllowHosts')->setValue($rewriter, ['fonts.googleapis.com']);
        $matcher = new HostMatcher([], true);
        $stats = ['scanned' => 0, 'rewritten' => 0];

        $html = '<!DOCTYPE html><html><head>'
            . '<link rel="stylesheet" href="' . self::FONTS . '">'
            . '<script src="https://fonts.googleapis.com/track.js"></script>'
            . '</head><body><p>x</p></body></html>';
        $result = (string) $ref->getMethod('rewriteHtml')->invokeArgs($rewriter, [$html, $matcher, &$stats]);

        // Stylesheet from the allowed host: untouched, still live.
        self::assertStringContainsString('href="' . self::FONTS . '"', $result);
        // Script from the same host: still neutralised (exactly one rewrite).
        self::assertStringContainsString('data-src="https://fonts.googleapis.com/track.js"', $result);
        self::assertSame(1, substr_count($result, 'data-name'));
    }
}
