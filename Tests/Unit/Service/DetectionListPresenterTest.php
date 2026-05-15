<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\DetectionRepository;
use WapplerSystems\SimpleCmpTypo3\Service\DetectionListPresenter;

final class DetectionListPresenterTest extends TestCase
{
    // --- decorateConfidence -----------------------------------------------

    #[Test]
    #[TestWith([0, 'bg-warning text-dark', true])]
    #[TestWith([1, 'bg-warning text-dark', true])]
    #[TestWith([2, 'bg-secondary', false])]
    #[TestWith([4, 'bg-secondary', false])]
    #[TestWith([5, 'bg-success', false])]
    #[TestWith([100, 'bg-success', false])]
    public function decorateConfidenceAssignsClassAndLowConfidenceFlag(
        int $occurrences,
        string $expectedClass,
        bool $expectedLowConfidence,
    ): void {
        $row = DetectionListPresenter::decorateConfidence(
            ['occurrences' => $occurrences],
            'CONFIRM_MESSAGE',
        );
        self::assertSame($expectedClass, $row['confidence_class']);
        self::assertSame($expectedLowConfidence, $row['low_confidence']);
    }

    #[Test]
    public function lowConfidenceConfirmIsPopulatedOnlyForLowConfidence(): void
    {
        $low = DetectionListPresenter::decorateConfidence(
            ['occurrences' => 1],
            'verify before curating',
        );
        $high = DetectionListPresenter::decorateConfidence(
            ['occurrences' => 5],
            'verify before curating',
        );
        self::assertSame('verify before curating', $low['low_confidence_confirm']);
        self::assertSame('', $high['low_confidence_confirm']);
    }

    #[Test]
    public function decorateConfidenceTreatsMissingOccurrencesAsZero(): void
    {
        $row = DetectionListPresenter::decorateConfidence([], 'msg');
        self::assertSame('bg-warning text-dark', $row['confidence_class']);
        self::assertTrue($row['low_confidence']);
    }

    #[Test]
    public function decorateConfidencePreservesOtherRowKeys(): void
    {
        $row = DetectionListPresenter::decorateConfidence(
            ['uid' => 42, 'occurrences' => 3, 'identifier' => '_test'],
            'msg',
        );
        self::assertSame(42, $row['uid']);
        self::assertSame('_test', $row['identifier']);
    }

    // --- computeSpikeContext ----------------------------------------------

    #[Test]
    public function spikeAlertOffWhenCountsAreZero(): void
    {
        $ctx = $this->presenterReturning(today: 0, sevenDayTotal: 0)->computeSpikeContext();
        self::assertFalse($ctx['spikeAlert']);
        self::assertSame(0, $ctx['todayCount']);
        self::assertSame(0.0, $ctx['sevenDayAverage']);
    }

    #[Test]
    public function spikeAlertOffWhenUnderAbsoluteFloor(): void
    {
        // 49 today, avg 0.1 — multiplier triggers but absolute doesn't
        $ctx = $this->presenterReturning(today: 49, sevenDayTotal: 1)->computeSpikeContext();
        self::assertFalse($ctx['spikeAlert']);
    }

    #[Test]
    public function spikeAlertOffWhenAbsoluteOkButMultiplierMisses(): void
    {
        // 60 today, avg 10 — absolute trips but 60 > 10*10 is false
        $ctx = $this->presenterReturning(today: 60, sevenDayTotal: 70)->computeSpikeContext();
        self::assertFalse($ctx['spikeAlert']);
        self::assertEqualsWithDelta(10.0, $ctx['sevenDayAverage'], 0.001);
    }

    #[Test]
    public function spikeAlertOnWhenBothThresholdsTrip(): void
    {
        // 60 today, avg 4 — 60 > 50 ✓, 60 > 10*4=40 ✓
        $ctx = $this->presenterReturning(today: 60, sevenDayTotal: 28)->computeSpikeContext();
        self::assertTrue($ctx['spikeAlert']);
    }

    #[Test]
    public function spikeAlertOffAtExactThresholdBoundaries(): void
    {
        // Floor is strict-greater-than: 50 should not trip.
        $ctx = $this->presenterReturning(today: 50, sevenDayTotal: 0)->computeSpikeContext();
        self::assertFalse($ctx['spikeAlert']);
    }

    #[Test]
    public function spikeContextExposesRoundedAverage(): void
    {
        // 7-day total 22 -> avg 3.142857... rounded to 3.1
        $ctx = $this->presenterReturning(today: 0, sevenDayTotal: 22)->computeSpikeContext();
        self::assertSame(3.1, $ctx['sevenDayAverage']);
    }

    private function presenterReturning(int $today, int $sevenDayTotal): DetectionListPresenter
    {
        $repo = $this->createMock(DetectionRepository::class);
        $repo->method('countSince')
            ->willReturnOnConsecutiveCalls($today, $sevenDayTotal);
        return new DetectionListPresenter($repo);
    }
}
