<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;

/**
 * BE module *Websites → SimpleCMP → Banner design*.
 *
 * Lets admins customize the FE consent banner's colors, typography,
 * and shape per Site Set without editing YAML or PHP. Tokens persist
 * in `tx_t3simplecmp_theme` (one row per site identifier); the FE
 * asset listener emits them as inline CSS custom property overrides
 * before the banner mounts.
 *
 * Three actions:
 * - `index(site)` — render the editor form for the chosen site
 * - `save(site, tokens)` — validate + upsert + redirect to index
 * - `reset(site)` — delete the row → falls back to defaults
 *
 * Defaults mirror upstream `src/ui/styles/tokens.ts`. The defaults
 * map is the source of truth for both the form's initial values and
 * the "what does Reset mean" semantics.
 */
final class ThemeDesignerController extends ActionController
{
    /**
     * Site Set identifier the FE bundle ships with. A site has to
     * include this in its dependencies for SimpleCMP to load — and
     * therefore for theming it to make sense.
     */
    private const string SET_IDENTIFIER = 'simplecmp/t3-simplecmp';

    /**
     * Defaults straight from upstream tokens.ts. Stays here as a const
     * map rather than a separate file because it's read in two paths
     * (form pre-fill + reset semantics) and both want a static value.
     *
     * @var array<string, string>
     */
    public const array DEFAULT_TOKENS = [
        'color-primary' => '#15775a',
        'color-primary-hover' => '#0f5d44',
        'color-text' => '#1a232c',
        'color-text-muted' => '#5f6b78',
        'color-bg' => '#ffffff',
        'color-bg-alt' => '#f5f7f9',
        'color-border' => '#dde2e7',
        'color-danger' => '#da2c43',
        'radius' => '6px',
        // Typography. `--simplecmp-font-family-heading` defaults to
        // `var(--simplecmp-font-family)` upstream (same font for body
        // and headings) — but for the BE designer's "if equals default,
        // don't persist" sanitize logic, we need a literal default
        // string. Keeping it equal to font-family means no row gets
        // persisted unless admin explicitly sets a heading font.
        'font-family' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        'font-family-heading' => 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
        'font-size' => '0.95rem',
        'font-size-heading' => '20px',
    ];

    /**
     * Field grouping for the form template. Semantic groups — brand
     * colors first, surface colors next, then the secondary "advanced"
     * tokens (hover/muted/alt) that vary the main ones. Typography and
     * shape close out with non-color tokens.
     *
     * @var array<string, list<string>>
     */
    private const array FIELD_GROUPS = [
        'brand' => [
            'color-primary',
            'color-danger',
        ],
        'surface' => [
            'color-text',
            'color-bg',
            'color-border',
        ],
        'advanced' => [
            'color-primary-hover',
            'color-text-muted',
            'color-bg-alt',
        ],
        'typography' => [
            'font-family',
            'font-size',
            'font-family-heading',
            'font-size-heading',
        ],
        'shape' => ['radius'],
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly ThemeRepository $themeRepository,
        private readonly SiteFinder $siteFinder,
    ) {
    }

    public function indexAction(string $site = '', string $language = ''): ResponseInterface
    {
        $availableSites = $this->availableSites();
        $moduleTemplate = $this->initModuleTemplate();

        // Empty-state: no site has the SimpleCMP Site Set in its
        // dependencies. Theming would be pointless because the FE
        // bundle never loads. Surface the cause with an actionable
        // hint instead of rendering an empty form.
        if ($availableSites === []) {
            $moduleTemplate->assignMultiple([
                'hasAvailableSites' => false,
                'setIdentifier' => self::SET_IDENTIFIER,
                'allSites' => $this->allSiteIdentifiers(),
            ]);
            return $moduleTemplate->renderResponse('ThemeDesigner/Index');
        }

        $site = $this->normalizeSite($site, $availableSites);
        $stored = $this->themeRepository->findBySite($site) ?? [];
        $tokens = array_merge(self::DEFAULT_TOKENS, $stored);
        $hasCustomTheme = $stored !== [];

        // Per-option URLs for the site picker. The generic
        // Pagination.js navigation handler can't be used here because
        // it appends a bare `?site=X` query param — but the Extbase
        // UriBuilder this module uses produces namespaced action URLs
        // (`tx_<plugin>[action]=index&tx_<plugin>[site]=…`), so the
        // bare param is ignored and `$site` arrives empty. Building
        // the URLs server-side via the same UriBuilder guarantees the
        // chosen site actually lands in the action argument.
        $siteOptions = [];
        foreach ($availableSites as $siteId) {
            $siteOptions[] = [
                'id' => $siteId,
                'url' => $this->uri('index', ['site' => $siteId]),
            ];
        }

        // Language context for the live preview. Defaults to the BE
        // user's interface language so the editor sees the banner in
        // the language they're working in. Pickable from the site's
        // configured FE languages so the editor can preview each
        // language variant before deploying.
        $availableLanguages = $this->availableLanguagesForSite($site);
        $beUserLanguage = $this->beUserLanguage();
        $previewLanguage = $this->resolvePreviewLanguage($language, $availableLanguages, $beUserLanguage);
        $languageOptions = [];
        foreach ($availableLanguages as $lang) {
            $languageOptions[] = [
                'code' => $lang['code'],
                'label' => $lang['label'],
                'url' => $this->uri('index', ['site' => $site, 'language' => $lang['code']]),
            ];
        }

        $moduleTemplate->assignMultiple([
            'hasAvailableSites' => true,
            'site' => $site,
            'availableSites' => $availableSites,
            'siteOptions' => $siteOptions,
            'previewLanguage' => $previewLanguage,
            'languageOptions' => $languageOptions,
            'hasLanguageChoice' => count($languageOptions) > 1,
            'siteBaseUrl' => $this->siteBaseUrl($site),
            'tokens' => $tokens,
            'fieldGroups' => self::FIELD_GROUPS,
            'hasCustomTheme' => $hasCustomTheme,
            'uri_save' => $this->uri('save'),
            'uri_reset' => $this->uri('reset', ['site' => $site]),
        ]);
        return $moduleTemplate->renderResponse('ThemeDesigner/Index');
    }

    /**
     * All site identifiers regardless of Set assignment — used by the
     * empty-state hint to show editors which configs need editing.
     *
     * @return list<string>
     */
    private function allSiteIdentifiers(): array
    {
        $ids = [];
        foreach ($this->siteFinder->getAllSites() as $identifier => $site) {
            // Skip TYPO3's auto-generated placeholder sites — they
            // appear with an `autogenerated-<pid>-…` identifier and
            // an empty Sets list, and aren't configurable from disk.
            if (str_starts_with((string)$identifier, 'autogenerated-')) {
                continue;
            }
            $ids[] = (string)$identifier;
        }
        sort($ids);
        return $ids;
    }

    /**
     * Active site's base URL, used by the BE detect-fonts JS to load
     * the FE in a hidden iframe and read its computed body/heading
     * typography. Empty string if the site doesn't expose a base
     * (shouldn't happen for sites with the SimpleCMP set).
     */
    private function siteBaseUrl(string $siteIdentifier): string
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return '';
        }
        return (string) $site->getBase();
    }

    /**
     * Languages configured on the given site, normalised for the
     * preview picker. Returns at minimum the site's default language
     * so the picker never shows up empty.
     *
     * v14: `SiteLanguage::getTwoLetterIsoCode()` was removed. Use the
     * `Locale` object's language code instead — it returns the same
     * lowercase 2/3-letter ISO 639 code (`de`, `fr`, `de-AT` → `de`).
     *
     * @return list<array{code: string, label: string}>
     */
    private function availableLanguagesForSite(string $siteIdentifier): array
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($site->getAllLanguages() as $lang) {
            $code = strtolower((string) $lang->getLocale()->getLanguageCode());
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $title = (string) $lang->getTitle();
            $out[] = [
                'code' => $code,
                'label' => $title !== '' ? sprintf('%s (%s)', $title, $code) : $code,
            ];
        }
        return $out;
    }

    /**
     * BE user's interface language as a lowercase two-letter code,
     * e.g. `de`. Falls back to `en` when the user has no preference.
     */
    private function beUserLanguage(): string
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        $lang = '';
        if (is_object($beUser) && isset($beUser->user) && is_array($beUser->user)) {
            $lang = (string) ($beUser->user['lang'] ?? '');
        }
        if ($lang === '' || $lang === 'default') {
            return 'en';
        }
        // BE user lang is typically already a 2-letter code (`de`, `fr`),
        // but defensive trimming + lower-casing handles any locale-style
        // values that snuck in.
        $lang = strtolower($lang);
        if (preg_match('/^([a-z]{2,3})/', $lang, $m) === 1) {
            return $m[1];
        }
        return 'en';
    }

    /**
     * Pick the preview language: requested → BE-user language →
     * first available → empty. Constrained to languages the site
     * actually has, otherwise the SimpleCMP bundle would fall back
     * to its own default and the picker would lie about state.
     *
     * @param list<array{code: string, label: string}> $available
     */
    private function resolvePreviewLanguage(string $requested, array $available, string $beUserLang): string
    {
        $codes = array_column($available, 'code');
        if ($codes === []) {
            return '';
        }
        $candidate = strtolower(trim($requested));
        if ($candidate !== '' && in_array($candidate, $codes, true)) {
            return $candidate;
        }
        if ($beUserLang !== '' && in_array($beUserLang, $codes, true)) {
            return $beUserLang;
        }
        return $codes[0];
    }

    /**
     * @param array<string, string> $tokens
     */
    public function saveAction(string $site = '', array $tokens = []): ResponseInterface
    {
        $availableSites = $this->availableSites();
        $site = $this->normalizeSite($site, $availableSites);
        $clean = self::sanitizeTokens($tokens);
        $this->themeRepository->upsert($site, $clean);
        return $this->redirect('index', null, null, ['site' => $site]);
    }

    public function resetAction(string $site = ''): ResponseInterface
    {
        $availableSites = $this->availableSites();
        $site = $this->normalizeSite($site, $availableSites);
        $this->themeRepository->delete($site);
        return $this->redirect('index', null, null, ['site' => $site]);
    }

    /**
     * Strip unknown keys, trim values, drop blanks so the saved row
     * contains only fields the admin actually edited. Validation is
     * deliberately permissive — TYPO3 BE color pickers always emit
     * `#rrggbb`, and font-family strings are free-form by nature; we
     * trust the form's HTML5 input types to constrain what reaches
     * the controller.
     *
     * @param array<string, mixed> $tokens
     * @return array<string, string>
     */
    public static function sanitizeTokens(array $tokens): array
    {
        $clean = [];
        foreach (self::DEFAULT_TOKENS as $key => $default) {
            $value = $tokens[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '' || $value === $default) {
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    /**
     * Sites that actually run SimpleCMP — i.e. have the
     * `simplecmp/t3-simplecmp` Site Set among their resolved
     * dependencies. Theming any other site is moot because the FE
     * bundle never loads there.
     *
     * This also excludes TYPO3's auto-generated placeholder sites
     * (`autogenerated-<pid>-…`) created when a page is marked as a
     * siteroot without a real config; those have empty dependencies.
     *
     * @return list<string>
     */
    private function availableSites(): array
    {
        $ids = [];
        foreach ($this->siteFinder->getAllSites() as $identifier => $site) {
            if (in_array(self::SET_IDENTIFIER, $site->getSets(), true)) {
                $ids[] = $identifier;
            }
        }
        sort($ids);
        return $ids;
    }

    /**
     * @param list<string> $available
     */
    private function normalizeSite(string $site, array $available): string
    {
        if ($site !== '' && in_array($site, $available, true)) {
            return $site;
        }
        return $available[0] ?? 'default';
    }

    /**
     * @param array<string, scalar> $arguments
     */
    private function uri(string $action, array $arguments = []): string
    {
        return (string) $this->uriBuilder
            ->reset()
            ->setRequest($this->request)
            ->uriFor($action, $arguments);
    }

    private function initModuleTemplate(): ModuleTemplate
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('SimpleCMP');
        // Reuse the detection module's filter-navigation handler: the
        // site picker uses `data-list-filter="site"` so Pagination.js
        // navigates to the index action with the chosen site on change.
        $this->pageRenderer->loadJavaScriptModule(
            '@simplecmp/t3-simplecmp/Backend/Pagination.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@simplecmp/t3-simplecmp/Backend/ConfirmForm.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@simplecmp/t3-simplecmp/Backend/ThemePreview.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@simplecmp/t3-simplecmp/Backend/DetectFonts.js'
        );
        return $moduleTemplate;
    }
}
