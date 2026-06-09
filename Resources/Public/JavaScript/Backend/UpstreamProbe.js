/**
 * Auto-probe the library upstream when the Bibliotheks-Upstream panel
 * rendered a stale ("unknown") verdict.
 *
 * The BE list render is deliberately cache-only — it never makes a
 * synchronous /v1/health call, so the tab can't hang on a slow/unreachable
 * upstream (see LibraryUpstreamHealth::cachedSnapshot + the perf fix that
 * removed the ~16 s blocking probe). The trade-off is that the panel often
 * shows a *stale* cache: the success cache lives only 30 min and nothing
 * refreshes it, so on most visits the state is "unknown".
 *
 * Rather than make the admin click "Jetzt prüfen" to find out the real
 * status, we fire that same probe in the background here — render stays
 * instant, then the panel self-heals to ok/down on reload.
 *
 * Termination is guaranteed: a probe ALWAYS writes a success or a
 * (negative-)failure cache row, so the next render is 'ok' or 'down', never
 * 'unknown' again. The sessionStorage throttle only guards the pathological
 * case where the probe POST can't reach the server at all — without it a
 * failed reload could loop.
 */

const PANEL = '[data-simplecmp-upstream]';
const THROTTLE_KEY = 'simplecmp-upstream-autoprobe';
const THROTTLE_MS = 10000;

function init() {
  const panel = document.querySelector(PANEL);
  if (!panel || panel.getAttribute('data-simplecmp-upstream-state') !== 'unknown') {
    return;
  }
  const form = panel.querySelector('[data-simplecmp-upstream-refresh]');
  if (!(form instanceof HTMLFormElement) || !form.action) {
    return;
  }

  try {
    const last = Number(sessionStorage.getItem(THROTTLE_KEY) || '0');
    if (Number.isFinite(last) && Date.now() - last < THROTTLE_MS) {
      return;
    }
    sessionStorage.setItem(THROTTLE_KEY, String(Date.now()));
  } catch {
    // sessionStorage unavailable (private mode / disabled) — proceed
    // without the throttle; the cache-write termination still applies.
  }

  // Swap the stale label for a "checking…" affordance while the probe runs.
  const stale = panel.querySelector('[data-simplecmp-upstream-stale]');
  const checking = panel.querySelector('[data-simplecmp-upstream-checking]');
  if (stale) stale.hidden = true;
  if (checking) checking.hidden = false;

  // Reuse the "Jetzt prüfen" action: it flushes + probes server-side and
  // 303-redirects to the list. We don't care about the response body — the
  // reload re-renders the freshly-cached state.
  fetch(form.action, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new URLSearchParams(),
    credentials: 'same-origin',
  })
    .catch(() => {
      // Swallow — the reload shows whatever the server managed to cache.
    })
    .finally(() => {
      window.location.reload();
    });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
