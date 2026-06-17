<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Setup-Wizard state — thin facade over {@see EffectiveSettingsResolver}'s
 * INTERNAL_KEYS. Two timestamps decide whether the onboarding banner is
 * shown:
 *
 *   - `wizardCompletedAt` — editor finished the wizard.
 *   - `wizardSkippedAt`   — editor explicitly dismissed it.
 *
 * Either of them being set hides the banner. The Settings tab's "Wizard
 * erneut starten" link calls {@see reopen()} which drops both keys.
 */
final readonly class WizardStateService
{
    public const string KEY_COMPLETED_AT = 'simplecmp.internal.wizardCompletedAt';
    public const string KEY_SKIPPED_AT = 'simplecmp.internal.wizardSkippedAt';

    public function __construct(
        private EffectiveSettingsResolver $effectiveSettings,
        private ClockInterface $clock = new SystemClock(),
    ) {
    }

    public function shouldShowBanner(string $siteIdentifier): bool
    {
        return $this->effectiveSettings->getInternal($siteIdentifier, self::KEY_COMPLETED_AT) === null
            && $this->effectiveSettings->getInternal($siteIdentifier, self::KEY_SKIPPED_AT) === null;
    }

    public function isCompleted(string $siteIdentifier): bool
    {
        return $this->effectiveSettings->getInternal($siteIdentifier, self::KEY_COMPLETED_AT) !== null;
    }

    public function markCompleted(string $siteIdentifier, int $beUserId): void
    {
        $this->effectiveSettings->setInternal(
            $siteIdentifier,
            self::KEY_COMPLETED_AT,
            $this->clock->now(),
            $beUserId,
        );
        // Completion supersedes "skip" — drop the skip marker so the
        // Settings-tab UI doesn't have to disambiguate the two.
        if ($this->effectiveSettings->getInternal($siteIdentifier, self::KEY_SKIPPED_AT) !== null) {
            $this->effectiveSettings->deleteInternal($siteIdentifier, self::KEY_SKIPPED_AT, $beUserId);
        }
    }

    public function markSkipped(string $siteIdentifier, int $beUserId): void
    {
        $this->effectiveSettings->setInternal(
            $siteIdentifier,
            self::KEY_SKIPPED_AT,
            $this->clock->now(),
            $beUserId,
        );
    }

    public function reopen(string $siteIdentifier, int $beUserId): void
    {
        if ($this->effectiveSettings->getInternal($siteIdentifier, self::KEY_COMPLETED_AT) !== null) {
            $this->effectiveSettings->deleteInternal($siteIdentifier, self::KEY_COMPLETED_AT, $beUserId);
        }
        if ($this->effectiveSettings->getInternal($siteIdentifier, self::KEY_SKIPPED_AT) !== null) {
            $this->effectiveSettings->deleteInternal($siteIdentifier, self::KEY_SKIPPED_AT, $beUserId);
        }
    }
}
