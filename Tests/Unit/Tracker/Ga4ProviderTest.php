<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Tracker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Tracker\Ga4Provider;

final class Ga4ProviderTest extends TestCase
{
    private Ga4Provider $provider;

    protected function setUp(): void
    {
        $this->provider = new Ga4Provider();
    }

    #[Test]
    public function defaultPostureIsBlock(): void
    {
        // Per ADR-0016 and the consent-mode-v2-tracker-wiring decision,
        // the safe DACH default is to load-gate gtag/js until the visitor
        // accepts (no third-party traffic pre-consent) and to NOT emit a
        // Consent-Mode signal — otherwise the dangling default-deny
        // suppresses GA4 even after consent.
        $config = ['measurementId' => 'G-ABC123'];
        self::assertTrue($this->provider->wantsLoadGate($config));
        self::assertFalse($this->provider->wantsConsentMode($config));
    }

    #[Test]
    public function signalGatePostureFlipsBothFlags(): void
    {
        $config = ['measurementId' => 'G-ABC123', 'consentPosture' => 'signal-gate'];
        // Mutually exclusive: surrendering the load gate is the only way
        // to opt into the Consent Mode v2 signal-gate. Both flags must
        // flip together — anything else is the ADR-0016 anti-pattern.
        self::assertFalse($this->provider->wantsLoadGate($config));
        self::assertTrue($this->provider->wantsConsentMode($config));
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function invalidPostureValues(): array
    {
        return [
            ['signal_gate'],            // wrong separator
            ['SIGNAL-GATE'],            // case-sensitive
            ['enabled'],                // unknown
            [''],                       // blank
            [true],                     // legacy boolean (consentMode pre-0.4.4)
            [null],                     // missing
            [['signal-gate']],          // array
        ];
    }

    #[Test]
    #[DataProvider('invalidPostureValues')]
    public function unknownPostureFallsBackToBlock(mixed $value): void
    {
        // A tampered POST / typo'd YAML / legacy `consentMode: true` must
        // never silently enable signal-gate — the safe default wins.
        $config = ['measurementId' => 'G-ABC123', 'consentPosture' => $value];
        self::assertTrue($this->provider->wantsLoadGate($config));
        self::assertFalse($this->provider->wantsConsentMode($config));
    }

    #[Test]
    public function bootstrapInlineNoLongerEmitsHandRolledConsentDefault(): void
    {
        // Regression guard for ADR-0016. Both postures rely on the
        // engine (or the load gate) to handle consent state — emitting
        // a competing `gtag('consent', 'default', …)` in the provider
        // either re-introduces the suppress-after-consent bug (block
        // posture) or races the engine hook (signal-gate posture).
        foreach (['block', 'signal-gate'] as $posture) {
            $script = $this->provider->getBootstrapInlineScript([
                'measurementId' => 'G-ABC123',
                'consentPosture' => $posture,
            ]);
            self::assertStringNotContainsString(
                "consent', 'default'",
                $script,
                sprintf('Ga4Provider must not hand-roll a consent default in %s posture', $posture),
            );
            self::assertStringNotContainsString('analytics_storage', $script);
            self::assertStringNotContainsString('wait_for_update', $script);
        }
    }

    #[Test]
    public function bootstrapInlineStillEmitsConfigAndAnonymizeIp(): void
    {
        $script = $this->provider->getBootstrapInlineScript([
            'measurementId' => 'G-ABC123',
            'anonymizeIp' => true,
        ]);
        self::assertStringContainsString("gtag('config', \"G-ABC123\"", $script);
        self::assertStringContainsString('"anonymize_ip":true', $script);
    }

    #[Test]
    public function bootstrapInlineOmitsAnonymizeIpWhenDisabled(): void
    {
        $script = $this->provider->getBootstrapInlineScript([
            'measurementId' => 'G-ABC123',
            'anonymizeIp' => false,
        ]);
        self::assertStringNotContainsString('anonymize_ip', $script);
    }

    #[Test]
    public function rejectsMissingOrMalformedMeasurementId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->provider->getLoaderUrl(['measurementId' => 'not-a-ga4-id']);
    }
}