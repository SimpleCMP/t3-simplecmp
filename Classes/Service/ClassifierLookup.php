<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use SimpleCMP\ServicesLibrary\ServicesLibrary;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;

/**
 * Unified cookie/origin lookup that consults BOTH the admin-curated
 * registry (`tx_t3simplecmp_service`) and the bundled
 * `simplecmp/services-library` JSON catalog.
 *
 * The two sources play distinct roles in the new (post-fe_visible)
 * architecture:
 *
 * - **Registry** = admin's curated service surface. Every row appears
 *   in the FE banner. Populated only via deliberate per-entry actions
 *   (Übernehmen / Anpassen / Kuratieren / manual TCA create).
 * - **Library** = bundled read-only reference catalog. Adds classifier
 *   coverage without bloating the FE banner. Loaded directly from
 *   `vendor/simplecmp/services-library/data/services/*.json`.
 *
 * The Service-DB middleware (`/api/simplecmp/v1/lookup`) uses this
 * class so any cookie covered by either source classifies as `known`.
 * Without library coverage the recorder + bridge would fire for every
 * common third-party cookie even though the data exists locally.
 *
 * Registry wins on conflict: if an admin has explicitly edited a row
 * for a service whose `service_id` matches a library entry, that
 * (admin-edited) row is returned and the library entry is ignored.
 */
final readonly class ClassifierLookup
{
    public function __construct(
        private ServiceRepository $registry,
    ) {
    }

    /**
     * Return services matching the given cookie or origin from registry
     * + library, deduplicated by `service_id`.
     *
     * @return list<array<string, mixed>> protocol-shaped rows
     */
    public function lookup(?string $cookie, ?string $origin): array
    {
        if ($cookie === null && $origin === null) {
            return [];
        }

        $byId = [];
        foreach ($this->registry->lookup($cookie, $origin) as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }

        foreach (ServicesLibrary::services() as $entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '' || isset($byId[$id])) {
                continue;
            }
            $cookies = $entry['matches']['cookies'] ?? [];
            $origins = $entry['matches']['origins'] ?? [];
            if (
                ($cookie !== null && self::cookieMatches($cookie, is_array($cookies) ? $cookies : []))
                || ($origin !== null && self::originMatches($origin, is_array($origins) ? $origins : []))
            ) {
                $byId[$id] = $entry;
            }
        }

        return array_values($byId);
    }

    /**
     * Test whether a cookie name matches any of the matchers. Mirrors
     * `ServiceRepository::cookieMatches()` — duplicated here because
     * the repository's matchers are tied to its DB-row decoding path.
     * If a refactor later extracts a shared `MatcherEngine`, both
     * callers fold into it.
     *
     * @param array<int, mixed> $matchers
     */
    public static function cookieMatches(string $cookieName, array $matchers): bool
    {
        foreach ($matchers as $matcher) {
            if (is_string($matcher) && self::cookieNameMatches($cookieName, $matcher)) {
                return true;
            }
            // ADR-0010 host-qualified form: object with `name` + `requireOrigin`.
            // The middleware can only validate the name part; the recorder
            // applies the requireOrigin filter at classification time.
            if (
                is_array($matcher)
                && isset($matcher['name'], $matcher['requireOrigin'])
                && is_string($matcher['name'])
                && self::cookieNameMatches($cookieName, $matcher['name'])
            ) {
                return true;
            }
        }
        return false;
    }

    private static function cookieNameMatches(string $cookieName, string $matcher): bool
    {
        if (strlen($matcher) >= 2 && $matcher[0] === '/' && $matcher[-1] === '/') {
            $pattern = '/' . substr($matcher, 1, -1) . '/';
            return @preg_match($pattern, $cookieName) === 1;
        }
        return $matcher === $cookieName;
    }

    /**
     * @param array<int, mixed> $matchers
     */
    public static function originMatches(string $origin, array $matchers): bool
    {
        foreach ($matchers as $matcher) {
            if (!is_string($matcher)) {
                continue;
            }
            if (str_starts_with($matcher, '*.')) {
                $suffix = substr($matcher, 1);
                if (str_ends_with($origin, $suffix) || $origin === substr($suffix, 1)) {
                    return true;
                }
                continue;
            }
            if ($matcher === $origin) {
                return true;
            }
        }
        return false;
    }
}
