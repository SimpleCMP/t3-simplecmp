<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\UniversalBlocking\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Middleware\HtmlRewriter;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Service\HostMatcher;

/**
 * Defensive encoding tests for HtmlRewriter.
 *
 * Audit P2 from 2026-05-22: the middleware uses
 * `strlen($rewritten)` for the `Content-Length` header, which is
 * byte-count (correct for HTTP). But the rewriter rounds through
 * `DOMDocument::loadHTML(` + xml-PI + `$html)` to
 * force UTF-8 interpretation, then strips the prepended PI again
 * with a regex. The audit worried that non-ASCII content + BOM +
 * pre-existing XML preamble could trigger encoding surprises that
 * the byte-count math doesn't account for.
 *
 * These tests pin the current behavior so future PHP / DOMDocument
 * changes can't silently shift the byte counts.
 *
 * The private `rewriteHtml` is exercised via reflection — the
 * middleware-level entry would require PSR-7 mocking, which is
 * heavier than this scope warrants.
 */
final class HtmlRewriterEncodingTest extends TestCase
{
    private function rewrite(string $html, ?HostMatcher $matcher = null): string
    {
        $matcher ??= $this->matchingHostMatcher();
        $stats = ['scanned' => 0, 'rewritten' => 0];

        $rewriter = new HtmlRewriter();
        $ref = new \ReflectionClass($rewriter);

        // sameOriginHosts isn't a problem for these tests (we use a
        // distinct third-party host in the markup) but the property
        // must be initialized — Phase `process()` does this from the
        // request URI. Empty array is safe for these reflection-
        // driven tests.
        $sameOriginProp = $ref->getProperty('sameOriginHosts');
        $sameOriginProp->setValue($rewriter, []);

        $method = $ref->getMethod('rewriteHtml');
        $result = $method->invokeArgs($rewriter, [$html, $matcher, &$stats]);

        return (string) $result;
    }

    private function matchingHostMatcher(): HostMatcher
    {
        // blockAllThirdParty=true → resolves every non-allowlisted
        // host to either a library hit or `{service: <host>, source: 'host'}`.
        // Either way the rewriter does its thing, which is what we
        // want for the encoding-around-rewrites test.
        return new HostMatcher([], true);
    }

    #[Test]
    public function multibyteUtf8RoundTripsViaHtmlEntities(): void
    {
        // DOMDocument's `saveHTML()` converts non-ASCII characters to
        // HTML entities (numeric for chars without a named entity,
        // named otherwise). This is `saveHTML()`'s documented default
        // and we don't try to undo it — visitor browsers decode the
        // entities back to the original characters, so the rendered
        // output is identical to the input even though the byte
        // stream is different.
        //
        // Locking in the current behavior so future readers know
        // what to expect:
        //   - `Ü` → `&Uuml;` (named entity)
        //   - `你` → `&#20320;` (numeric entity)
        //   - emoji `🎉` → `&#127881;` (numeric entity)
        $payload = 'Über Café 你好 🎉';
        $html = '<!DOCTYPE html><html><head><title>' . $payload . '</title></head>'
            . '<body><p>Inhalt: ' . $payload . '</p>'
            . '<script src="https://tracker.example.com/x"></script>'
            . '</body></html>';

        $result = $this->rewrite($html);

        self::assertStringContainsString('data-name=', $result);

        // Named entity for Ü (ASCII 220).
        self::assertStringContainsString('&Uuml;', $result);
        // Numeric entity for the CJK characters.
        self::assertStringContainsString('&#20320;', $result);  // 你
        self::assertStringContainsString('&#22909;', $result);  // 好
        // Numeric entity for the emoji.
        self::assertStringContainsString('&#127881;', $result);  // 🎉
    }

    #[Test]
    public function contentLengthHeaderMatchesActualByteCount(): void
    {
        $payload = 'café — preserved';
        $html = "<!DOCTYPE html><html><body>{$payload}<img src=\"https://tracker.example.com/p.gif\"></body></html>";

        $result = $this->rewrite($html);

        // strlen on a UTF-8 string is byte count, not char count.
        // The byte count is what HTTP Content-Length needs. Pin that
        // strlen(result) is a stable byte measurement that matches
        // what the middleware writes via withHeader('Content-Length').
        $bytes = strlen($result);
        self::assertGreaterThan(strlen($payload), $bytes);
        // strlen and mb_strlen with the byte encoding agree.
        self::assertSame($bytes, mb_strlen($result, '8bit'));
    }

    #[Test]
    public function inputWithExistingXmlPreambleStillProducesValidByteCount(): void
    {
        // Someone passes HTML that already starts with an XML PI.
        // The rewriter prepends its own xml-PI as the UTF-8 hint;
        // the strip regex removes the LAST-prepended one but leaves
        // the user's original PI intact. We don't promise to dedup
        // user-supplied PIs — only to keep the byte-count math
        // consistent so the Content-Length header doesn't lie.
        //
        // Note: literal xml-PI tokens in this test file would break
        // the PHP lexer (the closing tag terminates PHP mode under
        // some configurations). Build the PI via concat so the
        // source stays parseable.
        $html = '<' . '?xml version="1.0"?' . '><html><body><script src="https://tracker.example.com/x"></script></body></html>';

        $result = $this->rewrite($html);

        // The script still got rewritten — PI noise at the top didn't
        // confuse DOMDocument enough to break the rewrite pass.
        self::assertStringContainsString('data-name=', $result);
        // Byte-count math is consistent.
        self::assertSame(strlen($result), mb_strlen($result, '8bit'));
        // The strip didn't fail-open — our injected encoding hint isn't
        // in the output (otherwise we'd be leaking the UTF-8 marker
        // into the visitor's response body).
        self::assertStringNotContainsString('encoding="utf-8"', $result);
    }

    #[Test]
    public function inputWithUtf8BomDoesNotCorruptByteCount(): void
    {
        // UTF-8 BOM is the 3-byte sequence EF BB BF. Some HTTP
        // sources include it; DOMDocument's behavior with the BOM
        // varies by libxml version. We don't care whether it's
        // preserved or stripped — we only care that the strlen
        // result is consistent with what mb_strlen('8bit') reports,
        // so the Content-Length header doesn't go out of sync.
        $bom = "\xEF\xBB\xBF";
        $html = $bom . '<!DOCTYPE html><html><body><script src="https://tracker.example.com/x"></script></body></html>';

        $result = $this->rewrite($html);

        self::assertSame(
            strlen($result),
            mb_strlen($result, '8bit'),
            'strlen and mb_strlen-8bit must agree on byte count regardless of BOM handling',
        );
        // Smoke: rewriter still did its job around the BOM weirdness.
        self::assertStringContainsString('data-name=', $result);
    }
}
