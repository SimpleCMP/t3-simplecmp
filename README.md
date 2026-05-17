# SimpleCMP for TYPO3

TYPO3 v14 integration for [SimpleCMP](https://github.com/SimpleCMP/simplecmp) — the
open-source consent manager with development-time tracker auto-detection, a shared
service database, and optional CMS-bridge webhook alerts.

This extension is **pre-1.0** and tracks SimpleCMP's own pre-release status. APIs
will change.

![Detection triage view](Documentation/Images/be-list-default.png)

*The detection triage view: three-state per-row model surfaces what the admin
should actually do next.*

## What it does

- **Frontend integration** — embeds the SimpleCMP JS bundle on every TYPO3
  frontend page, sourcing its `init({...})` config from the active Site
  Set's settings. The service registry (`tx_simplecmptypo3_service`)
  drives the runtime services array and a per-language `translations`
  block.

- **Service-DB endpoint** at `/api/simplecmp/v1/{health,services,lookup}` —
  implements the upstream
  [Service-DB protocol](https://github.com/SimpleCMP/simplecmp/blob/main/docs/service-db-protocol.md).
  Two seed paths:
  - `vendor/bin/typo3 simplecmp:seed` — 10 bundled essentials (Google
    Analytics, Matomo, YouTube, …) shipped inside this extension.
  - `vendor/bin/typo3 simplecmp:import-known-trackers` — 40 curated
    services from the [`simplecmp/services-library`](https://github.com/SimpleCMP/services-library)
    composer package (Hotjar, Stripe, Intercom, TikTok Pixel, hCaptcha,
    Bugsnag, Mailchimp, and 33 more).

- **CMS-bridge receiver** at `/api/simplecmp/webhook` — accepts the
  HMAC-signed POSTs the frontend bridge emits when the recorder catches a
  cookie or origin neither the local classifier nor the Service-DB
  endpoint recognises. Idempotent: repeat hits of the same
  `(source, kind, identifier)` triple bump `occurrences` instead of
  inserting duplicates.

- **BE detection module** at *Websites → SimpleCMP-Detektionen* — three
  states derived per row at view time from **registry coverage + bundled
  library coverage**:

  | State | Meaning | Action |
  |---|---|---|
  | **Kuratiert** | Registry already covers this cookie/origin | *Dienst bearbeiten* |
  | **Erkannt** | Library recognises the pattern but the local registry doesn't | *Übernehmen* (silent insert after confirmation modal) **or** *Anpassen* (curate with library pre-fill) |
  | **Unbekannt** | Neither registry nor library matches | *Kuratieren* only |

  No `reviewed` flag, no dismiss-only path — the admin makes an explicit
  decision on every actionable row.

- **Multisite support** — one TYPO3 install can serve as the central
  triage point for several frontend sites. The *Reporting site* column
  tags each detection with the Site Set that reported it; the filter
  dropdown lets admins slice by site.

## Screenshots

### The three row states

| Erkannt — library knows it | Unbekannt — nobody knows it | Kuratiert — already in registry |
|---|---|---|
| ![Erkannt](Documentation/Images/be-list-state-erkannt.png) | ![Unbekannt](Documentation/Images/be-list-state-unbekannt.png) | ![Kuratiert](Documentation/Images/be-list-state-kuratiert.png) |

### The Übernehmen confirmation modal

Three sections so the admin sees exactly what they're approving before
the registry gets the entry — frontend-facing data (purposes with
descriptions, privacy URL, a faithful preview of the FE service-toggle),
raw data (the JSON that will land in the registry, link to the library
source on GitHub), and impact (count of existing detections that will be
resolved):

![Übernehmen modal](Documentation/Images/be-modal-uebernehmen.png)

### Multisite triage

Detections from multiple Site Sets in one list, with the *Reporting
site* column showing which frontend reported each row:

![Multisite list](Documentation/Images/be-list-multisite.png)

Filter to a single Site Set:

![Reporting-site filter](Documentation/Images/be-filter-reporting-site.png)

### Bridge configured

The green pill that an active install shows once the HMAC secret is in
place:

![Bridge configured](Documentation/Images/be-callout-bridge-configured.png)

### Frontend

| Consent banner | Configuration modal |
|---|---|
| ![Banner](Documentation/Images/fe-banner.png) | ![Modal](Documentation/Images/fe-modal.png) |

## Installation

```bash
composer require wapplersystems/simplecmp-typo3
```

In Site → Site Sets, add the **SimpleCMP — consent manager** set as a
dependency. Configure under Site → Settings.

After install, run the two seed commands to populate the registry:

```bash
ddev exec vendor/bin/typo3 simplecmp:seed
ddev exec vendor/bin/typo3 simplecmp:import-known-trackers
```

That gives you ~50 curated services out of the box, so most frontend
trackers classify as known on first visit.

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

## Bridge / Service-DB race

When both `serviceDbUrl` and `cmsBridgeUrl` point at this extension, the
JS-side bridge can fire a webhook for any detection that's still
`unknown` at first announcement — including ones the Service-DB lookup
later resolves to known. Order of events:

1. Recorder catches a cookie.
2. Local classifier misses; detection emitted as `unknown`.
3. Bridge fires webhook immediately.
4. Service-DB lookup completes; status updated to `known`.

So a well-known tracker can produce both a Service-DB hit (the page
classifies it as known) *and* a webhook row.

**The `simplecmp:import-known-trackers` command dramatically reduces
this** — well-known patterns ship in the local classifier so step 2
matches and the bridge never fires. The race still applies for genuinely
new patterns, but those are by definition worth recording.

A future SimpleCMP release will add an opt-in grace delay so the bridge
waits for the Service-DB lookup to settle.

## Status

Five iterations shipped:

1. Frontend bundle integration + Site Set settings wiring.
2. Service-DB endpoint with the protocol-conformant routes.
3. CMS-bridge receiver + HMAC nonce auth (`simplecmp:generate-bridge-secret`).
4. BE detection module with mark-reviewed / bulk-delete / convert-to-service.
5. Three-state model with library-aware approve flow + multisite support.

See the upstream
[SimpleCMP requirements](https://github.com/SimpleCMP/simplecmp/blob/main/docs/requirements.md)
for the JS-side roadmap.

## License

BSD-3-Clause. Mirrors the upstream SimpleCMP license.
