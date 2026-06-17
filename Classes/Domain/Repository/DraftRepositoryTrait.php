<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;

/**
 * Phase-4 draft-aware repository helper.
 *
 * Mixed-in by the five repositories that mirror banner-config tables
 * (Service / Theme / TranslationOverride / ManagedTracker /
 * AllowedStylesheetHost). Provides the common draft-side primitives:
 *
 *   - `selectDraft()` — SELECT from the draft table, optionally
 *     scoped to a `draft_site` value
 *   - `insertDraft()` / `updateDraft()` / `deleteDraftBy()` — write
 *     helpers that always carry the bookkeeping columns
 *     (draft_site / draft_owner_be_user / draft_modified_at)
 *
 * Repositories using the trait must define:
 *   - `protected const string LIVE_TABLE` — `tx_t3simplecmp_*`
 *   - `protected const string DRAFT_TABLE` — `tx_t3simplecmp_*_draft`
 *
 * The trait talks to the existing `ConnectionPool` already injected
 * by each repository's constructor.
 */
trait DraftRepositoryTrait
{
    /**
     * SELECT all rows in the draft table for a single scope. Scope is
     * stored in the `draft_site` column — '' for global (services),
     * the site identifier otherwise.
     *
     * @return list<array<string, mixed>>
     */
    protected function selectDraftRows(string $scope): array
    {
        $draftSite = $scope === \SimpleCMP\T3SimpleCmp\Service\LockState::SCOPE_GLOBAL ? '' : $scope;
        $qb = $this->connectionPool->getConnectionForTable(static::DRAFT_TABLE)->createQueryBuilder();
        $rows = $qb->select('*')
            ->from(static::DRAFT_TABLE)
            ->where($qb->expr()->eq(
                'draft_site',
                $qb->createNamedParameter($draftSite, Connection::PARAM_STR),
            ))
            ->executeQuery()
            ->fetchAllAssociative();
        return array_values($rows);
    }

    /**
     * INSERT a draft row with the bookkeeping columns set. Returns
     * the new uid.
     *
     * @param array<string, mixed> $data  payload columns (without draft_*)
     */
    protected function insertDraftRow(string $scope, array $data, int $beUserId): int
    {
        $draftSite = $scope === \SimpleCMP\T3SimpleCmp\Service\LockState::SCOPE_GLOBAL ? '' : $scope;
        $now = time();
        $data['draft_site'] = $draftSite;
        $data['draft_owner_be_user'] = $beUserId;
        $data['draft_modified_at'] = $now;
        if (!isset($data['crdate'])) {
            $data['crdate'] = $now;
        }
        if (!isset($data['tstamp']) && $this->tableHasTstamp(static::DRAFT_TABLE)) {
            $data['tstamp'] = $now;
        }
        $conn = $this->connectionPool->getConnectionForTable(static::DRAFT_TABLE);
        $conn->insert(static::DRAFT_TABLE, $data);
        return (int) $conn->lastInsertId();
    }

    /**
     * UPDATE matching rows in the draft table. Always bumps
     * `draft_modified_at` + `tstamp` (where applicable). Returns the
     * affected row count.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $identifier  WHERE clause as column => value
     */
    protected function updateDraftRow(string $scope, array $identifier, array $data, int $beUserId): int
    {
        $draftSite = $scope === \SimpleCMP\T3SimpleCmp\Service\LockState::SCOPE_GLOBAL ? '' : $scope;
        $now = time();
        $data['draft_owner_be_user'] = $beUserId;
        $data['draft_modified_at'] = $now;
        if ($this->tableHasTstamp(static::DRAFT_TABLE)) {
            $data['tstamp'] = $now;
        }
        $identifier['draft_site'] = $draftSite;
        $conn = $this->connectionPool->getConnectionForTable(static::DRAFT_TABLE);
        return $conn->update(static::DRAFT_TABLE, $data, $identifier);
    }

    /**
     * DELETE matching rows in the draft table. Returns affected count.
     *
     * @param array<string, mixed> $identifier
     */
    protected function deleteDraftRows(string $scope, array $identifier): int
    {
        $draftSite = $scope === \SimpleCMP\T3SimpleCmp\Service\LockState::SCOPE_GLOBAL ? '' : $scope;
        $identifier['draft_site'] = $draftSite;
        $conn = $this->connectionPool->getConnectionForTable(static::DRAFT_TABLE);
        return $conn->delete(static::DRAFT_TABLE, $identifier);
    }

    /**
     * Does the draft table have a `tstamp` column? Determined by the
     * known per-table column lists in
     * {@see \SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService}. Avoids
     * a SHOW COLUMNS query on every write.
     */
    private function tableHasTstamp(string $draftTable): bool
    {
        // Service/Theme/TranslationOverride/ManagedTracker/AllowedStylesheetHost
        // all carry tstamp in their schema (`ext_tables.sql`).
        return str_starts_with($draftTable, 'tx_t3simplecmp_');
    }
}