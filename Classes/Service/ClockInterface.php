<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Trivial epoch-seconds clock — injected wherever Phase-4 logic depends
 * on "now" so unit tests can freeze time. PSR-20 was considered but
 * adds a Composer dependency for one trivial primitive.
 */
interface ClockInterface
{
    public function now(): int;
}