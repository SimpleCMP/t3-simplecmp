<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\OriginMatcher;

/**
 * Origin-matcher semantics, including the path-prefix grammar that
 * lets two services share one host.
 */
final class OriginMatcherTest extends TestCase
{
    /**
     * Everything that worked before path prefixes existed must keep
     * working byte-for-byte — these cases are lifted from the four
     * private implementations this class replaced.
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function hostOnlyProvider(): array
    {
        return [
            'exact host'                 => ['youtube.com', 'youtube.com', true],
            'exact host, different'      => ['vimeo.com', 'youtube.com', false],
            'wildcard matches subdomain' => ['www.youtube.com', '*.youtube.com', true],
            'wildcard matches apex'      => ['youtube.com', '*.youtube.com', true],
            'wildcard matches deep sub'  => ['a.b.youtube.com', '*.youtube.com', true],
            'wildcard misses other'      => ['youtube.org', '*.youtube.com', false],
            // `*.youtube.com` is a suffix walk, so a host that merely
            // ENDS in the string but is a different domain must miss.
            'wildcard misses lookalike'  => ['notyoutube.com', '*.youtube.com', false],
        ];
    }

    #[Test]
    #[DataProvider('hostOnlyProvider')]
    public function hostOnlyMatchersBehaveAsBefore(string $host, string $matcher, bool $expected): void
    {
        self::assertSame($expected, OriginMatcher::matchesOne($host, $matcher));
        self::assertSame($expected, OriginMatcher::matches($host, [$matcher]));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function pathScopedProvider(): array
    {
        return [
            'maps embed hits maps claim' => [
                'www.google.com', '/maps/embed', 'www.google.com/maps/', true,
            ],
            'maps embed misses recaptcha claim' => [
                'www.google.com', '/maps/embed', 'www.google.com/recaptcha/', false,
            ],
            'recaptcha api hits recaptcha claim' => [
                'www.google.com', '/recaptcha/api.js', 'www.google.com/recaptcha/', true,
            ],
            'recaptcha api misses maps claim' => [
                'www.google.com', '/recaptcha/api.js', 'www.google.com/maps/', false,
            ],
            'directory itself counts as inside it' => [
                'www.google.com', '/maps', 'www.google.com/maps/', true,
            ],
            // The trailing slash in the claim is what stops this: a
            // bare prefix compare would accept `/mapsomething`.
            'sibling path with shared prefix misses' => [
                'www.google.com', '/mapsomething', 'www.google.com/maps/', false,
            ],
            'root path misses a path claim' => [
                'www.google.com', '/', 'www.google.com/maps/', false,
            ],
            'wrong host, right path, misses' => [
                'evil.example', '/maps/embed', 'www.google.com/maps/', false,
            ],
            'wildcard host plus path' => [
                'de.example.com', '/embed/x', '*.example.com/embed/', true,
            ],
        ];
    }

    #[Test]
    #[DataProvider('pathScopedProvider')]
    public function pathScopedMatchersRespectThePath(
        string $host,
        string $path,
        string $matcher,
        bool $expected,
    ): void {
        self::assertSame($expected, OriginMatcher::matchesOne($host, $matcher, $path));
    }

    /**
     * A caller that knows only the host (the `/v1/lookup` API queried
     * by origin, the BE detection list) must still get the candidates
     * that live on that host — answering "nothing" would be worse
     * than answering "these could apply".
     */
    #[Test]
    public function pathScopedMatcherFallsBackToHostWhenPathIsUnknown(): void
    {
        self::assertTrue(OriginMatcher::matchesOne('www.google.com', 'www.google.com/maps/'));
        self::assertTrue(OriginMatcher::matchesOne('www.google.com', 'www.google.com/recaptcha/'));
        self::assertFalse(OriginMatcher::matchesOne('maps.example', 'www.google.com/maps/'));
    }

    #[Test]
    public function splitSeparatesHostFromPath(): void
    {
        self::assertSame(['www.google.com', '/maps/'], OriginMatcher::split('www.google.com/maps/'));
        self::assertSame(['*.google.com', '/maps/'], OriginMatcher::split('*.google.com/maps/'));
        self::assertSame(['youtube.com', null], OriginMatcher::split('youtube.com'));
        // A bare trailing slash carries no information.
        self::assertSame(['youtube.com', null], OriginMatcher::split('youtube.com/'));
    }

    #[Test]
    public function isPathScopedIdentifiesTheMoreSpecificClaims(): void
    {
        self::assertTrue(OriginMatcher::isPathScoped('www.google.com/maps/'));
        self::assertFalse(OriginMatcher::isPathScoped('www.google.com'));
        self::assertFalse(OriginMatcher::isPathScoped('www.google.com/'));
    }

    #[Test]
    public function nonStringAndEmptyEntriesAreSkipped(): void
    {
        self::assertFalse(OriginMatcher::matches('youtube.com', [null, 42, '', ['x']]));
        self::assertTrue(OriginMatcher::matches('youtube.com', [null, 'youtube.com']));
    }
}
