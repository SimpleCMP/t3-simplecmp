<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Tracker;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerRuntimeState;

final class TrackerRuntimeStateTest extends TestCase
{
    #[Test]
    public function defaultStateIsConsentModeNotRequested(): void
    {
        // RegisterAssets only emits `consentMode: true` into the init
        // payload when a tracker on this request opted into signal-gate.
        // The default must be "off" so block-only sites get the minimal
        // payload — and so legacy installs upgrading don't suddenly
        // start sending Consent-Mode signals they didn't ask for.
        self::assertFalse((new TrackerRuntimeState())->isConsentModeRequested());
    }

    #[Test]
    public function requestingConsentModeIsSticky(): void
    {
        $state = new TrackerRuntimeState();
        $state->requestConsentMode();
        self::assertTrue($state->isConsentModeRequested());

        // Subsequent calls don't toggle it back off — one signal-gate
        // tracker is enough for the whole request to need consentMode.
        $state->requestConsentMode();
        self::assertTrue($state->isConsentModeRequested());
    }
}