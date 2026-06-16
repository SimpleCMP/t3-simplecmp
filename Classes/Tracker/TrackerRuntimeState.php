<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Per-request runtime state shared between
 * {@see \SimpleCMP\T3SimpleCmp\EventListener\TrackerMaterializer} (which
 * walks the configured trackers and resolves their per-tracker consent
 * posture) and
 * {@see \SimpleCMP\T3SimpleCmp\EventListener\RegisterAssets} (which
 * builds the `cmp.init()` payload).
 *
 * Tracks two related pieces of state:
 *
 *   - whether ANY tracker on this request opted into the signal-gate
 *     posture (Consent Mode v2). When true, RegisterAssets forwards
 *     a non-falsy `consentMode` into the init config so the engine
 *     hook owns the v2 `default (denied)` AND the `update (granted)`
 *     on accept — resolving the ADR-0016 anti-pattern.
 *
 *   - which engine-side **vendor adapters** to enable (ADR-0017).
 *     Each provider that returns true from `wantsConsentMode()`
 *     contributes its vendor key (`google`, `meta`, `microsoftUet`)
 *     via `addConsentVendor()`. RegisterAssets builds the
 *     `consentMode: { vendors: [...] }` shape from these — or
 *     emits the legacy `consentMode: true` (= Google-only) when
 *     no vendor was explicitly added, preserving backward compat
 *     for providers that pre-date the vendor system.
 *
 * SingletonInterface means TYPO3 reuses the same instance for the
 * whole request; the listener ordering on `BeforeJavaScriptsRendering`
 * guarantees `TrackerMaterializer` runs first
 * ({@see TrackerMaterializer::class}'s `before:` attribute) so
 * RegisterAssets always sees the resolved state.
 */
final class TrackerRuntimeState implements SingletonInterface
{
    private bool $consentModeRequested = false;

    /** @var array<string, true> Set-of-vendor keys, kept stable-ordered via insertion. */
    private array $consentVendors = [];

    public function requestConsentMode(): void
    {
        $this->consentModeRequested = true;
    }

    public function isConsentModeRequested(): bool
    {
        return $this->consentModeRequested;
    }

    /**
     * Add an engine-side vendor adapter key (ADR-0017). Valid keys
     * today are `google`, `meta`, `microsoftUet` — the engine's vendor
     * registry rejects unknown values, so the bundle's own type checks
     * are the source of truth. Providers should call this from their
     * materialization path whenever they would have called
     * {@see requestConsentMode()} alone.
     *
     * Duplicate calls for the same vendor are idempotent.
     */
    public function addConsentVendor(string $vendor): void
    {
        $this->consentModeRequested = true;
        $this->consentVendors[$vendor] = true;
    }

    /**
     * Resolved vendor list, in insertion order. Empty when no provider
     * declared a vendor — RegisterAssets uses that as the cue to emit
     * the legacy `consentMode: true` (Google-only) shape.
     *
     * @return list<string>
     */
    public function getConsentVendors(): array
    {
        return array_keys($this->consentVendors);
    }
}