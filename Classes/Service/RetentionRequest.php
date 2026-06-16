<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Parsed + validated retention parameters. CLI builds one; the
 * {@see AuditRetentionService} consumes it. Holds the operator's
 * intent verbatim — `target`, `keepDays`, `reason`, etc. — plus a
 * pre-computed threshold so the service doesn't depend on wall-clock
 * time once the request is built.
 */
final readonly class RetentionRequest
{
    /**
     * @param non-empty-string $target  one of {@see AuditRetentionService::availableTargets()}
     * @param int<0, max>      $keepDays  rows OLDER than this many days are eligible for deletion
     * @param non-empty-string $reason   mandatory operator-supplied justification
     * @param non-empty-string $invokedBy  'cli' (CLI invocation) or 'be:<userUid>'
     * @param string|null      $site     restrict to a single site identifier, null for all
     * @param bool             $dryRun   true = count only, no DELETE
     * @param int              $now      epoch seconds — caller's "now" reference (CLI: time())
     */
    public function __construct(
        public string $target,
        public int $keepDays,
        public string $reason,
        public string $invokedBy,
        public ?string $site,
        public bool $dryRun,
        public int $now,
    ) {
    }

    /**
     * The cut-off `crdate`: rows with `crdate < thresholdCrdate()` go.
     * Pre-computed so a long-running command sees the same threshold
     * for count + DELETE (otherwise rows that crossed the boundary
     * mid-execution would skew the log entry).
     */
    public function thresholdCrdate(): int
    {
        return $this->now - ($this->keepDays * 86400);
    }
}
