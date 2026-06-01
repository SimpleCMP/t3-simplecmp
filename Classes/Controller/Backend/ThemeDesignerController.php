<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\TranslationOverrideRepository;
use SimpleCMP\T3SimpleCmp\Service\ComplianceCheckService;

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
     * Curated list of banner / modal translation keys editors are
     * allowed to override from the designer module. Limited so the
     * BE-side surface stays approachable — covers the Du/Sie-relevant
     * texts plus the prominent buttons. `kind` hints the input
     * widget the template should render (single-line vs textarea).
     *
     * The bundle's translation tree is nested
     * (`consentNotice.description`); we store + render via the dotted
     * path and expand to nested objects when handing `config.translations`
     * to `cmp.init()`.
     *
     * @var list<array{key: string, kind: string}>
     */
    public const array OVERRIDABLE_KEYS = [
        ['key' => 'consentNotice.title', 'kind' => 'line'],
        ['key' => 'consentNotice.description', 'kind' => 'text'],
        ['key' => 'consentNotice.changeDescription', 'kind' => 'text'],
        ['key' => 'consentNotice.learnMore', 'kind' => 'line'],
        ['key' => 'consentNotice.testing', 'kind' => 'line'],
        ['key' => 'consentModal.title', 'kind' => 'line'],
        ['key' => 'consentModal.description', 'kind' => 'text'],
        ['key' => 'privacyPolicy.name', 'kind' => 'line'],
        ['key' => 'imprint.name', 'kind' => 'line'],
        ['key' => 'consentNotice.imprint.name', 'kind' => 'line'],
        ['key' => 'acceptAll', 'kind' => 'line'],
        ['key' => 'acceptSelected', 'kind' => 'line'],
        ['key' => 'decline', 'kind' => 'line'],
        ['key' => 'save', 'kind' => 'line'],
        ['key' => 'ok', 'kind' => 'line'],
    ];

    /**
     * Languages for which the upstream `simplecmp` bundle ships a
     * curated informal-tone overlay (du/tu/tú/…). The BE tone toggle
     * only appears for editing languages listed here — for any other
     * language flipping the switch would be a silent no-op (the
     * bundle would fall back to formal).
     *
     * Stays in sync with `simplecmp/src/engine/translations/informal/`
     * by convention. When upstream adds a new informal pack, append
     * its language code here and the toggle becomes available.
     *
     * **Native-speaker review status** (per upstream `informal/
     * index.ts`):
     *   - `de`: reviewed
     *   - `fr`, `it`, `es`, `nl`: draft, native-speaker review
     *     pending. Wording is grammatically clean and tonally
     *     consistent but hasn't been audited against brand-voice
     *     norms or regional variants.
     *
     * @var list<string>
     */
    public const array LANGUAGES_WITH_INFORMAL_TONE = ['de', 'es', 'fr', 'it', 'nl'];

    private const string TONE_FORMAL = 'formal';
    private const string TONE_INFORMAL = 'informal';

    /**
     * Defaults straight from upstream tokens.ts. Stays here as a const
     * map rather than a separate file because it's read in two paths
     * (form pre-fill + reset semantics) and both want a static value.
     *
     * @var array<string, string>
     */
    public const array DEFAULT_TOKENS = [
        // Brand-relevant colors stay editable — operators want their
        // accept button on their actual brand color even when the
        // chosen `theme` framework would otherwise bind it to the
        // framework's primary (e.g. Bootstrap's blue). Typography
        // (`font-family`, `font-size`) and shape (`radius`) used to
        // be editable here too; they were removed because the
        // framework adapters (`bootstrap5`, `tailwind4`) already
        // bind those to the host site's tokens (`--bs-body-font-
        // family`, `--bs-border-radius`, `--font-sans`, `--radius-md`,
        // …). On `default` (no framework) the bundle's `tokens.ts`
        // ships sensible system-stack defaults. Either way the editor
        // doesn't need per-banner overrides for fonts or radius —
        // those are site-design concerns handled outside this module.
        'color-primary' => '#15775a',
        'color-primary-hover' => '#0f5d44',
        'color-text' => '#1a232c',
        'color-text-muted' => '#5f6b78',
        'color-bg' => '#ffffff',
        'color-bg-alt' => '#f5f7f9',
        'color-border' => '#dde2e7',
        'color-danger' => '#da2c43',
        // Banner placement — one of the nine `POSITION_*` keys below.
        // Translated by the FE asset listener into the upstream
        // `--simplecmp-banner-inset` / `-transform` / `-max-width`
        // tokens at runtime; not persisted when the admin keeps the
        // default. Default mirrors the original hard-coded
        // bottom-right corner.
        'position' => 'bottom-right',
        // CSS-framework adapter — picks one of the upstream theme
        // overlays so the consent UI inherits the host site's design
        // tokens (e.g. Bootstrap 5's `--bs-*`). The value flows
        // straight through to `cmp.init({ theme: ... })`; the bundle
        // injects the matching adapter `<style>` element. Default
        // `default` is no-op: the bundle's own tokens (plus this
        // designer's per-token overrides) apply unchanged.
        'theme' => 'default',
        // Banner button-row template. `standard` ships three equally-
        // styled buttons (Configure | Decline | Accept); `compact`
        // drops Configure so the first layer is a two-button row;
        // `stacked` keeps all three but lays them vertically full-
        // width for narrow viewports. All three templates preserve
        // the equal-prominence compliance baseline — they only
        // differ in which buttons appear + how they're arranged.
        'layout' => 'standard',
    ];

    /**
     * Allowed `position` token values + a human-readable label
     * (used for the BE picker's tooltip) and the matching upstream
     * CSS-var translation. The keys form a 3x3 grid:
     *
     *   top-left    | top-center    | top-right
     *   middle-left | middle-center | middle-right
     *   bottom-left | bottom-center | bottom-right
     *
     * `inset` is `top right bottom left`. Center positions use
     * `transform: translate(…)` to do the actual centering and trim
     * `max-width` so the centered banner stays readable across the
     * viewport instead of stretching edge-to-edge.
     *
     * @var array<string, array{label: string, inset: string, transform: string, maxWidth: ?string}>
     */
    public const array POSITIONS = [
        'top-left' => [
            'label' => 'Top left',
            'inset' => 'var(--simplecmp-spacing) auto auto var(--simplecmp-spacing)',
            'transform' => 'none',
            'maxWidth' => null,
        ],
        'top-center' => [
            'label' => 'Top center',
            'inset' => 'var(--simplecmp-spacing) auto auto 50%',
            'transform' => 'translateX(-50%)',
            'maxWidth' => 'min(30rem, calc(100vw - 2 * var(--simplecmp-spacing)))',
        ],
        'top-right' => [
            'label' => 'Top right',
            'inset' => 'var(--simplecmp-spacing) var(--simplecmp-spacing) auto auto',
            'transform' => 'none',
            'maxWidth' => null,
        ],
        'middle-left' => [
            'label' => 'Middle left',
            'inset' => '50% auto auto var(--simplecmp-spacing)',
            'transform' => 'translateY(-50%)',
            'maxWidth' => null,
        ],
        'middle-center' => [
            'label' => 'Middle center',
            'inset' => '50% auto auto 50%',
            'transform' => 'translate(-50%, -50%)',
            'maxWidth' => 'min(30rem, calc(100vw - 2 * var(--simplecmp-spacing)))',
        ],
        'middle-right' => [
            'label' => 'Middle right',
            'inset' => '50% var(--simplecmp-spacing) auto auto',
            'transform' => 'translateY(-50%)',
            'maxWidth' => null,
        ],
        'bottom-left' => [
            'label' => 'Bottom left',
            'inset' => 'auto auto var(--simplecmp-spacing) var(--simplecmp-spacing)',
            'transform' => 'none',
            'maxWidth' => null,
        ],
        'bottom-center' => [
            'label' => 'Bottom center',
            'inset' => 'auto auto var(--simplecmp-spacing) 50%',
            'transform' => 'translateX(-50%)',
            'maxWidth' => 'min(30rem, calc(100vw - 2 * var(--simplecmp-spacing)))',
        ],
        'bottom-right' => [
            'label' => 'Bottom right',
            'inset' => 'auto var(--simplecmp-spacing) var(--simplecmp-spacing) auto',
            'transform' => 'none',
            'maxWidth' => null,
        ],
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
        'placement' => ['position'],
        'framework' => ['theme'],
        'template' => ['layout'],
    ];

    /**
     * Allowed values for the `theme` token. Keys match the upstream
     * bundle's `Theme` union (`src/ui/themes/index.ts`); labels are
     * the human-readable strings the BE select-box shows. Adding a
     * new entry requires the upstream bundle to ship the matching
     * adapter (otherwise `cmp.init({ theme: ... })` warns and falls
     * back to default at runtime).
     *
     * @var array<string, string>
     */
    public const array THEMES = [
        'default' => 'Default (SimpleCMP eigene Token)',
        'bootstrap5' => 'Bootstrap 5 (übernimmt --bs-* der Site)',
        'tailwind4' => 'Tailwind 4 (übernimmt @theme-Tokens der Site)',
        'bulma' => 'Bulma 1+ (übernimmt --bulma-* der Site)',
        'pico' => 'Pico CSS 2 (übernimmt --pico-* der Site)',
    ];

    /**
     * Allowed values for the `layout` token. Keys match the
     * upstream bundle's `layout` config field; labels are the
     * human-readable strings the BE select-box shows. Adding a
     * new entry requires the upstream bundle to ship the matching
     * render branch (otherwise `cmp.init({ layout: ... })` falls
     * back to the standard template at runtime).
     *
     * @var array<string, string>
     */
    public const array LAYOUTS = [
        'standard' => 'Drei Buttons nebeneinander (Konfigurieren, Ablehnen, Akzeptieren)',
        'compact' => 'Zwei Buttons (Ablehnen, Akzeptieren — kein Konfigurieren auf Ebene 1)',
        'stacked' => 'Drei Buttons untereinander (für schmale Viewports / Mobile)',
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly ThemeRepository $themeRepository,
        private readonly TranslationOverrideRepository $overrideRepository,
        private readonly SiteFinder $siteFinder,
        private readonly \TYPO3\CMS\Backend\Routing\UriBuilder $backendUriBuilder,
        private readonly ComplianceCheckService $complianceCheck,
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

        // Privacy + Imprint URLs from the site's Settings. Surfaced for
        // two reasons:
        //   1. The preview iframe needs them so the banner can render
        //      both links exactly the way the live FE will — empty
        //      strings turn into placeholder `#` URLs so the editor
        //      still sees the layout even when the site hasn't
        //      configured them yet.
        //   2. The "URLs" status block in the BE module shows the
        //      currently saved values (read-only) so editors don't
        //      need to leave the designer to verify.
        $policyUrls = $this->policyUrlsForSite($site);
        $siteSettingsUri = $this->siteSettingsEditUri($site);

        // 3x3 position picker — passed as a list of {key, label, row, col}
        // so the template can lay it out in a CSS grid without knowing
        // the position-name → grid-cell mapping.
        $positionOptions = $this->buildPositionOptions();

        // CSS-framework picker — flat {key, label} list for a select.
        $themeOptions = [];
        foreach (self::THEMES as $key => $label) {
            $themeOptions[] = ['key' => $key, 'label' => $label];
        }

        // Banner-template picker — same flat shape as themeOptions.
        $layoutOptions = [];
        foreach (self::LAYOUTS as $key => $label) {
            $layoutOptions[] = ['key' => $key, 'label' => $label];
        }

        // Compliance audit — runs the legal-requirement checks against
        // the live site config (settings + service registry). Results
        // mirror the upstream `simplecmp.audit()` JS surface so a
        // single CHANGELOG entry on either side prompts the other to
        // re-sync. The template renders them as an inline-banner at
        // the top of the form so editors see findings before they
        // touch any other control.
        $auditResults = $this->complianceCheck->audit(
            $this->siteFinder->getSiteByIdentifier($site)
        );
        $auditWorstSeverity = $this->complianceCheck->worstSeverity($auditResults);
        // Enrich each finding with a clickable URL pointing at the
        // compliance-reference view, anchored to the matching
        // section. The reference replaces the previous "(§1.3)" plain-
        // text with a link an editor can actually follow to read why
        // the check exists and what to fix. Section ID `1.3` → URL
        // anchor `1-3` (dots aren't valid in HTML5 fragment ids
        // anywhere they'd matter for our routing).
        foreach ($auditResults as $i => $finding) {
            $anchor = str_replace('.', '-', $finding['section']);
            $auditResults[$i]['sectionAnchor'] = $anchor;
            $auditResults[$i]['complianceUri'] = $this->uri('compliance', ['section' => $anchor])
                . '#section-' . $anchor;
        }
        // Split into failed (rendered as actionable items) vs passed
        // (rendered as a collapsed "all green" group). Editors care
        // most about what's broken; the green list is for confidence.
        $auditFailed = array_values(array_filter($auditResults, static fn (array $r) => $r['passed'] === false));
        $auditPassed = array_values(array_filter($auditResults, static fn (array $r) => $r['passed'] === true));

        // Translation overrides — text fields keyed by dotted-path,
        // scoped to the currently selected preview language so editors
        // see / edit one language at a time. The Vorschau-Sprache
        // picker already switches the active language, which doubles
        // as the override-scope picker.
        $allOverrides = $this->overrideRepository->findBySite($site) ?? [];
        $forLang = $allOverrides[$previewLanguage] ?? ['tone' => null, 'overrides' => []];
        $overridesForLang = $forLang['overrides'];
        $toneForLang = $forLang['tone'] ?? self::TONE_FORMAL;

        $overrideKeys = [];
        foreach (self::OVERRIDABLE_KEYS as $entry) {
            $key = $entry['key'];
            $overrideKeys[] = [
                'key' => $key,
                'kind' => $entry['kind'],
                'value' => $overridesForLang[$key] ?? '',
            ];
        }
        $hasInformalTone = in_array($previewLanguage, self::LANGUAGES_WITH_INFORMAL_TONE, true);

        // Preview-iframe payload: the editor's manual overrides only.
        // The tone preset (du-form) is no longer materialised here —
        // it lives in the upstream bundle now and is requested via
        // the separate `tone` URL param read by init.js. Keeping the
        // overrides + tone payloads independent means the preview
        // mirrors the FE's actual resolution chain (bundle < tone <
        // overrides) instead of pre-merging them on the server.
        $overridesEncoded = $overridesForLang === []
            ? ''
            : base64_encode((string) json_encode($overridesForLang, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $moduleTemplate->assignMultiple([
            'hasAvailableSites' => true,
            'site' => $site,
            'availableSites' => $availableSites,
            'siteOptions' => $siteOptions,
            'previewLanguage' => $previewLanguage,
            'languageOptions' => $languageOptions,
            'hasLanguageChoice' => count($languageOptions) > 1,
            'policyUrls' => $policyUrls,
            'siteSettingsUri' => $siteSettingsUri,
            'positionOptions' => $positionOptions,
            'themeOptions' => $themeOptions,
            'layoutOptions' => $layoutOptions,
            'auditResults' => $auditResults,
            'auditFailed' => $auditFailed,
            'auditPassed' => $auditPassed,
            'auditWorstSeverity' => $auditWorstSeverity,
            'auditCriticalCount' => count(array_filter($auditResults, static fn (array $r) => $r['severity'] === 'critical')),
            'auditWarningCount' => count(array_filter($auditResults, static fn (array $r) => $r['severity'] === 'warning')),
            // Server-rendered i18n map for the DOM-level audit. The
            // preview iframe calls `simplecmp.auditDom()` after mount
            // and posts results to the parent; the BE-side JS reads
            // this map to render the findings in the editor's BE
            // language. Each entry maps a check ID to its localized
            // title + severity-label strings. Section pointers are
            // 1.2 / 2.2 — same `Compliance.html` page anchors as the
            // config-audit findings.
            'domAuditI18nJson' => $this->buildDomAuditI18nMap(),
            // Live-FE audit needs the site's base URL — it loads
            // `<siteBase>?simplecmp_audit=1` in a hidden iframe and
            // expects the bundle there to post audit results back.
            // Empty string when the site doesn't expose a base
            // (shouldn't happen for SimpleCMP-Set sites).
            'siteBaseUrl' => $this->siteBaseUrl($site),
            'overrideKeys' => $overrideKeys,
            'overrideLanguage' => $previewLanguage,
            'overridesEncoded' => $overridesEncoded,
            'toneForLang' => $toneForLang,
            'hasInformalTone' => $hasInformalTone,
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
     * Read the privacy + imprint URLs the site has configured in its
     * Site Settings. Used by the preview iframe (so the banner shows
     * the live links) and by the BE status block (so editors can
     * verify both are set without leaving the designer).
     *
     * @return array{privacy: string, imprint: string, hasPrivacy: bool, hasImprint: bool}
     */
    private function policyUrlsForSite(string $siteIdentifier): array
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return ['privacy' => '', 'imprint' => '', 'hasPrivacy' => false, 'hasImprint' => false];
        }
        $settings = $site->getSettings();
        $privacy = (string) $settings->get('simplecmp.privacyPolicyUrl', '');
        $imprint = (string) $settings->get('simplecmp.imprintUrl', '');
        return [
            'privacy' => $privacy,
            'imprint' => $imprint,
            'hasPrivacy' => $privacy !== '',
            'hasImprint' => $imprint !== '',
        ];
    }

    /**
     * Deep link into the standard Sites BE module's settings editor
     * for this site. Lets the editor jump straight to the place where
     * privacy + imprint URLs are maintained, instead of explaining the
     * path in prose.
     */
    /**
     * Build the 3x3 grid descriptor for the position picker. The
     * template renders each entry as a radio inside a CSS grid cell.
     * Row/col are 1-based to match `grid-row` / `grid-column`.
     *
     * @return list<array{key: string, label: string, row: int, col: int, vRow: string, vCol: string}>
     */
    private function buildPositionOptions(): array
    {
        $rows = ['top' => 1, 'middle' => 2, 'bottom' => 3];
        $cols = ['left' => 1, 'center' => 2, 'right' => 3];
        $vRows = ['top' => 'top', 'middle' => 'middle', 'bottom' => 'bottom'];
        $vCols = ['left' => 'left', 'center' => 'center', 'right' => 'right'];
        $out = [];
        foreach (self::POSITIONS as $key => $cfg) {
            // Keys are `<row>-<col>`.
            [$rowName, $colName] = explode('-', $key, 2);
            $out[] = [
                'key' => $key,
                'label' => $cfg['label'],
                'row' => $rows[$rowName] ?? 1,
                'col' => $cols[$colName] ?? 1,
                'vRow' => $vRows[$rowName] ?? 'top',
                'vCol' => $vCols[$colName] ?? 'left',
            ];
        }
        return $out;
    }

    private function siteSettingsEditUri(string $siteIdentifier): string
    {
        try {
            return (string) $this->backendUriBuilder->buildUriFromRoute(
                'site_configuration.edit',
                ['site' => $siteIdentifier],
            );
        } catch (\Throwable) {
            return '';
        }
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
     * @param array<string, array<string, string>> $overrides
     *   language → (dotted-key → value). Only the language currently
     *   shown in the editor is submitted; the merge below preserves
     *   stored overrides for other languages.
     */
    public function saveAction(
        string $site = '',
        array $tokens = [],
        string $language = '',
        array $overrides = [],
        string $tone = '',
    ): ResponseInterface {
        $availableSites = $this->availableSites();
        $site = $this->normalizeSite($site, $availableSites);
        $clean = self::sanitizeTokens($tokens);
        $this->themeRepository->upsert($site, $clean);

        // Merge submitted-language overrides + tone with already-stored
        // ones for other languages so saving the German tab doesn't
        // clobber the French overrides.
        $submittedLang = strtolower(trim($language));
        if (preg_match('/^[a-z]{2,3}$/', $submittedLang) !== 1) {
            $submittedLang = '';
        }
        $cleanOverrides = self::sanitizeOverrides($overrides);
        $cleanTone = self::sanitizeTone($tone);

        $stored = $this->overrideRepository->findBySite($site) ?? [];
        foreach ($cleanOverrides as $lang => $entries) {
            $existingTone = $stored[$lang]['tone'] ?? null;
            $stored[$lang] = [
                'tone' => $lang === $submittedLang ? $cleanTone : $existingTone,
                'overrides' => $entries,
            ];
        }
        // If the editor flipped the tone toggle but didn't touch any
        // override field, $cleanOverrides for the submitted language
        // may be empty — still persist the tone choice on its own.
        if ($submittedLang !== '' && !isset($stored[$submittedLang])) {
            if ($cleanTone !== null) {
                $stored[$submittedLang] = ['tone' => $cleanTone, 'overrides' => []];
            }
        } elseif ($submittedLang !== '') {
            $stored[$submittedLang]['tone'] = $cleanTone;
        }
        $this->overrideRepository->upsert($site, $stored);

        return $this->redirect('index', null, null, ['site' => $site, 'language' => $language]);
    }

    /**
     * Whitelist tone values; null = "formal" / no preset applied.
     * Stored only when non-null so the row stays empty for sites
     * that never touched the toggle.
     */
    public static function sanitizeTone(string $tone): ?string
    {
        $value = strtolower(trim($tone));
        return $value === self::TONE_INFORMAL ? self::TONE_INFORMAL : null;
    }

    /**
     * Build a JSON-encoded localization map for the DOM-audit IDs.
     * The preview iframe calls `simplecmp.auditDom()` after banner
     * mount and posts back the raw English findings; the BE-side JS
     * reads this map to render them in the editor's BE language and
     * link each finding's section reference to the matching
     * Compliance.html anchor.
     *
     * Mirrors `ComplianceCheckService::audit()` in shape but limited
     * to the static fields the client-side JS needs (title, section
     * pointer, deep-link). Keep in lockstep with upstream's
     * `src/audit/dom.ts`: a new DOM check upstream requires adding
     * the matching trans-unit + an entry here.
     */
    private function buildDomAuditI18nMap(): string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        $translate = static function (string $key) use ($lang): string {
            if (!is_object($lang) || !method_exists($lang, 'sL')) {
                return $key;
            }
            $value = (string) $lang->sL(
                'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_design.xlf:' . $key
            );
            return $value !== '' ? $value : $key;
        };
        $ids = [
            'dom-buttons-are-buttons' => '2.2',
            'dom-buttons-equal-styling' => '1.2',
            'dom-buttons-wcag-contrast' => '1.2',
        ];
        $out = [];
        foreach ($ids as $id => $section) {
            $anchor = str_replace('.', '-', $section);
            $out[$id] = [
                'title' => $translate('designer.audit.title.' . $id),
                'section' => $section,
                'complianceUri' => $this->uri('compliance', ['section' => $anchor])
                    . '#section-' . $anchor,
            ];
        }
        return (string) json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Active site's base URL — surfaced as a view variable so the
     * BE designer's "Live-FE-Audit" button can load
     * `<siteBase>?simplecmp_audit=1` in a hidden iframe. Empty
     * string when the site doesn't expose a base.
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
     * Standalone reference view that explains the legal requirements
     * the compliance audit checks against, in the editor's BE
     * language. Linked from each audit finding via the `§X.Y` anchor
     * (`?section=1-3` → opens at `#section-1-3`) so an editor can
     * jump straight to the explanation of the failing check.
     *
     * Renders a Fluid template with structured German content
     * (initially German-only; English source kept in
     * `simplecmp/docs/legal-compliance.md` for engineers). The view
     * has no save/persistence — it's read-only reference material.
     */
    public function complianceAction(string $section = ''): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('SimpleCMP');
        $moduleTemplate->assignMultiple([
            // The fragment used by the audit banner to jump to a specific
            // §-section. Template sets the anchor on each heading + can
            // emit a tiny scrollIntoView script for the active one.
            'activeSection' => preg_replace('/[^a-z0-9-]/', '', strtolower($section)) ?? '',
            'uri_backToDesigner' => $this->uri('index'),
        ]);
        // The compliance reference has two language variants — German
        // and English. Pick by BE-user language. Anything else falls
        // through to English since it's the wider default in the
        // SimpleCMP ecosystem; the only narrowing case is `de`.
        $template = $this->beUserLanguage() === 'de'
            ? 'ThemeDesigner/Compliance'
            : 'ThemeDesigner/ComplianceEn';
        return $moduleTemplate->renderResponse($template);
    }

    public function resetAction(string $site = ''): ResponseInterface
    {
        $availableSites = $this->availableSites();
        $site = $this->normalizeSite($site, $availableSites);
        $this->themeRepository->delete($site);
        $this->overrideRepository->delete($site);
        return $this->redirect('index', null, null, ['site' => $site]);
    }

    /**
     * Filter incoming overrides down to whitelisted keys, trim
     * whitespace, drop empty values (which represent "use upstream
     * default"). Unknown lang codes / unknown keys are silently
     * dropped so a tampered POST can't pollute the row.
     *
     * @param array<string, array<string, string>> $overrides
     * @return array<string, array<string, string>>
     */
    public static function sanitizeOverrides(array $overrides): array
    {
        $allowedKeys = array_column(self::OVERRIDABLE_KEYS, 'key');
        $out = [];
        foreach ($overrides as $lang => $entries) {
            if (!is_string($lang) || $lang === '' || !is_array($entries)) {
                continue;
            }
            // Allow only 2/3-letter codes (`de`, `de-AT` → `de`).
            if (preg_match('/^[a-z]{2,3}$/i', $lang) !== 1) {
                continue;
            }
            $cleanLang = strtolower($lang);
            $clean = [];
            foreach ($entries as $key => $value) {
                if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                    continue;
                }
                if (!is_string($value)) {
                    continue;
                }
                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }
                $clean[$key] = $trimmed;
            }
            $out[$cleanLang] = $clean;
        }
        return $out;
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
            // Enum guard for `position` — silently drop unknown values
            // so a tampered POST can't leak garbage CSS into the page.
            if ($key === 'position' && !isset(self::POSITIONS[$value])) {
                continue;
            }
            // Same enum guard for `theme` — only allow values the
            // upstream bundle ships an adapter for.
            if ($key === 'theme' && !isset(self::THEMES[$value])) {
                continue;
            }
            // Same enum guard for `layout` — only allow values the
            // upstream bundle ships a render branch for.
            if ($key === 'layout' && !isset(self::LAYOUTS[$value])) {
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
        return $moduleTemplate;
    }
}
