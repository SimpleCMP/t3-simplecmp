<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use SimpleCMP\T3SimpleCmp\Service\LockState;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Repository for `tx_t3simplecmp_publish_lock` (Phase 4 draft/publish workspace).
 *
 * Concurrency model: UNIQUE on `scope` is the primary defence. The
 * INSERT-and-catch-collision pattern (mirroring Phase 1/2 audit
 * repositories) makes the acquire operation race-free without
 * row-locking — two simultaneous "acquire" callers both attempt the
 * INSERT, one wins, the other catches and reads the winner's row.
 *
 * `releaseLock` is intentionally idempotent — calling it on a scope
 * that is already unlocked is a no-op rather than an error.
 */
final readonly class PublishLockRepository
{
    public const string TABLE = 'tx_t3simplecmp_publish_lock';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    public function find(string $scope): ?LockState
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }
        return new LockState(
            scope: (string) $row['scope'],
            ownerBeUserId: (int) $row['owner_be_user'],
            acquiredAt: (int) $row['acquired_at'],
            lastActivityAt: (int) $row['last_activity_at'],
            conflict: false,
        );
    }

    /**
     * Try to acquire the lock for $scope on behalf of $beUserId.
     * Race-safe: INSERT first, on UNIQUE collision read the winner.
     *
     * Returns the resulting `LockState` — either freshly acquired by
     * the caller, or the existing lock by some other (or the same)
     * user. The caller derives ownership from `isOwnedBy($beUserId)`.
     */
    public function acquire(string $scope, int $beUserId, int $now): LockState
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        try {
            $conn->insert(self::TABLE, [
                'scope' => $scope,
                'owner_be_user' => $beUserId,
                'acquired_at' => $now,
                'last_activity_at' => $now,
                'crdate' => $now,
            ], [
                'scope' => Connection::PARAM_STR,
                'owner_be_user' => Connection::PARAM_INT,
                'acquired_at' => Connection::PARAM_INT,
                'last_activity_at' => Connection::PARAM_INT,
                'crdate' => Connection::PARAM_INT,
            ]);
            return new LockState(
                scope: $scope,
                ownerBeUserId: $beUserId,
                acquiredAt: $now,
                lastActivityAt: $now,
                conflict: false,
            );
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $existing = $this->find($scope) ?? LockState::unlocked($scope);
            if ($existing->isOwnedBy($beUserId)) {
                $this->touch($scope, $now);
                return new LockState(
                    scope: $existing->scope,
                    ownerBeUserId: $existing->ownerBeUserId,
                    acquiredAt: $existing->acquiredAt,
                    lastActivityAt: $now,
                    conflict: false,
                );
            }
            return new LockState(
                scope: $existing->scope,
                ownerBeUserId: $existing->ownerBeUserId,
                acquiredAt: $existing->acquiredAt,
                lastActivityAt: $existing->lastActivityAt,
                conflict: true,
            );
        }
    }

    /**
     * Drop the lock row. Idempotent — silently does nothing if no row
     * exists for the scope.
     */
    public function release(string $scope): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->delete(self::TABLE, ['scope' => $scope], ['scope' => Connection::PARAM_STR]);
    }

    /**
     * Atomically reassign the lock to a new user. Replaces ownership
     * regardless of who held it before. Used by the take-over flow
     * after the caller has confirmed the takeover via UI.
     */
    public function takeover(string $scope, int $newOwnerBeUserId, int $now): LockState
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $affected = $conn->update(
            self::TABLE,
            [
                'owner_be_user' => $newOwnerBeUserId,
                'acquired_at' => $now,
                'last_activity_at' => $now,
            ],
            ['scope' => $scope],
            [
                'owner_be_user' => Connection::PARAM_INT,
                'acquired_at' => Connection::PARAM_INT,
                'last_activity_at' => Connection::PARAM_INT,
                'scope' => Connection::PARAM_STR,
            ],
        );
        if ($affected === 0) {
            // No row to take over — acquire fresh instead.
            return $this->acquire($scope, $newOwnerBeUserId, $now);
        }
        return new LockState(
            scope: $scope,
            ownerBeUserId: $newOwnerBeUserId,
            acquiredAt: $now,
            lastActivityAt: $now,
            conflict: false,
        );
    }

    /**
     * Bump `last_activity_at` without changing ownership. Called on
     * every draft write so abandoned drafts can be identified later
     * (Phase-4.1 auto-timeout feature, out of scope for v1).
     */
    public function touch(string $scope, int $now): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->update(
            self::TABLE,
            ['last_activity_at' => $now],
            ['scope' => $scope],
            [
                'last_activity_at' => Connection::PARAM_INT,
                'scope' => Connection::PARAM_STR,
            ],
        );
    }

    /**
     * Snapshot of every lock currently held. Used by the BE diagnostics
     * tab to show "Editor X is working on Site Y since timestamp Z".
     *
     * @return list<LockState>
     */
    public function findAll(): array
    {
        $qb = $this->connectionPool->getConnectionForTable(self::TABLE)->createQueryBuilder();
        $rows = $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('last_activity_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(
            static fn (array $row) => new LockState(
                scope: (string) $row['scope'],
                ownerBeUserId: (int) $row['owner_be_user'],
                acquiredAt: (int) $row['acquired_at'],
                lastActivityAt: (int) $row['last_activity_at'],
                conflict: false,
            ),
            array_values($rows),
        );
    }
}