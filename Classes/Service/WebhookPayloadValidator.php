<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Service;

/**
 * Validates webhook payloads against the SimpleCMP CMS bridge v1
 * schema (see `docs/cms-bridge-webhook.md` in the upstream repo).
 *
 * This is the "trust nothing the browser sends" boundary. The bridge
 * endpoint is semi-public by design (the browser-side sender cannot
 * hold a real secret — see `webhook_browser_secret_constraint`), so
 * strict field validation is one of the few real defenses against
 * malicious or malformed inputs.
 *
 * On invalid input, callers should return the HTTP status and message
 * from the result; the message is safe to surface to the client (it
 * names the failing field, not the expected value, to avoid handing
 * attackers an oracle).
 */
final class WebhookPayloadValidator
{
    public const int MAX_BODY_BYTES = 4096;
    private const int MAX_IDENTIFIER = 256;
    private const int MAX_SOURCE = 64;
    private const int MAX_URL = 2048;
    private const int MAX_USER_AGENT = 512;
    private const int MAX_ORIGIN = 253;
    private const int MAX_LIBRARY_VERSION = 32;
    private const int MAX_SENT_AT = 40;
    private const int MAX_COUNT = 10_000;
    private const int CLOCK_SKEW_FUTURE_MS = 60_000;
    private const int LOOKBACK_MS = 24 * 3600 * 1000;
    private const string SOURCE_REGEX = '/^[a-z0-9_-]{1,64}$/';
    private const array ALLOWED_KINDS = ['cookie', 'script', 'iframe', 'image', 'link', 'request'];

    public function validate(string $body, ?int $nowMs = null): WebhookValidationResult
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return WebhookValidationResult::failure(413, 'Payload too large');
        }
        if ($body === '') {
            return WebhookValidationResult::failure(400, 'Empty request body');
        }
        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return WebhookValidationResult::failure(400, 'Invalid JSON body');
        }
        if (!is_array($payload)) {
            return WebhookValidationResult::failure(400, 'Payload must be a JSON object');
        }
        if (($payload['schemaVersion'] ?? null) !== 1) {
            return WebhookValidationResult::failure(400, 'Unsupported schemaVersion');
        }

        $err = $this->validateSource($payload)
            ?? $this->validateSentAt($payload)
            ?? $this->validateLibrary($payload)
            ?? $this->validatePage($payload)
            ?? $this->validateDetection($payload, $nowMs ?? (int) floor(microtime(true) * 1000));

        if ($err !== null) {
            return WebhookValidationResult::failure(400, $err);
        }
        return WebhookValidationResult::success($payload);
    }

    /** @param array<string, mixed> $p */
    private function validateSource(array $p): ?string
    {
        $source = $p['source'] ?? null;
        if (!is_string($source) || preg_match(self::SOURCE_REGEX, $source) !== 1) {
            return 'Invalid source';
        }
        return null;
    }

    /** @param array<string, mixed> $p */
    private function validateSentAt(array $p): ?string
    {
        $sentAt = $p['sentAt'] ?? null;
        if (!is_string($sentAt) || $sentAt === '' || strlen($sentAt) > self::MAX_SENT_AT) {
            return 'Invalid sentAt';
        }
        if (!self::isUtf8($sentAt)) {
            return 'Invalid sentAt encoding';
        }
        return null;
    }

    /** @param array<string, mixed> $p */
    private function validateLibrary(array $p): ?string
    {
        $lib = $p['library'] ?? null;
        if (!is_array($lib) || ($lib['name'] ?? null) !== 'simplecmp') {
            return 'Invalid library.name';
        }
        $version = $lib['version'] ?? null;
        if (!is_string($version) || $version === '' || strlen($version) > self::MAX_LIBRARY_VERSION) {
            return 'Invalid library.version';
        }
        return null;
    }

    /** @param array<string, mixed> $p */
    private function validatePage(array $p): ?string
    {
        $page = $p['page'] ?? null;
        if (!is_array($page)) {
            return 'Invalid page';
        }
        $url = $page['url'] ?? null;
        if (!is_string($url) || $url === '' || strlen($url) > self::MAX_URL || !self::isHttpUrl($url)) {
            return 'Invalid page.url';
        }
        if (isset($page['referrer'])) {
            if (!is_string($page['referrer']) || strlen($page['referrer']) > self::MAX_URL || !self::isUtf8($page['referrer'])) {
                return 'Invalid page.referrer';
            }
        }
        if (isset($page['userAgent'])) {
            if (!is_string($page['userAgent']) || strlen($page['userAgent']) > self::MAX_USER_AGENT || !self::isUtf8($page['userAgent'])) {
                return 'Invalid page.userAgent';
            }
        }
        return null;
    }

    /** @param array<string, mixed> $p */
    private function validateDetection(array $p, int $nowMs): ?string
    {
        $det = $p['detection'] ?? null;
        if (!is_array($det)) {
            return 'Invalid detection';
        }
        if (!in_array($det['kind'] ?? null, self::ALLOWED_KINDS, true)) {
            return 'Invalid detection.kind';
        }
        $id = $det['identifier'] ?? null;
        if (!is_string($id) || $id === '' || strlen($id) > self::MAX_IDENTIFIER || !self::isUtf8($id)) {
            return 'Invalid detection.identifier';
        }
        if (isset($det['origin'])) {
            if (!is_string($det['origin']) || strlen($det['origin']) > self::MAX_ORIGIN || !self::isUtf8($det['origin'])) {
                return 'Invalid detection.origin';
            }
        }
        foreach (['firstSeen', 'lastSeen'] as $tsField) {
            $ts = $det[$tsField] ?? null;
            if (!is_int($ts)) {
                return 'Invalid detection.' . $tsField;
            }
            if ($ts < $nowMs - self::LOOKBACK_MS || $ts > $nowMs + self::CLOCK_SKEW_FUTURE_MS) {
                return 'Invalid detection.' . $tsField . ' (out of range)';
            }
        }
        $count = $det['count'] ?? null;
        if (!is_int($count) || $count < 1 || $count > self::MAX_COUNT) {
            return 'Invalid detection.count';
        }
        if (isset($det['firstSeenOn'])) {
            if (!is_string($det['firstSeenOn']) || strlen($det['firstSeenOn']) > self::MAX_URL || !self::isUtf8($det['firstSeenOn'])) {
                return 'Invalid detection.firstSeenOn';
            }
        }
        if (($det['status'] ?? null) !== 'unknown') {
            return 'Invalid detection.status';
        }
        return null;
    }

    private static function isUtf8(string $s): bool
    {
        return preg_match('//u', $s) === 1;
    }

    private static function isHttpUrl(string $s): bool
    {
        if (!self::isUtf8($s)) {
            return false;
        }
        $scheme = parse_url($s, PHP_URL_SCHEME);
        return $scheme === 'http' || $scheme === 'https';
    }
}
