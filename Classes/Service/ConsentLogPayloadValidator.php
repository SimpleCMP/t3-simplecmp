<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Validates payloads sent to `POST /api/simplecmp/v1/consent-log`
 * (Phase 2 audit trail). Mirrors {@see WebhookPayloadValidator}'s
 * defensive shape: cap body length, JSON_THROW on parse, enum / regex
 * each field, terse error messages without leaking the expected value.
 *
 * Schema (v1):
 *
 *   {
 *     "schemaVersion": 1,
 *     "source":        "<storageName-derived, charset ^[a-z0-9_-]{1,64}$>",
 *     "versionHash":   "<64-char sha256 hex — Phase-1 snapshot version>",
 *     "visitorUuid":   "<UUID v4 — server hashes with bridge_secret>",
 *     "decisions":     { "<service-id>": true|false, ... },
 *     "decisionType":  "accept" | "decline" | "script" | "partial",
 *     "pageHost":      "<optional, host only>",
 *     "uaFamily":      "<optional, coarse enum>"
 *   }
 *
 * Maximum body 4 KB — consent payloads are tiny (a handful of services
 * × a bool per service); anything larger is malformed or hostile.
 */
final class ConsentLogPayloadValidator
{
    public const int MAX_BODY_BYTES = 4096;
    public const int MAX_DECISIONS_COUNT = 200;
    private const string SOURCE_REGEX = '/^[a-z0-9_-]{1,64}$/';
    private const string HASH_REGEX = '/^[0-9a-f]{64}$/';
    private const string UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string SERVICE_ID_REGEX = '/^[a-zA-Z0-9_.-]{1,100}$/';
    private const string PAGE_HOST_REGEX = '/^[a-z0-9.-]{1,253}$/';
    private const array ALLOWED_DECISION_TYPES = ['accept', 'decline', 'script', 'partial'];
    private const array ALLOWED_UA_FAMILIES = ['chrome', 'firefox', 'safari', 'edge', 'opera', 'other'];

    public function validate(string $body): WebhookValidationResult
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return WebhookValidationResult::failure(413, 'Payload too large');
        }
        if ($body === '') {
            return WebhookValidationResult::failure(400, 'Empty request body');
        }
        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return WebhookValidationResult::failure(400, 'Invalid JSON body');
        }
        if (!is_array($payload)) {
            return WebhookValidationResult::failure(400, 'Payload must be a JSON object');
        }
        if (($payload['schemaVersion'] ?? null) !== 1) {
            return WebhookValidationResult::failure(400, 'Unsupported schemaVersion');
        }

        $err = $this->validateStringRegex($payload, 'source', self::SOURCE_REGEX, 'source')
            ?? $this->validateStringRegex($payload, 'versionHash', self::HASH_REGEX, 'versionHash')
            ?? $this->validateStringRegex($payload, 'visitorUuid', self::UUID_REGEX, 'visitorUuid')
            ?? $this->validateDecisions($payload)
            ?? $this->validateDecisionType($payload)
            ?? $this->validateOptionalPageHost($payload)
            ?? $this->validateOptionalUaFamily($payload);

        if ($err !== null) {
            return WebhookValidationResult::failure(400, $err);
        }
        return WebhookValidationResult::success($payload);
    }

    /** @param array<string, mixed> $p */
    private function validateStringRegex(array $p, string $field, string $regex, string $errorLabel): ?string
    {
        $value = $p[$field] ?? null;
        if (!is_string($value) || preg_match($regex, $value) !== 1) {
            return 'Invalid ' . $errorLabel;
        }
        return null;
    }

    /** @param array<string, mixed> $p */
    private function validateDecisions(array $p): ?string
    {
        $decisions = $p['decisions'] ?? null;
        if (!is_array($decisions)) {
            return 'Invalid decisions';
        }
        if (count($decisions) > self::MAX_DECISIONS_COUNT) {
            return 'Too many decisions';
        }
        foreach ($decisions as $serviceId => $value) {
            if (!is_string($serviceId) || preg_match(self::SERVICE_ID_REGEX, $serviceId) !== 1) {
                return 'Invalid decisions key';
            }
            if (!is_bool($value)) {
                return 'Invalid decisions value';
            }
        }
        return null;
    }

    /** @param array<string, mixed> $p */
    private function validateDecisionType(array $p): ?string
    {
        $type = $p['decisionType'] ?? null;
        if (!is_string($type) || !in_array($type, self::ALLOWED_DECISION_TYPES, true)) {
            return 'Invalid decisionType';
        }
        return null;
    }

    /** @param array<string, mixed> $p */
    private function validateOptionalPageHost(array $p): ?string
    {
        if (!array_key_exists('pageHost', $p) || $p['pageHost'] === null) {
            return null;
        }
        if (!is_string($p['pageHost']) || preg_match(self::PAGE_HOST_REGEX, strtolower($p['pageHost'])) !== 1) {
            return 'Invalid pageHost';
        }
        return null;
    }

    /** @param array<string, mixed> $p */
    private function validateOptionalUaFamily(array $p): ?string
    {
        if (!array_key_exists('uaFamily', $p) || $p['uaFamily'] === null) {
            return null;
        }
        if (!is_string($p['uaFamily']) || !in_array(strtolower($p['uaFamily']), self::ALLOWED_UA_FAMILIES, true)) {
            return 'Invalid uaFamily';
        }
        return null;
    }
}
