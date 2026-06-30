# SimpleCMP for TYPO3

TYPO3 v14 integration for [SimpleCMP](https://github.com/SimpleCMP/simplecmp) — the
open-source consent manager with development-time tracker auto-detection, a shared
service database, and optional CMS-bridge webhook alerts.

This extension is **pre-1.0** and tracks SimpleCMP's own pre-release status. APIs
will change.

![Detection triage view](Documentation/Images/be-list-default.png)

*The detection triage view, default filter. The four-state model
(curated / recognised / unknown / dismissed) surfaces what the admin
should actually do next; dismissed rows are filed under the Verworfen
filter and excluded from this default actionable view.*

## What it does

- **Frontend integration** — embeds the SimpleCMP JS bundle on every TYPO3
  frontend page, sourcing its `init({...})` config from the active Site
  Set's settings. The service registry (`tx_t3simplecmp_service`)
  drives the runtime services array and a per-language `translations`
  block.

- **Service-DB endpoint** at `/api/simplecmp/v1/{health,services,lookup}` —
  implements the upstream
  [Service-DB protocol](https://github.com/SimpleCMP/simplecmp/blob/main/docs/service-db-protocol.md).
  Classifier coverage comes from two sources unioned at lookup time:
  the admin-curated registry (`tx_t3simplecmp_service`) plus the
  bundled [`simplecmp/services-library`](https://github.com/SimpleCMP/services-library)
  composer package (Hotjar, Stripe, Intercom, TikTok Pixel, hCaptcha,
  Mailchimp, and hundreds more — read-only reference, no DB mirror).
  Admin adopts a library entry into the registry via the BE *Bibliothek*
  tab or by Übernehmen on a real detection; only adopted entries appear
  on the visitor's banner.

- **CMS-bridge receiver** at `/api/simplecmp/webhook` — accepts the
  HMAC-signed POSTs the frontend bridge emits when the recorder catches a
  cookie or origin neither the local classifier nor the Service-DB
  endpoint recognises. Idempotent: repeat hits of the same
  `(source, kind, identifier)` triple bump `occurrences` instead of
  inserting duplicates.

- **BE detection module** at *Websites → SimpleCMP* — three tabs and a
  four-state model. Tabs:

  - *Detektionen* — observation log of trackers visitors triggered.
  - *Dienste* — full registry index, source-tagged (Eigene / Aus
    Bibliothek / Verwaist). The Dienste tab is where every registry row
    lives regardless of how it got there.
  - *Bibliothek* — browse the bundled `simplecmp/services-library`,
    Übernehmen entries on demand or **in bulk**, unadopt, and act on
    recommendations. A freshness panel reports whether the bundled
    snapshot matches the hosted upstream (`library.simplecmp.eu`), with
    ok / stale / down health states and a per-request circuit breaker.
    Every row has a ⓘ info modal (locale-resolved description, matchers,
    L2 vendor fields).

  Per-row detection state, derived at view time from **registry
  coverage + bundled library coverage + dismissal flag**:

  | State | Meaning | Action |
  |---|---|---|
  | **Curated** | Registry already covers this cookie/origin | *Edit service*, *Dismiss* |
  | **Recognised** | Library recognises the pattern but the local registry doesn't | *Approve* (silent insert after confirmation modal) **or** *Customise* (curate with library pre-fill), *Dismiss* |
  | **Unknown** | Neither registry nor library matches | *Curate*, *Dismiss* |
  | **Dismissed** | Admin parked the row via *Verwerfen* — `dismissed_at` set, persists across visitors so a fresh browser can't resurrect it | *Restore*, *Delete permanently* (confirmation modal) |

  The Dismissed bucket is the only path that hides a row without
  curating it, but it's auditable: the row stays in the table, the
  Verworfen filter surfaces them, and Restore is one click away. No
  silent dismissal.

  Dienste tab signals when the bundled library drops or renames a
  service the admin previously adopted — a new **Verwaist** badge,
  orange callout at the list level, plus an inline alert at the top of
  the TCA edit form pointing the admin at the Bibliothek tab to find a
  possible renamed replacement.

- **Discover trackers** — sitemap sweep that walks a list of FE URLs in
  a hidden iframe inside the admin's own browser, so the detection
  table populates without waiting for organic visitor traffic.
  Reachable from a *Tracker entdecken* button on the Detektionen list
  toolbar. Each iframe load gets `?simplecmp_discover=1` appended, which
  the upstream bridge honours by suspending cross-session dedup, DNT,
  and sampling for that page load only — visitor traffic is unaffected.
  Pre-fills URLs from `<baseUrl>/sitemap.xml` when EXT:seo is installed
  (auto-detect tries each language base for multilingual sites); an
  editable textarea (one URL per line, `#` comments ignored) is the
  fallback for sites without a sitemap. The single morphing
  Start / Stop / Continue button makes the run interruptible — Stop
  pauses after the current URL, Continue resumes from the next one.
  Discovery state (snapshot, currentIndex, log) persists in
  localStorage per site, so a paused run survives a BE reload. A
  Reset button clears state and re-fetches the sitemap; the activity
  log shows the estimated total time on Start and the updated
  remaining time on Continue. No Node, no headless browser, no
  production-server changes — uses the browser the admin already has
  open.

- **Multisite support** — one TYPO3 install can serve as the central
  triage point for several frontend sites. The *Reporting site* column
  tags each detection with the Site Set that reported it; the filter
  dropdown lets admins slice by site.

- **Theme Designer** at *Websites → SimpleCMP Theme Designer* — per-site
  editor for the FE consent banner. Pick a CSS-framework preset
  (default / Bootstrap 5 / Tailwind / Bulma / Pico), set brand + surface
  colors, choose the banner position on a 3×3 grid, toggle the address
  tone (Sie / Du), and override individual UI strings (persisted in
  `tx_t3simplecmp_translation_override`) — without editing YAML or PHP. A
  live preview iframe updates as you type, and a built-in **compliance
  audit** runs the engine's DSGVO / WCAG checks against the previewed
  banner. Theme tokens persist in `tx_t3simplecmp_theme` per site;
  deleting a row resets that site to defaults.

- **Managed trackers + multi-vendor Consent Mode (ADR-0017)** — a
  *Tracker setup* area registers GA4 / GTM / Matomo / Meta Pixel /
  Microsoft UET tags (stored in `tx_t3simplecmp_managed_tracker`,
  providers `Ga4Provider` / `GtmProvider` / `MatomoProvider` /
  `MetaProvider` / `MicrosoftUetProvider`) and wires them to consent,
  emitting the engine's vendor-aware Consent Mode signals (`gtag('consent',
  …)` for Google, `fbq('consent', …)` for Meta,
  `uetq.push('consent', …)` for Microsoft UET) plus a CSP policy
  contribution. Meta Pixel is signal-only — the customer's own pixel
  template keeps loading `fbevents.js`; this extension only dispatches
  the consent transitions. The consent-*update* wiring on returning-
  visitor replay is in progress (tracked upstream).

- **Region-aware behaviour (REQ-N4)** — Site Set settings
  `simplecmp.region` / `simplecmp.regionHeader` select an opt-in (GDPR),
  opt-out (US / CCPA), or no-banner regime per visitor region.

- **Click-to-enable on blocked embeds** — when a content editor pastes
  a third-party embed with the standard `data-name="<service>"
  data-src="..."` pattern (YouTube, Spotify, Vimeo, Maps, etc.), the
  upstream SimpleCMP engine auto-inserts a small placeholder card
  next to the blocked iframe with *Show once*, *Always show*, and
  *Open settings* buttons. Adopted library services carry their
  curated `placeholderDescription` automatically — admins don't have
  to write per-service copy unless they want to override the bundled
  text. Two new optional columns on `tx_t3simplecmp_service`
  (`placeholder_title`, `placeholder_description`) store any
  overrides the adoption flow brings in from
  [`simplecmp/services-library`](https://github.com/SimpleCMP/services-library).

## Screenshots

### The three row states

| Recognised — library knows it | Unknown — nobody knows it | Curated — already in registry |
|---|---|---|
| ![Recognised](Documentation/Images/be-list-state-erkannt.png) | ![Unknown](Documentation/Images/be-list-state-unbekannt.png) | ![Curated](Documentation/Images/be-list-state-kuratiert.png) |

*(Screenshots from a German-locale TYPO3 backend — labels read
*Erkannt* / *Unbekannt* / *Kuratiert*; English-locale shows *Recognised*
/ *Unknown* / *Curated*.)*

### The Approve confirmation modal

Three sections so the admin sees exactly what they're approving before
the registry gets the entry — frontend-facing data (purposes with
descriptions, privacy URL, a faithful preview of the FE service-toggle),
raw data (the JSON that will land in the registry, link to the library
source on GitHub), and impact (count of existing detections that will be
resolved):

![Approve modal](Documentation/Images/be-modal-uebernehmen.png)

### Multisite triage

Detections from multiple Site Sets in one list, with the *Reporting
site* column showing which frontend reported each row:

![Multisite list](Documentation/Images/be-list-multisite.png)

Filter to a single Site Set:

![Reporting-site filter](Documentation/Images/be-filter-reporting-site.png)

### Theme Designer

Per-site banner editor at *Websites → SimpleCMP Theme Designer*: a
CSS-framework preset, brand / surface colors, 3×3 position, tone
(Sie / Du), and per-string text overrides on the left, with a live
preview and a built-in compliance audit on the right. *(Screenshot may
predate the current controls.)*

![Theme Designer module](Documentation/Images/be-banner-design.png)

### Frontend

| Consent banner | Configuration modal |
|---|---|
| ![Banner](Documentation/Images/fe-banner.png) | ![Modal](Documentation/Images/fe-modal.png) |

## Installation

```bash
composer require simplecmp/t3-simplecmp
```

In Site → Site Sets, add the **SimpleCMP — consent manager** set as a
dependency. Configure under Site → Settings.

The registry (`tx_t3simplecmp_service`) starts empty and only ever
holds admin-curated entries. The bundled
[`simplecmp/services-library`](https://github.com/SimpleCMP/services-library)
is consulted at classifier-lookup time directly (no DB mirror), so
common third-party cookies classify as `known` from day one without
any setup. To put a specific service on the visitor's banner the
admin **adopts** it — either via the BE *Bibliothek* tab (browse the
library, click Übernehmen on any entry) or by waiting until the
recorder catches its cookie on the FE and clicking *Übernehmen /
Anpassen* in the *Detektionen* tab.

## Configuring the bridge webhook

Required when `cmsBridgeUrl` is set in your Site Set settings. Two ways
to bootstrap a secret:

- **CLI:** `vendor/bin/typo3 simplecmp:generate-bridge-secret` prints a
  fresh value plus a paste-ready configuration snippet. Recommended for
  production (env-var interpolation).
- **BE module:** the SimpleCMP detection list surfaces a *Generate
  bridge secret* button when no secret is configured. The button writes
  the value to `config/system/settings.php` for you.

One secret per TYPO3 installation. If you run multiple installs and one
POSTs bridge webhooks to another, configure the **same** value on both
ends.

## Bridge / Service-DB ordering

The recorder emits a `detectionSettled` event once any async
classification (local + Service-DB lookup) has finished, and the bridge
subscribes to that event rather than the initial `detection`. So a
well-known tracker that the Service-DB resolves to `known` produces
exactly one webhook row, with `status: 'known'` and a `matchedService`
hint — never a duplicate (one `unknown` followed by an upgrade) the
old behaviour had.

Webhook payloads use schema v2: batched `detections[]` arrays,
client-side batching (1.5 s debounce), cross-session dedup
(`localStorage` marker keyed by `(source, kind, identifier)` with 7-day
TTL), DNT opt-in / opt-out, and `navigator.sendBeacon` flushing on
`pagehide`. The receiver dedupes by `(source, kind, identifier)` triple
into a single row whose `occurrences` and `last_seen` bump on repeat
hits.

## Blocking third-party stylesheets (Google Fonts)

Universal blocking gates third-party `<script>` / `<iframe>` / `<img>`
out of the box, but it leaves `<link rel="stylesheet">` alone by
default. Third-party CSS — most commonly **Google Fonts** loaded from
`fonts.googleapis.com` — sends the visitor's IP to the third party
before any consent, which DACH courts have treated as a violation
(LG München I, 3 O 17493/20). This is opt-in because, unlike a blocked
script, a blocked stylesheet fails *visibly* (unstyled text) with no
per-visitor recovery for unknown hosts. See
[the decision record](docs/decisions/2026-06-10-stylesheet-blocking-default-off.md)
for why it ships off.

> **The durable fix is self-hosting.** Download the font / CSS and serve
> it from your own domain — then no third-party request happens at all,
> and there is nothing to block or consent to. Blocking is a stopgap for
> CSS you can't (yet) self-host.

**Turning it on.** Set `simplecmp.universalBlocking.blockStylesheets: true`
in the site's settings (Site module → *Einrichtung* → *Settings*; requires
`universalBlocking.enabled` too). The `HtmlRewriter` then moves a blocked
stylesheet's `href` into `data-href` so the browser never fetches it
pre-consent; the engine reinjects it (and re-blocks on withdrawal) exactly
like a gated script. While the toggle is off, the SimpleCMP module shows a
**first-run nudge** with a one-click deep-link into each affected site's
settings.

**Reviewing what got blocked.** Run *Tracker entdecken* (Discover) with the
toggle on — it sweeps your pages in the admin's browser and records every
blocked stylesheet as a `stylesheet`-kind detection. For each one you can
either self-host it, or click **Stylesheet erlauben** to allow that host's
CSS through. The allow is **stylesheet-scoped**: scripts and iframes from
the same host stay gated (deliberately narrower than the host-wide
`universalBlocking.allowlist`, which passes every resource type).

**Best-effort, not "now compliant".** The browser's preload scanner can
fetch a stylesheet before the rewriter intervenes, `@import url(...)` inside
a stylesheet escapes entirely, and dynamically-inserted `<link>`s aren't
hooked. Treat blocking as risk reduction; self-hosting is the only complete
fix.

## Bundle preload (ADR-0019)

`RegisterAssets` emits a `<link rel="preload" as="script">` in the page
head for whichever SimpleCMP bundle is registered (full or slim). The
browser starts fetching the script in parallel with HTML parsing, so
the regular `<script>` tag downstream resolves to a cached fetch
instead of starting a new one. Free LCP win for any bundle.

On by default. Turn off via the Site Setting `simplecmp.preloadBundle`
in the unusual case where the site has a global preload-quota
constraint.

> Future iteration: the upstream `dist/simplecmp-core.js` ESM
> split-chunk artifact (with `simplecmp-chunk.js` / `simplecmp-deferred.js`
> companions) is shipped by the bundle's `tsup` config but is not yet
> consumed by t3-simplecmp. Wiring it would require swapping the IIFE
> `<script>` for an inline `<script type="module">` that imports `init`,
> plus `modulepreload` hints for both the entry and the shared chunk.
> Tracked upstream as ADR-0019; tracked here as a follow-up to the
> "Slim bundle (ADR-0018)" workflow once the matching sync infrastructure
> lands.

## Slim bundle (ADR-0018)

Opt into the English-only **slim bundle** + per-language pack injection
to save ~15–20 KB gzip on every page load. The full bundle ships all
26 locales and is the default for back-compat.

### Activating

Two ingredients are required:

1. `EXT:simplecmp/Resources/Public/JavaScript/simplecmp.core.global.js`
   — the slim English-only IIFE artifact built by the upstream bundle
   (`tsup` entry `simplecmp.core`).
2. `EXT:simplecmp/Resources/Public/JavaScript/translations/<lang>.json`
   for each active site language — copied verbatim from the upstream
   `src/engine/translations/`.

With both files present, flip the Site Setting:

```yaml
# config/sites/<id>/settings.yaml
simplecmp:
  useSlimBundle: true
```

`RegisterAssets` will then:

- register `simplecmp.core.global.js` instead of `simplecmp.global.js`
- read the active site language's ISO code via
  `$request->getAttribute('language')->getTwoLetterIsoCode()`
- read the matching `translations/<isoCode>.json`, parse it, and merge
  it into `config.translations[<isoCode>]` (per-site Designer
  overrides keep winning — the editor's wording survives the swap)

### Fallback behaviour

| Condition | Result |
| --- | --- |
| `useSlimBundle = false` (default) | Full bundle loaded, no change |
| `useSlimBundle = true`, slim file missing | Warns, falls back to the full bundle (no broken page) |
| `useSlimBundle = true`, slim file present, pack missing for active lang | Warns, loads slim bundle, FE renders in English |
| `useSlimBundle = true`, slim file + pack present | Slim bundle + injected pack, full localized FE |

### Verification

After enabling, hard-reload the page and check:

- DevTools Network: `simplecmp.core.global.js` (not `simplecmp.global.js`).
- Source response: `<script>SimpleCMP.init({...,"translations":{"de":{...}}})</script>` — the active language pack should be inlined in the init payload.
- The banner renders in the visitor's language exactly as before.
- File size diff: roughly 60-80 KB raw / 15-20 KB gzip lighter than the full bundle.

## Betrieb mit StaticFileCache (REQ-N9)

`t3-simplecmp` ist mit `EXT:staticfilecache` (`lochmueller/staticfilecache`) und
ähnlichen Full-Page-HTML-Caches (Varnish, Cloudflare Cache Rules) kompatibel.
Zwei Vorkehrungen greifen automatisch:

### Universal Blocking läuft vor dem Cache

`Classes/UniversalBlocking/Middleware/HtmlRewriter.php` gated Drittanbieter-Tags
im HTML-Body. Die Middleware-Registrierung in `Configuration/RequestMiddlewares.php`
trägt `before: lochmueller/staticfilecache/persist` — der Cache speichert daher
das **bereits geblockte** HTML. Ohne den Constraint würde SFC das ungeblockte
HTML auf Disk schreiben und es bei jedem Cache-Hit ausliefern → Pre-Consent-
Tracking → Compliance-Bruch.

**Verifikation** (mit aktivem SFC):

```bash
# Cache eine Seite mit einem YouTube-Embed-CE, dann den Cache-File prüfen:
grep -l 'youtube\.com/embed' typo3temp/var/cache/.../<host>/<path>/index.html
# Erwartung: kein Treffer im `src=`-Attribut, nur in `data-simplecmp-src`.

grep -c 'src="about:blank"' typo3temp/var/cache/.../<host>/<path>/index.html
# Erwartung: ≥ 1 (jeder gegatete iframe → about:blank).
```

Falls SFC eine andere Middleware-ID als `lochmueller/staticfilecache/persist`
nutzt (z. B. Fork oder Drittparty-Extension), die ID in der `before:`-Liste
ergänzen. TYPO3 ignoriert unbekannte `before:`-Targets stillschweigend, der
Constraint ist also non-destructive.

### Bridge-Nonce — Auto-Refresh-on-401

Der HMAC-Nonce in `cmsBridgeAuth.token` hat eine TTL von **1 Stunde** und wird
gemeinsam mit dem HTML gecached. Bei einem Cache-Hit nach Ablauf würde jeder
Bridge-POST → 401 → Detection-Berichte hören still auf.

Lösung: `RegisterAssets` setzt zusätzlich `cmsBridgeAuth.refreshUrl` auf
`/api/simplecmp/v1/bridge-nonce?source=<derived>`. Beim ersten 401 holt sich
das Bundle dort einen frischen Nonce, swappt ihn in-memory und retried den
POST genau einmal. Concurrent-Batch-Guard + `retried`-Flag verhindern
Stampedes und Loops.

Der Endpoint wird von der vorhandenen `ServiceDbApi`-Middleware bedient — sie
läuft `before: typo3/cms-frontend/site` und liefert `Cache-Control: no-store`
per Default, ist also **nie** vom Full-Page-Cache erfasst.

**Verifikation**:

```bash
# Manuell den Endpoint testen (charset-validiertes source):
curl -i 'https://example.com/api/simplecmp/v1/bridge-nonce?source=simplecmp-default'
# Erwartung: 200, JSON `{token: "...", expiresInMs: 3600000}`,
#            Header `Cache-Control: no-store`.

# Invalide source:
curl -i 'https://example.com/api/simplecmp/v1/bridge-nonce?source=BAD%20%21'
# Erwartung: 400, JSON `{error: "invalid_source"}`.

# Bridge-Secret nicht konfiguriert:
curl -i 'https://example.com/api/simplecmp/v1/bridge-nonce?source=simplecmp-default'
# Erwartung: 503, JSON `{error: "bridge_secret_unconfigured"}`.
```

**End-to-End** mit gecached'er Seite: Cache eine Seite mit `simplecmp.cmsBridgeUrl`,
warte > 1 Stunde (oder simuliere via Server-Zeit / kurzer TTL), öffne die
gecached'e Seite und füge per JS ein neues Embed ein. Browser-DevTools-Network
zeigt:

1. `POST /api/simplecmp/webhook` → **401**
2. `GET /api/simplecmp/v1/bridge-nonce?source=...` → **200**
3. `POST /api/simplecmp/webhook` (retry mit neuem Token) → **200**
4. BE-Modul `simplecmp_detections` → Detection ist drin.

### Was nicht funktioniert

- TYPO3-Backend (`/typo3/...`) liegt außerhalb von SFC — keine Auswirkung.
- `/api/simplecmp/*`-Routen werden via Middleware-Order **vor** `typo3/cms-frontend/site`
  ausgeliefert und niemals gecached.
- Der finale `navigator.sendBeacon`-Flush bei `pagehide` kann den Refresh nicht
  abwarten — Detections der letzten Sekunde mit abgelaufenem Token gehen verloren.
  Akzeptierte Restschwäche (die ganze in-session Mehrheit ist abgedeckt).

## Audit & Nachweis (Phase 1)

`t3-simplecmp` schreibt bei jeder Editor-Änderung an Dienste, Theme-Tokens
oder Übersetzungs-Overrides einen **vollständigen Snapshot** der aufgelösten
Banner-Konfiguration nach `tx_t3simplecmp_config_snapshot` — append-only,
sha256-dedupliziert. Damit lässt sich später nachweisen, wie der Banner zu
einem bestimmten Zeitpunkt aussah (DSGVO Art. 7 Abs. 1).

### Was im Snapshot drin ist

- Komplette Dienste-Registry (Protokoll-Shape via `ServiceRepository::findAll()`)
- Banner-Theme-Tokens für die Site
- Translation-Overrides + Tone-Wahl je Sprache
- Kuratierte `simplecmp.*` Site-Settings:
  `enabled`, `storageName`, `privacyPolicyUrl`, `imprintUrl`,
  `floatingTriggerLabel`, `respectGPC`, `regimeDefault`,
  `universalBlocking.enabled`, `universalBlocking.blockStylesheets`,
  `universalBlocking.allowlist`, `libraryUpstreamUrl`, `trackers`

Bewusst NICHT im Snapshot: per-request-Schalter (`regionHeader`) und
ops-Tuning (`bridgeRateLimit`, `useSlimBundle`, …) — diese ändern den
sichtbaren Banner nicht.

### BE-Modul: Tab „Revision & Nachweis"

Im Modul *Cookie-Manager* (BE-ID `simplecmp_detections`) gibt es einen
neuen Tab. Liste pro Site, paginiert; Detail-View mit komplettem
canonical-JSON + Line-Diff zum direkt vorherigen Snapshot derselben Site.

### YAML-Edits sind nicht auto-gesnapshotted

Wer `config/sites/<id>/settings.yaml` direkt editiert (Git-Pull, Ops-Deploy),
muss anschließend manuell auslösen:

```bash
ddev exec vendor/bin/typo3 simplecmp:snapshot-config --site=default
# oder --all-sites
```

Idempotent — gleiche kanonische Inhalte → gleicher Hash → kein neuer Row.

### Append-only Enforcement

- TCA: `readOnly: true` + `hideTable: true` — kein Form, kein List-Modul-Eintrag
- DataHandler-Hooks: UPDATE/DELETE/MOVE über die BE-API werden mit
  FlashMessage abgelehnt
- **Direkt-SQL ist nicht geblockt** — Production-Retention ist eine
  bewusste Phase-3-CLI-Aktion (kommt mit dedicatem Audit-Log über sich
  selbst), kein versteckter DB-Trigger.

### Was Phase 2/3 ergänzt

- **Phase 2**: Visitor-Decision-Logging — Tabelle `tx_t3simplecmp_consent_log`
  mit Hash-pseudonymisiertem Visitor + Foreign-Key auf die hier
  geschriebenen Snapshots, neuer FE-Endpoint, Bundle-Hook in
  `consentManager.onChange()`.
- **Phase 3** (geliefert): DSGVO-Auskunfts-Workflow + Retention-CLI + Export.

## Auskunft, Retention & Export (Phase 3)

### DSGVO Art. 15 — Auskunfts-Tab

Neuer Tab **„Auskunft"** im Cookie-Manager-Modul. Admin-vermittelt:
der Besucher liefert seine raw UUID (aus seinem Browser-localStorage
unter `simplecmp-<site>-visitor-uuid`), der Admin gibt sie im
Auskunfts-Formular ein, der Server pseudonymisiert sie mit dem
Bridge-Secret und zeigt:

- alle Entscheidungs-Zeilen dieses Besuchers auf der gewählten Site,
- alle Snapshots, gegen die diese Entscheidungen getroffen wurden
  (kanonisches JSON aufklappbar),
- Download-Buttons für JSON und CSV.

Die raw UUID landet **nirgendwo serverseitig** — weder in URL noch in
FlashMessage noch in DB-Log. Form ist POST.

### Retention-CLI

```bash
ddev exec vendor/bin/typo3 simplecmp:audit-retention \
    --target=consent-log|config-snapshot|all \
    --keep-days=1095 \
    --site=default \
    --reason="DSGVO Art. 5 (1) (e) — 3 Jahre Aufbewahrungspflicht" \
    --i-know-what-i-do
```

Pflicht-Flags und Schutz-Defaults:

- `--target` (Enum — beinhaltet bewusst NICHT das Self-Audit-Log)
- `--keep-days` (unter 90 verlangt `--allow-aggressive-retention`)
- `--reason` (mindestens 30 Zeichen, landet wörtlich im Self-Audit-Log)
- `--i-know-what-i-do` (Tripwire, sonst Abort)
- `--dry-run` (Count + Self-Audit-Log mit `dry_run=1`, kein DELETE)

**Self-Audit-Log:** Jeder Aufruf — inklusive Dry-Run, inklusive
Validation-Pass — schreibt eine Zeile in `tx_t3simplecmp_audit_retention_log`
mit `rows_deleted`, `keep_days`, `oldest_kept_crdate`, `invoked_by`,
`invocation_reason`, `dry_run`. INSERT-BEFORE-DELETE-Ordering: ein
Crash mitten in der DELETE-Phase hinterlässt einen „wir haben es
versucht"-Eintrag, der sichtbar bleibt.

### Export-CLI

```bash
# Snapshot + alle Decisions, die ihn referenzieren
ddev exec vendor/bin/typo3 simplecmp:export-audit \
    --site=default --snapshot=2537f80136… --format=json

# Visitor-zentrische Auskunft (CLI-Pfad — Server-Variante des BE-Buttons)
ddev exec vendor/bin/typo3 simplecmp:export-audit \
    --site=default --visitor=e8400000-1234-4abc-9def-1234567890ab --format=json

# Date-Range Export (z. B. monatliche Rohdaten für Anwalt / Wirtschaftsprüfer)
ddev exec vendor/bin/typo3 simplecmp:export-audit \
    --site=default --since=2026-01-01 --format=csv --output=/tmp/q1-2026.csv
```

JSON-Bundle-Shape (`schemaVersion: 1`):

```jsonc
{
  "schemaVersion": 1,
  "exportedAt": "2026-06-16T20:00:00+00:00",
  "exportedBy": "cli" | "be:<userId>",
  "filter": { "kind": "visitor", "site": "default", "visitorHash": "…" },
  "snapshots": [{ "uid": …, "versionHash": "…", "canonical": {…}, … }],
  "decisions": [{ "uid": …, "versionHash": "…", "decisions": {…}, … }]
}
```

CSV-Format: zwei Sektionen (`# SECTION: snapshots` / `# SECTION: decisions`)
mit UTF-8-BOM, RFC-4180-quoting, `crdate_iso`-Spalte zusätzlich zur
Epoch-Spalte. Excel-import-ready.

### Was Phase 3 explizit NICHT macht

- Kein **Visitor-Self-Service-Portal** — die UUID darf nicht via URL
  / Server-Logs fließen. Admin-vermittelt ist DSGVO-rechtlich sauber.
- Kein **Retention-Scheduler-Task** — Retention bleibt manuell-invoked.
  Auto-Run wäre eine versteckte rechtliche Entscheidung; CLI mit
  Reason-Pflicht zwingt zur dokumentierten Entscheidung.
- Keine **Verschlüsselung at rest** — Phase-1+2 OOS bleibt. Pseudonymisierung
  deckt den DSGVO-Schutzbedarf für Audit-Datensätze ab.
- Keine **inkrementellen Exporte** (delta seit X) — Export-Bundles sind
  full-bundle pro Filter. Wenn Sites mit > 100k Decisions auftauchen,
  ist Inkrement das nächste Feature.

## Status

Iterations shipped:

1. Frontend bundle integration + Site Set settings wiring.
2. Service-DB endpoint with the protocol-conformant routes.
3. CMS-bridge receiver + HMAC nonce auth (`simplecmp:generate-bridge-secret`).
4. BE detection module with mark-reviewed / bulk-delete / convert-to-service.
5. Three-state model with library-aware approve flow + multisite support.
6. Banner Design module with per-site theming + live preview.
7. **3-table architecture** — registry / library JSON / detection log
   cleanly separated; `ClassifierLookup` unions registry + library at
   lookup time so library coverage is automatic without a DB mirror.
8. **Webhook schema v2** — batched detections, status:'known' detections
   reach the BE so library matches surface as *Erkannt*, bandwidth
   bounded by client-side batching + cross-session dedup + DNT respect.
9. **Four-state model** — *Verworfen* (dismiss) added on top of
   curated/recognised/unknown. Dismissal is durable across visitors
   (`dismissed_at` column), auditable, and reversible.
10. **Dienste tab** — full registry index, source-tagged
    (*Eigene* / *Aus Bibliothek* / *Verwaist*). Surfaces library
    drift: a previously-adopted service the bundled library no longer
    contains is flagged as Verwaist with an orange callout + inline
    alert in the TCA edit form.
11. **Universal pre-consent blocking** (off by default). The
    `HtmlRewriter` frontend middleware rewrites every third-party
    `<script src>` / `<iframe src>` / `<img src>` / `<link href>` to
    the engine's `data-name + data-src + src="about:blank"` gate
    shape before the response is flushed — no integrator markup
    required. Toggle on per Site Set via
    `simplecmp.universalBlocking.enabled`; exempt vendor CDNs and
    your own infrastructure via the
    `simplecmp.universalBlocking.allowlist` stringlist
    (`cdn.example.com` or `*.example.com` wildcards). Recognises hosts
    via the bundled `simplecmp/services-library`; emits a
    `Server-Timing: rewriter` header so cost is visible per request.
    See [ADR-0013](https://github.com/SimpleCMP/simplecmp/blob/main/docs/adr/0013-universal-blocking-implementation-plan.md)
    for design.
12. **Upstream library consultation (ADR-0014 Phase A).** New Site
    Set field `simplecmp.libraryUpstreamUrl` (default
    `https://library.simplecmp.eu/v1`); when set, `ClassifierLookup`
    consults the canonical hosted services-library as a third tier
    after the local registry and the bundled JSON both miss. Visitor
    IPs never reach the upstream — only this server's PHP queries it
    server-to-server. 24h positive + negative cache;
    `simplecmp.libraryUpstreamDailyBudget` caps daily calls.
13. **REQ-19 L2 Provider-Informationen modal (v0.5.0).** The FE
    contextual-notice gets a "Weitere Informationen ›" link that
    opens a modal disclosing the recipient legal entity, full postal
    address, country, privacy policy URL, opt-out URL, partner /
    joint-controllers, and provider description — sourced from the
    services-library v0.3.0 curated entries. `RegisterAssets`
    forwards the data into the FE `libraryFallback` payload for
    library-known-but-not-adopted services; for adopted services
    the registry stores the same fields (new columns
    `vendor_address` / `vendor_opt_out_url` / `vendor_partner` /
    `vendor_description`; admin-editable via TCA). Run
    `vendor/bin/typo3 database:updateschema` on upgrade. Matches the
    German-market accepted three-layer-disclosure pattern.
14. **Opt-in third-party stylesheet blocking (REQ-N8).** New Site Set
    field `simplecmp.universalBlocking.blockStylesheets` (default
    **off**) extends the rewriter to gate third-party
    `<link rel="stylesheet">` (e.g. Google Fonts) behind consent —
    `href` moved to `data-href`, reinjected by the engine on consent.
    The BE surfaces blocked stylesheets as a distinct `stylesheet` kind
    with a **stylesheet-scoped per-host allow** (new
    `tx_t3simplecmp_allowed_stylesheet_host` table — run
    `database:updateschema` on upgrade) that, unlike the host-wide
    allowlist, still gates scripts/iframes from that host. A first-run
    nudge points admins of blocking-enabled sites that aren't yet
    gating CSS at the toggle + Discover, leading with self-hosting as
    the durable fix. Best-effort (preload scanner / `@import` escape).
    See [Blocking third-party stylesheets](#blocking-third-party-stylesheets-google-fonts).
15. **Theme Designer rework** — the banner-design module gained a
    CSS-framework picker, 3×3 position, Sie/Du tone toggle, per-string
    text overrides (`tx_t3simplecmp_translation_override`), and a
    compliance-audit runner; typography / shape / detect-fonts dropped.
16. **Managed trackers + Consent Mode v2** — *Tracker setup* for
    GA4 / GTM / Matomo (`tx_t3simplecmp_managed_tracker`) with Google
    Consent Mode v2 emission and a CSP policy mutator (consent-update
    wiring in progress).
17. **Region-aware regimes (REQ-N4)** — per-region opt-in / opt-out /
    none via `simplecmp.region` / `simplecmp.regionHeader`.
18. **Library browser depth** — bulk adopt / unadopt, recommendations,
    *Detektionen rückgängig*, a per-service ⓘ info modal, and an upstream
    freshness / health panel (`tx_t3simplecmp_library_cache`) with a
    frontend circuit breaker.
19. **Multi-vendor Consent Mode (ADR-0017)** — Meta Pixel and Microsoft
    UET tracker providers. `RegisterAssets` now emits the
    `consentMode: { vendors: [...] }` shape when any non-Google vendor
    is registered; the bundle's vendor-adapter registry dispatches
    `fbq('consent', …)` / `uetq.push('consent', …)` alongside the
    existing `gtag('consent', …)`.
20. **StaticFileCache compatibility (REQ-N9)** — `before:
    lochmueller/staticfilecache/persist` on the universal-blocking
    rewriter so SFC stores the gated HTML, plus a new uncached
    `GET /api/simplecmp/v1/bridge-nonce` endpoint paired with the
    bundle's `cmsBridgeAuth.refreshUrl` so the embedded nonce can
    refresh after the cached HTML outlives its TTL.
21. **Audit-Snapshot Phase 1** — append-only `tx_t3simplecmp_config_snapshot`
    table, DataHandler-hook serialises the full resolved banner
    configuration on every editor save, sha256-deduplicated; CLI
    command `simplecmp:snapshot-config` for YAML-only edits; new
    "Revision & Nachweis" tab in the detections module with
    canonical-JSON view + line-diff against the previous snapshot.

## YAML als Vorschlag, Editor übernimmt (Phase 5)

Phase 5 schließt das Loch, das Phase 4 offen ließ: YAML-Site-
Settings konnten still per Dev-Deploy banner-relevante Werte
ändern (z.B. `privacyPolicyUrl`, `simplecmp.trackers`), ohne im
Editor-Publish-Audit aufzutauchen. Lösung: YAML wird zum
**Dev-Vorschlag**; Editor übernimmt explizit im BE und der
Übernehmen-Akt landet im Snapshot.

### Architektur

```
YAML (Git, deployed)  →  Vorschlag des Devs
       ↓ "Übernehmen" durch Editor im BE
DB-Layer (active_settings) →  was Besucher tatsächlich sehen
       ↓ wird Teil des Audit-Snapshots
```

Zentrale Komponenten:

- **`tx_t3simplecmp_active_settings`** — eine Row pro Site,
  JSON-Blob der editor-übernommenen Banner-Content-Settings.
- **`EffectiveSettingsResolver`** — single source of truth für
  jeden Settings-Read. Editor-Content-Keys: DB-active > YAML.
  Ops-Keys: YAML direkt. Eingebauter per-Request-Cache.
- **Neuer BE-Tab „Einstellungen"** mit Diff-View, Tracker-
  Vorschlägen, Bootstrap-Banner für Erst-Übernahme.

### Editor-vs-Ops-Scope

Hardcoded in `EffectiveSettingsResolver::EDITOR_CONTENT_KEYS` —
12 Keys, alle Banner-Inhalt mit DSGVO-Relevanz. Beispiele:

```
✓ Editor-content (durchläuft Vorschlag/Übernehmen):
  simplecmp.enabled, .storageName, .privacyPolicyUrl, .imprintUrl,
  .floatingTriggerLabel, .respectGPC, .regimeDefault,
  .hideDeclineAll, .universalBlocking.enabled, .blockStylesheets,
  .allowlist, .trackers (Sonderfall — als Tracker-Vorschläge)

✗ Ops (YAML-direkt, kein Confirm):
  simplecmp.regionHeader, .serviceDbUrl, .cmsBridgeUrl,
  .consentLogUrl, .consentLogRateLimit, .storagePid,
  .bridgeRateLimit, .serviceDbRateLimit, .libraryUpstreamUrl,
  .libraryUpstreamDailyBudget, .useSlimBundle, .preloadBundle
```

Bridge-Secret oder Rate-Limits via Editor-Confirm zu schicken
wäre absurd — das sind Server-Identity/Tuning, kein Banner-Inhalt.

### YAML-Tracker als Vorschläge

`simplecmp.trackers` wird in Phase 5 NICHT mehr auto-materialisiert.
`TrackerMaterializer`-EventListener liest nur noch
`tx_t3simplecmp_managed_tracker` (BE-Wizard-eigene). YAML-Tracker
erscheinen im Settings-Tab als „Vorschlag: matomo (siteId 99) —
[Anlegen]". Klick erzeugt eine `managed_tracker_draft`-Row für
diese Site und läuft danach durch den Phase-4-Publish-Workflow.

**Breaking-Change**: bestehende YAML-`simplecmp.trackers`-Configs
sind nach Update auf Phase 5 plötzlich „inaktiv" — sie laufen
erst nach explizitem Editor-Klick im Settings-Tab live. Migration:
für jede Site einmal in den Settings-Tab gehen, Tracker
übernehmen, im Tracker-Setup-Tab veröffentlichen.

### Snapshot-Schema-Bump v3 → v4

`canonical_json` enthält jetzt einen `activeSettings`-Block neben
den 5 DB-editierbaren Tabellen. Pre-Phase-5-Snapshots bleiben mit
`schemaVersion: 3` als historische Einträge.

### Migration / Bootstrap

Neuer Install: `active_settings` ist leer. Resolver fällt für alle
Editor-Keys auf YAML zurück. FE-Verhalten identisch zu vor Phase 5.
Beim ersten Besuch des Settings-Tabs sieht der Editor ein Bootstrap-
Banner und klickt einmal „Aus YAML übernehmen" — danach ist die
Drift-Detection aktiv.

## Draft/Publish Workspace (Phase 4)

Phase 4 reworks the editor flow: rather than each save going live
immediately (with a snapshot per keystroke), edits now land in
draft mirror tables. The editor reviews their pending state, then
clicks **Veröffentlichen** to atomically promote draft → live
and trigger a single deliberate snapshot with
`trigger_event='publish'`.

### Architecture

Per banner-config table (`service`, `theme`, `translation_override`,
`managed_tracker`, `allowed_stylesheet_host`) there is now a
`*_draft` mirror table with the same columns plus three workspace
bookkeeping columns:

  - `draft_site` — `''` for the globally-shared service registry,
    site identifier otherwise
  - `draft_owner_be_user` — the editor currently working on the
    draft
  - `draft_modified_at` — last write within the draft (touch-style)

A new `tx_t3simplecmp_publish_lock` table tracks the
*scope-to-editor* assignment via a UNIQUE(`scope`) constraint: at
most one editor per scope can have a pending draft at any time.

Scopes are either:

  - `__global__` for the service registry (shared across sites)
  - a site identifier for per-site theme / overrides / trackers /
    hosts

### Editor workflow

1. Editor opens a SimpleCMP module tab → list shows live state.
2. First save / adopt / delete triggers
   `DraftWorkspaceService::initializeDraft($scope, $beUserId)`:
   acquires the lock and copies live → draft.
3. Subsequent edits hit the draft table only; FE bundle continues to
   read live (no visitor-visible change).
4. Editor clicks **Veröffentlichen** → `DraftPublishService::publish`
   runs an atomic transaction (DELETE live, INSERT FROM draft,
   DELETE draft) and fires a snapshot.
5. The editor can also **Discard** to throw the draft away, or
   **Take Over** if another editor holds the lock.

### CLI / direct-edit lockdown

Phase 4 makes live banner-config tables `readOnly` in TCA and
adds a DataHandler hook that refuses any UPDATE/DELETE via the
editor API on those tables. Editors must use the SimpleCMP module
tabs + the Publish flow. Direct SQL bypasses everything by design
(same trade-off as the audit tables); the audit log is the
operator-disciplined record.

### Snapshot schema bump

`tx_t3simplecmp_config_snapshot.canonical_json` now serializes
exactly the five DB-editable banner-config tables — services,
theme, translations, managedTrackers, allowedStylesheetHosts.
The `schemaVersion` field bumps from `1` to `3`.

`schemaVersion: 1` snapshots carried 3 tables + a YAML site-
settings subset. `schemaVersion: 3` drops the site-settings (incl.
the previously-included `simplecmp.trackers` YAML array) entirely:
YAML lives in `config/sites/<id>/settings.yaml` under Git
versioning and doesn't belong in the editor-publication audit
trail. Use `git log -- config/sites/` for YAML-state history.

Pre-Phase-4 snapshots stay in the audit trail untouched; the next
publish creates a `schemaVersion: 3` entry whose content reflects
the new shape.

### Files

| Layer | Files |
|---|---|
| Tables | 5 `*_draft` mirrors + `tx_t3simplecmp_publish_lock` |
| Services | `DraftWorkspaceService` (lock + copy-on-write), `DraftPublishService` (atomic promotion) |
| DTOs | `LockState`, `PublishResult` |
| Controller | `PublishController` (publish/discard/takeover actions) |
| TCA | `tx_t3simplecmp_publish_lock` + `tx_t3simplecmp_service_draft` (live `service.php` now `readOnly+hideTable`) |
| Hook | `EnforceLiveBannerConfigReadOnly` (refuses direct edits to live tables) |
| Tests | `DraftWorkspaceServiceLockTest` (9 cases) + `LockStateTest` (4 cases) + `DraftWorkspaceServiceCopyTest` (6 functional cases) + `DraftPublishServiceTest` (4 functional cases) |

The extension now uses thirteen `tx_t3simplecmp_*` tables: 5 live
banner-config tables + 5 draft mirrors + audit-snapshot + consent-log +
audit-retention-log + detection log + library-cache + publish-lock. Run
`vendor/bin/typo3 database:updateschema` after upgrading.

See the upstream
[SimpleCMP requirements](https://github.com/SimpleCMP/simplecmp/blob/main/docs/requirements.md)
for the JS-side roadmap.

## Setup Wizard (Phase 6)

For first-time editors a linear onboarding wizard reduces the 7-tab
learning curve to a guided 3-step flow: **Tracker → Design → Publish**.

* A non-intrusive banner (`WizardBanner.html` partial) is rendered on
  every SimpleCMP tab while the wizard hasn't been completed yet —
  click **Start wizard** to enter the flow, **Remind me later** to skip.
* Step 1 picks one tracker provider (Matomo / GA4 / GTM / Meta Pixel /
  Microsoft UET) and writes the configuration to the
  `managed_tracker_draft` table via the same code path the regular
  Tracker-Setup tab uses.
* Step 2 picks a banner-style preset (card / bar-bottom / bar-top /
  centered modal) which gets persisted as a theme draft.
* Step 3 reviews everything and atomically publishes via
  `DraftPublishService::publish()`, the same publish path the standard
  tabs use — full audit snapshot included.

Wizard state lives in the Phase-5 `tx_t3simplecmp_active_settings`
table under two internal keys (`simplecmp.internal.wizardCompletedAt`,
`simplecmp.internal.wizardSkippedAt`), kept outside
`EFFECTIVE_CONTENT_KEYS` so they never appear in drift detection or
audit snapshots. The Settings tab has a footer link to relaunch the
wizard at any time (`reopen` action — clears both timestamps).

The wizard is purely a UX layer over Phase 4 + Phase 5 — no parallel
data store, no separate publish path. If the wizard is bypassed, the
standard tabs achieve the exact same end state.

## License

BSD-3-Clause. Mirrors the upstream SimpleCMP license.
