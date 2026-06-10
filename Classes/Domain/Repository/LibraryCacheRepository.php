<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * 24-hour local cache for upstream library-service lookups.
 *
 * Keyed by `(query_type, query_value)` — `query_type` is `'cookie'`
 * or `'origin'`, `query_value` is the cookie name or origin host.
 * Stores the full response (array of matched services) as JSON in
 * `response_json`, or null to express a negative cache ("upstream
 * confirmed no match"). Negative caching is essential — without
 * it, unknown cookies hit the upstream on every recorder event.
 *
 * Both positive and negative entries get the same 24h TTL. Stale
 * entries are simply re-fetched the next time they're queried
 * (synchronous; no background refresh worker).
 */
final readonly class LibraryCacheRepository
{
    private const string TABLE = 'tx_t3simplecmp_library_cache';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Return the cached response for the given query, or null if no
     * cache entry exists or the entry has expired. A non-null array
     * (including the empty array) means a valid cache HIT — empty
     * array signals "upstream said no match." Distinguish miss-vs-
     * negative-hit via `has()`.
     *
     * @return list<array<string, mixed>>|null
     */
    public function get(string $queryType, string $queryValue, int $now): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('response_json', 'expires_at')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('query_type', $qb->createNamedParameter($queryType)),
                $qb->expr()->eq('query_value', $qb->createNamedParameter($queryValue)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }
        if ((int) $row['expires_at'] <= $now) {
            return null;
        }
        $decoded = json_decode((string) $row['response_json'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist a fresh cache entry. Overwrites any existing row for
     * the same (queryType, queryValue) pair. Stores `[]` for the
     * negative case.
     *
     * @param list<array<string, mixed>> $response
     */
    public function put(string $queryType, string $queryValue, array $response, int $fetchedAt, int $expiresAt): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $payload = (string) json_encode($response);

        // Race-safe upsert. The old DELETE-then-INSERT could collide on the
        // UNIQUE (query_type, query_value) key when two visitor lookups cached
        // the same query concurrently — the second INSERT threw and surfaced as
        // a 500 on the public /v1/lookup path. Try INSERT; on a unique-key
        // violation (a concurrent writer won, OR a stale/expired row is still
        // present — get() leaves expired rows), refresh the row in place. Last
        // write wins, which is fine for a cache; crdate is preserved.
        try {
            $connection->insert(self::TABLE, [
                'query_type' => $queryType,
                'query_value' => $queryValue,
                'response_json' => $payload,
                'fetched_at' => $fetchedAt,
                'expires_at' => $expiresAt,
                'crdate' => $fetchedAt,
                'tstamp' => $fetchedAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            $connection->update(
                self::TABLE,
                [
                    'response_json' => $payload,
                    'fetched_at' => $fetchedAt,
                    'expires_at' => $expiresAt,
                    'tstamp' => $fetchedAt,
                ],
                ['query_type' => $queryType, 'query_value' => $queryValue],
            );
        }
    }

    /**
     * Drop expired rows. Cheap to call periodically; the
     * `expires_at` index makes the scan tight. Not called
     * automatically anywhere — could be wired into the TYPO3
     * scheduler later if cache growth becomes a concern.
     */
    public function purgeExpired(int $now): int
    {
        return (int) $this->connectionPool->getConnectionForTable(self::TABLE)
            ->executeStatement(
                'DELETE FROM ' . self::TABLE . ' WHERE expires_at <= ?',
                [$now],
            );
    }

    /**
     * Live positive cache rows as a "cookie:<name>" / "origin:<host>"
     * → matched-services map. Used by the BE presenter to extend
     * state derivation with an "upstream-library" tier so that
     * after admin clicks *Re-classify unknowns* the unbekannt rows
     * actually flip to erkannt in the list (without forcing the
     * upstream call back through the FE classifier path).
     *
     * Negative cache rows are dropped — they carry no service to
     * surface as a match. Expired rows are dropped too.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function findLivePositive(int $now): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('query_type', 'query_value', 'response_json')
            ->from(self::TABLE)
            ->where($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['response_json'], true);
            if (!is_array($decoded) || $decoded === []) {
                continue;
            }
            $key = (string) $row['query_type'] . ':' . (string) $row['query_value'];
            $map[$key] = $decoded;
        }
        return $map;
    }

    /**
     * Row counts for the BE indicator on the Bibliothek tab. Splits
     * live (`expires_at > now`) entries into positive (cookies the
     * upstream classified as known) and negative (upstream said no
     * match) so admins can see both halves of the cache at a glance.
     * Expired rows aren't reported because they'll be re-fetched on
     * next access; counting them would inflate the "we're cached!"
     * narrative.
     *
     * @return array{positive: int, negative: int, expired: int}
     */
    public function countLive(int $now): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('response_json', 'expires_at')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        $positive = 0;
        $negative = 0;
        $expired = 0;
        foreach ($rows as $row) {
            if ((int) $row['expires_at'] <= $now) {
                $expired++;
                continue;
            }
            $decoded = json_decode((string) $row['response_json'], true);
            if (is_array($decoded) && $decoded !== []) {
                $positive++;
            } else {
                $negative++;
            }
        }
        return ['positive' => $positive, 'negative' => $negative, 'expired' => $expired];
    }
}
