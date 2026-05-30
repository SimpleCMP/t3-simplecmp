<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Per-IP per-hour rate limit for the SimpleCMP API endpoints.
 *
 * Two independent counters share this logic via distinct cache-key
 * prefixes: `check()` guards `/api/simplecmp/webhook`
 * (`simplecmp.bridgeRateLimit`, default 500), and `checkLookup()` guards
 * the public `/api/simplecmp/v1/lookup` classifier endpoint
 * (`simplecmp.serviceDbRateLimit`, default 5000 — deliberately loose
 * because legit visitor browsers hit it). They never share a budget.
 *
 * Buckets requests by `floor(time / 3600)` and the client's
 * `REMOTE_ADDR`. The bucket TTL matches the bucket length, so old
 * entries expire naturally on the next-hour rollover.
 *
 * Each limit per site is read from its Site Set setting. Set to 0 to
 * disable that counter.
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
    private const string LOOKUP_SETTING_KEY = 'simplecmp.serviceDbRateLimit';
    private const int LOOKUP_DEFAULT_LIMIT = 5000;
    private const int BUCKET_SECONDS = 3600;

    public function __construct(
        private CacheManager $cacheManager,
        private SiteFinder $siteFinder,
    ) {
    }

    /**
     * Webhook limit — per-IP per-hour, `simplecmp.bridgeRateLimit`
     * (default 500). Key prefix `b_`.
     *
     * @return array{allowed: bool, limit: int, count: int, retryAfter: int}
     */
    public function check(ServerRequestInterface $request): array
    {
        return $this->evaluate(
            $request,
            $this->limitForRequest($request, self::SETTING_KEY, self::DEFAULT_LIMIT),
            'b_',
        );
    }

    /**
     * Service-DB `/lookup` limit — a SEPARATE, deliberately loose per-IP
     * per-hour counter (its own `l_` key prefix, so it never shares the
     * webhook's budget). `/lookup` is a public FE endpoint hit by visitor
     * browsers on a classifier miss, so the default is high
     * (5000/h/IP ≈ 83/min) to stay invisible to real traffic and tolerant
     * of shared NAT/CGNAT IPs, while still capping a single-IP flood.
     * `simplecmp.serviceDbRateLimit` overrides it; 0 disables.
     *
     * @return array{allowed: bool, limit: int, count: int, retryAfter: int}
     */
    public function checkLookup(ServerRequestInterface $request): array
    {
        return $this->evaluate(
            $request,
            $this->limitForRequest($request, self::LOOKUP_SETTING_KEY, self::LOOKUP_DEFAULT_LIMIT),
            'l_',
        );
    }

    /**
     * Shared per-IP per-hour counter. `$keyPrefix` keeps the webhook and
     * lookup buckets independent.
     *
     * @return array{allowed: bool, limit: int, count: int, retryAfter: int}
     */
    private function evaluate(ServerRequestInterface $request, int $limit, string $keyPrefix): array
    {
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
        $key = $this->cacheKey($keyPrefix, $ip, $bucket);
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

    private function cacheKey(string $prefix, string $ip, int $bucket): string
    {
        // sha1 — strip non-alphanumerics from the IP that cache backends
        // refuse, gives a fixed-length stable key. `$prefix` separates the
        // webhook (`b_`) and lookup (`l_`) counters.
        return $prefix . sha1($ip) . '_' . $bucket;
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

    private function limitForRequest(ServerRequestInterface $request, string $settingKey, int $default): int
    {
        $host = $request->getUri()->getHost();
        if ($host !== '') {
            foreach ($this->siteFinder->getAllSites() as $site) {
                if (!$site instanceof Site) {
                    continue;
                }
                $baseHost = parse_url((string) $site->getBase(), PHP_URL_HOST);
                if (is_string($baseHost) && strcasecmp($baseHost, $host) === 0) {
                    return $this->limitFromSite($site, $settingKey, $default);
                }
            }
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($site instanceof Site) {
                return $this->limitFromSite($site, $settingKey, $default);
            }
        }
        return $default;
    }

    private function limitFromSite(Site $site, string $settingKey, int $default): int
    {
        $value = $site->getSettings()->get($settingKey);
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && ctype_digit($value)) {
            return max(0, (int) $value);
        }
        return $default;
    }
}
