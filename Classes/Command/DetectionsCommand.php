<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Domain\Repository\AllowedStylesheetHostRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Service\DetectionListPresenter;
use SimpleCMP\T3SimpleCmp\Service\DetectionResetGeneration;
use SimpleCMP\T3SimpleCmp\Service\ServiceCurator;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Triage the trackers the recorder reported — the Detektionen tab.
 *
 * Without options it lists; the actions each take one or more `--uid`.
 * The four states are derived the same way the module derives them, by
 * the same presenter, so the console and the tab never disagree about
 * what still needs a decision:
 *
 *   kuratiert  a registry service already covers this cookie/origin
 *   erkannt    the bundled library knows it, the registry does not yet
 *   unbekannt  neither knows it — needs a curation decision
 *   verworfen  explicitly dismissed
 *
 * Removal stays two steps here as well: `--dismiss` flags a row and
 * keeps it as an audit trail, `--purge` deletes it and only ever
 * touches rows that were dismissed first.
 */
final class DetectionsCommand extends Command
{
    public function __construct(
        private readonly DetectionRepository $detectionRepository,
        private readonly DetectionListPresenter $listPresenter,
        private readonly ServiceRepository $serviceRepository,
        private readonly ServiceCurator $serviceCurator,
        private readonly AllowedStylesheetHostRepository $allowedHostRepository,
        private readonly DetectionResetGeneration $resetGeneration,
        private readonly StoragePidResolver $storagePidResolver,
        private readonly CliEditorSession $session,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('List and triage tracker detections: adopt, dismiss, undismiss, purge, or allow a stylesheet host.')
            ->addOption('uid', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Detection uid to act on (repeatable).')
            ->addOption('state', null, InputOption::VALUE_REQUIRED, 'Filter the listing: pending (default), erkannt, unbekannt, kuratiert, verworfen, all.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows to list.', '50')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Site whose draft carries a write (required for every action).')
            ->addOption('be-user', 'u', InputOption::VALUE_REQUIRED, 'BE admin uid or username the change is attributed to.')
            ->addOption('adopt', null, InputOption::VALUE_NONE, 'Adopt the matching library entry into the registry (erkannt rows).')
            ->addOption('dismiss', null, InputOption::VALUE_NONE, 'Mark as verworfen — kept as an audit trail, hidden from the actionable list.')
            ->addOption('undismiss', null, InputOption::VALUE_NONE, 'Bring a verworfen row back.')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Delete dismissed rows for good. Only touches rows that are dismissed.')
            ->addOption('allow-stylesheet-host', null, InputOption::VALUE_NONE, 'Allowlist the row\'s host for stylesheets instead of gating it.')
            ->addOption('no-publish', null, InputOption::VALUE_NONE, 'Leave the draft open after an adopt.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $uidOpts */
        $uidOpts = $input->getOption('uid');
        $uids = array_values(array_filter(array_map('intval', $uidOpts), static fn (int $u): bool => $u > 0));

        $actions = array_filter([
            'adopt' => (bool) $input->getOption('adopt'),
            'dismiss' => (bool) $input->getOption('dismiss'),
            'undismiss' => (bool) $input->getOption('undismiss'),
            'purge' => (bool) $input->getOption('purge'),
            'allow-stylesheet-host' => (bool) $input->getOption('allow-stylesheet-host'),
        ]);
        if (count($actions) > 1) {
            $io->error('Pick one action: ' . implode(', ', array_map(static fn (string $a): string => '--' . $a, array_keys($actions))));
            return Command::INVALID;
        }
        $action = array_key_first($actions);

        if ($action === null) {
            return $this->listDetections($io, (string) $input->getOption('state'), (int) $input->getOption('limit'));
        }
        if ($uids === []) {
            $io->error('--uid=<n> is required for an action (repeatable).');
            return Command::INVALID;
        }

        $site = (string) ($input->getOption('site') ?? '');
        if ($site === '') {
            $io->error('--site=<identifier> is required (the draft is opened per site).');
            return Command::INVALID;
        }
        try {
            $this->siteFinder->getSiteByIdentifier($site);
            $beUserId = $this->session->resolveBeUser((string) ($input->getOption('be-user') ?? ''));
            $this->session->open($site, $beUserId);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $result = match ($action) {
            'adopt' => $this->adopt($io, $uids, $beUserId),
            'dismiss' => $this->dismiss($io, $uids),
            'undismiss' => $this->undismiss($io, $uids),
            'purge' => $this->purge($io, $uids),
            'allow-stylesheet-host' => $this->allowStylesheetHost($io, $uids, $beUserId),
            default => Command::INVALID,
        };

        if ($result === Command::SUCCESS && !$input->getOption('no-publish')) {
            $this->session->publish($site, $beUserId);
        }
        return $result;
    }

    private function listDetections(SymfonyStyle $io, string $state, int $limit): int
    {
        $state = $state !== '' ? $state : 'pending';
        $limit = $limit > 0 ? $limit : 50;
        $context = $this->listPresenter->loadStateContext();

        $rows = [];
        foreach ($this->detectionRepository->recent(1000) as $detection) {
            $derived = DetectionListPresenter::deriveState(
                $detection,
                $context['services'],
                $context['library'],
                $context['upstreamCache'],
            );
            $rowState = (string) ($derived['state'] ?? '');
            if (!$this->matchesFilter($rowState, $state)) {
                continue;
            }
            $rows[] = [
                (string) $detection['uid'],
                $rowState,
                (string) ($detection['kind'] ?? ''),
                mb_strimwidth((string) ($detection['identifier'] ?? ''), 0, 46, '…'),
                (string) ($detection['source'] ?? ''),
                (string) ($detection['occurrences'] ?? 0),
            ];
            if (count($rows) >= $limit) {
                break;
            }
        }

        if ($rows === []) {
            $io->success(sprintf('No detections in state "%s".', $state));
            return Command::SUCCESS;
        }
        $io->table(['uid', 'State', 'Kind', 'Identifier', 'Source', 'Reports'], $rows);
        $io->writeln(' <comment>erkannt</comment> → --adopt · <comment>unbekannt</comment> → simplecmp:curate-service · <comment>any</comment> → --dismiss');
        return Command::SUCCESS;
    }

    private function matchesFilter(string $rowState, string $filter): bool
    {
        return match ($filter) {
            'all' => true,
            // "pending" is the module's default view: everything that
            // still needs a human decision.
            'pending' => in_array($rowState, [DetectionListPresenter::STATE_RECOGNIZED, DetectionListPresenter::STATE_UNKNOWN], true),
            default => $rowState === $filter,
        };
    }

    /**
     * @param list<int> $uids
     */
    private function adopt(SymfonyStyle $io, array $uids, int $beUserId): int
    {
        $pid = $this->storagePidResolver->resolveDefault();
        $adopted = 0;
        foreach ($uids as $uid) {
            $row = $this->detectionRepository->findOne($uid);
            if ($row === null) {
                $io->warning(sprintf('No detection uid=%d.', $uid));
                continue;
            }
            $match = $this->serviceCurator->findLibraryMatch($row);
            if ($match === null) {
                $io->warning(sprintf(
                    'uid=%d (%s) has no library match — curate it with simplecmp:curate-service.',
                    $uid,
                    (string) ($row['identifier'] ?? ''),
                ));
                continue;
            }
            $this->serviceRepository->upsertDraft(
                $this->session->globalScope(),
                $match,
                $beUserId,
                true,
                $this->storagePidResolver->resolveForSource((string) ($row['source'] ?? '')) ?: $pid,
            );
            $io->writeln(sprintf(' uid=%d → adopted <info>%s</info>', $uid, (string) $match['id']));
            $adopted++;
        }
        if ($adopted === 0) {
            $io->warning('Nothing adopted.');
            return Command::FAILURE;
        }
        $io->success(sprintf('Adopted %d service(s).', $adopted));
        return Command::SUCCESS;
    }

    /**
     * @param list<int> $uids
     */
    private function dismiss(SymfonyStyle $io, array $uids): int
    {
        $n = 0;
        foreach ($uids as $uid) {
            $n += $this->detectionRepository->dismiss($uid);
        }
        $io->success(sprintf('Dismissed %d row(s). They stay in the database as an audit trail.', $n));
        return Command::SUCCESS;
    }

    /**
     * @param list<int> $uids
     */
    private function undismiss(SymfonyStyle $io, array $uids): int
    {
        $n = 0;
        foreach ($uids as $uid) {
            $n += $this->detectionRepository->undismiss($uid);
        }
        $io->success(sprintf('Brought back %d row(s).', $n));
        return Command::SUCCESS;
    }

    /**
     * @param list<int> $uids
     */
    private function purge(SymfonyStyle $io, array $uids): int
    {
        $purge = $this->detectionRepository->purgeDismissed($uids);
        if ($purge['deleted'] === 0) {
            $io->warning('Nothing purged — purge only deletes rows that were dismissed first.');
            return Command::SUCCESS;
        }
        // Without the bump, browsers already reporting hold a stale
        // cross-session dedup marker and a still-present tracker stays
        // undetected for the whole TTL.
        foreach ($purge['sources'] as $source) {
            $this->resetGeneration->bump($source);
        }
        $io->success(sprintf('Purged %d row(s); re-detection re-armed for %d source(s).', $purge['deleted'], count($purge['sources'])));
        return Command::SUCCESS;
    }

    /**
     * @param list<int> $uids
     */
    private function allowStylesheetHost(SymfonyStyle $io, array $uids, int $beUserId): int
    {
        $n = 0;
        foreach ($uids as $uid) {
            $row = $this->detectionRepository->findOne($uid);
            if ($row === null) {
                $io->warning(sprintf('No detection uid=%d.', $uid));
                continue;
            }
            $host = $this->hostOf((string) ($row['identifier'] ?? ''));
            if ($host === null) {
                $io->warning(sprintf('uid=%d has no host to allow (identifier: %s).', $uid, (string) ($row['identifier'] ?? '')));
                continue;
            }
            $source = (string) ($row['source'] ?? '');
            $scope = str_starts_with($source, 'simplecmp-') ? substr($source, strlen('simplecmp-')) : $source;
            $this->allowedHostRepository->allowDraft($scope, $host, $beUserId);
            $io->writeln(sprintf(' uid=%d → allowed <info>%s</info> for stylesheets', $uid, $host));
            $n++;
        }
        if ($n === 0) {
            return Command::FAILURE;
        }
        $io->success(sprintf('Allowed %d host(s).', $n));
        return Command::SUCCESS;
    }

    private function hostOf(string $identifier): ?string
    {
        if ($identifier === '') {
            return null;
        }
        $host = parse_url($identifier, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }
        // Detections store bare hosts for some kinds.
        return str_contains($identifier, '.') && !str_contains($identifier, '/')
            ? strtolower($identifier)
            : null;
    }
}
