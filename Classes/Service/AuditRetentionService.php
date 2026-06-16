<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use SimpleCMP\T3SimpleCmp\Domain\Repository\AuditRetentionLogRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Phase-3 retention executor. The CLI command parses + validates;
 * this service does the actual work and writes the self-audit log.
 *
 * Two callable surfaces:
 *   - {@see apply()} — invoked from the CLI; INSERTs into the log,
 *     then DELETEs the rows (in that order, so a crash mid-DELETE
 *     still leaves a "we tried" trail).
 *   - {@see dryRun()} — counts what would go, writes a dry-run row
 *     to the log marked `dry_run=1`.
 *
 * Retention targets — hardcoded enum to prevent operator typos
 * accidentally targeting the wrong table (or worse, the retention
 * log itself):
 *
 *     'config-snapshot' → tx_t3simplecmp_config_snapshot
 *     'consent-log'     → tx_t3simplecmp_consent_log
 *
 * The self-audit log table is INTENTIONALLY not addressable. Even
 * --target=all means "all two audit tables, never the log".
 *
 * Implementation note: ordering is INSERT first, then DELETE. Reverse
 * order would have a brief window where the rows are gone but the log
 * isn't yet — a crash would leave silent deletions. INSERT-first means
 * a crash leaves a planned-but-not-executed log row, which is
 * detectable + corrigible (rows_deleted will read N even though some
 * may have survived the crash; the operator runs again to converge).
 */
final readonly class AuditRetentionService
{
    public const string TARGET_CONFIG_SNAPSHOT = 'config-snapshot';
    public const string TARGET_CONSENT_LOG = 'consent-log';

    private const array TARGET_TABLES = [
        self::TARGET_CONFIG_SNAPSHOT => 'tx_t3simplecmp_config_snapshot',
        self::TARGET_CONSENT_LOG => 'tx_t3simplecmp_consent_log',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private AuditRetentionLogRepository $logRepository,
    ) {
    }

    /**
     * @return list<string> CLI-stable enum keys
     */
    public static function availableTargets(): array
    {
        return array_keys(self::TARGET_TABLES);
    }

    /**
     * Resolve a CLI-target keyword to a real table name. Throws if
     * the key is unknown — protects against arbitrary table names
     * sneaking through into a DELETE.
     */
    public static function tableForTarget(string $target): string
    {
        if (!isset(self::TARGET_TABLES[$target])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown retention target "%s". Allowed: %s',
                $target,
                implode(', ', array_keys(self::TARGET_TABLES)),
            ));
        }
        return self::TARGET_TABLES[$target];
    }

    /**
     * Apply retention. Returns the result so the CLI can render it.
     */
    public function apply(RetentionRequest $request): RetentionResult
    {
        $table = self::tableForTarget($request->target);
        $threshold = $request->thresholdCrdate();
        $matched = $this->countOlderThan($table, $threshold, $request->site);
        $oldestKept = $this->oldestKeptCrdate($table, $threshold, $request->site);

        $logUid = $this->logRepository->insert(
            targetTable: $table,
            targetSite: $request->site ?? '',
            rowsDeleted: $matched,
            keepDays: $request->keepDays,
            oldestKeptCrdate: $oldestKept,
            invokedBy: $request->invokedBy,
            invocationReason: $request->reason,
            dryRun: $request->dryRun,
        );

        $actuallyDeleted = $matched;
        if (!$request->dryRun && $matched > 0) {
            $actuallyDeleted = $this->deleteOlderThan($table, $threshold, $request->site);
        }

        return new RetentionResult(
            target: $request->target,
            table: $table,
            site: $request->site,
            matched: $matched,
            deleted: $request->dryRun ? 0 : $actuallyDeleted,
            keepDays: $request->keepDays,
            oldestKeptCrdate: $oldestKept,
            dryRun: $request->dryRun,
            logUid: $logUid,
        );
    }

    private function countOlderThan(string $table, int $threshold, ?string $site): int
    {
        $qb = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $qb->count('*')
            ->from($table)
            ->where($qb->expr()->lt('crdate', $qb->createNamedParameter($threshold, Connection::PARAM_INT)));
        if ($site !== null && $site !== '') {
            $qb->andWhere($qb->expr()->eq('site', $qb->createNamedParameter($site)));
        }
        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * What is the oldest `crdate` that will be KEPT after the cut?
     * Captured into the log for forensic reconstruction — combined
     * with `keep_days`, the operator can derive exactly the cut
     * timestamp at the time of execution (which differs from
     * "threshold" by clock-drift between count and DELETE).
     */
    private function oldestKeptCrdate(string $table, int $threshold, ?string $site): int
    {
        $qb = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $qb->select('crdate')
            ->from($table)
            ->where($qb->expr()->gte('crdate', $qb->createNamedParameter($threshold, Connection::PARAM_INT)))
            ->orderBy('crdate', 'ASC')
            ->setMaxResults(1);
        if ($site !== null && $site !== '') {
            $qb->andWhere($qb->expr()->eq('site', $qb->createNamedParameter($site)));
        }
        $value = $qb->executeQuery()->fetchOne();
        return $value === false ? 0 : (int) $value;
    }

    private function deleteOlderThan(string $table, int $threshold, ?string $site): int
    {
        $conn = $this->connectionPool->getConnectionForTable($table);
        $where = ['crdate < :threshold'];
        $params = ['threshold' => $threshold];
        $types = ['threshold' => Connection::PARAM_INT];
        if ($site !== null && $site !== '') {
            $where[] = 'site = :site';
            $params['site'] = $site;
            $types['site'] = Connection::PARAM_STR;
        }
        $sql = sprintf('DELETE FROM %s WHERE %s', $table, implode(' AND ', $where));
        return (int) $conn->executeStatement($sql, $params, $types);
    }
}
