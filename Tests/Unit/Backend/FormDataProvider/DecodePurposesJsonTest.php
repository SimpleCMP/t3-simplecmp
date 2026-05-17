<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Unit\Backend\FormDataProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use WapplerSystems\SimpleCmpTypo3\Backend\FormDataProvider\DecodePurposesJson;

final class DecodePurposesJsonTest extends TestCase
{
    #[Test]
    public function passesThroughOtherTables(): void
    {
        $provider = new DecodePurposesJson();
        $result = $provider->addData([
            'tableName' => 'pages',
            'databaseRow' => ['purposes' => '["analytics"]'],
        ]);
        // Untouched: providers are scoped to the service table only.
        self::assertSame('["analytics"]', $result['databaseRow']['purposes']);
    }

    #[Test]
    public function leavesRowAloneWhenPurposesAbsent(): void
    {
        $provider = new DecodePurposesJson();
        $result = $provider->addData([
            'tableName' => 'tx_simplecmptypo3_service',
            'databaseRow' => ['uid' => 1],
        ]);
        self::assertArrayNotHasKey('purposes', $result['databaseRow']);
    }

    #[Test]
    #[TestWith(['["analytics","marketing"]', 'analytics,marketing'])]
    #[TestWith(['["analytics"]', 'analytics'])]
    #[TestWith(['[]', ''])]
    #[TestWith(['', ''])]
    #[TestWith(['not-json-at-all', ''])]
    public function decodesJsonArrayIntoCommaSeparatedString(string $stored, string $expected): void
    {
        $provider = new DecodePurposesJson();
        $result = $provider->addData([
            'tableName' => 'tx_simplecmptypo3_service',
            'databaseRow' => ['purposes' => $stored],
        ]);
        self::assertSame($expected, $result['databaseRow']['purposes']);
    }

    #[Test]
    public function dropsNonStringEntriesInTheJsonArray(): void
    {
        // Defensive: garbage in the column (someone hand-edited via
        // SQL) shouldn't crash the form.
        $provider = new DecodePurposesJson();
        $result = $provider->addData([
            'tableName' => 'tx_simplecmptypo3_service',
            'databaseRow' => ['purposes' => '["analytics", 42, null, "marketing"]'],
        ]);
        self::assertSame('analytics,marketing', $result['databaseRow']['purposes']);
    }
}
