<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ActiveSettingsRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver;
use SimpleCMP\T3SimpleCmp\Service\SettingsDriftEntry;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Phase-5 resolver behaviour lock. Covers:
 *   - editor-vs-ops key routing
 *   - drift state derivation (bootstrap-pending / in-sync / drift)
 *   - adopt / adoptAll / setCustom / resetToYaml
 *   - tracker-proposal generation
 *   - per-request cache memoisation
 */
final class EffectiveSettingsResolverTest extends TestCase
{
    private const string SITE = 'default';

    #[Test]
    public function returnsYamlForEditorKeyWhenNoActiveRow(): void
    {
        $resolver = $this->makeResolver(
            yaml: ['simplecmp.privacyPolicyUrl' => 'https://yaml.example.com/p'],
            active: null,
        );
        self::assertSame(
            'https://yaml.example.com/p',
            $resolver->get(self::SITE, 'simplecmp.privacyPolicyUrl'),
        );
    }

    #[Test]
    public function returnsActiveOverYamlForEditorKey(): void
    {
        $resolver = $this->makeResolver(
            yaml: ['simplecmp.privacyPolicyUrl' => 'https://yaml.example.com/p'],
            active: ['simplecmp.privacyPolicyUrl' => 'https://live.example.com/p'],
        );
        self::assertSame(
            'https://live.example.com/p',
            $resolver->get(self::SITE, 'simplecmp.privacyPolicyUrl'),
        );
    }

    #[Test]
    public function opsKeysAlwaysReadYamlEvenIfActiveHasValue(): void
    {
        // bridgeRateLimit is intentionally ops — even if someone slipped
        // it into active_settings, the resolver returns YAML.
        $resolver = $this->makeResolver(
            yaml: ['simplecmp.bridgeRateLimit' => 500],
            active: ['simplecmp.bridgeRateLimit' => 9999],
        );
        self::assertSame(500, $resolver->get(self::SITE, 'simplecmp.bridgeRateLimit'));
    }

    #[Test]
    public function fallsBackToDefaultWhenNeitherActiveNorYamlSet(): void
    {
        $resolver = $this->makeResolver(yaml: [], active: null);
        self::assertSame('fallback', $resolver->get(self::SITE, 'simplecmp.imprintUrl', 'fallback'));
    }

    #[Test]
    public function driftReportsBootstrapPendingWhenNoActiveRow(): void
    {
        $resolver = $this->makeResolver(
            yaml: ['simplecmp.privacyPolicyUrl' => 'X'],
            active: null,
        );
        $drift = $resolver->drift(self::SITE);
        self::assertNotEmpty($drift);
        foreach ($drift as $entry) {
            self::assertSame(SettingsDriftEntry::STATE_BOOTSTRAP_PENDING, $entry->state);
        }
    }

    #[Test]
    public function driftReportsInSyncForAdoptedKey(): void
    {
        $resolver = $this->makeResolver(
            yaml: ['simplecmp.privacyPolicyUrl' => 'X'],
            active: ['simplecmp.privacyPolicyUrl' => 'X'],
        );
        $entry = $this->driftEntryFor($resolver->drift(self::SITE), 'simplecmp.privacyPolicyUrl');
        self::assertSame(SettingsDriftEntry::STATE_IN_SYNC, $entry->state);
    }

    #[Test]
    public function driftReportsDriftWhenActiveDiffersFromYaml(): void
    {
        $resolver = $this->makeResolver(
            yaml: ['simplecmp.privacyPolicyUrl' => 'NEU'],
            active: ['simplecmp.privacyPolicyUrl' => 'ALT'],
        );
        $entry = $this->driftEntryFor($resolver->drift(self::SITE), 'simplecmp.privacyPolicyUrl');
        self::assertSame(SettingsDriftEntry::STATE_DRIFT_YAML_NEWER, $entry->state);
        self::assertTrue($entry->needsAction());
    }

    #[Test]
    public function countActionableDriftSumsBootstrapAndDrift(): void
    {
        $resolver = $this->makeResolver(
            yaml: ['simplecmp.privacyPolicyUrl' => 'A'],
            active: null,
        );
        // 12 editor-content keys; all reported as bootstrap-pending → all actionable.
        self::assertSame(count(EffectiveSettingsResolver::EDITOR_CONTENT_KEYS), $resolver->countActionableDrift(self::SITE));
    }

    #[Test]
    public function adoptKeyRefusesNonEditorKey(): void
    {
        $resolver = $this->makeResolver(yaml: [], active: null);
        $this->expectException(\InvalidArgumentException::class);
        $resolver->adoptKey(self::SITE, 'simplecmp.bridgeRateLimit', 1);
    }

    #[Test]
    public function adoptKeyWritesYamlValueToActive(): void
    {
        $activeRepo = $this->createMock(ActiveSettingsRepository::class);
        $activeRepo->method('findBySite')->willReturn(null);
        $activeRepo->expects(self::once())
            ->method('upsertKey')
            ->with(self::SITE, 'simplecmp.privacyPolicyUrl', 'X', 42);

        $resolver = $this->makeResolverWithActiveRepo(
            yaml: ['simplecmp.privacyPolicyUrl' => 'X'],
            activeRepo: $activeRepo,
        );
        $resolver->adoptKey(self::SITE, 'simplecmp.privacyPolicyUrl', 42);
    }

    #[Test]
    public function setCustomRefusesNonEditorKey(): void
    {
        $resolver = $this->makeResolver(yaml: [], active: null);
        $this->expectException(\InvalidArgumentException::class);
        $resolver->setCustom(self::SITE, 'simplecmp.bridgeRateLimit', 1000, 1);
    }

    #[Test]
    public function setCustomWritesGivenValueToActive(): void
    {
        $activeRepo = $this->createMock(ActiveSettingsRepository::class);
        $activeRepo->method('findBySite')->willReturn([]);
        $activeRepo->expects(self::once())
            ->method('upsertKey')
            ->with(self::SITE, 'simplecmp.privacyPolicyUrl', 'CUSTOM', 7);

        $resolver = $this->makeResolverWithActiveRepo(
            yaml: ['simplecmp.privacyPolicyUrl' => 'X'],
            activeRepo: $activeRepo,
        );
        $resolver->setCustom(self::SITE, 'simplecmp.privacyPolicyUrl', 'CUSTOM', 7);
    }

    #[Test]
    public function trackerProposalsListYamlEntriesUnadoptedFirst(): void
    {
        $tracker = $this->createMock(ManagedTrackerRepository::class);
        $tracker->method('findBySite')->willReturn([]);
        $tracker->method('findBySiteDraft')->willReturn([]);

        $resolver = $this->makeResolverWithRepos(
            yaml: [],
            trackerYaml: [
                ['type' => 'matomo', 'serviceId' => 'matomo', 'url' => 'https://m.example/', 'siteId' => '99'],
            ],
            activeRepo: null,
            trackerRepo: $tracker,
        );
        $proposals = $resolver->trackerProposals(self::SITE);
        self::assertCount(1, $proposals);
        self::assertSame('matomo', $proposals[0]->type);
        self::assertSame('matomo', $proposals[0]->serviceId);
        self::assertFalse($proposals[0]->alreadyAdopted);
        self::assertSame('https://m.example/', $proposals[0]->config['url']);
    }

    #[Test]
    public function trackerProposalsMarkAlreadyAdoptedWhenManagedTrackerExists(): void
    {
        $tracker = $this->createMock(ManagedTrackerRepository::class);
        $tracker->method('findBySite')->willReturn([
            ['uid' => 1, 'tracker_type' => 'matomo', 'service_id' => 'matomo'],
        ]);
        $tracker->method('findBySiteDraft')->willReturn([]);

        $resolver = $this->makeResolverWithRepos(
            yaml: [],
            trackerYaml: [
                ['type' => 'matomo', 'serviceId' => 'matomo'],
            ],
            activeRepo: null,
            trackerRepo: $tracker,
        );
        $proposals = $resolver->trackerProposals(self::SITE);
        self::assertTrue($proposals[0]->alreadyAdopted);
        self::assertSame(1, $proposals[0]->existingLiveUid);
    }

    #[Test]
    public function activeSnapshotEmptyWhenNotBootstrapped(): void
    {
        $resolver = $this->makeResolver(yaml: ['simplecmp.privacyPolicyUrl' => 'X'], active: null);
        self::assertSame([], $resolver->activeSnapshot(self::SITE));
    }

    #[Test]
    public function activeSnapshotMixesActiveOverYaml(): void
    {
        $resolver = $this->makeResolver(
            yaml: [
                'simplecmp.privacyPolicyUrl' => 'YAML',
                'simplecmp.imprintUrl' => 'IMPR',
            ],
            active: [
                'simplecmp.privacyPolicyUrl' => 'LIVE',
            ],
        );
        $snap = $resolver->activeSnapshot(self::SITE);
        self::assertSame('LIVE', $snap['simplecmp.privacyPolicyUrl']);
        self::assertSame('IMPR', $snap['simplecmp.imprintUrl']);
    }

    #[Test]
    public function getInternalReturnsNullWhenNotSet(): void
    {
        $resolver = $this->makeResolver(yaml: [], active: null);
        self::assertNull($resolver->getInternal(self::SITE, EffectiveSettingsResolver::INTERNAL_KEYS[0]));
    }

    #[Test]
    public function getInternalReturnsValueWhenStored(): void
    {
        $resolver = $this->makeResolver(
            yaml: [],
            active: ['simplecmp.internal.wizardCompletedAt' => 1734567890],
        );
        self::assertSame(
            1734567890,
            $resolver->getInternal(self::SITE, 'simplecmp.internal.wizardCompletedAt'),
        );
    }

    #[Test]
    public function getInternalRefusesUnknownKey(): void
    {
        $resolver = $this->makeResolver(yaml: [], active: null);
        $this->expectException(\InvalidArgumentException::class);
        $resolver->getInternal(self::SITE, 'simplecmp.bridgeRateLimit');
    }

    #[Test]
    public function setInternalWritesValueViaUpsert(): void
    {
        $activeRepo = $this->createMock(ActiveSettingsRepository::class);
        $activeRepo->method('findBySite')->willReturn(null);
        $activeRepo->expects(self::once())
            ->method('upsertKey')
            ->with(self::SITE, 'simplecmp.internal.wizardCompletedAt', 1700000000, 7);

        $resolver = $this->makeResolverWithActiveRepo(yaml: [], activeRepo: $activeRepo);
        $resolver->setInternal(self::SITE, 'simplecmp.internal.wizardCompletedAt', 1700000000, 7);
    }

    #[Test]
    public function setInternalRefusesUnknownKey(): void
    {
        $resolver = $this->makeResolver(yaml: [], active: null);
        $this->expectException(\InvalidArgumentException::class);
        $resolver->setInternal(self::SITE, 'simplecmp.privacyPolicyUrl', 'x', 1);
    }

    #[Test]
    public function deleteInternalCallsRepoDeleteKey(): void
    {
        $activeRepo = $this->createMock(ActiveSettingsRepository::class);
        $activeRepo->method('findBySite')->willReturn(null);
        $activeRepo->expects(self::once())
            ->method('deleteKey')
            ->with(self::SITE, 'simplecmp.internal.wizardSkippedAt', 3);

        $resolver = $this->makeResolverWithActiveRepo(yaml: [], activeRepo: $activeRepo);
        $resolver->deleteInternal(self::SITE, 'simplecmp.internal.wizardSkippedAt', 3);
    }

    #[Test]
    public function driftDoesNotIncludeInternalKeys(): void
    {
        $resolver = $this->makeResolver(
            yaml: ['simplecmp.privacyPolicyUrl' => 'X'],
            active: [
                'simplecmp.privacyPolicyUrl' => 'X',
                'simplecmp.internal.wizardCompletedAt' => 1700000000,
            ],
        );
        foreach ($resolver->drift(self::SITE) as $entry) {
            self::assertStringStartsNotWith('simplecmp.internal.', $entry->key);
        }
    }

    #[Test]
    public function activeSnapshotDoesNotIncludeInternalKeys(): void
    {
        $resolver = $this->makeResolver(
            yaml: ['simplecmp.privacyPolicyUrl' => 'X'],
            active: [
                'simplecmp.privacyPolicyUrl' => 'X',
                'simplecmp.internal.wizardCompletedAt' => 1700000000,
            ],
        );
        $snap = $resolver->activeSnapshot(self::SITE);
        self::assertArrayNotHasKey('simplecmp.internal.wizardCompletedAt', $snap);
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * @param list<SettingsDriftEntry> $drift
     */
    private function driftEntryFor(array $drift, string $key): SettingsDriftEntry
    {
        foreach ($drift as $entry) {
            if ($entry->key === $key) {
                return $entry;
            }
        }
        self::fail('Drift entry not found for key ' . $key);
    }

    /**
     * @param array<string, mixed> $yaml
     * @param array<string, mixed>|null $active
     */
    private function makeResolver(array $yaml, ?array $active): EffectiveSettingsResolver
    {
        $activeRepo = $this->createMock(ActiveSettingsRepository::class);
        $activeRepo->method('findBySite')->willReturn($active);
        return $this->makeResolverWithActiveRepo($yaml, $activeRepo);
    }

    /**
     * @param array<string, mixed> $yaml
     */
    private function makeResolverWithActiveRepo(
        array $yaml,
        ActiveSettingsRepository $activeRepo,
    ): EffectiveSettingsResolver {
        return $this->makeResolverWithRepos(
            yaml: $yaml,
            trackerYaml: [],
            activeRepo: $activeRepo,
            trackerRepo: $this->createMock(ManagedTrackerRepository::class),
        );
    }

    /**
     * @param array<string, mixed> $yaml
     * @param list<array<string, mixed>> $trackerYaml
     */
    private function makeResolverWithRepos(
        array $yaml,
        array $trackerYaml,
        ?ActiveSettingsRepository $activeRepo,
        ManagedTrackerRepository $trackerRepo,
    ): EffectiveSettingsResolver {
        // Merge tracker entries into dot-flat identifiers + values so
        // the resolver's SiteSettings flatten path works.
        $allValues = $yaml;
        $identifiers = array_keys($yaml);
        foreach ($trackerYaml as $i => $entry) {
            foreach ($entry as $k => $v) {
                $key = 'simplecmp.trackers.' . $i . '.' . $k;
                $allValues[$key] = $v;
                $identifiers[] = $key;
            }
        }
        $settings = $this->createMock(SiteSettings::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $k) => $allValues[$k] ?? null
        );
        $settings->method('getIdentifiers')->willReturn($identifiers);
        $site = $this->createMock(Site::class);
        $site->method('getSettings')->willReturn($settings);
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getSiteByIdentifier')->willReturn($site);

        return new EffectiveSettingsResolver(
            $activeRepo ?? $this->createMock(ActiveSettingsRepository::class),
            $trackerRepo,
            $finder,
        );
    }
}
