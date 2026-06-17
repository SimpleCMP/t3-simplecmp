<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Outcome of {@see DraftPublishService::publish()}. Carries one
 * sub-result per scope-relevant table so the BE can render a
 * meaningful "x rows of Y promoted" summary.
 */
final readonly class PublishResult
{
    /**
     * @param array<string, array{deleted: int, inserted: int}> $perTable
     */
    public function __construct(
        public string $scope,
        public array $perTable,
        public ?string $snapshotHash,
        public bool $noOp,
    ) {
    }

    public function totalChanged(): int
    {
        $sum = 0;
        foreach ($this->perTable as $row) {
            $sum += max($row['inserted'], $row['deleted']);
        }
        return $sum;
    }
}