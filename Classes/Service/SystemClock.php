<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Production-time clock — wraps PHP `time()`. Tests inject a
 * {@see FrozenClock} or similar from their own test util.
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return time();
    }
}