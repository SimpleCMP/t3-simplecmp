<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller\Backend;

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
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Service\BridgeSecretProvider;
use SimpleCMP\T3SimpleCmp\Service\DetectionListFilter;
use SimpleCMP\T3SimpleCmp\Service\DetectionListPresenter;
use SimpleCMP\T3SimpleCmp\Service\ServiceCurator;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;

/**
 * Backend module: review unknown-tracker detections that the SimpleCMP
 * CMS bridge posted into `tx_t3simplecmp_detection`.
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
 * `erkannt`, `unbekannt`, `kuratiert`, `verworfen`, `all`. The "needs
 * action" default surfaces only rows the admin can productively act on
 * — both curated and dismissed are excluded since they require no
 * further triage.
 */
final class DetectionReviewController extends ActionController
{
    private const string DETECTION_TABLE = 'tx_t3simplecmp_detection';
    private const string SERVICE_TABLE = 'tx_t3simplecmp_service';
    private const array PER_PAGE_OPTIONS = [25, 50, 100, 500];
    private const int DEFAULT_PER_PAGE = 25;
    private const array STATUS_OPTIONS = [
        'pending',
        'erkannt',
        'unbekannt',
        'kuratiert',
        'verworfen',
        'all',
    ];
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
        private readonly DetectionListFilter $listFilter,
        private readonly ServiceCurator $serviceCurator,
        private readonly StoragePidResolver $storagePidResolver,
        private readonly BridgeSecretProvider $bridgeSecretProvider,
        private readonly \SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamClient $libraryUpstream,
        private readonly \SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamStats $libraryUpstreamStats,
        private readonly \TYPO3\CMS\Core\Site\SiteFinder $siteFinder,
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
        // Auto-generate the bridge HMAC secret if it isn't configured
        // yet. Cheap no-op when already present; writes to
        // LocalConfiguration.php on first BE module access so the
        // bridge works out of the box without the admin having to run
        // the CLI command + edit env. Env-var override still wins for
        // production 12-factor deploys.
        $this->bridgeSecretProvider->ensureExists();

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
        $this->listFilter->apply($qb, $filters);
        $allRows = $qb->executeQuery()->fetchAllAssociative();

        $decorated = [];
        foreach ($allRows as $r) {
            $decorated[] = DetectionListPresenter::decorateState(
                $r,
                $context['services'],
                $context['library'],
                $context['upstreamCache'] ?? [],
            );
        }
        $stateFiltered = array_values(array_filter(
            $decorated,
            fn (array $r): bool => $this->stateMatches((string) $r['state'], $filters['status']),
        ));
        $filteredCount = count($stateFiltered);
        $totalPages = max(1, (int) ceil($filteredCount / $perPage));
        $page = min($page, $totalPages);
        $paginated = array_slice($stateFiltered, ($page - 1) * $perPage, $perPage);

        // State counts need to be computed before the per-row decoration loop
        // because each Erkannt row reads its affected count from the map.
        $stateCounts = $this->stateCountsAcrossAll($context);

        $filterArg = $this->filterArg($filters);
        $rowsWithActions = [];
        foreach ($paginated as $r) {
            $rowArgs = ['uid' => (int) $r['uid']] + $filterArg;
            $r['uri_show'] = $this->uri('show', $rowArgs);
            $r['uri_createService'] = $this->uri('createService', ['uid' => (int) $r['uid']]);
            $r['uri_approve'] = $this->uri('approve', $rowArgs);
            $r['uri_dismiss'] = $this->uri('dismiss', $rowArgs);
            $r['uri_undismiss'] = $this->uri('undismiss', $rowArgs);
            $r['uri_purge'] = $this->uri('purge', $rowArgs);
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
            $r['approve_affected_count'] = 0;
            if ($r['state'] === DetectionListPresenter::STATE_RECOGNIZED
                && is_array($r['match'] ?? null)
                && isset($r['match']['id'])
            ) {
                $r['approve_affected_count'] = $stateCounts['affectedByLibraryId'][(string) $r['match']['id']] ?? 0;
            }
            $r = DetectionListPresenter::decorateConfidence($r);
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
            'pendingCount' => $stateCounts['pending'],
            'curatedCount' => $stateCounts['kuratiert'],
            'dismissedCount' => $stateCounts['verworfen'],
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
            'uri_bulkDismissAll' => $this->uri('bulkDismissAll', $filterArg),
            'uri_bulkDismissSelected' => $this->uri('bulkDismissSelected', $filterArg),
            'uri_bulkUndismissSelected' => $this->uri('bulkUndismissSelected', $filterArg),
            'uri_bulkPurgeSelected' => $this->uri('bulkPurgeSelected', $filterArg),
            'uri_generateBridgeSecret' => $this->uri('generateBridgeSecret'),
            'uri_resetFilters' => $this->uri('list'),
            'filtersActive' => $filterArg !== [],
            'uri_detectionsTab' => $this->uri('list'),
            'uri_registryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Backend\\RegistryList_list',
            ),
            'uri_libraryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Backend\\LibraryBrowser_list',
            ),
            'uri_discover' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Backend\\Discovery_index',
            ),
            'uri_reclassifyUnknowns' => $this->uri('reclassifyUnknowns'),
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
     * "pending" is a pseudo-state that covers anything actionable —
     * erkannt + unbekannt — and excludes both curated and dismissed
     * (neither needs admin attention). The default view, since
     * those are the rows the admin can productively triage.
     */
    private function stateMatches(string $rowState, string $filterStatus): bool
    {
        if ($filterStatus === 'all') {
            return true;
        }
        if ($filterStatus === 'pending') {
            return $rowState !== DetectionListPresenter::STATE_CURATED
                && $rowState !== DetectionListPresenter::STATE_DISMISSED;
        }
        return $rowState === $filterStatus;
    }

    /**
     * Header counters + per-library affected-row map. Single full-table
     * pass: count pending / curated / dismissed for the header, and
     * bucket Erkannt rows by their matched library service id so the
     * *Übernehmen* confirmation modal can show "approving this resolves
     * N detections." Dismissed rows are not counted as affected (admin
     * already chose not to act on them).
     *
     * @param array{services: array<array<string, mixed>>, library: array<array<string, mixed>>} $context
     * @return array{
     *     pending: int,
     *     kuratiert: int,
     *     verworfen: int,
     *     affectedByLibraryId: array<string, int>
     * }
     */
    private function stateCountsAcrossAll(array $context): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'kind', 'identifier', 'origin', 'dismissed_at')
            ->from(self::DETECTION_TABLE)
            ->executeQuery()
            ->fetchAllAssociative();
        $pending = 0;
        $curated = 0;
        $dismissed = 0;
        $affectedByLibraryId = [];
        foreach ($rows as $r) {
            $derived = DetectionListPresenter::deriveState(
                $r,
                $context['services'],
                $context['library'],
                $context['upstreamCache'] ?? [],
            );
            if ($derived['state'] === DetectionListPresenter::STATE_DISMISSED) {
                $dismissed++;
            } elseif ($derived['state'] === DetectionListPresenter::STATE_CURATED) {
                $curated++;
            } else {
                $pending++;
            }
            if ($derived['state'] === DetectionListPresenter::STATE_RECOGNIZED
                && is_array($derived['match'])
                && isset($derived['match']['id'])
            ) {
                $id = (string) $derived['match']['id'];
                $affectedByLibraryId[$id] = ($affectedByLibraryId[$id] ?? 0) + 1;
            }
        }
        return [
            'pending' => $pending,
            'kuratiert' => $curated,
            'verworfen' => $dismissed,
            'affectedByLibraryId' => $affectedByLibraryId,
        ];
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
     * One-click generate-or-rotate: always produces a fresh HMAC
     * secret and persists it to `config/system/settings.php` via
     * TYPO3's `ConfigurationManager`. Idempotent semantics:
     *
     * - First call on a fresh install (no auto-gen ran yet) creates
     *   the secret. Rare in practice — the auto-gen path in
     *   `listAction` usually fires first.
     * - Subsequent calls rotate the existing secret. Visitor pages
     *   loaded before rotation get one 401 cycle until their next
     *   page render re-issues a fresh nonce. The "Rotate secret"
     *   button in the BE template confirms via modal first.
     */
    public function generateBridgeSecretAction(): ResponseInterface
    {
        $this->bridgeSecretProvider->rotate();
        return $this->redirect('list');
    }

    /**
     * Walk all current `unbekannt` detections, deduplicate by
     * (kind, identifier|origin), and run an upstream library lookup
     * for each unique key. Cache writes happen as a side-effect of
     * `LibraryUpstreamClient::lookup()`; the BE list's state
     * derivation picks them up via `loadStateContext()` on the
     * subsequent render so unbekannt rows flip to erkannt without
     * any per-row migration.
     *
     * Respects the daily budget — entries that would push past the
     * cap are skipped (counted in the flash summary) and can be
     * tried again tomorrow.
     *
     * Idempotent: cache hits short-circuit upstream calls, so
     * pressing the button twice in quick succession costs the same
     * as once.
     */
    public function reclassifyUnknownsAction(): ResponseInterface
    {
        [$upstreamUrl, $dailyBudget] = $this->resolveLibraryUpstream();
        if ($upstreamUrl === null) {
            $this->emitFlash(
                'list.reclassify.flash.disabled',
                ContextualFeedbackSeverity::WARNING,
            );
            return $this->redirect('list');
        }

        $context = $this->listPresenter->loadStateContext();
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('kind', 'identifier', 'origin', 'dismissed_at')
            ->from(self::DETECTION_TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        // Deduplicate by the cache key the upstream client uses, so a
        // table with 1000 occurrences of the same unknown cookie costs
        // exactly one upstream call.
        $candidates = [];
        foreach ($rows as $r) {
            $derived = DetectionListPresenter::deriveState(
                $r,
                $context['services'],
                $context['library'],
                $context['upstreamCache'] ?? [],
            );
            if ($derived['state'] !== DetectionListPresenter::STATE_UNKNOWN) {
                continue;
            }
            $kind = (string) ($r['kind'] ?? '');
            $identifier = (string) ($r['identifier'] ?? '');
            $origin = isset($r['origin']) ? (string) $r['origin'] : '';
            if ($kind === 'cookie' && $identifier !== '') {
                $candidates['cookie:' . $identifier] = ['cookie' => $identifier, 'origin' => null];
            } elseif ($origin !== '') {
                $candidates['origin:' . $origin] = ['cookie' => null, 'origin' => $origin];
            }
        }

        $matched = 0;
        $unmatched = 0;
        $skipped = 0;
        foreach ($candidates as $candidate) {
            $result = $this->libraryUpstream->lookup(
                $upstreamUrl,
                $candidate['cookie'],
                $candidate['origin'],
                $dailyBudget,
            );
            if ($result === null) {
                // Budget exhausted (or upstream URL got nulled mid-loop, but
                // that's impossible since we resolved it above).
                $skipped++;
                continue;
            }
            if ($result === []) {
                $unmatched++;
            } else {
                $matched++;
            }
        }

        $this->emitFlash(
            'list.reclassify.flash.summary',
            $matched > 0 ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::INFO,
            [
                'matched' => $matched,
                'unmatched' => $unmatched,
                'skipped' => $skipped,
                'total' => count($candidates),
            ],
        );
        return $this->redirect('list');
    }

    /**
     * BE-module variant of {@see ServiceDbApi::resolveLibraryUpstream()}
     * — there's no request URI to match here, so we pick the first
     * Site Set that has the URL configured and read its budget.
     *
     * @return array{0: string|null, 1: int}
     */
    private function resolveLibraryUpstream(): array
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            $settings = $site->getSettings();
            $url = $settings->get('simplecmp.libraryUpstreamUrl');
            if (!is_string($url) || $url === '') {
                continue;
            }
            $budget = $settings->get('simplecmp.libraryUpstreamDailyBudget');
            return [$url, is_int($budget) ? $budget : (int) $budget];
        }
        return [null, 0];
    }

    /**
     * Push a localized flash via Extbase's controller helper, which
     * routes through the controller's own queue identifier (auto-
     * resolved by the framework) so the next module render picks it
     * up. Using the raw FlashMessageService with a default queue
     * identifier doesn't work in Extbase BE modules — the rendered
     * template reads from the Extbase-specific queue.
     *
     * @param array<string, scalar> $arguments
     */
    private function emitFlash(string $key, ContextualFeedbackSeverity $severity, array $arguments = []): void
    {
        $messageKey = 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_mod.xlf:' . $key;
        $message = (string) ($GLOBALS['LANG']->sL($messageKey) ?: $key);
        if ($arguments !== []) {
            $message = strtr($message, array_combine(
                array_map(static fn ($k) => '{' . $k . '}', array_keys($arguments)),
                array_map(static fn ($v) => (string) $v, array_values($arguments)),
            ));
        }
        $this->addFlashMessage($message, '', $severity);
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
        $row = DetectionListPresenter::decorateState(
            $row,
            $context['services'],
            $context['library'],
            $context['upstreamCache'] ?? [],
        );
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
        // Übernehmen inserts (or updates) the registry row. The registry
        // holds admin-curated services only, so anything here appears on
        // the FE banner. fromLibrary=true stamps library_adopted_at so
        // the Dienste tab can derive Aus-Bibliothek source state.
        $this->serviceRepository->upsert($match, $pid, true);
        return $this->redirectToList($filters);
    }

    /**
     * Verwerfen — flip `dismissed_at` to NOW on a single row.
     *
     * Recoverable via undismiss. The row stays in the table; future
     * bridge re-POSTs for the same (source, kind, identifier) triple
     * bump `occurrences` / `last_seen` but leave `dismissed_at` set, so
     * dismissal survives across visitors with different browsers.
     *
     * Only acts on rows where `dismissed_at = 0` — a stale POST against
     * an already-dismissed row is a no-op rather than rewriting the
     * audit timestamp. The per-row Verwerfen button is hidden by the
     * template on dismissed rows; this SQL guard is defense-in-depth
     * against forged URLs.
     */
    public function dismissAction(
        int $uid,
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->executeStatement(
                'UPDATE ' . self::DETECTION_TABLE
                . ' SET dismissed_at = ?, tstamp = ? WHERE uid = ? AND dismissed_at = 0',
                [time(), time(), $uid],
            );
        return $this->redirectToList($this->normalizeFilters($status, $source, $kind, $confidence));
    }

    /**
     * Wieder aufgreifen — clear `dismissed_at` so the row falls back to
     * its derived state (erkannt / unbekannt / kuratiert) and reappears
     * in the actionable list. Only touches dismissed rows so the
     * idempotent case stays a no-op.
     */
    public function undismissAction(
        int $uid,
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->executeStatement(
                'UPDATE ' . self::DETECTION_TABLE
                . ' SET dismissed_at = 0, tstamp = ? WHERE uid = ? AND dismissed_at > 0',
                [time(), $uid],
            );
        return $this->redirectToList($this->normalizeFilters($status, $source, $kind, $confidence));
    }

    /**
     * Endgültig löschen — true delete, reachable from the Verworfen view
     * only and behind a confirmation modal in the template. Removes the
     * row entirely; the next bridge POST for the same triple will
     * re-create it as a fresh `unbekannt` detection.
     *
     * Defense-in-depth: only deletes if the row is actually dismissed,
     * even though the template only renders the purge button on
     * Verworfen rows. A forged URL pointing at a non-dismissed UID is a
     * no-op rather than a quiet destructive bypass of the dismiss-first
     * audit-trail rule.
     */
    public function purgeAction(
        int $uid,
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->executeStatement(
                'DELETE FROM ' . self::DETECTION_TABLE
                . ' WHERE uid = ? AND dismissed_at > 0',
                [$uid],
            );
        return $this->redirectToList($this->normalizeFilters($status, $source, $kind, $confidence));
    }

    /**
     * Bulk-dismiss every row currently in the actionable filter. Same
     * "are you sure" dropdown affordance as before — just dismisses
     * instead of destroying.
     *
     * Race-safety: this is a single UPDATE statement with a WHERE
     * clause, which is atomic under InnoDB's default REPEATABLE READ
     * isolation — the read view is established at statement start, so
     * any INSERT landing after this UPDATE begins is not visible to
     * its scan and won't be swept up. No explicit BEGIN/COMMIT needed.
     * (Filter args are not currently passed into the WHERE — the
     * action dismisses every undismissed row regardless of which
     * filter the admin was viewing. That's by current design; the
     * "are you sure" modal carries the burden of communicating scope.)
     */
    public function bulkDismissAllAction(
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $this->connectionPool->getConnectionForTable(self::DETECTION_TABLE)
            ->executeStatement(
                'UPDATE ' . self::DETECTION_TABLE
                . ' SET dismissed_at = ?, tstamp = ? WHERE dismissed_at = 0',
                [time(), time()],
            );
        return $this->redirectToList($this->normalizeFilters($status, $source, $kind, $confidence));
    }

    /**
     * Bulk-dismiss the rows the admin ticked in the list checkboxes.
     *
     * @param array<int, scalar> $uids
     */
    public function bulkDismissSelectedAction(
        array $uids = [],
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        return $this->bulkUpdateDismissed($uids, $status, $source, $kind, $confidence, time());
    }

    /**
     * Bulk-undismiss — counterpart to bulkDismissSelected for the
     * Verworfen view.
     *
     * @param array<int, scalar> $uids
     */
    public function bulkUndismissSelectedAction(
        array $uids = [],
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        return $this->bulkUpdateDismissed($uids, $status, $source, $kind, $confidence, 0);
    }

    /**
     * Bulk-purge — true delete of the ticked rows, reachable only from
     * the Verworfen view (the template hides this bulk-action key
     * elsewhere) and confirmed via a modal.
     *
     * @param array<int, scalar> $uids
     */
    public function bulkPurgeSelectedAction(
        array $uids = [],
        string $status = self::DEFAULT_STATUS,
        string $source = '',
        string $kind = '',
        string $confidence = '',
    ): ResponseInterface {
        $filters = $this->normalizeFilters($status, $source, $kind, $confidence);
        $ints = $this->intUids($uids);
        if ($ints === []) {
            return $this->redirectToList($filters);
        }
        // Purge only deletes rows that are actually dismissed — the
        // dismiss-first audit-trail rule must hold even if a forged
        // URL points the bulk action at non-Verworfen UIDs.
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->delete(self::DETECTION_TABLE)
            ->where($qb->expr()->in(
                'uid',
                $qb->createNamedParameter($ints, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY),
            ))
            ->andWhere($qb->expr()->gt(
                'dismissed_at',
                $qb->createNamedParameter(0, ParameterType::INTEGER),
            ))
            ->executeStatement();
        return $this->redirectToList($filters);
    }

    /**
     * Bulk-dismiss ($newValue > 0) or bulk-undismiss ($newValue = 0).
     *
     * The WHERE clause filters by current `dismissed_at` so the
     * operation is symmetric and idempotent — dismissing only touches
     * not-yet-dismissed rows, undismissing only touches dismissed rows.
     * This preserves the audit timestamp on rows that are already in
     * the target state, which matters in the `all` view where a ticked
     * Verworfen row used to silently reset its `dismissed_at` to NOW.
     *
     * @param array<int, scalar> $uids
     */
    private function bulkUpdateDismissed(
        array $uids,
        string $status,
        string $source,
        string $kind,
        string $confidence,
        int $newValue,
    ): ResponseInterface {
        $filters = $this->normalizeFilters($status, $source, $kind, $confidence);
        $ints = $this->intUids($uids);
        if ($ints === []) {
            return $this->redirectToList($filters);
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::DETECTION_TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->update(self::DETECTION_TABLE)
            ->set('dismissed_at', (string) $newValue, false)
            ->set('tstamp', (string) time(), false)
            ->where($qb->expr()->in(
                'uid',
                $qb->createNamedParameter($ints, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY),
            ))
            ->andWhere($newValue > 0
                ? $qb->expr()->eq('dismissed_at', $qb->createNamedParameter(0, ParameterType::INTEGER))
                : $qb->expr()->gt('dismissed_at', $qb->createNamedParameter(0, ParameterType::INTEGER)))
            ->executeStatement();
        return $this->redirectToList($filters);
    }

    /**
     * @param array<int, scalar> $uids
     * @return list<int>
     */
    private function intUids(array $uids): array
    {
        return array_values(array_filter(
            array_map('intval', $uids),
            static fn (int $u): bool => $u > 0,
        ));
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
            '@simplecmp/t3-simplecmp/Backend/ConfirmForm.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@simplecmp/t3-simplecmp/Backend/BulkSelect.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@simplecmp/t3-simplecmp/Backend/Pagination.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@simplecmp/t3-simplecmp/Backend/ApproveModal.js'
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
            'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_mod.xlf:' . $key
        );
        return $translated !== '' ? $translated : null;
    }
}
