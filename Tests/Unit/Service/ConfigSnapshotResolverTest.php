<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\TranslationOverrideRepository;
use SimpleCMP\T3SimpleCmp\Service\ConfigSnapshotResolver;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Lock the shape of the resolver's output: which fields are included,
 * which Site Settings keys are pulled, and that unknown sites
 * short-circuit cleanly.
 */
final class ConfigSnapshotResolverTest extends TestCase
{
    #[Test]
    public function returnsNullForUnknownSite(): void
    {
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getSiteByIdentifier')
            ->willThrowException(new SiteNotFoundException('not found'));

        $resolver = new ConfigSnapshotResolver(
            $this->createMock(ServiceRepository::class),
            $this->createMock(ThemeRepository::class),
            $this->createMock(TranslationOverrideRepository::class),
            $this->createMock(\SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository::class),
            $this->createMock(\SimpleCMP\T3SimpleCmp\Domain\Repository\AllowedStylesheetHostRepository::class),
            $finder,
            new NullLogger(),
        );

        self::assertNull($resolver->resolveCurrentSnapshot('ghost-site'));
    }

    #[Test]
    public function returnsExpectedTopLevelKeys(): void
    {
        $resolver = $this->makeResolver(services: [], theme: null, overrides: null, settingsMap: []);
        $snapshot = $resolver->resolveCurrentSnapshot('default');
        self::assertIsArray($snapshot);
        self::assertSame(
            ['services', 'theme', 'translations', 'managedTrackers', 'allowedStylesheetHosts', 'settings', 'schemaVersion'],
            array_keys($snapshot),
        );
        self::assertSame(2, $snapshot['schemaVersion']);
    }

    #[Test]
    public function nullThemeBecomesEmptyArray(): void
    {
        $snapshot = $this->makeResolver(theme: null)->resolveCurrentSnapshot('default');
        self::assertSame([], $snapshot['theme']);
    }

    #[Test]
    public function nullOverridesBecomeEmptyArray(): void
    {
        $snapshot = $this->makeResolver(overrides: null)->resolveCurrentSnapshot('default');
        self::assertSame([], $snapshot['translations']);
    }

    #[Test]
    public function snapshotsCuratedSiteSettingsOnly(): void
    {
        $resolver = $this->makeResolver(settingsMap: [
            // Curated → included
            'simplecmp.enabled' => true,
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            'simplecmp.regimeDefault' => 'opt-in',
            // Not curated (ops-tuning) → excluded
            'simplecmp.bridgeRateLimit' => 500,
            'simplecmp.regionHeader' => 'CF-IPCountry',
            'simplecmp.useSlimBundle' => true,
        ]);
        $snapshot = $resolver->resolveCurrentSnapshot('default');
        $keys = array_keys($snapshot['settings']);
        self::assertContains('simplecmp.privacyPolicyUrl', $keys);
        self::assertContains('simplecmp.regimeDefault', $keys);
        self::assertContains('simplecmp.enabled', $keys);
        self::assertNotContains('simplecmp.bridgeRateLimit', $keys);
        self::assertNotContains('simplecmp.regionHeader', $keys);
        self::assertNotContains('simplecmp.useSlimBundle', $keys);
    }

    #[Test]
    public function missingSiteSettingsAreOmitted(): void
    {
        $snapshot = $this->makeResolver(settingsMap: [
            'simplecmp.privacyPolicyUrl' => 'https://example.com/privacy',
            // imprintUrl deliberately absent — settings.get() returns null
        ])->resolveCurrentSnapshot('default');
        self::assertArrayHasKey('simplecmp.privacyPolicyUrl', $snapshot['settings']);
        self::assertArrayNotHasKey('simplecmp.imprintUrl', $snapshot['settings']);
    }

    #[Test]
    public function allowlistSettingDefaultsToEmptyListInsteadOfBeingOmitted(): void
    {
        // The stringlist Setting is always shape-stable — null/missing
        // becomes [], so a snapshot doesn't flip-flop between
        // "key absent" and "key present empty" between editor saves.
        $snapshot = $this->makeResolver(settingsMap: [])->resolveCurrentSnapshot('default');
        self::assertArrayHasKey('simplecmp.universalBlocking.allowlist', $snapshot['settings']);
        self::assertSame([], $snapshot['settings']['simplecmp.universalBlocking.allowlist']);
    }

    #[Test]
    public function reconstructsFlattenedTrackersArray(): void
    {
        // TYPO3 v14 flattens undefined nested site-settings to dot
        // keys — `simplecmp.trackers` is exactly that case.
        $snapshot = $this->makeResolver(
            settingsMap: [
                'simplecmp.trackers.0.type' => 'matomo',
                'simplecmp.trackers.0.url' => 'https://matomo.example/',
                'simplecmp.trackers.0.siteId' => '99',
                'simplecmp.trackers.1.type' => 'ga4',
                'simplecmp.trackers.1.measurementId' => 'G-ABC',
            ],
            identifiers: [
                'simplecmp.trackers.0.type',
                'simplecmp.trackers.0.url',
                'simplecmp.trackers.0.siteId',
                'simplecmp.trackers.1.type',
                'simplecmp.trackers.1.measurementId',
            ],
        )->resolveCurrentSnapshot('default');

        self::assertArrayHasKey('simplecmp.trackers', $snapshot['settings']);
        $trackers = $snapshot['settings']['simplecmp.trackers'];
        self::assertCount(2, $trackers);
        self::assertSame('matomo', $trackers[0]['type']);
        self::assertSame('https://matomo.example/', $trackers[0]['url']);
        self::assertSame('ga4', $trackers[1]['type']);
        self::assertSame('G-ABC', $trackers[1]['measurementId']);
    }

    /**
     * @param list<array<string, mixed>> $services
     * @param array<string, mixed>|null $theme
     * @param array<string, mixed>|null $overrides
     * @param array<string, mixed> $settingsMap
     * @param list<string> $identifiers
     */
    private function makeResolver(
        array $services = [],
        ?array $theme = null,
        ?array $overrides = null,
        array $settingsMap = [],
        array $identifiers = [],
    ): ConfigSnapshotResolver {
        $serviceRepo = $this->createMock(ServiceRepository::class);
        $serviceRepo->method('findAll')->willReturn($services);
        $themeRepo = $this->createMock(ThemeRepository::class);
        $themeRepo->method('findBySite')->willReturn($theme);
        $overrideRepo = $this->createMock(TranslationOverrideRepository::class);
        $overrideRepo->method('findBySite')->willReturn($overrides);

        $settings = $this->createMock(SiteSettings::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key) => $settingsMap[$key] ?? null
        );
        $settings->method('getIdentifiers')->willReturn($identifiers);
        $site = $this->createMock(Site::class);
        $site->method('getSettings')->willReturn($settings);

        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getSiteByIdentifier')->willReturn($site);

        $managedTrackerRepo = $this->createMock(\SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository::class);
        $managedTrackerRepo->method('findBySite')->willReturn([]);
        $allowedHostsRepo = $this->createMock(\SimpleCMP\T3SimpleCmp\Domain\Repository\AllowedStylesheetHostRepository::class);
        $allowedHostsRepo->method('hostsForSource')->willReturn([]);

        return new ConfigSnapshotResolver(
            $serviceRepo,
            $themeRepo,
            $overrideRepo,
            $managedTrackerRepo,
            $allowedHostsRepo,
            $finder,
            new NullLogger(),
        );
    }
}
