<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use WapplerSystems\SimpleCmpTypo3\Service\BridgeSecretProvider;
use WapplerSystems\SimpleCmpTypo3\Service\SitemapFetcher;

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

    public function indexAction(string $site = ''): ResponseInterface
    {
        $availableSites = $this->availableSites();
        $site = $this->normalizeSite($site, $availableSites);
        $siteObject = $this->resolveSite($site);
        $baseUrl = $siteObject !== null ? (string) $siteObject->getBase() : '';
        $sitemapUrl = $baseUrl !== '' ? $this->sitemapFetcher->defaultSitemapUrl($baseUrl) : '';
        $urls = $sitemapUrl !== '' ? $this->sitemapFetcher->fetch($sitemapUrl) : [];

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
    public function fetchSitemapAction(string $site = ''): ResponseInterface
    {
        $availableSites = $this->availableSites();
        $site = $this->normalizeSite($site, $availableSites);
        $siteObject = $this->resolveSite($site);
        $baseUrl = $siteObject !== null ? (string) $siteObject->getBase() : '';
        $sitemapUrl = $baseUrl !== '' ? $this->sitemapFetcher->defaultSitemapUrl($baseUrl) : '';
        $urls = $sitemapUrl !== '' ? $this->sitemapFetcher->fetch($sitemapUrl) : [];

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
     * Sites that have `wapplersystems/simplecmp` in their resolved Site
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
            if (in_array('wapplersystems/simplecmp', $site->getSets(), true)) {
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
            '@wapplersystems/simplecmp-typo3/Backend/Discovery.js'
        );
        return $moduleTemplate;
    }
}
