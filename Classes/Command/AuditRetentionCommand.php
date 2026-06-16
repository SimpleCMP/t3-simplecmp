<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Service\AuditRetentionService;
use SimpleCMP\T3SimpleCmp\Service\RetentionRequest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Phase-3 retention CLI — DSGVO Art. 5 (1) (e) Speicherbegrenzung.
 *
 * The command does NO actual deletion itself; it parses + validates
 * operator flags, builds a {@see RetentionRequest}, and hands off to
 * {@see AuditRetentionService::apply()}, which writes the self-audit
 * log row + executes the DELETE in that order.
 *
 * Flag philosophy: every retention CALL must explicitly carry an
 * operator's intent. Defaults are conservative; surprising or risky
 * combinations require explicit opt-in flags. The combination of:
 *   - --reason (≥30 chars)
 *   - --i-know-what-i-do (boolean tripwire)
 *   - --keep-days minimum 90 unless --allow-aggressive-retention
 * exists to make the legal-team review trail explicit. Each option
 * surfaces a hint about its own minimum + the override switch.
 */
final class AuditRetentionCommand extends Command
{
    private const int MIN_REASON_LENGTH = 30;
    private const int CONSERVATIVE_MIN_DAYS = 90;
    private const string TARGET_ALL = 'all';

    public function __construct(
        private readonly AuditRetentionService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $allowedTargets = array_merge(AuditRetentionService::availableTargets(), [self::TARGET_ALL]);
        $this
            ->setDescription(
                'Apply DSGVO retention to one or more SimpleCMP audit tables. '
                . 'Append-only log writes happen FIRST so a crash leaves a trail.'
            )
            ->addOption(
                'target',
                't',
                InputOption::VALUE_REQUIRED,
                'Which table(s) to apply retention to. One of: ' . implode(', ', $allowedTargets),
            )
            ->addOption(
                'keep-days',
                'k',
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Keep rows newer than this many days. Lower than %d requires --allow-aggressive-retention.',
                    self::CONSERVATIVE_MIN_DAYS,
                ),
            )
            ->addOption(
                'reason',
                'r',
                InputOption::VALUE_REQUIRED,
                sprintf('Operator reason — required, minimum %d characters. Goes into the audit log verbatim.', self::MIN_REASON_LENGTH),
            )
            ->addOption(
                'site',
                's',
                InputOption::VALUE_REQUIRED,
                'Restrict to a single site identifier. Default: all sites.',
            )
            ->addOption(
                'i-know-what-i-do',
                null,
                InputOption::VALUE_NONE,
                'Required tripwire flag — confirms the operator understands deletion is irreversible.',
            )
            ->addOption(
                'allow-aggressive-retention',
                null,
                InputOption::VALUE_NONE,
                sprintf('Allow --keep-days below the conservative minimum (%d).', self::CONSERVATIVE_MIN_DAYS),
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Count what WOULD be deleted, write a dry_run log entry, but do not DELETE.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = $input->getOption('target');
        if (!is_string($target) || $target === '') {
            $io->error('--target is required (config-snapshot | consent-log | all).');
            return Command::INVALID;
        }
        $allowedTargets = array_merge(AuditRetentionService::availableTargets(), [self::TARGET_ALL]);
        if (!in_array($target, $allowedTargets, true)) {
            $io->error(sprintf(
                'Unknown --target "%s". Allowed: %s',
                $target,
                implode(', ', $allowedTargets),
            ));
            return Command::INVALID;
        }

        $keepDaysRaw = $input->getOption('keep-days');
        if (!is_string($keepDaysRaw) || $keepDaysRaw === '' || !ctype_digit($keepDaysRaw)) {
            $io->error('--keep-days is required and must be a non-negative integer.');
            return Command::INVALID;
        }
        $keepDays = (int) $keepDaysRaw;

        $allowAggressive = (bool) $input->getOption('allow-aggressive-retention');
        if ($keepDays < self::CONSERVATIVE_MIN_DAYS && !$allowAggressive) {
            $io->error(sprintf(
                '--keep-days=%d is below the conservative minimum of %d. Add --allow-aggressive-retention to acknowledge.',
                $keepDays,
                self::CONSERVATIVE_MIN_DAYS,
            ));
            return Command::INVALID;
        }

        $reason = $input->getOption('reason');
        if (!is_string($reason)) {
            $reason = '';
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            $io->error(sprintf(
                '--reason="…" is required and must be at least %d characters (got %d).',
                self::MIN_REASON_LENGTH,
                mb_strlen($reason),
            ));
            return Command::INVALID;
        }

        if (!(bool) $input->getOption('i-know-what-i-do')) {
            $io->error('--i-know-what-i-do is required. Retention is irreversible — confirm intent.');
            return Command::INVALID;
        }

        $site = $input->getOption('site');
        $site = is_string($site) && $site !== '' ? $site : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $targetsToApply = $target === self::TARGET_ALL
            ? AuditRetentionService::availableTargets()
            : [$target];

        $now = time();
        $io->title(sprintf(
            'SimpleCMP retention — keep-days=%d, dry-run=%s, site=%s',
            $keepDays,
            $dryRun ? 'yes' : 'no',
            $site ?? '(all)',
        ));

        $any = false;
        foreach ($targetsToApply as $t) {
            $request = new RetentionRequest(
                target: $t,
                keepDays: $keepDays,
                reason: $reason,
                invokedBy: 'cli',
                site: $site,
                dryRun: $dryRun,
                now: $now,
            );
            $result = $this->service->apply($request);
            $io->writeln(' ' . $result->summary());
            $any = true;
        }

        if (!$any) {
            $io->warning('No targets applied.');
            return Command::SUCCESS;
        }

        $io->success($dryRun ? 'Dry-run complete — no rows were deleted.' : 'Retention complete.');
        return Command::SUCCESS;
    }
}
