<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Single source of truth for the detection `source` string of a site.
 *
 * The discover sweep authenticates its server-side DB write with a
 * source-bound nonce (see BridgeNonceService): the BE mints a token bound
 * to this string, and HtmlRewriter both verifies the token against it and
 * writes detections under it. Mint and verify MUST agree byte-for-byte —
 * any drift is a silent auth failure — so both derive the source here
 * rather than each re-deriving it inline.
 *
 * Rule (mirrors the original inline logic + the bridge envelope `source`):
 * the configured `simplecmp.storageName`, or `simplecmp-<siteIdentifier>`
 * when unset.
 */
final class DiscoverSource
{
    public static function forSite(Site $site): string
    {
        $source = (string) ($site->getSettings()->get('simplecmp.storageName') ?: '');
        return $source !== '' ? $source : 'simplecmp-' . $site->getIdentifier();
    }
}
