<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver;
use SimpleCMP\T3SimpleCmp\Service\SettingsDriftEntry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Confirm the site's YAML banner settings as the active values — the
 * CLI equivalent of the Einstellungen tab's *Aus Site-Konfiguration
 * übernehmen* / *Übernehmen*.
 *
 * Why this step exists at all: banner-content settings ship in
 * `config/sites/<id>/settings.yaml`, which belongs to the deployment,
 * but what a visitor is shown must be something a person confirmed, not
 * whatever the last deploy happened to carry. So a deployed value is a
 * *proposal* until it is adopted, and until the first adoption a site is
 * "not bootstrapped" and runs on the raw YAML.
 *
 * That makes this the command a rollout runs first — and the one to run
 * again after any deploy that changes `simplecmp.*`, otherwise the new
 * values sit as drift and the old ones keep rendering. `--dry-run`
 * prints the drift without touching anything.
 */
final class AdoptSettingsCommand extends Command
{
    public function __construct(
        private readonly EffectiveSettingsResolver $effectiveSettings,
        private readonly CliEditorSession $session,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Adopt a site\'s YAML banner settings as the editor-confirmed active values (all keys, or selected ones).')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Site identifier.')
            ->addOption('be-user', 'u', InputOption::VALUE_REQUIRED, 'BE admin uid or username the change is attributed to.')
            ->addOption(
                'key',
                'k',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Adopt only this key (repeatable), e.g. simplecmp.floatingTriggerLabel. Default: every drifting key.',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be adopted and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $site = (string) ($input->getOption('site') ?? '');
        $dryRun = (bool) $input->getOption('dry-run');
        /** @var list<string> $keys */
        $keys = $input->getOption('key');

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

        // drift() reports every editor-content key, in-sync ones
        // included; only the actionable ones are ours to adopt.
        $drift = array_values(array_filter(
            $this->effectiveSettings->drift($site),
            static fn (SettingsDriftEntry $entry): bool => $entry->needsAction(),
        ));
        $driftKeys = array_map(static fn (SettingsDriftEntry $entry): string => $entry->key, $drift);

        if ($keys !== []) {
            $unknown = array_diff($keys, $driftKeys);
            if ($unknown !== []) {
                // Not an error: a key that does not drift is already
                // active with the YAML value, so asking for it is a
                // no-op, not a mistake.
                $io->note(sprintf(
                    'Not drifting (already active, nothing to adopt): %s',
                    implode(', ', $unknown),
                ));
            }
            $keys = array_values(array_intersect($keys, $driftKeys));
        }

        $todo = $keys !== [] ? $keys : $driftKeys;
        if ($todo === []) {
            $io->success(sprintf('"%s": settings already match the site configuration.', $site));
            return Command::SUCCESS;
        }

        foreach ($drift as $entry) {
            if (!in_array($entry->key, $todo, true)) {
                continue;
            }
            $io->writeln(sprintf(
                ' <info>%s</info>: %s → %s',
                $entry->key,
                $this->render($entry->activeValue),
                $this->render($entry->yamlValue),
            ));
        }

        if ($dryRun) {
            $io->note(sprintf('--dry-run: %d key(s) would be adopted.', count($todo)));
            return Command::SUCCESS;
        }

        try {
            $beUserId = $this->session->resolveBeUser((string) ($input->getOption('be-user') ?? ''));
            // Adoption writes to tx_t3simplecmp_active_settings, not to a
            // draft table — but the BE gates it on an open draft, and the
            // CLI must not be a way around a colleague's in-progress work.
            $this->session->open($site, $beUserId);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($keys === []) {
            $this->effectiveSettings->adoptAll($site, $beUserId);
        } else {
            foreach ($todo as $key) {
                $this->effectiveSettings->adoptKey($site, $key, $beUserId);
            }
        }

        // Settings are not draft content, so this publish usually just
        // closes the session — which is exactly what we want it to do.
        $this->session->publish($site, $beUserId);

        $io->success(sprintf('"%s": adopted %d setting(s).', $site, count($todo)));
        return Command::SUCCESS;
    }

    private function render(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '?';
    }
}
