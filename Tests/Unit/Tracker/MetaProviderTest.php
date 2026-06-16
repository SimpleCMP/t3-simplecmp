<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Tracker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Tracker\ConsentVendorAware;
use SimpleCMP\T3SimpleCmp\Tracker\MetaProvider;

final class MetaProviderTest extends TestCase
{
    private MetaProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MetaProvider();
    }

    #[Test]
    public function exposesMetaVendorKey(): void
    {
        self::assertInstanceOf(ConsentVendorAware::class, $this->provider);
        self::assertSame('meta', $this->provider->getConsentVendor(['pixelId' => '1234567890123']));
    }

    #[Test]
    public function buildsServiceDataWithPixelOriginsAndCookies(): void
    {
        $data = $this->provider->buildServiceData(['pixelId' => '1234567890123']);

        self::assertSame('meta-pixel', $data['id']);
        self::assertSame('Meta Pixel (Facebook)', $data['name']);
        // Vendor-specific origins the CSP bridge has to whitelist.
        self::assertContains('connect.facebook.net', $data['matches']['origins']);
        self::assertContains('www.facebook.com', $data['matches']['origins']);
        // The two first-party cookies fbq sets on the customer host.
        self::assertContains('_fbp', $data['matches']['cookies']);
        self::assertContains('_fbc', $data['matches']['cookies']);
        // Marketing purpose — Meta Pixel does ad measurement and audience building.
        self::assertSame(['marketing'], $data['purposes']);
        // Vendor metadata for the per-instance L2 Provider-Informationen modal.
        self::assertSame('Meta Platforms Ireland Ltd.', $data['vendor']);
        self::assertSame('IE', $data['vendorCountry']);
    }

    #[Test]
    public function serviceIdCanBeOverridden(): void
    {
        $data = $this->provider->buildServiceData([
            'pixelId' => '1234567890123',
            'serviceId' => 'meta-de',
        ]);
        self::assertSame('meta-de', $data['id']);
    }

    #[Test]
    public function emitsNoLoaderUrl(): void
    {
        // Meta's own snippet must continue to be the canonical loader.
        // Pre-defining window.fbq from PHP would bail Meta's loader
        // (`if(f.fbq)return;`) and break the merchant's pixel.
        self::assertNull($this->provider->getLoaderUrl(['pixelId' => '1234567890123']));
    }

    #[Test]
    public function emitsNoBootstrapInline(): void
    {
        self::assertSame('', $this->provider->getBootstrapInlineScript(['pixelId' => '1234567890123']));
    }

    #[Test]
    public function neverLoadGatesAlwaysSignals(): void
    {
        $config = ['pixelId' => '1234567890123'];
        // No loader to gate — load-gating is universal-blocking's
        // territory for Meta (ADR-0013).
        self::assertFalse($this->provider->wantsLoadGate($config));
        // The signal is this provider's only function — always on.
        self::assertTrue($this->provider->wantsConsentMode($config));
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function invalidPixelIds(): array
    {
        return [
            'missing'         => [null],
            'integer'         => [1234567890123],
            'too short'       => ['1234'],
            'too long'        => ['12345678901234567890'],
            'non-digits'      => ['ABCDEF1234567'],
            'with spaces'     => [' 1234567890123 '],
            'with separators' => ['12-34-567-890-123'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPixelIds')]
    public function rejectsInvalidPixelIds(mixed $pixelId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pixelId/');
        $config = $pixelId === null ? [] : ['pixelId' => $pixelId];
        $this->provider->buildServiceData($config);
    }

    #[Test]
    public function descriptionMentionsPixelId(): void
    {
        $data = $this->provider->buildServiceData(['pixelId' => '9876543210987']);
        self::assertStringContainsString('9876543210987', $data['description']);
    }
}
