<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Fetches and parses a TYPO3 sitemap into a flat list of FE URLs the
 * BE discovery sweep can hit.
 *
 * Two shapes are supported by the sitemaps protocol:
 *
 * - **Sitemap** (`<urlset><url><loc>...</loc></url>...</urlset>`) —
 *   leaves; the `<loc>` values are the URLs to visit.
 * - **Sitemap index** (`<sitemapindex><sitemap><loc>...</loc>...`) —
 *   intermediate; the `<loc>` values point at further sitemaps that
 *   must be fetched and recursed into.
 *
 * The recursion is depth-bounded to prevent runaway loops in
 * misconfigured sitemap-index trees. Results are de-duplicated by
 * absolute URL.
 *
 * Network errors (timeouts, non-2xx responses, malformed XML) are
 * logged and skipped — the discovery sweep is best-effort by design,
 * and a single broken sub-sitemap shouldn't abort the entire crawl.
 */
final readonly class SitemapFetcher
{
    private const int DEFAULT_TIMEOUT_SECONDS = 10;
    private const int MAX_INDEX_DEPTH = 3;
    private const int MAX_URLS = 5000;

    public function __construct(
        private RequestFactory $requestFactory,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Fetch a sitemap URL and return a flat list of FE URLs.
     *
     * @return list<string>
     */
    public function fetch(string $sitemapUrl): array
    {
        $urls = $this->fetchInternal($sitemapUrl, 0);
        // Preserve first-seen order while de-duplicating; admins reading
        // the URL preview in the BE expect roughly the sitemap's own
        // ordering, not an alphabetical shuffle.
        $seen = [];
        $unique = [];
        foreach ($urls as $url) {
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $unique[] = $url;
            if (count($unique) >= self::MAX_URLS) {
                break;
            }
        }
        return $unique;
    }

    /**
     * Build the default sitemap URL for a TYPO3 site.
     *
     * EXT:seo serves the sitemap at `?type=1533906435` by default;
     * `/sitemap.xml` is the conventional alias most sites enable via
     * a route enhancer. We default to `/sitemap.xml` because (a) it's
     * the public-facing convention and (b) it lets admins point a
     * custom CDN-cached sitemap at the discovery flow without us
     * having to know about TYPO3 internals.
     */
    public function defaultSitemapUrl(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/sitemap.xml';
    }

    /**
     * @return list<string>
     */
    private function fetchInternal(string $sitemapUrl, int $depth): array
    {
        if ($depth > self::MAX_INDEX_DEPTH) {
            $this->logger->warning(
                'SimpleCMP discovery: sitemap-index recursion exceeded max depth, stopping descent',
                ['url' => $sitemapUrl, 'depth' => $depth],
            );
            return [];
        }

        $xml = $this->fetchXml($sitemapUrl);
        if ($xml === null) {
            return [];
        }

        // Sitemap-index — each <sitemap><loc> points at a child sitemap.
        if ($xml->getName() === 'sitemapindex') {
            $urls = [];
            foreach ($xml->children() as $child) {
                if ($child->getName() !== 'sitemap') {
                    continue;
                }
                $loc = trim((string) $child->loc);
                if ($loc === '') {
                    continue;
                }
                foreach ($this->fetchInternal($loc, $depth + 1) as $url) {
                    $urls[] = $url;
                }
            }
            return $urls;
        }

        // Plain urlset — each <url><loc> is a leaf URL.
        $urls = [];
        foreach ($xml->children() as $child) {
            if ($child->getName() !== 'url') {
                continue;
            }
            $loc = trim((string) $child->loc);
            if ($loc !== '') {
                $urls[] = $loc;
            }
        }
        return $urls;
    }

    private function fetchXml(string $url): ?\SimpleXMLElement
    {
        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'application/xml,text/xml'],
                // Self-signed certs are common on local dev (ddev).
                // The discovery flow runs against the admin's own
                // site, never against arbitrary third parties.
                'verify' => false,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'SimpleCMP discovery: sitemap fetch failed',
                ['url' => $url, 'error' => $e->getMessage()],
            );
            return null;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning(
                'SimpleCMP discovery: sitemap returned non-2xx',
                ['url' => $url, 'status' => $status],
            );
            return null;
        }

        $body = (string) $response->getBody();
        return $this->parseXml($body, $url);
    }

    /**
     * Public so tests can drive the parser directly without HTTP.
     */
    public function parseXml(string $body, string $sourceUrl = ''): ?\SimpleXMLElement
    {
        if ($body === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body);
            if ($xml === false) {
                $errors = array_map(
                    static fn (\LibXMLError $e): string => trim($e->message),
                    libxml_get_errors(),
                );
                libxml_clear_errors();
                $this->logger->warning(
                    'SimpleCMP discovery: sitemap XML parse failed',
                    ['url' => $sourceUrl, 'errors' => $errors],
                );
                return null;
            }
            return $xml;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
