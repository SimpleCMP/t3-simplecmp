<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use SimpleCMP\T3SimpleCmp\Domain\Repository\AllowedStylesheetHostRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AllowedStylesheetHostRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['simplecmp/t3-simplecmp'];

    private AllowedStylesheetHostRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->get(AllowedStylesheetHostRepository::class);
    }

    #[Test]
    public function hostsForSourceIsEmptyWhenNothingAllowed(): void
    {
        self::assertSame([], $this->repository->hostsForSource('simplecmp-1'));
    }

    #[Test]
    public function allowThenListRoundTrips(): void
    {
        $this->repository->allow('simplecmp-1', 'fonts.googleapis.com');
        self::assertSame(['fonts.googleapis.com'], $this->repository->hostsForSource('simplecmp-1'));
    }

    #[Test]
    public function allowIsIdempotentOnRepeat(): void
    {
        // The UNIQUE (source, host) key makes a repeat allow a silent no-op —
        // no duplicate row, no exception.
        $this->repository->allow('simplecmp-1', 'fonts.googleapis.com');
        $this->repository->allow('simplecmp-1', 'fonts.googleapis.com');
        self::assertSame(['fonts.googleapis.com'], $this->repository->hostsForSource('simplecmp-1'));
    }

    #[Test]
    public function hostIsStoredLowercased(): void
    {
        // DNS hosts are case-insensitive; the rewriter compares lowercased, so
        // the stored value is normalised on the way in.
        $this->repository->allow('simplecmp-1', 'Fonts.GoogleAPIs.com');
        self::assertSame(['fonts.googleapis.com'], $this->repository->hostsForSource('simplecmp-1'));
    }

    #[Test]
    public function allowsAreScopedToSource(): void
    {
        $this->repository->allow('simplecmp-1', 'fonts.googleapis.com');
        $this->repository->allow('simplecmp-2', 'cdn.other.test');
        self::assertSame(['fonts.googleapis.com'], $this->repository->hostsForSource('simplecmp-1'));
        self::assertSame(['cdn.other.test'], $this->repository->hostsForSource('simplecmp-2'));
    }

    #[Test]
    public function blankSourceOrHostIsIgnored(): void
    {
        $this->repository->allow('', 'fonts.googleapis.com');
        $this->repository->allow('simplecmp-1', '   ');
        self::assertSame([], $this->repository->hostsForSource(''));
        self::assertSame([], $this->repository->hostsForSource('simplecmp-1'));
    }
}
