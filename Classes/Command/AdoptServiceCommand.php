<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Library\ServicesLibrary;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Copy entries from the bundled services library into the registry —
 * the CLI equivalent of the Bibliothek tab's *Übernehmen*.
 *
 *     simplecmp:adopt-service google-analytics linkedin --site=main --be-user=admin
 *
 * Adoption is how a third-party service becomes something the banner
 * manages consent for, with the vendor data, purposes and cookie/origin
 * matchers the library curates. `--search` finds the slug when you only
 * know the vendor's name.
 *
 * Idempotent: re-adopting refreshes the entry from the library and never
 * duplicates it, so this is safe to keep in a deployment script.
 */
final class AdoptServiceCommand extends Command
{
    public function __construct(
        private readonly ServiceRepository $serviceRepository,
        private readonly StoragePidResolver $storagePidResolver,
        private readonly CliEditorSession $session,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Adopt one or more bundled-library services into the consent registry.')
            ->addArgument('serviceIds', InputArgument::IS_ARRAY, 'Library service ids, e.g. google-analytics linkedin.')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Site whose draft carries the change (the registry itself is global).')
            ->addOption('be-user', 'u', InputOption::VALUE_REQUIRED, 'BE admin uid or username the change is attributed to.')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'List library entries matching this text and exit — use it to find a slug.')
            ->addOption('no-publish', null, InputOption::VALUE_NONE, 'Stage into the draft and leave it open, to publish together with other commands.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be adopted and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $search = $input->getOption('search');
        if ($search !== null) {
            return $this->search($io, (string) $search);
        }

        /** @var list<string> $serviceIds */
        $serviceIds = $input->getArgument('serviceIds');
        $site = (string) ($input->getOption('site') ?? '');

        if ($serviceIds === []) {
            $io->error('Pass at least one library service id, or --search=<text> to find one.');
            return Command::INVALID;
        }
        if ($site === '') {
            $io->error('--site=<identifier> is required (the draft is opened per site).');
            return Command::INVALID;
        }
        try {
            $this->siteFinder->getSiteByIdentifier($site);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        $entries = [];
        $missing = [];
        foreach ($serviceIds as $serviceId) {
            $entry = $this->loadLibraryEntry($serviceId);
            if ($entry === null) {
                $missing[] = $serviceId;
                continue;
            }
            $entries[] = $entry;
        }
        if ($missing !== []) {
            $io->error(sprintf(
                'Not in the bundled library: %s. Try --search=<text>.',
                implode(', ', $missing),
            ));
            return Command::INVALID;
        }

        foreach ($entries as $entry) {
            $io->writeln(sprintf(
                ' <info>%s</info> — %s, purposes: %s',
                (string) $entry['id'],
                (string) ($entry['vendor'] ?? 'unknown vendor'),
                implode(', ', $entry['purposes'] ?? []) ?: '–',
            ));
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('--dry-run: %d service(s) would be adopted.', count($entries)));
            return Command::SUCCESS;
        }

        try {
            $beUserId = $this->session->resolveBeUser((string) ($input->getOption('be-user') ?? ''));
            $this->session->open($site, $beUserId);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $pid = $this->storagePidResolver->resolveDefault();
        foreach ($entries as $entry) {
            $this->serviceRepository->upsertDraft(
                $this->session->globalScope(),
                $entry,
                $beUserId,
                true,
                $pid,
            );
        }

        if ($input->getOption('no-publish')) {
            $io->success(sprintf(
                '%d service(s) staged. Draft stays open — finish with simplecmp:publish.',
                count($entries),
            ));
            return Command::SUCCESS;
        }

        $this->session->publish($site, $beUserId);
        $io->success(sprintf('%d service(s) adopted and published.', count($entries)));
        return Command::SUCCESS;
    }

    private function search(SymfonyStyle $io, string $needle): int
    {
        $needle = mb_strtolower(trim($needle));
        if ($needle === '') {
            $io->error('--search needs a non-empty value.');
            return Command::INVALID;
        }
        $rows = [];
        foreach (ServicesLibrary::services() as $entry) {
            $haystack = mb_strtolower(
                (string) ($entry['id'] ?? '') . ' '
                . (string) ($entry['name'] ?? '') . ' '
                . (string) ($entry['vendor'] ?? ''),
            );
            if (!str_contains($haystack, $needle)) {
                continue;
            }
            $rows[] = [
                (string) ($entry['id'] ?? ''),
                (string) ($entry['name'] ?? ''),
                (string) ($entry['vendor'] ?? ''),
                implode(', ', $entry['purposes'] ?? []),
            ];
        }
        if ($rows === []) {
            $io->warning(sprintf('Nothing in the bundled library matches "%s".', $needle));
            return Command::SUCCESS;
        }
        $io->table(['Service id', 'Name', 'Vendor', 'Purposes'], $rows);
        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLibraryEntry(string $serviceId): ?array
    {
        foreach (ServicesLibrary::services() as $entry) {
            if (isset($entry['id']) && (string) $entry['id'] === $serviceId) {
                return $entry;
            }
        }
        return null;
    }
}
