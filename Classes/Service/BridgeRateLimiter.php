<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Per-IP per-hour rate limit for `/api/simplecmp/webhook` POSTs.
 *
 * Buckets requests by `floor(time / 3600)` and the client's
 * `REMOTE_ADDR`. The bucket TTL matches the bucket length, so old
 * entries expire naturally on the next-hour rollover.
 *
 * The limit per site is read from the `simplecmp.bridgeRateLimit`
 * Site Set setting (default 500). Set to 0 to disable.
 *
 * Race conditions in the read-modify-write path are accepted: an
 * attacker squeezing through a handful of extra requests via
 * concurrency is uninteresting for this threat model. Rate limit is
 * a courtesy / DoS dampener, not a security boundary.
 *
 * `X-Forwarded-For` is intentionally NOT honored. It is trivially
 * spoofable when the application has no record of which proxy is
 * trusted, and would let an attacker bypass the limit by varying the
 * header. If a deployment is behind a known proxy and needs the real
 * client IP, that's a future configuration item.
 */
final readonly class BridgeRateLimiter
{
    public const string CACHE_IDENTIFIER = 't3_simplecmp_bridge_ratelimit';
    private const string SETTING_KEY = 'simplecmp.bridgeRateLimit';
    private const int DEFAULT_LIMIT = 500;
    private const int BUCKET_SECONDS = 3600;

    public function __construct(
        private CacheManager $cacheManager,
        private SiteFinder $siteFinder,
    ) {
    }

    /**
     * @return array{allowed: bool, limit: int, count: int, retryAfter: int}
     */
    public function check(ServerRequestInterface $request): array
    {
        $limit = $this->limitForRequest($request);
        if ($limit <= 0) {
            return ['allowed' => true, 'limit' => 0, 'count' => 0, 'retryAfter' => 0];
        }

        $ip = $this->clientIp($request);
        if ($ip === '') {
            // No identifiable IP — let the request through but the upstream
            // layers (origin guard, validator) still apply.
            return ['allowed' => true, 'limit' => $limit, 'count' => 0, 'retryAfter' => 0];
        }

        $bucket = (int) floor(time() / self::BUCKET_SECONDS);
        $key = $this->cacheKey($ip, $bucket);
        $cache = $this->cache();
        $current = (int) ($cache->get($key) ?: 0);
        if ($current >= $limit) {
            return [
                'allowed' => false,
                'limit' => $limit,
                'count' => $current,
                'retryAfter' => $this->secondsUntilNextBucket(),
            ];
        }
        $cache->set($key, $current + 1, [], self::BUCKET_SECONDS);
        return ['allowed' => true, 'limit' => $limit, 'count' => $current + 1, 'retryAfter' => 0];
    }

    private function cache(): FrontendInterface
    {
        return $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
    }

    private function cacheKey(string $ip, int $bucket): string
    {
        // sha1 — strip non-alphanumerics from the IP that cache backends
        // refuse, gives a fixed-length stable key.
        return 'b_' . sha1($ip) . '_' . $bucket;
    }

    private function secondsUntilNextBucket(): int
    {
        return self::BUCKET_SECONDS - (time() % self::BUCKET_SECONDS);
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();
        $remote = $params['REMOTE_ADDR'] ?? '';
        return is_string($remote) ? $remote : '';
    }

    private function limitForRequest(ServerRequestInterface $request): int
    {
        $host = $request->getUri()->getHost();
        if ($host !== '') {
            foreach ($this->siteFinder->getAllSites() as $site) {
                if (!$site instanceof Site) {
                    continue;
                }
                $baseHost = parse_url((string) $site->getBase(), PHP_URL_HOST);
                if (is_string($baseHost) && strcasecmp($baseHost, $host) === 0) {
                    return $this->limitFromSite($site);
                }
            }
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($site instanceof Site) {
                return $this->limitFromSite($site);
            }
        }
        return self::DEFAULT_LIMIT;
    }

    private function limitFromSite(Site $site): int
    {
        $value = $site->getSettings()->get(self::SETTING_KEY);
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && ctype_digit($value)) {
            return max(0, (int) $value);
        }
        return self::DEFAULT_LIMIT;
    }
}
