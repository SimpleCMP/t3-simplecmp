<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Probes `/v1/health` on the configured upstream library service for
 * the freshness panel on the Bibliothek tab.
 *
 * Caches the result for 30 minutes so opening the tab repeatedly
 * doesn't spam upstream. The "Jetzt prüfen" button on the same panel
 * flushes the cache for an on-demand refresh.
 *
 * The bundle's commit SHA is captured alongside the snapshot in the
 * cache; if the bundle gets upgraded via composer between probes,
 * the stale "in sync" claim is invalidated on the next render rather
 * than waiting for the TTL to roll over.
 *
 * Cache backend: Typo3DatabaseBackend over VariableFrontend
 * (`t3_simplecmp_library_upstream_health`, registered in
 * ext_localconf.php) — same choice as `BridgeRateLimiter`.
 *
 * Network posture mirrors `LibraryUpstreamClient`: 3s timeout, silent
 * failure (returns null), warning logged. Tab still renders the rest
 * of the card.
 */
final readonly class LibraryUpstreamHealth
{
    public const string CACHE_IDENTIFIER = 't3_simplecmp_library_upstream_health';
    private const int TIMEOUT_SECONDS = 3;
    private const int CACHE_TTL_SECONDS = 1800; // 30 minutes

    public function __construct(
        private RequestFactory $requestFactory,
        private CacheManager $cacheManager,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Fetch the upstream health snapshot. Returns null when the URL is
     * empty, unreachable, or returns a malformed payload. The caller
     * should render an "upstream not reachable" indicator in that
     * case. Cache-first; only stale or bundle-shifted entries trigger
     * a network call.
     *
     * `$bundleDataHash` is captured in the cache row so a bundle
     * upgrade (composer update) automatically invalidates a stale
     * "drift" answer — the next call sees the SHA mismatch and
     * re-probes rather than reporting the old upstream state against
     * the new bundle.
     *
     * @return array{
     *     serviceCount: int|null,
     *     sourceSha: string|null,
     *     dataHash: string|null,
     *     lastSyncAt: int|null,
     *     fetchedAt: int,
     * }|null
     */
    public function snapshot(?string $upstreamUrl, ?string $bundleDataHash): ?array
    {
        if ($upstreamUrl === null || $upstreamUrl === '') {
            return null;
        }
        $cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
        $key = $this->cacheKey($upstreamUrl);
        $cached = $cache->get($key);
        if (is_array($cached) && ($cached['bundleDataHash'] ?? null) === $bundleDataHash) {
            unset($cached['bundleDataHash']);
            /** @var array{serviceCount: int|null, sourceSha: string|null, dataHash: string|null, lastSyncAt: int|null, fetchedAt: int} $cached */
            return $cached;
        }

        $snapshot = $this->fetchFromUpstream($upstreamUrl);
        if ($snapshot === null) {
            return null;
        }
        $cache->set(
            $key,
            ['bundleDataHash' => $bundleDataHash] + $snapshot,
            [],
            self::CACHE_TTL_SECONDS,
        );
        return $snapshot;
    }

    /**
     * Drop all cached health snapshots so the next `snapshot()` call
     * re-fetches. Wired to the "Jetzt prüfen" button.
     */
    public function flush(): void
    {
        $this->cacheManager->getCache(self::CACHE_IDENTIFIER)->flush();
    }

    /**
     * Cache-only sync probe — never triggers a network call. Returns
     * true iff a cached health snapshot exists for `$upstreamUrl`, was
     * captured against the same bundle hash, AND reports a non-null
     * upstream `dataHash` equal to `$bundleDataHash`.
     *
     * Used by `LibraryUpstreamClient::lookup()` to short-circuit
     * upstream `/lookup` calls when the bundled library and upstream
     * carry byte-identical data — in that state, upstream cannot
     * return any match the bundled tier didn't already see, so the
     * call is provably wasted.
     *
     * Three guard cases return false:
     *   - empty / null URL
     *   - no cache entry, or cache entry was captured against a
     *     different bundle hash (composer-update happened)
     *   - upstream `dataHash` is null (legacy upstream pre-d92ed61
     *     or malformed response)
     *
     * Degrades cleanly to today's behavior: callers that can't
     * confirm in-sync fall through to the normal upstream call.
     */
    public function cachedInSync(?string $upstreamUrl, string $bundleDataHash): bool
    {
        if ($upstreamUrl === null || $upstreamUrl === '') {
            return false;
        }
        $cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
        $cached = $cache->get($this->cacheKey($upstreamUrl));
        if (!is_array($cached)) {
            return false;
        }
        if (($cached['bundleDataHash'] ?? null) !== $bundleDataHash) {
            return false;
        }
        $upstreamHash = $cached['dataHash'] ?? null;
        return is_string($upstreamHash) && $upstreamHash === $bundleDataHash;
    }

    /**
     * @return array{
     *     serviceCount: int|null,
     *     sourceSha: string|null,
     *     dataHash: string|null,
     *     lastSyncAt: int|null,
     *     fetchedAt: int,
     * }|null
     */
    private function fetchFromUpstream(string $upstreamUrl): ?array
    {
        $endpoint = rtrim($upstreamUrl, '/') . '/health';
        try {
            $response = $this->requestFactory->request($endpoint, 'GET', [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => self::TIMEOUT_SECONDS,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'SimpleCMP library upstream /health probe failed (network): {message}',
                ['endpoint' => $endpoint, 'message' => $e->getMessage()],
            );
            return null;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning(
                'SimpleCMP library upstream /health returned non-2xx: {status}',
                ['endpoint' => $endpoint, 'status' => $status],
            );
            return null;
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            $this->logger->warning(
                'SimpleCMP library upstream /health returned malformed JSON.',
                ['endpoint' => $endpoint],
            );
            return null;
        }

        $serviceCount = $payload['serviceCount'] ?? null;
        $sourceSha = $payload['sourceSha'] ?? null;
        $dataHash = $payload['dataHash'] ?? null;
        $lastSyncRaw = $payload['lastSyncAt'] ?? null;
        $lastSyncAt = null;
        if (is_string($lastSyncRaw) && $lastSyncRaw !== '') {
            $ts = strtotime($lastSyncRaw);
            $lastSyncAt = $ts === false ? null : $ts;
        }

        return [
            'serviceCount' => is_int($serviceCount) ? $serviceCount : null,
            'sourceSha' => is_string($sourceSha) && strlen($sourceSha) === 40 ? $sourceSha : null,
            'dataHash' => is_string($dataHash) && strlen($dataHash) === 64 ? $dataHash : null,
            'lastSyncAt' => $lastSyncAt,
            'fetchedAt' => time(),
        ];
    }

    private function cacheKey(string $upstreamUrl): string
    {
        return 'h_' . sha1($upstreamUrl);
    }
}
