..  include:: /Includes.rst.txt

..  _administration:

==============
Administration
==============

This page covers day-to-day operation of the backend module.

The detections module
=====================

Open *Site Management → SimpleCMP detections* in the TYPO3 backend.
The module lists every unknown-tracker report received via the
CMS-bridge webhook, with filters, per-row actions, and a one-click
"convert to service" shortcut.

Filters and counts
------------------

The header shows the unreviewed-vs-total count. Two filter buttons
above the table toggle the row set:

*   **Only unreviewed** (default) — hides rows you've already
    triaged.
*   **All** — every row regardless of review status.

The filter survives mark/unmark/bulk-delete redirects so you stay on
whichever view you were working in.

Confidence badges
-----------------

Each row carries a coloured badge with the report count:

*   **Green** (5 or more reports) — high confidence; this is almost
    certainly a real tracker someone's actually triggering.
*   **Gray** (2-4 reports) — multiple visitors saw it; worth a
    look.
*   **Yellow / outlined** (1 report) — single observation. Treat
    with care — see "Convert to service" below for the safeguard.

Volume spike alert
------------------

When today's ingest sharply exceeds the 7-day rolling baseline, a
yellow banner appears at the top of the list with the day-vs-
baseline counts. Usually this just signals a campaign launch or a
newly-added tracker on the site, but the banner is a prompt to
review the recent rows carefully before bulk-curating any of them.

Per-row actions
---------------

*   **Details** — opens the raw webhook payload for the row,
    including page URL, user agent, referrer, and the full detection
    timing.
*   **Convert to service** — see the next section.
*   **Mark reviewed** — flips the :code:`reviewed` flag without
    deleting. The row stays in the table but hides in the default
    filter.
*   **Unmark** — visible on rows that are already marked reviewed.

The "Delete all reviewed" toolbar button bulk-deletes everything
marked reviewed. The action is confirmed with a browser dialog.

Convert to service
==================

The *Convert to service* button is the curation shortcut. It does
one of two things, depending on whether any existing service
already covers the detection:

*   **No existing match.** Opens a new-record form for
    :sql:`tx_simplecmptypo3_service`, pre-filled from the
    detection: service ID slug, name, and the appropriate cookie or
    origin matcher.
*   **Existing match.** Opens the *existing* service for editing,
    so you can see (and refine) what's already curated rather than
    starting fresh. When multiple services overlap on the same
    matcher, the most recently *created* one wins the tiebreak —
    typically what you want.

Low-confidence guardrail
------------------------

When a detection has only one report, the *Convert to service*
button shows a confirmation dialog before navigating. Treat
single-report rows as unverified — confirm only after you've
checked the cookie or origin against a real third-party service.

Service curation
================

Services live in :sql:`tx_simplecmptypo3_service` and edit via the
standard TYPO3 *Web → List* module. See :ref:`configuration` for
the field reference.

The new-record form launched from *Convert to service* is the same
form — pre-filled defaults vs. blank, otherwise identical.

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
