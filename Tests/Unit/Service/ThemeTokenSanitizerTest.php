<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Controller\ThemeDesignerController;

/**
 * `sanitizeTokens()` is the gate both writers into `tx_t3simplecmp_theme`
 * go through — the Design tab and `simplecmp:set-theme`. It is the only
 * thing standing between a token value and shadow-DOM CSS, so its
 * behaviour is pinned here rather than left to the controller's own
 * coverage.
 */
final class ThemeTokenSanitizerTest extends TestCase
{
    #[Test]
    public function keepsAValidEnumValue(): void
    {
        $clean = ThemeDesignerController::sanitizeTokens(['triggerPosition' => 'bottom-left']);
        self::assertSame(['triggerPosition' => 'bottom-left'], $clean);
    }

    #[Test]
    public function dropsAnEnumValueOutsideTheAllowedSet(): void
    {
        self::assertArrayNotHasKey(
            'triggerPosition',
            ThemeDesignerController::sanitizeTokens(['triggerPosition' => 'links']),
        );
    }

    /**
     * The theme is stored as a diff from the defaults, so a value that
     * equals the default is dropped on purpose — not a rejection.
     */
    #[Test]
    public function dropsAValueThatEqualsTheDefault(): void
    {
        $default = ThemeDesignerController::DEFAULT_TOKENS['triggerPosition'];
        self::assertArrayNotHasKey(
            'triggerPosition',
            ThemeDesignerController::sanitizeTokens(['triggerPosition' => $default]),
        );
    }

    #[Test]
    public function dropsAColorCarryingCssMetacharacters(): void
    {
        // RegisterAssets concatenates color tokens raw into shadow-DOM
        // CSS; anything that can close a declaration block is a stored
        // injection primitive.
        foreach (
            [
                'red;} :host{display:none',
                '#fff; background-image: url(//evil.example/x)',
                'blue */',
                'var(--x); }',
            ] as $payload
        ) {
            self::assertArrayNotHasKey(
                'color-trigger-bg',
                ThemeDesignerController::sanitizeTokens(['color-trigger-bg' => $payload]),
                sprintf('"%s" must not survive sanitising', $payload),
            );
        }
    }

    #[Test]
    public function keepsOrdinaryCssColors(): void
    {
        foreach (['#0d6efd', 'rgb(13, 110, 253)', 'hsl(217, 89%, 61%)', 'teal'] as $color) {
            self::assertSame(
                ['color-trigger-bg' => $color],
                ThemeDesignerController::sanitizeTokens(['color-trigger-bg' => $color]),
                sprintf('"%s" is a legitimate colour', $color),
            );
        }
    }

    /**
     * The keyword safelist is deliberately small — the picker emits hex,
     * so it only has to cover the manual-text fallback, and every entry
     * is a value that bypasses the hex and function-form regexes. A CSS
     * colour name that is not on it is dropped, which is by design and
     * not a gap.
     */
    #[Test]
    public function dropsANamedColorOutsideTheAuditedSafelist(): void
    {
        self::assertSame([], ThemeDesignerController::sanitizeTokens(['color-trigger-bg' => 'rebeccapurple']));
    }

    #[Test]
    public function ignoresKeysThatAreNotThemeTokens(): void
    {
        self::assertSame([], ThemeDesignerController::sanitizeTokens(['notAToken' => 'x']));
    }
}
