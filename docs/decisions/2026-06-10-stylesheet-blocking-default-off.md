# Opt-in stylesheet blocking: default OFF + first-run nudge

**Date:** 2026-06-10
**Status:** Accepted (REQ-N8 Phase A engine + Phase B rewriter shipped).
**Component:** `Classes/UniversalBlocking/Middleware/HtmlRewriter.php`,
`Configuration/Sets/SimpleCmp/settings.definitions.yaml`
(`simplecmp.universalBlocking.blockStylesheets`).

## Context

REQ-N8 adds an opt-in to gate third-party `<link rel="stylesheet">`
(prominently **Google Fonts** — LG München I, 20.01.2022, Az. 3 O
17493/20), with consent re-injection (the engine restores `href` from
`data-href` on consent — Phase A). The open question: should the
`blockStylesheets` toggle default **on** or **off**?

The instinct toward **on** is consistency: `universalBlocking.enabled`
already defaults **true** (`t3-simplecmp@93a4c9c`), and the product is
positioned compliance-first. A compliance-first CMP that leaks Google
Fonts by default arguably fails its own framing.

## Decision

**`blockStylesheets` defaults OFF.** Turning it on is a deliberate,
guided opt-in surfaced via a **first-run nudge** in the backend (built in
Phase C): when the toggle is off, the SimpleCMP module invites the admin
to enable it and immediately run *Tracker entdecken* (Discover) to see
exactly which stylesheets get blocked, then allow the ones they need
(allowlist) or self-host them.

## Why OFF (despite `enabled=true`)

Blocked **scripts/iframes** and blocked **stylesheets** fail very
differently, so the "block-by-default is safe" logic behind
`enabled=true` does **not** transfer:

| | script / iframe | stylesheet |
|---|---|---|
| Failure is | **invisible** until consent | **visible** — broken/unstyled layout |
| Per-visitor recovery | click-to-enable placeholder | **none** (state-3 unknown host = informational notice, no accept button) |
| Wrong-block blast radius | one dead embed | the whole page looks broken |

1. **Visible breakage > invisible leak.** Default-on means any site
   upgrading while using a third-party CSS CDN (Bootstrap, Font Awesome,
   Google Fonts) silently renders broken until the admin notices — and
   visitors can't fix it. "The CMP broke my fonts" destroys trust faster
   than the leak it prevents.
2. **It's leaky anyway** (documented on the setting + the 2026-05-30
   rel-policy decision): the browser preload-scanner can fetch a
   stylesheet before the rewriter intervenes, and `@import url(...)`
   inside a stylesheet escapes entirely. Default-on **over-promises**
   ("now compliant") for best-effort protection.
3. **No mainstream CMP auto-blocks arbitrary stylesheets** (deep research
   2026-05-30: Complianz, Usercentrics, Google all say **self-host**).
   Default-on would ship page-breakage as the outlier.
4. **Opt-in fits the workflow.** The admin enables it deliberately → runs
   Discover → sees what breaks → allows the legit CDNs / self-hosts the
   fonts. The admin *owns* the decision instead of being ambushed. Phase
   C's nudge + fast-allow is what makes opt-in pleasant — not what makes
   default-on safe.

## First-run nudge (Phase C entry point)

Default-off without discovery would just hide the feature. So the
mitigation is the nudge: a prominent, dismissible BE callout (shown while
`blockStylesheets` is off) that frames the trade-off, links self-hosting
guidance first, and offers a one-step "enable + Discover" so the admin
can see and triage the impact in their own browser immediately — no wait
for organic traffic.

## Revisiting

A future flip to default-on is a **shared compliance-posture decision**
(like the `enabled=true` flip, which was Sven's call) — not a unilateral
one. If the preload-scanner / `@import` leaks are ever closed and the
admin-recovery UX proves smooth, reconsider; until then, OFF + nudge.

## References

- REQ-N8 in `simplecmp/docs/requirements.md`
- `docs/decisions/2026-05-30-link-rewrite-rel-policy.md` (Part A / Part B split)
