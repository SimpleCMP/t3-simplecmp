# Changelog

All notable changes to `wapplersystems/simplecmp-typo3` are recorded here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This extension is **pre-1.0**. The API may shift between minor versions in
step with the upstream
[SimpleCMP](https://github.com/SimpleCMP/simplecmp) library's own pre-1.0
development.

## Unreleased

### Fixed

- **Modal focus trap + `aria-labelledby` (bundle resync).** Tracks
  upstream `simplecmp@d53c94f`: the consent modal now hand-rolls a
  Tab/Shift+Tab focus trap (native `<dialog>`'s built-in trap doesn't
  work inside Shadow DOM) and exposes an explicit
  `aria-labelledby="simplecmp-modal-title"`. No TYPO3-side code change
  — bundle resync only.
- **WCAG AA contrast — default primary token darkened**. Tracks
  upstream `simplecmp@c4159f6`: default `color-primary` shifts from
  `#1a936f` (3.85:1 on white, failed AA) to `#15775a` (5.30:1);
  `color-primary-hover` from `#15775a` to `#0f5d44`.
  `ThemeDesignerController::DEFAULT_TOKENS` updated alongside, so
  `sanitizeTokens` keeps treating the upstream defaults as "drop
  from storage" (otherwise sites running default primary would have
  it persisted as a non-default and stop tracking future upstream
  changes). `ApproveModal.js` accent-color updated, bundle resynced
  for the FE banner.
- **Consent persistence broken for large libraries** (compliance
  fix). The FE config emits one entry per registered service in
  the consent payload, which after v0.2.0's services-library
  expansion runs ~9 KB. Browsers silently drop cookies exceeding
  the per-cookie ~4 KB limit, so consent was never persisting and
  the banner re-prompted every visit. `RegisterAssets` now emits
  `storageMethod: 'localStorage'` in the FE init config; the
  engine's `LocalStorageStore` handles MB-scale values. The deeper
  question — whether the FE config should carry the full registry
  at all — is deferred for v1.0.
- **BE detection state derivation recognises host-qualified
  matchers**. `DetectionListPresenter::cookieMatches` was the
  second place where object-form entries got silently skipped
  (the first was `ServiceRepository::cookieMatches`, fixed in
  commit `4dd3a0c`). With both paths fixed, BE detection rows
  whose match is via an ADR-0010 host-qualified matcher
  correctly surface as `kuratiert` instead of `unbekannt`. Same
  shared `cookieNameMatches` helper.

### Added

- **Cross-classifier parity test** (`Tests/Unit/Classifier/ParityTest.php`)
  + matching fixture (`classifier-parity-fixture.json`). 20
  assertions (10 cases × `ServiceRepository` + `DetectionListPresenter`)
  load the SAME fixture as the JS side at `simplecmp/tests/`. Any
  future divergence between the two PHP paths or between PHP and
  JS surfaces as a fixture mismatch. Caught the
  `DetectionListPresenter` gap mentioned above.
- **Library-walk determinism test**
  (`Tests/Unit/Classifier/LibraryWalkDeterminismTest.php`). Locks in
  "first match in array order wins" + "real library iterates
  alphabetically by `id`". A future refactor that changes iteration
  order (caching, scandir without sort, …) breaks the test rather
  than silently flipping admins' BE match badges.

- **Host-qualified cookie matcher support** in the Service-DB
  middleware (ADR-0010 in upstream `SimpleCMP/simplecmp`).
  `ServiceRepository::cookieMatches` now recognises the object form
  `{name, requireOrigin}` and surfaces the candidate service for
  `/lookup` cookie queries. The recorder applies the requireOrigin
  check at runtime, so generic-name cookies (Stripe's `m`, GTM's
  `td`, Bing's MR/MC0/CC, …) only classify when their setting host
  is also loaded on the page. Companion to `simplecmp@5358528`.

- **Purposes multi-select widget** in the service TCA. Replaces the
  JSON textarea on `tx_simplecmptypo3_service.purposes` with a
  side-by-side dual-listbox + filter textbox
  (`selectMultipleSideBySide` + `enableMultiSelectFilterTextfield`).
  Available items are auto-discovered from the bundled
  `simplecmp/services-library` via `itemsProcFunc` — future purpose
  categories appearing in a library release show up in the BE form
  automatically. The DB column stays JSON; a FormDataProvider +
  DataHandler hook pivot between CSV (form value) and JSON (storage).
- **Documentation catch-up** on docs.typo3.org. Introduction lists
  the two BE modules; Administration rewritten for the v0.2.0 three-
  state model (kuratiert / erkannt / unbekannt) and adds a Banner
  Design module section; Configuration mentions the new purposes
  widget. `guides.xml` bumped to project-release 0.2.0.

## 0.2.0 — 2026-05-17

Big sixth-iteration release. Largest UX overhaul to date — the
manual `reviewed` flag is gone (replaced by a three-state model
derived from registry + library coverage), the Banner Design BE
module shipped end-to-end with live preview, and the documentation
caught up across both README and screenshots. Breaking changes
listed below; admins upgrading from 0.1.0 should run the TYPO3
schema analyzer to drop the obsolete `reviewed` column.

### Added

- **Banner Design BE module** at *Websites → SimpleCMP banner design*.
  Per-site theme editor for the FE consent banner. Customise brand
  colors (Primary, Decline), surface colors (Text, Background, Border),
  typography (body + heading font-family and font-size with px/rem/em
  support), and corner radius. Tokens grouped into Brand / Surface /
  Advanced (collapsed) / Typography / Shape sections, with a live
  preview iframe on the right of the form that updates as you type.
  A "Detect fonts from active site" button reads computed body + h1
  font-family + font-size from the FE via a hidden iframe (same-origin
  only). Tokens persist in a new `tx_simplecmptypo3_theme` table —
  one row per Site Set, deleting resets that site to defaults.
- **Three-state detection model** (kuratiert / erkannt / unbekannt).
  Replaces the manual `reviewed` flag with state derived per row at
  view time from registry coverage + bundled library coverage. Three
  per-row actions: *Übernehmen* (one-click silent-import after a
  confirmation modal showing the library service summary), *Anpassen*
  (curate with library pre-fill), *Kuratieren* (bare new-record form).
  No dismiss-only path: admins must engage with every actionable row.
- **Übernehmen confirmation modal** with three labeled sections —
  Frontend data (purposes with descriptions, privacy URL, faithful
  preview of the FE service-toggle), Raw data (JSON literals + link
  to library source on GitHub), and Impact (count of existing
  detections that resolve on approval).
- **Known-trackers import command.** `vendor/bin/typo3 simplecmp:import-known-trackers`
  ships a curated library of 40 well-known third-party trackers
  (analytics, ad networks, embeds, chat widgets, payments,
  monitoring, fonts, maps) and upserts them into the service
  registry. Default behaviour is skip-if-exists so admin edits are
  preserved; pass `--force` to overwrite with the bundled values.
- **Per-row delete** in the *SimpleCMP detections* BE module. Each
  row in the list gains a delete icon button with a confirm dialog.
- **Multi-row selection + bulk delete** in the detection list. A
  new checkbox column lets admins tick the rows they want to wipe;
  the *Delete selected (n)* item in the bulk-delete dropdown is
  disabled while nothing is checked and shows the live count once
  rows are ticked. A header *Select all* checkbox toggles every row
  at once.
- **Bulk-delete-all** alongside the existing *Delete selected*
  button, surfaced as a split-button dropdown.
- **`Documentation/Images/` directory** with 9 BE / FE screenshots
  embedded throughout the README.

### Changed

- **README rewritten** around the three-state model with embedded
  screenshots. English labels in prose with a disclaimer that the
  screenshots are German-locale.
- **Bridge-secret callout + button copy** rephrased for non-technical
  admins. *"Bridge webhook is disabled"* → *"Tracker detection is
  off"*; *"Generate bridge secret"* → *"Turn on detection"*; tooltips
  drop *"HMAC nonces", "POSTs", "settings.php"* in favour of
  observable effects.
- **Status column** renamed: DE *"Quelle"* / EN *"Source"* →
  *"Meldende Site"* / *"Reporting site"*. Disambiguates from the
  third-party-origin `origin` column. Underlying field unchanged.
- **BE menu**: detection module and banner design module are flat
  siblings under *Websites*, prefixed *SimpleCMP* for visual grouping
  (TYPO3 BE menu is intentionally 2-level only).

### Removed (breaking)

- **`tx_simplecmptypo3_detection.reviewed` column dropped.** Run the
  TYPO3 schema analyzer after upgrading, or `ALTER TABLE ... DROP
  COLUMN reviewed` manually. Detection state is now derived at view
  time from registry + library coverage.
- **`markReviewed` / `unmarkReviewed` / `bulkDeleteReviewed`
  actions removed** from `DetectionReviewController` and the BE
  module's registered controllerActions.
- **All flash messages removed** from the detection module. UI
  feedback comes from the row's state badge after redirect.
- **Low-confidence-confirm dialog removed** from the *Anpassen* and
  *Kuratieren* per-row buttons. Spike-alert covers the planted-row
  threat at the table level instead.
- Filter `status` values renamed: `unreviewed` → `pending`, plus new
  `erkannt` / `unbekannt` / `kuratiert` values. Bookmarked URLs with
  `?status=unreviewed` redirect to the default pending view.

### Fixed

- `simplecmp.serviceDbUrl` Site Set values ending in `/v1` are now
  auto-stripped at render time (the JS client appends the protocol
  version itself; a configured `/v1` caused double-`/v1/v1/` 404s).
  Trailing slashes are also normalized. Auto-corrections are logged
  as warnings so the misconfiguration is visible without breaking
  the site.
- **Bulk-delete button vertical alignment** — the counter badge's
  1px border made the *Delete selected* button 0.56px taller than
  the dropdown-toggle; stripped the badge border so both heights
  match.
- **Theme overrides reach nested SimpleCMP components.** A light-DOM
  `<style>` block doesn't theme nested components (each component's
  `:host` rule re-declares the tokens, breaking inheritance). The
  Banner Design FE injection emits a script that walks every
  SimpleCMP custom element's shadow root and adopts a stylesheet
  with the theme, with a MutationObserver re-applying when the
  modal mounts lazily.

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
