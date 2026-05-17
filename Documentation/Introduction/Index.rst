..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

What it does
============

This extension wires the SimpleCMP JavaScript library into a TYPO3 v14
site and adds the server-side moving parts that turn it from a banner
into a full consent-management toolchain:

*   **Frontend integration.** The SimpleCMP bundle and its runtime
    configuration land on every TYPO3 frontend page where the
    SimpleCMP Site Set is enabled. Banner, modal, and floating
    "Cookie settings" trigger come out of the box. Configuration
    flows from Site Settings — no TypoScript needed.

*   **Service-DB endpoint.** TYPO3 hosts an implementation of the
    SimpleCMP service-DB protocol at
    :file:`/api/simplecmp/v1/{health,services,lookup}`. The
    frontend's recorder uses it to classify cookies and outbound
    requests it doesn't recognize locally.

*   **CMS-bridge receiver.** Production sites can report unknown
    trackers back to the same TYPO3 install via a JSON webhook at
    :file:`/api/simplecmp/webhook`. Reports land in a
    :sql:`tx_simplecmptypo3_detection` table; the BE module surfaces
    them for admin review and one-click conversion into curated
    services.

*   **Backend modules** at *Site Management*. Two flat siblings under
    the *Websites* group:

    *   *SimpleCMP detections* — review unknown-tracker reports and
        curate them into services. Each row carries a three-state
        badge (*kuratiert* / *erkannt* / *unbekannt*) derived at view
        time from registry and library coverage, with three per-row
        actions (*Übernehmen*, *Anpassen*, *Kuratieren*) that match
        what the row needs.
    *   *SimpleCMP banner design* — per-site theme editor for the
        consent banner. Colors, typography, and corner radius are
        edited in a form with a live preview iframe; tokens persist
        in :sql:`tx_simplecmptypo3_theme` (one row per Site Set).

Who it's for
============

Site admins running TYPO3 v14 who need cookie / tracker consent
compliance (DSGVO, GDPR, ePrivacy) and want:

*   A working banner-and-modal flow without writing JavaScript.
*   A registry-driven service catalogue they manage in the TYPO3 BE
    instead of editing JSON config files.
*   An optional production-monitoring channel to spot tracker drift
    over time without re-auditing the site by hand.

It is intentionally not a turnkey replacement for commercial CMPs
that ship with hundreds of pre-curated services or IAB TCF v2.x
integration. SimpleCMP itself is opinionated and minimal; this
extension keeps the same shape.

Relationship to upstream
========================

SimpleCMP (the JS library) and this extension are developed in
parallel and version-locked at the moment. Each release of this
extension bundles a specific SimpleCMP build at
:file:`Resources/Public/JavaScript/simplecmp.global.js`. Compatibility
with arbitrary upstream versions is not currently guaranteed; pin
the extension version that matches your SimpleCMP expectations.

See also
========

*   `SimpleCMP on GitHub <https://github.com/SimpleCMP/simplecmp>`__
*   `simplecmp-typo3 on GitHub <https://github.com/WapplerSystems/simplecmp-typo3>`__
*   `Service-DB protocol spec <https://github.com/SimpleCMP/simplecmp/blob/main/docs/service-db-protocol.md>`__
*   `CMS-bridge webhook spec <https://github.com/SimpleCMP/simplecmp/blob/main/docs/cms-bridge-webhook.md>`__
