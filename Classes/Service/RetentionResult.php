<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Outcome of one {@see AuditRetentionService::apply()} call. CLI uses
 * this to render the result line; tests assert against it.
 */
final readonly class RetentionResult
{
    public function __construct(
        public string $target,
        public string $table,
        public ?string $site,
        public int $matched,          // how many rows fell under the threshold
        public int $deleted,          // how many DELETE returned (0 on dry-run)
        public int $keepDays,
        public int $oldestKeptCrdate, // crdate of the oldest row NOT deleted (0 = empty after)
        public bool $dryRun,
        public int $logUid,           // uid of the retention-log row this call wrote
    ) {
    }

    public function summary(): string
    {
        $prefix = $this->dryRun ? '[dry-run] would delete' : 'Deleted';
        $sitePart = ($this->site !== null && $this->site !== '') ? ' on site "' . $this->site . '"' : '';
        return sprintf(
            '%s %d row(s) from %s%s (older than %d days). Log entry uid=%d.',
            $prefix,
            $this->matched,
            $this->table,
            $sitePart,
            $this->keepDays,
            $this->logUid,
        );
    }
}
