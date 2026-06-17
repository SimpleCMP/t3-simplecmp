<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\ViewHelpers\Format;

use TYPO3\CMS\Core\Localization\DateFormatter;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Format a date in the current BE editor's locale.
 *
 * Reads `$GLOBALS['BE_USER']->user['lang']` (defaults to the system
 * default locale when no BE user is around) and dispatches through
 * TYPO3's locale-aware {@see DateFormatter::format()}. The `format`
 * argument accepts the same input as `DateFormatter::format()` —
 * named patterns (FULL/LONG/MEDIUM/SHORT) or PHP `date()` syntax.
 *
 * Usage in Fluid:
 *   {namespace t3b=SimpleCMP\T3SimpleCmp\ViewHelpers}
 *   <t3b:format.beDate date="{row.crdate}" format="MEDIUM" />
 *
 * Default format is `MEDIUM` — short-ish but readable in any locale.
 *
 * Falls back to the existing PHP `date()` format string when an
 * unrecognised named-pattern is passed, so existing call sites that
 * mirror `<f:format.date format="Y-m-d H:i">` keep working but now
 * locale-aware via the IntlDateFormatter substitution table.
 */
final class BeDateViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('date', 'mixed', 'Unix-timestamp or DateTimeInterface', true);
        $this->registerArgument('format', 'string', 'IntlDateFormatter pattern or named (FULL/LONG/MEDIUM/SHORT)', false, 'MEDIUM');
    }

    public function render(): string
    {
        $date = $this->arguments['date'];
        if ($date === null || $date === '' || $date === 0) {
            return '';
        }
        $locale = $this->resolveLocale();
        $formatter = GeneralUtility::makeInstance(DateFormatter::class);
        return $formatter->format($date, (string) $this->arguments['format'], $locale);
    }

    private function resolveLocale(): string
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        $lang = $beUser?->user['lang'] ?? null;
        if (is_string($lang) && $lang !== '') {
            // BE user lang is a two-letter code (`de`, `en`, …); the
            // IntlDateFormatter accepts that directly.
            return $lang;
        }
        // Fall back to the BE default language configured in
        // sys_language; ultimately to 'en'.
        return $GLOBALS['TYPO3_CONF_VARS']['BE']['defaultUserTSconfig'] ?? 'en';
    }
}
