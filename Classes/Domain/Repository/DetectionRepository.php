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
     * Idempotent ingest. Aggregates repeat hits of the same
     * `(source, kind, identifier)` triple into one row.
     *
     * @param array<string, mixed> $payload the raw webhook body
     * @param int $pid TYPO3 page UID under which to file new rows.
     *                  Updates of existing rows do not change pid.
     */
    public function ingest(array $payload, int $pid = 0): void
    {
        $detection = $payload['detection'] ?? [];
        if (!is_array($detection) || !isset($detection['kind'], $detection['identifier'])) {
            // Drop malformed payloads silently — the JS side wouldn't have
            // sent one, but if a misconfigured receiver pokes us we don't
            // want to 500.
            return;
        }

        $source = isset($payload['source']) && is_string($payload['source'])
            ? $payload['source']
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
            'sent_at' => isset($payload['sentAt']) && is_string($payload['sentAt'])
                ? $payload['sentAt']
                : null,
            'origin' => isset($detection['origin']) && is_string($detection['origin'])
                ? $detection['origin']
                : null,
            'page_url' => isset($payload['page']['url']) && is_string($payload['page']['url'])
                ? $payload['page']['url']
                : null,
            'first_seen_on' => isset($detection['firstSeenOn']) && is_string($detection['firstSeenOn'])
                ? $detection['firstSeenOn']
                : null,
            'referrer' => isset($payload['page']['referrer']) && is_string($payload['page']['referrer'])
                ? $payload['page']['referrer']
                : null,
            'user_agent' => isset($payload['page']['userAgent']) && is_string($payload['page']['userAgent'])
                ? $payload['page']['userAgent']
                : null,
            'library_version' => isset($payload['library']['version']) && is_string($payload['library']['version'])
                ? $payload['library']['version']
                : null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
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
