<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller;

use Psr\Http\Message\ResponseInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\AuditRetentionLogRepository;
use SimpleCMP\T3SimpleCmp\Service\AuskunftCsvExporter;
use SimpleCMP\T3SimpleCmp\Service\AuskunftJsonExporter;
use SimpleCMP\T3SimpleCmp\Service\VisitorAuskunftService;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Auskunfts-Tab — DSGVO Art. 15 Auskunftsrecht (Phase 3).
 *
 * Admin-mediated visitor lookup: the visitor brings their raw UUID
 * from `localStorage[<storageName>-visitor-uuid]`; the admin enters
 * it here, the server recomputes the HMAC + queries the consent log
 * + the snapshots it references.
 *
 * Three actions:
 *   - `indexAction` — site picker + UUID form + recent retention-log
 *     entries (the audit surface for the audit table).
 *   - `lookupAction` — POST handler; renders the result page with
 *     decisions + snapshot bundles + download buttons.
 *   - `downloadAction` — re-runs the lookup, streams JSON or CSV
 *     with `Content-Disposition: attachment`.
 *
 * UUID handling: never persisted to URL or logs. The form is POST,
 * and the download URL re-uses POST as well so the UUID doesn't end
 * up in apache/nginx access logs.
 */
final class AuditAuskunftController extends ActionController
{
    private const string SET_IDENTIFIER = 'simplecmp/t3-simplecmp';

    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly VisitorAuskunftService $auskunftService,
        private readonly AuskunftJsonExporter $jsonExporter,
        private readonly AuskunftCsvExporter $csvExporter,
        private readonly AuditRetentionLogRepository $retentionLog,
    ) {
    }

    public function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setTitle($this->translate('module.auskunft.title'));
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
    }

    public function indexAction(): ResponseInterface
    {
        $sites = $this->collectSites();
        $retentionRows = array_map($this->decorateRetentionRow(...), $this->retentionLog->findRecent(20));

        $this->moduleTemplate->assignMultiple([
            'hasSites' => $sites !== [],
            'sites' => $sites,
            'retentionRows' => $retentionRows,
            'retentionTotal' => $this->retentionLog->countAll(),
        ]);
        $this->assignTabUris($this->moduleTemplate);
        return $this->moduleTemplate->renderResponse('AuditAuskunft/Index');
    }

    public function lookupAction(string $site = '', string $visitorUuid = ''): ResponseInterface
    {
        $site = trim($site);
        $visitorUuid = trim($visitorUuid);

        if ($site === '' || !$this->siteIsKnown($site)) {
            $this->addFlashMessage(
                $this->translate('module.auskunft.error.invalidSite'),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('index');
        }
        if (!$this->isPlausibleUuid($visitorUuid)) {
            $this->addFlashMessage(
                $this->translate('module.auskunft.error.invalidUuid'),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
            return $this->redirect('index');
        }

        $bundle = $this->auskunftService->buildForVisitor(
            site: $site,
            source: $this->sourceForSite($site),
            visitorUuid: $visitorUuid,
        );

        // Pretty-print canonical JSONs once for the template
        $snapshotsPretty = array_map(
            fn(array $row) => [
                'row' => $row,
                'pretty' => $this->prettyPrint((string) ($row['canonical_json'] ?? '')),
                'version_hash_short' => substr((string) ($row['version_hash'] ?? ''), 0, 12),
            ],
            $bundle->snapshots,
        );
        $decisionsPretty = array_map($this->decorateDecisionRow(...), $bundle->decisions);

        $this->moduleTemplate->assignMultiple([
            'site' => $site,
            'visitorUuid' => $visitorUuid,
            'visitorHashShort' => substr($this->auskunftService->buildForVisitor($site, $this->sourceForSite($site), $visitorUuid, 1)->filter['visitorHash'] ?? '', 0, 12),
            'bundle' => $bundle,
            'snapshots' => $snapshotsPretty,
            'decisions' => $decisionsPretty,
            'totalDecisions' => count($bundle->decisions),
            'totalSnapshots' => count($bundle->snapshots),
            'uri_back' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.AuditAuskunft_index',
            ),
            'uri_downloadJson' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.AuditAuskunft_download',
            ),
            'uri_downloadCsv' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.AuditAuskunft_download',
            ),
        ]);
        $this->assignTabUris($this->moduleTemplate);
        return $this->moduleTemplate->renderResponse('AuditAuskunft/Result');
    }

    public function downloadAction(string $site = '', string $visitorUuid = '', string $format = 'json'): ResponseInterface
    {
        $site = trim($site);
        $visitorUuid = trim($visitorUuid);
        if ($site === '' || !$this->siteIsKnown($site) || !$this->isPlausibleUuid($visitorUuid)) {
            // Bad inputs — redirect rather than render an attachment.
            return $this->redirect('index');
        }
        $bundle = $this->auskunftService->buildForVisitor(
            site: $site,
            source: $this->sourceForSite($site),
            visitorUuid: $visitorUuid,
        );

        $beUser = $GLOBALS['BE_USER']->user['uid'] ?? null;
        $exportedBy = is_int($beUser) || (is_numeric($beUser) && (int) $beUser > 0)
            ? 'be:' . (int) $beUser
            : 'be:anonymous';

        $hashShort = substr((string) ($bundle->filter['visitorHash'] ?? ''), 0, 8);
        $datePart = date('Y-m-d');

        if ($format === 'csv') {
            $body = $this->csvExporter->encode($bundle, $exportedBy);
            $filename = sprintf('simplecmp-auskunft-%s-%s-%s.csv', $site, $hashShort, $datePart);
            return (new Response())
                ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('X-SimpleCMP-Export-Schema', '1')
                ->withBody($this->streamFromString($body));
        }

        $body = $this->jsonExporter->encode($bundle, $exportedBy);
        $filename = sprintf('simplecmp-auskunft-%s-%s-%s.json', $site, $hashShort, $datePart);
        return (new Response())
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('X-SimpleCMP-Export-Schema', '1')
            ->withBody($this->streamFromString($body));
    }

    // --- helpers ------------------------------------------------------------

    private function streamFromString(string $content): \Psr\Http\Message\StreamInterface
    {
        $stream = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Http\Stream::class, 'php://temp', 'wb+');
        $stream->write($content);
        $stream->rewind();
        return $stream;
    }

    /**
     * @return list<array{identifier: string, label: string}>
     */
    private function collectSites(): array
    {
        $out = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (!in_array(self::SET_IDENTIFIER, $site->getSets(), true)) {
                continue;
            }
            $title = (string) ($site->getAttribute('websiteTitle') ?: $site->getIdentifier());
            $out[] = [
                'identifier' => $site->getIdentifier(),
                'label' => $title === $site->getIdentifier() ? $site->getIdentifier() : $title . ' (' . $site->getIdentifier() . ')',
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $out;
    }

    private function siteIsKnown(string $site): bool
    {
        foreach ($this->collectSites() as $entry) {
            if ($entry['identifier'] === $site) {
                return true;
            }
        }
        return false;
    }

    /**
     * Derive the bridge source for a site identifier — must match what
     * `RegisterAssets::bridgeSource()` produces so the visitor hash
     * recipe agrees with the one ServiceDbApi wrote with.
     */
    private function sourceForSite(string $siteIdentifier): string
    {
        // Convention used across the codebase: source = 'simplecmp-<site>'.
        // RegisterAssets sanitises non-source-safe characters; for normal
        // identifiers (lowercase, dashes, digits) the literal form holds.
        return 'simplecmp-' . $siteIdentifier;
    }

    private function isPlausibleUuid(string $candidate): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $candidate,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorateDecisionRow(array $row): array
    {
        $row['visitor_id_short'] = substr((string) ($row['visitor_id_sha256'] ?? ''), 0, 12);
        $row['decision_hash_short'] = substr((string) ($row['decision_hash'] ?? ''), 0, 12);
        $row['version_hash_short'] = substr((string) ($row['version_hash'] ?? ''), 0, 12);

        $decisions = [];
        try {
            $parsed = json_decode((string) ($row['decisions_json'] ?? '{}'), true, 8, JSON_THROW_ON_ERROR);
            if (is_array($parsed)) {
                $decisions = $parsed;
            }
        } catch (\JsonException) {
            // ignore
        }
        $row['decisions_parsed'] = $decisions;
        $row['type_class'] = match ((string) ($row['decision_type'] ?? '')) {
            'accept' => 'success',
            'decline' => 'danger',
            'partial' => 'warning',
            default => 'secondary',
        };
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorateRetentionRow(array $row): array
    {
        $row['oldest_kept_iso'] = ((int) ($row['oldest_kept_crdate'] ?? 0)) > 0
            ? date('Y-m-d H:i', (int) $row['oldest_kept_crdate'])
            : '—';
        $row['dry_run_class'] = ((int) ($row['dry_run'] ?? 0)) === 1 ? 'info' : 'warning';
        $row['dry_run_label'] = ((int) ($row['dry_run'] ?? 0)) === 1 ? 'Dry-run' : 'Live';
        return $row;
    }

    private function prettyPrint(string $canonical): string
    {
        if ($canonical === '') {
            return '';
        }
        try {
            $decoded = json_decode($canonical, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $canonical;
        }
        return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function assignTabUris(ModuleTemplate $template): void
    {
        $template->assignMultiple([
            'uri_detectionsTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.DetectionReview_list',
            ),
            'uri_registryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.RegistryList_list',
            ),
            'uri_libraryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.LibraryBrowser_list',
            ),
            'uri_trackerSetupTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.TrackerSetup_list',
            ),
            'uri_auditTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.AuditSnapshot_list',
            ),
            'uri_auskunftTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.AuditAuskunft_index',
            ),
            'uri_settingsTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Settings_index',
            ),
        ]);
    }

    private function translate(string $key): string
    {
        return (string) ($this->getLanguageService()->sL(
            'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_mod.xlf:' . $key,
        ) ?? '');
    }

    private function getLanguageService(): \TYPO3\CMS\Core\Localization\LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
