<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Draft session control: the *Entwurf anlegen* / *Entwurf verwerfen* /
 * *Übernehmen* buttons of the module's draft banner.
 *
 * Writing commands open and publish a draft on their own, so this is
 * for the cases where the session itself is the subject: inspecting who
 * holds a lock, throwing away a staging run that went wrong, or taking
 * over a lock somebody left open.
 *
 * Discarding and taking over are both destructive to someone's work, so
 * neither is a default: each needs its own flag, and takeover
 * additionally needs `--force`.
 */
final class DraftCommand extends Command
{
    public function __construct(
        private readonly DraftWorkspaceService $workspace,
        private readonly CliEditorSession $session,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Inspect, open, discard or take over the draft session for a site.')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Site identifier.')
            ->addOption('be-user', 'u', InputOption::VALUE_REQUIRED, 'BE admin uid or username (required for --open, --discard and --takeover).')
            ->addOption('open', null, InputOption::VALUE_NONE, 'Open (or reopen) the draft and hold the lock.')
            ->addOption('discard', null, InputOption::VALUE_NONE, 'Throw away everything staged in the draft and release the lock.')
            ->addOption('takeover', null, InputOption::VALUE_NONE, 'Take a lock held by another user. Requires --force.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm a takeover.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $site = (string) ($input->getOption('site') ?? '');
        if ($site === '') {
            $io->error('--site=<identifier> is required.');
            return Command::INVALID;
        }
        try {
            $this->siteFinder->getSiteByIdentifier($site);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        $open = (bool) $input->getOption('open');
        $discard = (bool) $input->getOption('discard');
        $takeover = (bool) $input->getOption('takeover');

        if ((int) $open + (int) $discard + (int) $takeover > 1) {
            $io->error('--open, --discard and --takeover are mutually exclusive.');
            return Command::INVALID;
        }

        if (!$open && !$discard && !$takeover) {
            return $this->show($io, $site);
        }

        try {
            $beUserId = $this->session->resolveBeUser((string) ($input->getOption('be-user') ?? ''));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        if ($takeover) {
            $lock = $this->workspace->lockForSite($site);
            if ($lock->isUnlocked()) {
                $io->success(sprintf('No lock on "%s" — nothing to take over.', $site));
                return Command::SUCCESS;
            }
            if ($lock->isOwnedBy($beUserId)) {
                $io->success(sprintf('"%s" is already yours (uid %d).', $site, $beUserId));
                return Command::SUCCESS;
            }
            if (!$input->getOption('force')) {
                $io->error(sprintf(
                    'Lock on "%s" is held by BE user uid=%d since %s. '
                    . 'Their staged changes stay in the draft, but they lose the session. '
                    . 'Re-run with --force if that is what you want.',
                    $site,
                    $lock->ownerBeUserId,
                    date('Y-m-d H:i', $lock->acquiredAt),
                ));
                return Command::FAILURE;
            }
            foreach ($this->workspace->relatedScopes($site) as $scope) {
                $this->workspace->takeoverLock($scope, $beUserId);
            }
            $io->warning(sprintf('Took the lock on "%s" from uid %d.', $site, $lock->ownerBeUserId));
            return Command::SUCCESS;
        }

        if ($discard) {
            $lock = $this->workspace->lockForSite($site);
            if (!$lock->isUnlocked() && !$lock->isOwnedBy($beUserId)) {
                $io->error(sprintf(
                    'Draft on "%s" belongs to BE user uid=%d — refusing to discard someone else\'s work. '
                    . 'Use --takeover --force first if you really mean to.',
                    $site,
                    $lock->ownerBeUserId,
                ));
                return Command::FAILURE;
            }
            if (!$this->workspace->hasDraftForSite($site)) {
                // Still release the lock: an open-but-empty session is
                // exactly what a discard should clean up.
                $this->workspace->discardDraftForSite($site);
                $io->success(sprintf('Nothing staged on "%s" — session closed.', $site));
                return Command::SUCCESS;
            }
            $this->workspace->discardDraftForSite($site);
            $io->success(sprintf('Discarded the draft on "%s".', $site));
            return Command::SUCCESS;
        }

        try {
            $this->session->open($site, $beUserId);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf(
            'Draft open on "%s" as uid %d. Stage with --no-publish, then simplecmp:publish.',
            $site,
            $beUserId,
        ));
        return Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, string $site): int
    {
        $state = $this->session->describe($site);
        $io->writeln(sprintf(' site:    <info>%s</info>', $site));
        $io->writeln(sprintf(' session: <info>%s</info>', $state['open'] ? 'open' : 'closed'));
        $io->writeln(sprintf(' staged:  <info>%s</info>', $state['hasContent'] ? 'yes' : 'no'));
        if ($state['ownerBeUserId'] > 0) {
            $io->writeln(sprintf(' holder:  BE user uid <info>%d</info>', $state['ownerBeUserId']));
        }
        return Command::SUCCESS;
    }
}
