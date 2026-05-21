/**
 * Discover trackers — sitemap sweep driven from the admin's browser.
 *
 * Walks the sitemap URL list sequentially through a hidden iframe.
 * Each iframe load gets `?simplecmp_discover=1` appended so the FE
 * recorder + bridge inside skip the bandwidth controls that suppress
 * repeat visits (cross-session marker, DNT, sample rate). After a
 * dwell window the iframe navigates to the next URL — that triggers
 * the previous document's `pagehide`, which the bridge hooks to
 * sendBeacon-flush any queued detections.
 *
 * No new server-side ingest path: the existing webhook receives the
 * POSTs exactly as it would from a real visitor.
 */

const DWELL_MS = 3000;
const BEACON_GRACE_MS = 400;
const STATE_STORAGE_PREFIX = 'simplecmp-discover-state:';
const LOG_RETENTION = 200;
const TEXTAREA_SAVE_DEBOUNCE_MS = 500;

class Discovery {
  initialize() {
    const root = document.querySelector('[data-discover-config]');
    if (!root) return;
    this.root = root;
    this.fetchSitemapUrl = root.getAttribute('data-discover-fetch-sitemap-url') || '';
    this.i18n = {
      starting: root.getAttribute('data-i18n-starting') || 'Starting discovery for {count} URLs.',
      stopping: root.getAttribute('data-i18n-stopping') || 'Stopping after current URL…',
      paused: root.getAttribute('data-i18n-paused') || 'Paused at {current} of {total}. Click Continue to resume.',
      done: root.getAttribute('data-i18n-done') || 'Done. Visited {current} of {total} URLs.',
      noUrls: root.getAttribute('data-i18n-no-urls') || 'No URLs to visit. Paste at least one URL and try again.',
      sitemapResult: root.getAttribute('data-i18n-sitemap-result') || 'Sitemap {url} → {count} URLs',
      sitemapError: root.getAttribute('data-i18n-sitemap-error') || 'Failed to fetch sitemap: {error}',
      iframeError: root.getAttribute('data-i18n-iframe-error') || 'iframe load error',
      cleared: root.getAttribute('data-i18n-cleared') || 'Discovery state cleared.',
      restored: root.getAttribute('data-i18n-restored') || 'Restored: {current} of {total} URLs already visited.',
      eta: root.getAttribute('data-i18n-eta') || 'Estimated time to walk all URLs: {eta}',
    };
    this.startButton = document.querySelector('[data-discover-start]');
    this.startLabel = document.querySelector('[data-discover-start-label]');
    this.iconPlay = document.querySelector('[data-discover-icon-play]');
    this.iconPause = document.querySelector('[data-discover-icon-pause]');
    this.siteSelect = document.querySelector('[data-discover-site]');
    this.showIframeToggle = document.querySelector('[data-discover-show-iframe]');
    this.iframeWrap = document.querySelector('[data-discover-iframe-wrap]');
    this.iframe = document.querySelector('[data-discover-iframe]');
    this.progressEl = document.querySelector('[data-discover-progress]');
    this.progressBar = document.querySelector('[data-discover-progress-bar]');
    this.progressLabel = document.querySelector('[data-discover-progress-label]');
    this.progressCounter = document.querySelector('[data-discover-progress-counter]');
    this.log = document.querySelector('[data-discover-log]');
    this.urlsTextarea = document.querySelector('[data-discover-urls]');
    this.urlCountEl = document.querySelector('[data-discover-url-count]');
    this.sitemapUrlEl = document.querySelector('[data-discover-sitemap-url]');
    this.refetchButton = document.querySelector('[data-discover-refetch]');

    this.resetButton = document.querySelector('[data-discover-reset]');

    this.running = false;
    this.stopRequested = false;
    // Snapshot taken on Start, kept across Stop/Continue. Reset on
    // completion. currentIndex is the last successfully visited index
    // (or -1 before the first URL).
    this.urlsSnapshot = null;
    this.currentIndex = -1;
    // Structured log mirror so we can re-render the log after a reload.
    // The DOM is the visible source of truth; this array is the
    // persistable shadow copy that survives a page navigation.
    this.logEntries = [];
    this.textareaSaveTimer = null;
    this.currentSite = this.siteSelect?.value || this.root.getAttribute('data-discover-current-site') || 'default';

    if (this.urlsTextarea && this.urlCountEl) {
      this.urlsTextarea.addEventListener('input', () => {
        this.urlCountEl.textContent = String(this.collectUrls().length);
        this.scheduleTextareaSave();
      });
      this.urlCountEl.textContent = String(this.collectUrls().length);
    }

    if (this.startButton) {
      this.startButton.addEventListener('click', () => this.onStartButtonClick());
    }
    if (this.resetButton) {
      this.resetButton.addEventListener('click', () => this.onReset());
    }
    if (this.siteSelect) {
      this.siteSelect.addEventListener('change', () => this.onSiteChange());
    }
    if (this.refetchButton) {
      this.refetchButton.addEventListener('click', () => this.onRefetch());
    }
    if (this.showIframeToggle && this.iframeWrap) {
      this.showIframeToggle.addEventListener('change', () => {
        this.iframeWrap.hidden = !this.showIframeToggle.checked;
      });
    }

    this.restoreState();
  }

  async onSiteChange() {
    // Save current site's state under its key, then load (or clear)
    // the target site's state. Without this, switching sites would
    // either bleed state across sites or silently overwrite.
    this.saveState();
    this.currentSite = this.siteSelect?.value || 'default';
    this.urlsSnapshot = null;
    this.currentIndex = -1;
    this.logEntries = [];
    this.clearLog();
    this.restoreState();
    await this.refetchSitemap({ resetUrl: true });
  }

  async onRefetch() {
    // Refetch returns a fresh URL list — any in-flight paused snapshot
    // is for the OLD list and would be confusing to keep around. Treat
    // an admin-driven Refetch as an implicit reset of the run state
    // (textarea + sitemap input stay — they're what the admin is
    // editing). onSiteChange uses refetchSitemap directly without this
    // reset path so per-site restored state survives picker changes.
    if (this.urlsSnapshot !== null) {
      this.urlsSnapshot = null;
      this.currentIndex = -1;
      this.clearLog();
      this.setButtonState('idle');
      if (this.urlsTextarea) this.urlsTextarea.disabled = false;
      if (this.siteSelect) this.siteSelect.disabled = false;
      if (this.progressEl) this.progressEl.hidden = true;
    }
    await this.refetchSitemap({ resetUrl: false });
  }

  async refetchSitemap({ resetUrl }) {
    if (!this.fetchSitemapUrl) return;
    const site = this.siteSelect?.value || '';
    const sitemapUrl = resetUrl ? '' : (this.sitemapUrlEl?.value || '');
    // BE module Extbase binds BARE param names to action arguments (NOT
    // the `tx_simplecmptypo3_*[name]` namespaced form). Mirrors the
    // existing Pagination.js convention. See memory:
    // `banner_theming.md` decision #6 for the same gotcha.
    const params = new URLSearchParams();
    if (site) params.set('site', site);
    if (sitemapUrl) params.set('sitemapUrl', sitemapUrl);
    const sep = this.fetchSitemapUrl.includes('?') ? '&' : '?';
    const url = `${this.fetchSitemapUrl}${sep}${params.toString()}`;
    if (this.refetchButton) this.refetchButton.disabled = true;
    try {
      const response = await fetch(url, { credentials: 'same-origin' });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      this.renderUrls(data.urls || [], data.sitemapUrl || '');
      this.appendLog(
        data.urls?.length > 0 ? 'ok' : 'warn',
        this.t(this.i18n.sitemapResult, { url: data.sitemapUrl, count: data.urls?.length || 0 }),
      );
      this.saveState();
    } catch (err) {
      this.appendLog('error', this.t(this.i18n.sitemapError, { error: err.message }));
    } finally {
      if (this.refetchButton) this.refetchButton.disabled = false;
    }
  }

  renderUrls(urls, sitemapUrl) {
    if (this.sitemapUrlEl) this.sitemapUrlEl.value = sitemapUrl;
    if (this.urlsTextarea) {
      this.urlsTextarea.value = urls.join('\n') + (urls.length > 0 ? '\n' : '');
    }
    if (this.urlCountEl) this.urlCountEl.textContent = String(urls.length);
  }

  collectUrls() {
    if (!this.urlsTextarea) return [];
    return this.urlsTextarea.value
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter((line) => line !== '' && !line.startsWith('#'));
  }

  /**
   * Single-button state machine:
   *   idle    → click = Start (collect URLs, walk from index 0)
   *   running → click = Stop (pause after current URL completes)
   *   paused  → click = Continue (resume from currentIndex + 1)
   *
   * On completion the snapshot is cleared and the button returns to
   * idle, so the next Start collects URLs fresh from the textarea.
   */
  async onStartButtonClick() {
    if (this.running) {
      this.requestStop();
      return;
    }
    if (this.urlsSnapshot !== null && this.currentIndex < this.urlsSnapshot.length - 1) {
      // Paused mid-list → resume.
      await this.runFrom(this.currentIndex + 1);
      return;
    }
    // Fresh start.
    const urls = this.collectUrls();
    if (urls.length === 0) {
      this.appendLog('warn', this.i18n.noUrls);
      return;
    }
    this.urlsSnapshot = urls;
    this.currentIndex = -1;
    this.clearLog();
    this.appendLog('info', this.t(this.i18n.starting, { count: urls.length }));
    this.logEta(urls.length);
    this.saveState();
    await this.runFrom(0);
  }

  requestStop() {
    if (!this.running) return;
    this.stopRequested = true;
    this.appendLog('info', this.i18n.stopping);
    if (this.startButton) this.startButton.disabled = true;
  }

  async runFrom(startIndex) {
    this.running = true;
    this.stopRequested = false;
    this.setButtonState('running');
    if (this.siteSelect) this.siteSelect.disabled = true;
    if (this.urlsTextarea) this.urlsTextarea.disabled = true;
    if (this.refetchButton) this.refetchButton.disabled = true;
    if (this.resetButton) this.resetButton.disabled = true;
    if (this.progressEl) this.progressEl.hidden = false;

    // On resume, surface the updated time-to-completion so the admin
    // sees how much is left before walking begins. Fresh starts log
    // the same line in onStartButtonClick (before this method).
    if (startIndex > 0) {
      const remaining = Math.max(0, this.urlsSnapshot.length - startIndex);
      this.logEta(remaining);
    }

    const total = this.urlsSnapshot.length;
    let i;
    for (i = startIndex; i < total; i++) {
      if (this.stopRequested) break;
      const url = this.urlsSnapshot[i];
      this.updateProgress(i + 1, total, url);
      try {
        await this.visit(url);
        this.appendLog('ok', `${url}`);
      } catch (err) {
        this.appendLog('warn', `${url} — ${err.message}`);
      }
      this.currentIndex = i;
      this.saveState();
    }

    // Flush the last document's queued batch by navigating away.
    if (this.iframe) {
      this.iframe.src = 'about:blank';
      await this.delay(BEACON_GRACE_MS);
    }

    this.running = false;
    if (this.stopRequested) {
      this.appendLog(
        'info',
        this.t(this.i18n.paused, { current: this.currentIndex + 1, total }),
      );
      // Paused state leaves textarea + site picker locked so the
      // snapshot stays consistent for Continue, but Refetch must be
      // available so the admin can explicitly abandon the paused run.
      if (this.refetchButton) this.refetchButton.disabled = false;
      this.setButtonState('paused');
    } else {
      this.appendLog(
        'info',
        this.t(this.i18n.done, { current: this.currentIndex + 1, total }),
      );
      this.urlsSnapshot = null;
      this.currentIndex = -1;
      this.setButtonState('idle');
      if (this.siteSelect) this.siteSelect.disabled = false;
      if (this.urlsTextarea) this.urlsTextarea.disabled = false;
      if (this.refetchButton) this.refetchButton.disabled = false;
    }
    if (this.resetButton) this.resetButton.disabled = false;
    this.saveState();
  }

  setButtonState(state) {
    if (!this.startButton) return;
    const labels = this.startButton.dataset;
    let label = labels.labelStart;
    let icon = 'play';
    let cls = 'btn-primary';
    if (state === 'running') {
      label = labels.labelStop;
      icon = 'pause';
      cls = 'btn-warning';
    } else if (state === 'paused') {
      label = labels.labelContinue;
      icon = 'play';
      cls = 'btn-primary';
    }
    if (this.startLabel) this.startLabel.textContent = label;
    if (this.iconPlay) this.iconPlay.hidden = icon !== 'play';
    if (this.iconPause) this.iconPause.hidden = icon !== 'pause';
    this.startButton.classList.remove('btn-primary', 'btn-warning');
    this.startButton.classList.add(cls);
    this.startButton.disabled = false;
  }

  visit(url) {
    return new Promise((resolve, reject) => {
      if (!this.iframe) {
        reject(new Error('no iframe'));
        return;
      }
      const target = this.appendDiscoverParam(url);
      let settled = false;
      const onLoad = async () => {
        if (settled) return;
        // Dwell so the cookie watcher (1000ms poll) + scripts that
        // inject themselves async (analytics, embeds) all get
        // observed before we navigate away.
        await this.delay(DWELL_MS);
        if (settled) return;
        settled = true;
        this.iframe.removeEventListener('load', onLoad);
        this.iframe.removeEventListener('error', onError);
        resolve();
      };
      const onError = () => {
        if (settled) return;
        settled = true;
        this.iframe.removeEventListener('load', onLoad);
        this.iframe.removeEventListener('error', onError);
        reject(new Error(this.i18n.iframeError));
      };
      this.iframe.addEventListener('load', onLoad);
      this.iframe.addEventListener('error', onError);
      this.iframe.src = target;
    });
  }

  appendDiscoverParam(url) {
    try {
      const u = new URL(url);
      u.searchParams.set('simplecmp_discover', '1');
      return u.toString();
    } catch {
      // Relative or otherwise unparseable — fall back to naive append.
      const sep = url.includes('?') ? '&' : '?';
      return `${url}${sep}simplecmp_discover=1`;
    }
  }

  updateProgress(visited, total, currentUrl) {
    const pct = Math.round((visited / total) * 100);
    if (this.progressBar) {
      this.progressBar.style.width = `${pct}%`;
      this.progressBar.setAttribute('aria-valuenow', String(pct));
    }
    if (this.progressLabel) {
      this.progressLabel.textContent = currentUrl;
    }
    if (this.progressCounter) {
      this.progressCounter.textContent = `${visited} / ${total}`;
    }
  }

  /**
   * Per-URL time estimate used for ETA math. Each URL costs one
   * dwell window plus the brief beacon-flush grace on navigate. The
   * iframe load itself is overlapping with everything else, so we
   * ignore it for the estimate. Constant for now; could become an
   * adaptive average if real-world pace diverges noticeably.
   */
  perUrlEtaMs() {
    return DWELL_MS + BEACON_GRACE_MS;
  }

  /**
   * Log the estimated wall-clock duration for a run of `count` URLs.
   * Used on Start (full list) and on Continue (remaining count) so the
   * admin can size their patience accordingly.
   */
  logEta(count) {
    if (count <= 0) return;
    const eta = this.formatEta(count * this.perUrlEtaMs());
    this.appendLog('info', this.t(this.i18n.eta, { eta }));
  }

  /**
   * Format a duration in milliseconds as a short human-readable
   * string: "<1s", "12s", "3m 12s", "1h 5m". Used by both the
   * live progress ETA and the starting/resuming log line.
   */
  formatEta(ms) {
    const totalSeconds = Math.max(0, Math.round(ms / 1000));
    if (totalSeconds < 1) return '<1s';
    if (totalSeconds < 60) return `${totalSeconds}s`;
    const totalMinutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    if (totalMinutes < 60) {
      return seconds > 0 ? `${totalMinutes}m ${seconds}s` : `${totalMinutes}m`;
    }
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return minutes > 0 ? `${hours}h ${minutes}m` : `${hours}h`;
  }

  appendLog(kind, message) {
    this.logEntries.push({ kind, message });
    if (this.logEntries.length > LOG_RETENTION) {
      this.logEntries.splice(0, this.logEntries.length - LOG_RETENTION);
    }
    this.renderLogEntry(kind, message);
  }

  renderLogEntry(kind, message) {
    if (!this.log) return;
    if (this.log.firstElementChild?.tagName === 'EM' || this.log.firstElementChild?.querySelector?.('em')) {
      this.log.innerHTML = '';
    }
    const line = document.createElement('div');
    const icon = kind === 'ok' ? '✓' : kind === 'warn' ? '⚠' : kind === 'error' ? '✗' : '·';
    const cls = kind === 'ok' ? 'text-success' : kind === 'warn' ? 'text-warning' : kind === 'error' ? 'text-danger' : 'text-body-secondary';
    line.className = cls;
    line.textContent = `${icon} ${message}`;
    this.log.appendChild(line);
    this.log.scrollTop = this.log.scrollHeight;
  }

  clearLog() {
    this.logEntries = [];
    if (this.log) this.log.innerHTML = '';
  }

  delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  /**
   * Tiny `{name}` placeholder interpolator for i18n strings. Replaces
   * `{key}` with String(values[key]); unknown placeholders are left
   * untouched so missing values show as `{name}` rather than blowing up.
   */
  t(template, values = {}) {
    return template.replace(/\{(\w+)\}/g, (_, key) =>
      Object.hasOwn(values, key) ? String(values[key]) : `{${key}}`,
    );
  }

  // --- persistence ----------------------------------------------------
  //
  // localStorage state survives BE page reloads so a paused run can be
  // resumed after the admin navigates away. Keyed per site so switching
  // the picker doesn't bleed state across sites. See plan in conversation
  // memory — DB-backed audit log is a separate future feature.

  storageKey(site = this.currentSite) {
    return `${STATE_STORAGE_PREFIX}${site || 'default'}`;
  }

  saveState() {
    if (typeof localStorage === 'undefined') return;
    try {
      const payload = {
        site: this.currentSite,
        sitemapUrl: this.sitemapUrlEl?.value || '',
        textareaUrls: this.urlsTextarea?.value || '',
        urls: this.urlsSnapshot,
        currentIndex: this.currentIndex,
        log: this.logEntries.slice(-LOG_RETENTION),
        savedAt: Date.now(),
      };
      localStorage.setItem(this.storageKey(), JSON.stringify(payload));
    } catch {
      // Quota / private browsing — fail silent. State is best-effort.
    }
  }

  loadStoredState() {
    if (typeof localStorage === 'undefined') return null;
    try {
      const raw = localStorage.getItem(this.storageKey());
      if (!raw) return null;
      const data = JSON.parse(raw);
      return data && typeof data === 'object' ? data : null;
    } catch {
      return null;
    }
  }

  clearStoredState() {
    if (typeof localStorage === 'undefined') return;
    try {
      localStorage.removeItem(this.storageKey());
    } catch {
      // ignore
    }
  }

  restoreState() {
    const data = this.loadStoredState();
    if (!data) return;

    if (typeof data.sitemapUrl === 'string' && this.sitemapUrlEl) {
      this.sitemapUrlEl.value = data.sitemapUrl;
    }
    if (typeof data.textareaUrls === 'string' && this.urlsTextarea) {
      this.urlsTextarea.value = data.textareaUrls;
    }
    if (this.urlCountEl) {
      this.urlCountEl.textContent = String(this.collectUrls().length);
    }
    if (Array.isArray(data.log)) {
      // Replay structured entries through renderLogEntry directly so
      // we don't re-push into logEntries (it's already populated below).
      this.logEntries = data.log.filter(
        (e) => e && typeof e === 'object' && typeof e.kind === 'string' && typeof e.message === 'string',
      );
      if (this.log) this.log.innerHTML = '';
      for (const entry of this.logEntries) {
        this.renderLogEntry(entry.kind, entry.message);
      }
    }
    if (Array.isArray(data.urls) && data.urls.length > 0 && Number.isInteger(data.currentIndex)) {
      this.urlsSnapshot = data.urls;
      this.currentIndex = data.currentIndex;
      const total = this.urlsSnapshot.length;
      if (this.currentIndex < total - 1) {
        // There's still work left → button morphs to Continue.
        this.setButtonState('paused');
        this.appendLog(
          'info',
          this.t(this.i18n.restored, { current: this.currentIndex + 1, total }),
        );
      } else {
        // Run completed before reload; clear snapshot, stay idle.
        this.urlsSnapshot = null;
        this.currentIndex = -1;
      }
    }
  }

  scheduleTextareaSave() {
    if (this.textareaSaveTimer !== null) clearTimeout(this.textareaSaveTimer);
    this.textareaSaveTimer = setTimeout(() => {
      this.textareaSaveTimer = null;
      this.saveState();
    }, TEXTAREA_SAVE_DEBOUNCE_MS);
  }

  onReset() {
    if (this.running) return;
    this.clearStoredState();
    this.urlsSnapshot = null;
    this.currentIndex = -1;
    this.clearLog();
    this.setButtonState('idle');
    // Paused state leaves the textarea + site picker + refetch
    // disabled (so a paused run can't be edited mid-snapshot). Reset
    // is the explicit "go back to idle" action — re-enable them.
    if (this.urlsTextarea) this.urlsTextarea.disabled = false;
    if (this.siteSelect) this.siteSelect.disabled = false;
    if (this.refetchButton) this.refetchButton.disabled = false;
    this.appendLog('info', this.i18n.cleared);
    if (this.fetchSitemapUrl) {
      void this.refetchSitemap({ resetUrl: true });
    }
  }
}

new Discovery().initialize();

export default Discovery;
