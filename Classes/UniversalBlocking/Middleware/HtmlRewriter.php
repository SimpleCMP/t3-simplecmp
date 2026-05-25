<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\UniversalBlocking\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Service\HostMatcher;

/**
 * Universal-blocking HTML rewriter for TYPO3 (ADR-0013).
 *
 * PSR-15 frontend middleware. After the downstream handler renders the
 * page, this scans the response body for third-party subresource
 * references (<script src>, <iframe src>, <img src>, <link href>) and
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
 * - Tag scope (#4): iframe + script + img + link only for now; source/
 *   video/audio added if a real site needs them.
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

    /** @var list<string> site's own hosts — never rewritten */
    private array $sameOriginHosts = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $handler->handle($request);
        }
        $settings = $site->getSettings();
        if (!$settings->get('simplecmp.universalBlocking.enabled')) {
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

        $this->sameOriginHosts = [$request->getUri()->getHost()];

        $start = hrtime(true);
        $stats = ['scanned' => 0, 'rewritten' => 0];
        $rewritten = $this->rewriteHtml($body, $matcher, $stats);
        $elapsedMs = (hrtime(true) - $start) / 1e6;

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
     * @param array{scanned: int, rewritten: int} $stats updated in-place
     */
    private function rewriteHtml(string $html, HostMatcher $matcher, array &$stats): string
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
                $url = $node->getAttribute($attr);
                if ($url === '') {
                    continue;
                }
                $host = parse_url($url, PHP_URL_HOST);
                if (!is_string($host) || $host === '') {
                    continue;
                }
                $stats['scanned']++;
                if (in_array($host, $this->sameOriginHosts, true)) {
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
                $node->setAttribute('data-src', $url);
                $node->setAttribute('data-blocked-source', $resolution['source']);
                if ($tagName === 'iframe' || $tagName === 'img') {
                    $node->setAttribute($attr, 'about:blank');
                } elseif ($tagName === 'script') {
                    $node->setAttribute('type', 'text/plain');
                    $node->removeAttribute('src');
                } elseif ($tagName === 'link') {
                    $node->setAttribute($attr, 'about:blank');
                }
                $stats['rewritten']++;
            }
        }

        // saveHTML emits a full document including DOCTYPE; strip the
        // XML processing instruction we prepended.
        $result = (string) $dom->saveHTML();
        return preg_replace('/^<\?xml encoding="utf-8"\?>\s*/', '', $result) ?? $result;
    }
}
