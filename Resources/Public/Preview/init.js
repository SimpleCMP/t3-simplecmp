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
if (cmp && typeof cmp.init === 'function') {
  cmp.init({
    storageName: 'simplecmp-preview',
    testing: true,
    privacyPolicy: '#',
    services: [
      { name: 'preview-functional', purposes: ['functional'], required: true },
      { name: 'preview-analytics', purposes: ['analytics'] },
      { name: 'preview-marketing', purposes: ['marketing'] },
    ],
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
