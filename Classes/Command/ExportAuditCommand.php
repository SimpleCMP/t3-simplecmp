<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Service\AuskunftCsvExporter;
use SimpleCMP\T3SimpleCmp\Service\AuskunftJsonExporter;
use SimpleCMP\T3SimpleCmp\Service\VisitorAuskunftService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Phase-3 audit-bundle exporter. Produces a JSON or CSV bundle for
 * one of three filter kinds, for legal/forensic export or visitor
 * Auskunft delivery.
 *
 * Exactly one of `--snapshot`, `--visitor`, `--visitor-hash`, or
 * `--since` must be given. `--site` is required for visitor + since
 * filters (the hash recipe and the consent_log index are both
 * site-scoped); not relevant for snapshot filter (version_hash is
 * globally unique).
 *
 * The exporter writes to stdout by default — redirect with
 * `--output=/path/to/out.json` when you want a file on disk (helpful
 * when the bundle is large enough to flood the terminal).
 */
final class ExportAuditCommand extends Command
{
    private const string FORMAT_JSON = 'json';
    private const string FORMAT_CSV = 'csv';

    public function __construct(
        private readonly VisitorAuskunftService $auskunft,
        private readonly AuskunftJsonExporter $jsonExporter,
        private readonly AuskunftCsvExporter $csvExporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Export a SimpleCMP audit bundle (snapshots + visitor decisions) for a single filter '
                . '— DSGVO Art. 15 Auskunft, legal forensics, or migration. JSON or CSV.'
            )
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Site identifier — required for --visitor / --visitor-hash / --since.')
            ->addOption('snapshot', null, InputOption::VALUE_REQUIRED, 'Filter by a snapshot version_hash. Exports the snapshot row + all decisions made against it.')
            ->addOption('visitor', null, InputOption::VALUE_REQUIRED, 'Filter by raw visitor UUID. The bridge secret is used to recompute the hash.')
            ->addOption('visitor-hash', null, InputOption::VALUE_REQUIRED, 'Filter by pseudonymized visitor sha256 (skip the rehash step).')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Filter by date — YYYY-MM-DD. Everything in [since, now).')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Bridge source used to hash the UUID. Defaults to "simplecmp-<site>" when --site is given.')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'json (default) or csv.', self::FORMAT_JSON)
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to a file instead of stdout.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows per section (snapshots + decisions). Default 1000.', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $snapshotFilter = $input->getOption('snapshot');
        $visitorFilter = $input->getOption('visitor');
        $visitorHashFilter = $input->getOption('visitor-hash');
        $sinceFilter = $input->getOption('since');

        $filtersSet = array_filter([
            'snapshot' => is_string($snapshotFilter) && $snapshotFilter !== '',
            'visitor' => is_string($visitorFilter) && $visitorFilter !== '',
            'visitor-hash' => is_string($visitorHashFilter) && $visitorHashFilter !== '',
            'since' => is_string($sinceFilter) && $sinceFilter !== '',
        ]);
        if (count($filtersSet) !== 1) {
            $io->error('Exactly one of --snapshot / --visitor / --visitor-hash / --since must be given.');
            return Command::INVALID;
        }

        $format = $input->getOption('format');
        if ($format !== self::FORMAT_JSON && $format !== self::FORMAT_CSV) {
            $io->error('--format must be json or csv.');
            return Command::INVALID;
        }

        $limitRaw = $input->getOption('limit');
        $limit = is_string($limitRaw) && ctype_digit($limitRaw) ? (int) $limitRaw : 1000;
        $limit = max(1, $limit);

        $site = $input->getOption('site');
        $site = is_string($site) && $site !== '' ? $site : null;

        $sourceRaw = $input->getOption('source');
        $source = is_string($sourceRaw) && $sourceRaw !== ''
            ? $sourceRaw
            : ($site !== null ? 'simplecmp-' . $site : '');

        $bundle = match (key($filtersSet)) {
            'snapshot' => $this->auskunft->buildForSnapshot((string) $snapshotFilter, $limit),
            'visitor' => $this->visitorFilter($io, (string) $visitorFilter, $site, $source, $limit),
            'visitor-hash' => $this->visitorHashFilter($io, (string) $visitorHashFilter, $site, $limit),
            'since' => $this->sinceFilter($io, (string) $sinceFilter, $site, $limit),
            default => null,
        };
        if ($bundle === null) {
            return Command::INVALID;
        }

        $payload = $format === self::FORMAT_JSON
            ? $this->jsonExporter->encode($bundle, 'cli')
            : $this->csvExporter->encode($bundle, 'cli');

        $outputPath = $input->getOption('output');
        if (is_string($outputPath) && $outputPath !== '') {
            $bytes = file_put_contents($outputPath, $payload);
            if ($bytes === false) {
                $io->error(sprintf('Could not write to %s.', $outputPath));
                return Command::FAILURE;
            }
            $io->success(sprintf('Wrote %d bytes to %s.', $bytes, $outputPath));
            return Command::SUCCESS;
        }
        $output->write($payload);
        return Command::SUCCESS;
    }

    private function visitorFilter(SymfonyStyle $io, string $uuid, ?string $site, string $source, int $limit): mixed
    {
        if ($site === null || $source === '') {
            $io->error('--visitor requires --site and (resolvable) --source.');
            return null;
        }
        return $this->auskunft->buildForVisitor($site, $source, $uuid, $limit);
    }

    private function visitorHashFilter(SymfonyStyle $io, string $hash, ?string $site, int $limit): mixed
    {
        if ($site === null) {
            $io->error('--visitor-hash requires --site (consent_log queries are site-scoped).');
            return null;
        }
        return $this->auskunft->buildForVisitorHash($site, $hash, $limit);
    }

    private function sinceFilter(SymfonyStyle $io, string $isoDate, ?string $site, int $limit): mixed
    {
        if ($site === null) {
            $io->error('--since requires --site.');
            return null;
        }
        $since = strtotime($isoDate);
        if ($since === false) {
            $io->error(sprintf('--since="%s" is not a valid date.', $isoDate));
            return null;
        }
        return $this->auskunft->buildForDateRange($site, $since, null, $limit);
    }
}
