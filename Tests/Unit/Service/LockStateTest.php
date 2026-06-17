<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\LockState;

/**
 * Pure-DTO behavior lock for {@see LockState}. Predicates are tiny but
 * load-bearing — the controller-routing decision (conflict view vs.
 * normal edit view) depends on them.
 */
final class LockStateTest extends TestCase
{
    #[Test]
    public function unlockedFactoryReturnsZeroOwner(): void
    {
        $lock = LockState::unlocked('default');
        self::assertTrue($lock->isUnlocked());
        self::assertFalse($lock->isOwnedBy(1));
        self::assertFalse($lock->isOwnedBy(0));
        self::assertSame('default', $lock->scope);
    }

    #[Test]
    public function isOwnedByRequiresMatchingPositiveId(): void
    {
        $lock = new LockState('default', 42, 100, 200, false);
        self::assertTrue($lock->isOwnedBy(42));
        self::assertFalse($lock->isOwnedBy(43));
        self::assertFalse($lock->isOwnedBy(0));
    }

    #[Test]
    public function isOwnedByRejectsZeroEvenIfOwnerIsZero(): void
    {
        // An "unlocked" state has ownerBeUserId=0; isOwnedBy(0) must
        // return false to prevent the "anonymous matches anonymous"
        // edge case slipping through as ownership.
        $lock = LockState::unlocked('default');
        self::assertFalse($lock->isOwnedBy(0));
    }

    #[Test]
    public function globalScopeConstantIsSentinel(): void
    {
        self::assertSame('__global__', LockState::SCOPE_GLOBAL);
    }
}