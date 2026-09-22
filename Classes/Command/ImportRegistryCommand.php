<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Domain\Repository\AllowedStylesheetHostRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\TranslationOverrideRepository;
use SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Apply a document produced by {@see ExportRegistryCommand}.
 *
 * This is the half that makes a curated consent setup deployable: stage
 * it on one environment, export, review the JSON in a merge request,
 * import on the next. Idempotent — re-running an unchanged document
 * changes nothing, so it belongs in a deployment script.
 *
 * **It adds and updates; it never removes.** A service missing from the
 * document is left alone rather than deleted. Withdrawing a service
 * revokes the consent UI for something that may still be loading, so it
 * stays a deliberate act (`unadopt` in the Bibliothek tab) instead of a
 * side effect of importing a file someone trimmed.
 *
 * Sites are matched by identifier. One the target install does not have
 * is skipped with a warning — silently inventing a site's configuration
 * would be worse than saying so.
 */
final class ImportRegistryCommand extends Command
{
    public function __construct(
        private readonly ServiceRepository $serviceRepository,
        private readonly ManagedTrackerRepository $trackerRepository,
        private readonly ThemeRepository $themeRepository,
        private readonly TranslationOverrideRepository $overrideRepository,
        private readonly AllowedStylesheetHostRepository $allowedHostRepository,
        private readonly EffectiveSettingsResolver $effectiveSettings,
        private readonly StoragePidResolver $storagePidResolver,
        private readonly TrackerRegistry $trackerRegistry,
        private readonly CliEditorSession $session,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Import a SimpleCMP registry document (from simplecmp:export-registry). Adds and updates; never removes.')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Path to the JSON document.')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Import only this site\'s part (the global service registry is always applied).')
            ->addOption('be-user', 'u', InputOption::VALUE_REQUIRED, 'BE admin uid or username the import is attributed to.')
            ->addOption('skip-settings', null, InputOption::VALUE_NONE, 'Leave adopted settings alone — useful when per-environment URLs differ.')
            ->addOption('no-publish', null, InputOption::VALUE_NONE, 'Stage into the draft and leave it open.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) ($input->getOption('file') ?? '');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($file === '' || !is_readable($file)) {
            $io->error(sprintf('--file=<path> is required and must be readable (got "%s").', $file));
            return Command::INVALID;
        }
        try {
            $document = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Not valid JSON: %s', $e->getMessage()));
            return Command::INVALID;
        }
        if (!is_array($document)) {
            $io->error('The document must be a JSON object.');
            return Command::INVALID;
        }
        $version = (int) ($document['formatVersion'] ?? 0);
        if ($version !== ExportRegistryCommand::FORMAT_VERSION) {
            $io->error(sprintf(
                'Unsupported formatVersion %d — this build reads %d. Re-export from the source install.',
                $version,
                ExportRegistryCommand::FORMAT_VERSION,
            ));
            return Command::INVALID;
        }

        $onlySite = $input->getOption('site');
        $known = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $known[] = $site->getIdentifier();
        }

        /** @var list<array<string, mixed>> $services */
        $services = array_values(array_filter(
            (array) ($document['services'] ?? []),
            static fn (mixed $entry): bool => is_array($entry) && isset($entry['id']),
        ));

        $existingServiceIds = array_map(
            static fn (array $s): string => (string) ($s['id'] ?? ''),
            $this->serviceRepository->findAll(),
        );
        $newServices = array_values(array_filter(
            $services,
            static fn (array $s): bool => !in_array((string) $s['id'], $existingServiceIds, true),
        ));

        $io->writeln(sprintf(
            ' services: <info>%d</info> in document — %d new, %d already present (refreshed)',
            count($services),
            count($newServices),
            count($services) - count($newServices),
        ));

        /** @var array<string, array<string, mixed>> $siteDocs */
        $siteDocs = (array) ($document['sites'] ?? []);
        $applySites = [];
        foreach ($siteDocs as $identifier => $payload) {
            $identifier = (string) $identifier;
            if ($onlySite !== null && $identifier !== $onlySite) {
                continue;
            }
            if (!in_array($identifier, $known, true)) {
                $io->warning(sprintf('Site "%s" is in the document but not configured here — skipped.', $identifier));
                continue;
            }
            $applySites[$identifier] = (array) $payload;
            $io->writeln(sprintf(
                ' site <info>%s</info>: %d tracker(s), theme %s, overrides %s, %d allowed host(s)%s',
                $identifier,
                count((array) ($payload['trackers'] ?? [])),
                ($payload['theme'] ?? null) !== null ? 'yes' : 'no',
                ($payload['translationOverrides'] ?? null) !== null ? 'yes' : 'no',
                count((array) ($payload['allowedStylesheetHosts'] ?? [])),
                $input->getOption('skip-settings') ? ', settings skipped' : sprintf(
                    ', %d setting(s)',
                    count((array) ($payload['activeSettings'] ?? [])),
                ),
            ));
        }

        if ($applySites === [] && $services === []) {
            $io->warning('Nothing in this document applies to this install.');
            return Command::SUCCESS;
        }
        if ($dryRun) {
            $io->note('--dry-run: nothing written.');
            return Command::SUCCESS;
        }

        // The global registry has no site of its own; its draft is opened
        // alongside whichever site we are importing for. With --site given
        // and no site part in the document, that is still the site the
        // operator named.
        $draftSite = $onlySite !== null ? (string) $onlySite : (array_key_first($applySites) ?? ($known[0] ?? ''));
        if ($draftSite === '') {
            $io->error('No site to open a draft for.');
            return Command::INVALID;
        }

        try {
            $beUserId = $this->session->resolveBeUser((string) ($input->getOption('be-user') ?? ''));
            $this->session->open($draftSite, $beUserId);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $pid = $this->storagePidResolver->resolveDefault();
        foreach ($services as $service) {
            // fromLibrary=false: an imported service is the source
            // install's curation, which may well have been edited away
            // from the library entry. Claiming otherwise would make the
            // Dienste tab offer a "refresh from library" that silently
            // discards it.
            $this->serviceRepository->upsertDraft(
                $this->session->globalScope(),
                $service,
                $beUserId,
                false,
                $pid,
            );
        }

        foreach ($applySites as $identifier => $payload) {
            $this->importTrackers($io, $identifier, (array) ($payload['trackers'] ?? []), $beUserId);

            $theme = $payload['theme'] ?? null;
            if (is_array($theme)) {
                $this->themeRepository->upsertDraft($identifier, $theme, $beUserId);
            }
            $overrides = $payload['translationOverrides'] ?? null;
            if (is_array($overrides)) {
                $this->overrideRepository->upsertDraft($identifier, $overrides, $beUserId);
            }
            foreach ((array) ($payload['allowedStylesheetHosts'] ?? []) as $host) {
                if (is_string($host) && $host !== '') {
                    $this->allowedHostRepository->allowDraft($identifier, $host, $beUserId);
                }
            }
            if (!$input->getOption('skip-settings')) {
                foreach ((array) ($payload['activeSettings'] ?? []) as $key => $value) {
                    $this->effectiveSettings->setCustom($identifier, (string) $key, $value, $beUserId);
                }
            }
        }

        if ($input->getOption('no-publish')) {
            $io->success('Imported into the draft. Draft stays open — finish with simplecmp:publish.');
            return Command::SUCCESS;
        }

        $this->session->publish($draftSite, $beUserId);
        foreach (array_keys($applySites) as $identifier) {
            if ($identifier !== $draftSite) {
                // Each site owns its own scope, so a multi-site document
                // needs one publish per site — the draft for the others
                // was opened implicitly by their writes.
                $this->session->publish($identifier, $beUserId);
            }
        }
        $io->success(sprintf(
            'Imported %d service(s) and %d site(s) from %s.',
            count($services),
            count($applySites),
            $file,
        ));
        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>>|array<mixed> $trackers
     */
    private function importTrackers(SymfonyStyle $io, string $site, array $trackers, int $beUserId): void
    {
        foreach ($trackers as $tracker) {
            if (!is_array($tracker)) {
                continue;
            }
            $type = (string) ($tracker['type'] ?? '');
            $serviceId = (string) ($tracker['serviceId'] ?? '');
            if ($type === '' || $serviceId === '') {
                continue;
            }
            if ($this->trackerRegistry->get($type) === null) {
                // A provider this install does not have — importing the
                // row would produce a tracker nothing can materialize.
                $io->warning(sprintf(
                    'Site "%s": tracker type "%s" is not available here — skipped.',
                    $site,
                    $type,
                ));
                continue;
            }
            $existingUid = null;
            foreach ($this->trackerRepository->findBySiteDraft($site) as $row) {
                if ((string) ($row['service_id'] ?? '') === $serviceId) {
                    $existingUid = (int) $row['uid'];
                    break;
                }
            }
            $this->trackerRepository->saveDraft(
                $site,
                $existingUid,
                $site,
                $type,
                $serviceId,
                (array) ($tracker['config'] ?? []),
                $beUserId,
            );
        }
    }
}
