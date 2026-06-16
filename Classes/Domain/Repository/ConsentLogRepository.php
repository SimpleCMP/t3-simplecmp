<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Append-only repository for `tx_t3simplecmp_consent_log` — Phase 2 of
 * the audit trail.
 *
 * Same INSERT-and-catch-collision shape as
 * {@see ConfigSnapshotRepository}: identical-content saves dedupe via
 * the `(site, visitor_id_sha256, version_hash, decision_hash)` UNIQUE
 * constraint. No update / no delete by design — the
 * {@see \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EnforceConsentLogAppendOnly}
 * hook refuses both via the editor API, and the repository simply
 * doesn't expose mutators.
 */
final readonly class ConsentLogRepository
{
    private const string TABLE = 'tx_t3simplecmp_consent_log';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Most-recent-first list of decisions logged against a specific
     * snapshot version. Used by the BE audit-tab show view to render
     * "Decisions for this version" alongside the snapshot diff.
     *
     * @return list<array<string, mixed>>
     */
    public function findByVersionHash(string $versionHash, int $limit = 100, int $offset = 0): array
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $rows = $qb->select(
            'uid',
            'crdate',
            'site',
            'version_hash',
            'visitor_id_sha256',
            'decision_hash',
            'decisions_json',
            'decision_type',
            'ua_family',
            'page_url_host',
        )
            ->from(self::TABLE)
            ->where($qb->expr()->eq('version_hash', $qb->createNamedParameter($versionHash)))
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset))
            ->executeQuery()
            ->fetchAllAssociative();
        return array_values($rows);
    }

    public function countByVersionHash(string $versionHash): int
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $count = $qb->count('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('version_hash', $qb->createNamedParameter($versionHash)))
            ->executeQuery()
            ->fetchOne();
        return (int) $count;
    }

    /**
     * All decisions for a given visitor hash on a single site, most
     * recent first. Future Phase-3 DSGVO-Auskunft workflow surface;
     * also useful for "show me this visitor's history" in the BE.
     *
     * @return list<array<string, mixed>>
     */
    public function findByVisitorHash(string $site, string $visitorIdSha256, int $limit = 100, int $offset = 0): array
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $rows = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('site', $qb->createNamedParameter($site)),
                $qb->expr()->eq('visitor_id_sha256', $qb->createNamedParameter($visitorIdSha256)),
            )
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset))
            ->executeQuery()
            ->fetchAllAssociative();
        return array_values($rows);
    }

    /**
     * INSERT a decision row. Concurrent identical-content writers hit
     * the UNIQUE constraint; we catch and treat as a no-op (the
     * existing row already proves the same decision was made by the
     * same visitor against the same snapshot version).
     */
    public function insert(
        string $site,
        string $versionHash,
        string $visitorIdSha256,
        string $decisionHash,
        string $decisionsJson,
        string $decisionType,
        ?string $uaFamily,
        ?string $pageUrlHost,
    ): void {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        try {
            $conn->insert(self::TABLE, [
                'site' => $site,
                'version_hash' => $versionHash,
                'visitor_id_sha256' => $visitorIdSha256,
                'decision_hash' => $decisionHash,
                'decisions_json' => $decisionsJson,
                'decision_type' => $decisionType,
                'ua_family' => $uaFamily,
                'page_url_host' => $pageUrlHost,
                'crdate' => time(),
            ], [
                // ua_family / page_url_host can be null; the rest are
                // string-typed. Explicit binding types avoid PDO's
                // null-string coercion surprises.
                'site' => Connection::PARAM_STR,
                'version_hash' => Connection::PARAM_STR,
                'visitor_id_sha256' => Connection::PARAM_STR,
                'decision_hash' => Connection::PARAM_STR,
                'decisions_json' => Connection::PARAM_STR,
                'decision_type' => Connection::PARAM_STR,
                'ua_family' => $uaFamily === null ? Connection::PARAM_NULL : Connection::PARAM_STR,
                'page_url_host' => $pageUrlHost === null ? Connection::PARAM_NULL : Connection::PARAM_STR,
                'crdate' => Connection::PARAM_INT,
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Same visitor, same snapshot version, same decision payload
            // — the existing row provides the audit trail.
        }
    }
}
