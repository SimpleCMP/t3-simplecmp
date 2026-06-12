# Consent Mode v2 — wire the tracker providers to the engine hook

**Date:** 2026-06-12
**Status:** Resolved — the posture is now `consentPosture: block | signal-gate`
per tracker (default `block`, DACH-safest). `TrackerRuntimeState` carries the
"any signal-gate tracker on this request" signal from `TrackerMaterializer` to
`RegisterAssets`, which forwards `consentMode: true` into `cmp.init()`. The
hand-rolled `gtag('consent', 'default', …)` in `Ga4Provider` /
`GtmProvider` is gone — the engine hook owns both `default` and `update`. The
ADR-0016 anti-pattern (block AND signal-gate) is now structurally
unrepresentable.
**Component:** `Classes/Tracker/*Provider.php`, `TrackerMaterializer`, the
Tracker-Setup BE wizard.
**Owner:** Sven (tracker subsystem author).

## TL;DR

`Ga4Provider` and `GtmProvider` emit Consent Mode v2 **`default` (denied)** but
carry an explicit `@todo`: the matching **`gtag('consent','update', …granted…)`
on accept was never wired. The upstream `simplecmp` engine now ships that half
(opt-in `consentMode` hook — REQ-N10 / ADR-0016, `simplecmp@5016b91`). This doc
records how to finish the t3 side, and a posture decision that must be made at
the same time — because the current setup is the "block **and** signal-gate"
combination ADR-0016 warns against, and it is likely silently suppressing GA4
**after** consent.

## What exists today

`TrackerMaterializer` per render:
1. upserts a service row (banner + CSP),
2. emits the **loader** (`gtag/js`, `gtm.js`) stamped `data-name="<service_id>"`
   → gated by the bundle's runtime patch = **load-blocking**,
3. emits a **bootstrap inline** that hand-rolls `gtag('consent','default',
   {…denied…})` (see `Ga4Provider::getBootstrapInlineScript`,
   `GtmProvider::getBootstrapInlineScript`).

So each Google tracker is **both** load-blocked (`data-name` gating) **and**
given a consent-mode `default`. No `update` is ever emitted.

## Two problems

1. **ADR-0016 anti-pattern, live.** A service should be *either* load-blocked
   *or* signal-gated, not both. Advanced Consent Mode's value is that the tag
   loads pre-consent and sends cookieless pings; load-blocking the loader
   defeats that and degrades to Basic.
2. **Likely latent bug (verify).** With `consentMode: true` (the provider
   default), once the loader unblocks post-consent and `gtag.js` loads, it reads
   the dataLayer's `analytics_storage: 'denied'` and — with **no `update`** ever
   emitted — stays denied. Net effect: **GA4 drops data even after the visitor
   accepts.** Today the only safe combo is `consentMode: false` + rely purely on
   the load-block (i.e. Consent Mode is effectively decorative).

## What to do (the integration)

Pick a **posture per tracker** (expose it in the BE wizard / YAML), then:

- **Posture A — Block (strict, current default, DACH-safest).** Keep the
  `data-name` load-gate. Turn the provider's consent-mode `default` **off**
  (it's moot — the tag never loads pre-consent, and the dangling `default:
  denied` is what causes problem #2). No call to Google until consent.
- **Posture B — Signal-gate (Advanced Consent Mode v2).** **Drop** the
  `data-name` gate for that tracker (let the tag load), and let the engine's
  `consentMode` hook own **both** `default` and `update`. The tag sends
  cookieless pings pre-consent, full data after. Better measurement; the
  cookieless pre-consent ping is a Google network call several DACH/EU
  regulators contest — surface that trade-off to the editor, don't default to it
  silently.

Mechanics for Posture B:
- Stop hand-rolling `gtag('consent','default')` in the providers (avoid a
  competing/duplicate `default`); let the engine hook emit it. Forward
  `consentMode: true` (or a `ConsentModeConfig`) into the `cmp.init()` config in
  `RegisterAssets::buildInitConfig` — the regime (REQ-N4) + GPC (REQ-5) state
  composition is automatic. The engine maps `analytics → analytics_storage`,
  `marketing → ad_storage + ad_user_data + ad_personalization`, which matches
  the providers' `purposes` (`Ga4Provider` = `['analytics']`, `GtmProvider` =
  `['marketing']` by default).
- The engine hook emits the `update` on every consent decision **and** replays
  it for returning visitors — fulfilling the `@todo`. The interim
  `window.SimpleCMP.on('consent', …)` snippet in
  `2026-06-08-tag-manager-first.md` becomes unnecessary.

## Coordinate

This reworks the tracker subsystem's consent integration (Sven's code) and adds
a BE-UX posture choice. Treat the posture as first-class, not a bolt-on toggle.
Sequenced **after** the Shopify app proves the engine hook end-to-end (clean
slate, no existing subsystem to reconcile).

## References

- Upstream `simplecmp`: REQ-N10 + ADR-0016 (Consent Mode v2 engine hook),
  `src/engine/consent-mode.ts`, commit `5016b91`. Shopify ADR-0003 drove the
  gate-not-forward decision.
- `2026-06-08-tag-manager-first.md` — "Consent Mode v2 wiring (current
  limitation)" section (the interim integrator workaround).
- `Classes/Tracker/Ga4Provider.php`, `Classes/Tracker/GtmProvider.php` — the
  `@todo` markers this resolves.
