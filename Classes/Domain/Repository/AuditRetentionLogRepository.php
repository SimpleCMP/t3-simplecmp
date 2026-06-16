<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Append-only repository for `tx_t3simplecmp_audit_retention_log` (Phase 3).
 *
 * Only insert + read methods — no update, no delete. The
 * {@see \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EnforceAuditRetentionLogAppendOnly}
 * hook refuses editor mutations; this class refuses them by not exposing them.
 * Written exclusively by
 * {@see \SimpleCMP\T3SimpleCmp\Service\AuditRetentionService}.
 */
final readonly class AuditRetentionLogRepository
{
    public const string TABLE = 'tx_t3simplecmp_audit_retention_log';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Most-recent-first list of retention log entries — for the
     * Auskunfts-tab visibility panel and CLI verification.
     *
     * @return list<array<string, mixed>>
     */
    public function findRecent(int $limit = 20, int $offset = 0): array
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $rows = $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset))
            ->executeQuery()
            ->fetchAllAssociative();
        return array_values($rows);
    }

    public function countAll(): int
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $count = $qb->count('*')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchOne();
        return (int) $count;
    }

    /**
     * INSERT a single retention log row. Returns the new uid so the
     * CLI command can report "log entry uid=Y" in its success line.
     */
    public function insert(
        string $targetTable,
        string $targetSite,
        int $rowsDeleted,
        int $keepDays,
        int $oldestKeptCrdate,
        string $invokedBy,
        string $invocationReason,
        bool $dryRun,
    ): int {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->insert(self::TABLE, [
            'target_table' => $targetTable,
            'target_site' => $targetSite,
            'rows_deleted' => $rowsDeleted,
            'keep_days' => $keepDays,
            'oldest_kept_crdate' => $oldestKeptCrdate,
            'invoked_by' => $invokedBy,
            'invocation_reason' => $invocationReason,
            'dry_run' => $dryRun ? 1 : 0,
            'crdate' => time(),
        ], [
            'target_table' => Connection::PARAM_STR,
            'target_site' => Connection::PARAM_STR,
            'rows_deleted' => Connection::PARAM_INT,
            'keep_days' => Connection::PARAM_INT,
            'oldest_kept_crdate' => Connection::PARAM_INT,
            'invoked_by' => Connection::PARAM_STR,
            'invocation_reason' => Connection::PARAM_STR,
            'dry_run' => Connection::PARAM_INT,
            'crdate' => Connection::PARAM_INT,
        ]);
        return (int) $conn->lastInsertId();
    }
}
