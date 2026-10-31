<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Compatibility;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * Stand-in for Site Sets, which TYPO3 12 does not have.
 *
 * From TYPO3 13 on, a site opts in by listing the `simplecmp/t3-simplecmp`
 * set, and the set's `settings.definitions.yaml` supplies every default.
 * TYPO3 12 has neither, so a site opts in by carrying a `simplecmp:` block
 * in its `settings.yaml`, and the defaults are read from the same
 * definitions file and laid under the site's own values here.
 */
final class SiteSetCompat
{
    private const DEFINITIONS_FILE = 'Configuration/Sets/SimpleCmp/settings.definitions.yaml';

    /** @var array<string, mixed>|null */
    private static ?array $defaults = null;

    /** @var array<string, SiteSettings> */
    private static array $settingsCache = [];

    /**
     * Whether SimpleCMP is switched on for the site: its settings carry a
     * `simplecmp` block (any content, `simplecmp: {}` is enough).
     */
    public static function isActive(SiteInterface $site): bool
    {
        if (!$site instanceof Site) {
            return false;
        }
        return array_key_exists('simplecmp', $site->getSettings()->getAll());
    }

    /**
     * The site's settings with the extension defaults filled in for every
     * `simplecmp.*` identifier the site does not set itself. Like a set's
     * definitions, the defaults only apply where SimpleCMP is active, so
     * `simplecmp.enabled` stays empty on every other site.
     */
    public static function settings(Site $site): SiteSettings
    {
        if (!self::isActive($site)) {
            return $site->getSettings();
        }
        $own = $site->getSettings()->getAll();
        $cacheKey = $site->getIdentifier() . ':' . md5(serialize($own));
        if (!isset(self::$settingsCache[$cacheKey])) {
            $tree = self::merge(self::defaultsTree(), $own);
            // TYPO3 12's SiteSettings::get() finds a nested value only via its
            // flattened leaves, so a list such as
            // `simplecmp.universalBlocking.allowlist` would come back null.
            // get() checks a literal top-level key first: expose every
            // defined identifier under its dotted name as well.
            foreach (array_keys(self::definitions()) as $identifier) {
                if (ArrayUtility::isValidPath($tree, (string) $identifier, '.')) {
                    $tree[$identifier] = ArrayUtility::getValueByPath($tree, (string) $identifier, '.');
                }
            }
            self::$settingsCache[$cacheKey] = new SiteSettings($tree);
        }
        return self::$settingsCache[$cacheKey];
    }

    /**
     * Deep merge for maps; a list is a value of its own and replaces the
     * default as a whole (array_replace_recursive would splice it by index).
     *
     * @param array<array-key, mixed> $defaults
     * @param array<array-key, mixed> $own
     * @return array<array-key, mixed>
     */
    private static function merge(array $defaults, array $own): array
    {
        foreach ($own as $key => $value) {
            if (is_array($value) && !array_is_list($value)
                && isset($defaults[$key]) && is_array($defaults[$key]) && !array_is_list($defaults[$key])
            ) {
                $defaults[$key] = self::merge($defaults[$key], $value);
                continue;
            }
            $defaults[$key] = $value;
        }
        return $defaults;
    }

    /**
     * Flat dotted identifiers of a settings object — TYPO3 13's
     * `SiteSettings::getIdentifiers()`.
     *
     * @return list<string>
     */
    public static function identifiers(SiteSettings $settings): array
    {
        return array_keys($settings->getAllFlat());
    }

    /**
     * @return array<string, mixed>
     */
    private static function definitions(): array
    {
        static $definitions = null;
        if ($definitions === null) {
            $file = dirname(__DIR__, 2) . '/' . self::DEFINITIONS_FILE;
            $definitions = (array) (Yaml::parseFile($file)['settings'] ?? []);
        }
        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultsTree(): array
    {
        if (self::$defaults === null) {
            $definitions = self::definitions();
            $tree = [];
            foreach ($definitions as $identifier => $definition) {
                if (is_array($definition) && array_key_exists('default', $definition)) {
                    $tree = ArrayUtility::setValueByPath($tree, (string) $identifier, $definition['default'], '.');
                }
            }
            self::$defaults = $tree;
        }
        return self::$defaults;
    }
}
