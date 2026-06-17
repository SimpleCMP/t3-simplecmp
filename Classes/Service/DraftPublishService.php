<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Phase-4 atomic draft → live promotion.
 *
 * For a given scope (global service registry or per-site), the
 * publish operation runs in a single DB transaction:
 *
 *   1. For each scope-relevant table:
 *      DELETE FROM <live>  WHERE (matching scope predicate)
 *      INSERT INTO <live>  SELECT <columns without draft_*> FROM <draft>
 *                          WHERE draft_site = ?
 *      DELETE FROM <draft> WHERE draft_site = ?
 *   2. releaseLock($scope)
 *   3. COMMIT
 *   4. Outside the transaction: trigger ConfigSnapshotListener with
 *      `trigger_event='publish'` for every site whose live content
 *      changed.
 *
 * Atomicity is per-connection. All scope-relevant tables live in the
 * default connection in TYPO3-stock setups; cross-connection deploys
 * would need additional coordination but are out of scope.
 */
final readonly class DraftPublishService
{
    public function __construct(
        private DraftWorkspaceService $workspace,
        private ConfigSnapshotListener $snapshotListener,
        private ConnectionPool $connectionPool,
        private SiteFinder $siteFinder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Promote the draft for $scope to live. No-op if no draft exists.
     */
    public function publish(string $scope, int $beUserId): PublishResult
    {
        if (!$this->workspace->hasDraft($scope)) {
            return new PublishResult(scope: $scope, perTable: [], snapshotHash: null, noOp: true);
        }

        $pairs = $scope === LockState::SCOPE_GLOBAL
            ? DraftWorkspaceService::SCOPE_TABLES_GLOBAL
            : DraftWorkspaceService::SCOPE_TABLES_PER_SITE;

        // Use the connection of the first pair; in practice all
        // banner-config tables share one connection (the default).
        $conn = $this->connectionPool->getConnectionForTable($pairs[0]['live']);
        $perTable = [];

        $conn->beginTransaction();
        try {
            foreach ($pairs as $pair) {
                $perTable[$pair['live']] = $this->promoteTable($pair, $scope);
            }
            $this->workspace->releaseLock($scope);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->logger->error(
                'DraftPublishService rollback: scope={scope} reason={reason}',
                ['scope' => $scope, 'reason' => $e->getMessage()],
            );
            throw $e;
        }

        $hash = $this->triggerSnapshots($scope, $beUserId);

        $this->logger->info(
            'DraftPublishService published: scope={scope} hash={hash} by={beUser}',
            ['scope' => $scope, 'hash' => $hash === null ? '(none)' : substr($hash, 0, 12), 'beUser' => $beUserId],
        );

        return new PublishResult(scope: $scope, perTable: $perTable, snapshotHash: $hash, noOp: false);
    }

    /**
     * Discard a draft + release the lock. Convenience wrapper around
     * the workspace service for symmetry with publish().
     */
    public function discard(string $scope): void
    {
        $this->workspace->discardDraft($scope);
    }

    /**
     * @param array{live: string, draft: string, siteColumn: ?string, columns: list<string>, siteColumnIsBridgeSource?: bool} $pair
     * @return array{deleted: int, inserted: int}
     */
    private function promoteTable(array $pair, string $scope): array
    {
        $liveConn = $this->connectionPool->getConnectionForTable($pair['live']);
        $draftConn = $this->connectionPool->getConnectionForTable($pair['draft']);
        $draftSite = $scope === LockState::SCOPE_GLOBAL ? '' : $scope;

        // DELETE from live with the appropriate scope predicate
        $deleted = $this->deleteLiveScope($pair, $scope, $liveConn);

        // SELECT FROM draft (columns ONLY, no draft_*), INSERT INTO live
        $qb = $draftConn->createQueryBuilder();
        $qb->select(...$pair['columns'])
            ->from($pair['draft'])
            ->where($qb->expr()->eq('draft_site', $qb->createNamedParameter($draftSite, Connection::PARAM_STR)));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $inserted = 0;
        foreach ($rows as $row) {
            $liveConn->insert($pair['live'], $row);
            $inserted++;
        }

        // DELETE from draft
        $draftConn->delete(
            $pair['draft'],
            ['draft_site' => $draftSite],
            ['draft_site' => Connection::PARAM_STR],
        );

        return ['deleted' => $deleted, 'inserted' => $inserted];
    }

    /**
     * @param array{live: string, draft: string, siteColumn: ?string, columns: list<string>, siteColumnIsBridgeSource?: bool} $pair
     */
    private function deleteLiveScope(array $pair, string $scope, Connection $liveConn): int
    {
        if ($pair['siteColumn'] === null || $scope === LockState::SCOPE_GLOBAL) {
            // Global: replace the whole table contents
            return (int) $liveConn->executeStatement('DELETE FROM ' . $pair['live']);
        }
        $siteValue = ($pair['siteColumnIsBridgeSource'] ?? false)
            ? 'simplecmp-' . $scope
            : $scope;
        return $liveConn->delete(
            $pair['live'],
            [$pair['siteColumn'] => $siteValue],
            [$pair['siteColumn'] => Connection::PARAM_STR],
        );
    }

    /**
     * Trigger the audit snapshot after a publish. Returns the
     * resulting version_hash (or null for site-not-found / no
     * change). Global publishes (services) affect every site, so
     * we trigger one snapshot per configured SimpleCMP-set site.
     */
    private function triggerSnapshots(string $scope, int $beUserId): ?string
    {
        if ($scope !== LockState::SCOPE_GLOBAL) {
            return $this->snapshotListener->snapshotIfChanged($scope, 'publish', $beUserId);
        }
        $lastHash = null;
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (!in_array('simplecmp/t3-simplecmp', $site->getSets(), true)) {
                continue;
            }
            $h = $this->snapshotListener->snapshotIfChanged($site->getIdentifier(), 'publish', $beUserId);
            if ($h !== null) {
                $lastHash = $h;
            }
        }
        return $lastHash;
    }
}