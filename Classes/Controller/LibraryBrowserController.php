<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller;

use Psr\Http\Message\ResponseInterface;
use SimpleCMP\T3SimpleCmp\Library\ServicesLibrary;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\LibraryCacheRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Service\BundledLibraryInfo;
use SimpleCMP\T3SimpleCmp\Service\LibraryRecommendationService;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamHealth;
use SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamStats;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;

/**
 * Backend module tab: browse the bundled
 * `simplecmp/services-library` JSON catalog and copy entries into the
 * site's registry on demand.
 *
 * Shares the `simplecmp_detections` module slot with
 * `DetectionReviewController`; rendered as the "Bibliothek" tab next
 * to "Detektionen".
 *
 * The library is a read-only reference: this controller never modifies
 * the JSON files. The only write path is `adoptAction`, which copies
 * one library entry into `tx_t3simplecmp_service` via the existing
 * upsert flow. After adoption the service appears on the visitor's
 * banner (every registry row is on the banner post-fe_visible
 * architecture).
 */
final class LibraryBrowserController extends ActionController
{
    private const array PER_PAGE_OPTIONS = [25, 50, 100, 500];
    private const int DEFAULT_PER_PAGE = 25;
    /**
     * `recommended` filters to entries whose adoption would resolve at
     * least one actionable detection on this site. Listed in dropdown
     * order: Verfügbar → Empfohlen → Übernommen → Alle.
     */
    private const array STATUS_OPTIONS = ['available', 'recommended', 'adopted', 'all'];
    private const string DEFAULT_STATUS = 'available';

    /**
     * How many recommendations to render inline in the "💡 Empfohlen"
     * top section before the "Alle X ansehen →" overflow link. Five
     * keeps the top area tight without hiding the long tail.
     */
    private const int TOP_RECOMMENDATIONS_INLINE = 5;

    /**
     * Cap on detections fetched per render. Recommendation matching is
     * O(detections × library), so an unbounded set could become a
     * bottleneck on busy production sites. 1000 covers the realistic
     * upper bound of "open actionable detections" for a single admin
     * session — anything beyond that is a triage problem on the
     * Detektionen tab, not a library-discovery problem.
     */
    private const int DETECTIONS_FETCH_LIMIT = 1000;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly UriBuilder $backendUriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly ServiceRepository $serviceRepository,
        private readonly StoragePidResolver $storagePidResolver,
        private readonly LibraryUpstreamStats $upstreamStats,
        private readonly LibraryCacheRepository $libraryCache,
        private readonly SiteFinder $siteFinder,
        private readonly LibraryUpstreamHealth $upstreamHealth,
        private readonly BundledLibraryInfo $bundledLibrary,
        private readonly DetectionRepository $detectionRepository,
        private readonly LibraryRecommendationService $recommendationService,
        private readonly \SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService $draftWorkspace,
    ) {
    }

    public function listAction(
        string $status = self::DEFAULT_STATUS,
        string $search = '',
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): ResponseInterface {
        $status = in_array($status, self::STATUS_OPTIONS, true) ? $status : self::DEFAULT_STATUS;
        $search = trim($search);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
        $page = max(1, $page);

        $adoptedIds = $this->adoptedIds();
        $allEntries = $this->loadLibrary($adoptedIds);
        $availableCount = 0;
        $adoptedCount = 0;
        foreach ($allEntries as $entry) {
            if ($entry['adopted']) {
                $adoptedCount++;
            } else {
                $availableCount++;
            }
        }

        // Compute recommendations once per render. Pure compute against
        // the registry + library + recent detections; cheap enough at the
        // current scale (see TOP_RECOMMENDATIONS_INLINE comment).
        $recommendations = $this->recommendationService->recommendationsFor(
            $this->detectionRepository->recent(self::DETECTIONS_FETCH_LIMIT),
            $this->serviceRepository->findAll(),
            $allEntries,
            $adoptedIds,
        );
        $headline = $this->recommendationService->headline($recommendations);

        $filtered = array_values(array_filter($allEntries, function (array $entry) use ($status, $search, $recommendations): bool {
            if ($status === 'available' && $entry['adopted']) {
                return false;
            }
            if ($status === 'adopted' && !$entry['adopted']) {
                return false;
            }
            if ($status === 'recommended' && !isset($recommendations[(string) ($entry['id'] ?? '')])) {
                return false;
            }
            if ($search !== '' && !$this->matchesSearch($entry, $search)) {
                return false;
            }
            return true;
        }));

        // When the admin is viewing the Empfohlen filter, sort by match
        // count descending (highest-impact first). Alphabetical
        // tiebreak via secondary sort key.
        if ($status === 'recommended') {
            usort($filtered, function (array $a, array $b) use ($recommendations): int {
                $idA = (string) ($a['id'] ?? '');
                $idB = (string) ($b['id'] ?? '');
                $countA = $recommendations[$idA]['count'] ?? 0;
                $countB = $recommendations[$idB]['count'] ?? 0;
                if ($countA !== $countB) {
                    return $countB <=> $countA;
                }
                return strcmp($idA, $idB);
            });
        }

        $filteredCount = count($filtered);
        $totalPages = max(1, (int) ceil($filteredCount / $perPage));
        $page = min($page, $totalPages);
        $paginated = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        $filterArg = $this->filterArg($status, $search);
        $rows = array_map(
            fn (array $entry): array => $this->decorateRow($entry, $filterArg, $recommendations),
            $paginated,
        );

        // Top "💡 Empfohlen für diese Site" section. Renders only when
        // there's at least one recommendation. Top 5 inline; overflow
        // link below jumps to the table filtered to ?status=recommended.
        $topRecommendedRows = [];
        foreach ($allEntries as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if (!isset($recommendations[$id])) {
                continue;
            }
            $topRecommendedRows[] = [
                'entry' => $this->decorateRow($entry, $filterArg, $recommendations),
                'count' => $recommendations[$id]['count'],
            ];
        }
        usort($topRecommendedRows, function (array $a, array $b): int {
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }
            return strcmp(
                (string) ($a['entry']['id'] ?? ''),
                (string) ($b['entry']['id'] ?? ''),
            );
        });
        $topRecommendedInline = array_map(
            fn (array $row): array => $row['entry'],
            array_slice($topRecommendedRows, 0, self::TOP_RECOMMENDATIONS_INLINE),
        );

        $pageArg = $filterArg + ['perPage' => $perPage];
        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
            'upstreamStatus' => $this->buildUpstreamStatus(count($allEntries)),
            'entries' => $rows,
            'status' => $status,
            'search' => $search,
            'statusOptions' => self::STATUS_OPTIONS,
            'availableCount' => $availableCount,
            'adoptedCount' => $adoptedCount,
            'totalCount' => count($allEntries),
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
            'uri_resetFilters' => $this->uri('list'),
            'filtersActive' => $filterArg !== [],
            'uri_detectionsTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.DetectionReview_list',
            ),
            'uri_registryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.RegistryList_list',
            ),
            'uri_libraryTab' => $this->uri('list'),
            'uri_trackerSetupTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.TrackerSetup_list',
            ),
            'uri_auditTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.AuditSnapshot_list',
            ),
            'uri_auskunftTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.AuditAuskunft_index',
            ),
            'topRecommended' => $topRecommendedInline,
            'recommendationHeadline' => $headline,
            'recommendationOverflow' => max(0, count($recommendations) - count($topRecommendedInline)),
            'uri_seeAllRecommended' => $this->uri('list', ['status' => 'recommended']),
            // Full list of recommended service-ids, sorted by count desc
            // (same order as the top section + the ?status=recommended
            // table). Backs the "Alle X übernehmen" one-shot form which
            // posts the entire list to bulkAdoptAction.
            'allRecommendedIds' => array_map(
                fn (array $row): string => (string) ($row['entry']['id'] ?? ''),
                $topRecommendedRows,
            ),
            'uri_bulkAdopt' => $this->uri('bulkAdopt', $filterArg),
            'uri_bulkUnadopt' => $this->uri('bulkUnadopt', $filterArg),
        ]);
        return $moduleTemplate->renderResponse('LibraryBrowser/List');
    }

    public function adoptAction(
        string $serviceId,
        string $status = self::DEFAULT_STATUS,
        string $search = '',
    ): ResponseInterface {
        $entry = $this->loadLibraryEntry($serviceId);
        if ($entry !== null) {
            try {
                $beUserId = $this->ensureGlobalDraft();
            } catch (\RuntimeException $e) {
                $this->addFlashMessage($e->getMessage(), '', \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR);
                return $this->redirect('list', null, null, $this->filterArg($status, $search));
            }
            // fromLibrary: true → stamps `library_adopted_at`
            $this->serviceRepository->upsertDraft(
                \SimpleCMP\T3SimpleCmp\Service\LockState::SCOPE_GLOBAL,
                $entry,
                $beUserId,
                true,
            );
        }
        return $this->redirect('list', null, null, $this->filterArg($status, $search));
    }

    /**
     * Phase 4 — acquire the global service-registry draft lock.
     */
    private function ensureGlobalDraft(): int
    {
        $beUserId = (int) ($GLOBALS['BE_USER']->user['uid'] ?? 0);
        if ($beUserId <= 0) {
            throw new \RuntimeException('Editor draft requires a logged-in BE user.');
        }
        $lock = $this->draftWorkspace->initializeDraft(
            \SimpleCMP\T3SimpleCmp\Service\LockState::SCOPE_GLOBAL,
            $beUserId,
        );
        if ($lock->conflict) {
            throw new \RuntimeException(sprintf(
                'Lock für die globale Service-Registry gehört BE-User uid=%d. Bitte abwarten oder den Lock übernehmen.',
                $lock->ownerBeUserId,
            ));
        }
        return $beUserId;
    }

    /**
     * Adopt many library entries in one round-trip. Used by the
     * "Alle X übernehmen" one-shot on the top recommended section
     * and by the per-row-checkbox toolbar.
     *
     * No confirm dialog by design — adoption is reversible via the
     * Dienste-tab unadopt path. Idempotent: re-submitting the same
     * id list a second time is a no-op (upsert UPDATE branch).
     *
     * `$serviceIds` arrives from the HTML form as a (possibly nested)
     * array of strings. Unknown ids are skipped silently (e.g. a
     * library entry was removed upstream between the form render
     * and the submit).
     *
     * @param array<int|string, mixed> $serviceIds
     */
    public function bulkAdoptAction(
        array $serviceIds = [],
        string $status = self::DEFAULT_STATUS,
        string $search = '',
    ): ResponseInterface {
        try {
            $beUserId = $this->ensureGlobalDraft();
        } catch (\RuntimeException $e) {
            $this->addFlashMessage($e->getMessage(), '', \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR);
            return $this->redirect('list', null, null, $this->filterArg($status, $search));
        }
        foreach ($serviceIds as $serviceId) {
            if (!is_string($serviceId) || $serviceId === '') {
                continue;
            }
            $entry = $this->loadLibraryEntry($serviceId);
            if ($entry !== null) {
                $this->serviceRepository->upsertDraft(
                    \SimpleCMP\T3SimpleCmp\Service\LockState::SCOPE_GLOBAL,
                    $entry,
                    $beUserId,
                    true,
                );
            }
        }
        return $this->redirect('list', null, null, $this->filterArg($status, $search));
    }

    public function unadoptAction(
        string $serviceId,
        string $status = self::DEFAULT_STATUS,
        string $search = '',
    ): ResponseInterface {
        try {
            $this->ensureGlobalDraft();
        } catch (\RuntimeException $e) {
            $this->addFlashMessage($e->getMessage(), '', \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR);
            return $this->redirect('list', null, null, $this->filterArg($status, $search));
        }
        $this->serviceRepository->deleteDraft(
            \SimpleCMP\T3SimpleCmp\Service\LockState::SCOPE_GLOBAL,
            $serviceId,
        );
        return $this->redirect('list', null, null, $this->filterArg($status, $search));
    }

    /**
     * Symmetric counterpart to bulkAdoptAction. Loops
     * ServiceRepository::delete per id. Same lack of confirm dialog —
     * unadoption is reversible via the per-row [Übernehmen] button.
     * Idempotent: deleting an already-deleted id is a silent no-op.
     *
     * @param array<int|string, mixed> $serviceIds
     */
    public function bulkUnadoptAction(
        array $serviceIds = [],
        string $status = self::DEFAULT_STATUS,
        string $search = '',
    ): ResponseInterface {
        try {
            $this->ensureGlobalDraft();
        } catch (\RuntimeException $e) {
            $this->addFlashMessage($e->getMessage(), '', \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR);
            return $this->redirect('list', null, null, $this->filterArg($status, $search));
        }
        foreach ($serviceIds as $serviceId) {
            if (!is_string($serviceId) || $serviceId === '') {
                continue;
            }
            $this->serviceRepository->deleteDraft(
                \SimpleCMP\T3SimpleCmp\Service\LockState::SCOPE_GLOBAL,
                $serviceId,
            );
        }
        return $this->redirect('list', null, null, $this->filterArg($status, $search));
    }

    /**
     * "Jetzt prüfen" button in the Bibliotheks-Upstream card. Drops the
     * cached `/v1/health` snapshots so the next list render re-probes
     * upstream. Touches only the health cache — the per-cookie lookup
     * cache (LibraryCacheRepository) is unaffected.
     */
    public function refreshUpstreamHealthAction(): ResponseInterface
    {
        // Explicit, user-triggered probe: flush the cache, then probe
        // ONCE here (the list render only reads cache and never probes).
        // A failed probe is negative-cached by snapshot(), so this can't
        // hang on every press.
        $this->upstreamHealth->flush();
        $url = $this->firstConfiguredUpstreamUrl();
        if ($url !== null) {
            $this->upstreamHealth->snapshot($url, $this->bundledLibrary->dataHash());
        }
        return $this->redirect('list');
    }

    /**
     * First site-configured upstream URL across all sites, or null.
     * Multi-site installs typically share one upstream; we use the
     * first non-empty one we see (same rule as buildUpstreamStatus).
     */
    private function firstConfiguredUpstreamUrl(): ?string
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            $url = $site->getSettings()->get('simplecmp.libraryUpstreamUrl');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------

    /**
     * Build the data structure consumed by the `UpstreamStatus` partial
     * on the Bibliothek tab. Cache rows + stats are system-wide;
     * upstream URL + daily budget are per-site, so we list one entry
     * per site that has the URL configured (usually just one).
     *
     * @param int $bundleServiceCount Pre-counted from loadLibrary() — passed in to avoid iterating
     *     `ServicesLibrary::services()` twice per list render.
     *
     * @return array{
     *     enabled: bool,
     *     cache: array{positive: int, negative: int, expired: int, total: int},
     *     todayCalls: int,
     *     todaySuccesses: int,
     *     todayFailures: int,
     *     totalCalls: int,
     *     maxBudget: int,
     *     budgetExhausted: bool,
     *     lastCallAt: int|null,
     *     lastSuccessAt: int|null,
     *     lastFailureAt: int|null,
     *     sites: list<array{identifier: string, url: string, budget: int}>,
     *     bundle: array{version: string|null, sha: string|null, shortSha: string|null, serviceCount: int, changelogUrl: string},
     *     upstream: array{
     *         probed: bool,
     *         state: 'ok'|'down'|'unknown',
     *         serviceCount: int|null,
     *         sourceSha: string|null,
     *         shortSourceSha: string|null,
     *         dataHash: string|null,
     *         lastSyncAt: int|null,
     *         fetchedAt: int|null,
     *         failedAt: int|null,
     *         inSync: bool,
     *     },
     *     uri_refresh: string,
     * }
     */
    private function buildUpstreamStatus(int $bundleServiceCount): array
    {
        $now = time();
        $cache = $this->libraryCache->countLive($now);
        $cache['total'] = $cache['positive'] + $cache['negative'];

        $snap = $this->upstreamStats->getSnapshot($now);

        $sites = [];
        $maxBudget = 0;
        $firstUpstreamUrl = null;
        foreach ($this->siteFinder->getAllSites() as $site) {
            $settings = $site->getSettings();
            $url = $settings->get('simplecmp.libraryUpstreamUrl');
            if (!is_string($url) || $url === '') {
                continue;
            }
            $firstUpstreamUrl ??= $url;
            $budget = $settings->get('simplecmp.libraryUpstreamDailyBudget');
            $budgetInt = is_int($budget) ? $budget : (int) $budget;
            $sites[] = [
                'identifier' => $site->getIdentifier(),
                'url' => $url,
                'budget' => $budgetInt,
            ];
            if ($budgetInt > $maxBudget) {
                $maxBudget = $budgetInt;
            }
        }

        $bundleSha = $this->bundledLibrary->sha();
        $bundleVersion = $this->bundledLibrary->version();
        $bundleDataHash = $this->bundledLibrary->dataHash();
        $bundle = [
            'version' => $bundleVersion,
            'sha' => $bundleSha,
            'shortSha' => $bundleSha !== null ? substr($bundleSha, 0, 7) : null,
            'serviceCount' => $bundleServiceCount,
            'changelogUrl' => 'https://github.com/SimpleCMP/services-library/blob/main/CHANGELOG.md',
        ];

        // Probe upstream /health only when at least one site has a URL
        // configured. Multi-site installs typically share one upstream;
        // we use the first one we see. Bundle dataHash drives cache
        // invalidation on the next composer update.
        // Cache-only read — the list render must NEVER block on the
        // network (a slow/unreachable upstream would otherwise hang every
        // Bibliothek-tab load). The probe runs only on the explicit
        // "Jetzt prüfen" button (refreshUpstreamHealthAction).
        $snapshot = $firstUpstreamUrl !== null
            ? $this->upstreamHealth->cachedSnapshot($firstUpstreamUrl, $bundleDataHash)
            : null;

        // Three render states, so the panel never claims "not reachable"
        // on a merely-stale cache (the common case — the success cache is
        // only 30 min and the render never probes):
        //   ok      — a fresh snapshot is cached
        //   down    — a probe failed within the negative-cache window
        //   unknown — cold/stale cache; the JS fires a background probe
        //             (UpstreamProbe.js) to self-heal without a click.
        $failedAt = ($snapshot === null && $firstUpstreamUrl !== null)
            ? $this->upstreamHealth->cachedFailureAt($firstUpstreamUrl, $bundleDataHash)
            : null;
        $upstreamState = $snapshot !== null ? 'ok' : ($failedAt !== null ? 'down' : 'unknown');

        // Drift comparison is on dataHash (content over service JSON
        // files) NOT sourceSha (which moves on every README/CI commit).
        // Upstreams that don't expose dataHash on /v1/health are
        // reported as drifted — operators see "Updates verfügbar" until
        // the upstream is rebuilt and dataHash matches.
        $upstreamDataHash = $snapshot['dataHash'] ?? null;
        $upstreamSourceSha = $snapshot['sourceSha'] ?? null;
        $inSync = $bundleDataHash !== ''
            && $upstreamDataHash !== null
            && hash_equals($bundleDataHash, $upstreamDataHash);

        $upstream = [
            'probed' => $snapshot !== null,
            'state' => $upstreamState,
            'serviceCount' => $snapshot['serviceCount'] ?? null,
            'sourceSha' => $upstreamSourceSha,
            'shortSourceSha' => $upstreamSourceSha !== null ? substr($upstreamSourceSha, 0, 7) : null,
            'dataHash' => $upstreamDataHash,
            'lastSyncAt' => $snapshot['lastSyncAt'] ?? null,
            'fetchedAt' => $snapshot['fetchedAt'] ?? null,
            'failedAt' => $failedAt,
            'inSync' => $inSync,
        ];

        return [
            'enabled' => $sites !== [],
            'cache' => $cache,
            'todayCalls' => $snap['today_calls'],
            'todaySuccesses' => $snap['today_successes'],
            'todayFailures' => $snap['today_failures'],
            'totalCalls' => $snap['total_calls'],
            'maxBudget' => $maxBudget,
            'budgetExhausted' => $maxBudget > 0 && $snap['today_calls'] >= $maxBudget,
            'lastCallAt' => $snap['last_call_at'],
            'lastSuccessAt' => $snap['last_success_at'],
            'lastFailureAt' => $snap['last_failure_at'],
            'sites' => $sites,
            'bundle' => $bundle,
            'upstream' => $upstream,
            'uri_refresh' => $this->uri('refreshUpstreamHealth'),
        ];
    }

    /**
     * @return array<string, true> keyed by service_id
     */
    private function adoptedIds(): array
    {
        $rows = $this->serviceRepository->findAll();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) ($row['id'] ?? '')] = true;
        }
        return $byId;
    }

    /**
     * @param array<string, true> $adoptedIds
     * @return list<array<string, mixed>>
     */
    private function loadLibrary(array $adoptedIds): array
    {
        $lang = $this->resolveBackendLanguageCode();
        $entries = [];
        foreach (ServicesLibrary::services() as $entry) {
            if (!isset($entry['id'])) {
                continue;
            }
            $id = (string) $entry['id'];
            $entry['adopted'] = isset($adoptedIds[$id]);
            $entry['resolvedDescription'] = $this->resolveLocalizedDescription($entry, $lang);
            // Payload for the per-row info modal — JSON-encoded full
            // service entry plus the resolved description (so JS doesn't
            // need to know the BE locale). Drift compared to the raw
            // JSON on disk: only `adopted` + `resolvedDescription` are
            // added; nothing rewritten.
            $entry['infoModalPayload'] = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * BE user's language code, normalised to a 2-letter form. Returns
     * `en` for `default` / empty so consumers can do straight
     * `$entry['i18n']['description'][$lang]` lookups without
     * special-casing English.
     */
    private function resolveBackendLanguageCode(): string
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        $lang = is_object($beUser) && isset($beUser->user['lang']) ? (string) $beUser->user['lang'] : '';
        $lang = strtolower(trim($lang));
        if ($lang === '' || $lang === 'default' || $lang === 'en') {
            return 'en';
        }
        return preg_replace('/[^a-z]/', '', substr($lang, 0, 2)) ?: 'en';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function resolveLocalizedDescription(array $entry, string $lang): string
    {
        $overlay = $entry['i18n']['description'][$lang] ?? null;
        if (is_string($overlay) && trim($overlay) !== '') {
            return $overlay;
        }
        $fallback = $entry['description'] ?? '';
        return is_string($fallback) ? $fallback : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLibraryEntry(string $serviceId): ?array
    {
        foreach (ServicesLibrary::services() as $entry) {
            if (isset($entry['id']) && (string) $entry['id'] === $serviceId) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function matchesSearch(array $entry, string $needle): bool
    {
        $haystack = strtolower(implode(' ', [
            (string) ($entry['id'] ?? ''),
            (string) ($entry['name'] ?? ''),
            (string) ($entry['vendor'] ?? ''),
            json_encode($entry['matches']['cookies'] ?? [], JSON_UNESCAPED_SLASHES) ?: '',
            json_encode($entry['matches']['origins'] ?? [], JSON_UNESCAPED_SLASHES) ?: '',
        ]));
        return str_contains($haystack, strtolower($needle));
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, scalar> $filterArg
     * @param array<string, array{count: int, identifiers: list<string>}> $recommendations
     * @return array<string, mixed>
     */
    private function decorateRow(array $entry, array $filterArg, array $recommendations = []): array
    {
        $id = (string) $entry['id'];
        $rowArgs = ['serviceId' => $id] + $filterArg;
        $entry['uri_adopt'] = $this->uri('adopt', $rowArgs);
        $entry['uri_unadopt'] = $this->uri('unadopt', $rowArgs);
        $entry['uri_edit'] = $entry['adopted'] ? $this->editServiceUri($id) : null;
        // Recommendation pill data. Tooltip preview (first 5 identifiers
        // + "+N more" suffix) is precomputed in PHP — Fluid's inline
        // f:if/f:join in HTML attributes hits the escaped-quote parser
        // trap documented in typo3_v14_gotchas memory and silently
        // renders the literal source.
        $rec = $recommendations[$id] ?? null;
        if ($rec !== null) {
            $entry['pillCount'] = $rec['count'];
            $sample = array_slice($rec['identifiers'], 0, 5);
            $more = max(0, $rec['count'] - count($sample));
            $entry['pillTooltip'] = $more > 0
                ? implode(', ', $sample) . ', +' . $more . ' more'
                : implode(', ', $sample);
        } else {
            $entry['pillCount'] = 0;
            $entry['pillTooltip'] = '';
        }
        return $entry;
    }

    private function editServiceUri(string $serviceId): ?string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_t3simplecmp_service');
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')
            ->from('tx_t3simplecmp_service')
            ->where($qb->expr()->eq('service_id', $qb->createNamedParameter($serviceId)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        if ($uid === false) {
            return null;
        }
        $returnUrl = $this->uri('list');
        return (string) $this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['tx_t3simplecmp_service' => [(int) $uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);
    }

    /**
     * @return array<string, scalar>
     */
    private function filterArg(string $status, string $search): array
    {
        $args = [];
        if ($status !== self::DEFAULT_STATUS) {
            $args['status'] = $status;
        }
        if ($search !== '') {
            $args['search'] = $search;
        }
        return $args;
    }

    /**
     * @param array<string, scalar> $arguments
     */
    private function uri(string $action, array $arguments = []): string
    {
        return (string) $this->uriBuilder
            ->reset()
            ->setRequest($this->request)
            ->uriFor($action, $arguments);
    }

    private function initModuleTemplate(): ModuleTemplate
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('SimpleCMP');
        $this->pageRenderer->loadJavaScriptModule(
            '@simplecmp/t3-simplecmp/Backend/Pagination.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@simplecmp/t3-simplecmp/Backend/ServiceInfoModal.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@simplecmp/t3-simplecmp/Backend/LibraryBulkSelect.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@simplecmp/t3-simplecmp/Backend/UpstreamProbe.js'
        );
        return $moduleTemplate;
    }
}
