<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use SimpleCMP\T3SimpleCmp\Library\ServicesLibrary;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;

/**
 * View-layer helpers for the Dienste BE tab.
 *
 * The tab lists every registry row (`tx_t3simplecmp_service`)
 * regardless of how it got there, and tags each row with a `source`:
 *
 * - **Eigene** (custom-curated) — `library_adopted_at = 0`. Never went
 *   through any Adopt-from-library path.
 * - **Aus Bibliothek** — `library_adopted_at > 0` AND the row's
 *   `service_id` still appears in the bundled library. The library
 *   continues to back this service.
 * - **Verwaist** (orphaned) — `library_adopted_at > 0` AND `service_id`
 *   is *no longer* in the bundled library. Library updates renamed or
 *   dropped the service; the registry row still works but isn't backed
 *   by the library anymore. Admin gets a callout + the Delete button
 *   (which Aus-Bibliothek rows don't have).
 *
 * Source state is derived at view time — no stored `source` column.
 * The library set is read fresh from `ServicesLibrary::services()` on
 * every render, so a `composer update` that drops a service flips the
 * affected rows from Aus-Bibliothek to Verwaist with no migration.
 */
final readonly class RegistryListPresenter
{
    public const string SOURCE_CUSTOM = 'custom';
    public const string SOURCE_LIBRARY = 'library';
    public const string SOURCE_ORPHANED = 'orphaned';

    public function __construct(
        private ServiceRepository $serviceRepository,
        private DetectionRepository $detectionRepository,
    ) {
    }

    /**
     * Snapshot of "what's in the library right now" — set of
     * `service_id` strings. Cached for the duration of a request via
     * the caller; not memoized here because the BE tab loads the
     * library exactly once per render.
     *
     * @return array<string, true>
     */
    public static function libraryIdSet(): array
    {
        $set = [];
        foreach (ServicesLibrary::services() as $entry) {
            if (isset($entry['id'])) {
                $set[(string) $entry['id']] = true;
            }
        }
        return $set;
    }

    /**
     * Source derivation for a single row. `$libraryIds` is the
     * pre-loaded set from {@see libraryIdSet()}.
     *
     * @param array<string, mixed> $row a row from
     *        {@see ServiceRepository::findAllForRegistryView()} —
     *        must carry `_libraryAdoptedAt` and `id` keys.
     * @param array<string, true> $libraryIds
     */
    public static function deriveSource(array $row, array $libraryIds): string
    {
        $adoptedAt = (int) ($row['_libraryAdoptedAt'] ?? 0);
        if ($adoptedAt === 0) {
            return self::SOURCE_CUSTOM;
        }
        $id = (string) ($row['id'] ?? '');
        return isset($libraryIds[$id]) ? self::SOURCE_LIBRARY : self::SOURCE_ORPHANED;
    }

    /**
     * Decorate a row with `source`, `source_class` (badge CSS), and
     * `source_label_key` (the locallang key for the badge label).
     *
     * @param array<string, mixed> $row
     * @param array<string, true> $libraryIds
     * @return array<string, mixed>
     */
    public static function decorateRow(array $row, array $libraryIds): array
    {
        $source = self::deriveSource($row, $libraryIds);
        $row['source'] = $source;
        $row['source_class'] = match ($source) {
            self::SOURCE_LIBRARY => 'bg-info text-dark',
            self::SOURCE_ORPHANED => 'bg-warning text-dark',
            default => 'bg-success',
        };
        $row['source_label_key'] = 'registry.badge.' . $source;
        return $row;
    }

    /**
     * Coverage counts per registry service: how many detection rows
     * currently derive to Kuratiert for each service. Surfaces unused
     * services (count = 0) so admins can spot prune candidates.
     *
     * One full-table scan over detections — same scale trade-off as
     * the detection list's state-derivation pass.
     *
     * @return array<string, int> keyed by service_id
     */
    public function coverageCountByServiceId(): array
    {
        $services = $this->serviceRepository->findAll();
        $library = iterator_to_array(ServicesLibrary::services(), false);
        $rows = $this->detectionRepository->recent(10000);
        $counts = [];
        foreach ($rows as $r) {
            $derived = DetectionListPresenter::deriveState($r, $services, $library);
            if (
                $derived['state'] === DetectionListPresenter::STATE_CURATED
                && is_array($derived['match'] ?? null)
                && isset($derived['match']['id'])
            ) {
                $id = (string) $derived['match']['id'];
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        return $counts;
    }
}
