<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\AuskunftBundle;
use SimpleCMP\T3SimpleCmp\Service\AuskunftJsonExporter;

/**
 * Schema lock for the Phase-3 JSON exporter — consumers will key off
 * the shape, so accidental field renames are a contract breach.
 */
final class AuskunftJsonExporterTest extends TestCase
{
    private AuskunftJsonExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new AuskunftJsonExporter();
    }

    #[Test]
    public function emitsSchemaVersionOne(): void
    {
        $bundle = new AuskunftBundle(snapshots: [], decisions: [], filter: []);
        $payload = json_decode($this->exporter->encode($bundle, 'cli', 1700000000), true);
        self::assertSame(1, $payload['schemaVersion']);
    }

    #[Test]
    public function exportedAtIsIsoUtc(): void
    {
        $bundle = new AuskunftBundle(snapshots: [], decisions: [], filter: []);
        $payload = json_decode($this->exporter->encode($bundle, 'cli', 1700000000), true);
        self::assertSame('2023-11-14T22:13:20+00:00', $payload['exportedAt']);
    }

    #[Test]
    public function decodesCanonicalJsonInsteadOfQuotingIt(): void
    {
        $bundle = new AuskunftBundle(
            snapshots: [[
                'uid' => 1,
                'crdate' => 1700000000,
                'site' => 'default',
                'version_hash' => 'abc',
                'trigger_event' => 'service-save',
                'creator_be_user' => 1,
                'canonical_json' => '{"foo":"bar","n":2}',
            ]],
            decisions: [],
            filter: [],
        );
        $payload = json_decode($this->exporter->encode($bundle, 'cli', 1700000000), true);
        self::assertSame(['foo' => 'bar', 'n' => 2], $payload['snapshots'][0]['canonical']);
    }

    #[Test]
    public function decodesDecisionsJsonOnDecisionRows(): void
    {
        $bundle = new AuskunftBundle(
            snapshots: [],
            decisions: [[
                'uid' => 2,
                'crdate' => 1700000100,
                'site' => 'default',
                'version_hash' => 'abc',
                'visitor_id_sha256' => 'def',
                'decision_hash' => 'ghi',
                'decision_type' => 'accept',
                'decisions_json' => '{"matomo":true,"youtube":false}',
                'ua_family' => 'chrome',
                'page_url_host' => 'example.com',
            ]],
            filter: [],
        );
        $payload = json_decode($this->exporter->encode($bundle, 'cli', 1700000000), true);
        self::assertSame(['matomo' => true, 'youtube' => false], $payload['decisions'][0]['decisions']);
    }

    #[Test]
    public function fallsBackGracefullyOnMalformedCanonicalJson(): void
    {
        $bundle = new AuskunftBundle(
            snapshots: [[
                'uid' => 1,
                'crdate' => 1700000000,
                'canonical_json' => 'this is not json',
            ]],
            decisions: [],
            filter: [],
        );
        $payload = json_decode($this->exporter->encode($bundle, 'cli', 1700000000), true);
        // Falls back to raw string rather than throwing
        self::assertSame('this is not json', $payload['snapshots'][0]['canonical']);
    }

    #[Test]
    public function isStableAcrossIdenticalInvocations(): void
    {
        $bundle = new AuskunftBundle(
            snapshots: [['uid' => 1, 'crdate' => 1700000000, 'site' => 'a', 'version_hash' => 'h', 'trigger_event' => 't', 'creator_be_user' => 0, 'canonical_json' => '{"k":"v"}']],
            decisions: [],
            filter: ['kind' => 'snapshot'],
        );
        $a = $this->exporter->encode($bundle, 'cli', 1700000000);
        $b = $this->exporter->encode($bundle, 'cli', 1700000000);
        self::assertSame($a, $b);
    }
}
