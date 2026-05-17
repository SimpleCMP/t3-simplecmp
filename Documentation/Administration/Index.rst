..  include:: /Includes.rst.txt

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

The detections module
=====================

*Site Management → SimpleCMP detections.*

Every unknown-tracker report received via the CMS-bridge webhook
lands here. The module's job is to help an admin decide what each
detection is and either *adopt* it from the bundled library or
*curate* it from scratch.

Three-state model
-----------------

Each row carries a state badge derived at view time — the
:sql:`reviewed` boolean from earlier versions is gone. The state
is read from registry and library coverage:

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

The state filter above the list has four values: *Ausstehend*
(default — *erkannt* + *unbekannt*), *Erkannt*, *Unbekannt*,
*Kuratiert*, and *Alle*. Bookmarked URLs from earlier versions
that used :code:`?status=unreviewed` are redirected to the default
*Ausstehend* view.

Confidence badges
-----------------

Each row also carries a coloured confidence badge with the report
count:

*   **Green** (5 or more reports) — high confidence; this is almost
    certainly a real tracker someone is actually triggering.
*   **Gray** (2-4 reports) — multiple visitors saw it; worth a
    look.
*   **Yellow / outlined** (1 report) — single observation. Treat
    with care.

Volume spike alert
------------------

When today's ingest sharply exceeds the 7-day rolling baseline, a
yellow banner appears at the top of the list with the day-vs-
baseline counts. Usually this just signals a campaign launch or a
newly-added tracker on the site, but the banner is a prompt to
review the recent rows carefully before bulk-curating any of them.

Per-row actions
---------------

Every row has up to three action buttons — which ones appear
depends on the row's state:

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

Each row also has a per-row delete button with a confirm dialog,
and the table supports multi-row selection with a *Delete
selected (n)* bulk action that activates once at least one row is
ticked. A *Delete all* sibling is available in the same split
button for whole-table cleanup.

There is **no** *Mark reviewed* or *Unmark* action. The pre-v0.2.0
:sql:`reviewed` column is dropped; state derives from registry +
library coverage instead.

Service curation
----------------

The new-record form launched from *Anpassen* / *Kuratieren* is the
same TCA form you reach via *Web → List → SimpleCMP services* —
pre-filled defaults vs. blank, otherwise identical. See
:ref:`configuration` for the field reference.

The banner design module
========================

*Site Management → SimpleCMP banner design.*

Per-site theme editor for the FE consent banner. Each Site Set
that runs SimpleCMP gets its own theme; admins customise colors,
typography, and corner radius without editing YAML or PHP.

What's editable
---------------

Tokens are grouped into five semantic sections:

*   **Brand** — primary color (Accept button background) + decline
    color.
*   **Surface** — body text, card background, border.
*   **Advanced** — primary-hover, muted text, alternate background
    (badges, required-pill).
*   **Typography** — body font-family + size, heading font-family +
    size. Sizes accept px/rem/em (validated by HTML5 pattern).
    Font-family inputs offer a :code:`<datalist>` with eight common
    stacks.
*   **Shape** — corner radius (px/rem/em).

A *Detect fonts from active site* button next to Typography reads
the live FE's computed :code:`<body>` and first-heading
:code:`font-family` and :code:`font-size` via a hidden iframe and
auto-fills the four typography fields. Same-origin only —
cross-origin reads throw a friendly *"Couldn't read fonts. Type
them in manually."* fallback.

Live preview
------------

The right pane is a live preview iframe that boots the real
SimpleCMP bundle with a synthetic three-service init config. As
you edit form fields the preview updates with a 120 ms debounce.
*Accept* / *Decline* clicks in the preview are inert (a capture-
phase event blocker stops them before they reach the banner's
event handlers); *Configure* passes through so you can preview
the modal with all services too.

Save / reset
------------

*Save theme* writes only the diff from the upstream defaults — if
the upstream default ever changes, sites that never customised
that token automatically move to the new default.

*Reset to defaults* (red button, confirmed) deletes the site's
row from :sql:`tx_simplecmptypo3_theme`. Missing row = upstream
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
