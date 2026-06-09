
..  _administration:

==============
Administration
==============

Day-to-day operation of the two SimpleCMP backend modules. Both live
under the *Websites* group in the module menu, prefixed *SimpleCMP*
for visual grouping.

.. contents::
   :local:
   :depth: 2

The SimpleCMP module
====================

*Site Management → SimpleCMP.*

The main module is organised into tabs:

*   **Detektionen** — triage trackers reported via the CMS-bridge
    webhook (and via :ref:`Discover sweeps <discover-trackers>`).
    Covered below.
*   **Dienste** — the curated service registry
    (:sql:`tx_t3simplecmp_service`): the services whose consent the
    frontend banner actually manages. Same records you can edit via
    *Web → List*; see :ref:`configuration`.
*   **Bibliothek** — browse and adopt entries from the bundled
    `simplecmp/services-library`, with recommendations for your open
    detections, and the upstream-freshness card (see
    `Library upstream freshness`_).
*   **Tracker-Einrichtung** — set up well-known managed trackers
    (Matomo, GA4, GTM) so they load behind consent without manual
    curation. See `Tracker setup`_.

A *Tracker entdecken* action and the bridge-secret controls sit on the
Detektionen tab.

The detections list
-------------------

Every unknown-tracker report received via the CMS-bridge webhook lands
on the **Detektionen** tab. The job is to decide what each detection is
and either *adopt* it from the bundled library or *curate* it from
scratch.

Four-state model
~~~~~~~~~~~~~~~~~

Each row carries a state badge derived at view time — the
:sql:`reviewed` boolean from earlier versions is gone. The state is
read from registry coverage, library coverage, and the row's
dismissal flag:

*   **kuratiert** (green) — an existing service in the registry
    already covers this detection's cookie or origin. Nothing to
    do; the FE banner already handles consent for this tracker.
*   **erkannt** (blue) — the bundled `simplecmp/services-library`
    knows this tracker but no service in this install's registry
    covers it yet. The recommended action is *Übernehmen* (silent
    one-click import).
*   **unbekannt** (yellow) — neither the registry nor the library
    recognise this. Requires a curation decision from the admin —
    *Anpassen* (curate with any partial library match pre-filled)
    or *Kuratieren* (start blank).
*   **verworfen** (gray) — explicitly dismissed by an admin (see
    *Dismiss & purge* below). Hidden from the default view; durable
    across visitors.

The state filter above the list offers *Brauchen Aktion* (default —
*erkannt* + *unbekannt*), *Nur erkannte*, *Nur unbekannte*, *Nur
kuratierte*, *Nur verworfene*, and *Alle*. Bookmarked URLs from
earlier versions that used :code:`?status=unreviewed` are redirected
to the default view.

There is also a *Unbekannte neu klassifizieren* button that re-runs the
open *unbekannt* rows against the upstream library (budget-aware) — a
quick way to pick up classifications added upstream since the rows were
first reported, without a :code:`composer update`.

Confidence badges
~~~~~~~~~~~~~~~~~

Each row also carries a coloured confidence badge with the report
count:

*   **Green** (5 or more reports) — high confidence; this is almost
    certainly a real tracker someone is actually triggering.
*   **Gray** (2-4 reports) — multiple visitors saw it; worth a
    look.
*   **Yellow / outlined** (1 report) — single observation. Treat
    with care.

Volume spike alert
~~~~~~~~~~~~~~~~~~

When today's ingest sharply exceeds the 7-day rolling baseline, a
yellow banner appears at the top of the list with the day-vs-
baseline counts. Usually this just signals a campaign launch or a
newly-added tracker on the site, but the banner is a prompt to
review the recent rows carefully before bulk-curating any of them.

Per-row actions
~~~~~~~~~~~~~~~

Each row shows the action buttons relevant to its state:

*   **Übernehmen** (*erkannt* rows only) — one-click silent-import
    of the matching bundled-library entry, surfaced behind a
    confirmation modal. The modal has three labelled sections:

    *   *Frontend data.* The purposes the library entry declares
        (with human-readable descriptions), its privacy-policy URL,
        and a faithful preview of how the service will render in
        the FE service-toggle.
    *   *Raw data.* The full library JSON literal, with a link to
        the source file on GitHub.
    *   *Impact.* The number of existing detections that this
        single import will resolve (because the new service will
        cover their cookie / origin too).

    Confirming creates the service record from the library data
    verbatim — no form intermediation.
*   **Anpassen** — opens the new-service form with whatever the
    library partially matched pre-filled. Use when the library
    entry is close but you want to refine purposes, vendor, or
    matchers before saving.
*   **Kuratieren** — opens a blank new-service form pre-filled
    only with the slug and the detection's cookie / origin
    matcher. Use for true *unbekannt* rows the library does not
    cover.
*   **Verwerfen** — dismiss the detection: the row moves to the
    *verworfen* state (durable across visitors) and leaves the
    default view. The table also supports multi-row selection with an
    *Ausgewählte verwerfen (n)* bulk action.
*   **Details** — open the full detection record: page URL,
    first / last seen, report count, and the raw bridge payload.

There is **no** *Mark reviewed* / *Unmark* action and no one-click
delete — the pre-v0.2.0 :sql:`reviewed` column is gone (state derives
from coverage + dismissal), and removal is the audit-safe two-step
described next.

Dismiss & purge
~~~~~~~~~~~~~~~

Removing a detection is deliberately two steps:

#.  **Verwerfen** moves the row to *verworfen*. It stays in the
    database — flagged and timestamped, excluded from the default view
    — an audit trail, not a deletion. From the *Nur verworfene* filter
    you can **Rückgängig** (undismiss) to bring it back.
#.  **Endgültig löschen** — the bulk purge, available only in the
    *Verworfen* view and behind a confirm — hard-deletes the ticked
    rows.

A purge means *"forget this; re-detect it if it's still on the
site."* See `Re-detection after deletion`_ for how a
purged-but-still-present tracker re-surfaces on the visitor's next
page load.

Service curation
~~~~~~~~~~~~~~~~

The new-record form launched from *Anpassen* / *Kuratieren* is the
same TCA form you reach via *Web → List → SimpleCMP services* —
pre-filled defaults vs. blank, otherwise identical. See
:ref:`configuration` for the field reference.

..  _discover-trackers:

Discover trackers
=================

*SimpleCMP detections → Tracker entdecken.*

The recorder only sees trackers that actually fire in a visitor's
browser. *Discover trackers* lets an admin proactively walk the whole
site so detections accumulate without waiting for real traffic.

How it works
------------

The module loads each URL of the site's sitemap into a **hidden iframe
in your own browser**, one after another with a short dwell between
pages. Each URL is visited with :code:`?simplecmp_discover=1` appended,
which tells the SimpleCMP frontend bundle to report every tracker it
sees *without* the usual bandwidth controls (cross-session dedup,
sampling, and Do-Not-Track are all bypassed for that page load only),
so the sweep populates the detections list reliably. Reports flow
through the normal CMS-bridge webhook.

Because the sweep runs in your browser, it sees the site exactly as a
visitor would — including trackers injected by JavaScript.

Blocked embeds
--------------

Embeds that universal blocking neutralises server-side (YouTube, Google
Maps, …) never run, so the recorder can't observe them. During a sweep
the server-side HTML rewriter records what it blocked as detections too,
so those surface in the list alongside runtime-detected trackers. This
only happens when :confval:`simplecmp.universalBlocking.enabled` is on
(the rewriter can only report what it neutralised) and is gated by a
signed token (see *Security* below).

Controls
--------

*   **Start / Stop / Continue.** Progress is saved per site in the
    browser's :code:`localStorage`, so you can stop a long sweep and
    resume it later from where it paused.
*   **Reset.** Clears the saved progress for the selected site and
    starts fresh.
*   **Estimated time.** Shown before you start, based on the URL count
    and the per-page dwell.
*   **Show iframe.** A toggle to reveal the otherwise-hidden iframe if
    you want to watch the pages load.

Security
--------

Only the site's *own* hosts (plus any sitemap hosts its
:file:`robots.txt` declares) can be swept — an admin can't point the
server-side sitemap fetch at internal services. The server-side
recording of blocked embeds is additionally gated by a short-lived,
source-bound token minted for the sweep, so the detection write can't be
triggered by anyone simply appending :code:`?simplecmp_discover=1` to a
public URL.

Library upstream freshness
==========================

*SimpleCMP detections → Bibliothek tab.*

When :confval:`simplecmp.libraryUpstreamUrl` points at a hosted
`services-library <https://github.com/SimpleCMP/services-library>`__
service, the Bibliothek tab shows a *Bibliotheks-Upstream* card with two
things: whether the **bundled** library shipped with the extension is
still current, and the runtime-lookup activity against the upstream.

Reading the card
----------------

The upstream status line shows one of:

*   **Auf dem Stand** (green ✓) — the bundled library and the upstream
    carry byte-identical data; nothing to update.
*   **Updates verfügbar** (yellow ⚠) — the upstream has newer data;
    refresh the bundle with :code:`composer update simplecmp/services-library`.
*   **nicht erreichbar** — a probe ran recently and failed (shown with
    the time it was last checked).
*   **Status veraltet** — no recent probe result is cached. This is a
    neutral state, *not* an error; the panel refreshes itself (see
    below).

The runtime-lookup box reports the local lookup cache size, today's
upstream calls against the daily budget, and the last call time.

Why it never makes the tab slow
-------------------------------

Opening the tab **never** probes the upstream over the network — it
renders only from cache, so a slow or unreachable upstream can never
make the Bibliothek tab hang. Two things keep the cached status fresh:

*   *Jetzt prüfen* runs a probe on demand.
*   When the cached status is *stale*, a small background request
    refreshes it automatically and the panel reloads into the real
    status — so you normally never see "veraltet" for more than a
    moment, and you rarely need *Jetzt prüfen* by hand.

A successful probe is cached for 24 hours (the upstream's data changes
rarely, and a :code:`composer update` invalidates the cache
immediately); a failed probe is cached briefly so a down upstream can't
be hammered.

Re-detection after deletion
===========================

Removing a detection is a two-step, audit-safe path: **Verwerfen**
(dismiss — the row stays, flagged, and won't clutter the default view)
and then, from the *Verworfen* filter, **endgültig löschen** (purge —
the row is hard-deleted). Dismissal is durable: a verworfen tracker
stays dismissed across visitors and is not re-surfaced.

A purge is different — it means *"forget this; re-detect it if it's
still on the site."* That used to not work for returning visitors: the
frontend remembers what it already reported (a cross-session marker in
the visitor's browser, kept ~7 days) and would stay silent even after
you purged the row, so a still-present tracker never came back.

Purging now bumps a per-site **report generation** counter that the
frontend bundle receives in its config. On the visitor's next page load
the bundle notices the bump and re-reports anything it had previously
suppressed — so a purged-but-still-present tracker re-surfaces (as
*erkannt* or *unbekannt*) without waiting out the ~7-day window.
Trackers you merely *verwerfen* are unaffected.

(A :ref:`Discover sweep <discover-trackers>` also re-surfaces
declaratively-blocked embeds regardless of any per-visitor state, since it
records them server-side.)

Tracker setup
=============

*SimpleCMP → Tracker-Einrichtung tab.*

Most of the module is about *discovering* third-party trackers after the
fact. This tab is the opposite: it lets you **set up a known tracker you
run on purpose** — Matomo, Google Analytics 4, or Google Tag Manager —
so it loads correctly behind consent, without hand-curating a service
record or editing any template.

You pick a tracker type, fill in a short form (the IDs from that
tool's own console), and save. The extension does the rest.

Supported trackers
------------------

Each provider exposes its own small set of fields; required ones are
marked, the rest are optional:

*   **Matomo** — :code:`url` (your Matomo install) and :code:`siteId`
    (required); :code:`disableCookies` (cookieless mode) optional.
*   **Google Analytics 4** — :code:`measurementId`
    (:code:`G-XXXXXXX`, required); :code:`anonymizeIp` and Google
    :code:`consentMode` optional.
*   **Google Tag Manager** — :code:`containerId`
    (:code:`GTM-XXXXXXX`, required); :code:`consentDefault` optional.

Every type also has an optional :code:`serviceId` — leave it blank for
the common single-instance case (it defaults to the type name), or set
it to run two instances of the same provider on one site (e.g. two
Matomo sites) under distinct consent keys.

Trackers are stored per site in :sql:`tx_t3simplecmp_managed_tracker`
and edited here with the usual new / edit / delete actions. Trackers
declared in Site Settings via a :code:`simplecmp.trackers` list are
also shown (they use the same provider definitions); manage those in
the settings, not here.

What gets wired up
------------------

From one saved entry the extension produces the three things a
consent-gated tracker needs, so you don't assemble them by hand:

#.  **A service record** in the registry — so the tracker appears in
    the banner with sensible defaults (purposes, vendor, privacy-policy
    URL, cookie/origin matchers) and its origins are allowed by the
    blocking/CSP layer.
#.  **A consent-gated loader.** The provider's remote script
    (:code:`matomo.js`, :code:`gtag.js`, :code:`gtm.js`) is emitted
    with :code:`data-name="<serviceId>"`, which SimpleCMP's runtime
    holds back until the visitor consents to that service.
#.  **An inline bootstrap** (:code:`_paq` / :code:`dataLayer` /
    :code:`gtag('config', …)`) that pre-configures the tracker with
    your IDs. It is emitted with the CSP nonce, so it doesn't trip a
    :code:`script-src` violation.

So a configured tracker stays fully dormant until consent is granted,
then initialises with the right settings — no manual service curation
and no Fluid/TypoScript edits.

The banner design module
========================

*Site Management → SimpleCMP banner design.*

Per-site editor for the FE consent banner and its wording. Each Site
Set that runs SimpleCMP gets its own theme; admins customise the
framework, layout, placement, colours, and per-language texts without
editing YAML or PHP. Changes take effect on the next frontend page
render.

What you can edit
-----------------

The form is grouped into sections:

*   **CSS framework** — bind the banner to your site's framework
    (:code:`default`, :code:`bootstrap5`, :code:`tailwind4`,
    :code:`bulma`, or :code:`pico`) so it inherits the host's design
    tokens rather than shipping its own.
*   **Banner style & placement** — a banner template/layout, a 3×3
    position picker (corner / edge / centre), and the floating-trigger
    position. Style-preset cards offer one-click looks.
*   **Colors** — brand (accept / decline), text & background, and
    advanced tokens (hover, muted text, alternate background). Custom
    colours are **opt-in**: by default the banner uses a
    compliance-safe palette that keeps the Accept and Decline buttons
    visually equal-weight (emphasising Accept is a dark-pattern risk).
    Enabling custom colours surfaces a warning to that effect.
*   **Override banner texts** — rewrite any bundle string for one
    language at a time, via a language picker. Use it to choose a
    formal vs. informal **Tone** (Sie/Du — the Tone toggle appears only
    for languages that ship an informal overlay) or to fully reword a
    button or notice. Empty fields fall back to the bundle default.
    Stored per site in :sql:`tx_t3simplecmp_translation_override`.

Typography and corner-radius controls from earlier versions were
removed; the banner now inherits type and shape from the chosen
framework and the host page.

Live preview
------------

The right pane shows a live preview that boots the real SimpleCMP
bundle and updates as you edit. *Accept* / *Decline* clicks in the
preview are inert, so you can't accidentally record consent while
designing; *Configure* still opens the modal so you can preview the
full service list.

Compliance audit
----------------

The designer runs a live compliance check and lists findings inline,
split by severity (**critical** / **warning**), each linking into the
relevant section of a built-in compliance reference. Two layers run: a
config-level audit of the saved theme and settings, and an on-demand
**frontend audit** that boots the real banner and inspects the
rendered DOM. Use it to catch dark-pattern and disclosure issues
(unequal buttons, missing policy links, …) before they ship.

Save / reset
------------

*Save theme* writes only the diff from the upstream defaults — if
the upstream default ever changes, sites that never customised
that token automatically move to the new default.

*Reset to defaults* (red button, confirmed) deletes the site's
row from :sql:`tx_t3simplecmp_theme`. Missing row = upstream
defaults; this is how a fresh site renders.

Bridge-secret rotation
======================

To rotate the bridge secret:

1.  Re-run the CLI generator: :code:`vendor/bin/typo3 simplecmp:generate-bridge-secret`.
2.  Replace the configured value in
    :file:`config/system/settings.php` or your env var.
3.  Hard-restart PHP / flush OPcache if you want the old value
    invalidated immediately. (Otherwise it expires from request-
    handling caches naturally within an hour.)

The BE module's *Generate bridge secret* button is intended for
first-time bootstrap. It does not overwrite an existing value — use
the CLI for rotation.

Logs
====

Misconfigurations and runtime warnings are logged via TYPO3's
standard logger. The most useful sources:

*   Misconfigured :confval:`simplecmp.serviceDbUrl` (auto-correction
    of a trailing :code:`/v1`).
*   Missing bridge secret when the bridge is enabled (also
    surfaced as a callout in the BE module).
