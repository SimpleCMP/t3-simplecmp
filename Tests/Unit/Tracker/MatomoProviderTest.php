<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Tracker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Tracker\MatomoProvider;

final class MatomoProviderTest extends TestCase
{
    private MatomoProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MatomoProvider();
    }

    #[Test]
    public function validHttpsUrlProducesLoaderAndOriginMatcher(): void
    {
        $config = ['url' => 'https://matomo.example.com/', 'siteId' => 3];
        self::assertSame('https://matomo.example.com/matomo.js', $this->provider->getLoaderUrl($config));
        $data = $this->provider->buildServiceData($config);
        self::assertSame(['matomo.example.com'], $data['matches']['origins']);
    }

    /**
     * The `url` becomes the /matomo.js <script src> + matcher origin, so a
     * non-http(s) / hostless value must be rejected at config time.
     *
     * @return list<array{string}>
     */
    public static function invalidUrls(): array
    {
        return [
            ['javascript:alert(document.cookie)'],
            ['data:text/html,<script>alert(1)</script>'],
            ['ftp://matomo.example.com/'],
            ['matomo.example.com'], // no scheme
            ['https:///matomo.js'],  // no host
        ];
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function rejectsNonHttpUrlInGetLoaderUrl(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->provider->getLoaderUrl(['url' => $url, 'siteId' => 1]);
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function rejectsNonHttpUrlInBuildServiceData(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->provider->buildServiceData(['url' => $url, 'siteId' => 1]);
    }

    #[Test]
    public function alwaysLoadGatedAndNeverRequestsConsentMode(): void
    {
        // Matomo doesn't speak Consent Mode v2. There's no signal-gate
        // analogue here — the load gate is the only consent enforcement
        // available. A passing config (even one with an `consentPosture`
        // key set to `signal-gate`) must not change either flag.
        $configs = [
            ['url' => 'https://matomo.example.com/', 'siteId' => 1],
            ['url' => 'https://matomo.example.com/', 'siteId' => 1, 'consentPosture' => 'signal-gate'],
        ];
        foreach ($configs as $config) {
            self::assertTrue($this->provider->wantsLoadGate($config));
            self::assertFalse($this->provider->wantsConsentMode($config));
        }
    }
}
