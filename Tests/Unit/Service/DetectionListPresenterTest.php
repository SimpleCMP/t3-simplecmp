<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Service\DetectionListPresenter;

final class DetectionListPresenterTest extends TestCase
{
    // --- decorateConfidence -----------------------------------------------

    #[Test]
    #[TestWith([0, 'bg-warning text-dark'])]
    #[TestWith([1, 'bg-warning text-dark'])]
    #[TestWith([2, 'bg-secondary'])]
    #[TestWith([4, 'bg-secondary'])]
    #[TestWith([5, 'bg-success'])]
    #[TestWith([100, 'bg-success'])]
    public function decorateConfidenceAssignsClassByOccurrences(
        int $occurrences,
        string $expectedClass,
    ): void {
        $row = DetectionListPresenter::decorateConfidence(['occurrences' => $occurrences]);
        self::assertSame($expectedClass, $row['confidence_class']);
    }

    #[Test]
    public function decorateConfidenceTreatsMissingOccurrencesAsZero(): void
    {
        $row = DetectionListPresenter::decorateConfidence([]);
        self::assertSame('bg-warning text-dark', $row['confidence_class']);
    }

    #[Test]
    public function decorateConfidencePreservesOtherRowKeys(): void
    {
        $row = DetectionListPresenter::decorateConfidence(
            ['uid' => 42, 'occurrences' => 3, 'identifier' => '_test'],
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
        return new DetectionListPresenter(
            $repo,
            $this->createMock(ServiceRepository::class),
            $this->createMock(\SimpleCMP\T3SimpleCmp\Domain\Repository\LibraryCacheRepository::class),
        );
    }

    // --- deriveState / decorateState --------------------------------------

    /**
     * @return array<array<string, mixed>>
     */
    private function services(): array
    {
        return [[
            'id' => 'curated-service',
            'name' => 'Curated Service',
            'matches' => [
                'cookies' => ['_curated_*'],
                'origins' => ['api.curated.example'],
            ],
        ]];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function library(): array
    {
        return [[
            'id' => 'amplitude',
            'name' => 'Amplitude',
            'matches' => [
                'cookies' => ['/^amplitude_/'],
                'origins' => ['cdn.amplitude.com'],
            ],
        ]];
    }

    #[Test]
    public function deriveStateReturnsCuratedWhenRegistryCoversCookie(): void
    {
        $result = DetectionListPresenter::deriveState(
            ['kind' => 'cookie', 'identifier' => '_curated_*'],
            $this->services(),
            $this->library(),
        );
        self::assertSame(DetectionListPresenter::STATE_CURATED, $result['state']);
        self::assertSame('curated-service', $result['match']['id']);
    }

    #[Test]
    public function deriveStateReturnsRecognizedWhenOnlyLibraryMatchesCookie(): void
    {
        $result = DetectionListPresenter::deriveState(
            ['kind' => 'cookie', 'identifier' => 'amplitude_session'],
            $this->services(),
            $this->library(),
        );
        self::assertSame(DetectionListPresenter::STATE_RECOGNIZED, $result['state']);
        self::assertSame('Amplitude', $result['match']['name']);
    }

    #[Test]
    public function deriveStateReturnsRecognizedWhenOnlyLibraryMatchesOrigin(): void
    {
        $result = DetectionListPresenter::deriveState(
            ['kind' => 'script', 'identifier' => 'x', 'origin' => 'cdn.amplitude.com'],
            $this->services(),
            $this->library(),
        );
        self::assertSame(DetectionListPresenter::STATE_RECOGNIZED, $result['state']);
    }

    #[Test]
    public function deriveStateReturnsUnknownWhenNeitherMatches(): void
    {
        $result = DetectionListPresenter::deriveState(
            ['kind' => 'cookie', 'identifier' => 'totally_mystery_cookie'],
            $this->services(),
            $this->library(),
        );
        self::assertSame(DetectionListPresenter::STATE_UNKNOWN, $result['state']);
        self::assertNull($result['match']);
    }

    #[Test]
    public function upstreamCacheTierLiftsUnknownToRecognized(): void
    {
        // Registry empty (uses services() default), library doesn't cover
        // this cookie. The upstream cache map carries one entry keyed
        // `cookie:_brand_new_2026` — deriveState must consult it after
        // registry + library miss.
        $upstreamCache = [
            'cookie:_brand_new_2026' => [
                ['id' => 'brand-new-2026', 'name' => 'Brand New 2026'],
            ],
        ];
        $result = DetectionListPresenter::deriveState(
            ['kind' => 'cookie', 'identifier' => '_brand_new_2026'],
            $this->services(),
            $this->library(),
            $upstreamCache,
        );
        self::assertSame(DetectionListPresenter::STATE_RECOGNIZED, $result['state']);
        self::assertSame('brand-new-2026', $result['match']['id']);
    }

    #[Test]
    public function upstreamCacheSkippedWhenRegistryOrLibraryAlreadyMatched(): void
    {
        // Registry covers exact name '_curated_*' (from services()) — the
        // upstream-cache map for the same key MUST be ignored so the
        // curated state stands.
        $upstreamCache = [
            'cookie:_curated_*' => [
                ['id' => 'should-not-win', 'name' => 'Should not surface'],
            ],
        ];
        $result = DetectionListPresenter::deriveState(
            ['kind' => 'cookie', 'identifier' => '_curated_*'],
            $this->services(),
            $this->library(),
            $upstreamCache,
        );
        self::assertSame(DetectionListPresenter::STATE_CURATED, $result['state']);
        self::assertSame('curated-service', $result['match']['id']);
    }

    #[Test]
    public function registryMatchTakesPrecedenceOverLibraryMatch(): void
    {
        // If a cookie matches BOTH registry and library, registry wins —
        // admin's curation is the source of truth for the FE consent UI.
        $services = [[
            'id' => 'my-amplitude',
            'name' => 'Custom Amplitude config',
            'matches' => ['cookies' => ['/^amplitude_/']],
        ]];
        $result = DetectionListPresenter::deriveState(
            ['kind' => 'cookie', 'identifier' => 'amplitude_session'],
            $services,
            $this->library(),
        );
        self::assertSame(DetectionListPresenter::STATE_CURATED, $result['state']);
        self::assertSame('my-amplitude', $result['match']['id']);
    }

    #[Test]
    public function decorateStateAttachesStateClassAndMatch(): void
    {
        $row = DetectionListPresenter::decorateState(
            ['uid' => 7, 'kind' => 'cookie', 'identifier' => 'amplitude_session'],
            $this->services(),
            $this->library(),
        );
        self::assertSame(DetectionListPresenter::STATE_RECOGNIZED, $row['state']);
        self::assertSame('bg-info text-dark', $row['state_class']);
        self::assertSame('Amplitude', $row['match']['name']);
        // Preserves unrelated row keys.
        self::assertSame(7, $row['uid']);
    }

    #[Test]
    public function dismissedAtTakesPrecedenceOverEverything(): void
    {
        // Dismissed wins over a registry hit. Without this order, a
        // visitor on a fresh browser could resurrect a dismissed row
        // by re-triggering a registry-covered tracker (the new POST
        // bumps occurrences and the row would otherwise re-derive to
        // kuratiert). The persisted dismissal must be the source of
        // truth.
        $row = DetectionListPresenter::deriveState(
            [
                'kind' => 'cookie',
                'identifier' => 'amplitude_session',
                'dismissed_at' => 1_700_000_000,
            ],
            [['id' => 'amplitude', 'matches' => ['cookies' => ['/^amplitude_/']]]],
            $this->library(),
        );
        self::assertSame(DetectionListPresenter::STATE_DISMISSED, $row['state']);
        // The underlying match info is still surfaced so the row's
        // sub-label can still display the service name, and so
        // un-dismiss restores it to the right state.
        self::assertSame('amplitude', $row['match']['id']);
    }

    #[Test]
    public function dismissedAtZeroFallsThroughToDerivedState(): void
    {
        // dismissed_at = 0 is the "not dismissed" sentinel and must
        // not trigger the STATE_DISMISSED branch.
        $row = DetectionListPresenter::deriveState(
            ['kind' => 'cookie', 'identifier' => 'amplitude_session', 'dismissed_at' => 0],
            $this->services(),
            $this->library(),
        );
        self::assertSame(DetectionListPresenter::STATE_RECOGNIZED, $row['state']);
    }

    #[Test]
    public function decorateStateForDismissedUsesMutedBadgeClass(): void
    {
        $row = DetectionListPresenter::decorateState(
            [
                'uid' => 9,
                'kind' => 'cookie',
                'identifier' => 'amplitude_session',
                'dismissed_at' => 1_700_000_000,
            ],
            $this->services(),
            $this->library(),
        );
        self::assertSame(DetectionListPresenter::STATE_DISMISSED, $row['state']);
        self::assertSame('bg-light text-muted border', $row['state_class']);
    }
}
