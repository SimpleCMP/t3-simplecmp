<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * HMAC-SHA256 pseudonymization of visitor UUIDs (audit-trail Phase 2+3).
 *
 * Single source of truth for the hash recipe: previously inlined as a
 * private method on {@see \SimpleCMP\T3SimpleCmp\Middleware\ServiceDbApi}.
 * Phase 3 needs the same hash in three different call sites
 * (ServiceDbApi consent-log POST, Auskunfts-BE form lookup, CLI export
 * for visitor filters), so the recipe lives here exactly once.
 *
 * Recipe — DO NOT CHANGE without also bumping a migration:
 *
 *     hash_hmac('sha256', $uuid, $secret . ':' . $source)
 *
 * Properties preserved across split-out:
 *   - Same UUID + same source + same secret → same hash → dedup works.
 *   - Different sources yield different hashes for the same UUID →
 *     no cross-site visitor correlation.
 *   - Raw UUID is unrecoverable from the hash without the secret.
 *
 * Secret rotation invalidates ALL existing hashes (by design — Phase-1+2
 * doc says "within a single secret-rotation lifetime"). The Auskunfts-
 * flow is therefore best-effort against historic rows, but the Live data
 * since the last rotation is always queryable.
 */
final readonly class VisitorUuidHasher
{
    public function __construct(
        private BridgeSecretProvider $secretProvider,
    ) {
    }

    /**
     * Hash a raw UUID for storage / lookup. Returns 64-char lowercase
     * hex (sha256). Callers SHOULD ensure $uuid passed RFC-4122 v4
     * validation upstream; this method does not re-validate.
     */
    public function hash(string $uuid, string $source): string
    {
        $secret = $this->secretProvider->get();
        // The bridge secret presence is enforced upstream — callers
        // hitting this code path have already been through
        // nonce-verify or admin-only context. The `?? ''` is a
        // defensive last-resort to avoid hashing with a literal `null`
        // (would PHP-warn) rather than a legitimate fallback.
        return hash_hmac('sha256', $uuid, ($secret ?? '') . ':' . $source);
    }
}
