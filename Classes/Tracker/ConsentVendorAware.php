<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tracker;

/**
 * Opt-in companion to {@see TrackerProviderInterface} — providers that
 * implement this interface declare which engine-side **Consent Mode
 * vendor adapter** their signal-gate posture should activate
 * (ADR-0017).
 *
 * Without this interface a provider's `wantsConsentMode()` still
 * signals "engine hook should run" but the engine defaults to the
 * Google-only adapter set (the back-compat shape from ADR-0016).
 * Existing Ga4/Gtm providers continue to work unchanged.
 *
 * Implementations should return a stable, lowercase vendor key
 * matching the bundle's vendor-adapter registry (currently
 * `google`, `meta`, `microsoftUet`). The engine rejects unknown
 * values; treat the bundle's `consent-mode-vendors.ts` as the
 * canonical list.
 */
interface ConsentVendorAware
{
    /**
     * Engine vendor key this provider's signal posture maps to.
     * Called only when the provider's `wantsConsentMode($config)`
     * returned true for the same config — providers that don't
     * support signal-gating at all need not implement this interface.
     *
     * @param array<string, mixed> $config
     */
    public function getConsentVendor(array $config): string;
}
