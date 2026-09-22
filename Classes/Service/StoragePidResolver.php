<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Resolves `simplecmp.storagePid` from a TYPO3 site's settings.
 *
 * Three resolution paths, each used by a different caller:
 *
 * - `resolveForRequest()` — webhook receiver path. Our middleware
 *   runs *before* `typo3/cms-frontend/site`, so the request doesn't
 *   carry a resolved site attribute yet; we re-derive it from the
 *   request's host.
 * - `resolveForSource()` — controller path. The detection's `source`
 *   field is typically `simplecmp-<siteIdentifier>` (the storage
 *   name), so we strip the prefix and look up the matching site.
 *   Falls back to the first site if no match.
 * - `resolveDefault()` — CLI / seed / global-registry path. Picks the
 *   first site that has the setting configured. There is typically only
 *   one storage-pid value across an installation anyway, since the
 *   registry is global.
 *
 * All three return 0 if no site matches or the setting isn't
 * configured — `pid=0` (site root) is the same default as if the
 * setting hadn't been added at all.
 */
final readonly class StoragePidResolver
{
    private const string SETTING_KEY = 'simplecmp.storagePid';
    private const string SOURCE_PREFIX = 'simplecmp-';

    public function __construct(
        private SiteFinder $siteFinder,
    ) {
    }

    public function resolveForRequest(ServerRequestInterface $request): int
    {
        $host = $request->getUri()->getHost();
        if ($host === '') {
            return $this->resolveDefault();
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($this->siteMatchesHost($site, $host)) {
                return $this->pidFromSite($site);
            }
        }
        return $this->resolveDefault();
    }

    public function resolveForSource(string $source): int
    {
        if ($source === '') {
            return $this->resolveDefault();
        }
        $identifier = str_starts_with($source, self::SOURCE_PREFIX)
            ? substr($source, strlen(self::SOURCE_PREFIX))
            : $source;
        try {
            $site = $this->siteFinder->getSiteByIdentifier($identifier);
            return $this->pidFromSite($site);
        } catch (\Throwable) {
            return $this->resolveDefault();
        }
    }

    public function resolveDefault(): int
    {
        // First site that actually CARRIES the setting — not simply the
        // first site. SiteFinder yields sites in identifier order, so on
        // a multi-site install the alphabetically-first site decides, and
        // it is usually not the one running the CMP: any site without
        // `simplecmp.storagePid` would then silently answer 0 and the
        // registry records land in the page-tree root instead of the
        // configured SysFolder. The registry is global, so any
        // configured value is the right one.
        foreach ($this->siteFinder->getAllSites() as $site) {
            $pid = $this->pidFromSite($site);
            if ($pid > 0) {
                return $pid;
            }
        }
        return 0;
    }

    private function pidFromSite(SiteInterface $site): int
    {
        if (!$site instanceof Site) {
            return 0;
        }
        $value = $site->getSettings()->get(self::SETTING_KEY);
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return 0;
        }
        return (int) $value;
    }

    private function siteMatchesHost(SiteInterface $site, string $host): bool
    {
        if (!$site instanceof Site) {
            return false;
        }
        $baseHost = parse_url((string) $site->getBase(), PHP_URL_HOST);
        return is_string($baseHost) && strcasecmp($baseHost, $host) === 0;
    }
}
