<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\AuskunftBundle;
use SimpleCMP\T3SimpleCmp\Service\AuskunftCsvExporter;

/**
 * Format stability for the Phase-3 CSV exporter. Same input → same
 * bytes; embedded commas/quotes/newlines are correctly RFC-4180
 * escaped; UTF-8 BOM is present so Excel opens the file as UTF-8.
 */
final class AuskunftCsvExporterTest extends TestCase
{
    private AuskunftCsvExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new AuskunftCsvExporter();
    }

    #[Test]
    public function emitsUtf8BomAndBothSectionMarkers(): void
    {
        $bundle = new AuskunftBundle(snapshots: [], decisions: [], filter: ['kind' => 'visitor']);
        $csv = $this->exporter->encode($bundle, 'cli', 1700000000);

        // UTF-8 BOM at byte 0
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        // Both section comments present, in order
        self::assertStringContainsString('# SECTION: snapshots', $csv);
        self::assertStringContainsString('# SECTION: decisions', $csv);
        self::assertLessThan(
            strpos($csv, '# SECTION: decisions'),
            strpos($csv, '# SECTION: snapshots'),
            'snapshots section must precede decisions section',
        );
    }

    #[Test]
    public function isStableForIdenticalInput(): void
    {
        $bundle = new AuskunftBundle(
            snapshots: [
                ['uid' => 1, 'crdate' => 1700000000, 'site' => 'default', 'version_hash' => 'abc', 'trigger_event' => 'service-save', 'creator_be_user' => 1],
            ],
            decisions: [
                ['uid' => 2, 'crdate' => 1700000100, 'site' => 'default', 'version_hash' => 'abc', 'visitor_id_sha256' => 'def', 'decision_hash' => 'ghi', 'decision_type' => 'accept', 'decisions_json' => '{"matomo":true}', 'ua_family' => 'chrome', 'page_url_host' => 'example.com'],
            ],
            filter: ['kind' => 'visitor', 'site' => 'default'],
        );
        $a = $this->exporter->encode($bundle, 'cli', 1700000000);
        $b = $this->exporter->encode($bundle, 'cli', 1700000000);
        self::assertSame($a, $b);
    }

    #[Test]
    public function quotesFieldsContainingCommas(): void
    {
        $bundle = new AuskunftBundle(
            snapshots: [],
            decisions: [
                // decisions_json contains commas — must be quoted
                ['uid' => 1, 'crdate' => 1700000000, 'decisions_json' => '{"a":true,"b":false}'],
            ],
            filter: [],
        );
        $csv = $this->exporter->encode($bundle, 'cli', 1700000000);
        self::assertStringContainsString('"{""a"":true,""b"":false}"', $csv);
    }

    #[Test]
    public function quotesFieldsWithEmbeddedQuotes(): void
    {
        $bundle = new AuskunftBundle(
            snapshots: [],
            decisions: [
                ['uid' => 1, 'crdate' => 1700000000, 'page_url_host' => 'host with "quotes"'],
            ],
            filter: [],
        );
        $csv = $this->exporter->encode($bundle, 'cli', 1700000000);
        // Embedded double-quotes are doubled per RFC-4180
        self::assertStringContainsString('"host with ""quotes"""', $csv);
    }

    #[Test]
    public function preservesUtf8UmlautsLiterally(): void
    {
        $bundle = new AuskunftBundle(
            snapshots: [],
            decisions: [
                ['uid' => 1, 'crdate' => 1700000000, 'page_url_host' => 'über.example.com'],
            ],
            filter: ['kind' => 'visitor', 'note' => 'Bürgerauskunft'],
        );
        $csv = $this->exporter->encode($bundle, 'cli', 1700000000);
        self::assertStringContainsString('über.example.com', $csv);
        // The filter JSON in the header comment must also keep the umlaut readable.
        self::assertStringContainsString('Bürgerauskunft', $csv);
    }

    #[Test]
    public function headerContainsFilterAndExportedBy(): void
    {
        $bundle = new AuskunftBundle(
            snapshots: [],
            decisions: [],
            filter: ['kind' => 'snapshot', 'versionHash' => 'abc123'],
        );
        $csv = $this->exporter->encode($bundle, 'be:42', 1700000000);
        self::assertStringContainsString('# exportedBy: be:42', $csv);
        self::assertStringContainsString('"kind":"snapshot"', $csv);
        self::assertStringContainsString('"versionHash":"abc123"', $csv);
    }
}
