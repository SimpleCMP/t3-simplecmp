<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use SimpleCMP\T3SimpleCmp\Domain\Repository\ConfigSnapshotRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ConsentLogRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Phase-3 bundle-builder. Both the CLI export command and the BE
 * Auskunfts-form route through here so they produce the same payload
 * shape against the same query semantics.
 *
 * Four entry points:
 *   - {@see buildForVisitor()}      — raw UUID → hash → consent rows + their snapshots
 *   - {@see buildForVisitorHash()}  — pre-hashed sha256 → same lookup, no rehash
 *   - {@see buildForSnapshot()}     — single snapshot + all decisions against it
 *   - {@see buildForDateRange()}    — site + time window → snapshots and decisions in [since, until)
 *
 * Snapshots and decisions are returned as plain rows; downstream
 * exporters know how to render them. The `filter` field on the bundle
 * carries the input parameters verbatim so the export header can say
 * "this is the export for visitor X on site Y at timestamp Z".
 *
 * Hash-vs-UUID path: callers that already have the sha256 (e.g. the
 * CLI passing through `--visitor=<sha256-hex>`) skip the hasher — there
 * is no plain UUID to feed it. The Auskunfts-form always passes the
 * raw UUID, so it lands in `buildForVisitor()` which hashes once.
 */
final readonly class VisitorAuskunftService
{
    public function __construct(
        private ConfigSnapshotRepository $snapshots,
        private ConsentLogRepository $decisions,
        private VisitorUuidHasher $hasher,
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Visitor brings their raw UUID. The bridge `$source` is needed
     * because the hash recipe is site-scoped — without it the
     * pseudonymization rule wouldn't match what ServiceDbApi wrote.
     */
    public function buildForVisitor(string $site, string $source, string $visitorUuid, int $limit = 1000): AuskunftBundle
    {
        $visitorHash = $this->hasher->hash($visitorUuid, $source);
        return $this->buildForVisitorHash($site, $visitorHash, $limit);
    }

    /**
     * Visitor's pseudonymized hash is already known (CLI path).
     */
    public function buildForVisitorHash(string $site, string $visitorIdSha256, int $limit = 1000): AuskunftBundle
    {
        $decisions = $this->decisions->findByVisitorHash($site, $visitorIdSha256, $limit);
        $snapshots = $this->snapshotsForDecisions($decisions);
        return new AuskunftBundle(
            snapshots: $snapshots,
            decisions: $decisions,
            filter: [
                'kind' => 'visitor',
                'site' => $site,
                'visitorHash' => $visitorIdSha256,
                'limit' => $limit,
            ],
        );
    }

    /**
     * Snapshot-scope export: one snapshot row + every decision row
     * whose `version_hash` matches it (regardless of site — a
     * version_hash is globally unique by content, so cross-site
     * collisions are vanishingly unlikely).
     */
    public function buildForSnapshot(string $versionHash, int $limit = 1000): AuskunftBundle
    {
        $snapshot = $this->snapshots->findByHash($versionHash);
        $decisions = $this->decisions->findByVersionHash($versionHash, $limit);
        return new AuskunftBundle(
            snapshots: $snapshot === null ? [] : [$snapshot],
            decisions: $decisions,
            filter: [
                'kind' => 'snapshot',
                'versionHash' => $versionHash,
                'limit' => $limit,
            ],
        );
    }

    /**
     * Date-range export: all snapshots and all decisions for a site
     * whose `crdate` falls in [since, until). `until` defaults to "now"
     * so callers can pass just `since` for "everything since X". Both
     * snapshots and decisions use `crdate` as their time axis.
     */
    public function buildForDateRange(string $site, int $since, ?int $until = null, int $limit = 1000): AuskunftBundle
    {
        $until ??= time();
        $snapshots = $this->snapshotsInRange($site, $since, $until, $limit);
        $decisions = $this->decisionsInRange($site, $since, $until, $limit);
        return new AuskunftBundle(
            snapshots: $snapshots,
            decisions: $decisions,
            filter: [
                'kind' => 'dateRange',
                'site' => $site,
                'since' => $since,
                'until' => $until,
                'limit' => $limit,
            ],
        );
    }

    /**
     * Collect the distinct snapshots referenced by a list of decision
     * rows. One DB round-trip per distinct hash — typically one or two
     * even for a long visitor history, since visitors usually consent
     * against the latest snapshot for an extended period.
     *
     * @param list<array<string, mixed>> $decisions
     * @return list<array<string, mixed>>
     */
    private function snapshotsForDecisions(array $decisions): array
    {
        $hashes = [];
        foreach ($decisions as $row) {
            $hash = isset($row['version_hash']) ? (string) $row['version_hash'] : '';
            if ($hash !== '') {
                $hashes[$hash] = true;
            }
        }
        if ($hashes === []) {
            return [];
        }
        $found = [];
        foreach (array_keys($hashes) as $hash) {
            $row = $this->snapshots->findByHash($hash);
            if ($row !== null) {
                $found[] = $row;
            }
        }
        return $found;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshotsInRange(string $site, int $since, int $until, int $limit): array
    {
        $qb = $this->connectionPool->getConnectionForTable('tx_t3simplecmp_config_snapshot')->createQueryBuilder();
        $rows = $qb->select('*')
            ->from('tx_t3simplecmp_config_snapshot')
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($since, Connection::PARAM_INT)),
                $qb->expr()->lt('crdate', $qb->createNamedParameter($until, Connection::PARAM_INT)),
            )
            ->orderBy('crdate', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
        return array_values($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decisionsInRange(string $site, int $since, int $until, int $limit): array
    {
        $qb = $this->connectionPool->getConnectionForTable('tx_t3simplecmp_consent_log')->createQueryBuilder();
        $rows = $qb->select('*')
            ->from('tx_t3simplecmp_consent_log')
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($since, Connection::PARAM_INT)),
                $qb->expr()->lt('crdate', $qb->createNamedParameter($until, Connection::PARAM_INT)),
            )
            ->orderBy('crdate', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
        return array_values($rows);
    }
}
