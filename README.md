# SimpleCMP for TYPO3

TYPO3 v14 integration for [SimpleCMP](https://github.com/SimpleCMP/simplecmp) — the
open-source consent manager with development-time tracker auto-detection, a shared
service database, and optional CMS-bridge webhook alerts.

This extension is **pre-1.0** and tracks SimpleCMP's own pre-release status. APIs
will change.

## What it does

- **Frontend:** loads the SimpleCMP JS bundle on every TYPO3 frontend page and
  passes it config sourced from the site's Settings (Site Sets, v13+).
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
- **TYPO3 backend module** *(iteration 4 — not yet implemented):*
  admins will review unknown detections and curate the service
  registry from the BE.

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

## Status

Iteration 1 in progress. See the upstream
[SimpleCMP requirements](https://github.com/SimpleCMP/simplecmp/blob/main/docs/requirements.md)
for the JS-side roadmap.

## License

BSD-3-Clause. Mirrors the upstream SimpleCMP license.
