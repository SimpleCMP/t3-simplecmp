<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\BeforeJavaScriptsRenderingEvent;
use TYPO3\CMS\Core\Site\Entity\Site;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ThemeRepository;
use SimpleCMP\T3SimpleCmp\Domain\Repository\TranslationOverrideRepository;
use SimpleCMP\T3SimpleCmp\Library\ServicesLibrary;
use SimpleCMP\T3SimpleCmp\Service\BridgeNonceService;
use SimpleCMP\T3SimpleCmp\Service\BridgeSecretProvider;

/**
 * Registers the SimpleCMP JS bundle + inline init call on every TYPO3
 * frontend page that has the SimpleCMP site set enabled.
 *
 * Listens on `BeforeJavaScriptsRenderingEvent` (Core's PageRenderer
 * dispatches this before composing the final <script> output) so the
 * additions land in the rendered HTML without TypoScript or middleware
 * gymnastics. Backend requests are filtered out via `ApplicationType`.
 *
 * Config flows from Site Settings (see
 * `Configuration/Sets/SimpleCmp/settings.definitions.yaml`) into the
 * `SimpleCMP.init(...)` call as a JSON-encoded object.
 */
#[AsEventListener(
    identifier: 'simplecmp-typo3/register-assets',
    event: BeforeJavaScriptsRenderingEvent::class,
)]
final readonly class RegisterAssets
{
    /**
     * Whitelist of SimpleCMP custom-element selectors that the theme
     * targets — same set as upstream `src/ui/styles/default.css`.
     *
     * Each upstream component imports `tokens.ts` and re-declares the
     * design tokens on its own `:host`, which **breaks the natural CSS
     * custom property cascade across shadow DOM boundaries**: a
     * light-DOM override on `simplecmp-modal` reaches the modal
     * element, but the nested `simplecmp-purpose-group` inside the
     * modal's shadow root resets the token via its own `:host` rule.
     *
     * Workaround: inject a `:host { --simplecmp-X: Y; }` rule into
     * every component's `adoptedStyleSheets` from JS. Adopted sheets
     * append after the component's `static styles`, so equal-
     * specificity `:host` rules tie and last-in wins.
     *
     * @var list<string>
     */
    private const array THEME_SELECTORS = [
        'simplecmp-banner',
        'simplecmp-modal',
        'simplecmp-purpose-group',
        'simplecmp-service-toggle',
        'simplecmp-trigger',
        'simplecmp-policy-links',
        'simplecmp-contextual-notice',
    ];

    public function __construct(
        private AssetCollector $assetCollector,
        private ServiceRepository $serviceRepository,
        private ThemeRepository $themeRepository,
        private TranslationOverrideRepository $overrideRepository,
        private BridgeSecretProvider $secretProvider,
        private BridgeNonceService $nonceService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BeforeJavaScriptsRenderingEvent $event): void
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return;
        }
        if (!ApplicationType::fromRequest($request)->isFrontend()) {
            return;
        }

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return;
        }

        $settings = $site->getSettings();
        if ($settings->get('simplecmp.enabled') === false) {
            return;
        }

        $config = $this->buildInitConfig($settings, $site);
        if ($config === null) {
            return;
        }

        // Bundle + init render in <head> via `priority => true`. Universal
        // pre-consent blocking (ADR-0013 Phase 2) needs the runtime
        // monkey-patches installed BEFORE any inline body script can
        // dispatch third-party requests — otherwise GTM-style loaders
        // race ahead of us. Upstream `init()` is body-aware: it installs
        // patches + creates the manager immediately, then defers the
        // banner/modal mount to DOMContentLoaded. So head-priority is
        // safe regardless of whether universalBlocking is on.
        // `csp => true` opts the asset into TYPO3 v14's nonce attachment
        // (see AssetRenderer::render — without this flag, the rendered
        // <script> tag has no nonce and the Report-Only / strict CSP
        // logs a script-src-elem violation against the inline init).
        $this->assetCollector->addJavaScript(
            'simplecmp-bundle',
            'EXT:t3_simplecmp/Resources/Public/JavaScript/simplecmp.global.js',
            [],
            ['priority' => true, 'csp' => true],
        );

        // Inline init right after — AssetCollector preserves insertion order
        // within each category, so the init call lands AFTER the bundle in
        // the head's priority bucket.
        $this->assetCollector->addInlineJavaScript(
            'simplecmp-init',
            sprintf(
                'window.SimpleCMP && window.SimpleCMP.init(%s);',
                json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ),
            [],
            ['priority' => true, 'csp' => true],
        );

        // Theme injection stays at the default (end of body) — its
        // MutationObserver attaches to `document.body`, which only
        // exists once parsing has progressed past <head>.
        $this->injectTheme($site);
    }

    /**
     * Emit the per-site banner theme as an inline `<script>` that
     * injects the overrides into every SimpleCMP component's shadow
     * root via `adoptedStyleSheets`. A `<style>` block in light DOM
     * wouldn't reach nested components (see THEME_SELECTORS comment).
     *
     * Skipped when no row exists for this site — falls back cleanly
     * to the bundle's built-in token defaults (`src/ui/styles/tokens.ts`).
     */
    private function injectTheme(Site $site): void
    {
        $tokens = $this->themeRepository->findBySite($site->getIdentifier());
        if ($tokens === null || $tokens === []) {
            return;
        }

        // Color-lock: when `colorPaletteLocked` is `1` (default), the
        // BE may have stored per-token color overrides anyway (they're
        // visible inside the "Eigene Farben" accordion) but the FE
        // ignores them and uses the audited SAFE_PALETTE values
        // instead. The lock is a UX guarantee: editors who clicked
        // away from custom colors get the verified set back, no
        // accidental compliance drift.
        $locked = ($tokens['colorPaletteLocked'] ?? '1') === '1';
        if ($locked) {
            foreach (\SimpleCMP\T3SimpleCmp\Controller\ThemeDesignerController::SAFE_PALETTE as $key => $safeValue) {
                $tokens[$key] = $safeValue;
            }
        }

        $declarations = [];
        foreach ($tokens as $token => $value) {
            if (!is_string($token) || !is_scalar($value)) {
                continue;
            }
            // `position` is a discrete enum — translate it into the three
            // upstream banner-placement CSS vars (`--simplecmp-banner-inset`,
            // `-transform`, `-max-width`) instead of a literal var that
            // the bundle wouldn't know how to consume.
            if ($token === 'position') {
                foreach ($this->positionDeclarations((string) $value) as $decl) {
                    $declarations[] = $decl;
                }
                continue;
            }
            // `theme`, `layout`, `colorPaletteLocked`, `triggerPosition`
            // are bundle config flags / BE-only state — not CSS vars.
            // Skip them so they don't pollute the shadow-DOM rule
            // (`triggerPosition` flows through cmp.init's
            // `floatingTrigger.position` instead).
            if (in_array($token, ['theme', 'layout', 'colorPaletteLocked', 'triggerPosition'], true)) {
                continue;
            }
            // `color-trigger-bg` is the optional trigger-button background
            // override. It needs a per-trigger rule scoped via
            // `:host(simplecmp-trigger)`, not the generic shared `:host`
            // rule — emit it separately below.
            if ($token === 'color-trigger-bg') {
                continue;
            }
            // Banner-button background overrides — per-button scoped
            // rules emitted below the host rule.
            if (in_array($token, ['color-accept-bg', 'color-decline-bg', 'color-configure-bg'], true)) {
                continue;
            }
            // Map our storage keys (`color-primary`, `radius`, …) to the
            // upstream CSS custom property names (`--simplecmp-color-primary`).
            // `!important` is required because the framework adapters
            // (bootstrap5 / tailwind4 / bulma / pico) inject a light-DOM
            // `<style>` with `:where(simplecmp-*) { --simplecmp-color-primary:
            //  var(--bs-primary); }` — that custom-property inherits into the
            // shadow DOM and wins over our shadow-root `:host` declaration
            // (inherited values trump :host-set values at equal specificity).
            // Without `!important` the editor's custom colours never reach
            // the rendered banner on the FE Live.
            $declarations[] = '--simplecmp-' . $token . ': ' . (string) $value . ' !important;';
        }

        $rules = [];
        if ($declarations !== []) {
            $rules[] = ':host { ' . implode(' ', $declarations) . ' }';
        }

        // Trigger-button background override. `:host(simplecmp-trigger)`
        // is scoped — when this rule is adopted into the banner's or
        // modal's shadow root it won't match. Only inside the trigger
        // shadow root does the host selector succeed and the rule
        // applies. `!important` because the bundle's static styles set
        // the trigger background from var(--simplecmp-color-primary)
        // with normal cascade weight, and we need to override that
        // without changing the primary token (which is used elsewhere).
        $triggerBg = $tokens['color-trigger-bg'] ?? '';
        if (is_string($triggerBg) && $triggerBg !== '') {
            $rules[] = ':host(simplecmp-trigger) button { background: ' . $triggerBg . ' !important; }';
            $rules[] = ':host(simplecmp-trigger) button:hover { background: ' . $triggerBg . ' !important; filter: brightness(0.92); }';
        }

        // Banner-button background overrides. Each is opt-in and scoped
        // via `:host(simplecmp-banner) .cn-<button>`. Setting any of
        // these breaks the BGH "Cookie II" equal-prominence baseline —
        // ComplianceCheckService surfaces the warning. `!important` so
        // these rules win over the bundle's `button { background: var(
        // --simplecmp-color-bg-alt) }` static style.
        $buttonOverrides = [
            'color-accept-bg' => '.cn-accept',
            'color-decline-bg' => '.cn-decline',
            'color-configure-bg' => '.cn-configure',
        ];
        foreach ($buttonOverrides as $tokenKey => $selector) {
            $value = $tokens[$tokenKey] ?? '';
            if (!is_string($value) || $value === '') {
                continue;
            }
            $rules[] = ':host(simplecmp-banner) ' . $selector . ' { background: ' . $value . ' !important; }';
            $rules[] = ':host(simplecmp-banner) ' . $selector . ':hover { background: ' . $value . ' !important; filter: brightness(0.92); }';
        }

        // Purpose-group: indent the "▾ N Dienst" toggle button so it
        // sits flush under the `.meta` block in the row above. The
        // bundle renders `.header` as a flex row with `checkbox +
        // .meta` (gap 8px; checkbox 13px wide with 4px/3px margins =
        // total 28px before .meta starts). The toggle button is
        // outside that flex row and starts at the host content edge —
        // shift it right by the same 28px to line up the visual
        // hierarchy. Scoped via :host(simplecmp-purpose-group) so the
        // rule is inert when the same sheet is adopted into other
        // simplecmp-* shadow roots.
        $rules[] = ':host(simplecmp-purpose-group) .toggle-services { margin-left: 28px; }';

        if ($rules === []) {
            return;
        }
        $css = implode(' ', $rules);
        $payload = [
            'css' => $css,
            'selectors' => self::THEME_SELECTORS,
        ];
        $script = '(function(){'
            . 'var payload = ' . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . ';'
            . 'var sheet = new CSSStyleSheet(); sheet.replaceSync(payload.css);'
            . 'function adopt(root) {'
                . 'payload.selectors.forEach(function(sel){'
                    . 'root.querySelectorAll(sel).forEach(function(el){'
                        . 'if (!el.shadowRoot) return;'
                        . 'if (el.shadowRoot.adoptedStyleSheets.indexOf(sheet) === -1) {'
                            . 'el.shadowRoot.adoptedStyleSheets = el.shadowRoot.adoptedStyleSheets.concat(sheet);'
                        . '}'
                        . 'adopt(el.shadowRoot);'
                    . '});'
                . '});'
            . '}'
            . 'adopt(document);'
            // Re-walk when the bundle mounts the modal lazily after Configure click.
            . 'new MutationObserver(function(){ adopt(document); })'
            . '.observe(document.body, { subtree: true, childList: true });'
            . '})();';
        $this->assetCollector->addInlineJavaScript(
            'simplecmp-theme-' . $site->getIdentifier(),
            $script,
            [],
            ['csp' => true],
        );
    }

    /**
     * Translate the persisted `position` token (one of nine
     * `<row>-<col>` keys) into the upstream banner-placement CSS
     * declarations. Returns an empty list if the value isn't a known
     * position key — so a hand-edited DB row with garbage falls back
     * silently to the upstream default (bottom-right).
     *
     * @return list<string>
     */
    /**
     * Read the saved `triggerPosition` token for the site and clamp it
     * to one of the four allowed corner enums. Falls back to
     * `bottom-right` if the token is missing or holds a tampered
     * value.
     */
    private function resolveTriggerPosition(Site $site): string
    {
        $tokens = $this->themeRepository->findBySite($site->getIdentifier()) ?? [];
        $candidate = $tokens['triggerPosition'] ?? null;
        if (is_string($candidate) && isset(\SimpleCMP\T3SimpleCmp\Controller\ThemeDesignerController::TRIGGER_POSITIONS[$candidate])) {
            return $candidate;
        }
        return 'bottom-right';
    }

    private function positionDeclarations(string $position): array
    {
        $defs = \SimpleCMP\T3SimpleCmp\Controller\ThemeDesignerController::POSITIONS[$position] ?? null;
        if ($defs === null) {
            return [];
        }
        $out = [
            '--simplecmp-banner-inset: ' . $defs['inset'] . ';',
            '--simplecmp-banner-transform: ' . $defs['transform'] . ';',
        ];
        if (!empty($defs['maxWidth'])) {
            $out[] = '--simplecmp-banner-max-width: ' . $defs['maxWidth'] . ';';
        }
        // Full-width bar variants look wrong with the card's rounded
        // corners and drop-shadow. Flatten them so the banner reads as
        // a notification bar rather than a stretched card.
        if (str_ends_with($position, '-full')) {
            $out[] = '--simplecmp-radius: 0;';
            $out[] = '--simplecmp-shadow: none;';
        }
        return $out;
    }

    /**
     * Map Site Settings → SimpleCMPConfig shape. Returns null when the
     * config is too incomplete to bother mounting (no privacy policy and
     * no services configured).
     *
     * @return array<string, mixed>|null
     */
    /**
     * Manual translation overrides per site, per language. The dotted-key
     * shape the BE designer stores (`consentNotice.description` → value)
     * is expanded into the nested
     * `{ <lang>: { consentNotice: { description: 'Hallo!' } } }` shape
     * that the bundle's translation tree expects.
     *
     * Tone selection is handled separately via `buildTones()` — the
     * bundle owns the curated formal/informal packs, so we only pass
     * the per-language tone flag, not the preset strings themselves.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildOverrideTranslations(string $siteIdentifier): array
    {
        $rows = $this->overrideRepository->findBySite($siteIdentifier);
        if ($rows === null || $rows === []) {
            return [];
        }
        $out = [];
        foreach ($rows as $lang => $entry) {
            $overrides = $entry['overrides'] ?? [];
            if ($overrides === []) {
                continue;
            }
            $tree = [];
            foreach ($overrides as $dottedKey => $value) {
                $this->assignNested($tree, explode('.', $dottedKey), $value);
            }
            if ($tree !== []) {
                $out[$lang] = $tree;
            }
        }
        return $out;
    }

    /**
     * Per-language tone flags for the bundle's `tones` config field.
     * Maps `<lang> => 'informal'` whenever the editor flipped the
     * tone switch in the BE designer. `'formal'` (the default) is
     * omitted — the bundle treats absence as formal.
     *
     * The bundle (`simplecmp` package, `src/engine/translations/informal/`)
     * owns the curated du/tu/tú/… overlays; this method just signals
     * which language should pull which register.
     *
     * @return array<string, 'informal'>
     */
    private function buildTones(string $siteIdentifier): array
    {
        $rows = $this->overrideRepository->findBySite($siteIdentifier);
        if ($rows === null || $rows === []) {
            return [];
        }
        $tones = [];
        foreach ($rows as $lang => $entry) {
            if (($entry['tone'] ?? null) === 'informal') {
                $tones[$lang] = 'informal';
            }
        }
        return $tones;
    }

    /**
     * Recursively assign `$value` into `$tree` at the path described
     * by `$path`. Intermediate keys become nested arrays.
     *
     * @param array<string, mixed> $tree
     * @param list<string> $path
     */
    private function assignNested(array &$tree, array $path, mixed $value): void
    {
        $node = &$tree;
        $last = array_pop($path);
        foreach ($path as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        if ($last !== null) {
            $node[$last] = $value;
        }
    }

    /**
     * Deep-merge `$override` on top of `$base`. Scalar values in
     * `$override` win; arrays merge recursively. Used to layer the
     * designer's overrides on top of the services-library
     * translations without losing intermediate keys either side has
     * defined.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeTranslationsDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                isset($base[$key])
                && is_array($base[$key])
                && is_array($value)
            ) {
                $base[$key] = $this->mergeTranslationsDeep($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }
        return $base;
    }

    private function buildInitConfig(object $settings, Site $site): ?array
    {
        // `??` (null-coalesce), NOT `?:` (truthy-fallback). The Elvis form
        // swallows an explicit `false` / `0` / `''` and replaces it with the
        // default — which silently inverted booleans like `respectGPC` and
        // `universalBlocking.enabled` when their declared defaults disagreed
        // with what the admin actually set.
        $get = static fn (string $key, mixed $default = null): mixed
            => $settings->get($key) ?? $default;

        [$services, $serviceTranslations] = $this->buildRuntimeServices();
        $config = [
            'storageName' => $get('simplecmp.storageName') ?: 'simplecmp-' . $site->getIdentifier(),
            'services' => $services,
            'respectGPC' => (bool) $get('simplecmp.respectGPC', true),
            // Show "Accept all" alongside "Decline" and "Save selected" in the
            // modal footer so returning users can bulk-toggle from the trigger.
            'acceptAll' => true,
            'floatingTrigger' => [
                'label' => (string) $get('simplecmp.floatingTriggerLabel', 'Cookie settings'),
                // Trigger corner is themed per site via the BE
                // Designer's `triggerPosition` token. Defaults to
                // bottom-right; the bundle's `<simplecmp-trigger>`
                // component reads this and applies its corresponding
                // `:host([position='…'])` rule.
                'position' => $this->resolveTriggerPosition($site),
            ],
        ];
        // Resolution chain (lowest → highest precedence):
        //   1. bundle defaults (formal register)
        //   2. tone overlays — passed via `config.tones`; the bundle's
        //      `getConfigTranslations` overlays its curated informal
        //      packs for any language we mark `informal` here
        //   3. service translations from the services library
        //   4. per-site BE-designer manual overrides — last wins so an
        //      editor's hand-written string beats both the tone preset
        //      and the bundle default
        $tones = $this->buildTones($site->getIdentifier());
        if ($tones !== []) {
            $config['tones'] = $tones;
        }
        // `theme` lives in the same theme repo (per-site row) as the
        // colour / typography tokens — the BE designer surfaces all
        // of them in one form. Forward to the bundle's `theme` config
        // field so it can inject the matching framework adapter
        // (e.g. Bootstrap 5's `--bs-*` mapping). Suppress when
        // `default` so the bundle treats it as unset.
        $themeTokens = $this->themeRepository->findBySite($site->getIdentifier()) ?? [];
        $themeChoice = isset($themeTokens['theme']) ? (string) $themeTokens['theme'] : 'default';
        if ($themeChoice !== '' && $themeChoice !== 'default') {
            $config['theme'] = $themeChoice;
        }
        // Banner-template picker — same persistence path as theme,
        // forwarded to the bundle's `layout` config field. `standard`
        // is the bundle's own default; suppress to keep the init
        // payload minimal.
        $layoutChoice = isset($themeTokens['layout']) ? (string) $themeTokens['layout'] : 'standard';
        if ($layoutChoice !== '' && $layoutChoice !== 'standard') {
            $config['layout'] = $layoutChoice;
        }
        $translations = $serviceTranslations;
        $overrideTranslations = $this->buildOverrideTranslations($site->getIdentifier());
        if ($overrideTranslations !== []) {
            $translations = $this->mergeTranslationsDeep($translations, $overrideTranslations);
        }
        if ($translations !== []) {
            $config['translations'] = $translations;
        }

        $privacy = (string) $get('simplecmp.privacyPolicyUrl', '');
        if ($privacy !== '') {
            $config['privacyPolicy'] = $privacy;
        }

        $imprint = (string) $get('simplecmp.imprintUrl', '');
        if ($imprint !== '') {
            $config['imprint'] = $imprint;
        }

        $serviceDbUrl = (string) $get('simplecmp.serviceDbUrl', '');
        if ($serviceDbUrl !== '') {
            $config['serviceDbUrl'] = $this->normalizeServiceDbUrl($serviceDbUrl);
            // The recorder must be running for the bridge to fire — opt
            // into record mode automatically when an admin has configured
            // a remote data sink (service DB or CMS bridge).
            $config['record'] = ['silenceProductionWarning' => true];
        }

        $cmsBridgeUrl = (string) $get('simplecmp.cmsBridgeUrl', '');
        if ($cmsBridgeUrl !== '') {
            if (!$this->secretProvider->isConfigured()) {
                // Refuse-until-configured. The bridge would otherwise POST
                // unauthenticated traffic to a receiver that should reject
                // it — silent breakage. Surface clearly and skip the bridge
                // config entirely so the rest of SimpleCMP still works.
                $this->logger->warning(
                    'SimpleCMP cmsBridgeUrl is configured but bridgeSecret is missing — '
                    . 'bridge will not be enabled on this site. The BE module normally '
                    . 'generates one on first access; if you see this warning, the '
                    . 'auto-gen path didn\'t run (e.g. config/system/settings.php not '
                    . 'writable by PHP). Open the SimpleCMP BE module to retry, or run '
                    . '`vendor/bin/typo3 simplecmp:generate-bridge-secret` and paste '
                    . 'the printed value into your TYPO3 config.',
                );
            } else {
                $config['cmsBridgeUrl'] = $cmsBridgeUrl;
                $config['cmsBridgeAuth'] = [
                    'token' => $this->nonceService->issue((string) $config['storageName']),
                ];
                $config['record'] = ['silenceProductionWarning' => true];
            }
        }

        if ((bool) $get('simplecmp.universalBlocking.enabled', true)) {
            // Pair with the Phase 1 server-side HtmlRewriter activated
            // by the same setting. Server-side covers declarative tags;
            // this opts the FE bundle into the runtime monkey-patches
            // that gate JS-injected scripts / iframes / pixels.
            //
            // `universalBlock: true` switches the FE matcher into the
            // strict "block everything third-party" posture — any host
            // not in config.services AND not in the admin allowlist is
            // gated using the host itself as a synthetic service id.
            // Same admin-curated allowlist that the HtmlRewriter uses
            // (`simplecmp.universalBlocking.allowlist`) flows through
            // as `sameOriginHosts`; window.location.host is added
            // implicitly by the runtime patches.
            $allowlistRaw = $settings->get('simplecmp.universalBlocking.allowlist');
            $sameOriginExtras = [];
            if (is_array($allowlistRaw)) {
                foreach ($allowlistRaw as $entry) {
                    if (is_string($entry) && $entry !== '') {
                        $sameOriginExtras[] = $entry;
                    }
                }
            }
            $config['interceptRuntime'] = [
                'universalBlock' => true,
                'sameOriginHosts' => $sameOriginExtras,
            ];

            // `libraryFallback` carries minimal per-service metadata
            // (currently just `purposes`) for library entries the
            // admin hasn't curated into `config.services`. The FE
            // contextual-notice's state-2 render mode (library-known,
            // not configured) reads this to surface the "Zwecke:
            // Marketing, Statistik" line under the description so
            // visitors see WHY they'd be loading the content — without
            // shipping the entire library to FE.
            //
            // Only emitted under universalBlocking because that's the
            // only path that produces state-2 notices.
            $config['libraryFallback'] = $this->buildLibraryFallback();
        }

        if ($privacy === '' && $config['services'] === []) {
            // Nothing useful to mount — skip the asset registration so
            // we don't ship dead JS on every page.
            return null;
        }

        return $config;
    }

    /**
     * Defensive normalization for the `serviceDbUrl` Site Set setting.
     *
     * The SimpleCMP JS client appends `/v1/<endpoint>` itself, so the
     * configured value must be the base URL **without** the protocol-
     * version segment. Admins who paste a URL ending in `/v1` (mirror
     * of what the protocol doc shows in examples) get double-`/v1/v1/`
     * 404s. Strip trailing slashes and a trailing `/v1` and warn so
     * the auto-correction is visible in the TYPO3 log.
     */
    /**
     * Build the `libraryFallback` map for the FE notice's state-2
     * render mode. Keyed by library service id (matches what
     * `HtmlRewriter` puts in `data-name` for library-recognised
     * hosts). Forwarded fields:
     *
     * - `purposes` — drives the "Zwecke: Marketing, Statistik" line
     *   under the placeholder description.
     * - `vendor`, `vendorCountry`, `vendorAddress`, `vendorOptOutUrl`,
     *   `vendorPartner`, `vendorDescription`, `privacyPolicyUrl` —
     *   the L2 Provider-Informationen modal fields (REQ-19). Without
     *   forwarding these, state-2 services would show only a "More
     *   information ›" link with an effectively-empty modal.
     *
     * Size budget (as of 2026-05-27, 368 entries; 32 with curated
     * provider data via services-library v0.3.0):
     *   - raw JSON:  ~65 KB
     *   - gzipped:   ~9.5 KB
     *
     * The `LIBRARY_FALLBACK_RAW_BUDGET_BYTES` constant (100 KB raw ≈
     * 14 KB gzipped) marks where inlining stops being clearly-better-
     * than-an-extra-fetch: an additional HTTP roundtrip costs
     * ~30-50ms; inlined data > ~15 KB gzipped starts pushing past that.
     * Bumped from 50 KB after Phase A.3 curation rolled in; the
     * payload grew ~4× without adding a roundtrip.
     *
     * If the library grows past the budget we log a warning so future-
     * us knows to consider lazy-loading via a same-origin endpoint
     * (e.g. `/api/simplecmp/v1/library-fallback.json` cached 24h, plugin
     * proxies it like everything else). For now: inline is fine.
     *
     * @return array<string, array{
     *     purposes?: list<string>,
     *     vendor?: string,
     *     vendorCountry?: string,
     *     vendorAddress?: string,
     *     vendorOptOutUrl?: string,
     *     vendorPartner?: string,
     *     vendorDescription?: string,
     *     privacyPolicyUrl?: string,
     * }>
     */
    private const int LIBRARY_FALLBACK_RAW_BUDGET_BYTES = 100000;

    private const array LIBRARY_FALLBACK_VENDOR_FIELDS = [
        'vendor',
        'vendorCountry',
        'vendorAddress',
        'vendorOptOutUrl',
        'vendorPartner',
        'vendorDescription',
        'privacyPolicyUrl',
    ];

    private function buildLibraryFallback(): array
    {
        $fallback = [];
        foreach (ServicesLibrary::services() as $service) {
            $id = (string) ($service['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $entry = [];

            $purposes = $service['purposes'] ?? [];
            if (is_array($purposes)) {
                $clean = [];
                foreach ($purposes as $p) {
                    if (is_string($p) && $p !== '') {
                        $clean[] = $p;
                    }
                }
                if ($clean !== []) {
                    $entry['purposes'] = $clean;
                }
            }

            // L2 Provider-Informationen fields — forward when present
            // and non-empty. The FE modal renders each field
            // independently, hiding any that are missing, so a partial
            // set is fine.
            foreach (self::LIBRARY_FALLBACK_VENDOR_FIELDS as $field) {
                $value = $service[$field] ?? null;
                if (is_string($value) && $value !== '') {
                    $entry[$field] = $value;
                }
            }

            if ($entry !== []) {
                $fallback[$id] = $entry;
            }
        }

        $rawSize = strlen((string) json_encode($fallback));
        if ($rawSize > self::LIBRARY_FALLBACK_RAW_BUDGET_BYTES) {
            $this->logger->warning(
                sprintf(
                    'SimpleCMP libraryFallback payload exceeded inline budget '
                    . '(%d bytes raw JSON, budget %d). Consider lazy-loading '
                    . 'via a same-origin endpoint instead of inlining. See '
                    . 'buildLibraryFallback() docblock.',
                    $rawSize,
                    self::LIBRARY_FALLBACK_RAW_BUDGET_BYTES,
                ),
            );
        }

        return $fallback;
    }

    private function normalizeServiceDbUrl(string $url): string
    {
        $stripped = rtrim($url, '/');
        if (str_ends_with($stripped, '/v1')) {
            $corrected = substr($stripped, 0, -3);
            $this->logger->warning(
                'simplecmp.serviceDbUrl ended in "/v1"; auto-stripping — '
                . 'the JS client appends the protocol-version segment itself. '
                . 'Configured: {configured}, sent to JS: {corrected}.',
                ['configured' => $url, 'corrected' => $corrected],
            );
            return $corrected;
        }
        return $stripped;
    }

    /**
     * Map the protocol-shaped rows from `ServiceRepository` to the runtime
     * `Service` shape the JS `init()` consumes, plus a `translations` block
     * carrying per-service title/description for the UI's lookup at
     * `services.<service_id>.{title,description}`.
     *
     * Title precedence:
     * - per-language: `i18n.title.<lang>` from the DB row (rarely set)
     * - fallback (`zz` language): the DB `name` column — the canonical
     *   display name, language-neutral if no localizations exist.
     *
     * Description precedence is the same: `i18n.description.<lang>` →
     * `zz` fallback from the DB `description` column.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function buildRuntimeServices(): array
    {
        // The registry only ever holds admin-curated services post-
        // fe_visible architecture — every row appears on the FE banner.
        // Classifier coverage for library cookies is consulted via the
        // bundled `simplecmp/services-library` JSONs at lookup time.
        $rows = $this->serviceRepository->findAll();
        $services = [];
        $translations = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $service = [
                // `name` is the consent key the manager tracks state under.
                'name' => $id,
                'purposes' => $row['purposes'] ?? [],
            ];
            $cookies = $row['matches']['cookies'] ?? [];
            if ($cookies !== []) {
                $service['cookies'] = $cookies;
            }
            $origins = $row['matches']['origins'] ?? [];
            if ($origins !== []) {
                $service['origins'] = $origins;
            }
            if (isset($row['privacyPolicyUrl'])) {
                $service['privacyPolicyUrl'] = (string) $row['privacyPolicyUrl'];
            }
            if (isset($row['vendor'])) {
                $service['vendor'] = (string) $row['vendor'];
            }
            if (isset($row['vendorCountry'])) {
                $service['vendorCountry'] = (string) $row['vendorCountry'];
            }
            // Click-to-enable placeholder copy. The component (engine
            // side) resolves in this order:
            //   service.placeholderTitle (JS-config override) →
            //   translations[lang]<service>.placeholderTitle? →
            //   translations[lang]<service>.title? → asTitle(name)
            // We surface the library's canonical English string through
            // the translations table (under `zz`, the engine's fallback
            // language) instead of setting it as a service property —
            // that lets per-language overlays from `i18n.placeholderX`
            // actually win when the active language differs from the
            // fallback. Same pattern as title / description above.
            $services[] = $service;

            $translations['zz'][$id]['title'] = (string) $row['name'];
            if (isset($row['description'])) {
                $translations['zz'][$id]['description'] = (string) $row['description'];
            }
            if (isset($row['placeholderTitle']) && is_string($row['placeholderTitle'])) {
                $translations['zz'][$id]['placeholderTitle'] = $row['placeholderTitle'];
            }
            if (isset($row['placeholderDescription']) && is_string($row['placeholderDescription'])) {
                $translations['zz'][$id]['placeholderDescription'] = $row['placeholderDescription'];
            }
            $i18n = is_array($row['i18n'] ?? null) ? $row['i18n'] : [];
            foreach (['title', 'description', 'placeholderTitle', 'placeholderDescription'] as $field) {
                $perLang = $i18n[$field] ?? null;
                if (!is_array($perLang)) {
                    continue;
                }
                foreach ($perLang as $lang => $value) {
                    if (!is_string($value) || $value === '') {
                        continue;
                    }
                    $translations[(string) $lang][$id][$field] = $value;
                }
            }
        }
        return [$services, $translations];
    }
}
