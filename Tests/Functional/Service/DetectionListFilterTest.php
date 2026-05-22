<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WapplerSystems\SimpleCmpTypo3\Service\DetectionListFilter;

/**
 * Locks the SQL semantics of every filter the detection list view
 * applies. Seeds rows with controlled (source, kind, occurrences)
 * triples, then asserts the filter narrows the result set
 * correctly.
 *
 * Pre-history: the `confidence=medium` filter blew up at runtime
 * because the previous inline implementation called
 * `ExpressionBuilder::between()`, which TYPO3's DBAL doesn't
 * expose. That bug went undetected because the controller's
 * filter pathway was uncovered by any test. This suite is the
 * regression guard — every confidence variant runs against a real
 * DBAL connection so a method-name slip will fail fast.
 */
final class DetectionListFilterTest extends FunctionalTestCase
{
    private const string DETECTION_TABLE = 'tx_simplecmptypo3_detection';

    protected array $testExtensionsToLoad = ['wapplersystems/simplecmp-typo3'];

    private DetectionListFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = $this->get(DetectionListFilter::class);

        // Seed: 5 detections with occurrences 1..5 across two sources
        // and two kinds, so every combination of source/kind/confidence
        // filter has rows on either side of the cut.
        $this->seedDetection(['source' => 'simplecmp-default', 'kind' => 'cookie', 'identifier' => 'low-a', 'occurrences' => 1]);
        $this->seedDetection(['source' => 'simplecmp-default', 'kind' => 'cookie', 'identifier' => 'med-a', 'occurrences' => 2]);
        $this->seedDetection(['source' => 'simplecmp-default', 'kind' => 'request', 'identifier' => 'med-b', 'occurrences' => 3]);
        $this->seedDetection(['source' => 'simplecmp-default', 'kind' => 'request', 'identifier' => 'med-c', 'occurrences' => 4]);
        $this->seedDetection(['source' => 'simplecmp-site2', 'kind' => 'cookie', 'identifier' => 'high-a', 'occurrences' => 5]);
    }

    #[Test]
    public function noFiltersReturnsAllRows(): void
    {
        $rows = $this->runFilter(['source' => '', 'kind' => '', 'confidence' => '']);
        self::assertCount(5, $rows);
    }

    #[Test]
    public function lowConfidenceMatchesOccurrencesEqualOne(): void
    {
        $rows = $this->runFilter(['source' => '', 'kind' => '', 'confidence' => 'low']);
        $ids = array_column($rows, 'identifier');
        self::assertEqualsCanonicalizing(['low-a'], $ids);
    }

    #[Test]
    public function mediumConfidenceMatchesOccurrencesTwoToFour(): void
    {
        // The bug fix: medium must NOT throw on
        // ExpressionBuilder::between(). gte+lte combo replaces it.
        $rows = $this->runFilter(['source' => '', 'kind' => '', 'confidence' => 'medium']);
        $ids = array_column($rows, 'identifier');
        self::assertEqualsCanonicalizing(['med-a', 'med-b', 'med-c'], $ids);
    }

    #[Test]
    public function highConfidenceMatchesOccurrencesFiveOrMore(): void
    {
        $rows = $this->runFilter(['source' => '', 'kind' => '', 'confidence' => 'high']);
        $ids = array_column($rows, 'identifier');
        self::assertEqualsCanonicalizing(['high-a'], $ids);
    }

    #[Test]
    public function unknownConfidenceValueIsTreatedAsNoFilter(): void
    {
        // Defensive: any out-of-vocabulary value should pass through
        // unchanged (so a malicious / typo'd URL query param can't
        // produce a TypeError or a 500).
        $rows = $this->runFilter(['source' => '', 'kind' => '', 'confidence' => 'bogus']);
        self::assertCount(5, $rows);
    }

    #[Test]
    public function sourceFilterNarrowsToOneReportingSite(): void
    {
        $rows = $this->runFilter(['source' => 'simplecmp-site2', 'kind' => '', 'confidence' => '']);
        self::assertCount(1, $rows);
        self::assertSame('high-a', $rows[0]['identifier']);
    }

    #[Test]
    public function kindFilterNarrowsToOneDetectionKind(): void
    {
        $rows = $this->runFilter(['source' => '', 'kind' => 'request', 'confidence' => '']);
        self::assertCount(2, $rows);
        $ids = array_column($rows, 'identifier');
        self::assertEqualsCanonicalizing(['med-b', 'med-c'], $ids);
    }

    #[Test]
    public function filtersCombineWithAndSemantics(): void
    {
        // The combination from the bug-report URL:
        // source=simplecmp-default & kind=cookie & confidence=medium.
        $rows = $this->runFilter([
            'source' => 'simplecmp-default',
            'kind' => 'cookie',
            'confidence' => 'medium',
        ]);
        self::assertCount(1, $rows);
        self::assertSame('med-a', $rows[0]['identifier']);
    }

    /**
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    private function runFilter(array $filters): array
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->select('*')->from(self::DETECTION_TABLE)->orderBy('identifier', 'ASC');
        $this->filter->apply($qb, $filters);
        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return $rows;
    }

    /** @param array<string, mixed> $data */
    private function seedDetection(array $data): void
    {
        $now = time();
        $row = [
            'pid' => 0,
            'source' => (string) $data['source'],
            'kind' => (string) $data['kind'],
            'identifier' => (string) $data['identifier'],
            'origin' => $data['origin'] ?? '',
            'occurrences' => (int) $data['occurrences'],
            'first_seen' => $now,
            'last_seen' => $now,
            'received_at' => $now,
            'crdate' => $now,
            'tstamp' => $now,
            'payload' => '{}',
            'dismissed_at' => 0,
        ];
        $this->get(ConnectionPool::class)
            ->getConnectionForTable(self::DETECTION_TABLE)
            ->insert(self::DETECTION_TABLE, $row);
    }
}
