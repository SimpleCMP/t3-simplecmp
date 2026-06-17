<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Log\LoggerInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\AllowedStylesheetHostRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\TranslationOverrideRepository;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Builds the full "active banner config" for a site at this moment in
 * time — the input to the audit snapshot.
 *
 * Source set (Phase 4, schemaVersion 3) — the five **DB-editable**
 * banner-config tables only:
 *
 *   - Services: global registry ({@see ServiceRepository::findAll()})
 *     in the protocol shape. **All sites see the same set**, but each
 *     snapshot still records it for that site because the visitor's
 *     consent decision is per-site.
 *   - Theme: per-site tokens ({@see ThemeRepository::findBySite()}).
 *   - Translation overrides + tone selection
 *     ({@see TranslationOverrideRepository::findBySite()}).
 *   - Managed trackers per site
 *     ({@see ManagedTrackerRepository::findBySite()}).
 *   - Allowed stylesheet hosts for the site's bridge source
 *     ({@see AllowedStylesheetHostRepository::hostsForSource()}).
 *
 * **YAML-Site-Settings (incl. the `simplecmp.trackers` array) are
 * deliberately NOT in the snapshot.** Site-Settings live in
 * `config/sites/<id>/settings.yaml` — they belong to the Git-versioned
 * deployment, not the BE-editor-controlled Publish workflow. Including
 * them would mean a snapshot whose hash could change on deploy without
 * any editor having clicked Veröffentlichen, blurring the line between
 * "audit of editor publications" and "deployment diff". Use `git log`
 * for YAML-state history.
 *
 * The output is a plain array structured for
 * {@see CanonicalJsonEncoder::encode()} to hash. Order of keys at
 * the top level is fixed (alphabetical) so the encoder doesn't have
 * to deal with our internal ordering.
 */
final readonly class ConfigSnapshotResolver
{
    public function __construct(
        private ServiceRepository $serviceRepository,
        private ThemeRepository $themeRepository,
        private TranslationOverrideRepository $overrideRepository,
        private ManagedTrackerRepository $managedTrackerRepository,
        private AllowedStylesheetHostRepository $allowedStylesheetHostRepository,
        private SiteFinder $siteFinder,
        private LoggerInterface $logger,
        private EffectiveSettingsResolver $effectiveSettings,
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
            $this->siteFinder->getSiteByIdentifier($siteIdentifier);
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
            'managedTrackers' => $this->managedTrackerRepository->findBySite($siteIdentifier),
            'allowedStylesheetHosts' => $this->allowedStylesheetHostRepository->hostsForSource(
                'simplecmp-' . $siteIdentifier,
            ),
            // Phase-5: editor-confirmed active banner-content settings.
            // Snapshot is the canonical record of what was effective at
            // publish-time; the Phase-5 resolver derives this from the
            // active_settings table merged with YAML defaults for
            // editor-content keys only. Ops keys remain off-snapshot.
            'activeSettings' => $this->effectiveSettings->activeSnapshot($siteIdentifier),
            'schemaVersion' => 4,
        ];
    }
}
