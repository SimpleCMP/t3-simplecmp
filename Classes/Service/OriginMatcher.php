<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * The one implementation of origin-matcher semantics.
 *
 * Four classes used to carry byte-identical private copies of this
 * logic (ClassifierLookup, DetectionListPresenter, ServiceCurator,
 * ServiceRepository). They now all delegate here, so a change to the
 * grammar cannot land in three places and miss the fourth.
 *
 * ## Grammar
 *
 *     matcher     := host-pattern [ path-prefix ]
 *     host-pattern := exact-host | "*." apex
 *     path-prefix  := "/" …
 *
 * Examples:
 *
 * | matcher                      | matches                                   |
 * |------------------------------|-------------------------------------------|
 * | `maps.google.com`            | exactly that host, any path               |
 * | `*.youtube.com`              | `youtube.com` (apex) and every subdomain  |
 * | `www.google.com/maps/`       | that host, only below `/maps/`            |
 * | `*.google.com/recaptcha/`    | apex + subdomains, only below `/recaptcha/` |
 *
 * ## Why path prefixes exist
 *
 * Some hosts serve two unrelated products that need different
 * consent treatment. `www.google.com` is the sharpest case: it
 * carries BOTH the Maps embed (`/maps/embed?pb=…`, a marketing-
 * purpose iframe) and the reCAPTCHA loader (`/recaptcha/api.js`, a
 * functional script). A host-only matcher must mislabel one of them
 * — and mislabelling is not cosmetic here: consent to reCAPTCHA is
 * not consent to Maps, and the banner claims otherwise.
 *
 * ## Degradation when no path is known
 *
 * Callers that only know a host (the `/v1/lookup` API queried by
 * origin, the BE detection list) pass `$path = null`. A path-scoped
 * matcher then matches on its host part alone. That is deliberate:
 * such a caller asks "which services *can* live on this host", and
 * answering "none" would be a regression. Callers that DO know the
 * path (the universal-blocking rewriter, which has the full URL)
 * get exact attribution.
 */
final class OriginMatcher
{
    /**
     * True when any matcher in the list matches host (+ path).
     *
     * @param array<int, mixed> $matchers raw library/registry entries;
     *                                    non-strings are skipped rather
     *                                    than tripping a type error, as
     *                                    the data is JSON-sourced
     */
    public static function matches(string $host, array $matchers, ?string $path = null): bool
    {
        foreach ($matchers as $matcher) {
            if (!is_string($matcher) || $matcher === '') {
                continue;
            }
            if (self::matchesOne($host, $matcher, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when this single matcher matches host (+ path).
     */
    public static function matchesOne(string $host, string $matcher, ?string $path = null): bool
    {
        [$hostPattern, $pathPrefix] = self::split($matcher);
        if (!self::hostMatches($host, $hostPattern)) {
            return false;
        }
        if ($pathPrefix === null) {
            return true;
        }
        // Host-only caller: cannot verify the path half, so the host
        // half decides (see "Degradation" in the class docblock).
        if ($path === null) {
            return true;
        }
        return self::pathMatches($path, $pathPrefix);
    }

    /**
     * Split a matcher into its host pattern and its optional path
     * prefix. A bare trailing slash (`example.com/`) carries no
     * information and is normalised away to "no path prefix".
     *
     * @return array{0: string, 1: string|null}
     */
    public static function split(string $matcher): array
    {
        $slash = strpos($matcher, '/');
        if ($slash === false) {
            return [$matcher, null];
        }
        $hostPattern = substr($matcher, 0, $slash);
        $pathPrefix = substr($matcher, $slash);
        return [$hostPattern, $pathPrefix === '/' ? null : $pathPrefix];
    }

    /**
     * True when the matcher carries a path prefix — i.e. it is more
     * specific than a bare host claim and should be resolved first.
     */
    public static function isPathScoped(string $matcher): bool
    {
        return self::split($matcher)[1] !== null;
    }

    /**
     * Exact host, or `*.apex` matching the apex itself and every
     * subdomain below it. Unchanged from the pre-path behaviour.
     */
    public static function hostMatches(string $host, string $hostPattern): bool
    {
        if (str_starts_with($hostPattern, '*.')) {
            $suffix = substr($hostPattern, 1); // ".example.com"
            return str_ends_with($host, $suffix) || $host === substr($suffix, 1);
        }
        return $hostPattern === $host;
    }

    /**
     * Prefix match on path segments.
     *
     * `/maps/` matches `/maps/embed` and also the bare `/maps`, so a
     * library entry does not have to list both spellings. It does NOT
     * match `/mapsomething`, which a naive `str_starts_with` on the
     * un-slashed prefix would wrongly accept.
     */
    public static function pathMatches(string $path, string $pathPrefix): bool
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        if (str_starts_with($path, $pathPrefix)) {
            return true;
        }
        // `/maps/` should also accept the directory itself, `/maps`.
        return str_ends_with($pathPrefix, '/') && $path === rtrim($pathPrefix, '/');
    }
}
