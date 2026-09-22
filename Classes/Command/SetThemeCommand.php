<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Controller\ThemeDesignerController;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Service\ComplianceCheckService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Banner appearance — the Design tab, minus the live preview.
 *
 * Theme tokens are stored as a diff from the bundle defaults, so a site
 * that never set a token automatically follows a future default. Setting
 * a token here writes only that token; `--reset` drops the site's row
 * and returns it to the defaults entirely.
 *
 * `--check` runs the same compliance audit the designer shows inline.
 * Worth having on the console: it is the one part of the banner that can
 * be wrong in a way nobody notices until it matters, and as an exit code
 * it belongs in CI.
 *
 * Custom colours are opt-in for a reason — the default palette keeps
 * Accept and Decline visually equal, and emphasising Accept is a
 * dark-pattern risk the EDPB's 03/2022 guidance covers. The audit will
 * say so if a palette drifts that way.
 */
final class SetThemeCommand extends Command
{
    public function __construct(
        private readonly ThemeRepository $themeRepository,
        private readonly ComplianceCheckService $compliance,
        private readonly CliEditorSession $session,
        private readonly SiteFinder $siteFinder,
        private readonly \TYPO3\CMS\Core\Localization\LanguageServiceFactory $languageServiceFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set, show, reset or audit a site\'s banner theme (framework, layout, placement, colours).')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Site identifier.')
            ->addOption('be-user', 'u', InputOption::VALUE_REQUIRED, 'BE admin uid or username the change is attributed to.')
            ->addOption(
                'set',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Theme token as key=value (repeatable), e.g. --set framework=bootstrap5 --set position=middle-center.',
            )
            ->addOption('show', null, InputOption::VALUE_NONE, 'Print the stored tokens and exit.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Run the compliance audit and exit non-zero on a critical finding.')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Drop the site\'s theme row and fall back to the bundle defaults.')
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
            $siteObject = $this->siteFinder->getSiteByIdentifier($site);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        if ($input->getOption('show')) {
            $tokens = $this->themeRepository->findBySite($site);
            if ($tokens === null || $tokens === []) {
                $io->success(sprintf('"%s" uses the bundle defaults — no tokens stored.', $site));
                return Command::SUCCESS;
            }
            $output->writeln(json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }

        if ($input->getOption('check')) {
            return $this->audit($io, $siteObject);
        }

        $reset = (bool) $input->getOption('reset');
        /** @var list<string> $sets */
        $sets = $input->getOption('set');

        if (!$reset && $sets === []) {
            $io->error('Nothing to do — pass --set key=value, --reset, --show or --check.');
            return Command::INVALID;
        }
        if ($reset && $sets !== []) {
            $io->error('--reset and --set are mutually exclusive.');
            return Command::INVALID;
        }

        try {
            $beUserId = $this->session->resolveBeUser((string) ($input->getOption('be-user') ?? ''));
            $this->session->open($site, $beUserId);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($reset) {
            $this->themeRepository->deleteDraft($site);
            $io->success(sprintf('"%s": theme reset to the bundle defaults.', $site));
        } else {
            // Start from what is stored so one --set does not wipe the
            // rest of the site's design.
            $tokens = $this->themeRepository->findBySiteDraft($site)
                ?? $this->themeRepository->findBySite($site)
                ?? [];
            foreach ($sets as $pair) {
                if (!is_string($pair) || !str_contains($pair, '=')) {
                    $io->error(sprintf('--set expects key=value, got "%s".', (string) $pair));
                    return Command::INVALID;
                }
                [$key, $value] = explode('=', $pair, 2);
                $key = trim($key);
                if ($key === '') {
                    $io->error('--set needs a token name before the "=".');
                    return Command::INVALID;
                }
                if ($value === '') {
                    // Empty means "stop overriding this token", which is
                    // not the same as setting it to an empty string.
                    unset($tokens[$key]);
                    continue;
                }
                $tokens[$key] = $value;
            }
            // Same gate the Design tab applies on save. It is not
            // cosmetic: `color-*` tokens are concatenated raw into
            // shadow-DOM CSS by RegisterAssets, so an unchecked value is
            // a stored-CSS-injection primitive — and the enum tokens
            // would otherwise store a typo that the frontend silently
            // ignores, leaving someone to wonder why nothing moved.
            $clean = ThemeDesignerController::sanitizeTokens($tokens);
            $rejected = [];
            foreach ($tokens as $key => $value) {
                // A value equal to the token's default is dropped by
                // design — the theme is stored as a diff — so that is
                // not a rejection.
                $isDefault = (ThemeDesignerController::DEFAULT_TOKENS[$key] ?? null) === $value;
                if (!array_key_exists($key, $clean) && !$isDefault) {
                    $rejected[$key] = $value;
                }
            }
            if ($rejected !== []) {
                $messages = [];
                foreach ($rejected as $key => $value) {
                    $messages[] = sprintf('%s = "%s"%s', $key, (string) $value, $this->allowedHint($key));
                }
                $io->error(array_merge(['Rejected — not written:'], $messages));
                return Command::INVALID;
            }

            $this->themeRepository->upsertDraft($site, $clean, $beUserId);
            $io->writeln(json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?: '');
            $io->success(sprintf('"%s": theme updated.', $site));
        }

        if (!$input->getOption('no-publish')) {
            $this->session->publish($site, $beUserId);
        }
        return Command::SUCCESS;
    }

    /**
     * Name the accepted values for the tokens that have a closed set, so
     * a rejected `--set` says what would have worked instead of only
     * that it failed.
     */
    private function allowedHint(string $key): string
    {
        $options = match ($key) {
            'triggerPosition' => array_keys(ThemeDesignerController::TRIGGER_POSITIONS),
            'position' => array_keys(ThemeDesignerController::POSITIONS),
            'theme' => array_keys(ThemeDesignerController::THEMES),
            'layout' => array_keys(ThemeDesignerController::LAYOUTS),
            'colorPaletteLocked' => ['0', '1'],
            default => [],
        };
        if ($options !== []) {
            return ' — allowed: ' . implode(', ', $options);
        }
        if (str_starts_with($key, 'color-')) {
            // The safelist of bare names is deliberately short (the BE
            // picker emits hex), so point at the forms that always work
            // rather than implying every CSS colour name is accepted.
            return ' — must be #hex, rgb()/rgba(), hsl()/hsla(), or one of the audited colour keywords';
        }
        return ' — not a known theme token';
    }

    private function audit(SymfonyStyle $io, \TYPO3\CMS\Core\Site\Entity\Site $site): int
    {
        // `audit()` returns every check it ran, passes included; only
        // the failures are findings.
        $findings = array_values(array_filter(
            $this->compliance->audit($site),
            static fn (array $result): bool => ($result['passed'] ?? true) === false,
        ));
        if ($findings === []) {
            $io->success('No compliance findings.');
            return Command::SUCCESS;
        }

        $language = $this->languageServiceFactory->create('default');
        $rows = [];
        foreach ($findings as $finding) {
            $label = $language->sL(
                'LLL:EXT:simplecmp/Resources/Private/Language/locallang_design.xlf:'
                . (string) ($finding['titleKey'] ?? ''),
            );
            $rows[] = [
                (string) ($finding['severity'] ?? ''),
                (string) ($finding['section'] ?? ''),
                mb_strimwidth($label !== '' ? $label : (string) ($finding['id'] ?? ''), 0, 84, '…'),
            ];
        }
        $io->table(['Severity', 'Section', 'Finding'], $rows);

        if ($this->compliance->worstSeverity($findings) === 'critical') {
            $io->error('Critical compliance findings — the banner should not ship like this.');
            return Command::FAILURE;
        }
        $io->warning(sprintf('%d compliance warning(s).', count($findings)));
        return Command::SUCCESS;
    }
}
