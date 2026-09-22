<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Read-only answer to "is this install actually set up?".
 *
 * Written for the two moments that need it most: right after a rollout,
 * and when a banner is not doing what someone expected. It reports the
 * three states that are invisible from the frontend — whether settings
 * have been bootstrapped, whether a draft is open (and whose), and
 * whether YAML has drifted away from the values the editor confirmed —
 * plus the registry counts.
 *
 * Touches nothing, so it is safe on production. `--json` makes it usable
 * as a deployment gate.
 */
final class StatusCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly EffectiveSettingsResolver $effectiveSettings,
        private readonly ServiceRepository $serviceRepository,
        private readonly ManagedTrackerRepository $trackerRepository,
        private readonly CliEditorSession $session,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Report SimpleCMP setup state per site: settings bootstrap, drift, managed trackers, registry size and draft/lock state. Read-only.')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Limit to one site identifier. Default: every configured site.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output for deployment gates.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('site');

        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($only !== null && $site->getIdentifier() !== $only) {
                continue;
            }
            $sites[] = $site->getIdentifier();
        }
        if ($sites === []) {
            $io->error($only !== null
                ? sprintf('No site "%s".', $only)
                : 'No sites configured.');
            return Command::INVALID;
        }

        // The service registry is global — reported once, not per site.
        $registry = [
            'services' => count($this->serviceRepository->findAll()),
        ];

        $report = ['registry' => $registry, 'sites' => []];
        foreach ($sites as $identifier) {
            $draft = $this->session->describe($identifier);
            $proposals = $this->effectiveSettings->trackerProposals($identifier);
            $unadopted = 0;
            foreach ($proposals as $proposal) {
                if (!$proposal->alreadyAdopted) {
                    $unadopted++;
                }
            }
            $report['sites'][$identifier] = [
                'enabled' => (bool) $this->effectiveSettings->get($identifier, 'simplecmp.enabled', false),
                'bootstrapped' => $this->effectiveSettings->isBootstrapped($identifier),
                'settingsDrift' => $this->effectiveSettings->countActionableDrift($identifier),
                'managedTrackers' => count($this->trackerRepository->findBySite($identifier)),
                'trackerProposalsPending' => $unadopted,
                'draftOpen' => $draft['open'],
                'draftHasContent' => $draft['hasContent'],
                'draftOwnerBeUserId' => $draft['ownerBeUserId'],
            ];
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }

        $io->title('SimpleCMP status');
        $io->writeln(sprintf(' Service registry (global): <info>%d</info> service(s)', $registry['services']));
        if ($registry['services'] === 0) {
            $io->writeln(' <comment>Empty registry — the banner manages no consent yet.</comment>');
        }
        $io->newLine();

        $rows = [];
        foreach ($report['sites'] as $identifier => $s) {
            if (!$s['enabled']) {
                // A site without the SimpleCMP site set runs no banner at
                // all. Its "drift" and "not bootstrapped" are facts about
                // a feature it does not use — printing them as numbers
                // invites someone to go fix a site that is fine.
                $rows[] = [$identifier, 'no', '–', '–', '–', '–', '–'];
                continue;
            }
            $rows[] = [
                $identifier,
                'yes',
                $s['bootstrapped'] ? 'yes' : 'no',
                $s['settingsDrift'] > 0 ? (string) $s['settingsDrift'] : '–',
                (string) $s['managedTrackers'],
                $s['trackerProposalsPending'] > 0 ? (string) $s['trackerProposalsPending'] : '–',
                $this->describeDraft($s),
            ];
        }
        $io->table(
            ['Site', 'Enabled', 'Bootstrapped', 'Drift', 'Trackers', 'Proposals', 'Draft'],
            $rows,
        );

        foreach ($report['sites'] as $identifier => $s) {
            if (!$s['enabled']) {
                continue;
            }
            if (!$s['bootstrapped']) {
                $io->writeln(sprintf(
                    ' <comment>%s</comment>: settings not bootstrapped — run <info>simplecmp:adopt-settings --site=%s --be-user=…</info>',
                    $identifier,
                    $identifier,
                ));
            }
            if ($s['bootstrapped'] && $s['settingsDrift'] > 0) {
                $io->writeln(sprintf(
                    ' <comment>%s</comment>: %d setting(s) changed in settings.yaml but not adopted — the old values still render. Run <info>simplecmp:adopt-settings --site=%s --be-user=…</info>',
                    $identifier,
                    $s['settingsDrift'],
                    $identifier,
                ));
            }
            if ($s['trackerProposalsPending'] > 0) {
                $io->writeln(sprintf(
                    ' <comment>%s</comment>: %d tracker(s) declared in settings.yaml but never adopted — they do NOT load. Run <info>simplecmp:setup-tracker</info>.',
                    $identifier,
                    $s['trackerProposalsPending'],
                ));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param array{draftOpen: bool, draftHasContent: bool, draftOwnerBeUserId: int} $s
     */
    private function describeDraft(array $s): string
    {
        if (!$s['draftOpen']) {
            return 'closed';
        }
        $who = $s['draftOwnerBeUserId'] > 0 ? sprintf(' (uid %d)', $s['draftOwnerBeUserId']) : '';
        return ($s['draftHasContent'] ? 'open, staged' : 'open, empty') . $who;
    }
}
