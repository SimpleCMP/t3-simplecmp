<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use SimpleCMP\T3SimpleCmp\Controller\ThemeDesignerController;

/**
 * Optional backdrop behind the consent UI (`color-backdrop` +
 * `backdrop-opacity` theme tokens), e.g. a translucent white veil like a
 * Bootstrap modal backdrop.
 *
 * Banner: the bundle positions the banner host with `position: fixed`
 * and, for the centered positions, `transform: translateX(-50%)`. A
 * fixed-position pseudo element inside it would be laid out against that
 * transformed box, not the viewport — so the veil is drawn as a huge
 * spread box-shadow on the host instead. It needs no extra element,
 * follows the banner's visibility (`:host([hidden])` is display:none) and
 * does not capture clicks: the page stays usable, the veil only shifts
 * focus. A blocking overlay would turn the banner into a consent wall.
 * The card's own shadow sits on `.cn-body`, so the host's box-shadow is
 * free; the host gets the card radius so the veil meets the rounded
 * corners without gaps.
 *
 * Settings dialog: a native <dialog> opened with showModal(), so its own
 * `::backdrop` takes the same color.
 */
final class BackdropStyle
{
    /** Opacity used when only a color is set. */
    public const DEFAULT_OPACITY = '50';

    /**
     * Resolved CSS color of the backdrop, or null when none is configured
     * or the stored value fails the color grammar (defense in depth: the
     * value is concatenated into CSS).
     *
     * @param array<string, mixed> $tokens
     */
    public static function color(array $tokens): ?string
    {
        $color = is_string($tokens['color-backdrop'] ?? null) ? strtolower(trim($tokens['color-backdrop'])) : '';
        if ($color === '' || !ThemeDesignerController::isCssColor($color)) {
            return null;
        }
        $opacity = is_string($tokens['backdrop-opacity'] ?? null) ? trim($tokens['backdrop-opacity']) : '';
        if (!isset(ThemeDesignerController::BACKDROP_OPACITIES[$opacity])) {
            $opacity = self::DEFAULT_OPACITY;
        }
        // The BE picker yields #rrggbb (no alpha); combine it with the
        // opacity token. Colors that already carry alpha or come as
        // functions/keywords are used as given.
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $m) === 1) {
            $color = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/', $color, $m) === 1) {
            return sprintf(
                'rgba(%d, %d, %d, %s)',
                hexdec($m[1]),
                hexdec($m[2]),
                hexdec($m[3]),
                rtrim(rtrim(number_format(((int) $opacity) / 100, 2, '.', ''), '0'), '.'),
            );
        }

        return $color;
    }

    /**
     * Shadow-DOM rules for the banner and the settings dialog; empty when
     * no backdrop is configured.
     *
     * @param array<string, mixed> $tokens
     * @return list<string>
     */
    public static function rules(array $tokens): array
    {
        $color = self::color($tokens);
        if ($color === null) {
            return [];
        }

        return [
            ':host(simplecmp-banner) { box-shadow: 0 0 0 100vmax ' . $color . ' !important; border-radius: var(--simplecmp-radius); }',
            ':host(simplecmp-modal) dialog::backdrop { background: ' . $color . ' !important; }',
        ];
    }
}
