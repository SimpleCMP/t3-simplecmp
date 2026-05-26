<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Persisted counters + timestamps for upstream library calls.
 *
 * Backs the daily-budget enforcement in {@see LibraryUpstreamClient}
 * and the BE indicator panel on the Bibliothek tab. Stats live in
 * `sys_registry` (namespace `tx_t3simplecmp`, key
 * `library_upstream_stats`) so no schema migration is needed and
 * there's no race window on table creation.
 *
 * Storage shape:
 * ```
 * [
 *   'today_date'        => 20260526,  // YYYYMMDD, UTC
 *   'today_calls'       => 17,        // resets when today_date rolls
 *   'today_successes'   => 16,
 *   'today_failures'    => 1,
 *   'last_call_at'      => <unix ts>,
 *   'last_success_at'   => <unix ts>,
 *   'last_failure_at'   => <unix ts>,
 *   'total_calls'       => 1234,      // lifetime
 *   'total_successes'   => 1180,
 *   'total_failures'    => 54,
 * ]
 * ```
 *
 * Concurrency: sys_registry has no atomic increment, so two parallel
 * cache misses can race and lose an increment. Acceptable for a
 * quota-style counter — the daily budget gate is "soft" by design
 * (the goal is to cap order-of-magnitude usage, not enforce exact
 * counts). The 3-second HTTP timeout already serialises most traffic
 * naturally.
 */
final class LibraryUpstreamStats implements SingletonInterface
{
    private const string REGISTRY_NAMESPACE = 'tx_t3simplecmp';
    private const string REGISTRY_KEY = 'library_upstream_stats';

    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    /**
     * Return the number of upstream calls recorded today. Day boundary
     * uses UTC so it matches the upstream's GDPR-zero-log posture
     * (no admin's local clock to wrangle).
     */
    public function getTodayCalls(int $now): int
    {
        $stats = $this->load();
        if (($stats['today_date'] ?? 0) !== $this->today($now)) {
            return 0;
        }
        return (int) ($stats['today_calls'] ?? 0);
    }

    /**
     * Record one upstream call. Increments today's + lifetime
     * counters, updates last-call / last-success / last-failure
     * timestamps. Rolls today's counters when the UTC day changes.
     */
    public function recordCall(bool $success, int $now): void
    {
        $stats = $this->load();
        $today = $this->today($now);

        if (($stats['today_date'] ?? null) !== $today) {
            $stats['today_date'] = $today;
            $stats['today_calls'] = 0;
            $stats['today_successes'] = 0;
            $stats['today_failures'] = 0;
        }

        $stats['today_calls'] = ((int) ($stats['today_calls'] ?? 0)) + 1;
        $stats['total_calls'] = ((int) ($stats['total_calls'] ?? 0)) + 1;
        $stats['last_call_at'] = $now;

        if ($success) {
            $stats['today_successes'] = ((int) ($stats['today_successes'] ?? 0)) + 1;
            $stats['total_successes'] = ((int) ($stats['total_successes'] ?? 0)) + 1;
            $stats['last_success_at'] = $now;
        } else {
            $stats['today_failures'] = ((int) ($stats['today_failures'] ?? 0)) + 1;
            $stats['total_failures'] = ((int) ($stats['total_failures'] ?? 0)) + 1;
            $stats['last_failure_at'] = $now;
        }

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $stats);
    }

    /**
     * Read-only snapshot for the BE indicator. Today's counters are
     * normalised to 0 when the persisted day is stale (so the panel
     * doesn't show yesterday's count after a quiet morning).
     *
     * @return array{
     *     today_date: int,
     *     today_calls: int,
     *     today_successes: int,
     *     today_failures: int,
     *     last_call_at: int|null,
     *     last_success_at: int|null,
     *     last_failure_at: int|null,
     *     total_calls: int,
     *     total_successes: int,
     *     total_failures: int,
     * }
     */
    public function getSnapshot(int $now): array
    {
        $stats = $this->load();
        $today = $this->today($now);
        $isToday = ($stats['today_date'] ?? null) === $today;

        return [
            'today_date' => $today,
            'today_calls' => $isToday ? (int) ($stats['today_calls'] ?? 0) : 0,
            'today_successes' => $isToday ? (int) ($stats['today_successes'] ?? 0) : 0,
            'today_failures' => $isToday ? (int) ($stats['today_failures'] ?? 0) : 0,
            'last_call_at' => isset($stats['last_call_at']) ? (int) $stats['last_call_at'] : null,
            'last_success_at' => isset($stats['last_success_at']) ? (int) $stats['last_success_at'] : null,
            'last_failure_at' => isset($stats['last_failure_at']) ? (int) $stats['last_failure_at'] : null,
            'total_calls' => (int) ($stats['total_calls'] ?? 0),
            'total_successes' => (int) ($stats['total_successes'] ?? 0),
            'total_failures' => (int) ($stats['total_failures'] ?? 0),
        ];
    }

    /**
     * Wipe all stats. Used by tests and could be wired to an admin
     * action later. Lifetime counters reset too.
     */
    public function reset(): void
    {
        $this->registry->remove(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $stats = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, []);
        return is_array($stats) ? $stats : [];
    }

    private function today(int $now): int
    {
        return (int) gmdate('Ymd', $now);
    }
}
