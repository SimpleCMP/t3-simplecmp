<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\DetectionRepository;

final class DetectionRepositoryTest extends FunctionalTestCase
{
    private const string TABLE = 'tx_simplecmptypo3_detection';

    protected array $testExtensionsToLoad = ['wapplersystems/simplecmp-typo3'];

    private DetectionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->get(DetectionRepository::class);
    }

    #[Test]
    public function ingestInsertsNewRowOnUniqueTriple(): void
    {
        $now = (int) floor(microtime(true) * 1000);
        $this->repository->ingest($this->payload([
            'source' => 'site-a',
            'detection' => $this->detection('_ga', firstSeen: $now),
        ]), 0);

        $row = $this->fetchRow('site-a', 'cookie', '_ga');
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['occurrences']);
        self::assertSame('cookie', $row['kind']);
        self::assertSame('_ga', $row['identifier']);
        self::assertSame($now, (int) $row['first_seen']);
        self::assertSame(0, (int) $row['reviewed']);
    }

    #[Test]
    public function ingestBumpsOccurrencesOnRepeatTriple(): void
    {
        $this->repository->ingest($this->payload(['detection' => $this->detection('_fbp')]));
        $this->repository->ingest($this->payload(['detection' => $this->detection('_fbp')]));
        $this->repository->ingest($this->payload(['detection' => $this->detection('_fbp')]));

        $rows = $this->allRowsForTriple('default', 'cookie', '_fbp');
        self::assertCount(1, $rows);
        self::assertSame(3, (int) $rows[0]['occurrences']);
    }

    #[Test]
    public function ingestKeepsRowsSeparatePerSource(): void
    {
        $this->repository->ingest($this->payload([
            'source' => 'site-a',
            'detection' => $this->detection('_shared'),
        ]));
        $this->repository->ingest($this->payload([
            'source' => 'site-b',
            'detection' => $this->detection('_shared'),
        ]));
        self::assertSame(2, $this->repository->count());
    }

    #[Test]
    public function ingestPreservesLatestPayloadFieldsOnUpdate(): void
    {
        $first = $this->payload([
            'detection' => $this->detection('_ga', origin: null, firstSeenOn: '/landing'),
            'page' => ['url' => 'https://example.com/landing'],
        ]);
        $second = $this->payload([
            'detection' => $this->detection('_ga', origin: 'google-analytics.com', firstSeenOn: '/checkout'),
            'page' => ['url' => 'https://example.com/checkout'],
        ]);

        $this->repository->ingest($first);
        $this->repository->ingest($second);

        $row = $this->fetchRow('default', 'cookie', '_ga');
        self::assertSame(2, (int) $row['occurrences']);
        self::assertSame('google-analytics.com', $row['origin']);
        self::assertSame('https://example.com/checkout', $row['page_url']);
        self::assertSame('/checkout', $row['first_seen_on']);
    }

    #[Test]
    public function ingestSilentlyDropsMalformedDetection(): void
    {
        $this->repository->ingest(['source' => 'site-a']);  // no detection
        $this->repository->ingest(['source' => 'site-a', 'detection' => 'not-an-array']);
        $this->repository->ingest(['source' => 'site-a', 'detection' => ['kind' => 'cookie']]);  // no identifier
        self::assertSame(0, $this->repository->count());
    }

    #[Test]
    public function ingestRespectsStoragePid(): void
    {
        $this->repository->ingest($this->payload(), 99);
        $row = $this->fetchRow('default', 'cookie', '_ga');
        self::assertSame(99, (int) $row['pid']);
    }

    #[Test]
    public function countReturnsTotal(): void
    {
        $this->repository->ingest($this->payload(['detection' => $this->detection('_a')]));
        $this->repository->ingest($this->payload(['detection' => $this->detection('_b')]));
        $this->repository->ingest($this->payload(['detection' => $this->detection('_c')]));
        self::assertSame(3, $this->repository->count());
    }

    #[Test]
    public function countSinceFiltersByCrdate(): void
    {
        $this->insertRowRaw(['source' => 'old', 'kind' => 'cookie', 'identifier' => '_old', 'crdate' => time() - 86400 * 8]);
        $this->insertRowRaw(['source' => 'mid', 'kind' => 'cookie', 'identifier' => '_mid', 'crdate' => time() - 86400 * 3]);
        $this->insertRowRaw(['source' => 'new', 'kind' => 'cookie', 'identifier' => '_new', 'crdate' => time() - 3600]);

        self::assertSame(3, $this->repository->countSince(0));
        self::assertSame(2, $this->repository->countSince(time() - 86400 * 7));
        self::assertSame(1, $this->repository->countSince(time() - 86400));
    }

    #[Test]
    public function recentReturnsOrderedByReceivedAtDescAndRespectsLimit(): void
    {
        $this->insertRowRaw(['source' => 's', 'kind' => 'cookie', 'identifier' => '_a', 'received_at' => 100]);
        $this->insertRowRaw(['source' => 's', 'kind' => 'cookie', 'identifier' => '_b', 'received_at' => 300]);
        $this->insertRowRaw(['source' => 's', 'kind' => 'cookie', 'identifier' => '_c', 'received_at' => 200]);

        $recent = $this->repository->recent(2);
        self::assertCount(2, $recent);
        self::assertSame('_b', $recent[0]['identifier']);
        self::assertSame('_c', $recent[1]['identifier']);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $detection = $overrides['detection'] ?? $this->detection('_ga');
        return array_replace([
            'schemaVersion' => 1,
            'source' => 'default',
            'sentAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'page' => ['url' => 'https://example.com/'],
            'library' => ['name' => 'simplecmp', 'version' => '0.0.1'],
            'detection' => $detection,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function detection(
        string $identifier,
        string $kind = 'cookie',
        ?string $origin = null,
        ?int $firstSeen = null,
        ?string $firstSeenOn = null,
    ): array {
        $now = (int) floor(microtime(true) * 1000);
        $out = [
            'kind' => $kind,
            'identifier' => $identifier,
            'firstSeen' => $firstSeen ?? $now,
            'lastSeen' => $now,
            'count' => 1,
            'status' => 'unknown',
        ];
        if ($origin !== null) {
            $out['origin'] = $origin;
        }
        if ($firstSeenOn !== null) {
            $out['firstSeenOn'] = $firstSeenOn;
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function fetchRow(string $source, string $kind, string $identifier): ?array
    {
        $rows = $this->allRowsForTriple($source, $kind, $identifier);
        return $rows[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    private function allRowsForTriple(string $source, string $kind, string $identifier): array
    {
        $conn = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        return $conn->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('source = :s AND kind = :k AND identifier = :i')
            ->setParameter('s', $source)
            ->setParameter('k', $kind)
            ->setParameter('i', $identifier)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** @param array<string, mixed> $row */
    private function insertRowRaw(array $row): void
    {
        $defaults = [
            'pid' => 0,
            'crdate' => time(),
            'tstamp' => time(),
            'received_at' => time(),
            'occurrences' => 1,
            'reviewed' => 0,
        ];
        $this->get(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->insert(self::TABLE, array_replace($defaults, $row));
    }
}
