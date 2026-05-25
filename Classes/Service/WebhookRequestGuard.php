<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Cheap, no-DB header checks for incoming bridge webhook POSTs.
 *
 * Two layers:
 *
 * 1. `Sec-Fetch-Site` — set by modern browsers, not modifiable by JS.
 *    Must be `same-origin` or `same-site` when present. Defends
 *    against cross-origin XSS abuse triggering the bridge from an
 *    attacker-controlled page. Absence is tolerated (older browsers,
 *    non-browser clients pass through to subsequent layers).
 *
 * 2. `Origin` — must match the host of some configured Site's base
 *    URL. Defends against opportunistic cross-origin abuse and
 *    obviously-misconfigured bridges pointed at the wrong receiver.
 *    Absence is tolerated for the same reason.
 *
 * Both are defense-in-depth; a curl/non-browser attacker can forge
 * either header. The strong defenses live one layer deeper (rate
 * limit + signed nonce in Phase 2).
 */
final readonly class WebhookRequestGuard
{
    private const array ALLOWED_FETCH_SITES = ['same-origin', 'same-site'];

    public function __construct(
        private SiteFinder $siteFinder,
    ) {
    }

    /**
     * @return string|null Error message on failure, null on pass.
     */
    public function check(ServerRequestInterface $request): ?string
    {
        $fetchSite = $this->firstHeader($request, 'Sec-Fetch-Site');
        if ($fetchSite !== null && !in_array($fetchSite, self::ALLOWED_FETCH_SITES, true)) {
            return 'Cross-site request rejected';
        }

        $origin = $this->firstHeader($request, 'Origin');
        if ($origin !== null && $origin !== '' && $origin !== 'null') {
            $originHost = parse_url($origin, PHP_URL_HOST);
            if (!is_string($originHost) || $originHost === '' || !$this->originIsKnown($originHost)) {
                return 'Unknown origin';
            }
        }

        return null;
    }

    private function firstHeader(ServerRequestInterface $request, string $name): ?string
    {
        $values = $request->getHeader($name);
        return $values === [] ? null : (string) $values[0];
    }

    private function originIsKnown(string $host): bool
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (!$site instanceof Site) {
                continue;
            }
            $baseHost = parse_url((string) $site->getBase(), PHP_URL_HOST);
            if (is_string($baseHost) && strcasecmp($baseHost, $host) === 0) {
                return true;
            }
        }
        return false;
    }
}
