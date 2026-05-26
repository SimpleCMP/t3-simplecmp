<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Issues and verifies short-lived nonces for the bridge webhook.
 *
 * Format (all parts base64url-encoded, dot-separated):
 *
 *     <source>.<expiryMs>.<random16>.<hmacSha256>
 *
 * HMAC input is the canonical string `source|expiryMs|random16`. The
 * source is bound into the signature so a nonce issued for site A
 * cannot be replayed against site B (multi-tenant blast-radius
 * containment).
 *
 * TTL defaults to 1 hour. Strict 5-min would require a refresh
 * callback on the SimpleCMP JS side (an upstream API change); 1 hour
 * is a pragmatic tradeoff for v1 — pages open longer lose bridge
 * function until reload, which is acceptable UX.
 *
 * Nonces are stateless: no server-side store, no replay-window
 * tracking within the TTL. The bridge dedup map on the JS side and
 * the per-IP rate limit on the receiver bound abuse within the
 * window. Replay protection is by HMAC + expiry only.
 */
final readonly class BridgeNonceService
{
    public const int DEFAULT_TTL_SECONDS = 3600;
    private const int RANDOM_BYTES = 16;

    public function __construct(
        private BridgeSecretProvider $secretProvider,
    ) {
    }

    public function issue(string $source, ?int $ttlSeconds = null, ?int $nowMs = null): string
    {
        $secret = $this->secret();
        $expiryMs = ($nowMs ?? (int) floor(microtime(true) * 1000))
            + ($ttlSeconds ?? self::DEFAULT_TTL_SECONDS) * 1000;
        $random = random_bytes(self::RANDOM_BYTES);

        $sourcePart = self::b64url($source);
        $randomPart = self::b64url($random);
        $hmac = $this->hmac($sourcePart, $expiryMs, $randomPart, $secret);

        return $sourcePart . '.' . $expiryMs . '.' . $randomPart . '.' . self::b64url($hmac);
    }

    public function verify(string $nonce, string $expectedSource, ?int $nowMs = null): BridgeNonceVerification
    {
        $secret = $this->secret();
        $parts = explode('.', $nonce);
        if (count($parts) !== 4) {
            return BridgeNonceVerification::malformed();
        }
        [$sourcePart, $expiryPart, $randomPart, $sigPart] = $parts;
        if ($sourcePart === '' || $randomPart === '' || $sigPart === '' || !ctype_digit($expiryPart)) {
            return BridgeNonceVerification::malformed();
        }
        $expiryMs = (int) $expiryPart;
        $sigBytes = self::b64urlDecode($sigPart);
        if ($sigBytes === null) {
            return BridgeNonceVerification::malformed();
        }
        $expected = $this->hmac($sourcePart, $expiryMs, $randomPart, $secret);
        if (!hash_equals($expected, $sigBytes)) {
            return BridgeNonceVerification::invalid();
        }
        $decodedSource = self::b64urlDecode($sourcePart);
        if ($decodedSource !== $expectedSource) {
            return BridgeNonceVerification::sourceMismatch();
        }
        $now = $nowMs ?? (int) floor(microtime(true) * 1000);
        if ($expiryMs <= $now) {
            return BridgeNonceVerification::expired();
        }
        return BridgeNonceVerification::ok();
    }

    private function secret(): string
    {
        $secret = $this->secretProvider->get();
        if ($secret === null) {
            throw new \RuntimeException(
                'SimpleCMP bridge secret is not configured. The BE module normally generates one on first access; if this exception fires, the auto-gen path didn\'t run (e.g. config/system/settings.php not writable by PHP). Open the SimpleCMP BE module to retry, or run `vendor/bin/typo3 simplecmp:generate-bridge-secret` and paste the printed value into your TYPO3 config.',
            );
        }
        return $secret;
    }

    private function hmac(string $sourcePart, int $expiryMs, string $randomPart, string $secret): string
    {
        return hash_hmac('sha256', $sourcePart . '|' . $expiryMs . '|' . $randomPart, $secret, true);
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): ?string
    {
        $decoded = base64_decode(strtr($s, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
