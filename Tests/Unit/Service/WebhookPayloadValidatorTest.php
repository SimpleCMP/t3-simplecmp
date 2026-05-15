<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WapplerSystems\SimpleCmpTypo3\Service\WebhookPayloadValidator;

final class WebhookPayloadValidatorTest extends TestCase
{
    private WebhookPayloadValidator $validator;
    private int $nowMs;

    protected function setUp(): void
    {
        $this->validator = new WebhookPayloadValidator();
        $this->nowMs = (int) floor(microtime(true) * 1000);
    }

    #[Test]
    public function rejectsBodyOverFourKilobytes(): void
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
        $result = $this->validator->validate('{"schemaVersion":2}', $this->nowMs);
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
    public function rejectsDetectionKindNotInEnum(): void
    {
        $payload = $this->validPayload();
        $payload['detection']['kind'] = 'totally-new-kind';
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('kind', $result->message);
    }

    #[Test]
    public function rejectsIdentifierOver256Bytes(): void
    {
        $payload = $this->validPayload();
        $payload['detection']['identifier'] = str_repeat('a', 257);
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('identifier', $result->message);
    }

    #[Test]
    public function rejectsTimestampMoreThan24HoursOld(): void
    {
        $payload = $this->validPayload();
        $payload['detection']['firstSeen'] = $this->nowMs - (25 * 3600 * 1000);
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('firstSeen', $result->message);
    }

    #[Test]
    public function rejectsTimestampFarInFuture(): void
    {
        $payload = $this->validPayload();
        $payload['detection']['firstSeen'] = $this->nowMs + 120_000;
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function acceptsTimestampWithinClockSkew(): void
    {
        $payload = $this->validPayload();
        $payload['detection']['firstSeen'] = $this->nowMs + 30_000;
        $payload['detection']['lastSeen'] = $this->nowMs + 30_000;
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertTrue($result->valid, $result->message);
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

    #[Test]
    public function rejectsCountOutOfRange(): void
    {
        $payload = $this->validPayload();
        $payload['detection']['count'] = 100_000;
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);

        $payload['detection']['count'] = 0;
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function rejectsStatusOtherThanUnknown(): void
    {
        $payload = $this->validPayload();
        $payload['detection']['status'] = 'known';
        $result = $this->validator->validate(json_encode($payload), $this->nowMs);
        self::assertFalse($result->valid);
        self::assertStringContainsString('status', $result->message);
    }

    #[Test]
    public function rejectsInvalidUtf8InIdentifier(): void
    {
        $payload = $this->validPayload();
        $payload['detection']['identifier'] = "valid-prefix\x80\x81";
        $body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        $body = str_replace(["\u{FFFD}", '?'], "\x80\x81", $body);
        // json_encode can't emit invalid UTF-8 directly; verify the bare
        // validator behaviour via a hand-crafted body instead.
        $manualBody = sprintf(
            '{"schemaVersion":1,"source":"test","sentAt":"2026-05-15T00:00:00.000Z","page":{"url":"https://example.com/"},"library":{"name":"simplecmp","version":"0.0.1"},"detection":{"kind":"cookie","identifier":"%s","firstSeen":%d,"lastSeen":%d,"count":1,"status":"unknown"}}',
            "x\x80y",
            $this->nowMs,
            $this->nowMs,
        );
        $result = $this->validator->validate($manualBody, $this->nowMs);
        // Either the validator catches the bad UTF-8, or json_decode does.
        self::assertFalse($result->valid);
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'schemaVersion' => 1,
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
            'detection' => [
                'kind' => 'cookie',
                'identifier' => '_unit_test',
                'firstSeen' => $this->nowMs,
                'lastSeen' => $this->nowMs,
                'count' => 1,
                'status' => 'unknown',
            ],
        ];
    }
}
