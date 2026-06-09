<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\DetectionResetGeneration;
use TYPO3\CMS\Core\Registry;

/**
 * Locks the per-source report-generation counter that drives FE
 * cross-session marker invalidation after a detection purge.
 */
final class DetectionResetGenerationTest extends TestCase
{
    #[Test]
    public function currentReadsFromRegistryDefaultingToZero(): void
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('get')
            ->with('tx_t3simplecmp', 'reportGeneration.mysite', 0)
            ->willReturn(3);

        self::assertSame(3, (new DetectionResetGeneration($registry))->current('mysite'));
    }

    #[Test]
    public function bumpIncrementsAndPersists(): void
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn(3);
        $registry->expects(self::once())
            ->method('set')
            ->with('tx_t3simplecmp', 'reportGeneration.mysite', 4);

        (new DetectionResetGeneration($registry))->bump('mysite');
    }

    #[Test]
    public function bumpIgnoresEmptySource(): void
    {
        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('set');

        (new DetectionResetGeneration($registry))->bump('');
    }
}
