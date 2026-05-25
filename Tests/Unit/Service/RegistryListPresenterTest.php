<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\RegistryListPresenter;

/**
 * Pure unit coverage for the three-source-state derivation. Hits the
 * static methods directly — the instance-side `coverageCountByServiceId`
 * needs ServiceRepository + DetectionRepository and is exercised in
 * the controller's manual browser verification instead.
 */
final class RegistryListPresenterTest extends TestCase
{
    #[Test]
    public function deriveSourceReturnsCustomWhenLibraryAdoptedAtIsZero(): void
    {
        $row = ['id' => 'my-thing', '_libraryAdoptedAt' => 0];
        self::assertSame(
            RegistryListPresenter::SOURCE_CUSTOM,
            RegistryListPresenter::deriveSource($row, ['my-thing' => true]),
        );
    }

    #[Test]
    public function deriveSourceReturnsLibraryWhenAdoptedAndIdStillPresentInLibrary(): void
    {
        $row = ['id' => 'stripe', '_libraryAdoptedAt' => 1_700_000_000];
        self::assertSame(
            RegistryListPresenter::SOURCE_LIBRARY,
            RegistryListPresenter::deriveSource($row, ['stripe' => true, 'youtube' => true]),
        );
    }

    #[Test]
    public function deriveSourceReturnsOrphanedWhenAdoptedButIdNoLongerInLibrary(): void
    {
        // The bundled library dropped or renamed this service in a
        // later release. The registry row still works; admin gets a
        // surface signal that it's no longer library-backed.
        $row = ['id' => 'discontinued-tracker', '_libraryAdoptedAt' => 1_700_000_000];
        self::assertSame(
            RegistryListPresenter::SOURCE_ORPHANED,
            RegistryListPresenter::deriveSource($row, ['stripe' => true, 'youtube' => true]),
        );
    }

    #[Test]
    public function decorateRowAttachesSourceClassAndLabelKey(): void
    {
        $row = ['id' => 'stripe', 'name' => 'Stripe', '_libraryAdoptedAt' => 1_700_000_000];
        $decorated = RegistryListPresenter::decorateRow($row, ['stripe' => true]);
        self::assertSame(RegistryListPresenter::SOURCE_LIBRARY, $decorated['source']);
        self::assertSame('bg-info text-dark', $decorated['source_class']);
        self::assertSame('registry.badge.library', $decorated['source_label_key']);
        // Preserves unrelated row keys.
        self::assertSame('Stripe', $decorated['name']);
    }

    #[Test]
    public function decorateRowForOrphansUsesWarningClass(): void
    {
        $row = ['id' => 'discontinued', '_libraryAdoptedAt' => 1_700_000_000];
        $decorated = RegistryListPresenter::decorateRow($row, []);
        self::assertSame(RegistryListPresenter::SOURCE_ORPHANED, $decorated['source']);
        self::assertSame('bg-warning text-dark', $decorated['source_class']);
        self::assertSame('registry.badge.orphaned', $decorated['source_label_key']);
    }

    #[Test]
    public function decorateRowForCustomUsesSuccessClass(): void
    {
        $row = ['id' => 'my-thing', '_libraryAdoptedAt' => 0];
        $decorated = RegistryListPresenter::decorateRow($row, ['my-thing' => true]);
        self::assertSame(RegistryListPresenter::SOURCE_CUSTOM, $decorated['source']);
        self::assertSame('bg-success', $decorated['source_class']);
        self::assertSame('registry.badge.custom', $decorated['source_label_key']);
    }
}
