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

    #[Test]
    public function vendorListIsEmptyByDefault(): void
    {
        // Empty list ⇒ RegisterAssets emits the legacy `consentMode: true`
        // (= Google-only) shape, preserving back-compat for older bundle
        // versions that pre-date ADR-0017's vendor registry.
        self::assertSame([], (new TrackerRuntimeState())->getConsentVendors());
    }

    #[Test]
    public function addingConsentVendorAlsoSetsRequested(): void
    {
        $state = new TrackerRuntimeState();
        $state->addConsentVendor('google');
        self::assertTrue($state->isConsentModeRequested());
        self::assertSame(['google'], $state->getConsentVendors());
    }

    #[Test]
    public function multipleVendorsAreCollectedInInsertionOrder(): void
    {
        $state = new TrackerRuntimeState();
        $state->addConsentVendor('google');
        $state->addConsentVendor('meta');
        $state->addConsentVendor('microsoftUet');
        // Stable insertion order so the emitted init payload is
        // deterministic — easier to debug, easier for end-to-end tests
        // that assert exact shape.
        self::assertSame(['google', 'meta', 'microsoftUet'], $state->getConsentVendors());
    }

    #[Test]
    public function duplicateVendorAddIsIdempotent(): void
    {
        $state = new TrackerRuntimeState();
        $state->addConsentVendor('google');
        $state->addConsentVendor('google');
        $state->addConsentVendor('google');
        self::assertSame(['google'], $state->getConsentVendors());
    }

    #[Test]
    public function legacyRequestPathDoesNotPopulateVendors(): void
    {
        // requestConsentMode() (used by providers that don't implement
        // ConsentVendorAware) sets the flag but leaves the vendor list
        // empty — RegisterAssets then emits the Google-only shape.
        $state = new TrackerRuntimeState();
        $state->requestConsentMode();
        self::assertTrue($state->isConsentModeRequested());
        self::assertSame([], $state->getConsentVendors());
    }
}