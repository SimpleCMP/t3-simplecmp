# Changelog

All notable changes to `wapplersystems/simplecmp-typo3` are recorded here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This extension is **pre-1.0**. The API may shift between minor versions in
step with the upstream
[SimpleCMP](https://github.com/SimpleCMP/simplecmp) library's own pre-1.0
development.

## Unreleased

### Added

- **Per-row delete** in the *SimpleCMP detections* BE module. Each
  row in the list gains a delete icon button with a confirm dialog.
- **Multi-row selection + bulk delete** in the detection list. A
  new checkbox column lets admins tick the rows they want to wipe;
  the *Delete selected (n)* item in the bulk-delete dropdown is
  disabled while nothing is checked and shows the live count once
  rows are ticked. A header *Select all* checkbox toggles every row
  at once.
- **Bulk-delete-all** alongside the existing *Delete reviewed*
  button, surfaced as a split-button dropdown. Two distinct
  confirmations: "Delete reviewed" wipes only `reviewed = 1` rows
  (old behaviour); the dropdown's "Delete all detections" wipes
  every row regardless of status.

### Fixed

- `simplecmp.serviceDbUrl` Site Set values ending in `/v1` are now
  auto-stripped at render time (the JS client appends the protocol
  version itself; a configured `/v1` caused double-`/v1/v1/` 404s).
  Trailing slashes are also normalized. Auto-corrections are logged
  as warnings so the misconfiguration is visible without breaking
  the site.

## 0.1.0 — 2026-05-15

First tagged pre-release. Captures iterations 1–4 of the integration plus
the bridge-secret bootstrap.

### Added

- **Frontend integration.** Listens on `BeforeJavaScriptsRenderingEvent`
  and mounts the SimpleCMP JS bundle plus an inline `init()` config on
  every TYPO3 frontend page where the SimpleCMP Site Set is enabled.
  Config is sourced from Site Settings; curated services from the
  registry flow in as both runtime services and per-language UI
  translations.
- **Service-DB endpoint** at `/api/simplecmp/v1/{health,services,lookup}`.
  Implements the upstream
  [service-DB protocol](https://github.com/SimpleCMP/simplecmp/blob/main/docs/service-db-protocol.md).
  Ten bundled seed services importable via
  `vendor/bin/typo3 simplecmp:seed`.
- **CMS-bridge receiver** at `/api/simplecmp/webhook`. Idempotently
  ingests unknown-tracker reports from the SimpleCMP frontend into
  `tx_simplecmptypo3_detection`. Repeat hits of the same
  `(source, kind, identifier)` triple bump `occurrences` rather than
  inserting duplicates.
- **Backend module** at *Site Management → SimpleCMP detections*.
  Review and curate unknown trackers, with list filtering, mark /
  unmark, bulk-delete-reviewed, and a "Convert to service" smart-
  redirect that opens an existing service for editing when one
  already matches the detection's cookie / origin.
- **Bridge secret is required** before the webhook will accept POSTs.
  Two ways to configure it:
  - `vendor/bin/typo3 simplecmp:generate-bridge-secret` from the CLI.
  - "Generate bridge secret" button in the BE module, surfaced when
    no secret is configured.
- **`simplecmp.storagePid` Site Set setting** to control which page UID
  new service / detection records are created under (default `0`).
- **`simplecmp.bridgeRateLimit` Site Set setting** for the receiver's
  per-IP-per-hour cap (default 500, 0 disables).
- **EN + DE translations** throughout the BE module.

### Tooling

- PHPUnit unit-test suite (54 tests). Run with `composer test:unit`.
