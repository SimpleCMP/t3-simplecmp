<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Stores incoming CMS-bridge webhooks from the SimpleCMP frontend.
 *
 * Receiver-side: on every POST that matches the
 * `docs/cms-bridge-webhook.md` schema, increment an existing row's
 * `occurrences` and `last_seen` if `(source, kind, identifier)` already
 * exists; otherwise insert a fresh row. This lets the TYPO3 admin see
 * "this unknown tracker has fired N times" instead of N rows of the
 * same thing.
 *
 * Resolution state is derived per-row at view time from registry
 * coverage — see {@see \SimpleCMP\T3SimpleCmp\Service\DetectionListPresenter}.
 */
final readonly class DetectionRepository
{
    private const string TABLE = 'tx_t3simplecmp_detection';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Idempotent batch ingest. Loops over `$payload['detections']` and
     * upserts each one — same `(source, kind, identifier)` triple
     * collapses to one row with bumped `occurrences` and `last_seen`.
     *
     * @param array<string, mixed> $payload the raw webhook body (v2 schema)
     * @param int $pid TYPO3 page UID under which to file new rows.
     *                  Updates of existing rows do not change pid.
     */
    public function ingest(array $payload, int $pid = 0): void
    {
        $detections = $payload['detections'] ?? null;
        if (!is_array($detections)) {
            return;
        }
        foreach ($detections as $detection) {
            if (is_array($detection)) {
                $this->ingestOne($payload, $detection, $pid);
            }
        }
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $detection
     */
    private function ingestOne(array $envelope, array $detection, int $pid): void
    {
        if (!isset($detection['kind'], $detection['identifier'])) {
            return;
        }

        $source = isset($envelope['source']) && is_string($envelope['source'])
            ? $envelope['source']
            : 'default';
        $kind = (string) $detection['kind'];
        $identifier = (string) $detection['identifier'];

        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $existing = $this->fetchExisting($conn, $source, $kind, $identifier);

        $now = time();
        $shared = [
            'tstamp' => $now,
            'last_seen' => isset($detection['lastSeen']) && is_int($detection['lastSeen'])
                ? $detection['lastSeen']
                : null,
            'sent_at' => isset($envelope['sentAt']) && is_string($envelope['sentAt'])
                ? $envelope['sentAt']
                : null,
            'origin' => isset($detection['origin']) && is_string($detection['origin'])
                ? $detection['origin']
                : null,
            'page_url' => isset($envelope['page']['url']) && is_string($envelope['page']['url'])
                ? $envelope['page']['url']
                : null,
            'first_seen_on' => isset($detection['firstSeenOn']) && is_string($detection['firstSeenOn'])
                ? $detection['firstSeenOn']
                : null,
            'referrer' => isset($envelope['page']['referrer']) && is_string($envelope['page']['referrer'])
                ? $envelope['page']['referrer']
                : null,
            'user_agent' => isset($envelope['page']['userAgent']) && is_string($envelope['page']['userAgent'])
                ? $envelope['page']['userAgent']
                : null,
            'library_version' => isset($envelope['library']['version']) && is_string($envelope['library']['version'])
                ? $envelope['library']['version']
                : null,
            'payload' => json_encode(['envelope' => $envelope, 'detection' => $detection], JSON_THROW_ON_ERROR),
        ];

        if ($existing === false) {
            try {
                $conn->insert(self::TABLE, array_merge($shared, [
                    'pid' => $pid,
                    'crdate' => $now,
                    'received_at' => $now,
                    'source' => $source,
                    'kind' => $kind,
                    'identifier' => $identifier,
                    'first_seen' => isset($detection['firstSeen']) && is_int($detection['firstSeen'])
                        ? $detection['firstSeen']
                        : null,
                    'occurrences' => 1,
                ]));
                return;
            } catch (UniqueConstraintViolationException) {
                // A concurrent POST inserted the same (source,kind,identifier)
                // between our SELECT and this INSERT. The UNIQUE dedup_key index
                // rejected the duplicate; re-read the now-present row and fall
                // through to the UPDATE so we bump occurrences instead of 500-ing.
                $existing = $this->fetchExisting($conn, $source, $kind, $identifier);
                if ($existing === false) {
                    // Row vanished again (extremely unlikely) — give up rather
                    // than loop; the detection is best-effort telemetry.
                    return;
                }
            }
        }

        $conn->update(
            self::TABLE,
            array_merge($shared, [
                'occurrences' => (int) $existing['occurrences'] + 1,
            ]),
            ['uid' => (int) $existing['uid']],
        );
    }

    /**
     * The existing row for a `(source, kind, identifier)` triple, or false.
     *
     * @return array<string, mixed>|false
     */
    private function fetchExisting(Connection $conn, string $source, string $kind, string $identifier): array|false
    {
        return $conn->createQueryBuilder()
            ->select('uid', 'occurrences')
            ->from(self::TABLE)
            ->where('source = :source AND kind = :kind AND identifier = :id')
            ->setParameter('source', $source)
            ->setParameter('kind', $kind)
            ->setParameter('id', $identifier)
            ->executeQuery()
            ->fetchAssociative();
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('received_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function count(): int
    {
        return (int) $this->connectionPool->getConnectionForTable(self::TABLE)
            ->executeQuery('SELECT COUNT(*) FROM ' . self::TABLE)
            ->fetchOne();
    }

    /**
     * Count rows with `crdate >= $timestamp`. Backs the BE module's
     * "ingest spike" detection — comparing today vs. a 7-day baseline.
     */
    public function countSince(int $timestamp): int
    {
        return (int) $this->connectionPool->getConnectionForTable(self::TABLE)
            ->executeQuery(
                'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE crdate >= ?',
                [$timestamp],
            )
            ->fetchOne();
    }

    /**
     * Fetch one detection row verbatim, deleted-restrictions off.
     *
     * @return array<string, mixed>|null
     */
    public function findOne(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * Verwerfen — flag the row and timestamp it. Not a delete: the row
     * stays as an audit trail, it only leaves the actionable view.
     *
     * Only touches rows that are not already dismissed, so the
     * idempotent case is a no-op and the original timestamp survives.
     *
     * @return int rows affected
     */
    public function dismiss(int $uid): int
    {
        $now = time();
        return (int) $this->connectionPool->getConnectionForTable(self::TABLE)
            ->executeStatement(
                'UPDATE ' . self::TABLE
                . ' SET dismissed_at = ?, tstamp = ? WHERE uid = ? AND dismissed_at = 0',
                [$now, $now, $uid],
            );
    }

    /**
     * Wieder aufgreifen — clear the flag so the row falls back to its
     * derived state and reappears in the actionable list. Symmetric to
     * {@see dismiss()}: only touches dismissed rows.
     *
     * @return int rows affected
     */
    public function undismiss(int $uid): int
    {
        return (int) $this->connectionPool->getConnectionForTable(self::TABLE)
            ->executeStatement(
                'UPDATE ' . self::TABLE
                . ' SET dismissed_at = 0, tstamp = ? WHERE uid = ? AND dismissed_at > 0',
                [time(), $uid],
            );
    }

    /**
     * Endgültig löschen — a true delete of dismissed rows.
     *
     * Deletes only rows that are actually dismissed, even when the
     * caller passes something else: dismiss-first is the audit-trail
     * rule, and a forged uid should be a no-op rather than a quiet
     * bypass of it.
     *
     * Returns the sources of the rows it removed so the caller can bump
     * their report generation — without that, browsers already
     * reporting hold a stale cross-session dedup marker and the tracker
     * stays undetected for the whole TTL.
     *
     * @param list<int> $uids
     * @return array{deleted: int, sources: list<string>}
     */
    public function purgeDismissed(array $uids): array
    {
        if ($uids === []) {
            return ['deleted' => 0, 'sources' => []];
        }
        $sources = $this->dismissedSourcesFor($uids);
        $deleted = (int) $this->connectionPool->getConnectionForTable(self::TABLE)
            ->executeStatement(
                'DELETE FROM ' . self::TABLE . ' WHERE uid IN (?) AND dismissed_at > 0',
                [$uids],
                [Connection::PARAM_INT_ARRAY],
            );
        return ['deleted' => $deleted, 'sources' => $sources];
    }

    /**
     * Distinct sources of the dismissed rows among `$uids` — captured
     * before a delete, with the same dismissed-only filter.
     *
     * @param list<int> $uids
     * @return list<string>
     */
    public function dismissedSourcesFor(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('source')
            ->distinct()
            ->from(self::TABLE)
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->gt('dismissed_at', $qb->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchFirstColumn();

        $sources = [];
        foreach ($rows as $source) {
            $source = (string) $source;
            if ($source !== '') {
                $sources[] = $source;
            }
        }
        return array_values(array_unique($sources));
    }
}
