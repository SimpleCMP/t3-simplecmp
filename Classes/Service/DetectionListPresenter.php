<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Service;

use WapplerSystems\SimpleCmpTypo3\Domain\Repository\DetectionRepository;

/**
 * View-layer helpers for the detection list BE module.
 *
 * Pulled out of `DetectionReviewController` so the threshold math
 * and per-row badge logic are testable without an Extbase harness.
 * The controller injects this and delegates; thresholds and the
 * tiered-badge classes live here as the single source of truth.
 */
final readonly class DetectionListPresenter
{
    /**
     * Spike alert trips when **both** conditions are true: today's
     * count exceeds an absolute floor AND exceeds N× the 7-day avg.
     * The floor avoids day-one false positives on a fresh install
     * where "any" detection is a relative spike.
     */
    private const int SPIKE_MIN_ABSOLUTE = 50;
    private const int SPIKE_MULTIPLIER = 10;

    public function __construct(
        private DetectionRepository $detectionRepository,
    ) {
    }

    /**
     * Add confidence-tier metadata to a detection row. Stateless and
     * pure — the only "input" is the row's `occurrences` count.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function decorateConfidence(array $row, string $lowConfidenceMessage): array
    {
        $occurrences = (int) ($row['occurrences'] ?? 0);
        $row['confidence_class'] = match (true) {
            $occurrences >= 5 => 'bg-success',
            $occurrences >= 2 => 'bg-secondary',
            default => 'bg-warning text-dark',
        };
        $row['low_confidence'] = $occurrences <= 1;
        $row['low_confidence_confirm'] = $row['low_confidence'] ? $lowConfidenceMessage : '';
        return $row;
    }

    /**
     * @return array{spikeAlert: bool, todayCount: int, sevenDayAverage: float}
     */
    public function computeSpikeContext(): array
    {
        $todayStart = mktime(0, 0, 0) ?: time();
        $todayCount = $this->detectionRepository->countSince($todayStart);
        $sevenDayTotal = $this->detectionRepository->countSince(time() - 7 * 86400);
        $sevenDayAverage = $sevenDayTotal / 7;
        $alert = $todayCount > self::SPIKE_MIN_ABSOLUTE
            && $todayCount > self::SPIKE_MULTIPLIER * $sevenDayAverage;
        return [
            'spikeAlert' => $alert,
            'todayCount' => $todayCount,
            'sevenDayAverage' => round($sevenDayAverage, 1),
        ];
    }
}
