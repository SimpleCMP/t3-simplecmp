<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Log\LoggerInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\TranslationOverrideRepository;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * Builds the full "active banner config" for a site at this moment in
 * time — the input to the audit snapshot.
 *
 * Three DB sources + one curated subset of Site Settings:
 *
 *   - Services: global registry ({@see ServiceRepository::findAll()})
 *     in the protocol shape. **All sites see the same set**, but each
 *     snapshot still records it for that site because the visitor's
 *     consent decision is per-site.
 *   - Theme: per-site tokens ({@see ThemeRepository::findBySite()}).
 *   - Translation overrides + tone selection
 *     ({@see TranslationOverrideRepository::findBySite()}).
 *   - Site Settings (only banner-content-relevant keys — see
 *     {@see SNAPSHOTTED_SETTINGS}). Per-request / admin-tuning keys
 *     like `regionHeader` or `bridgeRateLimit` are deliberately
 *     excluded; including them would create a snapshot every time
 *     ops bump a rate-limit value without anything banner-visible
 *     having changed.
 *
 * The output is a plain array structured for
 * {@see CanonicalJsonEncoder::encode()} to hash. Order of keys at
 * the top level is fixed (alphabetical) so the encoder doesn't have
 * to deal with our internal ordering.
 */
final readonly class ConfigSnapshotResolver
{
    /**
     * Banner-content-relevant `simplecmp.*` settings. Any setting
     * NOT in this list is treated as per-request / ops-tuning and
     * left out of the snapshot.
     *
     * `simplecmp.universalBlocking.allowlist` is a `stringlist` site-
     * setting type — the resolver normalises null/missing into [].
     * `simplecmp.trackers` is the YAML array of provisioned trackers;
     * we re-collect it via the same dot-key prefix scan
     * {@see \SimpleCMP\T3SimpleCmp\EventListener\TrackerMaterializer}
     * uses, because TYPO3 v14 flattens undefined nested settings to
     * dot-keys.
     *
     * @var list<string>
     */
    private const array SNAPSHOTTED_SETTINGS = [
        'simplecmp.enabled',
        'simplecmp.storageName',
        'simplecmp.privacyPolicyUrl',
        'simplecmp.imprintUrl',
        'simplecmp.floatingTriggerLabel',
        'simplecmp.respectGPC',
        'simplecmp.regimeDefault',
        'simplecmp.universalBlocking.enabled',
        'simplecmp.universalBlocking.blockStylesheets',
        'simplecmp.universalBlocking.allowlist',
        'simplecmp.libraryUpstreamUrl',
    ];

    /**
     * Prefix used to reconstruct the YAML `simplecmp.trackers` array
     * (TYPO3 v14 flattens undefined nested settings to dot keys).
     */
    private const string TRACKERS_KEY_PREFIX = 'simplecmp.trackers.';

    public function __construct(
        private ServiceRepository $serviceRepository,
        private ThemeRepository $themeRepository,
        private TranslationOverrideRepository $overrideRepository,
        private SiteFinder $siteFinder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Build the full snapshot for a site. Returns null if the site
     * identifier is unknown — caller should skip silently (a
     * snapshot-on-save for a deleted site identifier is a no-op).
     *
     * @return array<string, mixed>|null
     */
    public function resolveCurrentSnapshot(string $siteIdentifier): ?array
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\TYPO3\CMS\Core\Exception\SiteNotFoundException) {
            $this->logger->info(
                'ConfigSnapshotResolver: skipping snapshot for unknown site "{site}"',
                ['site' => $siteIdentifier],
            );
            return null;
        }

        return [
            'services' => $this->serviceRepository->findAll(),
            'theme' => $this->themeRepository->findBySite($siteIdentifier) ?? [],
            'translations' => $this->overrideRepository->findBySite($siteIdentifier) ?? [],
            'settings' => $this->collectSettings($site),
            // `schemaVersion` lets future readers spot when the snapshot
            // shape itself changed (e.g. when Phase 2 adds new
            // consent-log-relevant fields). Bump on shape changes.
            'schemaVersion' => 1,
        ];
    }

    /**
     * Collect the curated set of Site Settings + the YAML-defined
     * `simplecmp.trackers` array.
     *
     * @return array<string, mixed>
     */
    private function collectSettings(Site $site): array
    {
        $settings = $site->getSettings();
        $out = [];
        foreach (self::SNAPSHOTTED_SETTINGS as $key) {
            $value = $settings->get($key);
            // Skip undefined keys (TYPO3 returns null for missing or
            // explicit-null values). Stringlist allowlist is normalised
            // to an empty list, not null, so it's snapshot-stable.
            if ($value === null && $key !== 'simplecmp.universalBlocking.allowlist') {
                continue;
            }
            $out[$key] = $value ?? [];
        }
        $trackers = $this->collectTrackerEntries($settings);
        if ($trackers !== []) {
            $out['simplecmp.trackers'] = $trackers;
        }
        return $out;
    }

    /**
     * Reconstruct `simplecmp.trackers` from the flat dot-key
     * representation TYPO3 v14 uses for undefined nested settings.
     * Mirrors the trick in
     * {@see \SimpleCMP\T3SimpleCmp\EventListener\TrackerMaterializer::collectTrackerEntries()}.
     *
     * @return list<array<string, mixed>>
     */
    private function collectTrackerEntries(object $settings): array
    {
        $prefix = self::TRACKERS_KEY_PREFIX;
        $prefixLen = strlen($prefix);
        $flat = [];
        foreach ($settings->getIdentifiers() as $identifier) {
            if (!str_starts_with($identifier, $prefix)) {
                continue;
            }
            $relative = substr($identifier, $prefixLen);
            $flat[$relative] = $settings->get($identifier);
        }
        if ($flat === []) {
            return [];
        }
        try {
            $unflat = ArrayUtility::unflatten($flat);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($unflat)) {
            return [];
        }
        // unflatten produces an associative array keyed `0`, `1`, … —
        // numerically sort and reindex so the snapshot is stable.
        $byIndex = [];
        foreach ($unflat as $index => $value) {
            if (is_array($value)) {
                $byIndex[(int) $index] = $value;
            }
        }
        ksort($byIndex);
        return array_values($byIndex);
    }
}
