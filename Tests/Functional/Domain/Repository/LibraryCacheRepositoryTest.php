<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use SimpleCMP\T3SimpleCmp\Domain\Repository\LibraryCacheRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class LibraryCacheRepositoryTest extends FunctionalTestCase
{
    private const string TABLE = 'tx_t3simplecmp_library_cache';

    protected array $testExtensionsToLoad = ['simplecmp/t3-simplecmp'];

    private LibraryCacheRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->get(LibraryCacheRepository::class);
    }

    #[Test]
    public function getReturnsNullOnEmptyCache(): void
    {
        self::assertNull($this->repository->get('cookie', '_ga', time()));
    }

    #[Test]
    public function putThenGetReturnsTheStoredResponse(): void
    {
        $now = 1_700_000_000;
        $response = [['id' => 'google-analytics', 'name' => 'Google Analytics']];
        $this->repository->put('cookie', '_ga', $response, $now, $now + 86400);

        $cached = $this->repository->get('cookie', '_ga', $now);
        self::assertSame($response, $cached);
    }

    #[Test]
    public function getReturnsNullForExpiredEntry(): void
    {
        $now = 1_700_000_000;
        $this->repository->put('cookie', '_ga', [], $now, $now + 100);
        self::assertNull($this->repository->get('cookie', '_ga', $now + 200));
    }

    #[Test]
    public function getDistinguishesEmptyArrayFromCacheMiss(): void
    {
        // Negative cache: storing [] is a valid hit meaning "upstream
        // confirmed no match." Must return [] (not null) so the
        // upstream client can short-circuit the next time the same
        // unknown query comes in.
        $now = 1_700_000_000;
        $this->repository->put('origin', 'unknown.example', [], $now, $now + 86400);

        $cached = $this->repository->get('origin', 'unknown.example', $now);
        self::assertIsArray($cached);
        self::assertSame([], $cached);
    }

    #[Test]
    public function putOverwritesPreviousEntryForTheSameKey(): void
    {
        $now = 1_700_000_000;
        $this->repository->put('cookie', '_ga', [['id' => 'first']], $now, $now + 86400);
        $this->repository->put('cookie', '_ga', [['id' => 'second']], $now + 1, $now + 86401);

        $cached = $this->repository->get('cookie', '_ga', $now + 2);
        self::assertSame([['id' => 'second']], $cached);

        // And there's only ONE row (the unique key worked).
        $count = (int) $this->get(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->executeQuery(
                'SELECT COUNT(*) FROM ' . self::TABLE
                . ' WHERE query_type = ? AND query_value = ?',
                ['cookie', '_ga'],
            )
            ->fetchOne();
        self::assertSame(1, $count);
    }

    #[Test]
    public function putRefreshesExistingRowInPlacePreservingCrdate(): void
    {
        // Re-putting the same key must NOT throw (the old DELETE-then-INSERT
        // collided on the UNIQUE key under concurrency → 500 on /v1/lookup) and
        // must refresh the row in place — keeping crdate, updating value/expiry.
        $first = 1_700_000_000;
        $this->repository->put('cookie', '_ga', [['id' => 'first']], $first, $first + 86400);
        $second = $first + 500;
        $this->repository->put('cookie', '_ga', [['id' => 'second']], $second, $second + 86400);

        $rows = $this->get(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->executeQuery(
                'SELECT response_json, fetched_at, expires_at, crdate FROM ' . self::TABLE
                . ' WHERE query_type = ? AND query_value = ?',
                ['cookie', '_ga'],
            )
            ->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame([['id' => 'second']], json_decode((string) $rows[0]['response_json'], true));
        self::assertSame($second, (int) $rows[0]['fetched_at']);
        self::assertSame($second + 86400, (int) $rows[0]['expires_at']);
        // crdate is the original creation time — the in-place refresh keeps it
        // (the old recreate-every-put reset it).
        self::assertSame($first, (int) $rows[0]['crdate']);
    }

    #[Test]
    public function purgeExpiredDeletesOnlyExpiredRows(): void
    {
        $now = 1_700_000_000;
        $this->repository->put('cookie', 'fresh', [], $now, $now + 86400);
        $this->repository->put('cookie', 'stale-1', [], $now - 200, $now - 100);
        $this->repository->put('cookie', 'stale-2', [], $now - 200, $now - 50);

        $deleted = $this->repository->purgeExpired($now);
        self::assertSame(2, $deleted);
        self::assertNotNull($this->repository->get('cookie', 'fresh', $now));
        self::assertNull($this->repository->get('cookie', 'stale-1', $now));
    }
}
