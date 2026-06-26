# Changelog

All notable changes to `simplecmp/t3-simplecmp` are recorded here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

This extension is **pre-1.0**. The API may shift between minor versions in
step with the upstream
[SimpleCMP](https://github.com/SimpleCMP/simplecmp) library's own pre-1.0
development.

## Unreleased

### Changed

- **Consent Mode v2 is now wired end-to-end for GA4 and GTM, and the
  `block` vs. `signal-gate` posture is a first-class per-tracker setting.**
  Previously each Google tracker was *both* load-blocked (`data-name=…`
  gating) *and* given a hand-rolled `gtag('consent', 'default', {…denied…})`
  — the ADR-0016 anti-pattern. With no matching `update (granted)` ever
  emitted on accept, GA4 silently stayed denied even after the visitor
  accepted (i.e. consent worked legally but measurement dropped to zero —
  the original `@todo` in `Ga4Provider` / `GtmProvider`). Now the providers
  expose **`consentPosture: block | signal-gate`** (default `block`,
  DACH-safest):
  - **`block`** keeps the load gate, drops the dangling consent default —
    no third-party traffic pre-consent and no suppress-after-consent bug.
  - **`signal-gate`** drops the load gate and lets the upstream engine's
    `consentMode` hook (REQ-N10 / ADR-0016, `simplecmp@5016b91`) own both
    the v2 `default (denied)` and the matching `update (granted)` on
    accept — plus the replay for returning visitors. The engine maps each
    service's `purposes` onto the gtag-consent buckets
    (`analytics → analytics_storage`, `marketing →
    ad_storage + ad_user_data + ad_personalization`) so `cmp.init({
    consentMode: true })` is the only thing the integration needs to
    forward. New `TrackerRuntimeState` (singleton) carries the
    "any-tracker-on-this-request wants consent mode" signal from
    `TrackerMaterializer` to `RegisterAssets`.

  Both flags must flip together — the providers enforce that
  `wantsLoadGate()` is the strict inverse of `wantsConsentMode()` per
  config, so the block-AND-signal-gate combination is structurally
  unrepresentable.

  The legacy `consentMode: bool` (GA4) / `consentDefault: bool` (GTM)
  config keys are **removed**. Their old behaviour (toggle the now-deleted
  hand-rolled default-deny) had no safe meaning post-fix. Pre-existing
  YAML / DB rows that carried `consentMode: true` continue to materialise
  as `block` posture — which is what they were *behaviourally* anyway
  (the load gate dominated; the `consent default` was inert pre-consent
  and silently broken post-consent). Sites that want the signal-gate
  posture must opt in explicitly via `consentPosture: signal-gate` in
  YAML or the Tracker-Setup BE wizard (the boolean checkbox is now a
  two-radio posture choice with the trade-off documented inline).

  Closes #6.

### Security

- **Theme Designer `color-*` tokens are now grammar-validated before
  storage.** `ThemeDesignerController::sanitizeTokens()` enum-guarded the
  structural tokens (`position`, `theme`, `layout`, …) but accepted every
  `color-*` value as any non-empty string. `RegisterAssets::injectTheme()`
  then concatenated that value **raw, unescaped** into shadow-DOM CSS at
  three sinks (the shared `:host { … }` rule, the per-trigger
  `:host(simplecmp-trigger) button { background: … }` rule, and the
  per-banner-button `:host(simplecmp-banner) .cn-<button> { background: … }`
  rules). A stored value like `red !important; } :host { display: none } /*`
  broke out of its rule and let a backend user inject arbitrary CSS into
  the SimpleCMP shadow roots — e.g. `display:none` on `:host` hides the
  consent banner entirely (consent-UI defacement / clickjacking-grade
  restyle; CSS-level only — script execution is already closed by
  `JSON_HEX_TAG` on the inline payload). `colorPaletteLocked='1'` only
  guarded the 8 `SAFE_PALETTE` core keys; the four button-background
  overrides (`color-trigger-bg`, `color-accept-bg`, `color-decline-bg`,
  `color-configure-bg`) bypass the lock and were exploitable regardless.
  The new `isCssColor()` validator accepts hex (3/4/6/8 chars), `rgb()` /
  `rgba()` / `hsl()` / `hsla()` with a charset that excludes CSS
  metacharacters, and a small audited keyword safelist. Anything else is
  **dropped** (not escaped) so the per-token default / `SAFE_PALETTE` wins
  on the FE. Reported by Ilja Melnicenko; SimpleCMP/t3-simplecmp#5.

- **The discover-mode detection write now requires a signed token.** The
  HTML rewriter records declaratively-blocked embeds during a sweep (see
  Added), writing to the detection table — the only such path besides the
  HMAC-authed bridge webhook. It was gated only on `?simplecmp_discover=1`,
  which any anonymous visitor can set, so anyone could drive unauthenticated
  `INSERT`/`UPDATE`s (no injection — the data is the site's own static HTML —
  but a write-amplification / table-spam vector). The sweep now also carries
  a source-bound, expiring `simplecmp_discover_token` (a `BridgeNonceService`
  nonce minted by the backend `DiscoveryController`, which holds the bridge
  secret); the rewriter records only when it verifies against the site's
  detection source. Missing / forged / expired / wrong-source / no-secret →
  embeds are still blocked, nothing is written. Fails closed. New
  `DiscoverSource` centralises the source string so mint and verify can't
  drift.

- **Hardened the public `/api/simplecmp/v1/lookup` endpoint against
  abuse.** It's reachable unauthenticated (the frontend classifier hits
  it on a local miss), but had no rate limit, no batch cap, and fed the
  attacker-controlled cookie/origin straight into the curated regex
  matchers — so a flood could walk the full registry per item, drain the
  upstream daily budget, or trigger ReDoS. Now: a **separate, loose
  per-IP rate limit** (`simplecmp.serviceDbRateLimit`, default 5000/h —
  counted independently of the webhook limit, sized to stay invisible to
  real visitor traffic and tolerant of shared NAT IPs), a **batch cap**
  of 100 items per request (400 otherwise), and over-long
  (>512-char) cookie/origin strings are dropped before matching. The
  webhook limiter's behaviour is unchanged.

- **Discovery sitemap fetch is now constrained to the site's own hosts
  (SSRF fix).** The *Discover trackers* module fetched an admin-supplied
  `sitemapUrl` server-side — and recursed into sitemap-index `<loc>`s —
  with no restriction on the target host, so a backend user could point
  it at internal services or the cloud-metadata endpoint
  (`169.254.169.254`). `SitemapFetcher` now refuses any URL that isn't
  `http`/`https` on a host belonging to the selected Site (base +
  language hosts); it also rejects embedded credentials
  (`user:pass@host`) and protocol-relative URLs. The check runs on every
  server-side fetch, including recursed sub-sitemaps, and **fails closed**
  when the Site can't be resolved. Redirects are constrained to
  `http`/`https`. `verify => false` is retained for local self-signed
  certs but is now bounded by the host allowlist. Known residual: the
  allowlist is host-only (any port), and a pre-existing open redirect on
  the site's own host could still bounce a fetch — both narrow given the
  host constraint. New `SitemapFetcher::isFetchableUrl()` +
  `DiscoveryController::siteHosts()`.

### Added

- **Discover sweeps now surface declaratively-blocked embeds.** YouTube,
  Maps and similar embeds are neutralised to `about:blank` server-side by
  universal blocking, so they never run and the frontend recorder can't see
  them. The HTML rewriter knows exactly what it rewrote, so during a sweep
  (`?simplecmp_discover=1` + a valid token — see Security) it records those
  as detections too, de-duped per page by `(kind, identifier)`. Only fires
  when universal blocking is enabled (it can only record what it neutralised).

- **Purged detections re-surface on the frontend.** Hard-deleting a detection
  (the Verworfen-view purge) used to leave already-reporting browsers holding
  a cross-session dedup marker that suppressed the tracker for ~7 days, so it
  never came back even if still present on the site (the "accept-once → no
  re-detect" bug). A new per-source report-generation counter
  (`DetectionResetGeneration`, in `sys_registry`) is bumped on purge and
  injected into the frontend bridge config (`config.cmsBridge.reportGeneration`,
  keyed by `storageName`); the upstream bridge re-reports any detection whose
  cross-session marker predates the bump. Resurfaces on the visitor's next
  page load. Pairs with `simplecmp`'s `cmsBridge.reportGeneration`.

- **Off-host sitemaps via robots.txt discovery (no admin config).** So a
  site that legitimately serves its sitemap from another host (e.g. a
  CDN) still works under the new SSRF allowlist, the Discover module now
  reads the site's own `robots.txt` and trusts the hosts its `Sitemap:`
  directives name — the trust is anchored in a file the site itself
  serves, so a backend user can't point discovery elsewhere. robots.txt
  is fetched only from a site host, and declared URLs are filtered to
  public `http`/`https` on non-IP-literal hosts (so a tampered robots.txt
  still can't bless `169.254.x`/`10.x`/`::1` — without any DNS lookup).
  Declared sitemaps are also tried first during auto-detect. New
  `SitemapFetcher::robotsSitemapUrls()` / `parseRobots()` +
  `DiscoveryController::resolveAllowlist()`; 15 new unit tests cover the
  SSRF allowlist + robots policy.

### Fixed

- **Both compliance audits now evaluate the editor's pending DRAFT, not
  the published config.** The ThemeDesigner shows draft form values
  (`findBySiteDraft`) while a draft is open, but the inline compliance
  audit (`ComplianceCheckService`) read the *live* service registry /
  theme / overrides, and the "Live-FE-Audit" iframe rendered the *live*
  banner — so an editor saw findings about the older published state
  next to the draft they were editing. Now:
  - `ComplianceCheckService::audit(Site, preferDraft: true)` resolves
    services / theme / overrides per draft scope (global registry vs.
    per-site theme/overrides) with a live fallback when no draft exists.
    Site Settings stay live — they are not part of the draft workspace.
  - The live-FE-audit iframe is served the draft banner config: the BE
    mints a short-lived, HMAC-signed, **site-bound** preview token
    (`BridgeNonceService`, source `simplecmp-preview-<siteId>`) and the
    audit URL carries it as `?simplecmp_preview=…`. `RegisterAssets`
    verifies it before switching the service/theme/override reads to the
    draft. Fails closed: no/forged/expired token, or no bridge secret →
    the published config renders, exactly as before. Draft state never
    leaks to anonymous visitors. (Note: on sites that force a
    query-dropping language redirect at the document root, the token may
    not survive the redirect and the audit falls back to the live
    config.)

- **FE library-upstream client hardened against a slow/unreachable
  upstream.** `LibraryUpstreamClient::lookup()` makes a synchronous
  server-to-server call on a cache miss; if the upstream was down or slow
  it could hang each visitor's *distinct* unknown cookie for the full
  request timeout, and worse, a transient failure was cached as a 24h
  no-match — poisoning a real cookie's classification for a day. Now a
  failed call (network / timeout / non-2xx / malformed) opens a short
  circuit breaker (60 s) instead of writing a 24h negative row: while
  open, lookups skip the network and return "no match for now", and the
  breaker self-heals once upstream recovers. The bundled library keeps
  classifying known cookies throughout. Same anti-hang posture the BE
  `/v1/health` probe already has.

- **Bibliotheks-Upstream panel no longer cries "nicht erreichbar" for a
  merely-stale cache.** The list render is cache-only (never probes, so the
  tab can't hang on a slow upstream), but a cold/expired cache rendered
  identically to a real probe failure — and since the success cache was only
  30 min and nothing refreshed it, that false alarm showed on almost every
  visit. Now three states: *ok*, *down* (a probe actually failed recently,
  shown with the timestamp), and a neutral *stale* ("Status veraltet"); on
  *stale* a small `UpstreamProbe.js` fires the existing refresh in the
  background and reloads into the real state — no manual "Jetzt prüfen"
  click. Success-cache TTL raised 30 min → 24 h (drift changes rarely and a
  bundle upgrade invalidates instantly), so the auto-probe runs at most once
  a day. New `cachedFailureAt()` distinguishes down from cold.

- **Discover-recorded `<link>` detections use the correct kind.** The
  rewriter mapped rewritten `<link>` tags to kind `request`, but the frontend
  recorder (and the backend "Links" category) use `link`; since detections
  dedupe on `(source, kind, identifier)`, the same link seen both ways could
  split into two rows. Aligned to `link`.

- **Universal blocking no longer breaks third-party stylesheets or
  poisons SEO `<link>` tags.** The HTML rewriter used to rewrite *every*
  third-party `<link href>` to `about:blank` regardless of `rel` — with
  universal blocking on by default this silently dropped third-party-CDN
  stylesheets (Bootstrap / Font Awesome / Google Fonts → broken CSS with
  no recovery path) and clobbered cross-domain `rel="canonical"` /
  `rel="alternate"` (SEO damage). `<link>` rewriting is now gated to
  resource-hint rels only (`preconnect`, `dns-prefetch`, `preload`,
  `prefetch`, `modulepreload`, `prerender`) — the rels that open a
  pre-consent third-party connection, where neutralizing to `about:blank`
  is invisible. `stylesheet` / `canonical` / `alternate` / `icon` /
  `manifest` / unknown rels are left untouched (allowlist, not
  blocklist). Deliberate opt-in stylesheet blocking with consent
  re-injection is tracked as a follow-up — see
  `docs/decisions/2026-05-30-link-rewrite-rel-policy.md` for the
  rationale, competitor survey, and caveats (the correct fix for
  third-party fonts is self-hosting).

### Changed

- **Skip wasted upstream `/lookup` calls when the bundled library is in
  sync with upstream.** `LibraryUpstreamClient::lookup()` now consults
  `LibraryUpstreamHealth::cachedInSync()` after the local cache check;
  when bundle and upstream report identical `dataHash`, upstream
  *cannot* return any match the bundled tier didn't already see, so
  the call is provably wasted. Short-circuits with no network traffic,
  no stats increment, and no negative-cache write. Cache-only probe
  → degrades to today's behavior when the health cache is cold (fresh
  deploy, BE Bibliothek tab never opened).
- **Re-classify unknowns** now warms the health cache up-front and
  bails with an explanatory flash (`list.reclassify.flash.bundleInSync`)
  when bundle is in sync — admin sees "no new matches possible" with
  a `composer update` hint instead of a no-op summary.

### Added

- **`ext_conf_template.txt`** establishes the first extension-
  configuration field in this ext: `libraryUpstreamSkipWhenInSync`
  (default ON). Reachable at Settings → Extension Configuration →
  t3_simplecmp. Flip OFF to force upstream calls regardless of bundle
  sync state — debug-only, the optimization is provably safe
  otherwise.
- `LibraryUpstreamHealth::cachedInSync(?string $url, string $bundleDataHash)`
  — cache-only sync probe. Returns true iff the cached snapshot was
  captured against the current bundle hash AND reports a non-null
  upstream `dataHash` equal to the bundle's. Five guard cases
  covered by tests.
- `BundledLibraryInfo::dataHash()` is now memoized per-request — the
  underlying `ServicesLibrary::dataHash()` walks 368 JSON files and
  must not run on every visitor lookup.

## 0.6.0 — 2026-05-28

Backend polish + library-upstream feedback loop closes. Builds on
v0.5.0's `simplecmp/services-library` consultation: the BE now
*shows* the bundled-vs-upstream state (freshness panel), labels
its two upstream traffic sources distinctly (Bundle & Snapshot vs
Laufzeit-Abfragen, side-by-side on wider viewports), and surfaces
the full service surface on demand via a per-row info modal.

Hard requirement: `simplecmp/services-library` ≥ `v0.3.1`
(introduces `ServicesLibrary::dataHash()` — the freshness drift
signal and the info modal both depend on it).

### Added

- **Per-service info modal** on the Bibliothek and Dienste tabs.
  Every row gets a small ⓘ icon button next to its actions; clicking
  it opens a TYPO3 `Modal.advanced` with the full service surface:
  locale-resolved description (reads `i18n.description.<lang>` from
  the bundled JSON against the BE user's UI language), ID, vendor
  + country, privacy-policy link, purposes as badges, cookie /
  origin matchers, and the four optional `vendor*` L2 fields
  (address, opt-out URL, partners, vendor description) when present.
  The Dienste tab additionally folds the bundled library entry on
  top of the DB row for `Aus-Bibliothek` services, so they surface
  the i18n + `vendor*` data the registry DB doesn't store.
- **Sub-section labels and two-column layout** on the Bibliotheks-
  Upstream panel. "Bundle & Snapshot" (drift-probe driven) sits
  beside "Laufzeit-Abfragen" (runtime classifier counters, with
  tooltip clarifying the distinction). The two sub-sections are
  side-by-side on ≥768px viewports and collapse to a single column
  on narrower BE windows.
- **Bibliotheks-Upstream freshness panel** on the Bibliothek tab now
  shows the bundled library version (from Composer) alongside the live
  upstream snapshot from `/v1/health` (service count, source commit
  SHA, last sync time). A drift badge compares the bundled and upstream
  `dataHash` (sha256 over the service JSON files): ✓ "Auf dem Stand"
  when equal, ⚠ "Updates verfügbar" when they differ. Content-only
  comparison — README/CI/docs commits on the upstream library repo
  don't trigger drift signals. When upstream signals drift, an inline
  `composer update simplecmp/services-library` hint appears. A "Jetzt
  prüfen" button flushes the 30-minute cache for an on-demand re-probe.
  New cache backend `t3_simplecmp_library_upstream_health` is
  registered automatically; run `database:updateschema` on upgrade so
  the cache tables get created
  (`cache_t3_simplecmp_library_upstream_health` and its `_tags`
  companion).
- New services: `LibraryUpstreamHealth` (cached `/v1/health` probe
  with bundle-dataHash-aware invalidation), `BundledLibraryInfo` (thin
  wrapper over Composer's `InstalledVersions` + the library's own
  `dataHash()` method). Both covered by unit tests.

### Requires

- `simplecmp/services-library` ≥ the commit introducing
  `ServicesLibrary::dataHash()` and a reference-server emitting
  `dataHash` on `/v1/health`. Pre-dataHash upstreams degrade to a
  ⚠ "Updates verfügbar" state (the comparison can't establish
  equality without both sides).

## 0.5.0 — 2026-05-27

REQ-19 (L2 Provider-Informationen modal) Phase C lands the ext side
end-to-end: the new bundle ships the modal + per-instance attribute
overrides, the dep bump brings in 32 curated providers, and
`RegisterAssets` forwards the disclosure fields into the FE
`libraryFallback` payload. Plus the ADR-0014 Phase A work
(upstream services-library consultation) that had been sitting in
Unreleased.

### Added — REQ-19 Phase C: provider disclosure forwarding

- `RegisterAssets::buildLibraryFallback()` now forwards seven new
  optional `vendor*` fields from each services-library entry into
  the FE `libraryFallback` payload: `vendor`, `vendorCountry`,
  `vendorAddress`, `vendorOptOutUrl`, `vendorPartner`,
  `vendorDescription`, `privacyPolicyUrl`. The FE
  `<simplecmp-provider-info-modal>` (upstream
  `SimpleCMP/simplecmp@afcc5d1`, v0.3.0) renders them on click of
  the new "Weitere Informationen ›" link in the blocked-embed
  placeholder. State-2 services (library-known but not in admin
  registry) now produce a DSGVO-correct L2 disclosure surface
  matching the layered-disclosure pattern accepted by German DPAs.
- Two new unit tests in `RegisterAssetsTest`:
  `libraryFallbackForwardsVendorFieldsFromCuratedEntries` (asserts
  all 7 forwarded fields on `linkedin-insight`) and
  `libraryFallbackOmitsVendorFieldsForUncuratedEntries` (guards
  against null/empty leakage for entries with only purposes).
- `LIBRARY_FALLBACK_RAW_BUDGET_BYTES` bumped 50 KB → 100 KB raw.
  Current payload after Phase A.3 curation rolls in: 65 KB raw /
  9.5 KB gzipped (368 entries, 32 with full provider data). Still
  well below the extra-roundtrip threshold.

### Changed

- **`simplecmp/services-library` dep bumped `^0.1` → `^0.3`.**
  Brings in:
  - Four new optional `vendor*` schema fields validated by
    `ServicesLibraryTest` (Provider-Informationen fields).
  - 32 services curated with full provider data across 10 vendors
    (Google Ireland / Microsoft Ireland / Adobe Systems Software
    Ireland / Meta Platforms Ireland / TikTok Technology Ireland /
    Twitter International / Vimeo / LinkedIn Ireland / Pinterest
    Europe / Stripe Payments Europe).
  - `bin/migrate-apex-origins.php` migration + apex-domain
    wildcard rewrite for ~140 OCD-derived services.
  - `placeholderTitle` / `placeholderDescription` fields with
    curated copy for 15 high-value embeds.
  - The `curate-service-provider` Claude Code skill at
    `services-library/.claude/skills/`.

### Fixed

- **`LibraryWalkDeterminismTest::realLibraryWalkIsAlphabeticalBySourceFile`
  corrected** to match the iterator's actual behavior: `glob()`
  returns sorted-by-filename, where `-` (0x2D) < `.` (0x2E), so
  `akamai-botmanager.json` sorts before `akamai.json` even though
  by id `akamai` sorts before `akamai-botmanager`. The test was
  previously coincidentally passing because the v0.1.0 services-
  library subset shipped no prefix-pair IDs.

### Added — upstream services-library consultation (ADR-0014 Phase A)

- New Site Set field `simplecmp.libraryUpstreamUrl`. When set,
  `ClassifierLookup` consults the canonical hosted services-library
  endpoint (typically `https://library.simplecmp.eu/v1`) as a third
  tier when the local registry and the bundled
  `simplecmp/services-library` JSON both miss. Visitor IPs never
  reach the upstream — only this server's PHP queries it
  (server-to-server). Default empty for safety; admins opt in
  per-site by setting the URL.
- New `Classes/Service/LibraryUpstreamClient.php` — wraps TYPO3's
  `RequestFactory`, 3-second timeout, silent fallback to negative
  cache on any error (network, non-2xx, malformed JSON; warning
  logged to TYPO3 log).
- New `Classes/Domain/Repository/LibraryCacheRepository.php` +
  `tx_t3simplecmp_library_cache` table. 24h TTL for positive AND
  negative responses. Negative caching is essential — without it,
  unknown cookies would hit upstream forever. Run
  `vendor/bin/typo3 database:updateschema` to apply on upgrade.
- `Classes/Service/ClassifierLookup.php` gains an optional third
  tier consulted only when local tiers miss (no extra latency when
  the bundled library already covers the query).
- `Classes/Middleware/ServiceDbApi.php` resolves the setting from
  the request's Site, scanning all host-matching sites to skip
  TYPO3's auto-generated orphan sites (caught via live debugging:
  dev14's autogenerated-329 site shares the host with the real
  default site).

Live-verified against `https://library.simplecmp.eu/v1`: cold
lookup ~600ms, repeat lookup (cache hit) ~80ms, negative cache
correctly suppresses repeat upstream calls.

## 0.4.1 — 2026-05-26

### Changed (breaking) — universal blocking ON by default

- **`simplecmp.universalBlocking.enabled` default flipped `false` → `true`**
  (Sven's `93a4c9c`). Sites that haven't explicitly set the toggle now
  get pre-consent blocking active out of the box — the GDPR-aligned
  posture for first install. Admins can still turn it off via the Site
  Set. Follow-up `ad14a94` syncs the description string in
  `settings.definitions.yaml` ("On by default — turn off only if your
  site is fully self-hosted and embeds nothing third-party") and the
  PHP-layer fallback in `RegisterAssets::buildInitConfig`. The
  HtmlRewriter middleware intentionally keeps the no-default form
  (`$settings->get(...)` without a fallback) because it doubles as the
  "is SimpleCMP active on this site" guard for non-SimpleCMP sites
  whose responses the middleware also processes.

### Fixed — `$get` helper swallowed explicit `false`/`0`/`''`

- **`?:` → `??` in the settings reader.** The `$get` helper in
  `RegisterAssets::buildInitConfig` used `?:` (truthy fallback)
  instead of `??` (null coalesce), so an admin's explicit `false`
  (e.g. for `respectGPC` or `universalBlocking.enabled`) silently
  got replaced with the declared default. Hidden by matching
  defaults until the universal-blocking flip turned it visible.
  Fixed in `ad14a94`. Failing test renamed
  `interceptRuntimeAbsentWhenUniversalBlockingUnset`
  → `interceptRuntimeDefaultsOnWhenUniversalBlockingUnset`.

### Added — automated upstream bundle sync (Phase 1)

- **New CI workflow `.github/workflows/sync-bundle.yml`** (`7f348ea`,
  `c0cc336`, `68e2f5d`) listens for `repository_dispatch: bundle-sync`
  from `SimpleCMP/simplecmp` when upstream CI passes on main.
  Rebuilds the bundle from the dispatched SHA, runs Phase 1 gates
  (bundle integrity + PHPUnit unit + functional), and either
  auto-pushes to main (all green, github-actions[bot] as author) or
  opens a failure PR labelled `needs-triage` with reviewers
  `ille216,svewap` for human triage. Replaces the manual
  `pnpm build:sync-typo3` flow for routine syncs (the hand-sync still
  works as a fallback). End-to-end validated via manual
  workflow_dispatch (`a0170ed` was the first auto-sync commit);
  auto-dispatch path proven once `gnftqj9htp0g` cleared. Phase 2
  (Playwright BE + FE smoke in CI) is open.

### Added — `libraryFallback` carries per-service purposes to FE

- **`RegisterAssets` emits a `libraryFallback` map** in the JS init
  config when `simplecmp.universalBlocking.enabled` is on. Keyed by
  library service id (matches `data-name` on rewritten elements),
  each entry currently carries `{ purposes: [...] }` sourced from
  `SimpleCMP\ServicesLibrary\ServicesLibrary::services()`. The FE
  contextual-notice's state-2 render mode (library-known but not in
  `config.services`) reads this to surface the "Zwecke: …" line
  under the description — visitor sees WHY they'd be loading the
  content without the library getting shipped to FE in full.
  Payload cost: ~1-2 KB gzipped over the init JSON, paid only on
  pages with universal blocking active.

### Added — `data-blocked-source` attribute drives three-state FE notice

- **`HtmlRewriter` now emits `data-blocked-source="library"` or
  `data-blocked-source="host"`** on every element it rewrites. The FE
  contextual-notice component reads this to pick its render mode:
  - `library` → state 2 — visitor sees only the "Ja" (accept-once)
    button (no Immer/Settings because the service isn't in
    `config.services`).
  - `host` → state 3 — informational-only notice, no consent buttons
    (visitor has no basis to grant informed consent to an unknown
    vendor, admin contact is the only path).
  See `simplecmp` CHANGELOG for the FE side of the wiring.
- **`HostMatcher::resolve()`** — new method returning
  `['service' => string, 'source' => 'library'|'host']` so callers can
  drive the FE state. `match()` is preserved as a thin wrapper for
  the existing test surface that asserts on a plain string return.

### Added — Universal pre-consent blocking (Phase 1, ADR-0013)

- **New PSR-15 frontend middleware
  `SimpleCMP\T3SimpleCmp\UniversalBlocking\Middleware\HtmlRewriter`.**
  When enabled, scans the rendered HTML response for third-party
  `<script src>`, `<iframe src>`, `<img src>`, and `<link href>`
  references and rewrites them into the SimpleCMP engine's gate
  shape (`data-name + data-src + src="about:blank"`) before the
  response is flushed. The engine's existing handling takes it from
  there — consent granted swaps the real `src` back in, consent
  denied auto-inserts the click-to-enable placeholder. Hosts are
  recognised via the bundled `simplecmp/services-library` origin
  matchers, so the rewriter and the recorder agree on what counts as
  third-party.
- **Per-Site-Set toggle.** Two new settings on the `SimpleCmp` Site
  Set:
  - `simplecmp.universalBlocking.enabled` (bool, default `false`) —
    master switch.
  - `simplecmp.universalBlocking.allowlist` (stringlist, default
    `[]`) — admin-curated hosts that should pass through (exact host
    or `*.example.com` wildcard). The site's own host is allowlisted
    automatically.
- **`Server-Timing: rewriter;dur=NN;desc="scanned=N,rewritten=N"`**
  header emitted on every response the rewriter touches, so DevTools
  / synthetic monitors can read the cost without page instrumentation.
- **Escape hatches.** Elements that already carry `data-name`
  (integrator-marked) are skipped untouched; elements with
  `data-no-rewrite` opt out entirely.
- Phase 0 perf numbers carried over: ~5 ms p50 on the worst-case page
  (30 third-party iframes) measured on a clean dev14 install, well
  under ADR-0013's <30 ms typical / <80 ms worst-case budget.

### Added — Universal pre-consent blocking (Phase 2 wiring, ADR-0013)

- **`simplecmp.universalBlocking.enabled` now activates both blocking
  layers from a single toggle.** Previously the setting flipped only
  the server-side `HtmlRewriter` middleware; the FE bundle's runtime
  monkey-patches (upstream `interceptRuntime` option, shipped in
  SimpleCMP commit `6134463`) had no TYPO3 wiring, so JS-injected
  scripts / iframes / pixels stayed unblocked when the toggle was on.
  `RegisterAssets::buildInitConfig()` now emits `interceptRuntime: true`
  into the JSON init payload when the setting is on. Server-side
  catches declarative tags; runtime patches catch JS-injected calls.
  No new setting — the existing toggle is the single source of truth.

### Changed — Universal blocking now blocks ALL third-party (not just library-known)

- **`simplecmp.universalBlocking.enabled` semantics widened.** When on,
  both Phase 1 (server-side `HtmlRewriter`) and Phase 2 (FE runtime
  patches) now gate ANY non-same-origin host — not just hosts in the
  bundled `simplecmp/services-library`. Hosts the library recognises
  keep their canonical service id (`youtube`, `stripe`, …); unknowns
  fall back to the host itself as a synthetic service id. The intent:
  when an admin opts into universal blocking they're committing to
  the strict CCM19-style posture — broken-until-curated is the
  intentional default, admin Kuratieren unknowns from the BE
  detection log as usual.
- **Phase 2 plumbing.** `RegisterAssets::buildInitConfig()` now emits
  `interceptRuntime: { universalBlock: true, sameOriginHosts: [...allowlist] }`
  instead of plain `interceptRuntime: true`. The
  `simplecmp.universalBlocking.allowlist` Site Set flows through to
  the FE as additional same-origin hosts; `window.location.host` is
  added implicitly by the runtime patches so admins can't accidentally
  strip own-host protection.
- **Phase 1 plumbing.** `HostMatcher` gains a `bool $blockAllThirdParty`
  constructor flag (default `true`). When set, unknown hosts return
  the host itself as the synthetic service id, so the `HtmlRewriter`
  rewrites them too. Pass `false` for the legacy library-only narrow
  behaviour.
- **Closes the asymmetric-coverage gap** documented in
  ADR-0013 Phase 2: Phase 1 was already library-wide; Phase 2 was
  narrowed to configured services to avoid silently-broken embeds.
  With the toggle as an explicit opt-in for universal protection,
  that trade-off no longer applies.

### Changed — Bundle + init now emit in `<head>` (priority asset)

- **The SimpleCMP bundle and the inline `SimpleCMP.init(...)` call
  switched from end-of-`<body>` to head-priority via `['priority'
  => true]` on the AssetCollector.** Universal pre-consent blocking
  (Phase 2 runtime patches) needs the patches installed BEFORE any
  inline body script can dispatch third-party requests. Previously
  the bundle landed after body content, so GTM-style inline IIFEs
  did their `document.createElement('script').src = '...'` work
  before the prototype patches ever installed. Combined with the
  upstream change shipping the body-aware `init()` (SimpleCMP commit
  `90b46c4`), patches install in the DOM-free phase and the
  banner/modal mount is deferred to `DOMContentLoaded` automatically.
- Theme injection stays at end-of-body — its MutationObserver
  attaches to `document.body`, which only exists once parsing has
  progressed past `<head>`.

### Added — Click-to-enable on blocked embeds

- **Library placeholder copy flows through to the FE banner.** Adopting
  a service from the Bibliothek tab now carries the optional
  `placeholderTitle` / `placeholderDescription` fields from the
  bundled `simplecmp/services-library` entry through to the JS init
  config. The upstream SimpleCMP engine reads them as service
  properties and uses them in the auto-inserted contextual notice
  next to blocked third-party embeds. Curated copy ships for 15
  high-value embeds (YouTube, Vimeo, Maps, Spotify, etc.) — admins
  get the right placeholder text without writing any.
- **New columns** `placeholder_title` (varchar) +
  `placeholder_description` (text) on `tx_t3simplecmp_service`.
  No TCA fields — deliberately deferred (the fallback chain produces
  reasonable defaults; admins who want override-per-site can use the
  description field). When library curators add good copy, every
  adopted site benefits with zero admin work.
- `ServiceRepository::upsert()` writes the new fields on adoption;
  `rowToProtocol()` reads them; `RegisterAssets::buildRuntimeServices()`
  passes them through to the FE init config.
- Bundle sync from upstream ships the engine-side auto-placeholder
  insertion + the `<simplecmp-contextual-notice>` custom-element
  registration that was previously test-only. The
  `simplecmp:configure` event now opens the modal regardless of which
  widget emits it (the click-to-enable notice's *Open settings*
  button now works alongside the banner's).

### Added — Discover trackers (sitemap sweep in the admin's browser)

- **New BE action *Tracker entdecken*** linked from the Detektionen
  list toolbar (visible when the bridge is configured). Opens a
  dedicated discovery page that walks a list of FE URLs in a hidden
  iframe inside the admin's own tab. Each iframe load gets
  `?simplecmp_discover=1` appended; the FE recorder + bridge inside
  fire exactly as for a real visitor, so the existing webhook + ingest
  pipeline populates `tx_t3simplecmp_detection` with no new
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
  whose Site Set list includes `simplecmp/t3-simplecmp` are eligible.
- **Editable URL list (textarea, one per line, `#` comments
  ignored).** Pre-filled from the sitemap when EXT:seo is installed,
  but always present as a fallback for sites without a working sitemap
  (or for ad-hoc one-page checks). Counter live-updates as the admin
  edits.
- **`Discovery.js` walker** drives the iframe sequentially with a
  morphing Start / Stop / Continue button. Stop pauses after the
  current URL completes; Continue resumes from the next one. The
  log records the estimated wall-clock duration on Start and the
  updated remaining time on Continue.
- **State persistence** in localStorage (`simplecmp-discover-state:
  <site>`, capped at 200 log entries FIFO). Saved on every visit /
  Start / Stop / Done / Refetch / textarea input. Restored in
  `initialize()` — paused runs come back as Continue after a BE
  reload, with the full log replayed. Per-site keying so switching
  the picker swaps state cleanly.
- **Reset button** (`btn-outline-secondary`, refresh icon) — clears
  localStorage + log + snapshot, re-enables the textarea / site
  picker / refetch, triggers a fresh sitemap fetch. Disabled while
  running.
- **Refetch implicitly clears any paused snapshot** so a fresh URL
  list never coexists with a stale Continue button. The new
  "Sitemap X → N URLs" line replaces the abandoned run's log.
  Paused state explicitly re-enables the Refetch button so the
  admin has a way to escape Continue without going through Reset.
- **i18n strings** for the EN + DE locallang files. 22 new units
  covering title, intro, controls (Start / Stop / Continue / Reset
  / Refetch), progress, log lines (including the ETA template
  *"Geschätzte Zeit bis alle URL's durchgelaufen sind: {eta}"*),
  and the fallback alert.

### Added — Dienste tab (registry index, source-tagged)

- **New BE tab "Dienste"** between Detektionen and Bibliothek. Lists
  every row in `tx_t3simplecmp_service` regardless of origin, with
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
  `tx_t3simplecmp_service.library_adopted_at` column compared
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
- **Upgrade wizard** `t3SimplecmpBackfillLibraryAdoptedAt`
  back-fills `library_adopted_at = NOW()` for rows that pre-date the
  column whose `service_id` is in the currently-bundled library.
  Idempotent; runs once via Install Tool's Upgrade module or
  `vendor/bin/typo3 upgrade:run t3SimplecmpBackfillLibraryAdoptedAt`.
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
  `tx_t3simplecmp_detection.dismissed_at` timestamp rather than
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
  by mirroring them into `tx_t3simplecmp_service`. The two roles
  the registry used to conflate (classifier dictionary + banner
  surface) are now in separate places:
  - **Library** (`vendor/simplecmp/services-library/data/services/*.json`)
    — read-only reference, consulted by the new `ClassifierLookup`
    service.
  - **Registry** (`tx_t3simplecmp_service`) — admin-curated services
    only. Every row appears on the FE banner.
  - **Detection log** (`tx_t3simplecmp_detection`) — unchanged.
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
  `tx_t3simplecmp_service` once to drop the stale library copies.

### Changed

- **BE "Dienste" tab repurposed as "Bibliothek" (library browser).**
  Lists the bundled library JSON entries (369 today) with
  `available` / `adopted` / `all` filter, search, and per-row
  *Übernehmen* (adopt → copy into registry) / *Aus Registry
  entfernen* (unadopt → delete from registry) actions. The catalog
  is no longer a view over `tx_t3simplecmp_service` rows.
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
  `tx_t3simplecmp_service` with: visibility badge (Sichtbar /
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

- **TCA default for `tx_t3simplecmp_service.fe_visible` flipped
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
  New `tx_t3simplecmp_service.fe_visible` column controls whether a
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
  JSON textarea on `tx_t3simplecmp_service.purposes` with a
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
  only). Tokens persist in a new `tx_t3simplecmp_theme` table —
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

- **`tx_t3simplecmp_detection.reviewed` column dropped.** Run the
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
  `tx_t3simplecmp_detection`. Repeat hits of the same
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
