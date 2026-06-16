<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Append-only repository for `tx_t3simplecmp_config_snapshot` — the
 * audit history of resolved banner configurations (Phase 1).
 *
 * The repository exposes only read + insert. Update / delete are
 * intentionally absent: identical-content saves dedupe via the
 * `(site, version_hash)` UNIQUE constraint, and editor-level mutation
 * is blocked by TCA `readOnly` + a DataHandler hook
 * ({@see \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EnforceConfigSnapshotAppendOnly}).
 *
 * `insert()` uses an INSERT-and-catch-collision pattern so concurrent
 * saves of the same canonical content don't race into duplicate rows.
 */
final readonly class ConfigSnapshotRepository
{
    private const string TABLE = 'tx_t3simplecmp_config_snapshot';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Most-recent-first list of snapshots for a single site. Used by
     * the BE audit list view; cap defaults to one page.
     *
     * @return list<array<string, mixed>>
     */
    public function findBySite(string $site, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $rows = $qb->select('uid', 'crdate', 'site', 'version_hash', 'trigger_event', 'creator_be_user')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('site', $qb->createNamedParameter($site)))
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset))
            ->executeQuery()
            ->fetchAllAssociative();
        return array_values($rows);
    }

    /**
     * Total snapshot count per site — for pagination.
     */
    public function countBySite(string $site): int
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $count = $qb->count('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('site', $qb->createNamedParameter($site)))
            ->executeQuery()
            ->fetchOne();
        return (int) $count;
    }

    /**
     * Fetch a single snapshot by uid (BE-show action). Returns null
     * when the row doesn't exist. The full `canonical_json` blob is
     * included.
     *
     * @return array<string, mixed>|null
     */
    public function findOne(int $uid): ?array
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * Fetch the snapshot that immediately precedes the given one for
     * its site — used by the show action to render the "diff to
     * previous version" panel. Null when this is the first snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function findPrevious(string $site, int $uid): ?array
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->lt('uid', $qb->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * Most-recent snapshot's `version_hash` for a site, or null when
     * the site has no snapshots yet. Used by `RegisterAssets` to
     * stamp the FE init payload with the current snapshot reference
     * — Phase-2 consent-log POSTs cite this hash so each decision is
     * bound to the banner state it was made against.
     *
     * Cheap: uses the `by_site_date` index. Reads only the hash
     * column, not the full canonical JSON.
     */
    public function findLatestHashBySite(string $site): ?string
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $hash = $qb->select('version_hash')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('site', $qb->createNamedParameter($site)))
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return $hash === false ? null : (string) $hash;
    }

    /**
     * Whether a snapshot with this hash already exists for this site.
     * Used by the listener to short-circuit a no-op INSERT.
     */
    public function existsForHash(string $site, string $versionHash): bool
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $count = $qb->count('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->eq('version_hash', $qb->createNamedParameter($versionHash)),
            )
            ->executeQuery()
            ->fetchOne();
        return ((int) $count) > 0;
    }

    /**
     * Insert a snapshot row. Concurrent writers calling with the same
     * `(site, version_hash)` race-condition into the UNIQUE
     * constraint — we catch the collision and treat it as a no-op
     * (the existing row already proves the same content).
     */
    public function insert(
        string $site,
        string $versionHash,
        string $canonicalJson,
        string $triggerEvent,
        int $creatorBeUser,
    ): void {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        try {
            $conn->insert(self::TABLE, [
                'site' => $site,
                'version_hash' => $versionHash,
                'canonical_json' => $canonicalJson,
                'trigger_event' => $triggerEvent,
                'creator_be_user' => $creatorBeUser,
                'crdate' => time(),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Identical content from a concurrent writer — no-op.
            // The existing row already provides the audit trail.
        }
    }
}
