<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates a fresh HMAC secret for the bridge webhook nonces.
 *
 * Prints the value plus snippet for `additional.php`. Does NOT write
 * anywhere on disk — installing the secret is the operator's job and
 * deliberately so: where it lives (env var, secrets manager, file)
 * depends on the deployment.
 *
 * Rotation: re-run any time. Previously issued nonces remain valid
 * until they expire (default 1 hour) — i.e., there is no hard
 * cut-over. If hard rotation is needed, restart PHP/clear OPcache
 * after swapping the value.
 */
final class GenerateBridgeSecretCommand extends Command
{
    private const int BYTES = 32;

    protected function configure(): void
    {
        $this->setDescription('Generate a fresh HMAC secret for the SimpleCMP bridge webhook.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $secret = base64_encode(random_bytes(self::BYTES));

        $output->writeln('');
        $output->writeln('Generated SimpleCMP bridge secret:');
        $output->writeln('');
        $output->writeln('  <info>' . $secret . '</info>');
        $output->writeln('');
        $output->writeln('Add it to your TYPO3 configuration. Recommended via env:');
        $output->writeln('');
        $output->writeln('  # In your environment / .env:');
        $output->writeln('  SIMPLECMP_BRIDGE_SECRET=' . $secret);
        $output->writeln('');
        $output->writeln('  # In config/system/additional.php:');
        $output->writeln('  $GLOBALS[\'TYPO3_CONF_VARS\'][\'EXTENSIONS\'][\'t3_simplecmp\'][\'bridgeSecret\']');
        $output->writeln('      = getenv(\'SIMPLECMP_BRIDGE_SECRET\') ?: null;');
        $output->writeln('');
        $output->writeln('Or inline (less secure — not recommended for production):');
        $output->writeln('');
        $output->writeln('  $GLOBALS[\'TYPO3_CONF_VARS\'][\'EXTENSIONS\'][\'t3_simplecmp\'][\'bridgeSecret\']');
        $output->writeln('      = \'' . $secret . '\';');
        $output->writeln('');
        $output->writeln('If you run multiple TYPO3 installs and one POSTs bridge webhooks to');
        $output->writeln('another, configure the SAME secret on both ends.');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
