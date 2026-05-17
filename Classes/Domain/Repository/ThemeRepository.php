<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Domain\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Per-Site-Set banner theme storage.
 *
 * One row per site identifier (`default`, `site2`, …). Tokens are stored
 * as a single JSON blob to keep the schema flexible — the v0 set is
 * 10 fields but we add more without migrations as the design surface
 * grows. Missing row means "use defaults"; deleting a row is the
 * Reset action.
 *
 * Read at FE render time (per request, per site) and at BE designer
 * load time. Cheap enough that no caching layer is needed for v0.
 */
final readonly class ThemeRepository
{
    private const string TABLE = 'tx_simplecmptypo3_theme';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return array<string, scalar>|null tokens map, or null if no row
     */
    public function findBySite(string $site): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('tokens')
            ->from(self::TABLE)
            ->where('site = :site')
            ->setParameter('site', $site)
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false || !is_string($row['tokens']) || $row['tokens'] === '') {
            return null;
        }
        try {
            $decoded = json_decode($row['tokens'], true, 8, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, scalar> $tokens
     */
    public function upsert(string $site, array $tokens): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = time();
        $payload = [
            'tstamp' => $now,
            'tokens' => json_encode($tokens, JSON_THROW_ON_ERROR),
        ];
        $existing = $conn->createQueryBuilder()
            ->select('uid')
            ->from(self::TABLE)
            ->where('site = :site')
            ->setParameter('site', $site)
            ->executeQuery()
            ->fetchOne();
        if ($existing === false) {
            $payload['crdate'] = $now;
            $payload['site'] = $site;
            $conn->insert(self::TABLE, $payload);
            return;
        }
        $conn->update(self::TABLE, $payload, ['uid' => (int) $existing]);
    }

    public function delete(string $site): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['site' => $site]);
    }
}
