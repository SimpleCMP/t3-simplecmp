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
            'color-primary' => '#1f4f8f',  // default — drop
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

    /**
     * Regression: a stored `color-*` value with a CSS `}` breakout used
     * to be concatenated raw into the shadow-DOM `<style>` by
     * RegisterAssets::injectTheme(), turning a tampered POST into stored
     * CSS injection (consent-UI defacement). sanitizeTokens() now drops
     * any value that doesn't match the strict CSS-color grammar.
     */
    #[Test]
    public function sanitizeDropsColorTokenWithCssBreakout(): void
    {
        $clean = ThemeDesignerController::sanitizeTokens([
            'color-accept-bg' => 'red !important; } :host { display: none } /*',
            'color-decline-bg' => '#ff0000; }',
            'color-configure-bg' => '#abc',           // legit — must survive
        ]);
        self::assertSame(['color-configure-bg' => '#abc'], $clean);
    }

    #[Test]
    public function sanitizeDropsCorePaletteColorWithCssBreakout(): void
    {
        // Even when `colorPaletteLocked='0'` is set, the FE renders these
        // tokens directly into the `:host { … }` rule; a breakout payload
        // here is just as dangerous as in the per-button overrides.
        $clean = ThemeDesignerController::sanitizeTokens([
            'color-primary' => '#fff } body { display: none } /*',
            'color-text' => '#000000',
        ]);
        self::assertSame(['color-text' => '#000000'], $clean);
    }

    #[Test]
    public function isCssColorAcceptsCommonFormats(): void
    {
        self::assertTrue(ThemeDesignerController::isCssColor('#abc'));
        self::assertTrue(ThemeDesignerController::isCssColor('#aabbcc'));
        self::assertTrue(ThemeDesignerController::isCssColor('#aabbccdd'));
        self::assertTrue(ThemeDesignerController::isCssColor('rgb(0, 128, 255)'));
        self::assertTrue(ThemeDesignerController::isCssColor('rgba(0, 128, 255, 0.5)'));
        self::assertTrue(ThemeDesignerController::isCssColor('hsl(120deg, 100%, 50%)'));
        self::assertTrue(ThemeDesignerController::isCssColor('hsla(120, 100%, 50%, 0.8)'));
        self::assertTrue(ThemeDesignerController::isCssColor('transparent'));
        self::assertTrue(ThemeDesignerController::isCssColor('currentColor'));
    }

    #[Test]
    public function isCssColorRejectsInjectionPayloads(): void
    {
        // The actual exploit payloads from the audit — every one must
        // fail validation. Any "true" here is a stored XSS-class bug.
        self::assertFalse(ThemeDesignerController::isCssColor('red !important; } :host { display: none } /*'));
        self::assertFalse(ThemeDesignerController::isCssColor('#fff } body { display: none } /*'));
        self::assertFalse(ThemeDesignerController::isCssColor('#fff;'));
        self::assertFalse(ThemeDesignerController::isCssColor('expression(alert(1))'));
        self::assertFalse(ThemeDesignerController::isCssColor('url(javascript:alert(1))'));
        self::assertFalse(ThemeDesignerController::isCssColor('rgb(0,0,0) }'));
        self::assertFalse(ThemeDesignerController::isCssColor('hsl(0,0%,0%); content: "x"'));
        self::assertFalse(ThemeDesignerController::isCssColor(''));
        self::assertFalse(ThemeDesignerController::isCssColor('not-a-color'));
        self::assertFalse(ThemeDesignerController::isCssColor('#12345'));        // odd length
        self::assertFalse(ThemeDesignerController::isCssColor('#gggggg'));       // non-hex chars
    }
}
