<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Snapshot of the publish-lock state for a single scope (Phase 4).
 *
 * Returned by {@see DraftWorkspaceService::currentLock()} +
 * {@see DraftWorkspaceService::acquireLock()}. Carries the current
 * lock-holder + timestamps + whether the requesting user can edit
 * (i.e. owns the lock) or must take it over.
 *
 * `conflict` is true when the lock exists AND is held by a DIFFERENT
 * user than the requesting one. The controller decides what to do
 * (FlashMessage + Takeover-Button vs proceed normally).
 */
final readonly class LockState
{
    public const string SCOPE_GLOBAL = '__global__';

    public function __construct(
        public string $scope,
        public int $ownerBeUserId,
        public int $acquiredAt,
        public int $lastActivityAt,
        public bool $conflict,
    ) {
    }

    public static function unlocked(string $scope): self
    {
        return new self(
            scope: $scope,
            ownerBeUserId: 0,
            acquiredAt: 0,
            lastActivityAt: 0,
            conflict: false,
        );
    }

    public function isUnlocked(): bool
    {
        return $this->ownerBeUserId === 0;
    }

    public function isOwnedBy(int $beUserId): bool
    {
        return $this->ownerBeUserId === $beUserId && $beUserId > 0;
    }
}