<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Service;

/**
 * Single source of truth for the HMAC secret that signs bridge nonces.
 *
 * Reads from `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret']`.
 * Typical deployment uses env interpolation in `additional.php`:
 *
 *     $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret']
 *         = getenv('SIMPLECMP_BRIDGE_SECRET') ?: null;
 *
 * The secret is shared between bridge sender (`RegisterAssets` issuing
 * nonces into JS init config) and receiver (`ServiceDbApi::webhook()`
 * verifying nonces). For same-install setups that's one value; for
 * cross-install setups the admin pastes the same value on both ends.
 *
 * Missing-secret stance is "refuse, don't silently degrade":
 *   - Receiver: webhook returns 503 if a request arrives.
 *   - Sender (RegisterAssets): skip emitting the bridge config.
 * Phase 1 defenses (validator + headers + rate limit) still apply.
 */
final readonly class BridgeSecretProvider
{
    private const string CONFIG_KEY = 'bridgeSecret';
    private const string EXTENSION_KEY = 'simplecmp_typo3';
    private const int MIN_SECRET_BYTES = 32;

    /**
     * @return string|null the raw secret bytes, or null when not configured
     */
    public function get(): ?string
    {
        $value = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][self::EXTENSION_KEY][self::CONFIG_KEY] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        // Accept either raw bytes or base64-encoded; we treat the stored
        // value as opaque key material for HMAC. Refuse implausibly short
        // values to surface mis-pastes.
        if (strlen($value) < self::MIN_SECRET_BYTES) {
            return null;
        }
        return $value;
    }

    public function isConfigured(): bool
    {
        return $this->get() !== null;
    }
}
