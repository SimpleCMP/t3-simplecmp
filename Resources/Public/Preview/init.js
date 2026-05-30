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
const previewLang = (() => {
  const raw = new URLSearchParams(window.location.search).get('lang') || '';
  return /^[a-z]{2,3}$/i.test(raw) ? raw.toLowerCase() : 'en';
})();

// Mirror onto <html lang="…"> so the bundle's own detector
// (`document.documentElement.lang`) lands on the same value if its
// explicit `lang` config ever falls through.
document.documentElement.lang = previewLang;

if (cmp && typeof cmp.init === 'function') {
  cmp.init({
    storageName: 'simplecmp-preview',
    testing: true,
    privacyPolicy: '#',
    lang: previewLang,
    // The bundle's `dt()` translator falls back to this lang when the
    // primary lookup misses a key. Default upstream is "zz" (the
    // placeholder block), so any locale we don't explicitly fill ends
    // up showing my placeholder copy. English is a safer baseline.
    fallbackLang: 'en',
    services: [
      { name: 'preview-functional', purposes: ['functional'], required: true },
      { name: 'preview-analytics', purposes: ['analytics'] },
      { name: 'preview-marketing', purposes: ['marketing'] },
    ],
    // Service-specific copy (title/description for each demo service).
    // The bundle already translates banner shell and purposes
    // (Cookie-Einstellungen, Akzeptieren, …) for every locale in
    // its built-in registry. We only need to provide the per-service
    // strings — keyed per locale. `zz` stays as a final placeholder.
    translations: {
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
    },
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

function applyTokens(tokens) {
  const decls = [];
  for (const [key, value] of Object.entries(tokens || {})) {
    if (typeof key !== 'string' || typeof value !== 'string' || value === '') {
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
