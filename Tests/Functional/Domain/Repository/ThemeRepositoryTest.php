<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ThemeRepository;

final class ThemeRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['wapplersystems/simplecmp-typo3'];

    private ThemeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->get(ThemeRepository::class);
    }

    #[Test]
    public function findBySiteReturnsNullWhenMissing(): void
    {
        self::assertNull($this->repository->findBySite('nonexistent'));
    }

    #[Test]
    public function upsertInsertsNewRow(): void
    {
        $this->repository->upsert('default', [
            'color-primary' => '#ff0000',
            'radius' => '8px',
        ]);
        $loaded = $this->repository->findBySite('default');
        self::assertSame(['color-primary' => '#ff0000', 'radius' => '8px'], $loaded);
    }

    #[Test]
    public function upsertReplacesExistingTokensOnSecondCall(): void
    {
        // Second upsert is a full replace, not a merge — admin's intent
        // is "this is the new theme", so previously-saved keys not in
        // the new payload disappear.
        $this->repository->upsert('default', ['color-primary' => '#ff0000']);
        $this->repository->upsert('default', ['radius' => '12px']);
        self::assertSame(['radius' => '12px'], $this->repository->findBySite('default'));
    }

    #[Test]
    public function upsertScopesToSiteIdentifier(): void
    {
        $this->repository->upsert('default', ['color-primary' => '#ff0000']);
        $this->repository->upsert('site2', ['color-primary' => '#0000ff']);
        self::assertSame(
            ['color-primary' => '#ff0000'],
            $this->repository->findBySite('default'),
        );
        self::assertSame(
            ['color-primary' => '#0000ff'],
            $this->repository->findBySite('site2'),
        );
    }

    #[Test]
    public function deleteRemovesRowSoFindReturnsNull(): void
    {
        $this->repository->upsert('default', ['color-primary' => '#ff0000']);
        $this->repository->delete('default');
        self::assertNull($this->repository->findBySite('default'));
    }

    #[Test]
    public function deleteIsIdempotentForMissingRows(): void
    {
        // No-op: should not error even if there's nothing to delete.
        $this->repository->delete('never-existed');
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function upsertOfEmptyTokensIsStillReadable(): void
    {
        // Edge case: the controller's sanitize step might result in an
        // empty array (all defaults submitted). The row should still
        // round-trip as `[]`, not null — the difference matters: a row
        // with empty tokens means "explicitly cleared", a missing row
        // means "never customized".
        $this->repository->upsert('default', []);
        self::assertSame([], $this->repository->findBySite('default'));
    }
}
