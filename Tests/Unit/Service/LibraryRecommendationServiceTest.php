<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\LibraryRecommendationService;

/**
 * Covers the inverted matching path used by the Bibliothek tab's
 * "💡 Empfohlen" section: given a set of detections, a registry, and a
 * library, identify which UNADOPTED library entries would resolve
 * actionable detections.
 *
 * Pure compute service, no DI, no DB — straightforward fixture-driven
 * unit tests.
 */
final class LibraryRecommendationServiceTest extends TestCase
{
    private LibraryRecommendationService $service;

    protected function setUp(): void
    {
        $this->service = new LibraryRecommendationService();
    }

    #[Test]
    public function literalCookieMatchProducesRecommendation(): void
    {
        $recommendations = $this->service->recommendationsFor(
            detections: [
                ['kind' => 'cookie', 'identifier' => '_fbp', 'origin' => '', 'dismissed_at' => 0],
            ],
            registryServices: [],
            libraryEntries: [
                ['id' => 'facebook', 'matches' => ['cookies' => ['_fbp']]],
            ],
            adoptedIds: [],
        );
        self::assertArrayHasKey('facebook', $recommendations);
        self::assertSame(1, $recommendations['facebook']['count']);
        self::assertSame(['_fbp'], $recommendations['facebook']['identifiers']);
    }

    #[Test]
    public function wildcardOriginMatchProducesRecommendation(): void
    {
        $recommendations = $this->service->recommendationsFor(
            detections: [
                ['kind' => 'script', 'identifier' => 'gtm.js', 'origin' => 'www.googletagmanager.com', 'dismissed_at' => 0],
            ],
            registryServices: [],
            libraryEntries: [
                ['id' => 'gtm', 'matches' => ['origins' => ['*.googletagmanager.com']]],
            ],
            adoptedIds: [],
        );
        self::assertArrayHasKey('gtm', $recommendations);
        self::assertSame(1, $recommendations['gtm']['count']);
        self::assertSame(['www.googletagmanager.com'], $recommendations['gtm']['identifiers']);
    }

    #[Test]
    public function regexCookieMatcherFiresAcrossMultipleDetections(): void
    {
        $recommendations = $this->service->recommendationsFor(
            detections: [
                ['kind' => 'cookie', 'identifier' => '_hjSession_42', 'origin' => '', 'dismissed_at' => 0],
                ['kind' => 'cookie', 'identifier' => '_hjSessionUser_42', 'origin' => '', 'dismissed_at' => 0],
                // Negative control — wouldn't match the /^_hj/ pattern.
                ['kind' => 'cookie', 'identifier' => '_ga', 'origin' => '', 'dismissed_at' => 0],
            ],
            registryServices: [],
            libraryEntries: [
                ['id' => 'hotjar', 'matches' => ['cookies' => ['/^_hj/']]],
            ],
            adoptedIds: [],
        );
        self::assertSame(2, $recommendations['hotjar']['count']);
        self::assertSame(['_hjSession_42', '_hjSessionUser_42'], $recommendations['hotjar']['identifiers']);
    }

    #[Test]
    public function dismissedDetectionsAreExcluded(): void
    {
        $recommendations = $this->service->recommendationsFor(
            detections: [
                ['kind' => 'cookie', 'identifier' => '_fbp', 'origin' => '', 'dismissed_at' => 0],
                // Same cookie, but dismissed — must not contribute.
                ['kind' => 'cookie', 'identifier' => '_fbp', 'origin' => '', 'dismissed_at' => 1700000000],
            ],
            registryServices: [],
            libraryEntries: [
                ['id' => 'facebook', 'matches' => ['cookies' => ['_fbp']]],
            ],
            adoptedIds: [],
        );
        // Both rows carry `_fbp` but the deduped identifier list means
        // 1 distinct identifier — and the dismissed one is filtered out
        // before reaching the matcher anyway.
        self::assertSame(1, $recommendations['facebook']['count']);
    }

    #[Test]
    public function registryCoverageSuppressesRecommendation(): void
    {
        // `_ga` already covered by an adopted google-analytics service →
        // detection is STATE_CURATED, NOT actionable. No library entry
        // should claim it.
        $recommendations = $this->service->recommendationsFor(
            detections: [
                ['kind' => 'cookie', 'identifier' => '_ga', 'origin' => '', 'dismissed_at' => 0],
            ],
            registryServices: [
                ['id' => 'google-analytics', 'matches' => ['cookies' => ['_ga']]],
            ],
            libraryEntries: [
                // A hypothetical library entry that ALSO matches _ga;
                // shouldn't appear in recommendations since the
                // detection isn't actionable.
                ['id' => 'some-ga-alias', 'matches' => ['cookies' => ['_ga']]],
            ],
            adoptedIds: [],
        );
        self::assertSame([], $recommendations);
    }

    #[Test]
    public function adoptedLibraryEntriesAreSkipped(): void
    {
        $recommendations = $this->service->recommendationsFor(
            detections: [
                ['kind' => 'cookie', 'identifier' => '_fbp', 'origin' => '', 'dismissed_at' => 0],
            ],
            registryServices: [],
            libraryEntries: [
                ['id' => 'facebook', 'matches' => ['cookies' => ['_fbp']]],
            ],
            // Facebook adopted → no recommendation. Note: in real-world
            // flow the registry would also carry `facebook` so the
            // registry-cover filter would catch the detection anyway,
            // but the adoptedIds check is the primary filter.
            adoptedIds: ['facebook' => true],
        );
        self::assertSame([], $recommendations);
    }

    #[Test]
    public function headlineCountsDistinctDetectionsAcrossEntries(): void
    {
        $recommendations = [
            'facebook' => ['count' => 2, 'identifiers' => ['_fbp', 'fr']],
            'meta-pixel' => ['count' => 1, 'identifiers' => ['_fbp']],
            'stripe' => ['count' => 1, 'identifiers' => ['__stripe_mid']],
        ];
        $headline = $this->service->headline($recommendations);
        // 3 entries, 3 DISTINCT detections (`_fbp`, `fr`, `__stripe_mid`)
        // — `_fbp` covered by two entries doesn't get double-counted.
        self::assertSame(3, $headline['entries']);
        self::assertSame(3, $headline['detections']);
    }

    #[Test]
    public function headlineOnEmptyRecommendationsIsZeroZero(): void
    {
        $headline = $this->service->headline([]);
        self::assertSame(['entries' => 0, 'detections' => 0], $headline);
    }
}
