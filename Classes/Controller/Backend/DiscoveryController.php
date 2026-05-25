<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use SimpleCMP\T3SimpleCmp\Service\BridgeSecretProvider;
use SimpleCMP\T3SimpleCmp\Service\SitemapFetcher;

/**
 * BE module action *Discover trackers* — sitemap-driven sweep that runs
 * inside the admin's own browser via a hidden iframe.
 *
 * The admin clicks *Discover trackers* from the Detektionen list. We
 * pick the site (auto-select for single-site installs, dropdown
 * otherwise), fetch its sitemap server-side, present the URL list, and
 * let a JS module walk each URL in a hidden iframe with
 * `?simplecmp_discover=1` appended. The FE recorder + bridge inside the
 * iframe POSTs to its configured webhook exactly as for a real visitor,
 * and the existing detection-ingest pipeline does the rest. No new
 * server-side ingest path.
 *
 * Two actions:
 * - `index(site)` — render the picker + URL preview + walker UI
 * - `fetchSitemap(site)` — JSON endpoint for client-side re-fetch when
 *   the admin changes the site picker without a full page reload
 *
 * Refusing to render is the right behaviour when the bridge isn't
 * configured — there's nowhere for the iframe POSTs to land. We surface
 * a friendly callout pointing at the existing "generate bridge secret"
 * flow.
 */
final class DiscoveryController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly SiteFinder $siteFinder,
        private readonly SitemapFetcher $sitemapFetcher,
        private readonly BridgeSecretProvider $bridgeSecretProvider,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {
    }

    public function indexAction(string $site = '', string $sitemapUrl = ''): ResponseInterface
    {
        $availableSites = $this->availableSites();
        $site = $this->normalizeSite($site, $availableSites);
        $siteObject = $this->resolveSite($site);
        $baseUrl = $siteObject !== null ? (string) $siteObject->getBase() : '';
        if ($sitemapUrl === '') {
            [$sitemapUrl, $urls] = $this->autoDetectSitemap($siteObject, $baseUrl);
        } else {
            $urls = $this->sitemapFetcher->fetch($sitemapUrl);
        }

        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
            'site' => $site,
            'availableSites' => $availableSites,
            'siteBaseUrl' => $baseUrl,
            'sitemapUrl' => $sitemapUrl,
            'urls' => $urls,
            'secretMissing' => !$this->bridgeSecretProvider->isConfigured(),
            'uri_index' => $this->uri('index'),
            'uri_fetchSitemap' => $this->uri('fetchSitemap'),
            'uri_back' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Backend\\DetectionReview_list',
            ),
            'uri_detectionsTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Backend\\DetectionReview_list',
            ),
            'uri_registryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Backend\\RegistryList_list',
            ),
            'uri_libraryTab' => (string) $this->backendUriBuilder->buildUriFromRoute(
                'simplecmp_detections.Backend\\LibraryBrowser_list',
            ),
        ]);
        return $moduleTemplate->renderResponse('Discovery/Index');
    }

    /**
     * JSON endpoint used by the JS picker when the admin chooses a
     * different site without reloading the page. Returns the new
     * site's sitemap URL + URL list so the JS can repaint the preview.
     */
    public function fetchSitemapAction(string $site = '', string $sitemapUrl = ''): ResponseInterface
    {
        $availableSites = $this->availableSites();
        $site = $this->normalizeSite($site, $availableSites);
        $siteObject = $this->resolveSite($site);
        $baseUrl = $siteObject !== null ? (string) $siteObject->getBase() : '';
        if ($sitemapUrl === '') {
            [$sitemapUrl, $urls] = $this->autoDetectSitemap($siteObject, $baseUrl);
        } else {
            $urls = $this->sitemapFetcher->fetch($sitemapUrl);
        }

        $response = $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode([
            'site' => $site,
            'baseUrl' => $baseUrl,
            'sitemapUrl' => $sitemapUrl,
            'urls' => $urls,
        ], JSON_THROW_ON_ERROR));
        return $response;
    }

    /**
     * Try a small list of candidate sitemap URLs and return the first
     * one that yields any URLs, along with that URL.
     *
     * Real-world TYPO3 installs rarely serve their sitemap at the bare
     * `<base>/sitemap.xml` — EXT:seo serves per-rootline, and language-
     * prefixed sites (default base `/de/`, `/en/`, etc.) need the
     * language prefix in the URL. We try root → each language base in
     * order, settling for whichever returns URLs.
     *
     * Returns the original `<base>/sitemap.xml` (with empty URL list)
     * if nothing worked — the admin then sees what we tried and can
     * type the right URL into the Refetch input.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function autoDetectSitemap(?Site $site, string $baseUrl): array
    {
        if ($baseUrl === '') {
            return ['', []];
        }
        $rootUrl = $this->sitemapFetcher->defaultSitemapUrl($baseUrl);

        $candidates = [$rootUrl];
        if ($site !== null) {
            foreach ($site->getLanguages() as $language) {
                // SiteLanguage::getBase() returns the full resolved URI
                // (scheme + host + language path), not just the path. We
                // only want the path segment so we can re-anchor it on
                // the site's base.
                $langPath = trim($language->getBase()->getPath(), '/');
                if ($langPath === '') {
                    continue;
                }
                $candidates[] = rtrim($baseUrl, '/') . '/' . $langPath . '/sitemap.xml';
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            $urls = $this->sitemapFetcher->fetch($candidate);
            if ($urls !== []) {
                return [$candidate, $urls];
            }
        }
        return [$rootUrl, []];
    }

    /**
     * Sites that have `simplecmp/t3-simplecmp` in their resolved Site
     * Set dependencies. Same selector as the Banner Designer module —
     * crawling a site that doesn't run SimpleCMP would produce no
     * detections.
     *
     * @return list<string>
     */
    private function availableSites(): array
    {
        $ids = [];
        foreach ($this->siteFinder->getAllSites() as $identifier => $site) {
            if (in_array('simplecmp/t3-simplecmp', $site->getSets(), true)) {
                $ids[] = $identifier;
            }
        }
        sort($ids);
        return $ids;
    }

    /**
     * @param list<string> $available
     */
    private function normalizeSite(string $site, array $available): string
    {
        if ($site !== '' && in_array($site, $available, true)) {
            return $site;
        }
        return $available[0] ?? 'default';
    }

    private function resolveSite(string $identifier): ?Site
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($identifier);
        } catch (\Throwable) {
            return null;
        }
        return $site instanceof Site ? $site : null;
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
            '@simplecmp/t3-simplecmp/Backend/Discovery.js'
        );
        return $moduleTemplate;
    }
}
