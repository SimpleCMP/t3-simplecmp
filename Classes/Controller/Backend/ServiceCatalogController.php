<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;

/**
 * Backend module: browse the service registry and promote / hide entries
 * from the FE banner. Shares the `simplecmp_detections` module slot with
 * `DetectionReviewController`; rendered as the "Services" tab next to
 * the "Detections" tab.
 *
 * The catalog distinguishes two states per service:
 *
 * - **Visible** (`fe_visible = 1`) — appears in the visitor's consent
 *   banner. Promoted via the Übernehmen flow on a detection, an
 *   Anpassen / Kuratieren save (TCA form default), or a one-click
 *   action here.
 * - **Hidden** (`fe_visible = 0`) — in the registry for classifier
 *   purposes (Service-DB middleware + LocalClassifier server-side) but
 *   not exposed on the banner. Library imports
 *   (`simplecmp:import-known-trackers`) default to this state so the
 *   classifier benefits without bloating the FE init payload.
 *
 * Filtering is PHP-side after `findAllForCatalog()` because the search
 * term needs to substring-match JSON-encoded matcher columns and the
 * row volume (~hundreds) doesn't warrant SQL gymnastics.
 */
final class ServiceCatalogController extends ActionController
{
    private const array PER_PAGE_OPTIONS = [25, 50, 100, 500];
    private const int DEFAULT_PER_PAGE = 25;
    private const array STATUS_OPTIONS = ['all', 'visible', 'hidden'];
    private const string DEFAULT_STATUS = 'all';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly UriBuilder $backendUriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly ServiceRepository $serviceRepository,
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

        $all = $this->serviceRepository->findAllForCatalog();
        $visibleCount = 0;
        $hiddenCount = 0;
        foreach ($all as $row) {
            if ($row['feVisible']) {
                $visibleCount++;
            } else {
                $hiddenCount++;
            }
        }

        $filtered = array_values(array_filter($all, function (array $row) use ($status, $search): bool {
            if ($status === 'visible' && !$row['feVisible']) {
                return false;
            }
            if ($status === 'hidden' && $row['feVisible']) {
                return false;
            }
            if ($search !== '' && !$this->matchesSearch($row, $search)) {
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
            fn (array $row): array => $this->decorateRow($row, $filterArg),
            $paginated,
        );

        $pageArg = $filterArg + ['perPage' => $perPage];
        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
            'services' => $rows,
            'status' => $status,
            'search' => $search,
            'statusOptions' => self::STATUS_OPTIONS,
            'visibleCount' => $visibleCount,
            'hiddenCount' => $hiddenCount,
            'totalCount' => count($all),
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
            'uri_servicesTab' => $this->uri('list'),
        ]);
        return $moduleTemplate->renderResponse('ServiceCatalog/List');
    }

    public function promoteAction(
        string $serviceId,
        string $status = self::DEFAULT_STATUS,
        string $search = '',
    ): ResponseInterface {
        $this->serviceRepository->setVisibility($serviceId, true);
        return $this->redirectToList($status, $search);
    }

    public function hideAction(
        string $serviceId,
        string $status = self::DEFAULT_STATUS,
        string $search = '',
    ): ResponseInterface {
        $this->serviceRepository->setVisibility($serviceId, false);
        return $this->redirectToList($status, $search);
    }

    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row catalog-shaped row
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
     * @param array<string, mixed> $row
     * @param array<string, scalar> $filterArg
     * @return array<string, mixed>
     */
    private function decorateRow(array $row, array $filterArg): array
    {
        $id = (string) $row['id'];
        $rowArgs = ['serviceId' => $id] + $filterArg;
        $row['uri_promote'] = $this->uri('promote', $rowArgs);
        $row['uri_hide'] = $this->uri('hide', $rowArgs);
        $row['uri_edit'] = $this->editServiceUri($id);
        return $row;
    }

    private function editServiceUri(string $serviceId): ?string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_simplecmptypo3_service');
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')
            ->from('tx_simplecmptypo3_service')
            ->where($qb->expr()->eq('service_id', $qb->createNamedParameter($serviceId)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        if ($uid === false) {
            return null;
        }
        $returnUrl = $this->uri('list');
        return (string) $this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['tx_simplecmptypo3_service' => [(int) $uid => 'edit']],
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

    private function redirectToList(string $status, string $search): ResponseInterface
    {
        return $this->redirect('list', null, null, $this->filterArg($status, $search));
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
            '@wapplersystems/simplecmp-typo3/Backend/Pagination.js'
        );
        return $moduleTemplate;
    }
}
