<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use SimpleCMP\ServicesLibrary\ServicesLibrary;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;
use WapplerSystems\SimpleCmpTypo3\Service\StoragePidResolver;

/**
 * Imports a curated library of well-known third-party trackers
 * (analytics, ad networks, embeds, chat widgets, payments, …) into
 * the service registry so the recorder + classifier chain matches
 * them out of the box.
 *
 * The data comes from the `simplecmp/services-library` composer
 * package — a separate repo so future WordPress / Contao plugins
 * can share the same definitions. This command is the TYPO3-side
 * importer that upserts each record into `tx_simplecmptypo3_service`.
 *
 * Imported services land with `fe_visible = 0` (classifier pre-fill,
 * not on the visitor's banner). The bridge skips POSTing for cookies
 * matched by these — they're `status: known` server-side. Admin
 * promotes individual entries via the BE catalog tab ("Show on
 * banner") when they want a specific service to appear in the
 * consent UI.
 *
 * Default behaviour is **skip-if-exists** so admin-edited services
 * aren't clobbered when an admin re-runs after a release. Use
 * `--force` to overwrite matching `service_id`s with the library
 * values.
 *
 * Run with:
 *   ddev exec vendor/bin/typo3 simplecmp:import-known-trackers
 *   ddev exec vendor/bin/typo3 simplecmp:import-known-trackers --force
 */
#[AsCommand(
    name: 'simplecmp:import-known-trackers',
    description: 'Import a curated library of well-known third-party trackers into the service registry.',
)]
final class ImportKnownTrackersCommand extends Command
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
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Overwrite existing services. Without this flag, services whose `service_id` already exists in the registry are skipped (admin edits preserved).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pidOption = $input->getOption('pid');
        $pid = $pidOption !== null
            ? (int) $pidOption
            : $this->storagePidResolver->resolveDefault();
        $force = (bool) $input->getOption('force');

        $libraryDir = $this->libraryDirectory();
        if (!is_dir($libraryDir)) {
            $io->error("Library directory not found: {$libraryDir}");
            return Command::FAILURE;
        }

        $files = glob($libraryDir . '/*.json') ?: [];
        if ($files === []) {
            $io->warning("No tracker files found in {$libraryDir}.");
            return Command::SUCCESS;
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
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
                $io->error(sprintf('Tracker file %s missing required fields (id, name).', basename($file)));
                $errors++;
                continue;
            }
            $existing = $this->services->findOne((string) $payload['id']);
            if ($existing !== null && !$force) {
                $skipped++;
                continue;
            }
            $this->services->upsert($payload, $pid);
            if ($existing === null) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        $io->success(sprintf(
            'Imported known trackers at pid=%d: %d new, %d updated, %d skipped (already curated), %d error(s).',
            $pid, $inserted, $updated, $skipped, $errors,
        ));
        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function libraryDirectory(): string
    {
        return ServicesLibrary::dataPath();
    }
}
