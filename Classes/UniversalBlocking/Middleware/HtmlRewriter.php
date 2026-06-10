<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\UniversalBlocking\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;
use SimpleCMP\T3SimpleCmp\Domain\Repository\DetectionRepository;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceService;
use SimpleCMP\T3SimpleCmp\Service\DiscoverSource;
use SimpleCMP\T3SimpleCmp\Service\StoragePidResolver;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Service\HostMatcher;

/**
 * Universal-blocking HTML rewriter for TYPO3 (ADR-0013).
 *
 * PSR-15 frontend middleware. After the downstream handler renders the
 * page, this scans the response body for third-party subresource
 * references (<script src>, <iframe src>, <img src>, and <link href>
 * for resource-hint rels only — see LINK_REWRITABLE_RELS) and
 * rewrites them to the engine's `data-name + data-src + src="about:blank"`
 * shape — same shape integrators write by hand for the existing opt-in
 * pattern. The engine's existing handling takes it from there: consent
 * granted → `src` swapped in; consent denied → contextual-notice auto-
 * inserts next to the placeholder.
 *
 * Activation per Site Set: turn on via
 * `simplecmp.universalBlocking.enabled` in the site's settings. The
 * companion `simplecmp.universalBlocking.allowlist` stringlist lets
 * admins exempt hosts (exact `cdn.example.com` or wildcard
 * `*.example.com`); the site's own host is allowlisted automatically.
 *
 * Emits a `Server-Timing: rewriter;dur=NN;desc="scanned=N,rewritten=N"`
 * header on every request where the rewriter ran so dev tools / the
 * benchmark script can read cost numbers without instrumenting page
 * content.
 *
 * Parser: native `DOMDocument` with the HTML5-tolerant flag mask. Phase
 * 0 measurement on a 30-iframe worst-case page came in at <5 ms p50, so
 * no dependency on `Masterminds/HTML5` was needed.
 *
 * Design call defaults from ADR-0013:
 * - Tag scope (#4): iframe + script + img fully; <link> only for
 *   resource-hint rels (LINK_REWRITABLE_RELS) — stylesheet/canonical/
 *   icon are left untouched to avoid breaking CSS/SEO. source/video/
 *   audio added if a real site needs them.
 * - Inline scripts (#5): skipped — runtime monkey-patches handle JS-
 *   injected calls.
 * - Module scripts (#6): rewritten same as regular `<script src>`.
 * - Per-element override (#11): `data-no-rewrite` opts an element out
 *   (escape hatch for integrator-marked exceptions).
 */
final class HtmlRewriter implements MiddlewareInterface
{
    /**
     * Attributes to scan per tag. The first entry is the canonical
     * src attribute we rewrite to `data-src`; the engine swaps it
     * back on consent.
     *
     * @var array<string, string>
     */
    private const TAG_ATTR = [
        'iframe' => 'src',
        'script' => 'src',
        'img'    => 'src',
        'link'   => 'href',
    ];

    /**
     * `<link>` is only rewritten for these `rel` values — the resource
     * hints, which open a third-party connection (DNS/TCP/TLS or an
     * actual fetch) *before* consent. Neutralizing them to `about:blank`
     * is invisible (a hint has no visual effect; the underlying
     * script/img/iframe is gated by its own rule anyway).
     *
     * Everything else is deliberately left untouched:
     * - `stylesheet` — render-critical. There is no contextual-notice
     *   recovery UI for an invisible `<link>`, so rewriting it to
     *   `about:blank` would silently strip the page's CSS with no way
     *   to load it after consent. Blocking third-party stylesheets
     *   (e.g. Google Fonts) is also leaky at this layer — the browser's
     *   preload scanner can fetch before interception and `@import`
     *   inside a stylesheet escapes entirely — so a server-side strip
     *   gives false confidence. The correct fix for fonts is
     *   self-hosting. A deliberate opt-in stylesheet blocker (with
     *   consent re-injection) is tracked as a separate follow-up; see
     *   docs/decisions/2026-05-30-link-rewrite-rel-policy.md.
     * - `canonical` / `alternate` — SEO metadata; rewriting to
     *   `about:blank` poisons the canonical URL. These never cause a
     *   subresource fetch, so there is no privacy reason to touch them.
     * - `icon` / `manifest` / `mask-icon` / etc. — structural; breaking
     *   them harms the site for no privacy gain.
     * - unknown rels — left alone (allowlist, not blocklist) so a novel
     *   `rel` value can never cause surprise breakage.
     *
     * No mainstream CMP (Cookiebot, Usercentrics, Borlabs, Real Cookie
     * Banner, consentmanager.net) auto-rewrites arbitrary `<link>` tags;
     * they target scripts/iframes/images. This list keeps us aligned
     * with that norm while still neutralizing the genuine pre-consent
     * leak that hints represent.
     *
     * @var list<string>
     */
    private const LINK_REWRITABLE_RELS = [
        'preconnect',
        'dns-prefetch',
        'preload',
        'prefetch',
        'modulepreload',
        'prerender',
    ];

    /** @var list<string> site's own hosts (lowercased) — never rewritten */
    private array $sameOriginHosts = [];

    /** REQ-N8 opt-in: also gate third-party `<link rel="stylesheet">`. */
    private bool $blockStylesheets = false;

    /**
     * Maps a rewritten tag to the detection `kind` the BE list expects.
     * Must match the FE recorder's vocabulary (DomWatcher `TAG_TO_KIND`:
     * SCRIPT→script, IFRAME→iframe, IMG→image, LINK→link) — detections are
     * deduped/categorised by `(source, kind, identifier)`, so a tag seen
     * both server-side and by the recorder must land under the same kind
     * (and the same BE category — `link` → "Links", not "Anfragen").
     */
    private const KIND_MAP = [
        'script' => 'script',
        'iframe' => 'iframe',
        'img' => 'image',
        'link' => 'link',
    ];

    public function __construct(
        private readonly DetectionRepository $detectionRepository,
        private readonly StoragePidResolver $storagePidResolver,
        private readonly BridgeNonceService $bridgeNonceService,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $handler->handle($request);
        }
        $settings = $site->getSettings();
        if (!$settings->get('simplecmp.universalBlocking.enabled')) {
            // Coverage note: this short-circuit also means the discover
            // server-write below never fires for sites that rely solely on
            // `[data-name]` opt-in blocking (universal blocking off). That's
            // acceptable — this middleware can only record embeds *it*
            // neutralised, and with universal blocking off it neutralises
            // nothing. Such sites surface trackers via the FE recorder →
            // bridge path instead; the discover sweep just won't add
            // server-detected declaratively-blocked embeds for them.
            return $handler->handle($request);
        }

        $allowlistRaw = $settings->get('simplecmp.universalBlocking.allowlist');
        $allowlist = [];
        if (is_array($allowlistRaw)) {
            foreach ($allowlistRaw as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $allowlist[] = $entry;
                }
            }
        }
        $matcher = new HostMatcher($allowlist);

        $response = $handler->handle($request);
        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains(strtolower($contentType), 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();
        if ($body === '' || stripos($body, '<html') === false) {
            return $response;
        }

        $this->sameOriginHosts = $this->siteHosts($site, $request);
        $this->blockStylesheets = (bool) $settings->get('simplecmp.universalBlocking.blockStylesheets');

        $start = hrtime(true);
        $stats = ['scanned' => 0, 'rewritten' => 0];
        $detections = [];
        $rewritten = $this->rewriteHtml($body, $matcher, $stats, $detections);
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        // Discover mode: the admin's sitemap sweep wants declaratively-
        // blocked embeds (YouTube, Maps, …) to surface as detections too.
        // The FE recorder can't see them — we already neutralised them to
        // about:blank server-side, so they never run. We know exactly what
        // we rewrote, so record it here.
        //
        // This is the ONLY unauthenticated path that could otherwise write
        // to the detection table (the bridge webhook is HMAC-nonce + guard
        // + rate-limit protected). `?simplecmp_discover=1` is settable by
        // any anonymous visitor, so it alone must NOT authorise a DB write.
        // The legitimate sweep additionally carries a source-bound,
        // expiring `simplecmp_discover_token` minted by the BE (which holds
        // the bridge secret) — only a valid token authorises the write. An
        // anonymous visitor can't forge it, so they can rewrite/observe but
        // never persist. Runtime trackers still flow through the bridge.
        $params = $request->getQueryParams();
        if ($detections !== []
            && (($params['simplecmp_discover'] ?? null) === '1')
            && $this->discoverTokenValid($params['simplecmp_discover_token'] ?? null, $site)
        ) {
            $this->recordDiscoverDetections($request, $site, $detections);
        }

        $newBody = new Stream('php://temp', 'r+');
        $newBody->write($rewritten);
        $newBody->rewind();

        // Update Content-Length so downstream caches / clients don't
        // truncate; the rewrite may have grown or shrunk the body.
        return $response
            ->withBody($newBody)
            ->withHeader('Content-Length', (string) strlen($rewritten))
            ->withHeader(
                'Server-Timing',
                sprintf(
                    'rewriter;dur=%.2f;desc="scanned=%d,rewritten=%d"',
                    $elapsedMs,
                    $stats['scanned'],
                    $stats['rewritten'],
                ),
            );
    }

    /**
     * Persist the embeds we just neutralised as detections, so a Discover
     * sweep surfaces declaratively-blocked services (YouTube, Maps, …) that
     * the runtime recorder never sees. Mirrors the bridge receiver's ingest
     * path (`source` = storageName, pid via the resolver); the row's state
     * (kuratiert / erkannt / unbekannt) is derived at view time from the
     * registry + library, so no `matchedService` is stored here.
     *
     * @param list<array{kind: string, identifier: string, origin: string}> $detections
     */
    /**
     * Whether `$token` is a valid, unexpired discover token bound to this
     * site's detection source. The token is a {@see BridgeNonceService}
     * nonce (stateless HMAC + expiry, source-bound) minted by the BE
     * DiscoveryController; verifying it here proves the request belongs to
     * an admin-initiated sweep rather than arbitrary visitor traffic that
     * merely appended `?simplecmp_discover=1`. Returns false (no write) for
     * a missing / non-string / forged / expired / wrong-source token, and
     * when no bridge secret is configured (verify() throws → treated as
     * unauthorised). Never throws out of the middleware.
     */
    private function discoverTokenValid(mixed $token, Site $site): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        try {
            return $this->bridgeNonceService->verify($token, DiscoverSource::forSite($site))->isValid();
        } catch (\Throwable) {
            // Secret unconfigured / unexpected — fail closed.
            return false;
        }
    }

    private function recordDiscoverDetections(ServerRequestInterface $request, Site $site, array $detections): void
    {
        $source = DiscoverSource::forSite($site);

        // De-dupe within this page by (kind, identifier) — the same embed
        // can appear twice; ingest() would just bump occurrences, but we
        // save the round-trips.
        $seen = [];
        $unique = [];
        foreach ($detections as $detection) {
            $key = $detection['kind'] . '|' . $detection['identifier'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $detection;
        }

        $payload = [
            'source' => $source,
            'sentAt' => gmdate('c'),
            'page' => ['url' => (string) $request->getUri()],
            'detections' => $unique,
        ];
        $this->detectionRepository->ingest(
            $payload,
            $this->storagePidResolver->resolveForRequest($request),
        );
    }

    /**
     * The site's own hosts (lowercased), which must never be rewritten:
     * the request host plus the site's configured base + per-language base
     * hosts. Without the configured hosts, a multi-domain / multi-language
     * site's absolute first-party asset URLs (served from a sibling host the
     * site owns) would be treated as third-party and neutralised. Only
     * *widens* the first-party set to hosts the site actually owns — it never
     * exempts a third-party host. Mirrors DiscoveryController::siteHosts().
     *
     * @return list<string>
     */
    private function siteHosts(Site $site, ServerRequestInterface $request): array
    {
        $hosts = [];
        $add = static function (string $host) use (&$hosts): void {
            $host = strtolower($host);
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        };
        $add($request->getUri()->getHost());
        $add($site->getBase()->getHost());
        foreach ($site->getLanguages() as $language) {
            $add($language->getBase()->getHost());
        }

        return $hosts;
    }

    /**
     * @param array{scanned: int, rewritten: int} $stats updated in-place
     * @param list<array{kind: string, identifier: string, origin: string}> $detections collected in-place
     */
    private function rewriteHtml(string $html, HostMatcher $matcher, array &$stats, array &$detections = []): string
    {
        $dom = new \DOMDocument();
        // Real-world HTML is messy; suppress the libxml warning noise
        // and let `LIBXML_NOERROR` swallow non-fatal issues.
        $loaded = @$dom->loadHTML(
            '<?xml encoding="utf-8"?>' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
        );
        if (!$loaded) {
            return $html;
        }

        foreach (self::TAG_ATTR as $tagName => $attr) {
            foreach (iterator_to_array($dom->getElementsByTagName($tagName)) as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                if ($node->hasAttribute('data-no-rewrite')) {
                    continue;
                }
                if ($node->hasAttribute('data-name')) {
                    // Already integrator-marked; engine handles it.
                    continue;
                }
                // <link> is rewritten only for resource-hint rels (see
                // LINK_REWRITABLE_RELS) — plus rel=stylesheet when the
                // blockStylesheets opt-in is on (REQ-N8). canonical / icon /
                // unknown rels are always left untouched.
                if ($tagName === 'link'
                    && !$this->linkRelIsRewritable($node)
                    && !($this->blockStylesheets && $this->linkRelIsStylesheet($node))
                ) {
                    continue;
                }
                $url = $node->getAttribute($attr);
                if ($url === '') {
                    continue;
                }
                $host = parse_url($url, PHP_URL_HOST);
                if (!is_string($host) || $host === '') {
                    continue;
                }
                $stats['scanned']++;
                // sameOriginHosts is lowercased; parse_url() leaves the host
                // case as-written, and DNS hosts are case-insensitive — so
                // compare lowercased or an upper/mixed-case first-party URL
                // would slip past the exemption and get neutralised.
                if (in_array(strtolower($host), $this->sameOriginHosts, true)) {
                    continue;
                }
                $resolution = $matcher->resolve($host);
                if ($resolution === null) {
                    continue;
                }
                // Rewrite to the engine's gate shape. `data-blocked-source`
                // tells the FE engine which contextual-notice render mode
                // to use: `library` → visitor sees a "Ja" (one-time accept)
                // button; `host` → informational-only notice because the
                // visitor has no basis to consent to an unknown vendor.
                $node->setAttribute('data-name', $resolution['service']);
                $node->setAttribute('data-blocked-source', $resolution['source']);
                if ($tagName === 'link' && $this->blockStylesheets && $this->linkRelIsStylesheet($node)) {
                    // REQ-N8 stylesheet gate: the engine reinjects from
                    // data-href on consent. A stylesheet ignores `type=` and
                    // would still load at about:blank — only an ABSENT href
                    // blocks it — so move the href into data-href and strip it.
                    $node->setAttribute('data-href', $url);
                    $node->removeAttribute($attr); // href
                } else {
                    $node->setAttribute('data-src', $url);
                    if ($tagName === 'script') {
                        $node->setAttribute('type', 'text/plain');
                        $node->removeAttribute('src');
                    } else {
                        // iframe / img / resource-hint <link>
                        $node->setAttribute($attr, 'about:blank');
                    }
                }
                $stats['rewritten']++;
                $detections[] = [
                    'kind' => self::KIND_MAP[$tagName] ?? 'request',
                    'identifier' => $url,
                    'origin' => $host,
                ];
            }
        }

        // saveHTML emits a full document. When the input had no XML
        // processing instruction, our prepended UTF-8 hint lands at
        // the start; when the input HAD an xml-PI of its own,
        // saveHTML emits a DOCTYPE first and our PI ends up further
        // in. Strip the first occurrence anywhere — there's only
        // ever one because we inject exactly one. Without the
        // limit-1 + non-anchored regex, our `encoding="utf-8"`
        // marker would leak into the visitor's response body for any
        // input that had its own xml-PI.
        $result = (string) $dom->saveHTML();
        return preg_replace('/<\?xml encoding="utf-8"\?>\s*/', '', $result, 1) ?? $result;
    }

    /**
     * True only when a `<link>`'s `rel` carries a rewritable resource
     * hint (see LINK_REWRITABLE_RELS). `rel` is a space-separated,
     * case-insensitive token list; we rewrite if ANY token is a hint.
     * Missing/empty `rel` → not rewritable (left alone).
     */
    private function linkRelIsRewritable(\DOMElement $node): bool
    {
        $rel = strtolower(trim($node->getAttribute('rel')));
        if ($rel === '') {
            return false;
        }
        foreach (preg_split('/\s+/', $rel) ?: [] as $token) {
            if (in_array($token, self::LINK_REWRITABLE_RELS, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when a `<link>`'s `rel` token list includes `stylesheet`. Gated
     * behind the `blockStylesheets` opt-in by the caller (REQ-N8).
     */
    private function linkRelIsStylesheet(\DOMElement $node): bool
    {
        $rel = strtolower(trim($node->getAttribute('rel')));
        if ($rel === '') {
            return false;
        }
        return in_array('stylesheet', preg_split('/\s+/', $rel) ?: [], true);
    }
}
