<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * DSGVO-Auskunft / Export bundle (Phase 3) — what {@see VisitorAuskunftService}
 * produces, what the JSON/CSV exporters consume, what the BE Auskunfts-tab
 * renders.
 *
 * `snapshots` and `decisions` are arrays of raw row data (as returned by
 * the respective repositories); the exporters know how to walk them.
 * `filter` describes WHAT was requested so the exported bundle is
 * self-explaining ("this is the export for visitor sha256 X on site Y").
 */
final readonly class AuskunftBundle
{
    /**
     * @param list<array<string, mixed>> $snapshots
     * @param list<array<string, mixed>> $decisions
     * @param array<string, mixed> $filter  Describes the input — e.g.
     *     `['kind' => 'visitor', 'site' => 'default', 'visitorHash' => 'abc…']`,
     *     `['kind' => 'snapshot', 'versionHash' => '1234…']`,
     *     `['kind' => 'dateRange', 'site' => '…', 'since' => 1700000000, 'until' => 1800000000]`.
     */
    public function __construct(
        public array $snapshots,
        public array $decisions,
        public array $filter,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->snapshots === [] && $this->decisions === [];
    }
}
