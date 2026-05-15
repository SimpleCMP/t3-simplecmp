# SimpleCMP for TYPO3

TYPO3 v14 integration for [SimpleCMP](https://github.com/SimpleCMP/simplecmp) — the
open-source consent manager with development-time tracker auto-detection, a shared
service database, and optional CMS-bridge webhook alerts.

This extension is **pre-1.0** and tracks SimpleCMP's own pre-release status. APIs
will change.

## What it does

- **Frontend:** loads the SimpleCMP JS bundle on every TYPO3 frontend page
  and passes it config sourced from the site's Settings (Site Sets, v13+).
  Service entries from the registry (`tx_simplecmptypo3_service`) flow
  into the inline `init({...})` payload — both as runtime services and
  as a `translations` block with per-language title/description.
  `acceptAll: true` is enabled so the modal shows the full Decline /
  Save / Accept-all trio.
- **Service DB endpoint** *(iteration 2 — shipped):* TYPO3-hosted
  implementation of the
  [SimpleCMP service-DB protocol](https://github.com/SimpleCMP/simplecmp/blob/main/docs/service-db-protocol.md).
  Routes at `/api/simplecmp/v1/{health,services,lookup}`. 10 bundled
  seeds (Google Analytics, Matomo, YouTube, …) loaded via
  `ddev exec vendor/bin/typo3 simplecmp:seed`.
- **CMS-bridge receiver** *(iteration 3 — shipped):* receives JSON
  POSTs from the SimpleCMP bridge at `/api/simplecmp/webhook` and
  stores them in `tx_simplecmptypo3_detection`. Idempotent — repeat
  hits of the same `(source, kind, identifier)` triple bump
  `occurrences` rather than inserting duplicates.
- **TYPO3 backend module** *(iteration 4 — shipped):* admins review
  unknown detections at *Site Management → SimpleCMP-Detektionen* and
  curate the service registry from the BE. Extbase controller with
  `list`, `show`, `markReviewed`, `unmarkReviewed`, `bulkDelete`, and
  `createService` actions. Renders inside the standard TYPO3 backend
  chrome (`<f:layout name="Module" />`, docheader, breadcrumb,
  flash-message slot). EN + DE i18n. The `onlyUnreviewed` filter
  survives mark / unmark / bulk-delete redirects. Bulk delete asks
  for confirmation via a CSP-safe JS module (the previous inline
  `onsubmit="confirm(...)"` was blocked by TYPO3 v14's BE CSP).
- **"Convert to service" smart redirect:** clicking *In Dienst
  umwandeln* on a detection opens the existing service for editing
  when one already matches the detection's cookie/origin (resolved
  via `ServiceRepository::lookup()`, tiebreak by `crdate DESC`), so
  the admin sees their previously-curated values instead of a blank
  pre-fill form. Falls through to the standard new-record flow when
  no match exists.
- **Service-ID is a TCA `slug`** with `eval: unique` — admin gets
  inline AJAX feedback as they type ("/<slug>/ wird verwendet" /
  "wird bereits verwendet, stattdessen /<alt>/") and a clean save
  on collision instead of the misleading "Vorgang konnte nicht
  abgeschlossen werden" notice the old `input` + `eval: 'trim,unique'`
  combination produced.

## Known limitation: bridge / Service-DB race

When both `serviceDbUrl` and `cmsBridgeUrl` point at this extension,
the JS-side bridge fires a webhook for **every** detection that's
`unknown` at first announcement — including ones the Service-DB
lookup later resolves to known. Expected order:

1. Recorder catches a cookie.
2. Local classifier misses; detection emitted as `unknown`.
3. Bridge fires webhook immediately.
4. Service-DB lookup completes; status updated to `known`.

So a well-known tracker like `_ga` produces both a Service-DB hit
(detection becomes known on the page) *and* a webhook row in
`tx_simplecmptypo3_detection`. The webhook table effectively becomes a
raw event stream of "things the recorder didn't immediately recognize"
rather than "things nobody knows about." Treat the row as raw input;
cross-reference against `tx_simplecmptypo3_service` before alerting an
admin.

A future SimpleCMP release (tracked alongside iteration 4 of this
extension — the BE module that will actually surface the rows to a
human reviewer) will add an opt-in grace delay so the bridge waits
for the DB lookup to settle. Until then, this asymmetry between the
two persistence paths is documented and intentional.

## Installation

```bash
composer require wapplersystems/simplecmp-typo3
```

In the Site → Site Sets page, add the **SimpleCMP — consent manager** set as a
dependency. Configure under Site → Settings.

### Configuring the bridge webhook (required if `cmsBridgeUrl` is set)

The bridge webhook requires a configured secret. Two ways to bootstrap
one:

- **CLI:** `vendor/bin/typo3 simplecmp:generate-bridge-secret` prints
  a fresh value plus a paste-ready configuration snippet
  (env-var interpolation recommended for production).
- **BE module:** the SimpleCMP detection list surfaces a *Generate
  bridge secret* button when no secret is configured. The button
  writes the value to `config/system/settings.php` for you.

One secret per TYPO3 installation. If you run multiple installs and
one POSTs bridge webhooks to another, configure the **same** value on
both ends.

## Status

Iterations 1–4 shipped. The frontend banner/modal, the service-DB
endpoint, the CMS-bridge receiver, and the BE module are all live.
See the upstream
[SimpleCMP requirements](https://github.com/SimpleCMP/simplecmp/blob/main/docs/requirements.md)
for the JS-side roadmap.

## License

BSD-3-Clause. Mirrors the upstream SimpleCMP license.
