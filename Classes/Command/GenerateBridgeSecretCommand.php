<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a fresh HMAC secret value for the bridge webhook nonces.
 *
 * The default path for setting up the secret is the BE module: just
 * open SimpleCMP -> Detektionen and the secret is auto-generated +
 * persisted to `config/system/settings.php` on first visit, and the
 * "Rotate secret" button on the same page rotates it later. This
 * command is the fallback / non-BE rotation path:
 *
 *  - Multi-install setups where one TYPO3 instance POSTs bridge
 *    webhooks to another and both need the SAME value (paste the
 *    printed secret on both ends).
 *  - Deploys where `config/system/settings.php` is rebuilt from a
 *    template + env interpolation (12-factor); the secret lives in
 *    the env var, not the settings file.
 *  - Restoring access when BE write to `settings.php` is broken
 *    (read-only filesystem, permissions, etc.).
 *
 * Prints the value plus snippet for `additional.php`. Does NOT write
 * anywhere on disk — installing the printed value is the operator's
 * job and deliberately so: where it lives (env var, secrets manager,
 * file) depends on the deployment.
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
        $this->setDescription(
            'Print a fresh HMAC secret value for the SimpleCMP bridge webhook. '
            . 'For first-install bootstrap and routine rotation, prefer the BE '
            . 'module — this command is the non-BE / multi-install fallback.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $secret = base64_encode(random_bytes(self::BYTES));

        $output->writeln('');
        $output->writeln('Generated SimpleCMP bridge secret:');
        $output->writeln('');
        $output->writeln('  <info>' . $secret . '</info>');
        $output->writeln('');
        $output->writeln('Default path: just open the SimpleCMP BE module — the secret');
        $output->writeln('is auto-generated on first visit, and the "Rotate secret" button');
        $output->writeln('on the Detektionen page rotates it later.');
        $output->writeln('');
        $output->writeln('Use this command when the BE path doesn\'t fit, for example:');
        $output->writeln('  - Multi-install setups (same value on sender + receiver)');
        $output->writeln('  - 12-factor deploys (secret lives in env, not settings.php)');
        $output->writeln('  - Restoring access when BE write to settings.php is broken');
        $output->writeln('');
        $output->writeln('Add the printed value to your TYPO3 configuration. Recommended via env:');
        $output->writeln('');
        $output->writeln('  # In your environment / .env:');
        $output->writeln('  SIMPLECMP_BRIDGE_SECRET=' . $secret);
        $output->writeln('');
        $output->writeln('  # In config/system/additional.php:');
        $output->writeln('  $GLOBALS[\'TYPO3_CONF_VARS\'][\'EXTENSIONS\'][\'simplecmp\'][\'bridgeSecret\']');
        $output->writeln('      = getenv(\'SIMPLECMP_BRIDGE_SECRET\') ?: null;');
        $output->writeln('');
        $output->writeln('Or inline (less secure — not recommended for production):');
        $output->writeln('');
        $output->writeln('  $GLOBALS[\'TYPO3_CONF_VARS\'][\'EXTENSIONS\'][\'simplecmp\'][\'bridgeSecret\']');
        $output->writeln('      = \'' . $secret . '\';');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
