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
     * SSRF guard: every URL this fetches server-side (the top-level
     * sitemap AND any sitemap-index `<loc>` it recurses into) must have
     * an http/https scheme and a host in `$allowedHosts` — otherwise it
     * is refused and logged. `$allowedHosts` is the selected Site's own
     * base + language hosts (see `DiscoveryController::siteHosts()`).
     * An empty allowlist refuses everything (fail-closed): with no known
     * site there is nothing legitimate to fetch. The leaf `<url><loc>`
     * URLs returned here are NOT fetched server-side — they are visited
     * later in the admin's own browser — so they are not constrained.
     *
     * @param list<string> $allowedHosts hostnames the fetch may target
     * @return list<string>
     */
    public function fetch(string $sitemapUrl, array $allowedHosts): array
    {
        $allowedHosts = $this->normalizeHosts($allowedHosts);
        $urls = $this->fetchInternal($sitemapUrl, 0, $allowedHosts);
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
     * SSRF gate: true only for an http/https URL whose host is in the
     * allowlist. Rejects other schemes (file://, gopher://, and
     * protocol-relative `//host/…`), missing/empty hosts, embedded
     * credentials (`user:pass@host` — a classic host-parsing-confusion
     * vector), and any host not belonging to the selected site.
     *
     * Public so the policy can be unit-tested directly.
     *
     * @param list<string> $allowedHosts hostnames (any case)
     */
    public function isFetchableUrl(string $url, array $allowedHosts): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        if ($host === '') {
            return false;
        }
        return in_array($host, $this->normalizeHosts($allowedHosts), true);
    }

    /**
     * Lowercase, trim, drop empties, de-duplicate.
     *
     * @param list<string> $hosts
     * @return list<string>
     */
    private function normalizeHosts(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $h) {
            $h = strtolower(trim($h));
            if ($h !== '' && !in_array($h, $out, true)) {
                $out[] = $h;
            }
        }
        return $out;
    }

    /**
     * Read the site's own robots.txt and return the sitemap URLs it
     * declares via `Sitemap:` directives.
     *
     * This lets a site legitimately host its sitemap on another host
     * (e.g. a CDN) without an admin allowlist field: the trust is
     * anchored in a file the site itself serves. The robots.txt is
     * fetched only from a `$siteHosts` host (gated like any other
     * fetch), and the declared URLs are filtered to public http/https
     * URLs on non-IP-literal hosts — so even a tampered robots.txt can't
     * bless an internal address (169.254.x, 10.x, ::1, …) without DNS.
     *
     * Callers add the returned URLs' hosts to the allowlist they pass to
     * {@see fetch()}, and may use the URLs themselves as priority
     * sitemap candidates.
     *
     * @param list<string> $siteHosts the site's own base/language hosts
     * @return list<string> declared sitemap URLs (deduped)
     */
    public function robotsSitemapUrls(string $baseUrl, array $siteHosts): array
    {
        $robotsUrl = $this->robotsUrl($baseUrl);
        if ($robotsUrl === null) {
            return [];
        }
        $body = $this->fetchBody($robotsUrl, $this->normalizeHosts($siteHosts), 'text/plain');
        if ($body === null) {
            return [];
        }
        return $this->parseRobots($body);
    }

    /**
     * Extract `Sitemap:` URLs from robots.txt content, filtered to
     * public http/https URLs (non-IP-literal host, no credentials).
     * Public so the parse + filter policy is unit-testable without HTTP.
     *
     * @return list<string>
     */
    public function parseRobots(string $body): array
    {
        $out = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (!preg_match('/^\s*Sitemap\s*:\s*(\S+)/i', $line, $m)) {
                continue;
            }
            $url = $m[1];
            if ($this->isPublicHttpUrl($url) && !in_array($url, $out, true)) {
                $out[] = $url;
            }
        }
        return $out;
    }

    /**
     * Build the origin-root robots.txt URL (`scheme://host[:port]/robots.txt`)
     * for a site base, ignoring any path. Null if the base isn't a
     * usable http/https URL.
     */
    private function robotsUrl(string $baseUrl): ?string
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $host . $port . '/robots.txt';
    }

    /**
     * A robots-declared sitemap URL is acceptable only if it's http/https
     * with a real hostname host. Bare IP literals are refused — CDNs use
     * names, and refusing IPs keeps a tampered robots.txt from blessing
     * an internal address without us having to resolve DNS.
     */
    private function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return false;
        }
        // Strip IPv6 brackets before the IP check.
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false;
    }

    /**
     * @param list<string> $allowedHosts
     * @return list<string>
     */
    private function fetchInternal(string $sitemapUrl, int $depth, array $allowedHosts): array
    {
        if ($depth > self::MAX_INDEX_DEPTH) {
            $this->logger->warning(
                'SimpleCMP discovery: sitemap-index recursion exceeded max depth, stopping descent',
                ['url' => $sitemapUrl, 'depth' => $depth],
            );
            return [];
        }

        $xml = $this->fetchXml($sitemapUrl, $allowedHosts);
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
                foreach ($this->fetchInternal($loc, $depth + 1, $allowedHosts) as $url) {
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

    /**
     * @param list<string> $allowedHosts
     */
    private function fetchXml(string $url, array $allowedHosts): ?\SimpleXMLElement
    {
        $body = $this->fetchBody($url, $allowedHosts, 'application/xml,text/xml');
        return $body === null ? null : $this->parseXml($body, $url);
    }

    /**
     * SSRF-guarded HTTP GET returning the 2xx response body, or null on
     * refusal / transport error / non-2xx. Shared by the sitemap and
     * robots.txt fetches.
     *
     * The host allowlist is the primary SSRF control: refuse anything
     * that isn't an http/https URL on a host belonging to the selected
     * site. This covers the top-level sitemap, recursed sitemap-index
     * `<loc>`s (which come from fetched XML and could otherwise point at
     * internal hosts), and the robots.txt probe.
     *
     * @param list<string> $allowedHosts
     */
    private function fetchBody(string $url, array $allowedHosts, string $accept): ?string
    {
        if (!$this->isFetchableUrl($url, $allowedHosts)) {
            $this->logger->warning(
                'SimpleCMP discovery: refusing to fetch URL outside the site host allowlist (possible SSRF)',
                ['url' => $url, 'allowedHosts' => $allowedHosts],
            );
            return null;
        }

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
                'headers' => ['Accept' => $accept],
                // Constrain redirects to http/https so a redirect can't
                // downgrade to file:// etc. (defense in depth on top of
                // the host allowlist above).
                'allow_redirects' => ['max' => 5, 'strict' => true, 'protocols' => ['http', 'https']],
                // Self-signed certs are common on local dev (ddev). Safe
                // to leave off here ONLY because the host allowlist
                // already restricts fetches to the site's own declared
                // hosts — no arbitrary third-party targets to MITM.
                'verify' => false,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'SimpleCMP discovery: fetch failed',
                ['url' => $url, 'error' => $e->getMessage()],
            );
            return null;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning(
                'SimpleCMP discovery: fetch returned non-2xx',
                ['url' => $url, 'status' => $status],
            );
            return null;
        }

        return (string) $response->getBody();
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
