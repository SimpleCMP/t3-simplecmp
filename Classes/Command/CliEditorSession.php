<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Service\DraftPublishService;
use SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService;
use SimpleCMP\T3SimpleCmp\Service\LockState;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Draft-session plumbing shared by every writing CLI command.
 *
 * The draft workspace exists to stop an editor from publishing
 * half-finished work; a command invocation has no half-finished state,
 * so the CLI contract is: open the site's unified draft, write, publish,
 * release — one atomic step, unless the caller passes `--no-publish` to
 * stage several commands into one publish.
 *
 * Two deliberate constraints:
 *
 * - **A BE user is required.** The lock column is `owner_be_user`, and
 *   `LockState::isUnlocked()` reads uid 0 as "nobody" — so a lock taken
 *   as 0 would be invisible and two runs could interleave. Requiring a
 *   real uid also keeps the publish attributable: the audit tables
 *   record who published, and "some deploy script" is not an answer a
 *   DSGVO audit accepts. `--be-user` takes a uid or a username.
 * - **A human editor's lock is never stolen.** `initializeDraftForSite()`
 *   reports a conflict when someone else holds the scope; the CLI stops
 *   there instead of taking over. Takeover stays a deliberate BE action
 *   by a person who can see whose work they are interrupting.
 */
final readonly class CliEditorSession
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private DraftWorkspaceService $workspace,
        private DraftPublishService $publisher,
    ) {
    }

    /**
     * Resolve `--be-user` (uid or username) to a uid.
     *
     * @throws \RuntimeException when the user does not exist, is
     *                           disabled/deleted, or is not an admin
     */
    public function resolveBeUser(string $identifier): int
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new \RuntimeException('--be-user is required: pass a BE user uid or username.');
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
        $qb->getRestrictions()->removeAll();
        $qb->select('uid', 'username', 'admin', 'disable', 'deleted')->from('be_users');
        if (ctype_digit($identifier)) {
            $qb->where($qb->expr()->eq('uid', $qb->createNamedParameter((int) $identifier, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)));
        } else {
            $qb->where($qb->expr()->eq('username', $qb->createNamedParameter($identifier)));
        }
        $row = $qb->executeQuery()->fetchAssociative();

        if ($row === false) {
            throw new \RuntimeException(sprintf('No BE user "%s".', $identifier));
        }
        if ((int) $row['deleted'] === 1 || (int) $row['disable'] === 1) {
            throw new \RuntimeException(sprintf('BE user "%s" is disabled or deleted.', $row['username']));
        }
        if ((int) $row['admin'] !== 1) {
            // The BE module is admin-only (see Configuration/Backend/
            // Modules.php); the CLI must not be a way around that.
            throw new \RuntimeException(sprintf('BE user "%s" is not an admin.', $row['username']));
        }
        return (int) $row['uid'];
    }

    /**
     * Open (or reopen) the site's unified draft — global service
     * registry plus the per-site scopes.
     *
     * @throws \RuntimeException on a lock held by someone else
     */
    public function open(string $site, int $beUserId): void
    {
        $lock = $this->workspace->initializeDraftForSite($site, $beUserId);
        if ($lock->conflict) {
            throw new \RuntimeException(sprintf(
                'Draft scope "%s" is locked by BE user uid=%d (since %s). '
                . 'Refusing to take it over — publish or discard that draft in the backend first.',
                $lock->scope,
                $lock->ownerBeUserId,
                date('Y-m-d H:i', $lock->acquiredAt),
            ));
        }
    }

    /**
     * Publish every scope belonging to the site and release the locks.
     *
     * @return array<string, bool> scope => whether rows were promoted
     *                             (false = nothing staged in that scope)
     */
    public function publish(string $site, int $beUserId): array
    {
        $out = [];
        foreach ($this->workspace->relatedScopes($site) as $scope) {
            $result = $this->publisher->publish($scope, $beUserId);
            $out[$scope] = !$result->noOp;
        }
        return $out;
    }

    /**
     * Human-readable draft state for `simplecmp:status`.
     *
     * @return array{open: bool, hasContent: bool, ownerBeUserId: int, conflict: bool}
     */
    public function describe(string $site): array
    {
        $lock = $this->workspace->lockForSite($site);
        return [
            'open' => $this->workspace->isDraftOpenForSite($site),
            'hasContent' => $this->workspace->hasDraftForSite($site),
            'ownerBeUserId' => $lock->ownerBeUserId,
            'conflict' => $lock->conflict,
        ];
    }

    public function globalScope(): string
    {
        return LockState::SCOPE_GLOBAL;
    }
}
