/**
 * Drives the live banner preview iframe in the Banner-Design BE module.
 *
 * Listens for `input` events on token form fields (anything with
 * `data-token`) and posts the full current token snapshot to the
 * iframe via `postMessage`. Debounced ~120ms so dragging a color
 * picker doesn't fire dozens of messages per second.
 *
 * Also reacts to the iframe's "preview-ready" handshake so the first
 * paint reflects the saved theme rather than the bundle's defaults.
 */
class ThemePreview {
  initialize() {
    this._timer = null;
    document.addEventListener('input', this.onInput, true);
    document.addEventListener('change', this.onChange);
    window.addEventListener('message', this.onMessage);
  }

  /**
   * Language picker — when the editor picks a different locale, swap
   * the iframe's `?lang=` query so the SimpleCMP bundle re-mounts
   * with the new language. Updates the URL silently as well so a
   * browser refresh stays on the picked locale.
   *
   * Pure iframe-src swap rather than re-init via postMessage because
   * SimpleCMP's language is resolved once at `init()` time and the
   * cleanest way to re-resolve it is a fresh document load.
   */
  onChange = (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (target instanceof HTMLSelectElement && target.hasAttribute('data-preview-language-picker')) {
      this.swapPreviewLanguage(target);
      return;
    }
    if (target instanceof HTMLInputElement && target.id === 'override-tone-toggle') {
      this.swapPreviewTone(target);
      return;
    }
    if (target instanceof HTMLSelectElement && target.getAttribute('data-token') === 'theme') {
      this.swapPreviewTheme(target);
      return;
    }
    if (target instanceof HTMLSelectElement && target.getAttribute('data-token') === 'layout') {
      this.swapPreviewLayout(target);
      return;
    }
  };

  swapPreviewLayout(select) {
    // `layout` (like `theme` and `tone`) is consumed at cmp.init()
    // time — the bundle's banner component reads `config.layout`
    // once to pick which buttons to render, and there's no
    // postMessage-driven re-render path. Reload the iframe with
    // the new `?layout=` query so init runs again under the new
    // template.
    const iframe = document.querySelector('[data-preview-iframe]');
    if (!iframe) return;
    const value = select.value || 'standard';
    try {
      const url = new URL(iframe.src, window.location.origin);
      url.searchParams.set('layout', value);
      iframe.src = url.toString();
    } catch (_) {
      const sep = iframe.src.includes('?') ? '&' : '?';
      iframe.src = `${iframe.src}${sep}layout=${encodeURIComponent(value)}`;
    }
  }

  swapPreviewLanguage(select) {
    const iframe = document.querySelector('[data-preview-iframe]');
    if (!iframe) return;
    // Use the URL API so the cache-buster query `?<hash>` that
    // `f:uri.resource` puts on the base path is preserved AND any
    // other params already on the iframe (`privacy`, `imprint`, …) survive
    // the language swap. A plain string-concat with `?lang=` would
    // produce `?<hash>?lang=` and URLSearchParams in the iframe would
    // silently misparse it.
    const lang = select.value || 'en';
    let url;
    try {
      url = new URL(iframe.src, window.location.origin);
      url.searchParams.set('lang', lang);
    } catch (_) {
      const rawBase = select.getAttribute('data-preview-iframe-base') || iframe.src;
      const sep = rawBase.includes('?') ? '&' : '?';
      iframe.src = `${rawBase}${sep}lang=${encodeURIComponent(lang)}`;
      return;
    }
    iframe.src = url.toString();

    // Keep BE URL in sync via the option's pre-built action URL so a
    // browser refresh lands on the same site+language combination.
    const opt = select.options[select.selectedIndex];
    const href = opt?.getAttribute('data-href');
    if (href) {
      try {
        window.history.replaceState(null, '', href);
      } catch (_) { /* older browsers — best-effort */ }
    }
  }

  swapPreviewTheme(select) {
    // `theme` is consumed by cmp.init() at boot time (it picks the
    // adapter `<style>` element the bundle injects into <head>), so
    // we need a fresh document load — same reason the language and
    // tone pickers reload the iframe instead of postMessaging an
    // update.
    const iframe = document.querySelector('[data-preview-iframe]');
    if (!iframe) return;
    const value = select.value || 'default';
    try {
      const url = new URL(iframe.src, window.location.origin);
      url.searchParams.set('theme', value);
      iframe.src = url.toString();
    } catch (_) {
      const sep = iframe.src.includes('?') ? '&' : '?';
      iframe.src = `${iframe.src}${sep}theme=${encodeURIComponent(value)}`;
    }
  }

  swapPreviewTone(checkbox) {
    // Tone is consumed by cmp.init() at boot time — reload the iframe
    // with the new `tone=` query so the bundle re-mounts under the new
    // register. Same shape as the language swap above; we keep the
    // other params (lang, privacy, imprint, overrides) intact.
    //
    // The form's checkbox state continues to drive the save action —
    // a reload of the parent BE page after save re-reads the
    // persisted tone via the controller, so live preview and saved
    // state converge.
    const iframe = document.querySelector('[data-preview-iframe]');
    if (!iframe) return;
    const tone = checkbox.checked ? 'informal' : 'formal';
    try {
      const url = new URL(iframe.src, window.location.origin);
      url.searchParams.set('tone', tone);
      iframe.src = url.toString();
    } catch (_) {
      // Best-effort fallback — older browsers without URL parser support.
      const sep = iframe.src.includes('?') ? '&' : '?';
      iframe.src = `${iframe.src}${sep}tone=${tone}`;
    }
  }

  onInput = (event) => {
    const target = event.target;
    if (!(target instanceof Element) || !target.hasAttribute('data-token')) {
      return;
    }
    // For color pickers, the adjacent <code> shows the hex value; keep
    // it in sync as the user drags through the picker.
    if (target instanceof HTMLInputElement && target.type === 'color') {
      const label = target.parentElement?.querySelector('code');
      if (label) label.textContent = target.value;
    }
    clearTimeout(this._timer);
    this._timer = setTimeout(() => this.send(), 120);
  };

  onMessage = (event) => {
    if (event.data?.type === 'simplecmp-preview-ready') {
      // Iframe just finished loading + initialising — push the current
      // form state so it shows the admin's unsaved values, not bundle
      // defaults.
      this.send();
      return;
    }
    if (event.data?.type === 'simplecmp-dom-audit' && Array.isArray(event.data.results)) {
      // The preview iframe ran `simplecmp.auditDom()` after the banner
      // mounted and posted its findings. Render the failed ones inline
      // into the existing audit banner so editors see config-side and
      // DOM-side findings in one place.
      this.renderDomAudit(event.data.results);
      return;
    }
  };

  /**
   * Merge DOM-audit results into the existing audit banner. The server
   * pre-rendered:
   *   - `<div data-dom-audit hidden>` placeholder section
   *   - `<ul data-dom-audit-list>` empty list inside it
   *   - `<script data-dom-audit-i18n>` JSON map: id → {title, section, complianceUri}
   * This handler reads the i18n map, builds one <li> per failed
   * finding, and unhides the section. Passing findings stay collapsed
   * (only actionable items get rendered — different posture than the
   * server-rendered config-audit list which optionally folds passes
   * into a <details>).
   */
  renderDomAudit(results) {
    const section = document.querySelector('[data-dom-audit]');
    const list = document.querySelector('[data-dom-audit-list]');
    const i18nNode = document.querySelector('[data-dom-audit-i18n]');
    if (!section || !list || !i18nNode) return;
    let i18n = {};
    try {
      i18n = JSON.parse(i18nNode.textContent || '{}');
    } catch (_) {
      // Malformed JSON — bail silently. The findings just don't render.
      return;
    }
    const failed = results.filter((r) => r && r.passed === false);
    list.innerHTML = '';
    if (failed.length === 0) {
      section.hidden = true;
      return;
    }
    for (const result of failed) {
      const meta = i18n[result.id] || { title: result.title, section: result.section };
      const li = document.createElement('li');
      li.className = 'mb-2 d-flex align-items-start gap-2';
      const badge = document.createElement('span');
      badge.className =
        'badge text-bg-' + (result.severity === 'critical' ? 'danger' : 'warning') + ' flex-shrink-0';
      badge.textContent = result.severity === 'critical' ? 'Kritisch' : 'Warnung';
      const body = document.createElement('div');
      const titleEl = document.createElement('strong');
      titleEl.textContent = meta.title || result.id;
      body.appendChild(titleEl);
      if (meta.complianceUri) {
        const link = document.createElement('a');
        link.href = meta.complianceUri;
        link.target = 'simplecmp-compliance';
        link.className = 'ms-1 small text-decoration-none';
        link.textContent = '§' + (meta.section || result.section);
        body.appendChild(link);
      } else {
        const sect = document.createElement('span');
        sect.className = 'text-body-secondary small ms-1';
        sect.textContent = '(§' + result.section + ')';
        body.appendChild(sect);
      }
      const detail = document.createElement('div');
      detail.className = 'small';
      // The detail string from the upstream bundle is English with
      // multi-line context (e.g. "Mismatched properties: …"). Render
      // as preformatted-ish so the bullet-list inside survives.
      detail.style.whiteSpace = 'pre-wrap';
      detail.textContent = result.detail;
      body.appendChild(detail);
      li.appendChild(badge);
      li.appendChild(body);
      list.appendChild(li);
    }
    section.hidden = false;
  }

  send() {
    const iframe = document.querySelector('[data-preview-iframe]');
    if (!iframe || !iframe.contentWindow) {
      return;
    }
    const tokens = {};
    document.querySelectorAll('[data-token]').forEach((input) => {
      const key = input.getAttribute('data-token');
      if (!key) return;
      // `theme` and `layout` are config-time bundle flags, not CSS
      // variables. Skip them here so the postMessage payload stays
      // purely token-oriented; theme/layout changes are handled
      // separately via an iframe-src swap (see swapPreviewTheme /
      // swapPreviewLayout) that re-runs `cmp.init()`.
      if (key === 'theme' || key === 'layout') return;
      // Radios pose as a group of inputs all sharing `data-token`. Only
      // the checked one carries the user's choice; the rest are noise.
      if (input.type === 'radio') {
        if (input.checked) tokens[key] = input.value;
        return;
      }
      tokens[key] = input.value;
    });
    iframe.contentWindow.postMessage({ type: 'simplecmp-theme-preview', tokens }, '*');
  }
}

new ThemePreview().initialize();

export default ThemePreview;
