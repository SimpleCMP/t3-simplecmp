<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\UniversalBlocking\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Stream;
use WapplerSystems\SimpleCmpTypo3\UniversalBlocking\Service\HostMatcher;

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
 * Phase 0 prototype — opt-in via the env var
 * `SIMPLECMP_REWRITER_ENABLED=1` or the per-request query string
 * `?_simplecmp_rewrite=1` so dev14's regular pages aren't disrupted
 * unless we explicitly opt in. Emits a `Server-Timing: rewriter;dur=NN`
 * header on every request where the rewriter ran so the benchmark
 * script can pull cost numbers from headers without instrumenting page
 * content.
 *
 * Parser: native `DOMDocument` with the HTML5-tolerant flag mask. Zero
 * extra deps; if perf is bad on real pages we'll swap to Masterminds/
 * HTML5 in a follow-up. Phase 0's whole point is to find out.
 *
 * Design call defaults from ADR-0013:
 * - Tag scope (#4): iframe + script + img + link only for now; source/
 *   video/audio added if benchmarks allow.
 * - Inline scripts (#5): skipped — runtime monkey-patches (#26) handle
 *   JS-injected calls.
 * - Module scripts (#6): rewritten same as regular `<script src>`;
 *   compatibility verified later.
 * - Per-element override (#11): `data-no-rewrite` on the element opts
 *   it out (so integrators can keep one specific embed pre-marked).
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

    private HostMatcher $matcher;

    /** @var list<string> site's own hosts — never rewritten */
    private array $sameOriginHosts = [];

    public function __construct(?HostMatcher $matcher = null)
    {
        $this->matcher = $matcher ?? new HostMatcher();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Phase 0 gating — activate either via the env var (so a shell-
        // wide opt-in is possible) or via a per-request query string
        // (`?_simplecmp_rewrite=1`) so the benchmark script can A/B
        // back-to-back without restarting the stack.
        $envFlag = ($_ENV['SIMPLECMP_REWRITER_ENABLED'] ?? getenv('SIMPLECMP_REWRITER_ENABLED')) === '1';
        $queryFlag = ($request->getQueryParams()['_simplecmp_rewrite'] ?? null) === '1';
        if (!$envFlag && !$queryFlag) {
            return $handler->handle($request);
        }
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
        $rewritten = $this->rewriteHtml($body, $stats);
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
    private function rewriteHtml(string $html, array &$stats): string
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
                $service = $this->matcher->match($host);
                if ($service === null) {
                    continue;
                }
                // Rewrite to the engine's gate shape.
                $node->setAttribute('data-name', $service);
                $node->setAttribute('data-src', $url);
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
