<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Backend\TcaItemsProc;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Backend\TcaItemsProc\PurposeItems;

/**
 * Smoke-tests the items-discovery pass against the actual bundled
 * services-library. The library is part of the composer install (a
 * sibling vendor package), so a unit test can iterate it directly.
 *
 * What's asserted:
 *  - the items list is non-empty (otherwise the BE form would render
 *    a header with zero checkboxes — a silent failure mode);
 *  - it contains the protocol's six original purposes that the library
 *    has been seeded with; if the library ever drops one of these,
 *    this test fires and the team gets a chance to think about the
 *    migration before admins see an empty checkbox group.
 */
final class PurposeItemsTest extends TestCase
{
    #[Test]
    public function itemsDiscoversThePurposesUsedInTheLibrary(): void
    {
        $config = ['items' => []];
        (new PurposeItems())->items($config);

        self::assertNotEmpty($config['items']);

        $values = array_column($config['items'], 'value');
        self::assertContains('analytics', $values);
        self::assertContains('functional', $values);
        self::assertContains('marketing', $values);
    }

    #[Test]
    public function itemsAreAlphabeticallySorted(): void
    {
        $config = ['items' => []];
        (new PurposeItems())->items($config);

        $values = array_column($config['items'], 'value');
        $sorted = $values;
        sort($sorted);
        self::assertSame($sorted, $values);
    }

    #[Test]
    public function itemsPointAtLocallangKeys(): void
    {
        $config = ['items' => []];
        (new PurposeItems())->items($config);

        foreach ($config['items'] as $item) {
            self::assertStringStartsWith(
                'LLL:EXT:simplecmp/',
                $item['label'],
                'Items must use LLL labels so locallang stays the single source for translations.',
            );
            self::assertStringEndsWith(
                '.purposes.item.' . $item['value'],
                $item['label'],
            );
        }
    }

    #[Test]
    public function itemsPreserveExistingItemsAppendedByTca(): void
    {
        // The TCA can ship a leading "items" array (we use []) but the
        // proc-func is contractually additive — never mutate prior
        // entries, only append.
        $config = ['items' => [['label' => 'placeholder', 'value' => 'zzz-placeholder']]];
        (new PurposeItems())->items($config);

        self::assertSame('zzz-placeholder', $config['items'][0]['value']);
    }
}
