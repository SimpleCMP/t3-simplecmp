<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\DetectionSanitizer;

/**
 * Receiver-side stripping of visitor-identifying data, independent of
 * what the browser library already strips.
 */
final class DetectionSanitizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: ?string, 3: ?string}>
     */
    public static function identifierProvider(): array
    {
        return [
            'cookie kept as is, even with ?' => ['cookie', 'odd?name', null, 'odd?name'],
            'request url loses query' => [
                'request',
                'https://bat.bing.com/actionp/0?ti=1&mid=abc&msclkid=x',
                'bat.bing.com',
                'https://bat.bing.com/actionp/0',
            ],
            'script url loses fragment' => ['script', 'https://cdn.example.test/a.js#v', null, 'https://cdn.example.test/a.js'],
            'plain url unchanged' => ['link', 'https://fonts.googleapis.com/css2', null, 'https://fonts.googleapis.com/css2'],
            'firefox extension dropped' => ['script', 'moz-extension://d4518658-2afa/content.js', null, null],
            'chrome extension dropped' => ['iframe', 'chrome-extension://caljgklbbfbcjjanaijlacgncafpegll/x.html', null, null],
            'webkit masked url dropped' => ['image', 'webkit-masked-url://hidden/', null, null],
            'kaspersky by origin dropped' => [
                'script',
                'https://gc.kes.v2.scr.kaspersky-labs.com/0BE8F50F-5E01/main.js',
                'gc.kes.v2.scr.Kaspersky-Labs.com',
                null,
            ],
            'kaspersky by url host dropped' => ['request', 'https://me.kis.v2.scr.kaspersky-labs.com/38390218/x', null, null],
        ];
    }

    #[Test]
    #[DataProvider('identifierProvider')]
    public function identifierIsNormalisedOrDropped(string $kind, string $identifier, ?string $origin, ?string $expected): void
    {
        self::assertSame($expected, DetectionSanitizer::identifier($kind, $identifier, $origin));
    }

    #[Test]
    public function stripQueryCutsAtTheFirstQueryOrFragmentMarker(): void
    {
        self::assertSame('https://www.example.test/landing/', DetectionSanitizer::stripQuery('https://www.example.test/landing/?gclid=g#top'));
        self::assertSame('/de/home/', DetectionSanitizer::stripQuery('/de/home/#a?b'));
        self::assertSame('https://a.test/', DetectionSanitizer::stripQuery('https://a.test/'));
        self::assertNull(DetectionSanitizer::stripQuery(null));
    }

    #[Test]
    public function payloadKeepsOnlyThisDetectionAndStripsUrls(): void
    {
        $envelope = [
            'schemaVersion' => 2,
            'source' => 'main',
            'page' => [
                'url' => 'https://www.example.test/de/?utm_source=x',
                'referrer' => 'https://www.example.test/landing/?gclid=g',
                'userAgent' => 'Mozilla/5.0',
            ],
            'library' => ['name' => 'simplecmp', 'version' => '0.5.0'],
            'detections' => [
                ['kind' => 'request', 'identifier' => 'https://bat.bing.com/actionp/0?mid=other'],
                ['kind' => 'cookie', 'identifier' => '_ga'],
            ],
        ];
        $detection = [
            'kind' => 'request',
            'identifier' => 'https://bat.bing.com/actionp/0?mid=abc',
            'firstSeenOn' => '/de/?gclid=g',
        ];

        $payload = DetectionSanitizer::payload($envelope, $detection, 'https://bat.bing.com/actionp/0');

        self::assertArrayNotHasKey('detections', $payload['envelope']);
        self::assertArrayNotHasKey('userAgent', $payload['envelope']['page']);
        self::assertSame('https://www.example.test/de/', $payload['envelope']['page']['url']);
        self::assertSame('https://www.example.test/landing/', $payload['envelope']['page']['referrer']);
        self::assertSame('main', $payload['envelope']['source']);
        self::assertSame('https://bat.bing.com/actionp/0', $payload['detection']['identifier']);
        self::assertSame('/de/', $payload['detection']['firstSeenOn']);
        self::assertStringNotContainsString('mid=', (string) json_encode($payload));
    }
}
