<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use SimpleCMP\ServicesLibrary\ServicesLibrary;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
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
    private const array STATUS_OPTIONS = ['available', 'adopted', 'all'];
    private const string DEFAULT_STATUS = 'available';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly UriBuilder $backendUriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly ServiceRepository $serviceRepository,
        private readonly StoragePidResolver $storagePidResolver,
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

        $filtered = array_values(array_filter($allEntries, function (array $entry) use ($status, $search): bool {
            if ($status === 'available' && $entry['adopted']) {
                return false;
            }
            if ($status === 'adopted' && !$entry['adopted']) {
                return false;
            }
            if ($search !== '' && !$this->matchesSearch($entry, $search)) {
                return false;
            }
            return true;
        }));

        $filteredCount = count($filtered);
        $totalPages = max(1, (int) ceil($filteredCount / $perPage));
        $page = min($page, $totalPages);
        $paginated = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        $filterArg = $this->filterArg($status, $search);
        $rows = array_map(
            fn (array $entry): array => $this->decorateRow($entry, $filterArg),
            $paginated,
        );

        $pageArg = $filterArg + ['perPage' => $perPage];
        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
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
                'simplecmp_detections.Backend\\DetectionReview_list',
            ),
            'uri_registryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Backend\\RegistryList_list',
            ),
            'uri_libraryTab' => $this->uri('list'),
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
            $pid = $this->storagePidResolver->resolveDefault();
            // fromLibrary: true → stamps `library_adopted_at` so the
            // Dienste tab can later distinguish Aus-Bibliothek rows
            // from Eigene rows, and surface Verwaist if the bundled
            // library drops this service in a future composer update.
            $this->serviceRepository->upsert($entry, $pid, true);
        }
        return $this->redirect('list', null, null, $this->filterArg($status, $search));
    }

    public function unadoptAction(
        string $serviceId,
        string $status = self::DEFAULT_STATUS,
        string $search = '',
    ): ResponseInterface {
        $this->serviceRepository->delete($serviceId);
        return $this->redirect('list', null, null, $this->filterArg($status, $search));
    }

    // ---------------------------------------------------------------------

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
        $entries = [];
        foreach (ServicesLibrary::services() as $entry) {
            if (!isset($entry['id'])) {
                continue;
            }
            $id = (string) $entry['id'];
            $entry['adopted'] = isset($adoptedIds[$id]);
            $entries[] = $entry;
        }
        return $entries;
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
     * @return array<string, mixed>
     */
    private function decorateRow(array $entry, array $filterArg): array
    {
        $id = (string) $entry['id'];
        $rowArgs = ['serviceId' => $id] + $filterArg;
        $entry['uri_adopt'] = $this->uri('adopt', $rowArgs);
        $entry['uri_unadopt'] = $this->uri('unadopt', $rowArgs);
        $entry['uri_edit'] = $entry['adopted'] ? $this->editServiceUri($id) : null;
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
        return $moduleTemplate;
    }
}
