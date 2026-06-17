<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\ClockInterface;
use SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver;
use SimpleCMP\T3SimpleCmp\Service\WizardStateService;

final class WizardStateServiceTest extends TestCase
{
    private const string SITE = 'default';
    private const int BE_USER = 42;

    #[Test]
    public function bannerVisibleWhenNeitherKeySet(): void
    {
        $resolver = $this->createMock(EffectiveSettingsResolver::class);
        $resolver->method('getInternal')->willReturn(null);
        $service = new WizardStateService($resolver, $this->fixedClock(1));
        self::assertTrue($service->shouldShowBanner(self::SITE));
    }

    #[Test]
    public function bannerHiddenWhenCompletedSet(): void
    {
        $resolver = $this->createMock(EffectiveSettingsResolver::class);
        $resolver->method('getInternal')->willReturnMap([
            [self::SITE, WizardStateService::KEY_COMPLETED_AT, 1700000000],
            [self::SITE, WizardStateService::KEY_SKIPPED_AT, null],
        ]);
        $service = new WizardStateService($resolver, $this->fixedClock(1));
        self::assertFalse($service->shouldShowBanner(self::SITE));
    }

    #[Test]
    public function bannerHiddenWhenSkippedSet(): void
    {
        $resolver = $this->createMock(EffectiveSettingsResolver::class);
        $resolver->method('getInternal')->willReturnMap([
            [self::SITE, WizardStateService::KEY_COMPLETED_AT, null],
            [self::SITE, WizardStateService::KEY_SKIPPED_AT, 1700000000],
        ]);
        $service = new WizardStateService($resolver, $this->fixedClock(1));
        self::assertFalse($service->shouldShowBanner(self::SITE));
    }

    #[Test]
    public function markCompletedWritesTimestamp(): void
    {
        $resolver = $this->createMock(EffectiveSettingsResolver::class);
        $resolver->method('getInternal')->willReturn(null);
        $resolver->expects(self::once())
            ->method('setInternal')
            ->with(self::SITE, WizardStateService::KEY_COMPLETED_AT, 1700000000, self::BE_USER);
        $resolver->expects(self::never())->method('deleteInternal');

        $service = new WizardStateService($resolver, $this->fixedClock(1700000000));
        $service->markCompleted(self::SITE, self::BE_USER);
    }

    #[Test]
    public function markCompletedAlsoClearsSkippedWhenPresent(): void
    {
        $resolver = $this->createMock(EffectiveSettingsResolver::class);
        $resolver->method('getInternal')->willReturnMap([
            [self::SITE, WizardStateService::KEY_COMPLETED_AT, null],
            [self::SITE, WizardStateService::KEY_SKIPPED_AT, 1699000000],
        ]);
        $resolver->expects(self::once())
            ->method('setInternal')
            ->with(self::SITE, WizardStateService::KEY_COMPLETED_AT, 1700000000, self::BE_USER);
        $resolver->expects(self::once())
            ->method('deleteInternal')
            ->with(self::SITE, WizardStateService::KEY_SKIPPED_AT, self::BE_USER);

        $service = new WizardStateService($resolver, $this->fixedClock(1700000000));
        $service->markCompleted(self::SITE, self::BE_USER);
    }

    #[Test]
    public function markSkippedWritesTimestamp(): void
    {
        $resolver = $this->createMock(EffectiveSettingsResolver::class);
        $resolver->expects(self::once())
            ->method('setInternal')
            ->with(self::SITE, WizardStateService::KEY_SKIPPED_AT, 1700000000, self::BE_USER);

        $service = new WizardStateService($resolver, $this->fixedClock(1700000000));
        $service->markSkipped(self::SITE, self::BE_USER);
    }

    #[Test]
    public function reopenClearsBothKeysWhenSet(): void
    {
        $resolver = $this->createMock(EffectiveSettingsResolver::class);
        $resolver->method('getInternal')->willReturnMap([
            [self::SITE, WizardStateService::KEY_COMPLETED_AT, 1700000000],
            [self::SITE, WizardStateService::KEY_SKIPPED_AT, 1699000000],
        ]);
        $resolver->expects(self::exactly(2))->method('deleteInternal');

        $service = new WizardStateService($resolver, $this->fixedClock(1));
        $service->reopen(self::SITE, self::BE_USER);
    }

    #[Test]
    public function reopenIsNoopWhenNothingSet(): void
    {
        $resolver = $this->createMock(EffectiveSettingsResolver::class);
        $resolver->method('getInternal')->willReturn(null);
        $resolver->expects(self::never())->method('deleteInternal');

        $service = new WizardStateService($resolver, $this->fixedClock(1));
        $service->reopen(self::SITE, self::BE_USER);
    }

    private function fixedClock(int $now): ClockInterface
    {
        return new class ($now) implements ClockInterface {
            public function __construct(private int $t)
            {
            }

            public function now(): int
            {
                return $this->t;
            }
        };
    }
}
