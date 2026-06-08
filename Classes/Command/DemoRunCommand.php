<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Command;

use SimpleCMP\T3SimpleCmp\Service\BridgeNonceService;
use SimpleCMP\T3SimpleCmp\Service\BridgeSecretProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reset state + seed the detection table for the live demo. Intended
 * to be re-runnable at any point during a presentation — calling it
 * again returns the install to the same starting line.
 *
 * Two paths drive detections into the BE:
 *
 *  1. **FE bundle auto-detection** — visit the SimpleCMP demo page in
 *     a browser; the `simplecmp.global.js` NodeObserver fires for any
 *     foreign-origin asset that isn't tagged with a known `data-name`.
 *     That path is exercised by the editor visiting the FE.
 *
 *  2. **CMS-bridge POST** (this command) — emits a small batch of
 *     detections through the actual HMAC-signed webhook so the demo
 *     also exercises the server-side ingest path (the one customers
 *     wire up from their CRM / sitemap-crawler / ImportExport
 *     workflows). The nonce is minted via the production
 *     `BridgeNonceService` — same code path the FE uses.
 *
 * After this runs the editor opens SimpleCMP → Detektionen, sees the
 * seeded rows, picks one ("Übernehmen" / Adopt), and the row turns
 * into a `tx_t3simplecmp_service` registry entry visible in the FE
 * consent banner.
 *
 * Idempotent. Safe to call before each live demo run.
 */
final class DemoRunCommand extends Command
{
    public function __construct(
        private readonly BridgeNonceService $nonceService,
        private readonly BridgeSecretProvider $secretProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Reset SimpleCMP detection + service state for a demo run, then seed '
                . 'a small detections batch via the production CMS-bridge webhook so '
                . 'the BE Detektionen module shows fresh rows ready to be adopted.'
            )
            ->addOption(
                'base-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Site base URL the webhook POST is sent to. Auto-detects from the '
                . 'first configured site when omitted.',
            )
            ->addOption(
                'no-seed',
                null,
                InputOption::VALUE_NONE,
                'Reset only — skip the webhook seeding step. Useful when the demo '
                . 'is going to drive detections from the FE page on its own.',
            )
            ->addOption(
                'keep-services',
                null,
                InputOption::VALUE_NONE,
                "Don't truncate tx_t3simplecmp_service — preserves already-adopted "
                . 'rows. By default the table is emptied so the demo starts with '
                . 'an empty registry.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<info>SimpleCMP demo bootstrap</info>');
        $output->writeln('');

        // 1) Reset detection table (and optionally service table). The
        //    schema has soft-delete on tx_t3simplecmp_service but not
        //    on tx_t3simplecmp_detection (rows there are append-only
        //    audit log), so detection uses TRUNCATE for a clean slate.
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);

        $detectionConn = $pool->getConnectionForTable('tx_t3simplecmp_detection');
        $detectionConn->truncate('tx_t3simplecmp_detection');
        $output->writeln('  <comment>✓</comment> Truncated tx_t3simplecmp_detection');

        if (!$input->getOption('keep-services')) {
            $serviceConn = $pool->getConnectionForTable('tx_t3simplecmp_service');
            $serviceConn->truncate('tx_t3simplecmp_service');
            $output->writeln('  <comment>✓</comment> Truncated tx_t3simplecmp_service');
        } else {
            $output->writeln('  <comment>·</comment> Kept tx_t3simplecmp_service (--keep-services)');
        }

        // 2) Optional webhook seed. Skip when caller wants only the
        //    reset (e.g. they're about to drive detections from the
        //    actual FE page).
        if ($input->getOption('no-seed')) {
            $output->writeln('  <comment>·</comment> Skipped webhook seeding (--no-seed)');
            $output->writeln('');
            $this->printNextSteps($output);
            return Command::SUCCESS;
        }

        $baseUrl = $this->resolveBaseUrl((string) ($input->getOption('base-url') ?? ''));
        if ($baseUrl === null) {
            $output->writeln('<error>Could not resolve a site base URL. Pass --base-url=https://...</error>');
            return Command::FAILURE;
        }

        if (!$this->secretProvider->isConfigured()) {
            $output->writeln(
                '<error>Bridge secret not configured. Open the SimpleCMP BE module once '
                . 'so it can auto-generate one, or run `simplecmp:generate-bridge-secret`.</error>'
            );
            return Command::FAILURE;
        }

        $detections = $this->buildDemoDetections($baseUrl);
        $payload = $this->buildPayload($baseUrl, $detections);
        $nonce = $this->nonceService->issue('demo-cli');

        $url = rtrim($baseUrl, '/') . '/api/simplecmp/webhook';
        $output->writeln('  <comment>·</comment> POST ' . $url);

        $result = $this->postWebhook($url, $payload, $nonce);
        if ($result['status'] !== 200) {
            $output->writeln(sprintf(
                '<error>Webhook POST failed (%d): %s</error>',
                $result['status'],
                $result['body'],
            ));
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '  <comment>✓</comment> Seeded %d detection(s) via the bridge webhook',
            count($detections),
        ));
        $output->writeln('');
        $this->printNextSteps($output);
        return Command::SUCCESS;
    }

    /**
     * Hand-picked detection set chosen so a presenter can demonstrate
     * the three review states the BE module supports:
     *   - `unknown` → editor adopts a new service
     *   - `known`+matchedService → BE shows "Erkannt", no action needed
     * Origins use realistic third-party hostnames so the rendered
     * Detektionen list reads like a real audit.
     *
     * @return list<array{kind: string, identifier: string, origin: string, firstSeen: int, lastSeen: int, count: int, status: string, matchedService?: string, firstSeenOn: string}>
     */
    private function buildDemoDetections(string $baseUrl): array
    {
        $nowMs = (int) floor(microtime(true) * 1000);
        $page = rtrim($baseUrl, '/') . '/de/extensions/simplecmp';
        return [
            [
                'kind' => 'script',
                'identifier' => 'https://static.hotjar.com/c/hotjar-12345.js',
                'origin' => 'static.hotjar.com',
                'firstSeen' => $nowMs - 3000,
                'lastSeen' => $nowMs,
                'count' => 1,
                'status' => 'unknown',
                'firstSeenOn' => $page,
            ],
            [
                'kind' => 'iframe',
                'identifier' => 'https://app.usercentrics.eu/embed',
                'origin' => 'app.usercentrics.eu',
                'firstSeen' => $nowMs - 2500,
                'lastSeen' => $nowMs,
                'count' => 1,
                'status' => 'unknown',
                'firstSeenOn' => $page,
            ],
            [
                'kind' => 'cookie',
                'identifier' => '_fbp',
                'origin' => 't3bootstrap14.ddev.site',
                'firstSeen' => $nowMs - 2000,
                'lastSeen' => $nowMs,
                'count' => 1,
                'status' => 'unknown',
                'firstSeenOn' => $page,
            ],
            [
                'kind' => 'script',
                'identifier' => 'https://matomo.wappler.systems/matomo.js',
                'origin' => 'matomo.wappler.systems',
                'firstSeen' => $nowMs - 1500,
                'lastSeen' => $nowMs,
                'count' => 1,
                'status' => 'known',
                'matchedService' => 'matomo',
                'firstSeenOn' => $page,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $detections
     * @return array<string, mixed>
     */
    private function buildPayload(string $baseUrl, array $detections): array
    {
        $page = rtrim($baseUrl, '/') . '/de/extensions/simplecmp';
        return [
            'schemaVersion' => 2,
            'source' => 'demo-cli',
            'sentAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'library' => [
                'name' => 'simplecmp',
                'version' => 'demo',
            ],
            'page' => [
                'url' => $page,
                'referrer' => '',
                'userAgent' => 'SimpleCMP-Demo-CLI/1.0',
            ],
            'detections' => $detections,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    private function postWebhook(string $url, array $payload, string $nonce): array
    {
        $body = (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $nonce,
                // Sec-Fetch-Site: same-origin so the request-guard's
                // origin check accepts a server-side POST. The bridge
                // accepts same-origin / same-site / none in v1 — see
                // WebhookRequestGuard.
                'Sec-Fetch-Site: same-origin',
                'Origin: ' . parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST),
            ],
            // DDEV runs with locally signed certs; the demo command is
            // a localhost helper, not a production client.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // No curl_close() — it's a no-op since PHP 8.0 and the handle
        // is freed when $ch goes out of scope. Calling it on 8.5
        // emits a deprecation notice that TYPO3 turns into an
        // exception under console run.
        return ['status' => $status, 'body' => is_string($resp) ? $resp : ''];
    }

    /**
     * Pull a base URL from the configured Sites. Prefers a site whose
     * resolved Site-Set dependencies include `simplecmp/t3-simplecmp`
     * — those are the only sites where the FE bundle and webhook
     * actually run. Falls back to the first site with a non-empty base
     * URL when nothing matches (e.g. fresh install before any site
     * has been wired up). Editor can pass `--base-url=` to override.
     */
    private function resolveBaseUrl(string $explicit): ?string
    {
        if ($explicit !== '') {
            return $explicit;
        }
        $siteFinder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Site\SiteFinder::class);
        $fallback = null;
        foreach ($siteFinder->getAllSites() as $site) {
            $base = (string) $site->getBase();
            if ($base === '') {
                continue;
            }
            if (in_array('simplecmp/t3-simplecmp', $site->getSets(), true)) {
                return $base;
            }
            $fallback ??= $base;
        }
        return $fallback;
    }

    private function printNextSteps(OutputInterface $output): void
    {
        $output->writeln('<info>Next steps for the live demo:</info>');
        $output->writeln('  1) Open SimpleCMP → <comment>Detektionen</comment> in the TYPO3 BE');
        $output->writeln('     · 3 "Neu" rows ready to be reviewed');
        $output->writeln('     · 1 "Erkannt" row (Matomo, already library-classified)');
        $output->writeln('  2) Pick a row → "Übernehmen" to materialise it into the service registry');
        $output->writeln('  3) Reload the FE demo page — the new service appears in the consent banner');
        $output->writeln('');
    }
}
