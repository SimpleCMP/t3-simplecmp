<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Service\ConfigSnapshotListener;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Manual audit snapshot — for YAML-only edits and pre-deployment
 * checkpoints.
 *
 * The DataHandler hook auto-snapshots when the editor saves changes
 * through the BE UI, but reaches `config/sites/<id>/settings.yaml`
 * edits only by accident (the next BE save catches up). When a
 * deployment touches the YAML directly (Git pull, ops change), run
 * this command so the audit history reflects the new state with a
 * clear `trigger_event = cli-manual` marker.
 *
 * The command is also useful as a "freeze checkpoint": run before a
 * customer rollout, so the post-rollout consent decisions are
 * provably linked to the just-deployed configuration.
 *
 * Idempotent: same canonical content → same hash → dedup INSERT.
 */
final class SnapshotConfigCommand extends Command
{
    public function __construct(
        private readonly ConfigSnapshotListener $listener,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Capture an audit snapshot of the current SimpleCMP banner configuration. '
                . 'Idempotent — only INSERTs when the resolved content differs from the '
                . 'previous snapshot for the site.'
            )
            ->addOption(
                'site',
                's',
                InputOption::VALUE_REQUIRED,
                'Site identifier to snapshot. Mutually exclusive with --all-sites.',
            )
            ->addOption(
                'all-sites',
                'a',
                InputOption::VALUE_NONE,
                'Snapshot every configured site.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $site = $input->getOption('site');
        $allSites = (bool) $input->getOption('all-sites');

        if ($site === null && !$allSites) {
            $io->error('Either --site=<id> or --all-sites is required.');
            return Command::INVALID;
        }
        if ($site !== null && $allSites) {
            $io->error('--site and --all-sites are mutually exclusive.');
            return Command::INVALID;
        }

        $identifiers = $allSites
            ? array_map(static fn ($s) => $s->getIdentifier(), iterator_to_array($this->siteFinder->getAllSites(), false))
            : [(string) $site];

        $written = 0;
        $skipped = 0;
        $missing = 0;
        foreach ($identifiers as $identifier) {
            $hash = $this->listener->snapshotIfChanged($identifier, 'cli-manual', 0);
            if ($hash === null) {
                $io->warning(sprintf('Site "%s" is not configured — skipped.', $identifier));
                $missing++;
                continue;
            }
            // We can't tell from the listener whether the INSERT
            // actually happened (intentional — the API is "ensure a
            // snapshot exists for this hash"). The hash is logged so
            // the operator can correlate with the next BE audit-tab
            // visit.
            $io->writeln(sprintf(
                ' <info>%s</info> → <comment>%s</comment>',
                $identifier,
                substr($hash, 0, 16) . '…',
            ));
            $written++;
        }
        $io->success(sprintf('Snapshot completed: %d sites processed, %d not found.', $written, $missing));
        return Command::SUCCESS;
    }
}
