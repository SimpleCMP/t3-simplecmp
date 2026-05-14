<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;
use WapplerSystems\SimpleCmpTypo3\Service\StoragePidResolver;

/**
 * Bootstraps the service registry from the bundled
 * `Resources/Private/Seeds/services/*.json` fixtures. Idempotent (upsert
 * by `service_id`), so re-running after a release that adds new seeds
 * just inserts the new ones without disturbing admin edits.
 *
 * Run with:
 *   ddev exec vendor/bin/typo3 simplecmp:seed
 */
#[AsCommand(
    name: 'simplecmp:seed',
    description: 'Seed the SimpleCMP service registry from bundled JSON fixtures.',
)]
final class SeedServicesCommand extends Command
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly StoragePidResolver $storagePidResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'pid',
            null,
            InputOption::VALUE_REQUIRED,
            'Page UID to file new service records under. Overrides the Site Set setting `simplecmp.storagePid`.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pidOption = $input->getOption('pid');
        $pid = $pidOption !== null
            ? (int) $pidOption
            : $this->storagePidResolver->resolveDefault();

        $seedDir = $this->seedDirectory();
        if (!is_dir($seedDir)) {
            $io->error("Seed directory not found: {$seedDir}");
            return Command::FAILURE;
        }

        $files = glob($seedDir . '/*.json') ?: [];
        if ($files === []) {
            $io->warning("No seed files found in {$seedDir}.");
            return Command::SUCCESS;
        }

        $inserted = 0;
        $errors = 0;
        foreach ($files as $file) {
            try {
                $payload = json_decode(
                    (string) file_get_contents($file),
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException $e) {
                $io->error(sprintf('Failed to parse %s: %s', basename($file), $e->getMessage()));
                $errors++;
                continue;
            }
            if (!is_array($payload) || !isset($payload['id'], $payload['name'])) {
                $io->error(sprintf('Seed %s missing required fields (id, name).', basename($file)));
                $errors++;
                continue;
            }
            $this->services->upsert($payload, $pid);
            $inserted++;
        }

        $io->success(sprintf('Seeded %d service(s) at pid=%d; %d error(s).', $inserted, $pid, $errors));
        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function seedDirectory(): string
    {
        // Extension resources resolve relative to the composer-installed
        // vendor path; using Environment::getPublicPath() + EXT: would
        // require a TYPO3 frontend bootstrap that's overkill in a CLI.
        return Environment::getProjectPath()
            . '/vendor/wapplersystems/simplecmp-typo3/Resources/Private/Seeds/services';
    }
}
