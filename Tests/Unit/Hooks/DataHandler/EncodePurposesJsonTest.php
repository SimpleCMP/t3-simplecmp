<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Tests\Unit\Hooks\DataHandler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use WapplerSystems\SimpleCmpTypo3\Hooks\DataHandler\EncodePurposesJson;

final class EncodePurposesJsonTest extends TestCase
{
    private function invoke(string $table, array &$fieldArray): void
    {
        (new EncodePurposesJson())->processDatamap_postProcessFieldArray(
            'update',
            $table,
            42,
            $fieldArray,
            $this->createMock(DataHandler::class),
        );
    }

    #[Test]
    public function passesThroughOtherTables(): void
    {
        $fields = ['purposes' => 'analytics,marketing'];
        $this->invoke('pages', $fields);
        self::assertSame('analytics,marketing', $fields['purposes']);
    }

    #[Test]
    public function leavesFieldsAloneWhenPurposesAbsent(): void
    {
        $fields = ['name' => 'Google Analytics'];
        $this->invoke('tx_simplecmptypo3_service', $fields);
        self::assertArrayNotHasKey('purposes', $fields);
    }

    #[Test]
    #[TestWith(['analytics,marketing', '["analytics","marketing"]'])]
    #[TestWith(['analytics', '["analytics"]'])]
    #[TestWith(['', '[]'])]
    public function encodesCsvIntoJsonArray(string $csv, string $expected): void
    {
        $fields = ['purposes' => $csv];
        $this->invoke('tx_simplecmptypo3_service', $fields);
        self::assertSame($expected, $fields['purposes']);
    }

    #[Test]
    public function leavesAlreadyEncodedJsonValueUntouched(): void
    {
        // The importer command writes purposes already as a JSON array
        // when going through DataHandler — the hook must not re-wrap it.
        $fields = ['purposes' => '["analytics","marketing"]'];
        $this->invoke('tx_simplecmptypo3_service', $fields);
        self::assertSame('["analytics","marketing"]', $fields['purposes']);
    }

    #[Test]
    public function dedupesAndTrimsCsvEntries(): void
    {
        $fields = ['purposes' => ' analytics , analytics ,marketing,'];
        $this->invoke('tx_simplecmptypo3_service', $fields);
        self::assertSame('["analytics","marketing"]', $fields['purposes']);
    }

    #[Test]
    public function acceptsArrayValueIfDataHandlerEverHandsOne(): void
    {
        $fields = ['purposes' => ['analytics', 'marketing', '', 'marketing']];
        $this->invoke('tx_simplecmptypo3_service', $fields);
        self::assertSame('["analytics","marketing"]', $fields['purposes']);
    }
}
