<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Tracker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Tracker\ConsentVendorAware;
use SimpleCMP\T3SimpleCmp\Tracker\MicrosoftUetProvider;

final class MicrosoftUetProviderTest extends TestCase
{
    private MicrosoftUetProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MicrosoftUetProvider();
    }

    #[Test]
    public function exposesMicrosoftUetVendorKey(): void
    {
        self::assertInstanceOf(ConsentVendorAware::class, $this->provider);
        self::assertSame('microsoftUet', $this->provider->getConsentVendor(['tagId' => '12345678']));
    }

    #[Test]
    public function defaultPostureIsBlock(): void
    {
        // DACH-safest default: load-gate bat.js, no Consent Mode signal.
        // Same posture model as the Google providers (ADR-0016).
        $config = ['tagId' => '12345678'];
        self::assertTrue($this->provider->wantsLoadGate($config));
        self::assertFalse($this->provider->wantsConsentMode($config));
    }

    #[Test]
    public function signalGatePostureFlipsBothFlags(): void
    {
        $config = ['tagId' => '12345678', 'consentPosture' => 'signal-gate'];
        self::assertFalse($this->provider->wantsLoadGate($config));
        self::assertTrue($this->provider->wantsConsentMode($config));
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function invalidPostureValues(): array
    {
        return [
            'unknown'        => ['neither'],
            'mixed case'     => ['Signal-Gate'],
            'empty string'   => [''],
            'integer'        => [1],
            'array'          => [['signal-gate']],
        ];
    }

    #[Test]
    #[DataProvider('invalidPostureValues')]
    public function unknownPostureFallsBackToBlock(mixed $posture): void
    {
        $config = ['tagId' => '12345678', 'consentPosture' => $posture];
        // Unknown value silently degrades to the safe default — no
        // throw, since the posture switch should never be the surface
        // that breaks a page render.
        self::assertTrue($this->provider->wantsLoadGate($config));
        self::assertFalse($this->provider->wantsConsentMode($config));
    }

    #[Test]
    public function buildsServiceDataWithBatBingOriginsAndMuidCookie(): void
    {
        $data = $this->provider->buildServiceData(['tagId' => '12345678']);

        self::assertSame('microsoft-uet', $data['id']);
        self::assertSame('Microsoft UET (Bing Ads)', $data['name']);
        self::assertContains('bat.bing.com', $data['matches']['origins']);
        self::assertContains('MUID', $data['matches']['cookies']);
        // UET writes both `_uetsid` and `_uetvid` plus their `_exp`
        // companion cookies.
        self::assertContains('_uetsid', $data['matches']['cookies']);
        self::assertContains('_uetvid', $data['matches']['cookies']);
        self::assertSame(['marketing'], $data['purposes']);
        self::assertSame('Microsoft Corporation', $data['vendor']);
    }

    #[Test]
    public function loaderUrlIsBatJsRegardlessOfTagId(): void
    {
        // bat.js reads its tag id from the queue's `init` push, not from
        // the URL — so the URL is constant.
        self::assertSame('https://bat.bing.com/bat.js', $this->provider->getLoaderUrl(['tagId' => '12345678']));
        self::assertSame('https://bat.bing.com/bat.js', $this->provider->getLoaderUrl(['tagId' => '99999999']));
    }

    #[Test]
    public function bootstrapPreCreatesUetqAndPushesInit(): void
    {
        $bootstrap = $this->provider->getBootstrapInlineScript(['tagId' => '12345678']);
        // Queue creation must be present so customer-pushed events
        // before bat.js loads still survive (load-gate posture).
        self::assertStringContainsString('window.uetq = window.uetq || []', $bootstrap);
        // The init push tells bat.js which tag to associate with the queued events.
        self::assertStringContainsString('window.uetq.push("event", "init"', $bootstrap);
        self::assertStringContainsString('"12345678"', $bootstrap);
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function invalidTagIds(): array
    {
        return [
            'missing'         => [null],
            'integer'         => [12345678],
            'too short'       => ['12345'],
            'too long'        => ['12345678901'],
            'non-digits'      => ['ABCDEFGH'],
            'with separators' => ['1234-5678'],
        ];
    }

    #[Test]
    #[DataProvider('invalidTagIds')]
    public function rejectsInvalidTagIds(mixed $tagId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tagId/');
        $config = $tagId === null ? [] : ['tagId' => $tagId];
        $this->provider->buildServiceData($config);
    }

    #[Test]
    public function serviceIdCanBeOverridden(): void
    {
        $data = $this->provider->buildServiceData([
            'tagId' => '12345678',
            'serviceId' => 'microsoft-uet-de',
        ]);
        self::assertSame('microsoft-uet-de', $data['id']);
    }
}
