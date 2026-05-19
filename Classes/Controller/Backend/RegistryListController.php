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
use WapplerSystems\SimpleCmpTypo3\Service\RegistryListPresenter;

/**
 * Backend module tab: list every row in `tx_simplecmptypo3_service`
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
    ) {
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
        $rows = array_map(function (array $r) use ($filterArg): array {
            $r['uri_edit'] = $this->editServiceUri((int) ($r['_uid'] ?? 0));
            $r['uri_delete'] = $r['source'] === RegistryListPresenter::SOURCE_LIBRARY
                ? null
                : $this->uri('delete', ['serviceId' => (string) ($r['id'] ?? '')] + $filterArg);
            return $r;
        }, $paginated);

        $pageArg = $filterArg + ['perPage' => $perPage];
        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
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
            'filtersActive' => $filterArg !== [],
            'uri_libraryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Backend\\LibraryBrowser_list',
            ),
            'uri_detectionsTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Backend\\DetectionReview_list',
            ),
            'uri_registryTab' => $this->uri('list'),
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
        $this->serviceRepository->delete($serviceId);
        return $this->redirect('list', null, null, $filterArg);
    }

    // ---------------------------------------------------------------------

    private function fetchLibraryAdoptedAt(string $serviceId): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_simplecmptypo3_service');
        $qb->getRestrictions()->removeAll();
        $value = $qb->select('library_adopted_at')
            ->from('tx_simplecmptypo3_service')
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
            'edit' => ['tx_simplecmptypo3_service' => [$uid => 'edit']],
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

    private function initModuleTemplate(): ModuleTemplate
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('SimpleCMP');
        $this->pageRenderer->loadJavaScriptModule('@wapplersystems/simplecmp-typo3/Backend/Pagination.js');
        return $moduleTemplate;
    }
}
