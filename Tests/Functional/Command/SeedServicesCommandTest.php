<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WapplerSystems\SimpleCmpTypo3\Command\SeedServicesCommand;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;

final class SeedServicesCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['wapplersystems/simplecmp-typo3'];

    private SeedServicesCommand $command;
    private ServiceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = $this->get(SeedServicesCommand::class);
        $this->repository = $this->get(ServiceRepository::class);
    }

    #[Test]
    public function seededServicesAreVisibleOnFeBanner(): void
    {
        // Seed essentials must reach the visitor's banner — they're the
        // baseline every site needs (consent storage, core analytics
        // opt-out, etc.). Compare to ImportKnownTrackersCommand, whose
        // entries default to hidden.
        $exit = $this->command->run(new ArrayInput([]), new BufferedOutput());

        self::assertSame(0, $exit);
        self::assertGreaterThan(0, $this->repository->count());
        $visibleIds = array_column($this->repository->findAllVisibleOnFe(), 'id');
        self::assertNotEmpty($visibleIds, 'seeded services must be fe_visible=1');
        // The seed count and the visible count must match — nothing
        // should land in the registry as hidden via this path.
        self::assertCount(
            $this->repository->count(),
            $visibleIds,
            'every seeded service must be visible on the FE banner',
        );
    }

    #[Test]
    public function seedPromotesPreExistingHiddenRows(): void
    {
        // Real-world case: admin ran `simplecmp:import-known-trackers`
        // first (fe_visible=0), then re-runs `simplecmp:seed`. The seed
        // command must override the hidden state for its essentials —
        // `feVisibleOnInsert` only applies on insert, so without an
        // explicit `setVisibility(true)` call the UPDATE path would leave
        // the row hidden and admin's banner stays empty.
        $this->repository->upsert(
            ['id' => 'google-analytics', 'name' => 'Stale GA', 'purposes' => []],
            0,
            feVisibleOnInsert: false,
        );
        self::assertSame([], $this->repository->findAllVisibleOnFe());

        $this->command->run(new ArrayInput([]), new BufferedOutput());

        $visibleIds = array_column($this->repository->findAllVisibleOnFe(), 'id');
        self::assertContains(
            'google-analytics',
            $visibleIds,
            'pre-existing hidden seed entry must be promoted on re-run',
        );
    }
}
