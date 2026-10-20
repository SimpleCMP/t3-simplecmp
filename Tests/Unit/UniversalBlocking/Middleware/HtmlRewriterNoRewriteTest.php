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
 * `data-no-rewrite` opts an element out of Universal Blocking. The
 * signal-gate tracker loader relies on it: GTM must load before consent so
 * Consent Mode can gate the tags inside the container.
 */
final class HtmlRewriterNoRewriteTest extends TestCase
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
    public function libraryHostIsGatedWithoutTheMarker(): void
    {
        $result = $this->rewrite('<!DOCTYPE html><html><body>'
            . '<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>'
            . '</body></html>');

        self::assertStringContainsString('type="text/plain"', $result);
        self::assertDoesNotMatchRegularExpression('/<script[^>]*\ssrc=/', $result);
    }

    #[Test]
    public function markedLoaderIsLeftLive(): void
    {
        $result = $this->rewrite('<!DOCTYPE html><html><body>'
            . '<script async data-no-rewrite="1" src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script>'
            . '</body></html>');

        self::assertMatchesRegularExpression('/<script[^>]*\ssrc="https:\/\/www\.googletagmanager\.com\/gtm\.js\?id=GTM-X"/', $result);
        self::assertStringNotContainsString('text/plain', $result);
        self::assertStringNotContainsString('data-src', $result);
    }
}
