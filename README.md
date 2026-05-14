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
