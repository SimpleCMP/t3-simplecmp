<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Strips visitor-identifying data from CMS-bridge detections before they
 * are stored.
 *
 * The browser library does the same since simplecmp 0.5.x (bundled with
 * 14.0.9–14.0.11), but the receiver must not rely on it: cached pages and
 * other integrations keep sending the old shape. Resource URLs carry
 * tracking ids in their query string (Bing `mid`/`msclkid`, GA `cid`),
 * referrers carry campaign click ids (`gclid`, `utm_*`), and software on
 * the visitor's device injects resources with per-install ids
 * (`moz-extension://<uuid>`, Kaspersky `/<guid>/`).
 *
 * Pure functions, no state — see `DetectionRepository::ingestOne()`.
 */
final class DetectionSanitizer
{
    /**
     * Hosts whose resources are injected by software on the visitor's
     * device, not by the site. Matched as a hostname suffix. Mirrors
     * `VISITOR_INJECTED_HOST_SUFFIXES` in the browser library.
     */
    private const VISITOR_INJECTED_HOST_SUFFIXES = ['.kaspersky-labs.com'];

    /**
     * Normalised identifier, or null when the detection must not be stored.
     *
     * Cookie names are kept as they are. Every other kind is a resource
     * URL: only http(s) URLs belong to the site, and they are stored
     * without query string and fragment.
     */
    public static function identifier(string $kind, string $identifier, ?string $origin): ?string
    {
        if ($kind === 'cookie') {
            return $identifier;
        }
        if (preg_match('#^https?://#i', $identifier) !== 1) {
            return null;
        }
        if (self::isVisitorInjected($origin) || self::isVisitorInjected(parse_url($identifier, PHP_URL_HOST) ?: null)) {
            return null;
        }
        $stripped = self::stripQuery($identifier);
        return $stripped === '' ? null : $stripped;
    }

    /** URL-like string without query string and fragment; null stays null. */
    public static function stripQuery(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $cut = strcspn($url, '?#');
        return substr($url, 0, $cut);
    }

    /**
     * What is kept in the `payload` column: the envelope metadata without
     * the other detections of the batch and without the user agent (it has
     * its own column), plus this detection — all URLs stripped.
     *
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $detection
     * @return array{envelope: array<string, mixed>, detection: array<string, mixed>}
     */
    public static function payload(array $envelope, array $detection, string $identifier): array
    {
        unset($envelope['detections']);
        if (isset($envelope['page']) && is_array($envelope['page'])) {
            $page = $envelope['page'];
            unset($page['userAgent']);
            foreach (['url', 'referrer'] as $key) {
                if (isset($page[$key]) && is_string($page[$key])) {
                    $page[$key] = self::stripQuery($page[$key]);
                }
            }
            $envelope['page'] = $page;
        }
        $detection['identifier'] = $identifier;
        if (isset($detection['firstSeenOn']) && is_string($detection['firstSeenOn'])) {
            $detection['firstSeenOn'] = self::stripQuery($detection['firstSeenOn']);
        }
        return ['envelope' => $envelope, 'detection' => $detection];
    }

    private static function isVisitorInjected(?string $host): bool
    {
        if ($host === null || $host === '') {
            return false;
        }
        $host = strtolower($host);
        foreach (self::VISITOR_INJECTED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        return false;
    }
}
