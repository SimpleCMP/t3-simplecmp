<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use SimpleCMP\T3SimpleCmp\Library\ServicesLibrary;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\LibraryCacheRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;

/**
 * View-layer helpers for the detection list BE module.
 *
 * Pulled out of `DetectionReviewController` so the threshold math
 * and per-row badge logic are testable without an Extbase harness.
 * The controller injects this and delegates; thresholds and the
 * tiered-badge classes live here as the single source of truth.
 */
final readonly class DetectionListPresenter
{
    /**
     * Spike alert trips when **both** conditions are true: today's
     * count exceeds an absolute floor AND exceeds N× the 7-day avg.
     * The floor avoids day-one false positives on a fresh install
     * where "any" detection is a relative spike.
     */
    private const int SPIKE_MIN_ABSOLUTE = 50;
    private const int SPIKE_MULTIPLIER = 10;

    public const string STATE_CURATED = 'kuratiert';
    public const string STATE_RECOGNIZED = 'erkannt';
    public const string STATE_UNKNOWN = 'unbekannt';
    /**
     * Verworfen — admin clicked *Verwerfen* on the row. The dismissal is
     * persisted server-side via `dismissed_at` so it sticks across
     * visitors (a fresh browser without a localStorage marker can't
     * resurrect the row — the receiver bumps `occurrences` but leaves
     * `dismissed_at` set, keeping the row in this state). Recoverable
     * via *Wieder aufgreifen*; only true-delete from this state is
     * destructive.
     */
    public const string STATE_DISMISSED = 'verworfen';

    public function __construct(
        private DetectionRepository $detectionRepository,
        private ServiceRepository $serviceRepository,
        private LibraryCacheRepository $libraryCache,
    ) {
    }

    /**
     * Load the registry once + iterate the library once + read the live
     * positive upstream-library cache once. Returns pre-computed match
     * indexes so callers can decorate rows in O(1) per detection.
     *
     * The `upstreamCache` tier is the third classifier source: when
     * `Re-classify unknowns` has warmed it (or any visitor's lookup
     * has populated it), the affected `unbekannt` rows surface as
     * `erkannt` here without any duplicate upstream calls.
     *
     * @return array{
     *     services: array<array<string, mixed>>,
     *     library: array<array<string, mixed>>,
     *     upstreamCache: array<string, list<array<string, mixed>>>
     * }
     */
    public function loadStateContext(): array
    {
        return [
            'services' => $this->serviceRepository->findAll(),
            'library' => iterator_to_array(ServicesLibrary::services(), false),
            'upstreamCache' => $this->libraryCache->findLivePositive(time()),
        ];
    }

    /**
     * Derive the resolution state for a single detection given the
     * pre-loaded registry + library context. Three buckets:
     *
     * - `verworfen`: admin clicked *Verwerfen* (server-side flag).
     *   Checked first so a dismissed row stays dismissed regardless of
     *   registry/library coverage. Match info is still preserved
     *   (so un-dismissing sends the row back to its underlying state).
     * - `kuratiert`: registry already covers this cookie/origin →
     *   admin has nothing to do; row is filtered out of the default
     *   actionable view.
     * - `erkannt`: registry has no match, but the bundled library
     *   does → admin can one-click *Übernehmen* (silent-import) or
     *   *Anpassen* (curate with library pre-fill).
     * - `unbekannt`: nothing matches → admin must *Kuratieren*
     *   (manual entry).
     *
     * @param array<string, mixed> $detection
     * @param array<array<string, mixed>> $services
     * @param array<array<string, mixed>> $library
     * @param array<string, list<array<string, mixed>>> $upstreamCache
     * @return array{state: string, match: array<string, mixed>|null}
     */
    public static function deriveState(
        array $detection,
        array $services,
        array $library,
        array $upstreamCache = [],
    ): array {
        $kind = (string) ($detection['kind'] ?? '');
        $identifier = (string) ($detection['identifier'] ?? '');
        $origin = isset($detection['origin']) ? (string) $detection['origin'] : '';
        $cookie = $kind === 'cookie' && $identifier !== '' ? $identifier : null;
        $host = $kind !== 'cookie' && $origin !== '' ? $origin : null;

        $registryMatch = self::firstMatchingService($services, $cookie, $host);
        $libraryMatch = $registryMatch === null
            ? self::firstMatchingService($library, $cookie, $host)
            : null;
        $upstreamMatch = null;
        if ($registryMatch === null && $libraryMatch === null && $upstreamCache !== []) {
            $cacheKey = $cookie !== null ? 'cookie:' . $cookie : ($host !== null ? 'origin:' . $host : null);
            if ($cacheKey !== null && isset($upstreamCache[$cacheKey])) {
                $matches = $upstreamCache[$cacheKey];
                $upstreamMatch = $matches[0] ?? null;
            }
        }

        // Dismissed wins over everything — but we still surface the
        // underlying match so the row shows "Stripe" / "Google Analytics"
        // sub-labels and un-dismiss restores the right state.
        $dismissedAt = (int) ($detection['dismissed_at'] ?? 0);
        if ($dismissedAt > 0) {
            return [
                'state' => self::STATE_DISMISSED,
                'match' => $registryMatch ?? $libraryMatch ?? $upstreamMatch,
            ];
        }

        if ($registryMatch !== null) {
            return ['state' => self::STATE_CURATED, 'match' => $registryMatch];
        }
        if ($libraryMatch !== null) {
            return ['state' => self::STATE_RECOGNIZED, 'match' => $libraryMatch];
        }
        if ($upstreamMatch !== null) {
            return ['state' => self::STATE_RECOGNIZED, 'match' => $upstreamMatch];
        }
        return ['state' => self::STATE_UNKNOWN, 'match' => null];
    }

    /**
     * Decorate a detection row with `state`, `state_class` (badge CSS),
     * and `match` (matched service when applicable). Pure transform —
     * stateless once the context is loaded.
     *
     * @param array<string, mixed> $row
     * @param array<array<string, mixed>> $services
     * @param array<array<string, mixed>> $library
     * @param array<string, list<array<string, mixed>>> $upstreamCache
     * @return array<string, mixed>
     */
    public static function decorateState(
        array $row,
        array $services,
        array $library,
        array $upstreamCache = [],
    ): array {
        $derived = self::deriveState($row, $services, $library, $upstreamCache);
        $row['state'] = $derived['state'];
        $row['state_class'] = match ($derived['state']) {
            self::STATE_CURATED => 'bg-success',
            self::STATE_RECOGNIZED => 'bg-info text-dark',
            self::STATE_DISMISSED => 'bg-light text-muted border',
            default => 'bg-warning text-dark',
        };
        $row['match'] = $derived['match'];
        return $row;
    }

    /**
     * @param array<array<string, mixed>> $services
     * @return array<string, mixed>|null
     */
    private static function firstMatchingService(array $services, ?string $cookie, ?string $host): ?array
    {
        if ($cookie === null && $host === null) {
            return null;
        }
        foreach ($services as $service) {
            $cookies = $service['matches']['cookies'] ?? [];
            $origins = $service['matches']['origins'] ?? [];
            if ($cookie !== null && is_array($cookies) && self::cookieMatches($cookie, $cookies)) {
                return $service;
            }
            if ($host !== null && is_array($origins) && self::originMatches($host, $origins)) {
                return $service;
            }
        }
        return null;
    }

    /** @param list<mixed> $matchers */
    private static function cookieMatches(string $cookieName, array $matchers): bool
    {
        foreach ($matchers as $matcher) {
            if (is_string($matcher)) {
                if (self::cookieNameMatches($cookieName, $matcher)) {
                    return true;
                }
                continue;
            }
            // ADR-0010 host-qualified form `{name, requireOrigin}`. The
            // BE state derivation runs server-side and has no
            // observed-origins context, so it can only check the name
            // part — treats host-qualified entries as matching by name
            // alone. The runtime host-qualifier check is the FE
            // recorder's job. Admins see "kuratiert" for any cookie a
            // registry service *could* cover; the FE filters at runtime.
            if (
                is_array($matcher)
                && isset($matcher['name'], $matcher['requireOrigin'])
                && is_string($matcher['name'])
                && self::cookieNameMatches($cookieName, $matcher['name'])
            ) {
                return true;
            }
        }
        return false;
    }

    /** Slash-bounded = regex; otherwise exact match. */
    private static function cookieNameMatches(string $cookieName, string $matcher): bool
    {
        if (strlen($matcher) >= 2 && $matcher[0] === '/' && $matcher[-1] === '/') {
            return @preg_match('/' . substr($matcher, 1, -1) . '/', $cookieName) === 1;
        }
        return $matcher === $cookieName;
    }

    /** @param list<mixed> $matchers */
    private static function originMatches(string $host, array $matchers): bool
    {
        foreach ($matchers as $matcher) {
            if (!is_string($matcher)) {
                continue;
            }
            if (str_starts_with($matcher, '*.')) {
                $suffix = substr($matcher, 1);
                if (str_ends_with($host, $suffix) || $host === substr($suffix, 1)) {
                    return true;
                }
                continue;
            }
            if ($matcher === $host) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add confidence-tier metadata to a detection row. Stateless and
     * pure — the only "input" is the row's `occurrences` count.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function decorateConfidence(array $row): array
    {
        $occurrences = (int) ($row['occurrences'] ?? 0);
        $row['confidence_class'] = match (true) {
            $occurrences >= 5 => 'bg-success',
            $occurrences >= 2 => 'bg-secondary',
            default => 'bg-warning text-dark',
        };
        return $row;
    }

    /**
     * @return array{spikeAlert: bool, todayCount: int, sevenDayAverage: float}
     */
    public function computeSpikeContext(): array
    {
        $todayStart = mktime(0, 0, 0) ?: time();
        $todayCount = $this->detectionRepository->countSince($todayStart);
        $sevenDayTotal = $this->detectionRepository->countSince(time() - 7 * 86400);
        $sevenDayAverage = $sevenDayTotal / 7;
        $alert = $todayCount > self::SPIKE_MIN_ABSOLUTE
            && $todayCount > self::SPIKE_MULTIPLIER * $sevenDayAverage;
        return [
            'spikeAlert' => $alert,
            'todayCount' => $todayCount,
            'sevenDayAverage' => round($sevenDayAverage, 1),
        ];
    }
}
