..  include:: /Includes.rst.txt

..  _recorder:

==============
The recorder
==============

The **recorder** is the development-time half of SimpleCMP. It observes
the live frontend in a real browser and reports every cookie set,
script loaded, image fetched, iframe embedded, and outbound network
request it sees. Developers use it to *discover* trackers without
having to read third-party plugin source code or wade through their
own integrators' Slack history.

This page covers what the recorder is, where its output lives, and
how it interacts with the rest of the SimpleCMP stack on a TYPO3 site.

.. contents::
   :local:
   :depth: 2

When it runs
============

The recorder is **opt-in per site**. It runs when the SimpleCMP Site
Set is enabled *and* the :confval:`simplecmp.record` setting is true.
The setting defaults to off in production builds — running a recorder
in front of real visitors is a privacy concern (it tags everything
they trigger as a potential tracker) and a performance cost.

Typical setups:

*   **Dev / staging:** :code:`record: true`. The console shows every
    detection with classification, and the periodic summary table
    appears every 30 seconds.
*   **Production:** :code:`record: false` (the default). The recorder
    is not loaded; only the consent UI and (if configured) the CMS
    bridge run.

To force-enable the recorder on a production hostname (e.g. for a
controlled production-monitoring deployment per ADR-0004), set
:code:`record.silenceProductionWarning: true` — otherwise the
recorder emits a `console.warn` on every page load reminding admins
they're in a privacy-sensitive mode.

What gets observed
==================

Four watchers ship with the recorder. Each runs independently and
funnels into the recorder's de-duplication and classification
pipeline.

Cookie watcher
--------------

Polls :code:`document.cookie` once per second (:confval:`simplecmp.record.cookieIntervalMs`,
default `1000`) and emits a `cookie` detection on every newly-seen
name. The polling cadence is a deliberate compromise — `document.cookie`
has no mutation event, so polling is the only option, and 1 s is fast
enough to catch a cookie set immediately after page load.

Caveats:

*   The recorder cannot see `HttpOnly` cookies (browser-side cookie
    APIs don't expose them by design). If your server sets such a
    cookie, classify it manually.
*   The watcher **ignores** the consent storage cookie itself. The
    recorder's `ignoreCookies` list auto-includes the resolved
    `storageName`; integrators can extend it via
    :confval:`simplecmp.record.ignoreCookies` for other infra-owned
    cookies (e.g. a CSRF token cookie a developer doesn't want
    classified).

DOM watcher
-----------

A `MutationObserver` on `<body>` that catches `<script>`, `<img>`,
`<iframe>`, and `<link>` elements as they're inserted, plus a
synchronous one-shot sweep at start. Each detection carries the
loaded URL as its `identifier` and the host as `origin`.

Network watcher
---------------

A `PerformanceObserver` watching `resource` entries. Catches
`fetch`, `XMLHttpRequest`, and beacon traffic — useful for detecting
trackers that don't insert a DOM node (the typical "anonymous tracker
endpoint" pattern).

Classification
==============

When a raw detection arrives, the recorder runs it through the
**classifier** to enrich it with a status (`known` / `unknown`) and,
for known items, the service that matched.

Two classifiers exist:

Local classifier
----------------

Active on every site. Matches against the locally-registered service
list (the TYPO3 service registry, the curated services flowing
through Site Settings). Synchronous — runs in the same tick as the
watcher emit.

A typical match: visitor sets the `_ga` cookie → cookie watcher emits
`cookie:_ga` → local classifier sees an `_ga` matcher on the
`google-analytics` service → detection is marked `known` and
`matchedService = 'google-analytics'`.

Layered classifier
------------------

Active when :confval:`simplecmp.serviceDbUrl` is configured (ADR-0005,
REQ-8). Wraps the local classifier. When the local match misses, it
falls through to an asynchronous lookup against the configured
Service DB. The detection is announced as `unknown` first, then
patched in place if the DB lookup matches.

The two-stage emission is intentional — synchronous UIs (recorder
console output, in-page diagnostic overlays) re-render on each
emission, so consumers see the value flip from `unknown` to `known`
when the DB resolves.

Consumers that should not race the DB lookup — most notably the
**CMS bridge** (see below) — subscribe to the recorder's
:code:`'detectionSettled'` event instead, which fires once per
detection after classification is final. See
:file:`docs/adr/0009-detection-settled-event.md` upstream for the
design discussion.

Where the output goes
=====================

The recorder talks to three audiences. Each has its own surface.

Browser console
---------------

The default UX. Every detection logs one line:

..  code-block:: text

    [SimpleCMP recorder] cookie 🟡 unknown: _ga
    [SimpleCMP recorder] cookie → google-analytics: _ga

The 🟡 prefix marks an unknown — these are the rows developers
should action. The arrow form (`→ servicename`) shows a successful
match.

Every 30 seconds (:confval:`simplecmp.record.summaryIntervalMs`),
the recorder also dumps a `console.table` of the current snapshot —
deduplicated, grouped by kind, with counts. Useful for an at-a-glance
view of "what trackers are *active* right now on this site." Set
the interval to `0` to disable.

`sessionStorage` persistence (dev only)
---------------------------------------

If :confval:`simplecmp.record.persistInDev` is true, the recorder
mirrors its snapshot into `sessionStorage` (keyed by `storageName`)
so detections survive a page reload. Only takes effect on hostnames
that look like dev / staging — the recorder calls
`hostnameLooksLikeDev()` and refuses to persist on real-looking
domains (no `.com`, `.de`, etc. host suffix that's not `.local` /
`.test` / `.localhost`).

CMS bridge
----------

When :confval:`simplecmp.cmsBridgeUrl` is configured, the recorder
POSTs every settled-unknown detection to the configured webhook.
See :ref:`administration` for the BE detections module that
displays these.

Rather than waiting for organic traffic, an admin can drive the
recorder across the whole site on demand with the
:ref:`Discover trackers <discover-trackers>` module — it walks the
sitemap in a hidden iframe and reports without the usual bandwidth
controls.

Useful diagnostic helpers
=========================

In the browser DevTools console (recorder must be active):

..  code-block:: javascript

    // Current snapshot — deduplicated, with counts.
    simplecmp.getRecorder().getSnapshot();

    // Generate a service-list stub from the unknowns. Copy-paste
    // into your configuration as a starting point for curation.
    simplecmp.getRecorder().exportConfig();

    // CI / build-time gate: throws if any unknown detections exist.
    // Useful inside an e2e test that walks every page of the site
    // with the recorder running and fails the build on drift.
    simplecmp.getRecorder().assertNoUnknown();

Common pitfalls
===============

*Recorder reports the consent cookie as unknown.* — The recorder's
`ignoreCookies` should auto-include the consent storage name. If
you see a row for the storage cookie, either an integrator set
:confval:`simplecmp.record.ignoreCookies` and forgot to include
the storage name, or the resolved storage name isn't matching
the cookie name (e.g. site overrides the default).

*Same tracker reported twice.* — Recorder de-duplicates by
`${kind}:${identifier}`. A cookie and a request both for Google
Analytics produce two detections — `cookie:_ga` and
`request:https://www.google-analytics.com/...` — because they're
different evidence of the same service. The classifier matches
them both to the same `matchedService`; in the snapshot they
appear as separate rows but with the same service name.

*Production warning suppressed but recorder still inactive.* — The
warning suppression only quiets the `console.warn`. Whether the
recorder *runs* is controlled by :code:`record: true | false`. To
run on production with the warning silenced, both have to be set.
