# `<link>` rewrite rel-policy for universal blocking

**Date:** 2026-05-30
**Status:** Accepted (Part A shipped). Part B (opt-in stylesheet blocking) deferred.
**Component:** `Classes/UniversalBlocking/Middleware/HtmlRewriter.php`

## The bug

The universal-blocking HTML rewriter mapped `link => href` in `TAG_ATTR`
and rewrote **every** third-party `<link href>` to `about:blank`,
regardless of `rel`. With `simplecmp.universalBlocking.enabled`
defaulting to **on** (since `t3-simplecmp@93a4c9c`, 2026-05-26) and
`blockAllThirdParty=true` by default, every third-party `<link>` host
resolved and got clobbered. Concretely, by default this:

- poisoned `rel="canonical"` / `rel="alternate"` to `about:blank`
  (cross-domain / multilanguage setups → **SEO damage**);
- dropped third-party-CDN `rel="stylesheet"` (Bootstrap, Font Awesome,
  Google Fonts) → **broken page CSS, with no recovery path** (a
  `<link>` cannot carry a click-to-load contextual-notice placeholder
  the way an iframe can);
- killed `rel="icon"` / `rel="manifest"` / `preconnect` / `preload`.

This was a 🟢 finding in the 2026-05-30 three-repo audit.

## Decision (Part A — shipped)

Gate `<link>` rewriting to a **rel allowlist of resource hints only**:

```
preconnect, dns-prefetch, preload, prefetch, modulepreload, prerender
```

Everything else — `stylesheet`, `canonical`, `alternate`, `icon`,
`manifest`, and any **unknown** rel — is left untouched. Allowlist, not
blocklist, so a novel `rel` value can never cause surprise breakage.

Rationale:

- **Resource hints are the genuine pre-consent leak in a `<link>`** —
  they open a DNS/TCP/TLS connection or fetch *before* consent.
  Neutralizing them to `about:blank` is **invisible**: a hint has no
  visual effect, and the underlying script/img/iframe is gated by its
  own rule anyway.
- **Stylesheets are render-critical and have no recovery UI.** Rewriting
  one to `about:blank` silently strips the page's CSS with no way to
  load it after consent.
- **canonical/alternate cause no subresource fetch** — there is no
  privacy reason to touch them, and rewriting them poisons SEO.

## Why not "also block stylesheets" (Option 2)

The obvious maximalist alternative — also block third-party
`rel="stylesheet"` — was rejected for the **default** because:

1. **It breaks real sites at high frequency.** Third-party-CDN
   stylesheets (Bootstrap, Font Awesome, Google Fonts) are everywhere.
   A CMP that de-styles a site on install gets uninstalled — and an
   uninstalled CMP protects nobody.
2. **Server-side `<link>` rewriting is a leaky block anyway.** The
   browser's **preload scanner** can fetch the stylesheet before any
   interception, and `@import url(...)` *inside* a stylesheet escapes
   the rewriter entirely. So stripping `<link rel=stylesheet>` would
   give admins **false confidence** without reliably stopping the
   fetch. (consentmanager.net's own docs make exactly this point.)
3. **The correct fix for third-party fonts is self-hosting**, at a
   different layer — the consensus recommendation across Complianz,
   Usercentrics, and Google itself.

## Market alignment

No mainstream CMP auto-rewrites arbitrary `<link>` tags. The two
blocking models in the market both leave `<link rel=stylesheet>` alone:

- **Known-service template blocking** (Borlabs, Real Cookie Banner) —
  only block curated, recognized embeds; never touch arbitrary links.
- **Prior-consent / DOM-injection blocking** (Cookiebot, Usercentrics,
  consentmanager.net, orestbida/cookieconsent) — target scripts
  (`type="text/plain"`), iframes, images, pixels. Cookiebot blocks
  Google Fonts only via **manual** wrapping of the `<link>` in a
  `<script type="text/plain" data-cookieconsent=…>`, not by auto-
  rewriting the link.

So Part A keeps SimpleCMP aligned with the norm while still neutralizing
the genuine pre-consent leak that hints represent.

## Caveats (must stay documented)

- Part A leaves **all third-party stylesheets unblocked by default**.
  Any third-party `<link rel=stylesheet>` leaks the visitor's IP + UA +
  Referer to that origin on load, pre-consent. The **Google Fonts** case
  is the most prominent (LG München I, 20.01.2022, Az. 3 O 17493/20).
  For a compliance-first DACH product this is a known, accepted gap in
  the default — the honest fix is self-hosting, which the docs should
  surface.
- Neutralizing resource hints removes a performance optimization for the
  blocked third-party (no measured LCP impact for first-party assets,
  which are same-origin and skipped). If a future site reports a
  perf regression from a blocked third-party preload, revisit.

## Part B — follow-up (deferred, tracked here)

Close the Google Fonts gap **honestly**, not with a leaky blanket
blocker:

1. **Per-site opt-in toggle** `universalBlocking.blockStylesheets`
   (default **off**) that blocks third-party `rel="stylesheet"`
   **with consent re-injection** — strip `href`→`data-src`, re-inject
   the `<link>` on Accept (the Cookiebot model), rather than a permanent
   strip. Ship with docs that lead with "self-host your fonts" and frame
   the toggle as best-effort (still subject to the preload-scanner /
   `@import` leaks above).
2. **Surface third-party stylesheet loads** (`fonts.googleapis.com`,
   `fonts.gstatic.com`, …) in the existing detection / Discover flow
   with a "self-host this" recommendation — SimpleCMP's recorder already
   has the right primitive.

Part B needs cross-repo FE engine work (stylesheet re-injection) and is
a feature, not a fix — so it is intentionally decoupled from Part A.
Should be captured as a REQ when picked up.

## References

- `Classes/UniversalBlocking/Middleware/HtmlRewriter.php`
  (`LINK_REWRITABLE_RELS`, `linkRelIsRewritable()`)
- `Tests/Unit/UniversalBlocking/Middleware/HtmlRewriterLinkRelTest.php`
- ADR-0013 (universal-blocking implementation plan, upstream simplecmp)
