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
    document.addEventListener('change', this.onColorLockToggle);
    document.addEventListener('click', this.onClick);
    window.addEventListener('message', this.onMessage);
    this._initOptionalColorRows();

    // Mark the form dirty on any user-side input that touches a
    // theme token, an override field, or the tone toggle. The
    // indicator badge sits next to the preview heading and points
    // the editor at the Save button. We DON'T flip the flag from
    // the Save action itself (the form does a full POST + redirect,
    // so the indicator state is reset implicitly by the new page).
    document.addEventListener('input', this.onMaybeDirty, true);
    document.addEventListener('change', this.onMaybeDirty, true);
    this._initDirtyIndicator();

    // Autosave-to-draft: any theme change is persisted into the draft
    // automatically (debounced), so there is no manual Save button.
    // The draft is a safe scratch space — nothing goes live until the
    // editor clicks Publish. `data-draft-editable="1"` gates it so we
    // never POST when no editable draft exists.
    this._themeForm = document.querySelector('form[data-theme-form]');
    this._saveTimer = null;
    this._retryTimer = null;
    this._saveInFlight = false;
    this._dirtyPending = false;
    // Best-effort flush when the editor navigates away (site/language
    // switch, tab change) before the debounce fired — otherwise the last
    // edit could be lost. sendBeacon survives the unload.
    this.onPageHide = this.onPageHide.bind(this);
    window.addEventListener('pagehide', this.onPageHide);
  }

  /**
   * Sync each optional-colour row's initial state from the picker's
   * stored vs. fallback value. Fluid can't reliably evaluate
   * `tokens.color-trigger-bg` (dash in the key trips path parsing) so
   * the template renders both Reset and Enable buttons unconditionally;
   * JS hides the wrong one on first paint. The hidden empty <input>
   * already takes care of the form-submission default-state path.
   * Generalised over all `[data-optional-color-row]` so the same logic
   * drives trigger-bg, accept-bg, decline-bg, configure-bg.
   */
  _initOptionalColorRows() {
    document.querySelectorAll('[data-optional-color-row]').forEach((row) => {
      const colorInput = row.querySelector('input[type="color"][data-token]');
      if (!colorInput) return;
      const resetBtn = row.querySelector('[data-optional-color-reset]');
      const enableBtn = row.querySelector('[data-optional-color-enable]');
      const swatch = row.querySelector('[data-swatch-for]');
      const display = row.querySelector('[data-optional-color-display]');
      const unsetLabel = display?.getAttribute('data-unset-label') || '(default)';
      const isUnset = display?.textContent.trim() === unsetLabel.trim();
      if (isUnset) {
        colorInput.disabled = true;
        if (resetBtn) resetBtn.hidden = true;
        if (enableBtn) enableBtn.hidden = false;
        if (swatch) swatch.style.backgroundColor = 'transparent';
      } else {
        colorInput.disabled = false;
        if (resetBtn) resetBtn.hidden = false;
        if (enableBtn) enableBtn.hidden = true;
      }
    });
  }

  _initDirtyIndicator() {
    this._dirty = false;
    // No `beforeunload` guard on purpose — the designer lives inside
    // the BE module iframe and the editor switches sites / languages
    // / tabs constantly. A native confirm-prompt on every switch would
    // train the editor to dismiss it reflexively. The inline badge
    // beside the preview heading is enough — it's hard to miss while
    // editing because it sits in the sticky pane.
  }

  onMaybeDirty = (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    // Anything inside the designer form that the editor can change
    // counts as a "dirty" mutation. Limit to elements we know about
    // so unrelated BE-chrome events (search, dropdowns) don't trip
    // the flag.
    const isThemeInput = target.hasAttribute?.('data-token')
      || target.hasAttribute?.('data-override-field')
      || target.id === 'override-tone-toggle'
      || target.hasAttribute?.('data-preview-language-picker');
    if (!isThemeInput) return;
    this._setDirty(true);
  };

  _setDirty(value) {
    // Every dirty mutation triggers a debounced autosave into the draft.
    // (No manual Save button — the draft is persisted automatically.)
    if (value) this.scheduleAutosave();
  }

  scheduleAutosave() {
    const form = this._themeForm;
    if (!form || form.getAttribute('data-draft-editable') !== '1') return;
    this._dirtyPending = true;
    this._setAutosaveStatus('pending');
    if (this._retryTimer) { clearTimeout(this._retryTimer); this._retryTimer = null; }
    clearTimeout(this._saveTimer);
    this._saveTimer = setTimeout(() => this.doAutosave(), 800);
  }

  doAutosave() {
    const form = this._themeForm;
    if (!form || form.getAttribute('data-draft-editable') !== '1') return;
    if (this._saveInFlight) {
      // A save is already running — try again shortly so the newest edit
      // isn't dropped.
      this._saveTimer = setTimeout(() => this.doAutosave(), 400);
      return;
    }
    const fd = new FormData(form);
    fd.set('autosave', '1');
    this._saveInFlight = true;
    this._dirtyPending = false;
    this._setAutosaveStatus('saving');
    fetch(form.getAttribute('action'), {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then((r) => (r.ok ? r : Promise.reject(r.status)))
      .then(() => {
        this._saveInFlight = false;
        if (this._dirtyPending) { this.scheduleAutosave(); return; }
        this._setAutosaveStatus('saved');
      })
      .catch(() => {
        this._saveInFlight = false;
        this._dirtyPending = true;
        this._setAutosaveStatus('error');
        this._retryTimer = setTimeout(() => this.doAutosave(), 3000);
      });
  }

  _setAutosaveStatus(state) {
    const el = document.querySelector('[data-autosave-status]');
    if (!el) return;
    const msg = (k, d) => el.getAttribute('data-i18n-' + k) || d;
    let text;
    let cls;
    switch (state) {
      case 'pending': text = msg('pending', 'Unsaved changes…'); cls = 'text-body-secondary'; break;
      case 'saving': text = msg('saving', 'Saving…'); cls = 'text-body-secondary'; break;
      case 'saved': text = msg('saved', 'Saved · %s').replace('%s', new Date().toLocaleTimeString()); cls = 'text-success'; break;
      case 'error': text = msg('error', 'Could not save — retrying…'); cls = 'text-danger'; break;
      default: return;
    }
    el.textContent = text;
    el.className = 'me-auto small ' + cls;
  }

  onPageHide() {
    const form = this._themeForm;
    if (!form || form.getAttribute('data-draft-editable') !== '1') return;
    if (!this._dirtyPending && !this._saveInFlight) return;
    try {
      const fd = new FormData(form);
      fd.set('autosave', '1');
      navigator.sendBeacon(form.getAttribute('action'), fd);
    } catch (_) {
      /* best effort on unload */
    }
  }

  /**
   * Click delegate — picks up the "Live-FE-Audit ausführen" button
   * anywhere in the module. Keeps the handler installed at document
   * level so the button works even if the audit-banner re-renders
   * around it (which doesn't happen today but is cheap insurance).
   */
  onClick = (event) => {
    const styleTrigger = event.target.closest?.('[data-style-preset]');
    if (styleTrigger) {
      event.preventDefault();
      this.applyStylePreset(styleTrigger);
      return;
    }
    const optReset = event.target.closest?.('[data-optional-color-reset]');
    if (optReset) {
      event.preventDefault();
      this.resetOptionalColor(optReset);
      return;
    }
    const optEnable = event.target.closest?.('[data-optional-color-enable]');
    if (optEnable) {
      event.preventDefault();
      this.enableOptionalColor(optEnable);
      return;
    }
    const overrideClear = event.target.closest?.('[data-override-clear]');
    if (overrideClear) {
      event.preventDefault();
      this.clearOverrideField(overrideClear);
      return;
    }
    const trigger = event.target.closest?.('[data-fe-audit-trigger]');
    if (!trigger) return;
    event.preventDefault();
    this.runFeAudit(trigger);
  };

  /**
   * Reset an optional-colour override (trigger-bg, accept-bg,
   * decline-bg, configure-bg) back to "default". Disables the visible
   * color input so it drops out of form serialisation — the parallel
   * hidden empty field wins — and clears the swatch + display label.
   * Hides the reset button until the editor sets a new colour (which
   * re-enables the input).
   */
  resetOptionalColor(button) {
    const row = button.closest('[data-optional-color-row]');
    if (!row) return;
    const colorInput = row.querySelector('input[type="color"][data-token]');
    const swatch = row.querySelector('[data-swatch-for]');
    const display = row.querySelector('[data-optional-color-display]');
    const enableBtn = row.querySelector('[data-optional-color-enable]');
    if (!colorInput) return;
    colorInput.disabled = true;
    button.hidden = true;
    if (enableBtn) enableBtn.hidden = false;
    if (swatch) swatch.style.backgroundColor = 'transparent';
    if (display) {
      const unsetLabel = display.getAttribute('data-unset-label') || '(default)';
      display.textContent = unsetLabel;
    }
    // Push the cleared state to the preview immediately — `send()` skips
    // disabled inputs so the optional-colour override rule drops out.
    this._setDirty(true);
    clearTimeout(this._timer);
    this._timer = setTimeout(() => this.send(), 0);
  }

  /**
   * Clear an override text field and hide its clear-button. The
   * override's "active" badge in the label is server-rendered, so
   * after clearing we DON'T strip it client-side — the user still
   * needs to Save for the change to land, and the badge correctly
   * reflects the persisted state until then. The dirty indicator
   * already signals the unsaved state.
   */
  clearOverrideField(button) {
    const row = button.closest('[data-override-row]');
    if (!row) return;
    const input = row.querySelector('[data-override-input]');
    if (!input) return;
    input.value = '';
    button.hidden = true;
    input.focus();
    this._setDirty(true);
    // Fire a real `input` event so listeners (form-state, etc.) react.
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  /**
   * Companion to `resetOptionalColor`. Re-enable the color picker so
   * the editor can pick a custom override; swaps button visibility
   * back to the Reset state and opens the native picker so picking a
   * colour is a single follow-up click.
   */
  enableOptionalColor(button) {
    const row = button.closest('[data-optional-color-row]');
    if (!row) return;
    const colorInput = row.querySelector('input[type="color"][data-token]');
    const swatch = row.querySelector('[data-swatch-for]');
    const display = row.querySelector('[data-optional-color-display]');
    const resetBtn = row.querySelector('[data-optional-color-reset]');
    if (!colorInput) return;
    colorInput.disabled = false;
    button.hidden = true;
    if (resetBtn) resetBtn.hidden = false;
    if (swatch) swatch.style.backgroundColor = colorInput.value;
    if (display) display.textContent = colorInput.value;
    this._setDirty(true);
    try { colorInput.click(); } catch (_) { /* not all browsers honour this */ }
    clearTimeout(this._timer);
    this._timer = setTimeout(() => this.send(), 0);
  }

  /**
   * Live-toggle the `disabled` attribute on the colors fieldset so the
   * color pickers grey out the moment the editor flips the "Eigene
   * Farben verwenden" switch. CSS `:has()` already handles the warning-
   * banner + badge visibility; this handler covers the one piece that
   * CSS can't express (a real `disabled` attribute on form controls).
   */
  onColorLockToggle = (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.id !== 'token-colorPaletteLocked') return;
    const fieldset = document.querySelector('.simplecmp-colors-fieldset');
    if (!fieldset) return;
    // Checkbox CHECKED = custom colors on = fieldset enabled.
    fieldset.disabled = !target.checked;
  };

  /**
   * Click on a style-preset card → set the position radio in the
   * existing 9-slot picker to match the preset's bundled position
   * and trigger the preview update by dispatching a `change` event.
   */
  applyStylePreset(card) {
    const targetPosition = card.getAttribute('data-preset-position');
    if (!targetPosition) return;
    const radio = document.querySelector('input[type="radio"][name="tokens[position]"][value="' + targetPosition + '"]');
    if (!radio) return;
    radio.checked = true;
    // `input` is what triggers send() (the debounced postMessage to
    // the preview iframe); `change` is what the form actually persists.
    // Fire both so the preview updates instantly AND a subsequent save
    // captures the new value.
    radio.dispatchEvent(new Event('input', { bubbles: true }));
    radio.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /**
   * Mount a hidden iframe at `<siteBaseUrl>?simplecmp_audit=1`,
   * listen for the `simplecmp-audit-from-fe` postMessage the
   * bundle's audit-mode handler emits, render the findings, and
   * tear the iframe down.
   *
   * The iframe is sized 1024×768 so the banner mounts at a
   * representative viewport — narrower can hide layout overflow,
   * wider doesn't help since we only audit the banner. Positioned
   * off-screen so the editor doesn't see it. 10-second hard timeout
   * in case the bundle doesn't respond (site down, bundle not
   * loaded, X-Frame-Options blocking).
   */
  runFeAudit(trigger) {
    const url = trigger.getAttribute('data-fe-audit-url');
    if (!url) return;
    const statusEl = document.querySelector('[data-fe-audit-status]');
    const containerEl = document.querySelector('[data-fe-audit]');
    const listEl = document.querySelector('[data-fe-audit-list]');
    const headingEl = document.querySelector('[data-fe-audit-heading]');
    if (!statusEl || !containerEl || !listEl || !headingEl) return;
    const i18n = this._readDomAuditI18n();

    trigger.disabled = true;
    statusEl.textContent = this._stringFromLocale('running', 'Running on live FE…');
    containerEl.hidden = true;
    listEl.innerHTML = '';
    const revisionEl = document.querySelector('[data-fe-audit-revision]');
    if (revisionEl) {
      revisionEl.hidden = true;
      revisionEl.textContent = '';
    }

    const auditUrl = new URL(url, window.location.origin);
    auditUrl.searchParams.set('simplecmp_audit', '1');
    auditUrl.searchParams.set('cb', Date.now().toString());
    // HMAC preview token (minted BE-side, source-bound to this site).
    // Tells RegisterAssets to render the editor's pending DRAFT config
    // into the audited iframe instead of the published one. Absent when
    // no bridge secret is configured → audit falls back to live config.
    const previewToken = trigger.getAttribute('data-fe-audit-preview-token');
    if (previewToken) {
      auditUrl.searchParams.set('simplecmp_preview', previewToken);
    }
    // Belt-and-suspenders: ALSO add the audit marker as a hash
    // fragment so TYPO3's language redirect (which drops query
    // strings going `/` → `/de/`) doesn't disarm the audit. The
    // bundle's `isAuditMode()` checks both surfaces.
    auditUrl.hash = 'simplecmp_audit=1';
    const iframe = document.createElement('iframe');
    iframe.src = auditUrl.toString();
    iframe.style.cssText =
      'position: fixed; left: -9999px; top: -9999px; width: 1024px; height: 768px; border: 0;';
    iframe.dataset.feAuditIframe = '1';
    document.body.appendChild(iframe);

    const cleanup = () => {
      window.removeEventListener('message', handler);
      clearTimeout(timeout);
      iframe.remove();
      trigger.disabled = false;
    };

    const timeout = setTimeout(() => {
      cleanup();
      const tmpl = this._stringFromLocale('timeout', 'No response from %s after 10s.');
      statusEl.textContent = tmpl.replace('%s', auditUrl.host);
    }, 10000);

    // The FE posts two messages: a `simplecmp-typo3-preview-meta` marker
    // (which version was rendered) and the `simplecmp-audit-from-fe`
    // results. Order isn't guaranteed, so we stash the marker and render
    // the revision line both when it arrives and once results land.
    let previewMeta = null;
    const handler = (event) => {
      const data = event.data;
      if (data?.type === 'simplecmp-typo3-preview-meta') {
        previewMeta = data;
        this._renderFeAuditRevision(previewMeta);
        return;
      }
      if (data?.type !== 'simplecmp-audit-from-fe') return;
      if (!Array.isArray(data.results)) return;
      cleanup();
      this._renderFeAuditResults(data, i18n, { statusEl, containerEl, listEl, headingEl });
      this._renderFeAuditRevision(previewMeta);
    };
    window.addEventListener('message', handler);
  }

  /**
   * Render the "which version was checked" verification line from the
   * FE-posted preview marker, compared against the draft the BE expects.
   */
  _renderFeAuditRevision(meta) {
    const el = document.querySelector('[data-fe-audit-revision]');
    const wrapper = document.querySelector('[data-fe-audit-i18n-running]');
    if (!el || !wrapper || !meta) return;
    const expectedSource = wrapper.getAttribute('data-fe-audit-expected-source') || 'live';
    const expectedRev = wrapper.getAttribute('data-fe-audit-expected-rev') || '0';
    const renderedRev = String(meta.revision ?? '0');
    const fmt = (ts) => {
      const n = parseInt(ts, 10);
      return n > 0 ? new Date(n * 1000).toLocaleString() : '–';
    };
    let text;
    let cls;
    if (meta.source === 'draft' && expectedSource === 'draft') {
      if (renderedRev === expectedRev) {
        text = this._stringFromLocale('revDraftOk', 'Checked: draft (rev %s).').replace('%s', fmt(renderedRev));
        cls = 'text-success';
      } else {
        text = this._stringFromLocale('revDraftMismatch', 'Warning: checked draft rev %s, current is %s — reload the page.')
          .replace('%s', fmt(renderedRev))
          .replace('%s', fmt(expectedRev));
        cls = 'text-danger';
      }
    } else if (meta.source === 'live' && expectedSource === 'draft') {
      text = this._stringFromLocale('revLiveUnexpected', 'Warning: the LIVE version was checked, not your draft (preview token missing/expired?).');
      cls = 'text-danger';
    } else {
      text = this._stringFromLocale('revLive', 'Checked: live version (no draft present).');
      cls = 'text-body-secondary';
    }
    el.textContent = text;
    el.className = 'small mt-1 ' + cls;
    el.hidden = false;
  }

  _readDomAuditI18n() {
    const node = document.querySelector('[data-dom-audit-i18n]');
    if (!node) return {};
    try {
      return JSON.parse(node.textContent || '{}');
    } catch (_) {
      return {};
    }
  }

  _stringFromLocale(suffix, fallback) {
    // The template renders all FE-audit-related localized strings as
    // `data-fe-audit-i18n-<kebab-case-suffix>` attributes on the
    // wrapping div. Callers pass camelCase suffixes; we kebab-case
    // them here so HTML5 attribute semantics (kebab) match either
    // way without forcing callers to remember the convention.
    const wrapper = document.querySelector('[data-fe-audit-i18n-running]');
    if (!wrapper) return fallback;
    const kebab = suffix.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`);
    const value = wrapper.getAttribute(`data-fe-audit-i18n-${kebab}`);
    return value && value !== '' ? value : fallback;
  }

  _renderFeAuditResults(payload, i18n, els) {
    const failed = payload.results.filter((r) => r && r.passed === false);
    const headingTmpl = this._stringFromLocale('heading', 'From the live frontend (%s)');
    els.headingEl.textContent = headingTmpl.replace('%s', payload.location?.host || 'frontend');
    els.statusEl.textContent = this._stringFromLocale('done', 'Last live FE check: %s').replace(
      '%s',
      new Date().toLocaleTimeString()
    );
    els.listEl.innerHTML = '';
    if (failed.length === 0) {
      const li = document.createElement('li');
      li.className = 'small text-success';
      li.textContent = this._stringFromLocale('allPassed', 'All checks on the live frontend passed.');
      els.listEl.appendChild(li);
      els.containerEl.hidden = false;
      return;
    }
    for (const result of failed) {
      const meta = i18n[result.id] || { title: result.title, section: result.section };
      const li = document.createElement('li');
      li.className = 'mb-2 d-flex align-items-start gap-2';
      const badge = document.createElement('span');
      badge.className =
        'badge text-bg-' + (result.severity === 'critical' ? 'danger' : 'warning') + ' flex-shrink-0';
      badge.textContent =
        result.severity === 'critical'
          ? this._stringFromLocale('severity-critical', 'Critical')
          : this._stringFromLocale('severity-warning', 'Warning');
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
      }
      const detail = document.createElement('div');
      detail.className = 'small';
      detail.style.whiteSpace = 'pre-wrap';
      detail.textContent = result.detail;
      body.appendChild(detail);
      li.appendChild(badge);
      li.appendChild(body);
      els.listEl.appendChild(li);
    }
    els.containerEl.hidden = false;
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
    if (target instanceof HTMLSelectElement && target.getAttribute('data-token') === 'triggerPosition') {
      this.swapPreviewTriggerPosition(target);
      return;
    }
  };

  swapPreviewTriggerPosition(select) {
    // Same iframe-reload pattern as layout / theme / tone: the bundle
    // consumes `floatingTrigger.position` at cmp.init() time. We
    // re-encode it onto the iframe URL via a custom `triggerPosition`
    // query param that Preview/init.js feeds into `cmp.init()`.
    const iframe = document.querySelector('[data-preview-iframe]');
    if (!iframe) return;
    const value = select.value || 'bottom-right';
    try {
      const url = new URL(iframe.src, window.location.origin);
      url.searchParams.set('triggerPosition', value);
      iframe.src = url.toString();
    } catch (_) {
      const sep = iframe.src.includes('?') ? '&' : '?';
      iframe.src = `${iframe.src}${sep}triggerPosition=${encodeURIComponent(value)}`;
    }
  }

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
    if (!(target instanceof Element)) return;
    // Override text fields share `onInput` with token fields but only
    // care about the clear-button visibility — they don't trigger a
    // postMessage to the preview iframe (overrides are submitted via
    // a separate POST/redirect that re-renders the page).
    if (target.hasAttribute?.('data-override-input')) {
      const row = target.closest('[data-override-row]');
      const clearBtn = row?.querySelector('[data-override-clear]');
      if (clearBtn) clearBtn.hidden = !target.value;
      return;
    }
    if (!target.hasAttribute('data-token')) {
      return;
    }
    // For color pickers, the adjacent <code> shows the hex value AND
    // the visual swatch beside the picker shows the color as a filled
    // box — both kept in sync as the user drags through the picker so
    // they don't have to wait for the iframe round-trip to see what
    // colour is selected. Especially relevant for `color-primary`,
    // which the banner only uses for focus outlines + modal accents
    // (subtle by design — equal-prominence compliance baseline).
    if (target instanceof HTMLInputElement && target.type === 'color') {
      const row = target.parentElement;
      const label = row?.querySelector('code');
      if (label) label.textContent = target.value;
      const swatch = row?.querySelector('[data-swatch-for="' + target.id + '"]');
      if (swatch) swatch.style.backgroundColor = target.value;
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
      // Disabled inputs aren't part of the form submission, and the
      // preview should mirror that — otherwise a disabled trigger-bg
      // picker would keep colouring the live preview even after the
      // editor resets the field to "use primary color".
      if (input.disabled) return;
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
      // Checkboxes: only contribute when checked. The hidden sibling
      // (e.g. for `colorPaletteLocked`) already supplies the off-state
      // value so the iframe sees a coherent state on every send.
      if (input.type === 'checkbox') {
        if (input.checked) tokens[key] = input.value;
        return;
      }
      tokens[key] = input.value;
    });

    // Lock semantics — when the editor leaves the "Eigene Farben"
    // toggle off, the FE-Live renders with SAFE_PALETTE (overriding
    // whatever color-* values are still stored in the form). The
    // preview iframe needs to mirror that or the editor would see
    // their custom colors in the preview but not on the actual site.
    const locked = (tokens.colorPaletteLocked ?? '1') === '1';
    if (locked) {
      const safe = this._safePalette();
      for (const [k, v] of Object.entries(safe)) {
        tokens[k] = v;
      }
    }

    iframe.contentWindow.postMessage({ type: 'simplecmp-theme-preview', tokens }, '*');
  }

  /** Parse the SAFE_PALETTE JSON the controller renders into the iframe element. */
  _safePalette() {
    if (this._safePaletteCache) return this._safePaletteCache;
    const iframe = document.querySelector('[data-preview-iframe][data-safe-palette]');
    if (!iframe) return (this._safePaletteCache = {});
    try {
      this._safePaletteCache = JSON.parse(iframe.getAttribute('data-safe-palette') || '{}');
    } catch (_) {
      this._safePaletteCache = {};
    }
    return this._safePaletteCache;
  }
}

new ThemePreview().initialize();

export default ThemePreview;
