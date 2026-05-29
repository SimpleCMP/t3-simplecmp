<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SimpleCMP\T3SimpleCmp\Domain\Repository\LibraryCacheRepository;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Server-to-server client for the canonical SimpleCMP services-library
 * endpoint (typically `https://library.simplecmp.eu`, configurable via
 * the `simplecmp.libraryUpstreamUrl` Site Set setting).
 *
 * Layering posture (ADR-0014): visitor IPs NEVER reach the upstream.
 * The plugin's PHP queries upstream from server context only; the
 * detection bridge's FE-driven flow goes through
 * `ServiceDbApi::webhook()` which calls `ClassifierLookup` which
 * (when local tiers miss) reaches this client. The visitor talks
 * only to the plugin's same-origin endpoint.
 *
 * Cache posture: 24h TTL for both positive and negative responses.
 * Negative caching is essential — without it, every visitor's
 * unknown cookie would hit upstream forever. Synchronous refresh
 * on stale read (no background worker). See ADR-0014 + the
 * `services_library_standalone` memory for the rationale.
 *
 * Network resilience: 3-second timeout, silent failure (returns the
 * negative-cache value `[]`), warning logged to TYPO3 log. The
 * upstream is best-effort by design; the bundled library still
 * covers the canonical-known case offline.
 *
 * Bandwidth control: when `$dailyBudget > 0` and today's call count
 * has already reached it, `lookup()` returns null (tier-skip)
 * WITHOUT writing the cache. Budget exhaustion mustn't poison the
 * cache — when the day rolls over the next visitor should retry,
 * not pull a stale "we ran out yesterday" `[]` from a long-lived
 * row. Stats are recorded for every actual upstream call.
 */
final readonly class LibraryUpstreamClient
{
    private const int TIMEOUT_SECONDS = 3;
    private const int CACHE_TTL_SECONDS = 86400; // 24h, positive + negative

    /**
     * Resolved once at construction from the ext-config field
     * `libraryUpstreamSkipWhenInSync`. When true (default), the sync
     * gate in `lookup()` short-circuits upstream calls if the bundled
     * library is provably in sync with upstream. Admins can flip OFF
     * in Settings → Extension Configuration → t3_simplecmp for
     * debugging the upstream wiring.
     */
    private bool $skipWhenInSync;

    public function __construct(
        private RequestFactory $requestFactory,
        private LibraryCacheRepository $cache,
        private LibraryUpstreamStats $stats,
        private BundledLibraryInfo $bundle,
        private LibraryUpstreamHealth $health,
        ExtensionConfiguration $extensionConfiguration,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        // ExtensionConfiguration::get() throws
        // ExtensionConfigurationExtensionNotConfiguredException when an
        // admin hasn't visited Settings → Extension Configuration yet
        // (the ext_conf_template default isn't materialised into
        // TYPO3_CONF_VARS until the form is saved). Default to ON in
        // that case — the optimization is provably safe.
        try {
            $config = $extensionConfiguration->get('t3_simplecmp');
            $configured = is_array($config) ? ($config['libraryUpstreamSkipWhenInSync'] ?? true) : true;
        } catch (\Throwable) {
            $configured = true;
        }
        $this->skipWhenInSync = (bool) $configured;
    }

    /**
     * Query upstream for a single cookie or origin. Returns the
     * `matches` array from the upstream response, OR an empty array
     * if upstream confirmed no match. Returns null when no upstream
     * URL is configured OR the daily budget is exhausted (caller
     * should treat null as tier-skip, not as a no-match answer).
     *
     * Cache is consulted first; only stale/missing entries trigger
     * a network call. Budget enforcement runs only on cache miss —
     * cache hits are free and never count against the budget.
     *
     * @return list<array<string, mixed>>|null
     */
    public function lookup(?string $upstreamUrl, ?string $cookie, ?string $origin, ?int $dailyBudget = null): ?array
    {
        if ($upstreamUrl === null || $upstreamUrl === '') {
            return null;
        }
        if ($cookie === null && $origin === null) {
            return [];
        }
        $queryType = $cookie !== null ? 'cookie' : 'origin';
        $queryValue = (string) ($cookie ?? $origin);

        $now = time();
        $cached = $this->cache->get($queryType, $queryValue, $now);
        if ($cached !== null) {
            return $cached;
        }

        // Sync gate: when the bundled library's dataHash equals the
        // upstream's /v1/health.dataHash (probed by the freshness panel,
        // cached for 30 min), the upstream's SQLite serves the same
        // data the bundled JSON walk in `ClassifierLookup` already saw.
        // Skip the upstream call without writing a negative-cache row
        // — cache budget is finite and a fresh visitor next hour might
        // find a different sync state. Returns null = tier-skip (same
        // semantics as budget-exhausted below).
        //
        // Cache-only probe; if the health cache is cold (fresh deploy,
        // BE never opened) `cachedInSync` returns false and the call
        // proceeds as today.
        if ($this->skipWhenInSync && $this->health->cachedInSync($upstreamUrl, $this->bundle->dataHash())) {
            return null;
        }

        if ($dailyBudget !== null && $dailyBudget > 0 && $this->stats->getTodayCalls($now) >= $dailyBudget) {
            $this->logger->info(
                'SimpleCMP library upstream call skipped (daily budget {budget} reached).',
                [
                    'budget' => $dailyBudget,
                    'queryType' => $queryType,
                    'queryValue' => $queryValue,
                ],
            );
            return null;
        }

        $matches = $this->fetchFromUpstream($upstreamUrl, $queryType, $queryValue, $now);
        $this->cache->put(
            $queryType,
            $queryValue,
            $matches,
            $now,
            $now + self::CACHE_TTL_SECONDS,
        );
        return $matches;
    }

    /**
     * @return list<array<string, mixed>> matches from upstream, or `[]`
     *     on any failure (silent fallback; warning logged)
     */
    private function fetchFromUpstream(string $upstreamUrl, string $queryType, string $queryValue, int $now): array
    {
        $endpoint = rtrim($upstreamUrl, '/') . '/lookup';
        $body = json_encode([
            'items' => [
                [$queryType => $queryValue],
            ],
        ]);
        if ($body === false) {
            $this->stats->recordCall(false, $now);
            return [];
        }

        try {
            $response = $this->requestFactory->request($endpoint, 'POST', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => self::TIMEOUT_SECONDS,
            ]);
        } catch (\Throwable $e) {
            $this->stats->recordCall(false, $now);
            $this->logger->warning(
                'SimpleCMP library upstream lookup failed (network): {message}',
                ['endpoint' => $endpoint, 'message' => $e->getMessage()],
            );
            return [];
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->stats->recordCall(false, $now);
            $this->logger->warning(
                'SimpleCMP library upstream returned non-2xx: {status}',
                ['endpoint' => $endpoint, 'status' => $status],
            );
            return [];
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
            $this->stats->recordCall(true, $now);
            $this->logger->warning(
                'SimpleCMP library upstream returned malformed response (no `items` array).',
                ['endpoint' => $endpoint],
            );
            return [];
        }

        $this->stats->recordCall(true, $now);
        // Single-item request → single-item response. Pick the first
        // (and only) result item's `matches` array.
        $first = $payload['items'][0] ?? null;
        if (!is_array($first) || !isset($first['matches']) || !is_array($first['matches'])) {
            return [];
        }
        // Filter to associative-array entries so the caller can rely
        // on protocol-shaped rows. Any garbage in the response gets
        // silently skipped — better than throwing.
        $matches = [];
        foreach ($first['matches'] as $match) {
            if (is_array($match) && isset($match['id'])) {
                $matches[] = $match;
            }
        }
        return $matches;
    }
}
