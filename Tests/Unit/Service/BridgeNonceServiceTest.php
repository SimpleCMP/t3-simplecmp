<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WapplerSystems\SimpleCmpTypo3\Service\BridgeNonceService;
use WapplerSystems\SimpleCmpTypo3\Service\BridgeNonceVerification;
use WapplerSystems\SimpleCmpTypo3\Service\BridgeSecretProvider;

final class BridgeNonceServiceTest extends TestCase
{
    private const string SOURCE = 'simplecmp-default';

    private BridgeNonceService $service;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret']
            = base64_encode(random_bytes(32));
        $this->service = new BridgeNonceService(new BridgeSecretProvider());
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret']);
    }

    #[Test]
    public function issuedNonceVerifies(): void
    {
        $nonce = $this->service->issue(self::SOURCE);
        $result = $this->service->verify($nonce, self::SOURCE);
        self::assertTrue($result->isValid(), 'status was: ' . $result->status);
    }

    #[Test]
    public function nonceFormatHasFourDotSeparatedParts(): void
    {
        $nonce = $this->service->issue(self::SOURCE);
        self::assertCount(4, explode('.', $nonce));
    }

    #[Test]
    public function rejectsTamperedSignature(): void
    {
        $nonce = $this->service->issue(self::SOURCE);
        $parts = explode('.', $nonce);
        // Replace the first byte of the signature with a character we know
        // it isn't — `A` for any first byte != 'A', else `B`. Both are
        // base64url-valid so the format check still passes; the HMAC just
        // won't match.
        $sig = $parts[3];
        $parts[3] = ($sig[0] === 'A' ? 'B' : 'A') . substr($sig, 1);
        $tampered = implode('.', $parts);
        $result = $this->service->verify($tampered, self::SOURCE);
        self::assertFalse($result->isValid());
    }

    #[Test]
    public function rejectsMismatchedSource(): void
    {
        $nonce = $this->service->issue(self::SOURCE);
        $result = $this->service->verify($nonce, 'other-site');
        self::assertSame(BridgeNonceVerification::SOURCE_MISMATCH, $result->status);
    }

    #[Test]
    public function rejectsExpiredNonce(): void
    {
        // Issue with the timestamp shifted into the past so its TTL is already done.
        $nowMs = (int) floor(microtime(true) * 1000);
        $nonce = $this->service->issue(self::SOURCE, 60, $nowMs - 200_000);
        $result = $this->service->verify($nonce, self::SOURCE, $nowMs);
        self::assertSame(BridgeNonceVerification::EXPIRED, $result->status);
    }

    #[Test]
    public function rejectsMalformedNonce(): void
    {
        self::assertSame(
            BridgeNonceVerification::MALFORMED,
            $this->service->verify('not.a.valid.nonce.has.too.many.parts', self::SOURCE)->status,
        );
        self::assertSame(
            BridgeNonceVerification::MALFORMED,
            $this->service->verify('bare-string', self::SOURCE)->status,
        );
        self::assertSame(
            BridgeNonceVerification::MALFORMED,
            $this->service->verify('a..c.d', self::SOURCE)->status,
        );
    }

    #[Test]
    public function rejectsNonceSignedWithDifferentSecret(): void
    {
        $nonce = $this->service->issue(self::SOURCE);
        // Rotate the secret.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret']
            = base64_encode(random_bytes(32));
        $other = new BridgeNonceService(new BridgeSecretProvider());
        $result = $other->verify($nonce, self::SOURCE);
        self::assertSame(BridgeNonceVerification::INVALID, $result->status);
    }

    #[Test]
    public function issueThrowsWhenSecretMissing(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret']);
        $service = new BridgeNonceService(new BridgeSecretProvider());
        $this->expectException(\RuntimeException::class);
        $service->issue(self::SOURCE);
    }
}
