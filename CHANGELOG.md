# Changelog

All notable changes to `wapplersystems/simplecmp-typo3` are recorded here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This extension is **pre-1.0**. The API may shift between minor versions in
step with the upstream
[SimpleCMP](https://github.com/SimpleCMP/simplecmp) library's own pre-1.0
development.

## Unreleased

### Added — Discover trackers (sitemap sweep in the admin's browser)

- **New BE action *Tracker entdecken*** linked from the Detektionen
  list toolbar (visible when the bridge is configured). Opens a
  dedicated discovery page that walks a list of FE URLs in a hidden
  iframe inside the admin's own tab. Each iframe load gets
  `?simplecmp_discover=1` appended; the FE recorder + bridge inside
  fire exactly as for a real visitor, so the existing webhook + ingest
  pipeline populates `tx_simplecmptypo3_detection` with no new
  server-side code path. After ~3 s dwell per page the iframe
  navigates on, triggering pagehide → `navigator.sendBeacon` flush.
  See upstream `simplecmp` CHANGELOG for the matching
  `?simplecmp_discover=1` override.
- **`SitemapFetcher` service** — fetches `<baseUrl>/sitemap.xml` via
  TYPO3's `RequestFactory`, parses sitemap and sitemap-index XML
  shapes, recurses one level into sub-sitemaps, de-duplicates, caps
  at 5000 URLs. Failures log a warning and degrade to "no URLs found"
  so the manual textarea fallback takes over.
- **`DiscoveryController`** with `indexAction` (renders the page,
  fetches sitemap for the chosen site) and `fetchSitemapAction` (JSON
  endpoint used by the site-picker to repaint without a full reload).
  Same site-resolution path as the Banner Designer module: only sites
  whose Site Set list includes `wapplersystems/simplecmp` are eligible.
- **Editable URL list (textarea, one per line, `#` comments
  ignored).** Pre-filled from the sitemap when EXT:seo is installed,
  but always present as a fallback for sites without a working sitemap
  (or for ad-hoc one-page checks). Counter live-updates as the admin
  edits.
- **`Discovery.js` walker** drives the iframe sequentially with a
  cancellable progress UI, per-page log lines, and a debug toggle to
  show the iframe while it runs.
- **i18n strings** for the EN + DE locallang files. 17 new units
  covering title, intro, controls, progress, log, fallback alert.

### Added — Dienste tab (registry index, source-tagged)

- **New BE tab "Dienste"** between Detektionen and Bibliothek. Lists
  every row in `tx_simplecmptypo3_service` regardless of origin, with
  filter / search / per-row actions. Closes the UX gap where adopted
  library entries weren't visible together with custom-curated
  services anywhere in the SimpleCMP module — admins previously had
  to bounce to *Web → List → SimpleCMP-Dienst* (outside the module)
  to see the full picture.
- **Three-source-state derivation** (`RegistryListPresenter`):
  - **Eigene** (custom-curated) — no library-adoption history.
  - **Aus Bibliothek** — adopted from the bundled library *and* the
    library still contains the service.
  - **Verwaist** — was adopted from the library, but a later library
    release dropped or renamed the service. The registry row still
    works in the FE banner; admin gets an orange callout, a *Show
    only orphans* shortcut, and Delete is unlocked (because the
    library no longer claims the row).
  Source is derived at view time from a new
  `tx_simplecmptypo3_service.library_adopted_at` column compared
  against the current `ServicesLibrary::services()` ID set, so a
  `composer update` that drops a service flips affected rows from
  Aus-Bibliothek → Verwaist with no migration step.
- **"Aktive Detektionen" column** per registry row — counts how many
  current detections derive to *Kuratiert* via this specific
  service. Surfaces unused services (count = 0) so admins can spot
  prune candidates. One extra full-table detection scan per render.
- **Asymmetric Delete affordance:** *Eigene* and *Verwaist* rows
  expose Delete; *Aus Bibliothek* rows don't — those go through the
  Bibliothek tab's *Unadopt* to keep the adopt-from/unadopt-via-
  library symmetry. The controller re-derives the source server-side
  before deleting, so a forged URL pointing at an Aus-Bibliothek row
  is a no-op rather than a quiet bypass.
- **Upgrade wizard** `simplecmpTypo3BackfillLibraryAdoptedAt`
  back-fills `library_adopted_at = NOW()` for rows that pre-date the
  column whose `service_id` is in the currently-bundled library.
  Idempotent; runs once via Install Tool's Upgrade module or
  `vendor/bin/typo3 upgrade:run simplecmpTypo3BackfillLibraryAdoptedAt`.
- **`ServiceRepository::upsert()` gained `bool $fromLibrary` (default
  false).** Adoption paths
  (`LibraryBrowserController::adoptAction`,
  `DetectionReviewController::approveAction`) now pass `true` so the
  resulting registry row carries a `library_adopted_at` stamp.
- **Inline orphan callout in the TCA edit form.** Editing a Verwaist
  row in the Web → List view (or via the Dienste tab's Edit button)
  now shows a yellow `<div class="alert alert-warning">` at the top
  of the form with the adoption date, signalling the row's
  library-disowned state right where the admin is acting. Driven by
  a custom TCA `type: user, renderType: simplecmpOrphanCallout`
  element that renders empty HTML for Eigene and Aus-Bibliothek
  rows. EN + DE i18n.

### Changed (breaking, pre-1.0) — four-state model adds Verworfen

- **`Verwerfen` (dismiss) replaces destructive Delete on the actionable
  list.** Clicking the row's dismiss icon now sets a new
  `tx_simplecmptypo3_detection.dismissed_at` timestamp rather than
  deleting the row. The detection vanishes from the Needs-action view
  but survives in the new *Verworfen* filter; the dismissal is
  durable across visitors because the bridge receiver's `ingest()`
  bumps `occurrences` / `last_seen` on a re-POST but leaves
  `dismissed_at` untouched. Fresh-browser revisits to the same
  tracker no longer resurrect the row — the cross-browser
  resurrection that previously caused dismissed detections to come
  back via a different browser is closed.
- **New status filter value `verworfen`** plus a "dismissed" counter
  in the headline ("X need action · Y already curated · **Z
  dismissed** · N total"). Default *Needs action* filter excludes
  both curated and dismissed.
- **Endgültig löschen** (true delete) is now reachable only from the
  Verworfen view, behind a confirmation modal that warns the audit
  record will be lost. Bulk actions in the Verworfen view: *Restore
  selected* and *Delete selected permanently*. The trash-icon affordance
  on non-dismissed rows is swapped from red `actions-delete` to
  neutral `actions-close-alt` to signal the new "park" semantics
  rather than "destroy".
- **Controller actions renamed**: `deleteAction` →
  `dismissAction`; new `undismissAction` + `purgeAction` for the
  Verworfen-only paths. Bulk: `bulkDeleteAll` → `bulkDismissAll`,
  `bulkDeleteSelected` → `bulkDismissSelected`; new
  `bulkUndismissSelected` + `bulkPurgeSelected`.
- **`DetectionListPresenter::STATE_DISMISSED = 'verworfen'`** wins
  over registry/library coverage in `deriveState()`. The matched
  service is still surfaced on dismissed rows so the sub-label keeps
  showing "Stripe" / "Google Analytics" and un-dismiss restores the
  row to the right underlying state.

### Changed (breaking, pre-1.0) — webhook schema v2

- **Webhook accepts schema v2 only** (batched detections). The
  receiver expects `{ schemaVersion: 2, detections: [...] }` and rejects
  v1 (single-`detection` shape) with HTTP 400. Tracks upstream
  `simplecmp@94170f5`. `MAX_BODY_BYTES` raised from 4 KB → 16 KB so a
  full batch fits.
- **Receiver surfaces `status:'known'` detections too**, not just
  unknown. Library-matched cookies now reach the BE detection table —
  state derivation renders them as **Erkannt** so admins can adopt
  them via Übernehmen. Resolves the visibility gap previously tracked
  in `library_detection_visibility_gap.md`.
- **`DetectionRepository::ingest` loops over `payload['detections']`**
  internally; per-detection rows still aggregate by
  `(source, kind, identifier)` via `occurrences`. The `payload` column
  now stores `{ envelope, detection }` (envelope = source / page /
  library, detection = the specific row's data) rather than the raw
  body.

### Changed (breaking, pre-1.0) — 3-table architecture

- **The registry is now admin-curated only.** Bulk classifier
  pre-fill is handled by reading the bundled
  `simplecmp/services-library` JSON files directly at lookup time, not
  by mirroring them into `tx_simplecmptypo3_service`. The two roles
  the registry used to conflate (classifier dictionary + banner
  surface) are now in separate places:
  - **Library** (`vendor/simplecmp/services-library/data/services/*.json`)
    — read-only reference, consulted by the new `ClassifierLookup`
    service.
  - **Registry** (`tx_simplecmptypo3_service`) — admin-curated services
    only. Every row appears on the FE banner.
  - **Detection log** (`tx_simplecmptypo3_detection`) — unchanged.
- **New `ClassifierLookup` service.** Wraps `ServiceRepository::lookup()`
  and `SimpleCMP\ServicesLibrary` in one call; returns the union of
  matches, deduplicated by `service_id` (admin's curated row wins on
  conflict). Used by the Service-DB middleware so any cookie covered
  by either source classifies as `known`.
- **`fe_visible` column dropped.** The flag was a workaround for the
  registry's dual role; with the split the workaround is unnecessary.
  Every registry row is on the banner by definition; admin removes a
  service from the banner by deleting (or unadopting) the row.
- **`simplecmp:import-known-trackers` command removed.** The bundled
  library is consulted directly; bulk-mirroring into the registry no
  longer makes sense. Existing installs need to truncate
  `tx_simplecmptypo3_service` once to drop the stale library copies.

### Changed

- **BE "Dienste" tab repurposed as "Bibliothek" (library browser).**
  Lists the bundled library JSON entries (369 today) with
  `available` / `adopted` / `all` filter, search, and per-row
  *Übernehmen* (adopt → copy into registry) / *Aus Registry
  entfernen* (unadopt → delete from registry) actions. The catalog
  is no longer a view over `tx_simplecmptypo3_service` rows.
- `ServiceRepository`: simplified — `setVisibility()`,
  `findAllVisibleOnFe()`, `findAllForCatalog()` and the
  `feVisibleOnInsert` parameter on `upsert()` are gone. Added
  `delete(serviceId)` for the unadopt flow.
- `DetectionReviewController::approveAction` is now a simple
  `upsert()` — no per-flag visibility steps needed.

### Removed (breaking, pre-1.0)

- **`simplecmp:seed` command and the 10 bundled service JSON files
  (`Resources/Private/Seeds/services/`) are removed.** Bulk-importing
  ten "essentials" pre-populated the registry but conflicted with the
  rule that **`fe_visible = 1` only happens via explicit per-entry
  admin approval**. The path forward for any install is now:
  `simplecmp:import-known-trackers` pre-fills the classifier (rows
  land `fe_visible = 0`); the admin then promotes individual services
  to the banner via Übernehmen / Anpassen on a real detection, or via
  the BE *Dienste* (catalog) tab. No site needs the 10-essentials list
  separately — those services are all covered by the broader
  `simplecmp/services-library` import.

### Added

- **BE service catalog tab.** New "Dienste" tab inside the existing
  SimpleCMP module (sibling of "Detektionen"), backed by
  `ServiceCatalogController`. Lists every row in
  `tx_simplecmptypo3_service` with: visibility badge (Sichtbar /
  Verborgen), service id, name, vendor, purposes, and per-row actions.
  Filter dropdown (Alle / Sichtbar / Verborgen, default Alle), search
  across service_id / name / vendor / matchers (PHP-side substring,
  auto-submit on blur or Enter), pagination (25 / 50 / 100 / 500 per
  page). Two actions per row:
  - **Auf Banner zeigen / Vom Banner ausblenden** — one-click toggle
    via the new `ServiceRepository::setVisibility(id, bool)` method.
  - **Dienst bearbeiten** — opens the standard TCA edit form for full
    edits (vendor, matchers, purposes, etc.).
  Filter/search state is preserved across action redirects. New shared
  partial `Resources/Private/Partials/ModuleNav.html` renders the tab
  nav above both list templates.

### Changed

- **Module label**: "SimpleCMP-Detektionen" → "SimpleCMP" (the tabs
  now make the sub-scope clear).
- **Repository API**: `ServiceRepository::markVisibleOnFe(id)` →
  `setVisibility(id, bool)`. Old name removed (internal-only API, no
  consumers outside this extension).
- **Pagination.js** also handles `<input data-list-filter="…">` for
  text search (in addition to the existing `<select>` support); fires
  on `change` (blur or Enter).

### Changed

- **TCA default for `tx_simplecmptypo3_service.fe_visible` flipped
  from `0` to `1`.** Applies to new records created via the TCA edit
  form (Anpassen / Kuratieren flows on a detection row). The admin
  reviewing the pre-filled form and clicking Save is the per-entry
  approval — the saved service should land on the visitor's banner
  without forcing the admin to also flip the visibility toggle.
  Bulk paths (`import-known-trackers`) bypass the TCA default and
  set `fe_visible = 0` explicitly via the repository.

- **TCA: `purposes` and `description` are now required when saving via
  the BE form.** A service with no purposes can't render in the FE
  banner (the modal groups services by purpose; empty purposes →
  invisible to visitors), and missing descriptions made consent UI
  uninformative. Enforced via `minitems: 1` on the purposes select
  and `required: true` on the description text field. Bulk imports
  via `import-known-trackers` go through DBAL directly and bypass
  TCA validation — library entries with empty descriptions still
  land in the registry as classifier pre-fills.

### Changed (breaking, pre-1.0)

- **Service registry splits into classifier dictionary + banner surface.**
  New `tx_simplecmptypo3_service.fe_visible` column controls whether a
  service appears in the visitor's banner. Library imports
  (`simplecmp:import-known-trackers`) default to **hidden** (`0`); they
  pre-fill the classifier server-side so the recorder and Service-DB
  middleware can resolve cookies/origins without bloating the FE init
  config. The 10-essential `simplecmp:seed` entries default to visible
  (`1`). The Approve (Übernehmen) action on the BE detection table flips
  `fe_visible = 1` automatically, so the existing curation flow keeps
  working unchanged.

  **Why this matters:** before this change the FE config emitted every
  registered service, which pushed the consent payload past the 4 KB
  cookie limit once admins ran `import-known-trackers` and forced us to
  pin `storageMethod: 'localStorage'` as a v0.2.x workaround. With
  hidden library imports the FE config stays small (typically <10
  services per site) and the cookie storage backend is viable again —
  the workaround is removed.

  **Operator notes:**
  - All existing rows migrate to `fe_visible = 0` (the SQL default).
    Run `simplecmp:seed` after the schema migration to re-promote the
    essentials, then promote anything else via the BE service-edit form
    toggle ("Show on consent banner") or by clicking Approve on a
    matching detection.
  - Re-running `simplecmp:import-known-trackers --force` no longer
    demotes admin-promoted services — `fe_visible` is preserved on
    UPDATE.
  - The `storageMethod: 'localStorage'` pin is dropped from
    `RegisterAssets::buildFrontendConfig()`. The engine default (cookie)
    is back in effect; sites that explicitly want localStorage can set
    `simplecmp.storageMethod` in their Site Set.

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
