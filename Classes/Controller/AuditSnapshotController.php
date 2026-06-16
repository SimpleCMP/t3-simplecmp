<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller;

use Psr\Http\Message\ResponseInterface;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ConfigSnapshotRepository;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Audit-tab in the SimpleCMP detections BE module — Phase 1.
 *
 * Lists historical snapshots of the resolved banner configuration per
 * site (table `tx_t3simplecmp_config_snapshot`). Each snapshot is the
 * full canonical JSON of services + theme + translation overrides +
 * selected Site Settings at the moment the editor saved a change.
 *
 * Read-only by design — the BE never offers an edit/delete affordance.
 * The list view supports pagination + per-site filtering; the show
 * action displays the snapshot JSON plus a line-diff against the
 * immediately preceding snapshot for the same site.
 *
 * Follows the same controller layout as the other 4 tabs in this
 * module (site-picker via pre-built URIs, ModuleTemplate from the
 * factory, ActionController inheritance).
 */
final class AuditSnapshotController extends ActionController
{
    private const string SET_IDENTIFIER = 'simplecmp/t3-simplecmp';
    private const array PER_PAGE_OPTIONS = [25, 50, 100, 200];
    private const int DEFAULT_PER_PAGE = 25;

    protected ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SiteFinder $siteFinder,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly ConfigSnapshotRepository $repository,
    ) {
    }

    public function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setTitle($this->translate('module.audit.title'));
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
    }

    public function listAction(?string $site = null, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): ResponseInterface
    {
        $sites = $this->collectSites();
        if ($sites === []) {
            $this->moduleTemplate->assign('hasSites', false);
            $this->assignTabUris($this->moduleTemplate);
            return $this->moduleTemplate->renderResponse('AuditSnapshot/List');
        }

        $selected = $this->resolveSelectedSite($site, $sites);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
        $page = max(1, $page);
        $totalCount = $this->repository->countBySite($selected);
        $totalPages = max(1, (int) ceil($totalCount / $perPage));
        $page = min($page, $totalPages);

        $rows = $this->repository->findBySite($selected, $perPage, ($page - 1) * $perPage);
        $rows = array_map($this->decorateListRow(...), $rows);

        $siteOptions = $this->siteOptions($sites, $perPage);

        $this->moduleTemplate->assignMultiple([
            'hasSites' => true,
            'siteOptions' => $siteOptions,
            'selectedSite' => $selected,
            'rows' => $rows,
            'totalCount' => $totalCount,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'rangeStart' => $totalCount === 0 ? 0 : ($page - 1) * $perPage + 1,
            'rangeEnd' => min($page * $perPage, $totalCount),
            'uri_pageFirst' => $this->pageUri($selected, 1, $perPage),
            'uri_pagePrev' => $this->pageUri($selected, max(1, $page - 1), $perPage),
            'uri_pageNext' => $this->pageUri($selected, min($totalPages, $page + 1), $perPage),
            'uri_pageLast' => $this->pageUri($selected, $totalPages, $perPage),
        ]);
        $this->assignTabUris($this->moduleTemplate);
        return $this->moduleTemplate->renderResponse('AuditSnapshot/List');
    }

    public function showAction(int $uid): ResponseInterface
    {
        $row = $this->repository->findOne($uid);
        if ($row === null) {
            $this->addFlashMessage($this->translate('module.audit.notFound'), '', \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR);
            return $this->redirect('list');
        }

        $previous = $this->repository->findPrevious((string) $row['site'], (int) $row['uid']);
        $diff = $previous === null
            ? null
            : $this->lineDiff((string) ($previous['canonical_json'] ?? ''), (string) ($row['canonical_json'] ?? ''));

        // Pretty-print the canonical JSON for the BE detail view —
        // the stored canonical_json is intentionally compact (single
        // line, no whitespace) because it's the hash input. For human
        // reading we re-format.
        $prettyCurrent = $this->prettyPrint((string) ($row['canonical_json'] ?? ''));
        $prettyPrevious = $previous === null
            ? null
            : $this->prettyPrint((string) ($previous['canonical_json'] ?? ''));

        $this->moduleTemplate->assignMultiple([
            'snapshot' => $row,
            'snapshotPretty' => $prettyCurrent,
            'previous' => $previous,
            'previousPretty' => $prettyPrevious,
            'diff' => $diff,
            'uri_back' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.AuditSnapshot_list',
                ['site' => $row['site']],
            ),
        ]);
        $this->assignTabUris($this->moduleTemplate);
        return $this->moduleTemplate->renderResponse('AuditSnapshot/Show');
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorateListRow(array $row): array
    {
        $row['version_hash_short'] = substr((string) $row['version_hash'], 0, 12);
        $row['uri_show'] = (string) $this->backendUriBuilder->buildUriFromRoute(
            'simplecmp_detections.AuditSnapshot_show',
            ['uid' => (int) $row['uid']],
        );
        return $row;
    }

    /**
     * @param list<array{identifier: string, label: string}> $sites
     * @return list<array{identifier: string, label: string, url: string}>
     */
    private function siteOptions(array $sites, int $perPage): array
    {
        $out = [];
        foreach ($sites as $entry) {
            $out[] = [
                'identifier' => $entry['identifier'],
                'label' => $entry['label'],
                'url' => $this->pageUri($entry['identifier'], 1, $perPage),
            ];
        }
        return $out;
    }

    private function pageUri(string $site, int $page, int $perPage): string
    {
        return (string) $this->backendUriBuilder->buildUriFromRoute(
            'simplecmp_detections.AuditSnapshot_list',
            ['site' => $site, 'page' => $page, 'perPage' => $perPage],
        );
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

    /**
     * @param list<array{identifier: string, label: string}> $sites
     */
    private function resolveSelectedSite(?string $requested, array $sites): string
    {
        $identifiers = array_column($sites, 'identifier');
        if ($requested !== null && in_array($requested, $identifiers, true)) {
            return $requested;
        }
        return $identifiers[0] ?? '';
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
        ]);
    }

    /**
     * Pretty-print canonical JSON for human-readable BE display.
     * Falls back to the raw string if decode fails (which would
     * indicate a stored row produced by an older encoder version).
     */
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

    /**
     * Naive line-by-line diff suitable for audit reading. Produces a
     * list of `{type: 'context'|'add'|'remove', line: string}` entries
     * by aligning the longest-common-subsequence of pretty-printed
     * lines. Phase-1 simplicity: a wider diff library (wikidiff2,
     * jfcherng/php-diff) is a Phase-3 polish.
     *
     * @return list<array{type: string, line: string}>
     */
    private function lineDiff(string $before, string $after): array
    {
        $beforeLines = explode("\n", $this->prettyPrint($before));
        $afterLines = explode("\n", $this->prettyPrint($after));
        $lcs = $this->longestCommonSubsequence($beforeLines, $afterLines);

        $i = 0;
        $j = 0;
        $out = [];
        foreach ($lcs as $common) {
            while ($i < count($beforeLines) && $beforeLines[$i] !== $common) {
                $out[] = ['type' => 'remove', 'line' => $beforeLines[$i++]];
            }
            while ($j < count($afterLines) && $afterLines[$j] !== $common) {
                $out[] = ['type' => 'add', 'line' => $afterLines[$j++]];
            }
            $out[] = ['type' => 'context', 'line' => $common];
            $i++;
            $j++;
        }
        while ($i < count($beforeLines)) {
            $out[] = ['type' => 'remove', 'line' => $beforeLines[$i++]];
        }
        while ($j < count($afterLines)) {
            $out[] = ['type' => 'add', 'line' => $afterLines[$j++]];
        }
        return $out;
    }

    /**
     * Standard LCS algorithm. Worst case O(n*m); acceptable for the
     * pretty-printed JSON sizes we deal with (tens to a few hundred
     * lines).
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private function longestCommonSubsequence(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $dp[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }
        $i = $n;
        $j = $m;
        $out = [];
        while ($i > 0 && $j > 0) {
            if ($a[$i - 1] === $b[$j - 1]) {
                array_unshift($out, $a[$i - 1]);
                $i--;
                $j--;
            } elseif ($dp[$i - 1][$j] >= $dp[$i][$j - 1]) {
                $i--;
            } else {
                $j--;
            }
        }
        return $out;
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
