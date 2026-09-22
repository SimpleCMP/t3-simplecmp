<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Domain\Repository\AllowedStylesheetHostRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ManagedTrackerRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\TranslationOverrideRepository;
use SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Serialise the live SimpleCMP configuration to JSON.
 *
 * The registry lives in database tables, not in `config/`, so a curated
 * setup has had no way to travel: staging it on one environment and
 * reproducing it on another meant clicking through the backend twice and
 * hoping the results matched. Export and
 * {@see ImportRegistryCommand} close that gap — and because the output
 * is a plain, stably-ordered JSON document, it can also live in Git and
 * be reviewed like any other config change.
 *
 * What travels: the global service registry, and per site the managed
 * trackers, banner theme, translation overrides, allowed stylesheet
 * hosts and the adopted (active) settings.
 *
 * What deliberately does not: detections, consent logs and audit
 * snapshots. They are observations of one environment's visitors —
 * copying them into another would fabricate evidence.
 */
final class ExportRegistryCommand extends Command
{
    /**
     * Bumped when the document shape changes in a way the importer must
     * notice. The importer refuses a version it does not understand
     * rather than guessing at a half-matching structure.
     */
    public const int FORMAT_VERSION = 1;

    public function __construct(
        private readonly ServiceRepository $serviceRepository,
        private readonly ManagedTrackerRepository $trackerRepository,
        private readonly ThemeRepository $themeRepository,
        private readonly TranslationOverrideRepository $overrideRepository,
        private readonly AllowedStylesheetHostRepository $allowedHostRepository,
        private readonly EffectiveSettingsResolver $effectiveSettings,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Export the SimpleCMP registry (services, managed trackers, theme, translation overrides, adopted settings) as JSON.')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Limit the per-site part to one site. Default: every configured site.')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Write to this path instead of stdout.');
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
            $io->error($only !== null ? sprintf('No site "%s".', $only) : 'No sites configured.');
            return Command::INVALID;
        }

        $document = [
            'formatVersion' => self::FORMAT_VERSION,
            'exportedAt' => gmdate('c'),
            'services' => $this->exportServices(),
            'sites' => [],
        ];
        foreach ($sites as $identifier) {
            $document['sites'][$identifier] = [
                'activeSettings' => $this->effectiveSettings->activeSnapshot($identifier),
                'trackers' => $this->exportTrackers($identifier),
                // Both repositories return the decoded payload itself
                // (token map / language map), not a row wrapping it.
                'theme' => $this->themeRepository->findBySite($identifier),
                'translationOverrides' => $this->overrideRepository->findBySite($identifier),
                'allowedStylesheetHosts' => $this->allowedHostRepository->hostsForSource('simplecmp-' . $identifier),
            ];
        }

        // Pretty-printed and key-sorted so two exports of the same state
        // are byte-identical and `git diff` shows only real changes.
        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $file = $input->getOption('file');
        if ($file === null) {
            $output->writeln($json);
            return Command::SUCCESS;
        }
        if (file_put_contents((string) $file, $json . "\n") === false) {
            $io->error(sprintf('Could not write "%s".', (string) $file));
            return Command::FAILURE;
        }
        $io->success(sprintf(
            'Wrote %s — %d service(s), %d site(s).',
            (string) $file,
            count($document['services']),
            count($document['sites']),
        ));
        return Command::SUCCESS;
    }

    /**
     * `findAll()` already yields the library's own protocol shape —
     * `id` / `purposes` / `matches`, no `uid` or `pid` — which is what
     * makes an exported document and a library entry interchangeable on
     * import, and keeps per-environment row ids out of the file. Sorted
     * by id so the output is stable across installs.
     *
     * @return list<array<string, mixed>>
     */
    private function exportServices(): array
    {
        $out = $this->serviceRepository->findAll();
        usort($out, static fn (array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));
        return $out;
    }

    /**
     * @return list<array{type: string, serviceId: string, config: array<string, mixed>}>
     */
    private function exportTrackers(string $site): array
    {
        $out = [];
        foreach ($this->trackerRepository->findBySite($site) as $row) {
            $out[] = [
                'type' => (string) $row['tracker_type'],
                'serviceId' => (string) $row['service_id'],
                'config' => $row['config'],
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['serviceId'], $b['serviceId']));
        return $out;
    }
}
