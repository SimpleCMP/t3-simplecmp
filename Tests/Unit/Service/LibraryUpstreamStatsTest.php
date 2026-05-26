<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamStats;
use TYPO3\CMS\Core\Registry;

/**
 * Locks the LibraryUpstreamStats behavior:
 *
 *   - Empty registry → today_calls = 0, snapshot has zero counters
 *   - recordCall(true)  → increments today_calls + today_successes + total_calls + total_successes
 *   - recordCall(false) → increments today_calls + today_failures + total_calls + total_failures
 *   - last_call_at / last_success_at / last_failure_at update to the
 *     passed `$now`
 *   - When the UTC day rolls, today's per-day counters reset to 0 but
 *     lifetime totals keep climbing
 *   - reset() wipes everything
 */
final class LibraryUpstreamStatsTest extends TestCase
{
    private InMemoryRegistry $registry;
    private LibraryUpstreamStats $stats;

    protected function setUp(): void
    {
        $this->registry = new InMemoryRegistry();
        $this->stats = new LibraryUpstreamStats($this->registry);
    }

    #[Test]
    public function emptyStatsReturnsZeroes(): void
    {
        $now = $this->ts('2026-05-26 12:00:00');
        self::assertSame(0, $this->stats->getTodayCalls($now));
        $snap = $this->stats->getSnapshot($now);
        self::assertSame(0, $snap['today_calls']);
        self::assertSame(0, $snap['today_successes']);
        self::assertSame(0, $snap['today_failures']);
        self::assertSame(0, $snap['total_calls']);
        self::assertNull($snap['last_call_at']);
    }

    #[Test]
    public function recordCallSuccessIncrementsCounters(): void
    {
        $now = $this->ts('2026-05-26 12:00:00');
        $this->stats->recordCall(true, $now);
        $this->stats->recordCall(true, $now + 1);

        self::assertSame(2, $this->stats->getTodayCalls($now));
        $snap = $this->stats->getSnapshot($now);
        self::assertSame(2, $snap['today_calls']);
        self::assertSame(2, $snap['today_successes']);
        self::assertSame(0, $snap['today_failures']);
        self::assertSame(2, $snap['total_calls']);
        self::assertSame(2, $snap['total_successes']);
        self::assertSame($now + 1, $snap['last_call_at']);
        self::assertSame($now + 1, $snap['last_success_at']);
        self::assertNull($snap['last_failure_at']);
    }

    #[Test]
    public function recordCallFailureIncrementsCounters(): void
    {
        $now = $this->ts('2026-05-26 12:00:00');
        $this->stats->recordCall(false, $now);

        $snap = $this->stats->getSnapshot($now);
        self::assertSame(1, $snap['today_calls']);
        self::assertSame(0, $snap['today_successes']);
        self::assertSame(1, $snap['today_failures']);
        self::assertSame(1, $snap['total_calls']);
        self::assertSame(1, $snap['total_failures']);
        self::assertSame($now, $snap['last_call_at']);
        self::assertNull($snap['last_success_at']);
        self::assertSame($now, $snap['last_failure_at']);
    }

    #[Test]
    public function dayRollResetsTodayCountersButKeepsLifetime(): void
    {
        $day1 = $this->ts('2026-05-26 23:59:00');
        $day2 = $this->ts('2026-05-27 00:01:00');

        $this->stats->recordCall(true, $day1);
        $this->stats->recordCall(false, $day1);
        $this->stats->recordCall(true, $day1);

        // Mid-day-1 sanity: 3 calls.
        self::assertSame(3, $this->stats->getTodayCalls($day1));

        // Asking on day 2 with no new calls: today_calls = 0 (stale day).
        self::assertSame(0, $this->stats->getTodayCalls($day2));

        // Recording on day 2 should reset per-day and keep lifetime.
        $this->stats->recordCall(true, $day2);
        $snap = $this->stats->getSnapshot($day2);
        self::assertSame(1, $snap['today_calls']);
        self::assertSame(1, $snap['today_successes']);
        self::assertSame(0, $snap['today_failures']);
        self::assertSame(4, $snap['total_calls']);
        self::assertSame(3, $snap['total_successes']);
        self::assertSame(1, $snap['total_failures']);
    }

    #[Test]
    public function snapshotZerosTodayCountersWhenDayIsStale(): void
    {
        $day1 = $this->ts('2026-05-26 12:00:00');
        $day2 = $this->ts('2026-05-27 12:00:00');

        $this->stats->recordCall(true, $day1);
        $this->stats->recordCall(true, $day1);

        // Asking on day 2 without recording anything: today resets to 0.
        $snap = $this->stats->getSnapshot($day2);
        self::assertSame(0, $snap['today_calls']);
        self::assertSame(0, $snap['today_successes']);
        // Lifetime counters survive.
        self::assertSame(2, $snap['total_calls']);
        // Last-call-at is from yesterday — surfaced verbatim so the BE
        // indicator can show "last call: 18h ago" rather than blanking.
        self::assertSame($day1, $snap['last_call_at']);
        self::assertSame($day1, $snap['last_success_at']);
    }

    #[Test]
    public function dayBoundaryUsesUtc(): void
    {
        // The UTC day for "2026-05-26 23:30 UTC" is 20260526. A timestamp
        // 31 minutes later is still 2026-05-27 in UTC. We don't care
        // about admin's local clock for budget rollovers; the upstream
        // doesn't either.
        $lateOnDay1 = $this->ts('2026-05-26 23:30:00');
        $earlyOnDay2 = $this->ts('2026-05-27 00:01:00');

        $this->stats->recordCall(true, $lateOnDay1);
        self::assertSame(1, $this->stats->getTodayCalls($lateOnDay1));
        self::assertSame(0, $this->stats->getTodayCalls($earlyOnDay2));
    }

    #[Test]
    public function resetWipesEverything(): void
    {
        $now = $this->ts('2026-05-26 12:00:00');
        $this->stats->recordCall(true, $now);
        $this->stats->recordCall(false, $now);

        $this->stats->reset();

        $snap = $this->stats->getSnapshot($now);
        self::assertSame(0, $snap['today_calls']);
        self::assertSame(0, $snap['total_calls']);
        self::assertNull($snap['last_call_at']);
        self::assertNull($snap['last_success_at']);
        self::assertNull($snap['last_failure_at']);
    }

    private function ts(string $datetimeUtc): int
    {
        return (new \DateTimeImmutable($datetimeUtc, new \DateTimeZone('UTC')))->getTimestamp();
    }
}

/**
 * Minimal in-memory Registry double. The TYPO3 Registry class is final-
 * adjacent (concrete singleton with DB-backed entries) so we substitute
 * a tiny shim that mirrors the get / set / remove API surface used by
 * LibraryUpstreamStats.
 */
final class InMemoryRegistry extends Registry
{
    /** @var array<string, array<string, mixed>> */
    private array $store = [];

    public function __construct()
    {
        // Skip parent constructor — it touches the DB.
    }

    public function get($namespace, $key, $defaultValue = null)
    {
        return $this->store[$namespace][$key] ?? $defaultValue;
    }

    public function set($namespace, $key, $value): void
    {
        $this->store[$namespace][$key] = $value;
    }

    public function remove($namespace, $key): void
    {
        unset($this->store[$namespace][$key]);
    }
}
