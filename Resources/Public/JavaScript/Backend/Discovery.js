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

class Discovery {
  initialize() {
    const root = document.querySelector('[data-discover-config]');
    if (!root) return;
    this.root = root;
    this.fetchSitemapUrl = root.getAttribute('data-discover-fetch-sitemap-url') || '';
    this.startButton = document.querySelector('[data-discover-start]');
    this.cancelButton = document.querySelector('[data-discover-cancel]');
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

    if (this.urlsTextarea && this.urlCountEl) {
      const recount = () => {
        this.urlCountEl.textContent = String(this.collectUrls().length);
      };
      this.urlsTextarea.addEventListener('input', recount);
      recount();
    }

    this.cancelled = false;
    this.running = false;

    if (this.startButton) {
      this.startButton.addEventListener('click', () => this.start());
    }
    if (this.cancelButton) {
      this.cancelButton.addEventListener('click', () => this.cancel());
    }
    if (this.siteSelect) {
      this.siteSelect.addEventListener('change', () => this.onSiteChange());
    }
    if (this.showIframeToggle && this.iframeWrap) {
      this.showIframeToggle.addEventListener('change', () => {
        this.iframeWrap.hidden = !this.showIframeToggle.checked;
      });
    }
  }

  async onSiteChange() {
    const site = this.siteSelect.value;
    if (!site || !this.fetchSitemapUrl) return;
    const url = `${this.fetchSitemapUrl}${this.fetchSitemapUrl.includes('?') ? '&' : '?'}tx_simplecmptypo3_simplecmpdetections%5Bsite%5D=${encodeURIComponent(site)}`;
    try {
      const response = await fetch(url, { credentials: 'same-origin' });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      this.renderUrls(data.urls || [], data.sitemapUrl || '');
    } catch (err) {
      this.appendLog('error', `Failed to re-fetch sitemap: ${err.message}`);
    }
  }

  renderUrls(urls, sitemapUrl) {
    if (this.sitemapUrlEl) this.sitemapUrlEl.textContent = sitemapUrl;
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

  async start() {
    if (this.running) return;
    const urls = this.collectUrls();
    if (urls.length === 0) {
      this.appendLog('warn', 'No URLs to visit. Paste at least one URL and try again.');
      return;
    }

    this.running = true;
    this.cancelled = false;
    this.startButton.disabled = true;
    if (this.cancelButton) this.cancelButton.disabled = false;
    if (this.siteSelect) this.siteSelect.disabled = true;
    if (this.urlsTextarea) this.urlsTextarea.disabled = true;
    if (this.progressEl) this.progressEl.hidden = false;
    this.clearLog();
    this.appendLog('info', `Starting discovery for ${urls.length} URL${urls.length === 1 ? '' : 's'}.`);

    let visited = 0;
    let skipped = 0;
    for (const url of urls) {
      if (this.cancelled) break;
      visited++;
      this.updateProgress(visited, urls.length, url);
      try {
        await this.visit(url);
        this.appendLog('ok', `${url}`);
      } catch (err) {
        skipped++;
        this.appendLog('warn', `${url} — ${err.message}`);
      }
    }

    // Final navigation away so the last document's pagehide fires and
    // any queued bridge batch flushes via sendBeacon.
    if (this.iframe) {
      this.iframe.src = 'about:blank';
      await this.delay(BEACON_GRACE_MS);
    }

    const summary = this.cancelled
      ? `Cancelled after ${visited} of ${urls.length} URLs (${skipped} skipped).`
      : `Done. Visited ${visited} URLs (${skipped} skipped).`;
    this.appendLog('info', summary);

    this.running = false;
    this.cancelled = false;
    this.startButton.disabled = false;
    if (this.cancelButton) this.cancelButton.disabled = true;
    if (this.siteSelect) this.siteSelect.disabled = false;
    if (this.urlsTextarea) this.urlsTextarea.disabled = false;
  }

  cancel() {
    if (!this.running) return;
    this.cancelled = true;
    if (this.cancelButton) this.cancelButton.disabled = true;
    this.appendLog('info', 'Cancelling after current URL…');
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
        reject(new Error('iframe load error'));
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

  appendLog(kind, message) {
    if (!this.log) return;
    if (this.log.firstElementChild?.tagName === 'EM') {
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
    if (this.log) this.log.innerHTML = '';
  }

  delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }
}

new Discovery().initialize();

export default Discovery;
