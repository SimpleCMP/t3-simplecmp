<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\BackdropStyle;

final class BackdropStyleTest extends TestCase
{
    #[Test]
    public function noColorMeansNoBackdrop(): void
    {
        self::assertSame([], BackdropStyle::rules([]));
        self::assertSame([], BackdropStyle::rules(['backdrop-opacity' => '50']));
        self::assertNull(BackdropStyle::color(['color-backdrop' => '']));
    }

    #[Test]
    public function hexColorIsCombinedWithTheOpacity(): void
    {
        self::assertSame('rgba(255, 255, 255, 0.5)', BackdropStyle::color(['color-backdrop' => '#FFFFFF', 'backdrop-opacity' => '50']));
        self::assertSame('rgba(24, 61, 70, 0.3)', BackdropStyle::color(['color-backdrop' => '#183d46', 'backdrop-opacity' => '30']));
        self::assertSame('rgba(255, 255, 255, 0.9)', BackdropStyle::color(['color-backdrop' => '#fff', 'backdrop-opacity' => '90']));
    }

    #[Test]
    public function missingOrUnknownOpacityFallsBackToFiftyPercent(): void
    {
        self::assertSame('rgba(0, 0, 0, 0.5)', BackdropStyle::color(['color-backdrop' => '#000000']));
        self::assertSame('rgba(0, 0, 0, 0.5)', BackdropStyle::color(['color-backdrop' => '#000000', 'backdrop-opacity' => '0.5; }']));
    }

    #[Test]
    public function colorsWithOwnAlphaAreUsedAsGiven(): void
    {
        self::assertSame('rgba(255, 255, 255, 0.4)', BackdropStyle::color(['color-backdrop' => 'rgba(255, 255, 255, 0.4)', 'backdrop-opacity' => '80']));
        self::assertSame('#ffffff80', BackdropStyle::color(['color-backdrop' => '#FFFFFF80']));
    }

    #[Test]
    public function valuesFailingTheColorGrammarAreDropped(): void
    {
        // The color lands verbatim in shadow-DOM CSS.
        self::assertNull(BackdropStyle::color(['color-backdrop' => 'red; } body { display:none']));
        self::assertSame([], BackdropStyle::rules(['color-backdrop' => 'url(https://evil.test/x)']));
    }

    #[Test]
    public function rulesCoverBannerAndSettingsDialog(): void
    {
        $rules = BackdropStyle::rules(['color-backdrop' => '#ffffff', 'backdrop-opacity' => '50']);

        self::assertCount(2, $rules);
        self::assertStringStartsWith(':host(simplecmp-banner) {', $rules[0]);
        self::assertStringContainsString('box-shadow: 0 0 0 100vmax rgba(255, 255, 255, 0.5) !important', $rules[0]);
        self::assertSame(':host(simplecmp-modal) dialog::backdrop { background: rgba(255, 255, 255, 0.5) !important; }', $rules[1]);
    }
}
