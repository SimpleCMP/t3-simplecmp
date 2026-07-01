<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use SimpleCMP\T3SimpleCmp\Library\ServicesLibrary;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Service\RegistryListPresenter;

/**
 * Backend module tab: list every row in `tx_t3simplecmp_service`
 * regardless of source, tagged with `Eigene` / `Aus Bibliothek` /
 * `Verwaist`. Renders as the "Dienste" tab between Detections and
 * Bibliothek.
 *
 * Sole write path is `deleteAction`, and only for non-library rows —
 * Aus-Bibliothek deletions go through the Bibliothek tab's Unadopt
 * affordance to keep the symmetry of "adopted from library, unadopt
 * via library". Verwaist rows are deletable from here because the
 * library doesn't claim them anymore (admin owns the orphan).
 */
final class RegistryListController extends ActionController
{
    private const array PER_PAGE_OPTIONS = [25, 50, 100, 500];
    private const int DEFAULT_PER_PAGE = 25;
    private const array SOURCE_OPTIONS = ['all', 'custom', 'library', 'orphaned'];
    private const string DEFAULT_SOURCE = 'all';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly UriBuilder $backendUriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly ServiceRepository $serviceRepository,
        private readonly RegistryListPresenter $registryListPresenter,
        private readonly \SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService $draftWorkspace,
        private readonly \SimpleCMP\T3SimpleCmp\Service\DraftBannerContext $bannerContext,
        private readonly \SimpleCMP\T3SimpleCmp\Service\WizardBannerContext $wizardBannerContext,
        private readonly \SimpleCMP\T3SimpleCmp\Service\ActiveSiteResolver $activeSiteResolver,
    ) {
    }

    /**
     * Phase 4 — acquire the global service-registry draft lock and
     * copy live → draft if not yet present.
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

    public function listAction(
        string $source = self::DEFAULT_SOURCE,
        string $purpose = '',
        string $search = '',
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): ResponseInterface {
        $source = in_array($source, self::SOURCE_OPTIONS, true) ? $source : self::DEFAULT_SOURCE;
        $purpose = trim($purpose);
        $search = trim($search);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
        $page = max(1, $page);

        $libraryIds = RegistryListPresenter::libraryIdSet();
        $rawRows = $this->serviceRepository->findAllForRegistryView();
        $coverage = $this->registryListPresenter->coverageCountByServiceId();

        $custom = 0;
        $library = 0;
        $orphaned = 0;
        $decorated = [];
        foreach ($rawRows as $r) {
            $r = RegistryListPresenter::decorateRow($r, $libraryIds);
            $r['coverage_count'] = $coverage[(string) ($r['id'] ?? '')] ?? 0;
            match ($r['source']) {
                RegistryListPresenter::SOURCE_LIBRARY => $library++,
                RegistryListPresenter::SOURCE_ORPHANED => $orphaned++,
                default => $custom++,
            };
            $decorated[] = $r;
        }

        $filtered = array_values(array_filter($decorated, function (array $r) use ($source, $purpose, $search): bool {
            if ($source !== 'all' && $r['source'] !== $source) {
                return false;
            }
            if ($purpose !== '' && !in_array($purpose, $r['purposes'] ?? [], true)) {
                return false;
            }
            if ($search !== '' && !$this->matchesSearch($r, $search)) {
                return false;
            }
            return true;
        }));

        $filteredCount = count($filtered);
        $totalPages = max(1, (int) ceil($filteredCount / $perPage));
        $page = min($page, $totalPages);
        $paginated = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        $filterArg = $this->filterArg($source, $purpose, $search);
        $lang = $this->resolveBackendLanguageCode();
        $libraryIndex = $this->indexBundledLibraryById();
        $rows = array_map(function (array $r) use ($filterArg, $lang, $libraryIndex): array {
            $r['uri_edit'] = $this->editServiceUri((int) ($r['_uid'] ?? 0));
            $r['uri_delete'] = $r['source'] === RegistryListPresenter::SOURCE_LIBRARY
                ? null
                : $this->uri('delete', ['serviceId' => (string) ($r['id'] ?? '')] + $filterArg);
            // For library-sourced rows the bundled JSON carries i18n
            // overlays + vendor* fields the DB doesn't store; merge
            // them in (DB row wins on conflict).
            $id = (string) ($r['id'] ?? '');
            $libraryEntry = $libraryIndex[$id] ?? null;
            $merged = $libraryEntry !== null ? ($libraryEntry + $r) : $r;
            $merged['resolvedDescription'] = $this->resolveLocalizedDescription($merged, $lang);
            $r['resolvedDescription'] = $merged['resolvedDescription'];
            $r['infoModalPayload'] = json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
            return $r;
        }, $paginated);

        $pageArg = $filterArg + ['perPage' => $perPage];
        $moduleTemplate = $this->initModuleTemplate();
        // Unified per-site draft: the service registry is global, but the
        // draft lifecycle is presented per site (the umbrella spans the
        // global service scope + the active site). Service writes below
        // still target SCOPE_GLOBAL — only the banner/lifecycle is scoped
        // to the active site so all tabs agree on one draft.
        $activeSite = $this->activeSiteResolver->resolve();
        $bannerVars = $this->bannerContext->forSite($activeSite, $this->request);
        $moduleTemplate->assignMultiple($bannerVars + $this->wizardBannerContext->forAnyPendingSite() + [
            'draftScope' => $activeSite,
            'services' => $rows,
            'source' => $source,
            'purpose' => $purpose,
            'search' => $search,
            'sourceOptions' => self::SOURCE_OPTIONS,
            'purposeOptions' => $this->availablePurposes($decorated),
            'customCount' => $custom,
            'libraryCount' => $library,
            'orphanedCount' => $orphaned,
            'totalCount' => count($decorated),
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
            // Pre-built URL for the orphan callout's "Show only orphans"
            // shortcut. Building it here (via Extbase's URI builder) and
            // not via string-concat in the template avoids the double-`?`
            // bug — BE module URIs always carry a `?token=…` security
            // token, and `{uri}?source=…` ends up with two question marks.
            'uri_orphansFilter' => $this->uri('list', ['source' => 'orphaned']),
            'filtersActive' => $filterArg !== [],
            'uri_libraryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.LibraryBrowser_list',
            ),
            'uri_detectionsTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.DetectionReview_list',
            ),
            'uri_registryTab' => $this->uri('list'),
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
            'uri_designerTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.ThemeDesigner_index',
            ),
        ]);
        return $moduleTemplate->renderResponse('RegistryList/List');
    }

    /**
     * Delete a service row. Refuses (silently redirects) if the row is
     * Aus-Bibliothek — those go through the Bibliothek tab's Unadopt
     * action so the dismiss-from-library symmetry holds. Eigene and
     * Verwaist rows are deletable from here.
     */
    public function deleteAction(
        string $serviceId,
        string $source = self::DEFAULT_SOURCE,
        string $purpose = '',
        string $search = '',
    ): ResponseInterface {
        $filterArg = $this->filterArg($source, $purpose, $search);
        $existing = $this->serviceRepository->findOne($serviceId);
        if ($existing === null) {
            return $this->redirect('list', null, null, $filterArg);
        }
        // Re-derive source server-side — never trust the source the
        // form posted. A library row deleted from here would bypass
        // the "unadopt via Bibliothek" rule.
        $libraryIds = RegistryListPresenter::libraryIdSet();
        $rawRow = $existing + [
            '_libraryAdoptedAt' => $this->fetchLibraryAdoptedAt($serviceId),
        ];
        $derived = RegistryListPresenter::deriveSource($rawRow, $libraryIds);
        if ($derived === RegistryListPresenter::SOURCE_LIBRARY) {
            return $this->redirect('list', null, null, $filterArg);
        }
        try {
            $beUserId = $this->ensureGlobalDraft();
        } catch (\RuntimeException $e) {
            $this->addFlashMessage($e->getMessage(), '', \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR);
            return $this->redirect('list', null, null, $filterArg);
        }
        $this->serviceRepository->deleteDraft(
            \SimpleCMP\T3SimpleCmp\Service\LockState::SCOPE_GLOBAL,
            $serviceId,
        );
        $this->draftWorkspace->touchLock(\SimpleCMP\T3SimpleCmp\Service\LockState::SCOPE_GLOBAL);
        return $this->redirect('list', null, null, $filterArg);
    }

    // ---------------------------------------------------------------------

    private function fetchLibraryAdoptedAt(string $serviceId): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_t3simplecmp_service');
        $qb->getRestrictions()->removeAll();
        $value = $qb->select('library_adopted_at')
            ->from('tx_t3simplecmp_service')
            ->where($qb->expr()->eq('service_id', $qb->createNamedParameter($serviceId)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return $value === false ? 0 : (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matchesSearch(array $row, string $needle): bool
    {
        $haystack = strtolower(implode(' ', [
            (string) ($row['id'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['vendor'] ?? ''),
            json_encode($row['matches']['cookies'] ?? [], JSON_UNESCAPED_SLASHES) ?: '',
            json_encode($row['matches']['origins'] ?? [], JSON_UNESCAPED_SLASHES) ?: '',
        ]));
        return str_contains($haystack, strtolower($needle));
    }

    /**
     * Union of purposes seen across all registry rows — drives the
     * Zweck filter dropdown. Avoids a hardcoded list; if an admin
     * curates a service with a novel purpose it shows up here too.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function availablePurposes(array $rows): array
    {
        $seen = [];
        foreach ($rows as $r) {
            foreach ($r['purposes'] ?? [] as $p) {
                if (is_string($p) && $p !== '') {
                    $seen[$p] = true;
                }
            }
        }
        $out = array_keys($seen);
        sort($out);
        return $out;
    }

    /**
     * @return array<string, scalar>
     */
    private function filterArg(string $source, string $purpose, string $search): array
    {
        $args = [];
        if ($source !== self::DEFAULT_SOURCE) {
            $args['source'] = $source;
        }
        if ($purpose !== '') {
            $args['purpose'] = $purpose;
        }
        if ($search !== '') {
            $args['search'] = $search;
        }
        return $args;
    }

    private function editServiceUri(int $uid): ?string
    {
        if ($uid <= 0) {
            return null;
        }
        $returnUrl = $this->uri('list');
        return (string) $this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['tx_t3simplecmp_service' => [$uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexBundledLibraryById(): array
    {
        $index = [];
        foreach (ServicesLibrary::services() as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $index[$id] = $entry;
        }
        return $index;
    }

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

    private function initModuleTemplate(): ModuleTemplate
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('SimpleCMP');
        $this->pageRenderer->loadJavaScriptModule('@simplecmp/t3-simplecmp/Backend/Pagination.js');
        $this->pageRenderer->loadJavaScriptModule('@simplecmp/t3-simplecmp/Backend/ServiceInfoModal.js');
        return $moduleTemplate;
    }
}
