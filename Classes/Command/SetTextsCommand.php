<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Domain\Repository\TranslationOverrideRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Banner wording per language — the Design tab's *Override banner texts*
 * section.
 *
 * Two things live here. `--tone` picks the formal or informal overlay
 * for languages that ship one (German Sie/Du), which is usually all a
 * site needs. `--set <key>=<text>` rewrites an individual bundle string
 * when the default phrasing does not fit.
 *
 * An empty value clears an override rather than storing an empty string,
 * so a field can be handed back to the bundle default without dropping
 * the rest of the language's overrides.
 */
final class SetTextsCommand extends Command
{
    public function __construct(
        private readonly TranslationOverrideRepository $overrideRepository,
        private readonly CliEditorSession $session,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Override banner texts and pick the formal/informal tone for one language.')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Site identifier.')
            ->addOption('be-user', 'u', InputOption::VALUE_REQUIRED, 'BE admin uid or username the change is attributed to.')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'Language code the overrides belong to, e.g. de.')
            ->addOption('tone', null, InputOption::VALUE_REQUIRED, 'Tone overlay for this language (e.g. formal / informal). Empty string clears it.')
            ->addOption(
                'set',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Bundle string as key=text (repeatable). An empty text clears that override.',
            )
            ->addOption('show', null, InputOption::VALUE_NONE, 'Print the stored overrides and exit.')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Drop every override for --language, or for the whole site when no language is given.')
            ->addOption('no-publish', null, InputOption::VALUE_NONE, 'Stage into the draft and leave it open.');
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

        $stored = $this->overrideRepository->findBySite($site) ?? [];

        if ($input->getOption('show')) {
            if ($stored === []) {
                $io->success(sprintf('"%s" uses the bundle texts — no overrides stored.', $site));
                return Command::SUCCESS;
            }
            $output->writeln(json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }

        $language = (string) ($input->getOption('language') ?? '');
        $reset = (bool) $input->getOption('reset');
        $tone = $input->getOption('tone');
        /** @var list<string> $sets */
        $sets = $input->getOption('set');

        if (!$reset && $tone === null && $sets === []) {
            $io->error('Nothing to do — pass --tone, --set key=text, --show or --reset.');
            return Command::INVALID;
        }
        if (!$reset && $language === '') {
            $io->error('--language=<code> is required: an override always belongs to one language.');
            return Command::INVALID;
        }

        try {
            $beUserId = $this->session->resolveBeUser((string) ($input->getOption('be-user') ?? ''));
            $this->session->open($site, $beUserId);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // Work from the draft when one is already staged, so several
        // runs in a --no-publish sequence accumulate.
        $data = $this->overrideRepository->findBySiteDraft($site) ?? $stored;

        if ($reset) {
            if ($language === '') {
                $data = [];
                $io->writeln(' clearing every override on this site');
            } else {
                unset($data[$language]);
                $io->writeln(sprintf(' clearing overrides for <info>%s</info>', $language));
            }
        } else {
            $entry = $data[$language] ?? ['tone' => null, 'overrides' => []];
            if ($tone !== null) {
                $entry['tone'] = (string) $tone !== '' ? (string) $tone : null;
            }
            foreach ($sets as $pair) {
                if (!is_string($pair) || !str_contains($pair, '=')) {
                    $io->error(sprintf('--set expects key=text, got "%s".', (string) $pair));
                    return Command::INVALID;
                }
                [$key, $value] = explode('=', $pair, 2);
                $key = trim($key);
                if ($key === '') {
                    $io->error('--set needs a string key before the "=".');
                    return Command::INVALID;
                }
                if ($value === '') {
                    unset($entry['overrides'][$key]);
                    continue;
                }
                $entry['overrides'][$key] = $value;
            }
            $data[$language] = $entry;
            $io->writeln(sprintf(
                ' <info>%s</info>: tone %s, %d override(s)',
                $language,
                $entry['tone'] ?? '(bundle default)',
                count($entry['overrides']),
            ));
        }

        $this->overrideRepository->upsertDraft($site, $data, $beUserId);
        $io->success(sprintf('"%s": banner texts updated.', $site));

        if (!$input->getOption('no-publish')) {
            $this->session->publish($site, $beUserId);
        }
        return Command::SUCCESS;
    }
}
