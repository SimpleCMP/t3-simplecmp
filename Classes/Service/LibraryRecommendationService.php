<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Computes "adopting library entry X would resolve N detections"
 * recommendations for the Bibliothek tab.
 *
 * Inverts the classifier's usual data flow. The classifier walks
 * {detection → which library entry matches?}; this service walks
 * {library entry → which actionable detections would it match?}.
 *
 * Used by `LibraryBrowserController` to surface a discovery section
 * at the top of the Bibliothek tab ("💡 Empfohlen für diese Site")
 * + per-row pills on the existing table.
 *
 * Pure compute service — no DB, no DI deps. All inputs are passed in
 * by the controller. Same matcher semantics as `ClassifierLookup` /
 * `DetectionListPresenter` (reuses the public static matchers from
 * `ClassifierLookup`).
 *
 * Performance shape: O(D × R) for the actionable-filter pass + O(D × L)
 * for the recommendation pass, where D = detections, R = registry size,
 * L = unadopted library entries. At dev14's scale (~10 detections
 * × ~360 library × <50 registry = ~3700 cheap regex/string compares)
 * the cost is negligible. If detection count ever climbs past several
 * thousand the controller should cap via `DetectionRepository::recent($limit)`.
 */
final readonly class LibraryRecommendationService
{
    /**
     * Compute recommendations for the supplied library entries against
     * the actionable subset of `$detections` (= not dismissed AND not
     * already covered by a registry entry).
     *
     * @param array<array<string, mixed>> $detections
     *      Raw detection rows as returned by `DetectionRepository::recent()`.
     * @param array<array<string, mixed>> $registryServices
     *      Protocol-shaped services as returned by
     *      `ServiceRepository::findAll()`. Used to filter out
     *      detections already covered (STATE_CURATED).
     * @param iterable<array<string, mixed>> $libraryEntries
     *      Bundled library entries. Caller is expected to pass ALL
     *      entries; adopted ones are filtered internally via `$adoptedIds`.
     * @param array<string, true> $adoptedIds
     *      Set of service-ids already in the registry (skip these —
     *      no recommendation needed).
     *
     * @return array<string, array{count: int, identifiers: list<string>}>
     *      Map keyed by library service-id. Only entries with ≥1 match
     *      appear. `count` = number of actionable detections matched.
     *      `identifiers` = all matched detection identifiers (deduped,
     *      preserving order of first occurrence) — caller truncates for
     *      tooltip display.
     */
    public function recommendationsFor(
        array $detections,
        array $registryServices,
        iterable $libraryEntries,
        array $adoptedIds,
    ): array {
        $actionable = $this->filterActionable($detections, $registryServices);
        if ($actionable === []) {
            return [];
        }

        $recommendations = [];
        foreach ($libraryEntries as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '' || isset($adoptedIds[$id])) {
                continue;
            }
            $cookies = (array) ($entry['matches']['cookies'] ?? []);
            $origins = (array) ($entry['matches']['origins'] ?? []);
            $identifiers = [];
            $seen = [];
            foreach ($actionable as $detection) {
                if (!$this->detectionMatchesEntry($detection, $cookies, $origins)) {
                    continue;
                }
                $detId = $this->matchedIdentifier($detection);
                if ($detId === '' || isset($seen[$detId])) {
                    continue;
                }
                $seen[$detId] = true;
                $identifiers[] = $detId;
            }
            if ($identifiers !== []) {
                $recommendations[$id] = [
                    'count' => count($identifiers),
                    'identifiers' => $identifiers,
                ];
            }
        }
        return $recommendations;
    }

    /**
     * Headline aggregate for the "💡 Empfohlen" section.
     * `entries` = count of library entries with at least one match.
     * `detections` = count of distinct actionable detections covered
     * by any recommendation (deduped — adopting one entry that covers
     * a detection means a second entry covering the same detection
     * doesn't add to the count).
     *
     * @param array<string, array{count: int, identifiers: list<string>}> $recommendations
     * @return array{entries: int, detections: int}
     */
    public function headline(array $recommendations): array
    {
        $distinctIdentifiers = [];
        foreach ($recommendations as $rec) {
            foreach ($rec['identifiers'] as $detId) {
                $distinctIdentifiers[$detId] = true;
            }
        }
        return [
            'entries' => count($recommendations),
            'detections' => count($distinctIdentifiers),
        ];
    }

    /**
     * @param array<array<string, mixed>> $detections
     * @param array<array<string, mixed>> $registryServices
     * @return list<array<string, mixed>>
     */
    private function filterActionable(array $detections, array $registryServices): array
    {
        $actionable = [];
        foreach ($detections as $detection) {
            if ((int) ($detection['dismissed_at'] ?? 0) > 0) {
                continue;
            }
            // Skip rows the registry already covers — adopting a library
            // entry that matches them wouldn't change their state (they're
            // STATE_CURATED today). Mirrors the precedence in
            // DetectionListPresenter::deriveState.
            $covered = false;
            foreach ($registryServices as $service) {
                $cookies = (array) ($service['matches']['cookies'] ?? []);
                $origins = (array) ($service['matches']['origins'] ?? []);
                if ($this->detectionMatchesEntry($detection, $cookies, $origins)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $actionable[] = $detection;
            }
        }
        return $actionable;
    }

    /**
     * The human-meaningful identifier we surface in tooltips:
     * cookie name for `kind=cookie`, host for everything else.
     * Mirrors the `cookie ↔ identifier`, `host ↔ origin` split in
     * `DetectionListPresenter::deriveState`.
     *
     * @param array<string, mixed> $detection
     */
    private function matchedIdentifier(array $detection): string
    {
        $kind = (string) ($detection['kind'] ?? '');
        if ($kind === 'cookie') {
            return (string) ($detection['identifier'] ?? '');
        }
        return (string) ($detection['origin'] ?? '');
    }

    /**
     * @param array<string, mixed> $detection
     * @param list<mixed> $cookieMatchers
     * @param list<mixed> $originMatchers
     */
    private function detectionMatchesEntry(
        array $detection,
        array $cookieMatchers,
        array $originMatchers,
    ): bool {
        $kind = (string) ($detection['kind'] ?? '');
        $identifier = (string) ($detection['identifier'] ?? '');
        $origin = (string) ($detection['origin'] ?? '');

        if ($kind === 'cookie' && $identifier !== '' && $cookieMatchers !== []) {
            if (ClassifierLookup::cookieMatches($identifier, $cookieMatchers)) {
                return true;
            }
        }
        if ($kind !== 'cookie' && $origin !== '' && $originMatchers !== []) {
            if (ClassifierLookup::originMatches($origin, $originMatchers)) {
                return true;
            }
        }
        return false;
    }
}
