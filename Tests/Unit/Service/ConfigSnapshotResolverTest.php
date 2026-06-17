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
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Phase-4 schema (v3): the resolver outputs only the five DB-editable
 * banner-config tables. YAML Site-Settings (incl. simplecmp.trackers)
 * are intentionally OUT — they live in `config/sites/<id>/settings.yaml`
 * under Git versioning and don't belong in editor-publication audit.
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
        $snapshot = $this->makeResolver()->resolveCurrentSnapshot('default');
        self::assertIsArray($snapshot);
        self::assertSame(
            ['services', 'theme', 'translations', 'managedTrackers', 'allowedStylesheetHosts', 'schemaVersion'],
            array_keys($snapshot),
        );
        self::assertSame(3, $snapshot['schemaVersion']);
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
    public function snapshotsDoNotIncludeYamlSiteSettings(): void
    {
        // Even if site settings are set, the snapshot must not carry
        // them — they're Git-versioned, not editor-versioned.
        $snapshot = $this->makeResolver()->resolveCurrentSnapshot('default');
        self::assertArrayNotHasKey('settings', $snapshot);
    }

    /**
     * @param list<array<string, mixed>> $services
     * @param array<string, mixed>|null $theme
     * @param array<string, mixed>|null $overrides
     */
    private function makeResolver(
        array $services = [],
        ?array $theme = null,
        ?array $overrides = null,
    ): ConfigSnapshotResolver {
        $serviceRepo = $this->createMock(ServiceRepository::class);
        $serviceRepo->method('findAll')->willReturn($services);
        $themeRepo = $this->createMock(ThemeRepository::class);
        $themeRepo->method('findBySite')->willReturn($theme);
        $overrideRepo = $this->createMock(TranslationOverrideRepository::class);
        $overrideRepo->method('findBySite')->willReturn($overrides);

        $site = $this->createMock(Site::class);
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
