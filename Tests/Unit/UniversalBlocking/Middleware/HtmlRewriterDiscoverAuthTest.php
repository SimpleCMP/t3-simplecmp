<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\UniversalBlocking\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceService;
use SimpleCMP\T3SimpleCmp\Service\BridgeSecretProvider;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Middleware\HtmlRewriter;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The discover server-side DB write (HtmlRewriter::recordDiscoverDetections)
 * is the only path besides the HMAC-authed bridge webhook that can reach
 * DetectionRepository::ingest(). `?simplecmp_discover=1` is settable by any
 * anonymous visitor, so it alone must NOT authorise a write — only a valid,
 * source-bound, unexpired `simplecmp_discover_token` (a BridgeNonceService
 * nonce minted by the BE) may. This locks `HtmlRewriter::discoverTokenValid`,
 * the gate that decides it.
 */
final class HtmlRewriterDiscoverAuthTest extends TestCase
{
    private const string SOURCE = 'mysite';

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret']
            = base64_encode(random_bytes(32));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret']);
    }

    #[Test]
    public function acceptsAValidSourceBoundToken(): void
    {
        $nonce = $this->nonceService();
        $token = $nonce->issue(self::SOURCE, 3600);
        self::assertTrue(
            $this->isValid($this->rewriter($nonce), $token, $this->siteWithStorageName(self::SOURCE)),
        );
    }

    #[Test]
    public function acceptsTokenBoundToTheIdentifierFallbackSource(): void
    {
        // No storageName configured → DiscoverSource falls back to
        // `simplecmp-<identifier>`; mint + verify must agree on it.
        $nonce = $this->nonceService();
        $token = $nonce->issue('simplecmp-main', 3600);
        self::assertTrue(
            $this->isValid($this->rewriter($nonce), $token, $this->siteWithoutStorageName('main')),
        );
    }

    #[Test]
    public function rejectsMissingEmptyAndNonStringToken(): void
    {
        $rewriter = $this->rewriter($this->nonceService());
        $site = $this->siteWithStorageName(self::SOURCE);
        self::assertFalse($this->isValid($rewriter, null, $site), 'missing');
        self::assertFalse($this->isValid($rewriter, '', $site), 'empty');
        self::assertFalse($this->isValid($rewriter, 12345, $site), 'non-string');
    }

    #[Test]
    public function rejectsForgedToken(): void
    {
        $nonce = $this->nonceService();
        self::assertFalse(
            $this->isValid($this->rewriter($nonce), 'bm90.0.YQ.Zm9yZ2Vk', $this->siteWithStorageName(self::SOURCE)),
        );
    }

    #[Test]
    public function rejectsTokenBoundToADifferentSource(): void
    {
        // A token minted for site B must not authorise a write for site A
        // (multi-tenant blast-radius containment — the nonce is source-bound).
        $nonce = $this->nonceService();
        $token = $nonce->issue('other-site', 3600);
        self::assertFalse(
            $this->isValid($this->rewriter($nonce), $token, $this->siteWithStorageName(self::SOURCE)),
        );
    }

    #[Test]
    public function rejectsExpiredToken(): void
    {
        $nonce = $this->nonceService();
        // nowMs=1000, ttl=1s → expires at 2000ms, long in the past vs. real now.
        $expired = $nonce->issue(self::SOURCE, 1, 1000);
        self::assertFalse(
            $this->isValid($this->rewriter($nonce), $expired, $this->siteWithStorageName(self::SOURCE)),
        );
    }

    #[Test]
    public function failsClosedWhenNoSecretConfigured(): void
    {
        // Mint a valid token, then drop the secret: verify() throws and the
        // gate must swallow it and refuse (no write), not bubble out of the
        // middleware.
        $nonce = $this->nonceService();
        $token = $nonce->issue(self::SOURCE, 3600);
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret']);
        self::assertFalse(
            $this->isValid($this->rewriter($nonce), $token, $this->siteWithStorageName(self::SOURCE)),
        );
    }

    // --- helpers --------------------------------------------------------

    private function nonceService(): BridgeNonceService
    {
        return new BridgeNonceService(new BridgeSecretProvider());
    }

    private function rewriter(BridgeNonceService $nonce): HtmlRewriter
    {
        return new HtmlRewriter(
            $this->createMock(DetectionRepository::class),
            $this->createMock(StoragePidResolver::class),
            $nonce,
        );
    }

    private function siteWithStorageName(string $storageName): Site
    {
        return new Site('any-id', 1, [
            'base' => 'https://example.com/',
            'settings' => ['simplecmp' => ['storageName' => $storageName]],
        ]);
    }

    private function siteWithoutStorageName(string $identifier): Site
    {
        return new Site($identifier, 1, ['base' => 'https://example.com/']);
    }

    private function isValid(HtmlRewriter $rewriter, mixed $token, Site $site): bool
    {
        $method = (new \ReflectionClass($rewriter))->getMethod('discoverTokenValid');
        return (bool) $method->invoke($rewriter, $token, $site);
    }
}
