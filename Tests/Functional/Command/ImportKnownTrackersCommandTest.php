<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use WapplerSystems\SimpleCmpTypo3\Command\ImportKnownTrackersCommand;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;

final class ImportKnownTrackersCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['wapplersystems/simplecmp-typo3'];

    private ImportKnownTrackersCommand $command;
    private ServiceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = $this->get(ImportKnownTrackersCommand::class);
        $this->repository = $this->get(ServiceRepository::class);
    }

    #[Test]
    public function importsBundledTrackersIntoEmptyRegistry(): void
    {
        self::assertSame(0, $this->repository->count());

        $exit = $this->runImport();

        self::assertSame(0, $exit);
        self::assertGreaterThan(30, $this->repository->count(), 'Expected at least 30 bundled trackers');
        self::assertNotNull($this->repository->findOne('mixpanel'), 'Mixpanel should be imported');
        self::assertNotNull($this->repository->findOne('stripe'), 'Stripe should be imported');
    }

    #[Test]
    public function defaultRunSkipsExistingServices(): void
    {
        $this->repository->upsert([
            'id' => 'mixpanel',
            'name' => 'Custom Mixpanel — admin-edited',
            'purposes' => ['custom-purpose'],
            'matches' => ['cookies' => ['my_custom_mp']],
        ]);
        $beforeName = $this->repository->findOne('mixpanel')['name'];

        $this->runImport();

        $afterName = $this->repository->findOne('mixpanel')['name'];
        self::assertSame($beforeName, $afterName, 'Admin-edited service should not be overwritten without --force');
        self::assertSame('Custom Mixpanel — admin-edited', $afterName);
    }

    #[Test]
    public function forceFlagOverwritesExistingServices(): void
    {
        $this->repository->upsert([
            'id' => 'mixpanel',
            'name' => 'Stale name',
            'purposes' => [],
        ]);

        $this->runImport(['--force' => true]);

        $after = $this->repository->findOne('mixpanel');
        self::assertSame('Mixpanel', $after['name'], '--force should overwrite the admin-edited name with the bundled one');
    }

    #[Test]
    public function emitsCorrectSummaryCounts(): void
    {
        // Pre-seed one service so we have a skip
        $this->repository->upsert(['id' => 'mixpanel', 'name' => 'Old', 'purposes' => []]);

        $output = new BufferedOutput();
        $this->command->run(new ArrayInput([]), $output);
        $text = $output->fetch();

        self::assertMatchesRegularExpression('/\d+ new/', $text);
        self::assertStringContainsString('1 skipped', $text);
    }

    /** @param array<string, mixed> $args */
    private function runImport(array $args = []): int
    {
        return $this->command->run(new ArrayInput($args), new BufferedOutput());
    }
}
