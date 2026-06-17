<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

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
    private const string TABLE = 'tx_t3simplecmp_theme';
    protected const string LIVE_TABLE = 'tx_t3simplecmp_theme';
    protected const string DRAFT_TABLE = 'tx_t3simplecmp_theme_draft';

    use DraftRepositoryTrait;

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

    // --- Phase 4 draft surface ---------------------------------------------

    /**
     * Read theme tokens from the draft table for the given scope
     * (= site identifier). Returns null when no draft row exists.
     *
     * @return array<string, scalar>|null
     */
    public function findBySiteDraft(string $scope): ?array
    {
        $rows = $this->selectDraftRows($scope);
        $row = array_values(array_filter(
            $rows,
            static fn (array $r) => ($r['site'] ?? null) === $scope,
        ))[0] ?? null;
        if ($row === null || !is_string($row['tokens']) || $row['tokens'] === '') {
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
    public function upsertDraft(string $scope, array $tokens, int $beUserId): void
    {
        $existingUid = $this->draftUid($scope);
        $payload = ['tokens' => json_encode($tokens, JSON_THROW_ON_ERROR)];
        if ($existingUid === null) {
            $payload['site'] = $scope;
            $this->insertDraftRow($scope, $payload, $beUserId);
            return;
        }
        $this->updateDraftRow($scope, ['uid' => $existingUid], $payload, $beUserId);
    }

    public function deleteDraft(string $scope): int
    {
        return $this->deleteDraftRows($scope, ['site' => $scope]);
    }

    private function draftUid(string $scope): ?int
    {
        $rows = $this->selectDraftRows($scope);
        foreach ($rows as $row) {
            if (($row['site'] ?? null) === $scope) {
                return (int) $row['uid'];
            }
        }
        return null;
    }
}
