<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Promote a site's staged draft to live and release the lock.
 *
 * Only needed after running writing commands with `--no-publish`, which
 * is how several steps are staged into a single, atomic publish:
 *
 *     simplecmp:adopt-service google-analytics --no-publish …
 *     simplecmp:setup-tracker --type=gtm --no-publish …
 *     simplecmp:publish --site=main --be-user=…
 *
 * Publishing an empty draft is not an error — it closes the session.
 */
final class PublishCommand extends Command
{
    public function __construct(
        private readonly CliEditorSession $session,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Publish the staged SimpleCMP draft for a site and release the editing lock.')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Site identifier.')
            ->addOption('be-user', 'u', InputOption::VALUE_REQUIRED, 'BE admin uid or username the publish is attributed to.');
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
            $beUserId = $this->session->resolveBeUser((string) ($input->getOption('be-user') ?? ''));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        $results = $this->session->publish($site, $beUserId);
        $promoted = array_keys(array_filter($results));

        if ($promoted === []) {
            $io->success(sprintf('Nothing staged for "%s" — draft closed.', $site));
            return Command::SUCCESS;
        }
        foreach ($promoted as $scope) {
            $io->writeln(sprintf(' promoted scope <info>%s</info>', $scope));
        }
        $io->success(sprintf('Published "%s".', $site));
        return Command::SUCCESS;
    }
}
