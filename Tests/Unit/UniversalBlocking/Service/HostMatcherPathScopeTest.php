<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\UniversalBlocking\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\UniversalBlocking\Service\HostMatcher;

/**
 * Resolution order when several services claim the same host.
 *
 * Pinned to an injected fixture rather than the bundled library, so
 * these assertions keep meaning the same thing after a
 * services-library sync.
 */
final class HostMatcherPathScopeTest extends TestCase
{
    /**
     * Mirrors the real shape of the collision this feature exists
     * for: two products on `www.google.com`, plus a catch-all
     * wildcard over the whole apex.
     *
     * @return list<array<string, mixed>>
     */
    private function googleFixture(): array
    {
        return [
            [
                'id' => 'google-maps',
                'matches' => ['origins' => ['maps.google.com', 'www.google.com/maps/']],
            ],
            [
                'id' => 'google-recaptcha',
                'matches' => ['origins' => ['www.google.com/recaptcha/', 'recaptcha.net']],
            ],
            [
                'id' => 'google',
                'matches' => ['origins' => ['google.com', '*.google.com']],
            ],
        ];
    }

    #[Test]
    public function pathDecidesBetweenTwoProductsOnOneHost(): void
    {
        $matcher = new HostMatcher([], true, $this->googleFixture());

        self::assertSame(
            ['service' => 'google-maps', 'source' => 'library'],
            $matcher->resolve('www.google.com', '/maps/embed'),
        );
        self::assertSame(
            ['service' => 'google-recaptcha', 'source' => 'library'],
            $matcher->resolve('www.google.com', '/recaptcha/api.js'),
        );
    }

    /**
     * The regression this feature was written for: before path
     * scoping, `*.google.com` on the reCAPTCHA entry swallowed the
     * Maps embed and the banner asked visitors to consent to
     * reCAPTCHA in order to see a map.
     */
    #[Test]
    public function pathScopedClaimBeatsAWildcardOverTheSameApex(): void
    {
        $matcher = new HostMatcher([], true, $this->googleFixture());

        self::assertSame('google-maps', $matcher->match('www.google.com', '/maps/embed?pb=x'));
    }

    #[Test]
    public function exactHostStillBeatsWildcard(): void
    {
        $matcher = new HostMatcher([], true, $this->googleFixture());

        self::assertSame('google-maps', $matcher->match('maps.google.com', '/anything'));
    }

    /**
     * A host under the apex that no path claim covers falls through
     * to the catch-all, rather than becoming an unknown host with no
     * visitor recourse.
     */
    #[Test]
    public function unclaimedSubdomainFallsThroughToTheWildcard(): void
    {
        $matcher = new HostMatcher([], true, $this->googleFixture());

        self::assertSame('google', $matcher->match('docs.google.com', '/document/d/1/preview'));
        self::assertSame('google', $matcher->match('www.google.com', '/search'));
    }

    /**
     * Without a path the rewriter cannot verify a path claim, so it
     * must not let one win over the verifiable wildcard.
     */
    #[Test]
    public function pathClaimsAreSkippedWhenNoPathIsSupplied(): void
    {
        $matcher = new HostMatcher([], true, $this->googleFixture());

        self::assertSame('google', $matcher->match('www.google.com'));
    }

    #[Test]
    public function allowlistStillWinsOverEverything(): void
    {
        $matcher = new HostMatcher(['www.google.com'], true, $this->googleFixture());

        self::assertNull($matcher->resolve('www.google.com', '/maps/embed'));
    }

    #[Test]
    public function unknownHostStillFallsBackToTheHostItself(): void
    {
        $matcher = new HostMatcher([], true, $this->googleFixture());

        self::assertSame(
            ['service' => 'tracker.example', 'source' => 'host'],
            $matcher->resolve('tracker.example', '/pixel.gif'),
        );
    }

    #[Test]
    public function sizeCountsPathScopedClaimsSeparately(): void
    {
        $size = (new HostMatcher([], true, $this->googleFixture()))->size();

        // maps.google.com, recaptcha.net, google.com
        self::assertSame(3, $size['exact']);
        self::assertSame(1, $size['wildcards']);   // *.google.com
        self::assertSame(2, $size['pathScoped']);  // /maps/, /recaptcha/
    }
}
