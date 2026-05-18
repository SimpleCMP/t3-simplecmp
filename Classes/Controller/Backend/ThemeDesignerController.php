<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ThemeRepository;

/**
 * BE module *Websites → SimpleCMP → Banner design*.
 *
 * Lets admins customize the FE consent banner's colors, typography,
 * and shape per Site Set without editing YAML or PHP. Tokens persist
 * in `tx_simplecmptypo3_theme` (one row per site identifier); the FE
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

    public function indexAction(string $site = ''): ResponseInterface
    {
        $availableSites = $this->availableSites();
        $site = $this->normalizeSite($site, $availableSites);
        $stored = $this->themeRepository->findBySite($site) ?? [];
        $tokens = array_merge(self::DEFAULT_TOKENS, $stored);
        $hasCustomTheme = $stored !== [];

        $moduleTemplate = $this->initModuleTemplate();
        $moduleTemplate->assignMultiple([
            'site' => $site,
            'availableSites' => $availableSites,
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
     * `wapplersystems/simplecmp` Site Set among their resolved
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
            if (in_array('wapplersystems/simplecmp', $site->getSets(), true)) {
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
            '@wapplersystems/simplecmp-typo3/Backend/Pagination.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@wapplersystems/simplecmp-typo3/Backend/ConfirmForm.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@wapplersystems/simplecmp-typo3/Backend/ThemePreview.js'
        );
        $this->pageRenderer->loadJavaScriptModule(
            '@wapplersystems/simplecmp-typo3/Backend/DetectFonts.js'
        );
        return $moduleTemplate;
    }
}
