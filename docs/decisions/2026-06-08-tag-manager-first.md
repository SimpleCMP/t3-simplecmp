# Tag-Manager-First recommendation for multi-tracker sites

**Date:** 2026-06-08
**Status:** Accepted (guidance — not enforced in code).
**Component:** Integrator UX guidance for `simplecmp.trackers` and the
BE Tracker-Setup wizard.
**Audience:** Integrators, DevOps, anyone who configures the
`simplecmp.trackers` site setting or the Tracker-Setup BE module.

## TL;DR

If a site has **more than two** trackers, or **marketing wants to
add/remove** trackers without TYPO3 deployments, install a **Tag
Manager** (Matomo TM if you can self-host, Google TM otherwise) and
register only the Tag Manager as a SimpleCMP service. Manage the
individual trackers inside the TM UI. Below two trackers, direct
integration via `simplecmp.trackers` (YAML) or the Tracker-Setup BE
wizard remains the cleaner path.

## The friction-grows-linearly problem

Every additional tracker on a TYPO3 site multiplies complexity along
several dimensions:

| Aspect | 1 tracker | 5 trackers (direct) | 5 trackers (via TM) |
|--------|-----------|---------------------|---------------------|
| Service-DB rows | 1 | 5 | 1 |
| Consent banner entries | 1 | 5 | 1 |
| CSP origins to whitelist | ~1 | ~5–10 | 1 |
| Loader scripts in `<head>` | 1 | 5 | 1 |
| Bootstrap snippets | 1 | 5 | 1 |
| Changes per tracker swap | — | TYPO3 deploy | TM UI click |
| Editor needs server access | — | yes (or t3-simplecmp 0.4.0+ BE wizard) | no (after TM is wired) |

Past three or four trackers the direct path turns into per-tracker
PR + deploy cycles every time marketing wants to A/B a new pixel.
That's where Tag Manager pays off.

## Recommendation matrix

| Situation | Recommendation |
|-----------|----------------|
| 1 tracker (e.g. just Matomo) | **Direct.** TM is overkill — the indirection costs more than it saves. |
| 2 trackers, stable | **Direct.** Two clean Service-DB rows + two CSP entries is still manageable. |
| 2 trackers, frequent changes | **Consider TM.** If marketing iterates on the second tracker monthly, the TM payoff already kicks in. |
| ≥3 trackers, or marketing self-service | **Tag Manager strongly recommended.** Lower TYPO3-side surface, fewer deployments. |
| Privacy-first / "no Google touchpoints" | **Self-hosted Matomo Tag Manager.** Container hosted on your infra, no Google US-data-transfer risk. |
| DACH compliance focus | **Self-hosted Matomo TM** or **server-side GTM via Stape/own server.** Client-side GTM has been flagged by BfDI / DSK because the loader request itself leaks the visitor IP to Google before consent. |
| Heavy marketing operation | **Server-side TM** (Google TM Server / Stape / Matomo MTM). First-party cookies via your subdomain, ITP-resilient, CSP only needs your own host. |

## Why not just always use TM?

Tag Manager is not free of cost — three real downsides:

1. **Extra dependency.** Adds a vendor (Google LLC or InnoCraft) the
   site otherwise wouldn't have. If your tracker IS Matomo and you
   self-host it, adding Google TM just to "manage" the one tracker is
   nonsense.

2. **Pre-consent IP leak to the TM CDN.** Until the CMP gates the TM
   loader on consent (which t3-simplecmp does, via `data-name="gtm"`),
   the TM container's hostname (`googletagmanager.com` or your own
   server-side subdomain) is contacted on every page. This is the
   exact problem the BGH "Cookie II" + DSK 2022/2023 guidance is
   about. Server-side TM moves the endpoint to your own subdomain
   and so removes the third-party-DNS leak.

3. **Misconfigured container = silent compliance failure.** A wrong
   tag inside the TM container (e.g. a Facebook pixel that fires on
   page-view regardless of CMP state) is invisible from TYPO3's side.
   The CMP gate is the LOADER; downstream tag firing depends on TM
   listening to Consent Mode v2. Marketing teams must understand
   that.

Single-tracker setups don't earn those costs back.

## Server-side tagging is the next frontier

The most privacy-friendly variant: instead of `*.googletagmanager.com`
loading the container directly in the browser, the loader hits **your
own subdomain** (e.g. `https://gtm.example.com/gtag/destination`)
which then forwards events server-side to the actual vendors.

Wins:

* **CSP simplifies.** Only `gtm.example.com` (self) needs whitelisting.
  No third-party origins for the tracker beacons.
* **First-party cookies.** Cookies set by the server-side endpoint
  are first-party — ITP / Tracking Protection won't axe them after
  7 days.
* **No third-party DNS leak** before consent. The vendor never sees
  the visitor's IP unless your server-side container actually
  forwards.
* **Compliance**: server-side GTM with stripped IP and consent-state
  filtering passes DACH scrutiny far more reliably than client-side
  GTM.

Implementations to consider:

* **Matomo Tag Manager** (free, self-host alongside Matomo)
* **Stape** (commercial, fast setup)
* **Google Tag Manager Server-Side Container** (Google Cloud,
  technical setup, pay-per-event)
* **AnalyticsHub** (privacy-focused EU offering)

t3-simplecmp does **not yet** ship a server-side proxy (that's UX
Stage 4 on the roadmap). For now, integrators configure their TM
endpoint manually via `simplecmp.trackers[].url` or the BE wizard.

## Consent Mode v2 wiring (current limitation)

The bundled GA4 and GTM providers emit the recommended
`gtag('consent', 'default', { …denied… })` block via their Bootstrap
inline scripts. **They do not yet** emit the matching
`gtag('consent', 'update', { …granted… })` when the CMP records an
accept — that wiring is on the roadmap and marked with `@todo` in
the provider sources.

For now, integrators bridging the SimpleCMP bundle's consent events
to gtag's update API:

```js
// In your site-package JS (after simplecmp is loaded):
window.SimpleCMP?.on?.('consent', (consent) => {
  if (typeof window.gtag !== 'function') return;
  window.gtag('consent', 'update', {
    ad_storage: consent.marketing ? 'granted' : 'denied',
    ad_user_data: consent.marketing ? 'granted' : 'denied',
    ad_personalization: consent.marketing ? 'granted' : 'denied',
    analytics_storage: consent.analytics ? 'granted' : 'denied',
  });
});
```

Adjust the `consent.marketing` / `consent.analytics` accessors to
match your actual purpose / service taxonomy.

## Concrete migration paths

**You have 4 direct trackers, want to consolidate**

1. Install Matomo TM (or Google TM) and configure all four tags
   inside it. Test with TM's preview mode.
2. In TYPO3: remove the four `simplecmp.trackers[]` entries.
3. Add a single `gtm` (or matomo-tm) entry.
4. Verify in DevTools → Network: vendor beacons should now flow
   through TM's container script, not direct.
5. After a stability window, delete the redundant service-DB rows.

**You're starting fresh with marketing involvement**

1. Set up server-side TM **first** (Stape / Matomo MTM / own server).
2. Register exactly one tracker in t3-simplecmp pointing at the
   subdomain.
3. Hand marketing the TM UI access. They configure individual tags
   inside the container — TYPO3 stays untouched.

**You want to keep things minimal**

Keep your single self-hosted Matomo, configure it via
`simplecmp.trackers` YAML, leave TM out. The simplest setup wins
when there's nothing to consolidate.

## Related ADRs

* `2026-05-30-link-rewrite-rel-policy.md` — universal-blocking
  rewriter scope.

## References

* BGH I ZR 7/16 — equal-prominence requirement for consent buttons.
* DSK 2022/2023 — Datenschutzkonferenz guidance on cookie banners
  and tracking technologies.
* EDPB Guidelines 03/2022 — Dark Patterns in social media
  interfaces, applicable to consent UI.
* IAB Europe TCF v2.2 — not what SimpleCMP implements, but mentioned
  because TM tools often expect it as input.
