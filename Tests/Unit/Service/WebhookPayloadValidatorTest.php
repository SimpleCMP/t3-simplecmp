<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\WebhookPayloadValidator;

final class WebhookPayloadValidatorTest extends TestCase
{
    private WebhookPayloadValidator $validator;
    private int $nowMs;

    protected function setUp(): void
    {
        $this->validator = new WebhookPayloadValidator();
        $this->nowMs = (int) floor(microtime(true) * 1000);
    }

    // --- envelope ---------------------------------------------------------

    #[Test]
    public function rejectsBodyOverMaxBytes(): void
    {
        $body = '{"x":"' . str_repeat('a', WebhookPayloadValidator::MAX_BODY_BYTES) . '"}';
        $result = $this->validator->validate($body, $this->nowMs);
        self::assertFalse($result->valid);
        self::assertSame(413, $result->status);
    }

    #[Test]
    public function rejectsEmptyBody(): void
    {
        $result = $this->validator->validate('', $this->nowMs);
        self::assertFalse($result->valid);
        self::assertSame(400, $result->status);
    }

    #[Test]
    public function rejectsInvalidJson(): void
    {
        $result = $this->validator->validate('not json', $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('JSON', $result->message);
    }

    #[Test]
    public function rejectsJsonScalar(): void
    {
        $result = $this->validator->validate('42', $this->nowMs);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function rejectsMissingSchemaVersion(): void
    {
        $result = $this->validator->validate('{}', $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('schemaVersion', $result->message);
    }

    #[Test]
    public function rejectsUnsupportedSchemaVersion(): void
    {
        // v1 (legacy) and any other value are rejected — receiver only
        // accepts the batched v2 schema.
        $result = $this->validator->validate('{"schemaVersion":1}', $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('schemaVersion', $result->message);
    }

    #[Test]
    public function acceptsCanonicalValidPayload(): void
    {
        $payload = $this->validPayload();
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertTrue($result->valid, $result->message);
        self::assertSame('test-site', $result->payload['source']);
        self::assertCount(1, $result->payload['detections']);
    }

    #[Test]
    public function rejectsSourceWithIllegalCharacters(): void
    {
        $payload = $this->validPayload();
        $payload['source'] = 'Test-Site!';
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('source', $result->message);
    }

    #[Test]
    public function rejectsSourceLongerThanLimit(): void
    {
        $payload = $this->validPayload();
        $payload['source'] = str_repeat('a', 65);
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function rejectsLibraryNotSimplecmp(): void
    {
        $payload = $this->validPayload();
        $payload['library']['name'] = 'klaro';
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('library', $result->message);
    }

    #[Test]
    public function rejectsNonHttpPageUrl(): void
    {
        $payload = $this->validPayload();
        $payload['page']['url'] = 'javascript:alert(1)';
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('page.url', $result->message);
    }

    // --- batched detections ----------------------------------------------

    #[Test]
    public function acceptsMultipleDetectionsInOneBatch(): void
    {
        $payload = $this->validPayload();
        $payload['detections'][] = $this->validDetection(['identifier' => '_another']);
        $payload['detections'][] = $this->validDetection(['identifier' => '_third', 'status' => 'known']);
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertTrue($result->valid, $result->message);
        self::assertCount(3, $result->payload['detections']);
    }

    #[Test]
    public function rejectsMissingDetectionsArray(): void
    {
        $payload = $this->validPayload();
        unset($payload['detections']);
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('detections', $result->message);
    }

    #[Test]
    public function rejectsEmptyDetectionsArray(): void
    {
        $payload = $this->validPayload();
        $payload['detections'] = [];
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('detections', $result->message);
    }

    #[Test]
    public function rejectsDetectionsAsObjectInsteadOfList(): void
    {
        // Hand-craft a body where `detections` is an object with a
        // non-numeric key — json_decode would turn `{"0": x}` into a
        // PHP list, so we use a string key to keep it associative.
        $envelope = $this->validPayload();
        unset($envelope['detections']);
        $body = json_encode($envelope);
        $body = substr($body, 0, -1) // drop closing }
            . ',"detections":{"foo":' . json_encode($this->validDetection()) . '}}';
        $result = $this->validator->validate($body, $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('list', $result->message);
    }

    #[Test]
    public function rejectsBatchAboveMaxSize(): void
    {
        $payload = $this->validPayload();
        for ($i = 0; $i < 51; $i++) {
            $payload['detections'][] = $this->validDetection(['identifier' => '_n_' . $i]);
        }
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('Too many detections', $result->message);
    }

    // --- per-detection ---------------------------------------------------

    #[Test]
    public function rejectsDetectionKindNotInEnum(): void
    {
        $payload = $this->validPayload();
        $payload['detections'][0]['kind'] = 'totally-new-kind';
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('kind', $result->message);
    }

    #[Test]
    public function rejectsIdentifierOver256Bytes(): void
    {
        $payload = $this->validPayload();
        $payload['detections'][0]['identifier'] = str_repeat('a', 257);
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('identifier', $result->message);
    }

    #[Test]
    public function rejectsTimestampMoreThan24HoursOld(): void
    {
        $payload = $this->validPayload();
        $payload['detections'][0]['firstSeen'] = $this->nowMs - (25 * 3600 * 1000);
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('firstSeen', $result->message);
    }

    #[Test]
    public function rejectsTimestampFarInFuture(): void
    {
        $payload = $this->validPayload();
        $payload['detections'][0]['firstSeen'] = $this->nowMs + 120_000;
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function acceptsTimestampWithinClockSkew(): void
    {
        $payload = $this->validPayload();
        $payload['detections'][0]['firstSeen'] = $this->nowMs + 30_000;
        $payload['detections'][0]['lastSeen'] = $this->nowMs + 30_000;
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertTrue($result->valid, $result->message);
    }

    #[Test]
    public function rejectsCountOutOfRange(): void
    {
        $payload = $this->validPayload();
        $payload['detections'][0]['count'] = 100_000;
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);

        $payload['detections'][0]['count'] = 0;
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function acceptsKnownAndUnknownStatus(): void
    {
        foreach (['known', 'unknown'] as $status) {
            $payload = $this->validPayload();
            $payload['detections'][0]['status'] = $status;
            $result = $this->validator->validate(json_encode($payload), $this->nowMs);
            self::assertTrue($result->valid, "status={$status}: {$result->message}");
        }
    }

    #[Test]
    public function rejectsStatusOutsideKnownOrUnknown(): void
    {
        $payload = $this->validPayload();
        $payload['detections'][0]['status'] = 'maybe';
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('status', $result->message);
    }

    #[Test]
    public function acceptsOptionalMatchedService(): void
    {
        $payload = $this->validPayload();
        $payload['detections'][0]['status'] = 'known';
        $payload['detections'][0]['matchedService'] = 'google-analytics';
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertTrue($result->valid, $result->message);
    }

    #[Test]
    public function rejectsEmptyMatchedService(): void
    {
        $payload = $this->validPayload();
        $payload['detections'][0]['matchedService'] = '';
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('matchedService', $result->message);
    }

    #[Test]
    public function rejectsInvalidUtf8InIdentifier(): void
    {
        $manualBody = sprintf(
            '{"schemaVersion":2,"source":"test","sentAt":"2026-05-15T00:00:00.000Z",'
            . '"page":{"url":"https://example.com/"},'
            . '"library":{"name":"simplecmp","version":"0.0.1"},'
            . '"detections":[{"kind":"cookie","identifier":"%s","firstSeen":%d,"lastSeen":%d,"count":1,"status":"unknown"}]}',
            "x\x80y",
            $this->nowMs,
            $this->nowMs,
        );
        $result = $this->validator->validate($manualBody, $this->nowMs);
        self::assertFalse($result->valid);
    }

    // --- helpers ---------------------------------------------------------

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'schemaVersion' => 2,
            'source' => 'test-site',
            'sentAt' => '2026-05-15T07:40:00.000Z',
            'page' => [
                'url' => 'https://dev14.ddev.site/foo',
                'userAgent' => 'unit-test',
            ],
            'library' => [
                'name' => 'simplecmp',
                'version' => '0.0.1',
            ],
            'detections' => [$this->validDetection()],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validDetection(array $overrides = []): array
    {
        return array_replace([
            'kind' => 'cookie',
            'identifier' => '_unit_test',
            'firstSeen' => $this->nowMs,
            'lastSeen' => $this->nowMs,
            'count' => 1,
            'status' => 'unknown',
        ], $overrides);
    }
}
