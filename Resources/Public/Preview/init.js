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

// CSS-framework adapter — the bundle ships `default` (no adapter)
// and `bootstrap5` (re-binds `--simplecmp-*` to `--bs-*`). Anything
// else falls through to `default` so a typo doesn't break the
// preview. The bundle warns on unknown values at its own end.
const previewTheme = (() => {
  const raw = (previewParams.get('theme') || '').toLowerCase();
  return raw === 'bootstrap5' ? 'bootstrap5' : 'default';
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
  cmp.init(initConfig);

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
};

function applyTokens(tokens) {
  const decls = [];
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
      decls.push(`--simplecmp-banner-inset: ${def.inset};`);
      decls.push(`--simplecmp-banner-transform: ${def.transform};`);
      if (def.maxWidth) decls.push(`--simplecmp-banner-max-width: ${def.maxWidth};`);
      continue;
    }
    decls.push(`--simplecmp-${key}: ${value};`);
  }
  themeSheet.replaceSync(decls.length === 0 ? '' : `:host { ${decls.join(' ')} }`);
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
});

// Signal to the parent that the iframe is ready so the first postMessage
// fires after init rather than racing against the bundle load.
window.parent?.postMessage({ type: 'simplecmp-preview-ready' }, '*');
