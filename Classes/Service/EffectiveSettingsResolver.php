<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use SimpleCMP\T3SimpleCmp\Domain\Repository\ActiveSettingsRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * Phase-5 settings resolver — merges editor-confirmed active settings
 * (DB) over YAML defaults.
 *
 * The split between editor-vs-ops keys is the heart of Phase 5:
 *
 *  - **Editor-content keys** (DSGVO-relevant, visible to visitors)
 *    flow through the proposal/adopt workflow. The resolver returns
 *    the DB-active value when one exists, otherwise the YAML value.
 *    Editor changes via the BE Settings tab; deploys land as
 *    proposed changes the editor must confirm.
 *  - **Ops keys** (server-side wiring / rate-limits / URL-config)
 *    are read straight from YAML. No DB layer, no drift detection.
 *    Same behaviour as before Phase 5.
 *
 * Per-request memoisation: the first `get($site, ...)` call loads the
 * active_json blob + every `simplecmp.*` YAML key once into a cache.
 * Subsequent reads are array lookups — same cost as TYPO3's native
 * `SiteSettings::get()`, which keeps the FE init path fast.
 */
final class EffectiveSettingsResolver
{
    /**
     * Keys that go through the editor adoption workflow. Banner-content
     * decisions visible to visitors live here.
     *
     * @var list<string>
     */
    public const array EDITOR_CONTENT_KEYS = [
        'simplecmp.enabled',
        'simplecmp.storageName',
        'simplecmp.privacyPolicyUrl',
        'simplecmp.imprintUrl',
        'simplecmp.floatingTriggerLabel',
        'simplecmp.respectGPC',
        'simplecmp.regimeDefault',
        'simplecmp.hideDeclineAll',
        'simplecmp.universalBlocking.enabled',
        'simplecmp.universalBlocking.blockStylesheets',
        'simplecmp.universalBlocking.allowlist',
        // simplecmp.trackers is editor-content but handled separately
        // via trackerProposals() — it's an array, not a scalar.
    ];

    /**
     * Internal state keys — stored in the same active_settings row but
     * intentionally outside EDITOR_CONTENT_KEYS so they never appear
     * in drift() / activeSnapshot() or the BE Settings tab.
     *
     * @var list<string>
     */
    public const array INTERNAL_KEYS = [
        'simplecmp.internal.wizardCompletedAt',
        'simplecmp.internal.wizardSkippedAt',
    ];

    private const string TRACKERS_KEY_PREFIX = 'simplecmp.trackers.';

    /**
     * Per-site cache: ['active' => array, 'yaml' => array].
     *
     * @var array<string, array{active: array<string, mixed>, yaml: array<string, mixed>, hasActiveRow: bool}>
     */
    private array $cache = [];

    public function __construct(
        private readonly ActiveSettingsRepository $activeSettings,
        private readonly ManagedTrackerRepository $managedTrackers,
        private readonly SiteFinder $siteFinder,
    ) {
    }

    /**
     * Resolve an effective setting value. For editor-content keys
     * the active-DB value (if any) wins; otherwise YAML. For ops
     * keys, YAML is always authoritative.
     */
    public function get(string $siteIdentifier, string $key, mixed $default = null): mixed
    {
        // Editor-content keys: cache-backed (active wins, YAML fallback).
        if (in_array($key, self::EDITOR_CONTENT_KEYS, true)) {
            $cached = $this->loadCache($siteIdentifier);
            if (array_key_exists($key, $cached['active'])) {
                return $cached['active'][$key];
            }
            return $cached['yaml'][$key] ?? $default;
        }
        // Ops keys: straight YAML read (no cache, no active overlay).
        return $this->readYamlDirect($siteIdentifier, $key, $default);
    }

    private function readYamlDirect(string $siteIdentifier, string $key, mixed $default): mixed
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\TYPO3\CMS\Core\Exception\SiteNotFoundException) {
            return $default;
        }
        return $site->getSettings()->get($key) ?? $default;
    }

    /**
     * Has the editor bootstrapped settings for this site at all?
     * Used by the BE to render the "Initial-Übernahme bestätigen"
     * banner on first visit.
     */
    public function isBootstrapped(string $siteIdentifier): bool
    {
        return $this->loadCache($siteIdentifier)['hasActiveRow'];
    }

    /**
     * Compute the drift list — one entry per editor-content key.
     * Empty result means no drift (everything in sync).
     *
     * @return list<SettingsDriftEntry>
     */
    public function drift(string $siteIdentifier): array
    {
        $cached = $this->loadCache($siteIdentifier);
        $out = [];
        foreach (self::EDITOR_CONTENT_KEYS as $key) {
            $yaml = $cached['yaml'][$key] ?? null;
            $hasActive = array_key_exists($key, $cached['active']);
            $active = $hasActive ? $cached['active'][$key] : null;

            if (!$cached['hasActiveRow']) {
                $state = SettingsDriftEntry::STATE_BOOTSTRAP_PENDING;
            } elseif (!$hasActive) {
                // Active row exists but this key never adopted — falls
                // through to YAML. Treated as in-sync because the
                // editor never expressed a different opinion.
                $state = SettingsDriftEntry::STATE_IN_SYNC;
            } elseif ($this->valuesEqual($active, $yaml)) {
                $state = SettingsDriftEntry::STATE_IN_SYNC;
            } else {
                // Active differs from YAML. Two cases:
                //   - Editor adopted YAML at some point, dev then changed YAML
                //     → "yaml-newer" (action: re-adopt to pick up the change)
                //   - Editor wrote a custom value → "drift-custom"
                // We can't distinguish reliably without history, so we
                // collapse to "drift-yaml-newer" for now — the user
                // sees both values in the diff regardless.
                $state = SettingsDriftEntry::STATE_DRIFT_YAML_NEWER;
            }
            $out[] = new SettingsDriftEntry($key, $active, $yaml, $state);
        }
        return $out;
    }

    public function countActionableDrift(string $siteIdentifier): int
    {
        $n = 0;
        foreach ($this->drift($siteIdentifier) as $entry) {
            if ($entry->needsAction()) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Yaml-tracker proposals for the BE settings tab.
     *
     * @return list<TrackerProposal>
     */
    public function trackerProposals(string $siteIdentifier): array
    {
        $yamlTrackers = $this->collectYamlTrackers($siteIdentifier);
        if ($yamlTrackers === []) {
            return [];
        }
        $existing = $this->managedTrackers->findBySite($siteIdentifier);
        $existingDraft = method_exists($this->managedTrackers, 'findBySiteDraft')
            ? $this->managedTrackers->findBySiteDraft($siteIdentifier)
            : [];
        $out = [];
        foreach ($yamlTrackers as $entry) {
            $type = (string) ($entry['type'] ?? '');
            $serviceId = (string) ($entry['serviceId'] ?? $entry['service_id'] ?? '');
            if ($type === '') {
                continue;
            }
            $liveMatch = $this->findTracker($existing, $type, $serviceId);
            $draftMatch = $this->findTracker($existingDraft, $type, $serviceId);
            $config = $entry;
            unset($config['type'], $config['serviceId'], $config['service_id']);
            $out[] = new TrackerProposal(
                type: $type,
                serviceId: $serviceId,
                config: $config,
                alreadyAdopted: $liveMatch !== null || $draftMatch !== null,
                existingLiveUid: $liveMatch !== null ? (int) ($liveMatch['uid'] ?? 0) : null,
                existingDraftUid: $draftMatch !== null ? (int) ($draftMatch['uid'] ?? 0) : null,
            );
        }
        return $out;
    }

    // --- Mutation surface -------------------------------------------------

    /**
     * Adopt the current YAML value for a single key into the active
     * row. Call from the BE controller after the editor confirms.
     */
    public function adoptKey(string $siteIdentifier, string $key, int $beUserId): void
    {
        if (!in_array($key, self::EDITOR_CONTENT_KEYS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'adoptKey: "%s" is not in EDITOR_CONTENT_KEYS.',
                $key,
            ));
        }
        $cached = $this->loadCache($siteIdentifier);
        $yaml = $cached['yaml'][$key] ?? null;
        $this->activeSettings->upsertKey($siteIdentifier, $key, $yaml, $beUserId);
        unset($this->cache[$siteIdentifier]);
    }

    /**
     * Adopt every drifting YAML value at once.
     */
    public function adoptAll(string $siteIdentifier, int $beUserId): void
    {
        $cached = $this->loadCache($siteIdentifier);
        $map = $cached['active'];
        foreach (self::EDITOR_CONTENT_KEYS as $key) {
            $map[$key] = $cached['yaml'][$key] ?? null;
        }
        $this->activeSettings->replaceAll($siteIdentifier, $map, $beUserId);
        unset($this->cache[$siteIdentifier]);
    }

    /**
     * Editor sets a value different from YAML.
     */
    public function setCustom(string $siteIdentifier, string $key, mixed $value, int $beUserId): void
    {
        if (!in_array($key, self::EDITOR_CONTENT_KEYS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'setCustom: "%s" is not in EDITOR_CONTENT_KEYS.',
                $key,
            ));
        }
        $this->activeSettings->upsertKey($siteIdentifier, $key, $value, $beUserId);
        unset($this->cache[$siteIdentifier]);
    }

    /**
     * Reset a key — drop the editor's opinion so the resolver falls
     * back to YAML again.
     */
    public function resetToYaml(string $siteIdentifier, string $key, int $beUserId): void
    {
        $this->activeSettings->deleteKey($siteIdentifier, $key, $beUserId);
        unset($this->cache[$siteIdentifier]);
    }

    /**
     * Read an internal-state key (wizard timestamps etc.). Returns null
     * when no active row exists or the key was never set.
     */
    public function getInternal(string $siteIdentifier, string $internalKey): mixed
    {
        if (!in_array($internalKey, self::INTERNAL_KEYS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'getInternal: "%s" is not in INTERNAL_KEYS.',
                $internalKey,
            ));
        }
        $cached = $this->loadCache($siteIdentifier);
        return $cached['active'][$internalKey] ?? null;
    }

    /**
     * Write an internal-state key. Bypasses the editor adopt workflow —
     * not subject to drift detection, not surfaced in the Settings tab.
     */
    public function setInternal(string $siteIdentifier, string $internalKey, mixed $value, int $beUserId): void
    {
        if (!in_array($internalKey, self::INTERNAL_KEYS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'setInternal: "%s" is not in INTERNAL_KEYS.',
                $internalKey,
            ));
        }
        $this->activeSettings->upsertKey($siteIdentifier, $internalKey, $value, $beUserId);
        unset($this->cache[$siteIdentifier]);
    }

    /**
     * Drop an internal-state key (e.g. wizard reopen flow).
     */
    public function deleteInternal(string $siteIdentifier, string $internalKey, int $beUserId): void
    {
        if (!in_array($internalKey, self::INTERNAL_KEYS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'deleteInternal: "%s" is not in INTERNAL_KEYS.',
                $internalKey,
            ));
        }
        $this->activeSettings->deleteKey($siteIdentifier, $internalKey, $beUserId);
        unset($this->cache[$siteIdentifier]);
    }

    /**
     * Snapshot view of the editor-content active settings, for
     * inclusion in `ConfigSnapshotResolver`.
     *
     * @return array<string, mixed>  empty if not bootstrapped — caller renders "n/a"
     */
    public function activeSnapshot(string $siteIdentifier): array
    {
        $cached = $this->loadCache($siteIdentifier);
        if (!$cached['hasActiveRow']) {
            return [];
        }
        // Build a complete picture of editor-relevant state for the
        // snapshot — each EDITOR_CONTENT_KEY mapped to its effective
        // value. Filling in YAML defaults for keys the editor never
        // adopted keeps snapshot dedup stable (we don't want a no-op
        // YAML change to make every snapshot look different).
        $out = [];
        foreach (self::EDITOR_CONTENT_KEYS as $key) {
            $out[$key] = array_key_exists($key, $cached['active'])
                ? $cached['active'][$key]
                : ($cached['yaml'][$key] ?? null);
        }
        return $out;
    }

    // --- internals --------------------------------------------------------

    /**
     * @return array{active: array<string, mixed>, yaml: array<string, mixed>, hasActiveRow: bool}
     */
    private function loadCache(string $siteIdentifier): array
    {
        if (isset($this->cache[$siteIdentifier])) {
            return $this->cache[$siteIdentifier];
        }
        $active = $this->activeSettings->findBySite($siteIdentifier);
        $hasRow = $active !== null;
        $yaml = $this->collectYamlSettings($siteIdentifier);
        return $this->cache[$siteIdentifier] = [
            'active' => $active ?? [],
            'yaml' => $yaml,
            'hasActiveRow' => $hasRow,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectYamlSettings(string $siteIdentifier): array
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\TYPO3\CMS\Core\Exception\SiteNotFoundException) {
            return [];
        }
        $settings = $site->getSettings();
        $out = [];
        foreach (self::EDITOR_CONTENT_KEYS as $key) {
            $value = $settings->get($key);
            if ($value !== null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectYamlTrackers(string $siteIdentifier): array
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\TYPO3\CMS\Core\Exception\SiteNotFoundException) {
            return [];
        }
        $settings = $site->getSettings();
        $prefix = self::TRACKERS_KEY_PREFIX;
        $flat = [];
        foreach ($settings->getIdentifiers() as $identifier) {
            if (str_starts_with($identifier, $prefix)) {
                $flat[$identifier] = $settings->get($identifier);
            }
        }
        if ($flat === []) {
            return [];
        }
        try {
            $tree = ArrayUtility::unflatten($flat);
        } catch (\Throwable) {
            return [];
        }
        $list = $tree['simplecmp']['trackers'] ?? null;
        if (!is_array($list)) {
            return [];
        }
        ksort($list);
        return array_values($list);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findTracker(array $rows, string $type, string $serviceId): ?array
    {
        foreach ($rows as $row) {
            $rowType = (string) ($row['tracker_type'] ?? '');
            $rowSvc = (string) ($row['service_id'] ?? '');
            if ($rowType === $type && ($serviceId === '' || $rowSvc === $serviceId)) {
                return $row;
            }
        }
        return null;
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        // Loose JSON-ish equality: arrays compared canonically, scalars
        // strict. Avoids "0" != 0 issues that crop up when YAML stores
        // an int and DB-active stores a string.
        if (is_array($a) && is_array($b)) {
            return json_encode($a) === json_encode($b);
        }
        if (is_array($a) || is_array($b)) {
            return false;
        }
        return $a === $b || (is_scalar($a) && is_scalar($b) && (string) $a === (string) $b);
    }
}
