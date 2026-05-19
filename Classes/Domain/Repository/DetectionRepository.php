<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Domain\Repository;

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
 * coverage — see {@see \WapplerSystems\SimpleCmpTypo3\Service\DetectionListPresenter}.
 */
final readonly class DetectionRepository
{
    private const string TABLE = 'tx_simplecmptypo3_detection';

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
        $existing = $conn->createQueryBuilder()
            ->select('uid', 'occurrences')
            ->from(self::TABLE)
            ->where('source = :source AND kind = :kind AND identifier = :id')
            ->setParameter('source', $source)
            ->setParameter('kind', $kind)
            ->setParameter('id', $identifier)
            ->executeQuery()
            ->fetchAssociative();

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
}
