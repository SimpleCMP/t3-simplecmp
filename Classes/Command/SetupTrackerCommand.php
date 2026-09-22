<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerFieldSpec;
use SimpleCMP\T3SimpleCmp\Tracker\TrackerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Set up a managed tracker from the command line — the CLI equivalent of
 * the Tracker-Einrichtung tab.
 *
 *     simplecmp:setup-tracker --site=main --be-user=admin \
 *         --type=gtm --set containerId=GTM-XXXXXXX --set consentPosture=block
 *
 * One saved row produces the service record, the consent-gated loader
 * and the inline bootstrap, so this is the whole "make my tracker load
 * behind consent" step.
 *
 * Idempotent by `(site, serviceId)`: running it again updates the row
 * rather than adding a second one, which is what a repeatable deployment
 * needs. `--from-settings` adopts what `simplecmp.trackers` proposes in
 * `settings.yaml`, so a tracker declared in the deployment reaches the
 * registry without anyone retyping its IDs — those YAML entries are
 * proposals and load nothing until adopted.
 */
final class SetupTrackerCommand extends Command
{
    public function __construct(
        private readonly TrackerRegistry $trackerRegistry,
        private readonly TrackerFieldSpec $fieldSpec,
        private readonly ManagedTrackerRepository $trackerRepository,
        private readonly \SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver $effectiveSettings,
        private readonly CliEditorSession $session,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create or update a managed tracker (GTM, GA4, Matomo, Meta, Microsoft UET) so it loads behind consent.')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Site identifier.')
            ->addOption('be-user', 'u', InputOption::VALUE_REQUIRED, 'BE admin uid or username the change is attributed to.')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Provider type, e.g. gtm, ga4, matomo, meta, microsoftUet.')
            ->addOption(
                'set',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Provider config as key=value (repeatable), e.g. --set containerId=GTM-XXXXXXX.',
            )
            ->addOption('from-settings', null, InputOption::VALUE_NONE, 'Adopt every not-yet-adopted tracker proposed by simplecmp.trackers in settings.yaml.')
            ->addOption('no-publish', null, InputOption::VALUE_NONE, 'Stage into the draft and leave it open, to publish together with other commands.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $site = (string) ($input->getOption('site') ?? '');
        $fromSettings = (bool) $input->getOption('from-settings');
        $type = (string) ($input->getOption('type') ?? '');
        $dryRun = (bool) $input->getOption('dry-run');

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
        if ($fromSettings && $type !== '') {
            $io->error('--from-settings and --type are mutually exclusive.');
            return Command::INVALID;
        }
        if (!$fromSettings && $type === '') {
            $io->error(sprintf(
                'Either --type=<provider> or --from-settings is required. Known types: %s.',
                implode(', ', $this->trackerRegistry->getKnownTypes()),
            ));
            return Command::INVALID;
        }

        /** @var list<array{type: string, config: array<string, string>}> $wanted */
        $wanted = [];
        if ($fromSettings) {
            foreach ($this->effectiveSettings->trackerProposals($site) as $proposal) {
                if ($proposal->alreadyAdopted) {
                    continue;
                }
                $config = array_map(
                    static fn (mixed $v): string => is_scalar($v) ? (string) $v : json_encode($v, JSON_THROW_ON_ERROR),
                    $proposal->config,
                );
                $config['serviceId'] = $proposal->serviceId;
                $wanted[] = ['type' => $proposal->type, 'config' => $config];
            }
            if ($wanted === []) {
                $io->success(sprintf('"%s": every tracker from settings.yaml is already adopted.', $site));
                return Command::SUCCESS;
            }
        } else {
            $config = [];
            foreach ((array) $input->getOption('set') as $pair) {
                if (!is_string($pair) || !str_contains($pair, '=')) {
                    $io->error(sprintf('--set expects key=value, got "%s".', (string) $pair));
                    return Command::INVALID;
                }
                [$k, $v] = explode('=', $pair, 2);
                $config[trim($k)] = $v;
            }
            $wanted[] = ['type' => $type, 'config' => $config];
        }

        foreach ($wanted as $entry) {
            if ($this->trackerRegistry->get($entry['type']) === null) {
                $io->error(sprintf(
                    'Unknown tracker type "%s". Known types: %s.',
                    $entry['type'],
                    implode(', ', $this->trackerRegistry->getKnownTypes()),
                ));
                return Command::INVALID;
            }
            $problems = $this->fieldSpec->validate($entry['type'], $entry['config']);
            if ($problems !== []) {
                $io->error($problems);
                return Command::INVALID;
            }
        }

        foreach ($wanted as $entry) {
            $serviceId = ($entry['config']['serviceId'] ?? '') !== ''
                ? (string) $entry['config']['serviceId']
                : $this->trackerRegistry->get($entry['type'])->getDefaultServiceId();
            $io->writeln(sprintf(
                ' <info>%s</info> as service <info>%s</info>: %s',
                $entry['type'],
                $serviceId,
                json_encode($this->stripServiceId($entry['config']), JSON_UNESCAPED_SLASHES) ?: '{}',
            ));
        }

        if ($dryRun) {
            $io->note(sprintf('--dry-run: %d tracker(s) would be written.', count($wanted)));
            return Command::SUCCESS;
        }

        try {
            $beUserId = $this->session->resolveBeUser((string) ($input->getOption('be-user') ?? ''));
            $this->session->open($site, $beUserId);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        foreach ($wanted as $entry) {
            $config = $entry['config'];
            $serviceId = ($config['serviceId'] ?? '') !== ''
                ? (string) $config['serviceId']
                : $this->trackerRegistry->get($entry['type'])->getDefaultServiceId();
            unset($config['serviceId']);

            // Idempotence is keyed on (site, serviceId) — re-running a
            // deployment step must not accumulate duplicate trackers.
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
                $entry['type'],
                $serviceId,
                $config,
                $beUserId,
            );
        }

        if ($input->getOption('no-publish')) {
            $io->success(sprintf(
                '"%s": %d tracker(s) staged. Draft stays open — finish with simplecmp:publish.',
                $site,
                count($wanted),
            ));
            return Command::SUCCESS;
        }

        $this->session->publish($site, $beUserId);
        $io->success(sprintf('"%s": %d tracker(s) set up and published.', $site, count($wanted)));
        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $config
     * @return array<string, string>
     */
    private function stripServiceId(array $config): array
    {
        unset($config['serviceId']);
        return $config;
    }
}
