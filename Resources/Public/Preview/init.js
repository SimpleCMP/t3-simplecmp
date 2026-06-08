/**
 * Preview iframe init — runs inside the BE Banner-Design module's
 * right-pane iframe. Boots the real SimpleCMP bundle with a synthetic
 * three-service config, then listens for postMessages from the parent
 * window carrying the form's current token values; applies them to
 * each SimpleCMP component's shadow root.
 *
 * The shadow-root injection (rather than a light-DOM `<style>` block)
 * is required because every component imports `tokens.ts` and
 * re-declares the design tokens on `:host` — so a light-DOM override
 * only reaches top-level elements (banner, modal) and gets reset at
 * every nested component boundary (purpose-group, service-toggle).
 * Adopting a stylesheet into each shadow root sidesteps the cascade
 * trap: the adopted sheet appends after `static styles`, equal-
 * specificity `:host { ... }` rules tie, last-in wins.
 */
const cmp = window.SimpleCMP;

// Language for the SimpleCMP bundle — driven by `?lang=<code>` set by
// the BE module's language picker so the editor previews the banner in
// whichever locale they pick. Falls back to `en` if the query is
// missing or contains anything other than a 2/3-letter code.
const previewParams = new URLSearchParams(window.location.search);
const previewLang = (() => {
  const raw = previewParams.get('lang') || '';
  return /^[a-z]{2,3}$/i.test(raw) ? raw.toLowerCase() : 'en';
})();

// Privacy + Imprint URLs come from the site's Settings via the BE
// module — the controller reads them and the template puts them on
// the iframe URL. Fallback to `#` so the banner still renders both
// links and the editor sees the layout even when no URL is
// configured. The link won't navigate anywhere in that state, which
// is fine for a designer preview.
const previewPrivacy = previewParams.get('privacy') || '#';
const previewImprint = previewParams.get('imprint') || '#';

// Tone for the active preview language. The bundle ships curated
// informal-tone overlays under `simplecmp/src/engine/translations/
// informal/<lang>.json`; passing `tones: { <lang>: 'informal' }` to
// `cmp.init()` activates the overlay. Default is formal (no overlay).
const previewTone = (() => {
  const raw = (previewParams.get('tone') || '').toLowerCase();
  return raw === 'informal' ? 'informal' : 'formal';
})();
const previewTones = previewTone === 'informal' ? { [previewLang]: 'informal' } : undefined;

// CSS-framework adapter — the bundle ships `default` (no adapter),
// `bootstrap5` (`--bs-*`), and `tailwind4` (`@theme` tokens with
// shadcn/ui semantic-name fallback). Anything else falls through to
// `default` so a typo doesn't break the preview. The bundle warns on
// unknown values at its own end. Keep this whitelist in sync with
// `ThemeDesignerController::THEMES`.
const KNOWN_THEMES = new Set(['bootstrap5', 'tailwind4', 'bulma', 'pico']);
const previewTheme = (() => {
  const raw = (previewParams.get('theme') || '').toLowerCase();
  return KNOWN_THEMES.has(raw) ? raw : 'default';
})();

// Banner-template picker — `standard` (default), `compact`,
// `stacked`. Unknown values fall back to `standard` so a typo
// doesn't break the preview. Keep in sync with
// `ThemeDesignerController::LAYOUTS`.
const KNOWN_LAYOUTS = new Set(['compact', 'stacked']);
const previewLayout = (() => {
  const raw = (previewParams.get('layout') || '').toLowerCase();
  return KNOWN_LAYOUTS.has(raw) ? raw : 'standard';
})();

// Per-site translation overrides for the active preview language —
// the controller base64-encodes the dotted-key → value map and the
// template puts it on the iframe URL. Decode and expand to a nested
// tree so it can be deep-merged into `cmp.init`'s `translations`
// config under the active lang code.
function decodeOverrides(raw, lang) {
  if (!raw) return null;
  let json;
  try {
    // `atob()` returns a binary string; the bundled JSON is UTF-8
    // (Umlauts, accents, …). Decode the byte sequence explicitly so
    // multi-byte characters survive — otherwise "Können" arrives as
    // "KÃ¶nnen" in the preview.
    const binary = atob(raw);
    const bytes = Uint8Array.from(binary, (c) => c.charCodeAt(0));
    json = JSON.parse(new TextDecoder('utf-8').decode(bytes));
  } catch (_) {
    return null;
  }
  if (!json || typeof json !== 'object') return null;
  const tree = {};
  for (const [dotted, value] of Object.entries(json)) {
    if (typeof value !== 'string' || value === '') continue;
    const path = dotted.split('.');
    let node = tree;
    for (let i = 0; i < path.length - 1; i++) {
      const seg = path[i];
      if (typeof node[seg] !== 'object' || node[seg] === null) {
        node[seg] = {};
      }
      node = node[seg];
    }
    node[path[path.length - 1]] = value;
  }
  return Object.keys(tree).length > 0 ? { [lang]: tree } : null;
}

const previewOverrides = decodeOverrides(previewParams.get('overrides'), previewLang);

function deepMerge(base, override) {
  for (const [key, value] of Object.entries(override || {})) {
    if (
      base[key]
      && typeof base[key] === 'object'
      && !Array.isArray(base[key])
      && typeof value === 'object'
      && value !== null
      && !Array.isArray(value)
    ) {
      base[key] = deepMerge(base[key], value);
    } else {
      base[key] = value;
    }
  }
  return base;
}

// Mirror onto <html lang="…"> so the bundle's own detector
// (`document.documentElement.lang`) lands on the same value if its
// explicit `lang` config ever falls through.
document.documentElement.lang = previewLang;

// Service-specific copy baked here; the BE-designer overrides come in
// via `previewOverrides` and get deep-merged on top before
// `cmp.init()` reads the final tree. Keeping the baked block as a
// separate variable lets us merge cleanly without duplicating the
// init-config literal.
const baseTranslations = {
      zz: {
        'preview-functional': {
          title: 'Essential services',
          description: 'Required for the site to function correctly.',
        },
        'preview-analytics': {
          title: 'Analytics',
          description: 'Anonymous visitor statistics so we can improve the site.',
        },
        'preview-marketing': {
          title: 'Marketing',
          description: 'Personalised offers based on your interests.',
        },
      },
      en: {
        'preview-functional': {
          title: 'Essential services',
          description: 'Required for the site to function correctly.',
        },
        'preview-analytics': {
          title: 'Analytics',
          description: 'Anonymous visitor statistics so we can improve the site.',
        },
        'preview-marketing': {
          title: 'Marketing',
          description: 'Personalised offers based on your interests.',
        },
      },
      de: {
        'preview-functional': {
          title: 'Essenzielle Dienste',
          description: 'Notwendig, damit die Seite korrekt funktioniert.',
        },
        'preview-analytics': {
          title: 'Analyse',
          description: 'Anonyme Besucherstatistik zur Verbesserung der Seite.',
        },
        'preview-marketing': {
          title: 'Marketing',
          description: 'Personalisierte Angebote auf Basis Ihrer Interessen.',
        },
      },
      fr: {
        'preview-functional': {
          title: 'Services essentiels',
          description: 'Nécessaires au bon fonctionnement du site.',
        },
        'preview-analytics': {
          title: 'Analyse',
          description: 'Statistiques de visiteurs anonymes pour améliorer le site.',
        },
        'preview-marketing': {
          title: 'Marketing',
          description: 'Offres personnalisées basées sur vos centres d’intérêt.',
        },
      },
      it: {
        'preview-functional': {
          title: 'Servizi essenziali',
          description: 'Necessari per il corretto funzionamento del sito.',
        },
        'preview-analytics': {
          title: 'Analisi',
          description: 'Statistiche anonime dei visitatori per migliorare il sito.',
        },
        'preview-marketing': {
          title: 'Marketing',
          description: 'Offerte personalizzate in base ai tuoi interessi.',
        },
      },
      es: {
        'preview-functional': {
          title: 'Servicios esenciales',
          description: 'Necesarios para el correcto funcionamiento del sitio.',
        },
        'preview-analytics': {
          title: 'Analítica',
          description: 'Estadísticas anónimas de visitantes para mejorar el sitio.',
        },
        'preview-marketing': {
          title: 'Marketing',
          description: 'Ofertas personalizadas según tus intereses.',
        },
      },
      nl: {
        'preview-functional': {
          title: 'Essentiële diensten',
          description: 'Noodzakelijk voor de juiste werking van de site.',
        },
        'preview-analytics': {
          title: 'Analyse',
          description: 'Anonieme bezoekersstatistieken om de site te verbeteren.',
        },
        'preview-marketing': {
          title: 'Marketing',
          description: 'Gepersonaliseerde aanbiedingen op basis van uw interesses.',
        },
      },
};

// Merge BE-designer overrides into the baked block. `previewOverrides`
// is already shaped as `{ <lang>: { …nested… } }` so we can deep-merge
// it directly; missing-language case falls through to the base block.
const mergedTranslations = previewOverrides
  ? deepMerge({ ...baseTranslations }, previewOverrides)
  : baseTranslations;

if (cmp && typeof cmp.init === 'function') {
  const initConfig = {
    storageName: 'simplecmp-preview',
    testing: true,
    // Always pass both URLs so the upstream policy-links rendering
    // logic (banner + modal) emits the two-link box layout — the
    // imprint link must appear next to the privacy link whenever an
    // imprint URL is configured. `#` placeholders keep the layout
    // demoable when nothing is configured yet.
    privacyPolicy: previewPrivacy,
    imprint: previewImprint,
    lang: previewLang,
    // Default upstream is "zz" (the placeholder block); fall back to
    // English so locales we don't ship strings for at least show
    // readable defaults instead of placeholder copy.
    fallbackLang: 'en',
    services: [
      { name: 'preview-functional', purposes: ['functional'], required: true },
      { name: 'preview-analytics', purposes: ['analytics'] },
      { name: 'preview-marketing', purposes: ['marketing'] },
    ],
    translations: mergedTranslations,
  };
  if (previewTones) initConfig.tones = previewTones;
  if (previewTheme !== 'default') initConfig.theme = previewTheme;
  if (previewLayout !== 'standard') initConfig.layout = previewLayout;
  // Render the floating trigger so the editor can see it in the
  // preview — without a `floatingTrigger` entry the bundle skips it,
  // which would hide whatever `triggerPosition` value the editor just
  // picked. Default position is bottom-right, overridden by the
  // `triggerPosition` query param when present.
  const previewTriggerPosition = (previewParams.get('triggerPosition') || 'bottom-right').toLowerCase();
  initConfig.floatingTrigger = {
    label: 'Cookie settings',
    position: previewTriggerPosition,
  };
  cmp.init(initConfig);

  // Run the DOM-level compliance audit after the banner mounts and
  // post results to the parent so the BE designer can merge them
  // with the server-rendered config-audit findings. Two rAF frames
  // give the bundle time to paint the banner inside its own rAF
  // cycle and for the shadow-DOM style adoption to land — a single
  // rAF can race against that. If somehow the banner isn't visible
  // yet (e.g. consent already granted in a prior preview),
  // `auditDom()` returns "no banner mounted" infos that the parent
  // renders as skipped — harmless.
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      const audit = window.SimpleCMP?.auditDom;
      if (typeof audit !== 'function') return;
      const results = audit();
      try {
        window.parent?.postMessage({ type: 'simplecmp-dom-audit', results }, '*');
      } catch (_) {
        // Parent may be cross-origin in unusual setups; the BE module
        // is always same-origin so this is just defensive.
      }
    });
  });

  // Make Accept/Decline clicks inert in the preview. Real FE flows
  // (`manager.saveAndApplyConsents`, `simplecmp:accept|decline` events,
  // localStorage writes, banner unmount) are visitor concerns; the BE
  // designer is purely cosmetic and side-effects pollute the preview
  // iframe's storage + risk confusing future observers.
  //
  // Capture-phase listener on the iframe document catches the event
  // before it reaches the button's Lit handler inside the shadow DOM.
  // Configure passes through unchanged so admins can open the modal
  // to preview its theming.
  document.addEventListener('click', (event) => {
    const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
    for (const node of path) {
      if (!(node instanceof Element)) continue;
      if (node.classList?.contains('cn-accept') || node.classList?.contains('cn-decline')) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
      }
      // Stop walking once we reach a button — `.cn-configure` doesn't
      // match the conditions above and falls through cleanly.
      if (node.tagName === 'BUTTON') return;
    }
  }, true);
}

const SELECTORS = [
  'simplecmp-banner',
  'simplecmp-modal',
  'simplecmp-purpose-group',
  'simplecmp-service-toggle',
  'simplecmp-trigger',
  'simplecmp-policy-links',
  'simplecmp-contextual-notice',
];

const themeSheet = new CSSStyleSheet();
themeSheet._simplecmpTheme = true;

function adoptInto(root) {
  for (const sel of SELECTORS) {
    root.querySelectorAll(sel).forEach((el) => {
      if (!el.shadowRoot) return;
      if (!el.shadowRoot.adoptedStyleSheets.includes(themeSheet)) {
        el.shadowRoot.adoptedStyleSheets = [
          ...el.shadowRoot.adoptedStyleSheets,
          themeSheet,
        ];
      }
      adoptInto(el.shadowRoot);
    });
  }
}

// Banner-position keys → upstream CSS vars. Mirror of the PHP-side
// `ThemeDesignerController::POSITIONS` const. Keep in sync; the values
// have to match upstream `banner.ts`'s `--simplecmp-banner-{inset,
// transform, max-width}` tokens.
const BANNER_POSITION_DECLS = {
  'top-left':      { inset: 'var(--simplecmp-spacing) auto auto var(--simplecmp-spacing)', transform: 'none', maxWidth: null },
  'top-center':    { inset: 'var(--simplecmp-spacing) auto auto 50%', transform: 'translateX(-50%)', maxWidth: 'min(30rem, calc(100vw - 2 * var(--simplecmp-spacing)))' },
  'top-right':     { inset: 'var(--simplecmp-spacing) var(--simplecmp-spacing) auto auto', transform: 'none', maxWidth: null },
  'middle-left':   { inset: '50% auto auto var(--simplecmp-spacing)', transform: 'translateY(-50%)', maxWidth: null },
  'middle-center': { inset: '50% auto auto 50%', transform: 'translate(-50%, -50%)', maxWidth: 'min(30rem, calc(100vw - 2 * var(--simplecmp-spacing)))' },
  'middle-right':  { inset: '50% var(--simplecmp-spacing) auto auto', transform: 'translateY(-50%)', maxWidth: null },
  'bottom-left':   { inset: 'auto auto var(--simplecmp-spacing) var(--simplecmp-spacing)', transform: 'none', maxWidth: null },
  'bottom-center': { inset: 'auto auto var(--simplecmp-spacing) 50%', transform: 'translateX(-50%)', maxWidth: 'min(30rem, calc(100vw - 2 * var(--simplecmp-spacing)))' },
  'bottom-right':  { inset: 'auto var(--simplecmp-spacing) var(--simplecmp-spacing) auto', transform: 'none', maxWidth: null },
  'top-full':      { inset: '0 0 auto 0',                                                 transform: 'none', maxWidth: '100%' },
  'bottom-full':   { inset: 'auto 0 0 0',                                                 transform: 'none', maxWidth: '100%' },
};

function applyTokens(tokens) {
  const decls = [];
  // `!important` is required: framework adapters (bootstrap5,
  // tailwind4, …) inject a light-DOM `<style data-simplecmp-theme>`
  // with the same custom-property keys. In CSS-variable cascade those
  // adapter rules win over our shadow-DOM `:host` re-definitions —
  // probably because the adapter's `:where(simplecmp-banner)` matches
  // the light-DOM tree directly, and the resulting computed value
  // becomes the inherited value the shadow tree sees. `!important`
  // forces our override regardless of which adapter is currently
  // active.
  for (const [key, value] of Object.entries(tokens || {})) {
    if (typeof key !== 'string' || typeof value !== 'string' || value === '') {
      continue;
    }
    // `position` is a discrete enum — expand into the three upstream
    // banner-placement vars instead of a literal `--simplecmp-position`
    // that the bundle wouldn't read.
    if (key === 'position') {
      const def = BANNER_POSITION_DECLS[value];
      if (!def) continue;
      decls.push(`--simplecmp-banner-inset: ${def.inset} !important;`);
      decls.push(`--simplecmp-banner-transform: ${def.transform} !important;`);
      if (def.maxWidth) decls.push(`--simplecmp-banner-max-width: ${def.maxWidth} !important;`);
      // Full-width bar variants flatten the card silhouette so the
      // result reads as a notification bar, mirroring the FE-side
      // declarations from RegisterAssets::positionDeclarations().
      if (value === 'top-full' || value === 'bottom-full') {
        decls.push('--simplecmp-radius: 0 !important;');
        decls.push('--simplecmp-shadow: none !important;');
      }
      continue;
    }
    // `colorPaletteLocked` is a BE-only state flag — not a CSS var.
    // Skip it so it doesn't pollute the `:host` rule.
    if (key === 'colorPaletteLocked') {
      continue;
    }
    // `triggerPosition` only takes effect at cmp.init() time — handled
    // by the iframe-src-swap in ThemePreview.js, not by CSS-var live-
    // updates. Skip it here too.
    if (key === 'triggerPosition') {
      continue;
    }
    // `color-trigger-bg` is an optional override of the trigger button
    // background. The bundle's static styles set the trigger bg from
    // `var(--simplecmp-color-primary)`, so we need a per-trigger rule
    // scoped via `:host(simplecmp-trigger)` rather than a custom-prop
    // declaration on the shared :host. Handled separately below.
    if (key === 'color-trigger-bg') {
      continue;
    }
    decls.push(`--simplecmp-${key}: ${value} !important;`);
  }
  const rules = [];
  if (decls.length > 0) {
    rules.push(`:host { ${decls.join(' ')} }`);
  }
  // Trigger-button background override. Mirrors the FE-side rule
  // from RegisterAssets::injectTheme(). `:host(simplecmp-trigger)` is
  // scoped, so when this sheet is adopted into the banner or modal
  // shadow root the rule is inert there — only inside the trigger
  // shadow root does it match and override the default
  // `background: var(--simplecmp-color-primary)`.
  const triggerBg = tokens?.['color-trigger-bg'];
  if (typeof triggerBg === 'string' && triggerBg !== '') {
    rules.push(`:host(simplecmp-trigger) button { background: ${triggerBg} !important; }`);
    rules.push(`:host(simplecmp-trigger) button:hover { background: ${triggerBg} !important; filter: brightness(0.92); }`);
  }
  // Purpose-group: indent the "▾ N Dienst" toggle button so it lines
  // up under the .meta block above. Mirror of the FE-side rule from
  // RegisterAssets::injectTheme(). The 28px equals the checkbox's
  // total occupied width (margin-left 4px + width 13px + margin-right
  // 3px + flex gap 8px) inside the .header row.
  rules.push(`:host(simplecmp-purpose-group) .toggle-services { margin-left: 28px; }`);
  themeSheet.replaceSync(rules.length === 0 ? '' : rules.join(' '));
  adoptInto(document);
}

// Re-walk on DOM changes — the modal mounts lazily when the visitor
// clicks Configure, and its nested purpose-groups appear after that.
new MutationObserver(() => adoptInto(document)).observe(document.body, {
  subtree: true,
  childList: true,
});

window.addEventListener('message', (event) => {
  const data = event.data;
  if (!data || data.type !== 'simplecmp-theme-preview') {
    return;
  }
  applyTokens(data.tokens);
  // Re-run the DOM-level audit after token updates — the editor may
  // have just set a color combination that breaks WCAG contrast. The
  // iframe's `cmp.init()` only ran once, so `auditDom()` wouldn't
  // re-fire on its own. Two rAF frames so the computed styles AND
  // any cascading shadow-DOM style adoption land before the audit
  // reads them (single rAF reads pre-flush in some browsers).
  // Resolve `auditDom` lazily from `window.SimpleCMP` so a re-load of
  // the bundle (which we don't do today, but might in future
  // hot-reload flows) picks up the new function reference instead of
  // a stale closure.
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      const audit = window.SimpleCMP?.auditDom;
      if (typeof audit !== 'function') return;
      const results = audit();
      try {
        window.parent?.postMessage({ type: 'simplecmp-dom-audit', results }, '*');
      } catch (_) { /* same defensive cross-origin guard as boot-time */ }
    });
  });
});

// Signal to the parent that the iframe is ready so the first postMessage
// fires after init rather than racing against the bundle load.
window.parent?.postMessage({ type: 'simplecmp-preview-ready' }, '*');
