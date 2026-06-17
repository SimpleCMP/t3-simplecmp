<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Domain\Repository\PublishLockRepository;
use SimpleCMP\T3SimpleCmp\Service\ClockInterface;
use SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService;
use SimpleCMP\T3SimpleCmp\Service\LockState;

/**
 * Lock-only tests for DraftWorkspaceService (Phase 4 step P4.1). The
 * Copy-on-Write surface lands in P4.2 once the draft tables exist and
 * is tested separately by DraftWorkspaceServiceCopyTest (P4.2).
 *
 * Repository is mocked — atomic-write semantics live in a functional
 * test where MySQL actually enforces the UNIQUE constraint.
 */
final class DraftWorkspaceServiceLockTest extends TestCase
{
    public const int FROZEN_NOW = 1_700_000_000;
    public const string SCOPE = 'default';

    #[Test]
    public function acquireLockOnEmptyScopeReturnsOwnedLock(): void
    {
        $repo = $this->createMock(PublishLockRepository::class);
        $repo->expects(self::once())
            ->method('acquire')
            ->with(self::SCOPE, 42, self::FROZEN_NOW)
            ->willReturn(new LockState(
                scope: self::SCOPE,
                ownerBeUserId: 42,
                acquiredAt: self::FROZEN_NOW,
                lastActivityAt: self::FROZEN_NOW,
                conflict: false,
            ));
        $service = new DraftWorkspaceService($repo, $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class), $this->frozenClock());

        $lock = $service->acquireLock(self::SCOPE, 42);

        self::assertSame(42, $lock->ownerBeUserId);
        self::assertFalse($lock->conflict);
        self::assertTrue($lock->isOwnedBy(42));
    }

    #[Test]
    public function acquireLockOnContestedScopeReturnsConflictLock(): void
    {
        $repo = $this->createMock(PublishLockRepository::class);
        $repo->method('acquire')
            ->willReturn(new LockState(
                scope: self::SCOPE,
                ownerBeUserId: 100,           // a different editor holds it
                acquiredAt: self::FROZEN_NOW - 600,
                lastActivityAt: self::FROZEN_NOW - 60,
                conflict: true,
            ));
        $service = new DraftWorkspaceService($repo, $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class), $this->frozenClock());

        $lock = $service->acquireLock(self::SCOPE, 42);

        self::assertSame(100, $lock->ownerBeUserId);
        self::assertTrue($lock->conflict);
        self::assertFalse($lock->isOwnedBy(42));
        self::assertTrue($lock->isOwnedBy(100));
    }

    #[Test]
    public function acquireLockRejectsZeroOrNegativeUserId(): void
    {
        $repo = $this->createMock(PublishLockRepository::class);
        $repo->expects(self::never())->method('acquire');
        $service = new DraftWorkspaceService($repo, $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class), $this->frozenClock());

        $this->expectException(\InvalidArgumentException::class);
        $service->acquireLock(self::SCOPE, 0);
    }

    #[Test]
    public function takeoverLockReplacesOwnerEvenIfNoPriorLock(): void
    {
        $repo = $this->createMock(PublishLockRepository::class);
        $repo->expects(self::once())
            ->method('takeover')
            ->with(self::SCOPE, 7, self::FROZEN_NOW)
            ->willReturn(new LockState(
                scope: self::SCOPE,
                ownerBeUserId: 7,
                acquiredAt: self::FROZEN_NOW,
                lastActivityAt: self::FROZEN_NOW,
                conflict: false,
            ));
        $service = new DraftWorkspaceService($repo, $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class), $this->frozenClock());

        $lock = $service->takeoverLock(self::SCOPE, 7);

        self::assertSame(7, $lock->ownerBeUserId);
        self::assertFalse($lock->conflict);
    }

    #[Test]
    public function takeoverLockRejectsZeroOrNegativeUserId(): void
    {
        $repo = $this->createMock(PublishLockRepository::class);
        $repo->expects(self::never())->method('takeover');
        $service = new DraftWorkspaceService($repo, $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class), $this->frozenClock());

        $this->expectException(\InvalidArgumentException::class);
        $service->takeoverLock(self::SCOPE, -1);
    }

    #[Test]
    public function releaseLockDelegatesToRepository(): void
    {
        $repo = $this->createMock(PublishLockRepository::class);
        $repo->expects(self::once())->method('release')->with(self::SCOPE);
        $service = new DraftWorkspaceService($repo, $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class), $this->frozenClock());

        $service->releaseLock(self::SCOPE);
    }

    #[Test]
    public function currentLockReturnsUnlockedWhenRepositoryHasNoRow(): void
    {
        $repo = $this->createMock(PublishLockRepository::class);
        $repo->method('find')->willReturn(null);
        $service = new DraftWorkspaceService($repo, $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class), $this->frozenClock());

        $lock = $service->currentLock(self::SCOPE);

        self::assertTrue($lock->isUnlocked());
        self::assertSame(self::SCOPE, $lock->scope);
    }

    #[Test]
    public function touchLockPassesCurrentTimeToRepository(): void
    {
        $repo = $this->createMock(PublishLockRepository::class);
        $repo->expects(self::once())->method('touch')->with(self::SCOPE, self::FROZEN_NOW);
        $service = new DraftWorkspaceService($repo, $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class), $this->frozenClock());

        $service->touchLock(self::SCOPE);
    }

    #[Test]
    public function allActiveLocksDelegatesToRepository(): void
    {
        $locks = [
            new LockState('default', 1, 100, 200, false),
            new LockState(LockState::SCOPE_GLOBAL, 1, 50, 250, false),
        ];
        $repo = $this->createMock(PublishLockRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn($locks);
        $service = new DraftWorkspaceService($repo, $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class), $this->frozenClock());

        $result = $service->allActiveLocks();

        self::assertCount(2, $result);
        self::assertSame('default', $result[0]->scope);
    }

    // Copy-on-Write coverage lives in the functional test
    // `DraftWorkspaceServiceCopyTest` — needs an actual DB to exercise
    // the live→draft INSERT path.

    private function frozenClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): int
            {
                return DraftWorkspaceServiceLockTest::FROZEN_NOW;
            }
        };
    }
}