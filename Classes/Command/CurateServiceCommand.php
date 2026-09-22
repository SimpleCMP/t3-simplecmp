<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Curate a registry service by hand — the TCA form behind *Kuratieren*
 * and *Anpassen*, and the Dienste tab's delete.
 *
 * This is the path for a vendor the bundled library does not know, which
 * is the common case for regional embeds and small SaaS widgets. Without
 * it, Universal Blocking gates such a host and the visitor gets a
 * placeholder that **no consent choice can unlock**, because there is no
 * service to consent to. Curating one is what turns the block into a
 * decision the visitor can make.
 *
 * Fields can be given individually with `--set`, or as a JSON document
 * with `--from-json` in the same shape the library and
 * `simplecmp:export-registry` use — so a service curated on one install
 * can be lifted straight into another.
 */
final class CurateServiceCommand extends Command
{
    /** Scalar fields settable with `--set`. */
    private const array SCALAR_FIELDS = [
        'name', 'vendor', 'vendorCountry', 'vendorAddress', 'vendorOptOutUrl',
        'vendorPartner', 'vendorDescription', 'privacyPolicyUrl', 'description',
        'placeholderTitle', 'placeholderDescription',
    ];

    /** Comma-separated list fields settable with `--set`. */
    private const array LIST_FIELDS = ['purposes', 'cookies', 'origins'];

    public function __construct(
        private readonly ServiceRepository $serviceRepository,
        private readonly StoragePidResolver $storagePidResolver,
        private readonly CliEditorSession $session,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create, update or delete a registry service by hand — for vendors the bundled library does not cover.')
            ->addArgument('serviceId', InputArgument::OPTIONAL, 'Service id (slug). Required unless --from-json carries one.')
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Site whose draft carries the change (the registry is global).')
            ->addOption('be-user', 'u', InputOption::VALUE_REQUIRED, 'BE admin uid or username the change is attributed to.')
            ->addOption(
                'set',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Field as key=value (repeatable). Scalars: ' . implode(', ', self::SCALAR_FIELDS)
                . '. Comma-separated lists: ' . implode(', ', self::LIST_FIELDS) . '.',
            )
            ->addOption('from-json', null, InputOption::VALUE_REQUIRED, 'Read the service from a JSON file (library/export shape). --set wins over it.')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Delete this service from the registry.')
            ->addOption('show', null, InputOption::VALUE_NONE, 'Print the stored service as JSON and exit.')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List the registry and exit.')
            ->addOption('no-publish', null, InputOption::VALUE_NONE, 'Stage into the draft and leave it open.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the resulting record and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            return $this->listRegistry($io);
        }

        $serviceId = (string) ($input->getArgument('serviceId') ?? '');
        $fromJson = $input->getOption('from-json');

        $data = [];
        if ($fromJson !== null) {
            if (!is_readable((string) $fromJson)) {
                $io->error(sprintf('Cannot read "%s".', (string) $fromJson));
                return Command::INVALID;
            }
            try {
                $decoded = json_decode((string) file_get_contents((string) $fromJson), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $io->error(sprintf('Not valid JSON: %s', $e->getMessage()));
                return Command::INVALID;
            }
            if (!is_array($decoded)) {
                $io->error('The JSON document must be an object.');
                return Command::INVALID;
            }
            $data = $decoded;
            $serviceId = $serviceId !== '' ? $serviceId : (string) ($decoded['id'] ?? '');
        }

        if ($serviceId === '') {
            $io->error('A service id is required — as the argument or as "id" in --from-json.');
            return Command::INVALID;
        }
        $data['id'] = $serviceId;

        if ($input->getOption('show')) {
            $stored = $this->serviceRepository->findOne($serviceId);
            if ($stored === null) {
                $io->error(sprintf('No service "%s" in the registry.', $serviceId));
                return Command::FAILURE;
            }
            $output->writeln(json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }

        $remove = (bool) $input->getOption('remove');
        if (!$remove) {
            $existing = $this->serviceRepository->findOne($serviceId);
            if ($existing !== null && $fromJson === null) {
                // Editing: start from what is stored so a single --set
                // does not blank every other field.
                $data = array_replace($existing, ['id' => $serviceId]);
            }
            foreach ((array) $input->getOption('set') as $pair) {
                if (!is_string($pair) || !str_contains($pair, '=')) {
                    $io->error(sprintf('--set expects key=value, got "%s".', (string) $pair));
                    return Command::INVALID;
                }
                [$key, $value] = explode('=', $pair, 2);
                $key = trim($key);
                if (in_array($key, self::LIST_FIELDS, true)) {
                    $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
                    if ($key === 'purposes') {
                        $data['purposes'] = $items;
                    } else {
                        $data['matches'][$key] = $items;
                    }
                    continue;
                }
                if (!in_array($key, self::SCALAR_FIELDS, true)) {
                    $io->error(sprintf(
                        'Unknown field "%s". Scalars: %s. Lists: %s.',
                        $key,
                        implode(', ', self::SCALAR_FIELDS),
                        implode(', ', self::LIST_FIELDS),
                    ));
                    return Command::INVALID;
                }
                $data[$key] = $value;
            }

            if (($data['name'] ?? '') === '') {
                // The banner shows the name; a service without one is a
                // blank row the visitor cannot judge.
                $data['name'] = $serviceId;
            }
            if (($data['purposes'] ?? []) === []) {
                $io->error(
                    'At least one purpose is required — it decides which "accept analytics/marketing" '
                    . 'group switch covers this service. E.g. --set purposes=marketing',
                );
                return Command::INVALID;
            }
            if (($data['matches']['cookies'] ?? []) === [] && ($data['matches']['origins'] ?? []) === []) {
                $io->error(
                    'A service needs at least one cookie or origin matcher, otherwise it matches nothing '
                    . 'and gates nothing. E.g. --set origins=embed.example.com',
                );
                return Command::INVALID;
            }

            $io->writeln(sprintf(' <info>%s</info> — %s', $serviceId, (string) $data['name']));
            $io->writeln(sprintf('   purposes: %s', implode(', ', $data['purposes'])));
            $io->writeln(sprintf('   cookies:  %s', implode(', ', $data['matches']['cookies'] ?? []) ?: '–'));
            $io->writeln(sprintf('   origins:  %s', implode(', ', $data['matches']['origins'] ?? []) ?: '–'));
        }

        if ($input->getOption('dry-run')) {
            $io->note('--dry-run: nothing written.');
            return Command::SUCCESS;
        }

        $site = (string) ($input->getOption('site') ?? '');
        if ($site === '') {
            $io->error('--site=<identifier> is required (the draft is opened per site).');
            return Command::INVALID;
        }
        try {
            $this->siteFinder->getSiteByIdentifier($site);
            $beUserId = $this->session->resolveBeUser((string) ($input->getOption('be-user') ?? ''));
            $this->session->open($site, $beUserId);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($remove) {
            $deleted = $this->serviceRepository->deleteDraft($this->session->globalScope(), $serviceId);
            if ($deleted === 0) {
                $io->warning(sprintf('No service "%s" to remove.', $serviceId));
            } else {
                $io->success(sprintf(
                    'Removed "%s". Anything it gated is now an unconsentable block again until another service covers it.',
                    $serviceId,
                ));
            }
        } else {
            $this->serviceRepository->upsertDraft(
                $this->session->globalScope(),
                $data,
                $beUserId,
                false,
                $this->storagePidResolver->resolveDefault(),
            );
            $io->success(sprintf('Curated "%s".', $serviceId));
        }

        if (!$input->getOption('no-publish')) {
            $this->session->publish($site, $beUserId);
        }
        return Command::SUCCESS;
    }

    private function listRegistry(SymfonyStyle $io): int
    {
        $rows = [];
        foreach ($this->serviceRepository->findAllForRegistryView() as $service) {
            $rows[] = [
                (string) ($service['id'] ?? ''),
                (string) ($service['name'] ?? ''),
                (string) ($service['vendor'] ?? ''),
                implode(', ', $service['purposes'] ?? []),
                // `_libraryAdoptedAt` is the registry view's own marker
                // for "came from the bundled library" vs. hand-curated.
                ((int) ($service['_libraryAdoptedAt'] ?? 0)) > 0 ? 'library' : 'curated',
            ];
        }
        if ($rows === []) {
            $io->warning('The registry is empty — the banner manages no consent.');
            return Command::SUCCESS;
        }
        $io->table(['Service id', 'Name', 'Vendor', 'Purposes', 'Source'], $rows);
        return Command::SUCCESS;
    }
}
