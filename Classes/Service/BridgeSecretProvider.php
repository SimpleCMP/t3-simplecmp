<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use TYPO3\CMS\Core\Configuration\ConfigurationManager;

/**
 * Single source of truth for the HMAC secret that signs bridge nonces.
 *
 * Reads from `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret']`.
 * Typical deployment uses env interpolation in `additional.php`:
 *
 *     $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['t3_simplecmp']['bridgeSecret']
 *         = getenv('SIMPLECMP_BRIDGE_SECRET') ?: null;
 *
 * If no secret is configured, calling `ensureExists()` from a backend
 * admin context (typically the BE module's controller) auto-generates
 * one and persists it to `LocalConfiguration.php` so the bridge works
 * out of the box on first install. Env-var overrides still win — when
 * an env-bound `bridgeSecret` is non-empty, `isConfigured()` returns
 * true and auto-gen is a no-op.
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
    private const string EXTENSION_KEY = 't3_simplecmp';
    private const int MIN_SECRET_BYTES = 32;
    private const int GENERATED_BYTES = 32;

    public function __construct(
        private ?ConfigurationManager $configurationManager = null,
    ) {
    }

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

    /**
     * Auto-generate and persist a secret if none is configured.
     *
     * Safe to call on every BE module access: the `isConfigured()`
     * check is one isset + a length comparison.
     *
     * Intended caller: BE module controllers. Do NOT call from a
     * frontend / API context — config writes outside an admin scope
     * are a TYPO3 anti-pattern, and the BE-context guarantees the
     * write happens in a known-safe context.
     *
     * Returns true when a new secret was generated, false when one
     * already existed (no-op).
     */
    public function ensureExists(): bool
    {
        if ($this->isConfigured()) {
            return false;
        }
        return $this->generateAndPersist();
    }

    /**
     * Force-rotate the secret to a fresh value, regardless of whether
     * one is already configured. Used by the BE "Rotate secret"
     * button.
     *
     * Cost of rotation: nonces issued under the OLD secret no longer
     * verify, so visitors with bridge connections initialised before
     * the rotation will get one 401 cycle on their next webhook POST
     * until their next page render re-issues a fresh nonce. The
     * existing in-flight pages (page-loaded BEFORE the rotation) are
     * the only window where this matters; subsequent visits work
     * unchanged.
     *
     * Returns true when rotation succeeded.
     */
    public function rotate(): bool
    {
        return $this->generateAndPersist();
    }

    /**
     * Writes a fresh random secret to LocalConfiguration.php via
     * `ConfigurationManager` so the value survives cache flushes and
     * is shared across PHP workers. Also mirrors into `$GLOBALS` so
     * the rest of the current request sees the value without needing
     * a process restart.
     */
    private function generateAndPersist(): bool
    {
        if ($this->configurationManager === null) {
            // Defensive: in test contexts the provider may be
            // instantiated without DI. Don't write anywhere — caller
            // sees false.
            return false;
        }
        $secret = base64_encode(random_bytes(self::GENERATED_BYTES));
        $this->configurationManager->setLocalConfigurationValueByPath(
            'EXTENSIONS/' . self::EXTENSION_KEY . '/' . self::CONFIG_KEY,
            $secret,
        );
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][self::EXTENSION_KEY][self::CONFIG_KEY] = $secret;
        return true;
    }
}
