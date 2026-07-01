<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use SimpleCMP\T3SimpleCmp\Domain\Repository\PublishLockRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Phase-4 draft workspace orchestrator.
 *
 * Two concerns:
 *   - **Lock**: at most one editor per scope holds an active draft.
 *     `scope` is either a site identifier (per-site theme / overrides /
 *     trackers / hosts) or `LockState::SCOPE_GLOBAL` (the globally
 *     shared service registry).
 *   - **Copy-on-Write**: the first draft write for a scope copies the
 *     current live state into the draft tables, so the editor starts
 *     from a consistent baseline. Subsequent writes only mutate the
 *     draft.
 *
 * The scope → table-pairs map is hardcoded (see {@see SCOPE_TABLES})
 * — there are exactly two scope-shapes (global / per-site) and the
 * column lists are stable. Reflection-based discovery would be more
 * generic but harder to debug and slower at runtime.
 */
final readonly class DraftWorkspaceService
{
    /**
     * Per-scope table pairs to copy on init / discard.
     *
     * Each entry: ['live' => name, 'draft' => name, 'siteColumn' => 'site'|null].
     *
     * `siteColumn` is the live-table column that restricts the copy to
     * this scope (theme.site / translation_override.site /
     * managed_tracker.site / allowed_stylesheet_host.source). NULL for
     * service (global — entire live table is copied).
     */
    public const array SCOPE_TABLES_GLOBAL = [
        [
            'live' => 'tx_t3simplecmp_service',
            'draft' => 'tx_t3simplecmp_service_draft',
            'siteColumn' => null,
            'columns' => [
                'pid', 'tstamp', 'crdate',
                'service_id', 'name', 'vendor', 'vendor_country',
                'vendor_address', 'vendor_opt_out_url', 'vendor_partner',
                'vendor_description', 'purposes', 'privacy_policy_url',
                'description', 'retention', 'i18n', 'cookies', 'origins',
                'extensions', 'placeholder_title', 'placeholder_description',
                'library_adopted_at',
            ],
        ],
    ];

    public const array SCOPE_TABLES_PER_SITE = [
        [
            'live' => 'tx_t3simplecmp_theme',
            'draft' => 'tx_t3simplecmp_theme_draft',
            'siteColumn' => 'site',
            'columns' => ['pid', 'tstamp', 'crdate', 'site', 'tokens'],
        ],
        [
            'live' => 'tx_t3simplecmp_translation_override',
            'draft' => 'tx_t3simplecmp_translation_override_draft',
            'siteColumn' => 'site',
            'columns' => ['pid', 'tstamp', 'crdate', 'site', 'overrides'],
        ],
        [
            'live' => 'tx_t3simplecmp_managed_tracker',
            'draft' => 'tx_t3simplecmp_managed_tracker_draft',
            'siteColumn' => 'site',
            'columns' => ['pid', 'tstamp', 'crdate', 'deleted', 'site', 'tracker_type', 'service_id', 'config'],
        ],
        [
            'live' => 'tx_t3simplecmp_allowed_stylesheet_host',
            'draft' => 'tx_t3simplecmp_allowed_stylesheet_host_draft',
            // The "site" for an allowed-stylesheet-host is its
            // `source` column, which is the site's bridge source
            // (= 'simplecmp-<site>') — see RegisterAssets::bridgeSource().
            'siteColumn' => 'source',
            'siteColumnIsBridgeSource' => true,
            'columns' => ['pid', 'tstamp', 'crdate', 'source', 'host'],
        ],
    ];

    public function __construct(
        private PublishLockRepository $lockRepository,
        private ConnectionPool $connectionPool,
        private ClockInterface $clock = new SystemClock(),
    ) {
    }

    // --- lock ---------------------------------------------------------------

    public function currentLock(string $scope): LockState
    {
        return $this->lockRepository->find($scope) ?? LockState::unlocked($scope);
    }

    public function acquireLock(string $scope, int $beUserId): LockState
    {
        if ($beUserId <= 0) {
            throw new \InvalidArgumentException('acquireLock requires a positive BE user id.');
        }
        return $this->lockRepository->acquire($scope, $beUserId, $this->clock->now());
    }

    public function releaseLock(string $scope): void
    {
        $this->lockRepository->release($scope);
    }

    public function takeoverLock(string $scope, int $newOwnerBeUserId): LockState
    {
        if ($newOwnerBeUserId <= 0) {
            throw new \InvalidArgumentException('takeoverLock requires a positive BE user id.');
        }
        return $this->lockRepository->takeover($scope, $newOwnerBeUserId, $this->clock->now());
    }

    public function touchLock(string $scope): void
    {
        $this->lockRepository->touch($scope, $this->clock->now());
    }

    /**
     * @return list<LockState>
     */
    public function allActiveLocks(): array
    {
        return $this->lockRepository->findAll();
    }

    // --- copy-on-write ------------------------------------------------------

    /**
     * Does this scope have any draft data? Returns true if at least
     * one row exists in any of the scope-relevant draft tables.
     */
    public function hasDraft(string $scope): bool
    {
        foreach ($this->tablePairsForScope($scope) as $pair) {
            $count = $this->countDraftRows($pair['draft'], $scope);
            if ($count > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Revision marker for the scope's current draft: the newest
     * `draft_modified_at` across all of the scope's draft tables, or 0
     * when no draft row exists. Both sides of the live-FE-audit read it
     * identically — the BE to know the expected revision, the FE
     * (RegisterAssets) to report which revision it actually rendered —
     * so the editor can confirm the preview judged the right version.
     */
    public function draftRevision(string $scope): int
    {
        $draftSite = $scope === LockState::SCOPE_GLOBAL ? '' : $scope;
        $newest = 0;
        foreach ($this->tablePairsForScope($scope) as $pair) {
            $qb = $this->connectionPool->getConnectionForTable($pair['draft'])->createQueryBuilder();
            $value = $qb->select('draft_modified_at')
                ->from($pair['draft'])
                ->where($qb->expr()->eq('draft_site', $qb->createNamedParameter($draftSite, Connection::PARAM_STR)))
                ->orderBy('draft_modified_at', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
            if (is_numeric($value)) {
                $newest = max($newest, (int) $value);
            }
        }
        return $newest;
    }

    /**
     * Copy live state for this scope into the draft tables and acquire
     * the lock. Idempotent: re-invoking when draft already exists
     * touches the lock without re-copying. Caller must check for lock
     * conflict (this method does not enforce ownership).
     */
    public function initializeDraft(string $scope, int $beUserId): LockState
    {
        $lock = $this->acquireLock($scope, $beUserId);
        if ($lock->conflict) {
            return $lock;
        }
        if ($this->hasDraft($scope)) {
            // Drafts already initialized — caller is just reopening
            // their session.
            $this->touchLock($scope);
            return $lock;
        }
        $now = $this->clock->now();
        foreach ($this->tablePairsForScope($scope) as $pair) {
            $this->copyLiveToDraft($pair, $scope, $beUserId, $now);
        }
        return $lock;
    }

    /**
     * Discard all draft rows for this scope and release the lock.
     * Idempotent — invoking on an empty scope is a no-op.
     */
    public function discardDraft(string $scope): void
    {
        foreach ($this->tablePairsForScope($scope) as $pair) {
            $this->deleteDraftRows($pair['draft'], $scope);
        }
        $this->releaseLock($scope);
    }

    // --- Per-site umbrella -------------------------------------------------
    //
    // A site's config spans two physical draft scopes: the shared GLOBAL
    // service registry and the per-site tables (theme / overrides /
    // trackers / hosts). The module presents ONE draft per site, so these
    // helpers run the existing per-scope operations over BOTH scopes as a
    // unit. Services stay physically global (draft_site=''), so no data is
    // put at risk — only the lifecycle is unified.

    /**
     * The scopes that make up a site's unified draft. Passing the global
     * sentinel (or an empty string) collapses to the global scope only,
     * so callers can use the umbrella helpers uniformly.
     *
     * @return list<string>
     */
    public function relatedScopes(string $site): array
    {
        if ($site === '' || $site === LockState::SCOPE_GLOBAL) {
            return [LockState::SCOPE_GLOBAL];
        }
        return [LockState::SCOPE_GLOBAL, $site];
    }

    /**
     * True if EITHER the global service draft or the site draft exists.
     */
    public function hasDraftForSite(string $site): bool
    {
        foreach ($this->relatedScopes($site) as $scope) {
            if ($this->hasDraft($scope)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Newest draft_modified_at across both scopes (0 when none).
     */
    public function draftRevisionForSite(string $site): int
    {
        $newest = 0;
        foreach ($this->relatedScopes($site) as $scope) {
            $newest = max($newest, $this->draftRevision($scope));
        }
        return $newest;
    }

    /**
     * Combined lock for the umbrella: returns the site lock if held, else
     * the global lock, else unlocked. Mirrors currentLock()'s semantics
     * (ownership is enforced at publish/init time, not via the conflict
     * flag).
     */
    public function lockForSite(string $site): LockState
    {
        if ($site !== '' && $site !== LockState::SCOPE_GLOBAL) {
            $siteLock = $this->currentLock($site);
            if (!$siteLock->isUnlocked()) {
                return $siteLock;
            }
        }
        $globalLock = $this->currentLock(LockState::SCOPE_GLOBAL);
        return $globalLock->isUnlocked() ? LockState::unlocked($site) : $globalLock;
    }

    /**
     * Open (or reopen) a site's unified draft: initialize BOTH scopes and
     * acquire both locks. Returns a conflicting LockState as soon as any
     * scope is held by another user (nothing is created in that case for
     * the conflicting scope — acquireLock refuses it).
     */
    public function initializeDraftForSite(string $site, int $beUserId): LockState
    {
        $result = null;
        foreach ($this->relatedScopes($site) as $scope) {
            $lock = $this->initializeDraft($scope, $beUserId);
            if ($lock->conflict) {
                return $lock;
            }
            if ($result === null || $scope === $site) {
                $result = $lock;
            }
        }
        return $result ?? LockState::unlocked($site);
    }

    /**
     * Discard a site's unified draft: clear both scopes and release both
     * locks.
     */
    public function discardDraftForSite(string $site): void
    {
        foreach ($this->relatedScopes($site) as $scope) {
            $this->discardDraft($scope);
        }
    }

    // --- internals ----------------------------------------------------------

    /**
     * @return list<array{live: string, draft: string, siteColumn: ?string, columns: list<string>, siteColumnIsBridgeSource?: bool}>
     */
    private function tablePairsForScope(string $scope): array
    {
        return $scope === LockState::SCOPE_GLOBAL
            ? self::SCOPE_TABLES_GLOBAL
            : self::SCOPE_TABLES_PER_SITE;
    }

    /**
     * @param array{live: string, draft: string, siteColumn: ?string, columns: list<string>, siteColumnIsBridgeSource?: bool} $pair
     */
    private function copyLiveToDraft(array $pair, string $scope, int $beUserId, int $now): void
    {
        $conn = $this->connectionPool->getConnectionForTable($pair['live']);
        $qb = $conn->createQueryBuilder();
        $qb->select(...$pair['columns'])->from($pair['live']);
        if ($pair['siteColumn'] !== null && $scope !== LockState::SCOPE_GLOBAL) {
            $siteValue = ($pair['siteColumnIsBridgeSource'] ?? false)
                ? 'simplecmp-' . $scope
                : $scope;
            $qb->where($qb->expr()->eq(
                $pair['siteColumn'],
                $qb->createNamedParameter($siteValue, Connection::PARAM_STR),
            ));
        }
        $rows = $qb->executeQuery()->fetchAllAssociative();
        if ($rows === []) {
            return;
        }
        $draftSite = $scope === LockState::SCOPE_GLOBAL ? '' : $scope;
        $draftConn = $this->connectionPool->getConnectionForTable($pair['draft']);
        foreach ($rows as $row) {
            $row['draft_site'] = $draftSite;
            $row['draft_owner_be_user'] = $beUserId;
            $row['draft_modified_at'] = $now;
            $draftConn->insert($pair['draft'], $row);
        }
    }

    private function countDraftRows(string $draftTable, string $scope): int
    {
        $draftSite = $scope === LockState::SCOPE_GLOBAL ? '' : $scope;
        $qb = $this->connectionPool->getConnectionForTable($draftTable)->createQueryBuilder();
        $count = $qb->count('*')
            ->from($draftTable)
            ->where($qb->expr()->eq('draft_site', $qb->createNamedParameter($draftSite, Connection::PARAM_STR)))
            ->executeQuery()
            ->fetchOne();
        return (int) $count;
    }

    private function deleteDraftRows(string $draftTable, string $scope): void
    {
        $draftSite = $scope === LockState::SCOPE_GLOBAL ? '' : $scope;
        $conn = $this->connectionPool->getConnectionForTable($draftTable);
        $conn->delete(
            $draftTable,
            ['draft_site' => $draftSite],
            ['draft_site' => Connection::PARAM_STR],
        );
    }
}