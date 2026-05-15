<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;
use WapplerSystems\SimpleCmpTypo3\Service\BridgeSecretProvider;
use WapplerSystems\SimpleCmpTypo3\Service\DetectionListPresenter;
use WapplerSystems\SimpleCmpTypo3\Service\ServiceCurator;
use WapplerSystems\SimpleCmpTypo3\Service\StoragePidResolver;

/**
 * Backend module: review unknown-tracker detections that the SimpleCMP
 * CMS bridge posted into `tx_simplecmptypo3_detection`.
 *
 * The list is driven by a three-state model derived per-row at view
 * time — no `reviewed` flag, no "dismiss" escape hatch:
 *
 * - **Kuratiert** — the service registry already covers this
 *   cookie/origin. Nothing for the admin to do.
 * - **Erkannt** — the bundled `simplecmp/services-library` knows this
 *   pattern, but it hasn't been added to the local registry yet. Admin
 *   either *Übernehmen* (one-click silent insert with confirmation
 *   modal showing vendor/purposes/policy URL) or *Anpassen* (curate
 *   with library pre-fill).
 * - **Unbekannt** — neither registry nor library matches. Admin must
 *   *Kuratieren* (manual entry) — no dismiss-only path on purpose,
 *   so genuinely unknown trackers force a curation decision.
 *
 * Status filter values: `pending` (default; erkannt + unbekannt),
 * `erkannt`, `unbekannt`, `kuratiert`, `all`. The "needs action"
 * default surfaces only rows the admin can productively act on.
 */
final class DetectionReviewController extends ActionController
{
    private const string DETECTION_TABLE = 'tx_simplecmptypo3_detection';
    private const string SERVICE_TABLE = 'tx_simplecmptypo3_service';
    private const array PER_PAGE_OPTIONS = [25, 50, 100, 500];
    private const int DEFAULT_PER_PAGE = 25;
    private const array STATUS_OPTIONS = ['pending', 'erkannt', 'unbekannt', 'kuratiert', 'all'];
    private const string DEFAULT_STATUS = 'pending';
    private const array KIND_OPTIONS = ['cookie', 'script', 'iframe', 'image', 'link', 'request'];
    private const array CONFIDENCE_OPTIONS = ['low', 'medium', 'high'];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly UriBuilder $backendUriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly ServiceRepository $serviceRepository,
        private readonly DetectionListPresenter $listPresenter,
        private readonly ServiceCurator $serviceCurator,
        private readonly StoragePidResolver $storagePidResolver,
        private readonly BridgeSecretProvider $bridgeSecretProvider,
    ) {
    }

    public function listAction(
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): ResponseInterface {
        $filters = $this->normalizeFilters($status, $source, $kind, $confidence);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true)
            ? $perPage
            : self::DEFAULT_PER_PAGE;
        $page = max(1, $page);

        // Load registry + library once for the whole page, then derive
        // state per row in PHP. The state filter can't be expressed in
        // SQL (it's a "does any cookie/origin matcher match" join over
        // JSON-encoded arrays), so we fetch all rows matching the
        // non-state filters, decorate, and PHP-paginate the result.
        $context = $this->listPresenter->loadStateContext();

        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->select('*')
            ->from(self::DETECTION_TABLE)
            ->orderBy('received_at', 'DESC');
        $this->applyNonStateFilters($qb, $filters);
        $allRows = $qb->executeQuery()->fetchAllAssociative();

        $decorated = [];
        foreach ($allRows as $r) {
            $decorated[] = DetectionListPresenter::decorateState($r, $context['services'], $context['library']);
        }
        $stateFiltered = array_values(array_filter(
            $decorated,
            fn (array $r): bool => $this->stateMatches((string) $r['state'], $filters['status']),
        ));
        $filteredCount = count($stateFiltered);
        $totalPages = max(1, (int) ceil($filteredCount / $perPage));
        $page = min($page, $totalPages);
        $paginated = array_slice($stateFiltered, ($page - 1) * $perPage, $perPage);

        $filterArg = $this->filterArg($filters);
        $lowConfidenceMessage = $this->translate('list.action.curate.lowConfidenceConfirm') ?? '';
        $rowsWithActions = [];
        foreach ($paginated as $r) {
            $rowArgs = ['uid' => (int) $r['uid']] + $filterArg;
            $r['uri_show'] = $this->uri('show', $rowArgs);
            $r['uri_createService'] = $this->uri('createService', ['uid' => (int) $r['uid']]);
            $r['uri_approve'] = $this->uri('approve', $rowArgs);
            $r['uri_delete'] = $this->uri('delete', $rowArgs);
            // For curated rows, point straight at the matched service's edit form.
            if ($r['state'] === DetectionListPresenter::STATE_CURATED && is_array($r['match'] ?? null)) {
                $r['uri_editService'] = $this->editServiceUri((string) $r['match']['id']);
            } else {
                $r['uri_editService'] = null;
            }
            // Per-row payload for the Übernehmen confirmation modal.
            $r['approve_modal_data'] = $r['state'] === DetectionListPresenter::STATE_RECOGNIZED
                ? json_encode($r['match'] ?? [], JSON_UNESCAPED_SLASHES)
                : '';
            $r = DetectionListPresenter::decorateConfidence($r, $lowConfidenceMessage);
            $rowsWithActions[] = $r;
        }

        $spike = $this->listPresenter->computeSpikeContext();
        $stateCounts = $this->stateCountsAcrossAll($context);

        $pageArg = $filterArg + ['perPage' => $perPage];
        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
            'detections' => $rowsWithActions,
            'status' => $filters['status'],
            'source' => $filters['source'],
            'kind' => $filters['kind'],
            'confidence' => $filters['confidence'],
            'statusOptions' => self::STATUS_OPTIONS,
            'sourceOptions' => $this->availableSources(),
            'kindOptions' => self::KIND_OPTIONS,
            'confidenceOptions' => self::CONFIDENCE_OPTIONS,
            'totalCount' => $this->totalCount(),
            'pendingCount' => $stateCounts['pending'],
            'curatedCount' => $stateCounts['kuratiert'],
            'spikeAlert' => $spike['spikeAlert'],
            'todayCount' => $spike['todayCount'],
            'sevenDayAverage' => $spike['sevenDayAverage'],
            'secretMissing' => !$this->bridgeSecretProvider->isConfigured(),
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'filteredCount' => $filteredCount,
            'rangeStart' => $filteredCount === 0 ? 0 : ($page - 1) * $perPage + 1,
            'rangeEnd' => min($page * $perPage, $filteredCount),
            'uri_pageFirst' => $this->uri('list', $pageArg + ['page' => 1]),
            'uri_pagePrev' => $this->uri('list', $pageArg + ['page' => max(1, $page - 1)]),
            'uri_pageNext' => $this->uri('list', $pageArg + ['page' => min($totalPages, $page + 1)]),
            'uri_pageLast' => $this->uri('list', $pageArg + ['page' => $totalPages]),
            'uri_bulkDeleteAll' => $this->uri('bulkDeleteAll', $filterArg),
            'uri_bulkDeleteSelected' => $this->uri('bulkDeleteSelected', $filterArg),
            'uri_generateBridgeSecret' => $this->uri('generateBridgeSecret'),
            'uri_resetFilters' => $this->uri('list'),
            'filtersActive' => $filterArg !== [],
        ]);
        return $moduleTemplate->renderResponse('DetectionReview/List');
    }

    /**
     * @return array{status: string, source: string, kind: string, confidence: string}
     */
    private function normalizeFilters(string $status, string $source, string $kind, string $confidence): array
    {
        return [
            'status' => in_array($status, self::STATUS_OPTIONS, true) ? $status : self::DEFAULT_STATUS,
            'source' => in_array($source, $this->availableSources(), true) ? $source : '',
            'kind' => in_array($kind, self::KIND_OPTIONS, true) ? $kind : '',
            'confidence' => in_array($confidence, self::CONFIDENCE_OPTIONS, true) ? $confidence : '',
        ];
    }

    /**
     * SQL-expressible filters only. The status filter is applied in
     * PHP after state derivation.
     *
     * @param array<string, string> $filters
     */
    private function applyNonStateFilters(\TYPO3\CMS\Core\Database\Query\QueryBuilder $qb, array $filters): void
    {
        if ($filters['source'] !== '') {
            $qb->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($filters['source'])));
        }
        if ($filters['kind'] !== '') {
            $qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($filters['kind'])));
        }
        if ($filters['confidence'] === 'low') {
            $qb->andWhere($qb->expr()->eq('occurrences', $qb->createNamedParameter(1, ParameterType::INTEGER)));
        } elseif ($filters['confidence'] === 'medium') {
            $qb->andWhere($qb->expr()->between(
                'occurrences',
                $qb->createNamedParameter(2, ParameterType::INTEGER),
                $qb->createNamedParameter(4, ParameterType::INTEGER),
            ));
        } elseif ($filters['confidence'] === 'high') {
            $qb->andWhere($qb->expr()->gte('occurrences', $qb->createNamedParameter(5, ParameterType::INTEGER)));
        }
    }

    /**
     * "pending" is a pseudo-state that covers anything not yet
     * curated — i.e., erkannt + unbekannt. The default view, since
     * those are the rows the admin can productively act on.
     */
    private function stateMatches(string $rowState, string $filterStatus): bool
    {
        if ($filterStatus === 'all') {
            return true;
        }
        if ($filterStatus === 'pending') {
            return $rowState !== DetectionListPresenter::STATE_CURATED;
        }
        return $rowState === $filterStatus;
    }

    /**
     * Header counters: how many rows need action (pending) and how
     * many are already curated. Full-table scan + decoration once per
     * page render — acceptable at the expected scale.
     *
     * @param array{services: array<array<string, mixed>>, library: array<array<string, mixed>>} $context
     * @return array{pending: int, kuratiert: int}
     */
    private function stateCountsAcrossAll(array $context): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'kind', 'identifier', 'origin')
            ->from(self::DETECTION_TABLE)
            ->executeQuery()
            ->fetchAllAssociative();
        $pending = 0;
        $curated = 0;
        foreach ($rows as $r) {
            $state = DetectionListPresenter::deriveState($r, $context['services'], $context['library'])['state'];
            if ($state === DetectionListPresenter::STATE_CURATED) {
                $curated++;
            } else {
                $pending++;
            }
        }
        return ['pending' => $pending, 'kuratiert' => $curated];
    }

    /**
     * @param array<string, string> $filters
     * @return array<string, string|int>
     */
    private function filterArg(array $filters): array
    {
        $out = [];
        if ($filters['status'] !== self::DEFAULT_STATUS) {
            $out['status'] = $filters['status'];
        }
        foreach (['source', 'kind', 'confidence'] as $k) {
            if ($filters[$k] !== '') {
                $out[$k] = $filters[$k];
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function availableSources(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->selectLiteral('DISTINCT source')
            ->from(self::DETECTION_TABLE)
            ->orderBy('source', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
        return array_values(array_filter(array_map(static fn (array $r) => (string) $r['source'], $rows)));
    }

    /**
     * One-click bootstrap: generate a fresh HMAC secret and persist it
     * to `config/system/settings.php` via TYPO3's `ConfigurationManager`.
     */
    public function generateBridgeSecretAction(): ResponseInterface
    {
        if ($this->bridgeSecretProvider->isConfigured()) {
            return $this->redirect('list');
        }
        $secret = base64_encode(random_bytes(32));
        try {
            GeneralUtility::makeInstance(ConfigurationManager::class)
                ->setLocalConfigurationValueByPath(
                    'EXTENSIONS/simplecmp_typo3/bridgeSecret',
                    $secret,
                );
        } catch (\Throwable) {
            return $this->redirect('list');
        }
        return $this->redirect('list');
    }

    public function showAction(
        int $uid,
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $filters = $this->normalizeFilters($status, $source, $kind, $confidence);
        $row = $this->fetchOne($uid);
        if ($row === null) {
            return $this->redirectToList($filters);
        }

        $payload = null;
        try {
            $payload = json_decode((string) $row['payload'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }

        $filterArg = $this->filterArg($filters);
        $context = $this->listPresenter->loadStateContext();
        $row = DetectionListPresenter::decorateState($row, $context['services'], $context['library']);
        $row['uri_createService'] = $this->uri('createService', ['uid' => (int) $row['uid']]);
        $row['uri_approve'] = $this->uri('approve', ['uid' => (int) $row['uid']] + $filterArg);

        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
            'detection' => $row,
            'payload' => $payload,
            'payloadJson' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'uri_list' => $this->uri('list', $filterArg),
        ]);
        return $moduleTemplate->renderResponse('DetectionReview/Show');
    }

    /**
     * Build an Extbase URI for an action on this controller, with the
     * proper `tx_<ext>_<mod>` argument namespace baked in.
     *
     * @param array<string, scalar> $arguments
     */
    private function uri(string $action, array $arguments = []): string
    {
        return (string) $this->uriBuilder
            ->reset()
            ->setRequest($this->request)
            ->uriFor($action, $arguments);
    }

    private function editServiceUri(string $serviceId): ?string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::SERVICE_TABLE);
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')
            ->from(self::SERVICE_TABLE)
            ->where($qb->expr()->eq('service_id', $qb->createNamedParameter($serviceId)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        if ($uid === false) {
            return null;
        }
        $returnUrl = (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections');
        return (string) $this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::SERVICE_TABLE => [(int) $uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);
    }

    /**
     * Übernehmen: silent-insert the library entry that matches this
     * detection into the registry. Idempotent via `upsert`. The admin
     * already saw the service summary in the confirmation modal
     * before triggering this action, so no further confirmation is
     * needed server-side.
     */
    public function approveAction(
        int $uid,
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $filters = $this->normalizeFilters($status, $source, $kind, $confidence);
        $row = $this->fetchOne($uid);
        if ($row === null) {
            return $this->redirectToList($filters);
        }
        $match = $this->serviceCurator->findLibraryMatch($row);
        if ($match === null) {
            // The button shouldn't be visible for non-Erkannt rows;
            // a stale link is the only way to land here. Bounce.
            return $this->redirectToList($filters);
        }
        $pid = $this->storagePidResolver->resolveForSource((string) ($row['source'] ?? ''));
        $this->serviceRepository->upsert($match, $pid);
        return $this->redirectToList($filters);
    }

    public function deleteAction(
        int $uid,
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->delete(self::DETECTION_TABLE, ['uid' => $uid]);
        return $this->redirectToList($this->normalizeFilters($status, $source, $kind, $confidence));
    }

    public function bulkDeleteAllAction(
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->executeStatement('DELETE FROM ' . self::DETECTION_TABLE);
        return $this->redirectToList($this->normalizeFilters($status, $source, $kind, $confidence));
    }

    /**
     * Delete the rows whose uids the user ticked in the list checkboxes.
     *
     * @param array<int, scalar> $uids
     */
    public function bulkDeleteSelectedAction(
        array $uids = [],
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $filters = $this->normalizeFilters($status, $source, $kind, $confidence);
        $ints = array_values(array_filter(
            array_map('intval', $uids),
            static fn (int $u): bool => $u > 0,
        ));
        if ($ints === []) {
            return $this->redirectToList($filters);
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->delete(self::DETECTION_TABLE)
            ->where($qb->expr()->in(
                'uid',
                $qb->createNamedParameter($ints, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY),
            ))
            ->executeStatement();
        return $this->redirectToList($filters);
    }

    /** @param array<string, string> $filters */
    private function redirectToList(array $filters): ResponseInterface
    {
        return $this->redirect('list', null, null, $this->filterArg($filters));
    }

    /**
     * Open the TYPO3 record-edit form for the service entry that covers
     * this detection — either an existing one (admin sees their
     * previously-saved values) or a fresh new-record form, pre-populated
     * from the bundled library when a pattern matches, otherwise from
     * the bare detection identifier/origin.
     */
    public function createServiceAction(int $uid): ResponseInterface
    {
        $row = $this->fetchOne($uid);
        if ($row === null) {
            return $this->redirect('list');
        }

        $returnUrl = (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections');

        $existingUid = $this->serviceCurator->findExistingServiceUid($row);
        if ($existingUid !== null) {
            $editUrl = (string) $this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [self::SERVICE_TABLE => [$existingUid => 'edit']],
                'returnUrl' => $returnUrl,
            ]);
            return $this->responseFactory->createResponse(302)
                ->withHeader('Location', $editUrl);
        }

        $defaults = $this->serviceCurator->buildDefaults($row);
        $pid = $this->storagePidResolver->resolveForSource((string) ($row['source'] ?? ''));
        $editUrl = (string) $this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::SERVICE_TABLE => [$pid => 'new']],
            'defVals' => [self::SERVICE_TABLE => $defaults],
            'returnUrl' => $returnUrl,
        ]);

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $editUrl);
    }

    /** @return array<string, mixed>|null */
    private function fetchOne(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::DETECTION_TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    private function totalCount(): int
    {
        return (int) $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->executeQuery('SELECT COUNT(*) FROM ' . self::DETECTION_TABLE)
            ->fetchOne();
    }

    private function initModuleTemplate(): ModuleTemplate
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('SimpleCMP');
        // CSP-safe handlers loaded from the extension's JS module map.
        $this->pageRenderer->loadJavaScriptModule(
            '@wapplersystems/simplecmp-typo3/Backend/ConfirmForm.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@wapplersystems/simplecmp-typo3/Backend/BulkSelect.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@wapplersystems/simplecmp-typo3/Backend/Pagination.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@wapplersystems/simplecmp-typo3/Backend/ApproveModal.js'
        );
        return $moduleTemplate;
    }

    private function translate(string $key): ?string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang === null) {
            return null;
        }
        $translated = $lang->sL(
            'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_mod.xlf:' . $key
        );
        return $translated !== '' ? $translated : null;
    }
}
