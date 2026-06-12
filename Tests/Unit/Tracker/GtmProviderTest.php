<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Tracker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Tracker\GtmProvider;

final class GtmProviderTest extends TestCase
{
    private GtmProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new GtmProvider();
    }

    #[Test]
    public function defaultPostureIsBlock(): void
    {
        $config = ['containerId' => 'GTM-XYZ123'];
        self::assertTrue($this->provider->wantsLoadGate($config));
        self::assertFalse($this->provider->wantsConsentMode($config));
    }

    #[Test]
    public function signalGatePostureFlipsBothFlags(): void
    {
        $config = ['containerId' => 'GTM-XYZ123', 'consentPosture' => 'signal-gate'];
        self::assertFalse($this->provider->wantsLoadGate($config));
        self::assertTrue($this->provider->wantsConsentMode($config));
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function invalidPostureValues(): array
    {
        return [
            ['signal_gate'],
            ['SIGNAL-GATE'],
            ['enabled'],
            [''],
            [true],                     // legacy `consentDefault: true`
            [null],
            [['signal-gate']],
        ];
    }

    #[Test]
    #[DataProvider('invalidPostureValues')]
    public function unknownPostureFallsBackToBlock(mixed $value): void
    {
        $config = ['containerId' => 'GTM-XYZ123', 'consentPosture' => $value];
        self::assertTrue($this->provider->wantsLoadGate($config));
        self::assertFalse($this->provider->wantsConsentMode($config));
    }

    #[Test]
    public function bootstrapInlineNoLongerEmitsHandRolledConsentDefault(): void
    {
        foreach (['block', 'signal-gate'] as $posture) {
            $script = $this->provider->getBootstrapInlineScript([
                'containerId' => 'GTM-XYZ123',
                'consentPosture' => $posture,
            ]);
            self::assertStringNotContainsString(
                "consent', 'default'",
                $script,
                sprintf('GtmProvider must not hand-roll a consent default in %s posture', $posture),
            );
            self::assertStringNotContainsString('analytics_storage', $script);
            self::assertStringNotContainsString('ad_storage', $script);
            self::assertStringNotContainsString('wait_for_update', $script);
        }
    }

    #[Test]
    public function bootstrapInlineStillEmitsGtmStart(): void
    {
        $script = $this->provider->getBootstrapInlineScript(['containerId' => 'GTM-XYZ123']);
        // The dataLayer + gtm.js event push is what wakes the container
        // once the loader runs. Regression guard so we don't accidentally
        // strip this along with the consent default.
        self::assertStringContainsString('window.dataLayer', $script);
        self::assertStringContainsString("'event': 'gtm.js'", $script);
        self::assertStringContainsString("'gtm.start'", $script);
    }

    #[Test]
    public function rejectsMissingOrMalformedContainerId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->provider->getLoaderUrl(['containerId' => 'not-a-gtm-id']);
    }

    #[Test]
    public function defaultPurposesAreMarketing(): void
    {
        // GTM tags typically include ad / marketing surfaces, so the
        // safer default is `marketing`. The engine's consentMode hook
        // maps this onto `ad_storage + ad_user_data + ad_personalization`.
        $data = $this->provider->buildServiceData(['containerId' => 'GTM-XYZ123']);
        self::assertSame(['marketing'], $data['purposes']);
    }

    #[Test]
    public function purposesCanBeOverriddenViaConfig(): void
    {
        // Analytics-only GTM containers — see the docblock on GtmProvider.
        $data = $this->provider->buildServiceData([
            'containerId' => 'GTM-XYZ123',
            'purposes' => ['analytics'],
        ]);
        self::assertSame(['analytics'], $data['purposes']);
    }
}