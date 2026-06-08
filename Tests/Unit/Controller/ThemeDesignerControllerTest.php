<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Controller\ThemeDesignerController;

/**
 * Unit tests for the pure `sanitizeTokens` transformation.
 *
 * The rest of the controller (action methods, ModuleTemplate wiring,
 * SiteFinder filtering) needs an Extbase / BE harness; behaviour is
 * covered functionally through the Playwright save→FE round-trip.
 */
final class ThemeDesignerControllerTest extends TestCase
{
    #[Test]
    public function sanitizeStripsUnknownKeys(): void
    {
        $clean = ThemeDesignerController::sanitizeTokens([
            'color-primary' => '#ff0000',
            'attacker-injected' => 'oops',
            'random-key' => 'nope',
        ]);
        self::assertSame(['color-primary' => '#ff0000'], $clean);
    }

    #[Test]
    public function sanitizeStripsValuesEqualToDefaults(): void
    {
        // When the admin doesn't touch a field, the form re-submits its
        // default. We don't want to persist those because:
        //  1. The stored row should only carry meaningful overrides
        //  2. If the upstream default ever changes, sites that never
        //     customized that token stay on the new default automatically.
        $clean = ThemeDesignerController::sanitizeTokens([
            'color-primary' => '#15775a',  // default — drop
            'color-text' => '#000000',     // custom — keep
            'color-bg' => '#ffffff',       // default — drop
        ]);
        self::assertSame(['color-text' => '#000000'], $clean);
    }

    #[Test]
    public function sanitizeStripsBlankValues(): void
    {
        $clean = ThemeDesignerController::sanitizeTokens([
            'color-primary' => '',
            'color-text' => '   ',  // whitespace only
            'color-bg' => '#fefefe',
        ]);
        self::assertSame(['color-bg' => '#fefefe'], $clean);
    }

    #[Test]
    public function sanitizeTrimsWhitespace(): void
    {
        $clean = ThemeDesignerController::sanitizeTokens([
            'color-text' => '  #112233  ',
        ]);
        self::assertSame(['color-text' => '#112233'], $clean);
    }

    #[Test]
    public function sanitizeIgnoresNonStringValues(): void
    {
        $clean = ThemeDesignerController::sanitizeTokens([
            'color-primary' => ['not', 'a', 'string'],
            'color-text' => 12,
            'color-bg' => '#abcdef',
        ]);
        self::assertSame(['color-bg' => '#abcdef'], $clean);
    }

    #[Test]
    public function sanitizeReturnsEmptyArrayWhenAllDefaults(): void
    {
        $clean = ThemeDesignerController::sanitizeTokens(
            ThemeDesignerController::DEFAULT_TOKENS,
        );
        self::assertSame([], $clean);
    }

    #[Test]
    public function sanitizeReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], ThemeDesignerController::sanitizeTokens([]));
    }
}
