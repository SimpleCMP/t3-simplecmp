<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Unit\UniversalBlocking\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WapplerSystems\SimpleCmpTypo3\UniversalBlocking\Service\HostMatcher;

/**
 * Locks the lookup semantics of the universal-blocking host matcher.
 *
 * The matcher is the single source of truth for "is this host a
 * third-party we should rewrite, or should it pass through?". The
 * rewriter middleware feeds every `<script src>` / `<iframe src>` etc.
 * host through this; getting the precedence wrong here either leaks
 * third-party requests (allowlist ignored) or over-blocks the page
 * (allowlist not honored). Both are page-breaking regressions, so
 * they get explicit tests.
 */
final class HostMatcherTest extends TestCase
{
    #[Test]
    public function knownLibraryHostResolvesToServiceId(): void
    {
        $matcher = new HostMatcher();

        // vimeo entries are in the curated core subset that ships
        // with the ext (not just the full 369-entry registry), so
        // these assertions stay green even when phpunit runs against
        // the ext-local vendor dir rather than the consuming
        // project's larger one.
        self::assertSame('vimeo', $matcher->match('player.vimeo.com'));
        self::assertSame('vimeo', $matcher->match('foo.vimeocdn.com'));
        self::assertSame(
            'google-tag-manager',
            $matcher->match('www.googletagmanager.com'),
        );
    }

    #[Test]
    public function unknownHostFallsBackToHostAsSyntheticServiceId(): void
    {
        // Default mode is `blockAllThirdParty = true` — hosts the
        // library doesn't recognise still get rewritten, with the
        // host itself standing in as the synthetic service id. Admin
        // surfaces them in the detection table and Kuratieren'd them
        // from there.
        $matcher = new HostMatcher();

        self::assertSame('example.invalid', $matcher->match('example.invalid'));
        self::assertSame('analytics.megacorp.io', $matcher->match('analytics.megacorp.io'));
    }

    #[Test]
    public function resolveReturnsLibrarySourceForKnownHosts(): void
    {
        // `data-blocked-source: library` drives the FE contextual-notice
        // into its "Ja" mode — visitor recognises the brand from the
        // library entry, can grant one-time consent.
        $matcher = new HostMatcher();

        self::assertSame(
            ['service' => 'vimeo', 'source' => 'library'],
            $matcher->resolve('player.vimeo.com'),
        );
        self::assertSame(
            ['service' => 'google-tag-manager', 'source' => 'library'],
            $matcher->resolve('www.googletagmanager.com'),
        );
    }

    #[Test]
    public function resolveReturnsHostSourceForUnknownThirdParty(): void
    {
        // `data-blocked-source: host` drives the FE notice into
        // informational-only mode — no buttons because visitor has no
        // basis to grant informed consent to an unknown vendor.
        $matcher = new HostMatcher();

        self::assertSame(
            ['service' => 'example.invalid', 'source' => 'host'],
            $matcher->resolve('example.invalid'),
        );
    }

    #[Test]
    public function resolveReturnsNullForAllowlistedAndEmptyHosts(): void
    {
        $matcher = new HostMatcher(['cdn.example.com']);

        self::assertNull($matcher->resolve(''));
        self::assertNull($matcher->resolve('cdn.example.com'));
    }

    #[Test]
    public function legacyNarrowModeReturnsNullForUnknownHosts(): void
    {
        // The legacy library-only behaviour stays available behind an
        // explicit constructor flag for callers that want the narrower
        // semantics (e.g. a future "only block library-known"
        // posture).
        $matcher = new HostMatcher([], blockAllThirdParty: false);

        self::assertNull($matcher->match('example.invalid'));
        // Library hits still resolve.
        self::assertSame('vimeo', $matcher->match('player.vimeo.com'));
    }

    #[Test]
    public function emptyHostReturnsNull(): void
    {
        $matcher = new HostMatcher();

        self::assertNull($matcher->match(''));
    }

    #[Test]
    public function exactAllowlistEntryOverridesLibraryMatch(): void
    {
        // The admin allowlisted `player.vimeo.com` specifically (e.g.
        // they self-host an embed gateway behind that exact host).
        // The library would otherwise classify it as Vimeo; the admin
        // wins.
        $matcher = new HostMatcher(['player.vimeo.com']);

        self::assertNull($matcher->match('player.vimeo.com'));
        // A sibling subdomain still rewrites; the entry was exact,
        // not a wildcard.
        self::assertSame('vimeo', $matcher->match('foo.vimeocdn.com'));
    }

    #[Test]
    public function wildcardAllowlistEntryCoversApexAndSubdomains(): void
    {
        $matcher = new HostMatcher(['*.vimeo.com']);

        self::assertNull($matcher->match('vimeo.com'));
        self::assertNull($matcher->match('player.vimeo.com'));
        self::assertNull($matcher->match('m.vimeo.com'));
    }

    #[Test]
    public function allowlistDoesNotShadowUnrelatedHosts(): void
    {
        $matcher = new HostMatcher(['*.example.com']);

        // example.com is allowlisted; the GTM host is still
        // classified by the library.
        self::assertNull($matcher->match('cdn.example.com'));
        self::assertSame(
            'google-tag-manager',
            $matcher->match('www.googletagmanager.com'),
        );
    }

    #[Test]
    public function allowlistEmptyAndNonStringEntriesAreIgnored(): void
    {
        // Stringlist input from the Site Set may contain blank lines
        // or non-string values after normalisation; the matcher must
        // tolerate them without throwing.
        /** @psalm-suppress InvalidArgument intentional bad inputs */
        $matcher = new HostMatcher(['', '*.example.com']);

        self::assertNull($matcher->match('cdn.example.com'));
        self::assertSame('vimeo', $matcher->match('player.vimeo.com'));
    }

    #[Test]
    public function sizeReportsIndexCounts(): void
    {
        $matcher = new HostMatcher();
        $size = $matcher->size();

        self::assertArrayHasKey('exact', $size);
        self::assertArrayHasKey('wildcards', $size);
        self::assertGreaterThan(0, $size['exact'] + $size['wildcards']);
    }
}
