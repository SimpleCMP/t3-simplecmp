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
 * `data-name` marks an element as engine-managed, but the engine can only
 * hold back a <script> that is in the gate shape. A `<script src data-name>`
 * without `type="text/plain"` is fetched and executed by the parser.
 */
final class HtmlRewriterIntegratorMarkedScriptTest extends TestCase
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
    public function liveScriptWithDataNameIsMovedIntoGateShape(): void
    {
        $result = $this->rewrite('<!DOCTYPE html><html><body>'
            . '<script async data-name="gtm" src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>'
            . '</body></html>');

        self::assertStringContainsString('data-src="https://www.googletagmanager.com/gtm.js?id=GTM-X"', $result);
        self::assertStringContainsString('type="text/plain"', $result);
        self::assertDoesNotMatchRegularExpression('/<script[^>]*\ssrc=/', $result);
    }

    #[Test]
    public function originalScriptTypeIsKeptInDataType(): void
    {
        $result = $this->rewrite('<!DOCTYPE html><html><body>'
            . '<script type="module" data-name="widget" src="https://cdn.example.com/w.js"></script>'
            . '</body></html>');

        self::assertStringContainsString('data-type="module"', $result);
        self::assertStringContainsString('type="text/plain"', $result);
    }

    #[Test]
    public function scriptAlreadyInGateShapeIsLeftAlone(): void
    {
        $tag = '<script type="text/plain" data-name="gtm" data-src="https://www.googletagmanager.com/gtm.js"></script>';
        $result = $this->rewrite('<!DOCTYPE html><html><body>' . $tag . '</body></html>');

        self::assertStringContainsString($tag, $result);
    }
}
