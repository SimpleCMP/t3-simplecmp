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
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
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
 * Five actions:
 * - `list`              — paginated, optionally filtered to unreviewed only
 * - `show`              — single-row detail with raw payload
 * - `markReviewed`      — flip the `reviewed` flag to 1
 * - `unmarkReviewed`    — flip back to 0
 * - `bulkDelete`        — wipe all detections currently marked reviewed
 * - `createService`     — redirect to TYPO3's record-edit form for a new
 *                         service, pre-filling cookie / origin matchers
 *                         from the detection (closes the loop)
 *
 * Extbase ActionController for the free Fluid + i18n + flash-message
 * infrastructure; the underlying queries are raw DBAL since the data
 * model is denormalized-JSON and Extbase ORM would buy us nothing.
 */
final class DetectionReviewController extends ActionController
{
    private const string DETECTION_TABLE = 'tx_simplecmptypo3_detection';
    private const string SERVICE_TABLE = 'tx_simplecmptypo3_service';
    private const array PER_PAGE_OPTIONS = [25, 50, 100, 500];
    private const int DEFAULT_PER_PAGE = 25;
    private const array STATUS_OPTIONS = ['unreviewed', 'reviewed', 'all'];
    private const string DEFAULT_STATUS = 'unreviewed';
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

        $filteredCount = $this->filteredCount($filters);
        $totalPages = max(1, (int) ceil($filteredCount / $perPage));
        $page = min($page, $totalPages);

        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->select('*')
            ->from(self::DETECTION_TABLE)
            ->orderBy('received_at', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $this->applyFilters($qb, $filters);
        $rows = $qb->executeQuery()->fetchAllAssociative();

        // Build per-row action URLs in PHP — Fluid's `f:uri.action` doesn't
        // produce properly-namespaced URLs in BE module context (it omits
        // the `tx_<ext>_<mod>[action]` argument), so we generate URIs via
        // the Extbase UriBuilder here where it has the request context.
        // Bake the current filter state into every per-row action URL plus
        // the bulk-delete form. The action handlers read it back from the
        // request and redirect to `list` with the same values, so the user
        // stays on whichever filtered view they were on.
        $filterArg = $this->filterArg($filters);
        $lowConfidenceMessage = $this->translate('list.action.createService.lowConfidenceConfirm') ?? '';
        $rowsWithActions = [];
        foreach ($rows as $r) {
            $rowArgs = ['uid' => (int) $r['uid']] + $filterArg;
            $r['uri_show'] = $this->uri('show', $rowArgs);
            $r['uri_createService'] = $this->uri('createService', ['uid' => (int) $r['uid']]);
            $r['uri_markReviewed'] = $this->uri('markReviewed', $rowArgs);
            $r['uri_unmarkReviewed'] = $this->uri('unmarkReviewed', $rowArgs);
            $r['uri_delete'] = $this->uri('delete', $rowArgs);
            $r = DetectionListPresenter::decorateConfidence($r, $lowConfidenceMessage);
            $rowsWithActions[] = $r;
        }

        $spike = $this->listPresenter->computeSpikeContext();

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
            'unreviewedCount' => $this->unreviewedCount(),
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
            'uri_bulkDeleteReviewed' => $this->uri('bulkDeleteReviewed', $filterArg),
            'uri_bulkDeleteAll' => $this->uri('bulkDeleteAll', $filterArg),
            'uri_bulkDeleteSelected' => $this->uri('bulkDeleteSelected', $filterArg),
            'uri_generateBridgeSecret' => $this->uri('generateBridgeSecret'),
            'uri_resetFilters' => $this->uri('list'),
            'filtersActive' => $filterArg !== [],
            'filterDropHints' => $this->buildFilterDropHints($filters),
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

    /** @param array<string, string> $filters */
    private function applyFilters(\TYPO3\CMS\Core\Database\Query\QueryBuilder $qb, array $filters): void
    {
        if ($filters['status'] === 'unreviewed') {
            $qb->andWhere($qb->expr()->eq('reviewed', $qb->createNamedParameter(0)));
        } elseif ($filters['status'] === 'reviewed') {
            $qb->andWhere($qb->expr()->eq('reviewed', $qb->createNamedParameter(1)));
        }
        if ($filters['source'] !== '') {
            $qb->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($filters['source'])));
        }
        if ($filters['kind'] !== '') {
            $qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($filters['kind'])));
        }
        if ($filters['confidence'] === 'low') {
            $qb->andWhere($qb->expr()->eq('occurrences', $qb->createNamedParameter(1, \Doctrine\DBAL\ParameterType::INTEGER)));
        } elseif ($filters['confidence'] === 'medium') {
            $qb->andWhere($qb->expr()->between(
                'occurrences',
                $qb->createNamedParameter(2, \Doctrine\DBAL\ParameterType::INTEGER),
                $qb->createNamedParameter(4, \Doctrine\DBAL\ParameterType::INTEGER),
            ));
        } elseif ($filters['confidence'] === 'high') {
            $qb->andWhere($qb->expr()->gte('occurrences', $qb->createNamedParameter(5, \Doctrine\DBAL\ParameterType::INTEGER)));
        }
    }

    /**
     * @param array<string, string> $filters
     * @return array<string, string|int>
     */
    private function filterArg(array $filters): array
    {
        // Only include non-default values in the URL so the URLs stay clean
        // for the common case (default unreviewed-only view).
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
     * For each filter that is currently restricting results, compute
     * "how many rows would match if we dropped just this one filter."
     * Helps the user diagnose which filter is over-restrictive when
     * their combo yields zero results.
     *
     * A filter "is restricting" when it has a value that excludes
     * rows — i.e., `status` ∈ {unreviewed, reviewed} (not `all`), or
     * a non-empty source / kind / confidence. Status='all' is
     * semantically a no-op so a "drop status" hint would be useless;
     * skipped.
     *
     * @param array<string, string> $filters
     * @return list<array{name: string, count: int, uri: string}>
     */
    private function buildFilterDropHints(array $filters): array
    {
        $hints = [];
        foreach (['status', 'source', 'kind', 'confidence'] as $name) {
            $isRestricting = $name === 'status'
                ? $filters[$name] !== 'all'
                : $filters[$name] !== '';
            if (!$isRestricting) {
                continue;
            }
            $reduced = $filters;
            $reduced[$name] = $name === 'status' ? 'all' : '';
            $hints[] = [
                'name' => $name,
                'count' => $this->filteredCount($reduced),
                'uri' => $this->uri('list', $this->filterArg($reduced)),
            ];
        }
        return $hints;
    }

    /** @param array<string, string> $filters */
    private function filteredCount(array $filters): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->count('*')->from(self::DETECTION_TABLE);
        $this->applyFilters($qb, $filters);
        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * One-click bootstrap: generate a fresh HMAC secret and persist it
     * to `config/system/settings.php` via TYPO3's `ConfigurationManager`.
     *
     * The button on the list view only surfaces when the secret is
     * missing; rotation goes through the CLI command (documented in
     * the README) so the BE flow stays single-purpose.
     */
    public function generateBridgeSecretAction(): ResponseInterface
    {
        if ($this->bridgeSecretProvider->isConfigured()) {
            // Don't overwrite an existing secret silently — rotation is
            // a deliberate operation via CLI.
            $this->addFlash('flash.bridgeSecretAlreadyConfigured', ContextualFeedbackSeverity::INFO);
            return $this->redirect('list');
        }
        $secret = base64_encode(random_bytes(32));
        try {
            GeneralUtility::makeInstance(ConfigurationManager::class)
                ->setLocalConfigurationValueByPath(
                    'EXTENSIONS/simplecmp_typo3/bridgeSecret',
                    $secret,
                );
        } catch (\Throwable $e) {
            $this->addFlash(
                'flash.bridgeSecretWriteFailed',
                ContextualFeedbackSeverity::ERROR,
                ['reason' => $e->getMessage()],
            );
            return $this->redirect('list');
        }
        $this->addFlash('flash.bridgeSecretGenerated');
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
        $row['uri_createService'] = $this->uri('createService', ['uid' => (int) $row['uid']]);
        $row['uri_markReviewed'] = $this->uri('markReviewed', ['uid' => (int) $row['uid']] + $filterArg);
        $row['uri_unmarkReviewed'] = $this->uri('unmarkReviewed', ['uid' => (int) $row['uid']] + $filterArg);

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

    public function markReviewedAction(
        int $uid,
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $this->setReviewed($uid, true);
        $this->addFlash('flash.markedReviewed');
        return $this->redirectToList($this->normalizeFilters($status, $source, $kind, $confidence));
    }

    public function unmarkReviewedAction(
        int $uid,
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $this->setReviewed($uid, false);
        $this->addFlash('flash.unmarkedReviewed');
        return $this->redirectToList($this->normalizeFilters($status, $source, $kind, $confidence));
    }

    public function deleteAction(
        int $uid,
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $count = $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->delete(self::DETECTION_TABLE, ['uid' => $uid]);
        if ($count > 0) {
            $this->addFlash('flash.detectionDeleted');
        } else {
            $this->addFlash('flash.detectionNotFound', ContextualFeedbackSeverity::WARNING);
        }
        return $this->redirectToList($this->normalizeFilters($status, $source, $kind, $confidence));
    }

    public function bulkDeleteReviewedAction(
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $count = $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->delete(self::DETECTION_TABLE, ['reviewed' => 1]);
        $this->addFlash('flash.bulkDeletedReviewed', ContextualFeedbackSeverity::OK, ['count' => $count]);
        return $this->redirectToList($this->normalizeFilters($status, $source, $kind, $confidence));
    }

    public function bulkDeleteAllAction(
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $conn = $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE);
        // No WHERE — wipes everything. Use TRUNCATE semantics via raw SQL
        // because Connection::delete requires a non-empty criteria array.
        $count = (int) $conn->executeQuery('SELECT COUNT(*) FROM ' . self::DETECTION_TABLE)->fetchOne();
        $conn->executeStatement('DELETE FROM ' . self::DETECTION_TABLE);
        $this->addFlash('flash.bulkDeletedAll', ContextualFeedbackSeverity::OK, ['count' => $count]);
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
            $this->addFlash('flash.bulkDeleteSelectedEmpty', ContextualFeedbackSeverity::INFO);
            return $this->redirectToList($filters);
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $count = $qb->delete(self::DETECTION_TABLE)
            ->where($qb->expr()->in(
                'uid',
                $qb->createNamedParameter($ints, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY),
            ))
            ->executeStatement();
        $this->addFlash('flash.bulkDeletedSelected', ContextualFeedbackSeverity::OK, ['count' => $count]);
        return $this->redirectToList($filters);
    }

    /** @param array<string, string> $filters */
    private function redirectToList(array $filters): ResponseInterface
    {
        return $this->redirect('list', null, null, $this->filterArg($filters));
    }

    /**
     * Open the TYPO3 record-edit form for the service entry that covers
     * this detection — either an existing one (so the admin sees their
     * previously-saved custom values) or a fresh new-record form
     * pre-populated with the detection's cookie / origin / identifier.
     */
    public function createServiceAction(int $uid): ResponseInterface
    {
        $row = $this->fetchOne($uid);
        if ($row === null) {
            return $this->redirect('list');
        }

        $returnUrl = (string) $this->backendUriBuilder->buildUriFromRoute('simplecmp_detections');

        // If a service already covers this detection, open it for editing
        // instead of starting a fresh new-record form. Otherwise the admin
        // would see only the controller-derived pre-fill values and would
        // lose the visual cue that they had already curated this entry.
        $existingUid = $this->serviceCurator->findExistingServiceUid($row);
        if ($existingUid !== null) {
            $editUrl = (string) $this->backendUriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [self::SERVICE_TABLE => [$existingUid => 'edit']],
                'returnUrl' => $returnUrl,
            ]);
            $this->addFlash('flash.createServiceExisting');
            return $this->responseFactory->createResponse(302)
                ->withHeader('Location', $editUrl);
        }

        $defaults = ServiceCurator::buildServiceDefaults($row);
        $pid = $this->storagePidResolver->resolveForSource((string) ($row['source'] ?? ''));
        $editUrl = (string) $this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::SERVICE_TABLE => [$pid => 'new']],
            'defVals' => [self::SERVICE_TABLE => $defaults],
            'returnUrl' => $returnUrl,
        ]);

        $this->addFlash('flash.createServiceRedirect');
        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $editUrl);
    }

    /** @return array<string, mixed>|null */
    private function fetchOne(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        // BE-context QueryBuilder applies default restrictions (deleted, hidden,
        // workspace). Our records are pid=0 with no enable-columns, so the
        // restrictions return nothing — strip them.
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::DETECTION_TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    private function setReviewed(int $uid, bool $reviewed): void
    {
        $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->update(
                self::DETECTION_TABLE,
                ['reviewed' => $reviewed ? 1 : 0, 'tstamp' => time()],
                ['uid' => $uid],
            );
    }

    private function totalCount(): int
    {
        return (int) $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->executeQuery('SELECT COUNT(*) FROM ' . self::DETECTION_TABLE)
            ->fetchOne();
    }

    private function unreviewedCount(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        return (int) $qb->count('*')
            ->from(self::DETECTION_TABLE)
            ->where($qb->expr()->eq('reviewed', $qb->createNamedParameter(0)))
            ->executeQuery()
            ->fetchOne();
    }

    private function initModuleTemplate(): ModuleTemplate
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('SimpleCMP');
        // CSP-safe replacement for the inline `onsubmit="confirm(...)"` on the
        // bulk-delete form: the listener honours `data-confirm-message` and
        // calls preventDefault if the user cancels.
        $this->pageRenderer->loadJavaScriptModule(
            '@wapplersystems/simplecmp-typo3/Backend/ConfirmForm.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@wapplersystems/simplecmp-typo3/Backend/BulkSelect.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@wapplersystems/simplecmp-typo3/Backend/Pagination.js'
        );
        return $moduleTemplate;
    }

    private function addFlash(
        string $key,
        ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK,
        array $tokens = [],
    ): void {
        $message = $this->translate($key) ?? $key;
        foreach ($tokens as $token => $value) {
            $message = str_replace('{' . $token . '}', (string) $value, $message);
        }
        $this->addFlashMessage($message, '', $severity);
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
