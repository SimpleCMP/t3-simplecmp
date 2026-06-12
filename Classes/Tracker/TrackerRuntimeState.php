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
 * The only piece of state currently carried: whether at least one
 * tracker on this request opted into the **signal-gate** posture
 * (Consent Mode v2). When true, RegisterAssets forwards
 * `consentMode: true` into the init config so the engine's hook owns
 * both the v2 `default (denied)` AND the matching `update (granted)`
 * on accept — fulfilling the original `@todo` in `Ga4Provider` /
 * `GtmProvider` and resolving the ADR-0016 anti-pattern (block AND
 * signal-gate, with the dangling `default: denied` silently
 * suppressing GA4 after consent).
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

    public function requestConsentMode(): void
    {
        $this->consentModeRequested = true;
    }

    public function isConsentModeRequested(): bool
    {
        return $this->consentModeRequested;
    }
}