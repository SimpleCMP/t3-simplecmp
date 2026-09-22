
..  _configuration:

=============
Configuration
=============

All extension configuration lives in **Site Settings**, exposed once
you add **SimpleCMP — consent manager** as a Site Set dependency.

Open the BE at *Site Management → Settings*, select the site, and
the settings appear under the *simplecmp* heading.

..  contents::
    :local:
    :depth: 2

..  _settings-are-proposals:

A deployed setting is a proposal
================================

**Read this before wondering why a value you deployed has no effect.**

Banner-content settings ship in :file:`config/sites/<id>/settings.yaml`,
which belongs to the deployment. But what a visitor is shown has to be
something a person confirmed, not whatever the last deploy happened to
carry — so a deployed value is a *proposal* until an editor adopts it:

..  code-block:: text

    settings.yaml  ──deploy──▶  proposal  ──adopt──▶  active  ──▶  banner

Three states follow from that, and all three are silent:

*   **Not bootstrapped.** No value has ever been adopted for this site.
    The banner falls back to the raw YAML, so it looks like it is
    working — until the first adoption changes which values count.
*   **Drift.** A deploy changed :code:`simplecmp.*`, but nobody adopted
    the change. **The old value keeps rendering.** No error, no warning
    in the frontend.
*   **Custom.** An editor set a value in the backend that the YAML does
    not carry. The next deploy will not overwrite it.

Adopt in the backend under *Site Management → SimpleCMP →
Einstellungen*, which lists every drifting key with both values and an
*Übernehmen* button, or from the console:

..  code-block:: bash

    vendor/bin/typo3 simplecmp:status --site=main            # what state is it in?
    vendor/bin/typo3 simplecmp:adopt-settings --site=main --be-user=admin

Make the adopt step part of your deployment routine for any release that
touches :code:`simplecmp.*` — see :ref:`command-line`.

Two kinds of keys are exempt and take effect straight from YAML, because
they are operations rather than banner content: the URL and rate-limit
keys (:confval:`simplecmp.serviceDbUrl`, :confval:`simplecmp.cmsBridgeUrl`,
:confval:`simplecmp.bridgeRateLimit`, :confval:`simplecmp.libraryUpstreamUrl`,
:confval:`simplecmp.libraryUpstreamDailyBudget`) and
:confval:`simplecmp.storagePid`.

Site Settings reference
=======================

..  confval:: simplecmp.enabled

    :type: boolean
    :Default: true

    Master switch for the extension on this site. When :code:`false`,
    the SimpleCMP bundle is not loaded and no consent banner appears.

..  confval:: simplecmp.storageName

    :type: string
    :Default: :code:`simplecmp-<siteIdentifier>`

    localStorage / cookie key the visitor's consent decision is
    stored under. Defaults to :code:`simplecmp-` plus the site
    identifier — typically what you want. Override only if you need
    a specific key (e.g. for cross-site cookie sharing).

..  confval:: simplecmp.privacyPolicyUrl

    :type: string
    :Default: empty

    URL of your privacy policy. Linked in the banner and modal as
    *Datenschutz / Privacy*. **Required by DSGVO / GDPR.**

..  confval:: simplecmp.imprintUrl

    :type: string
    :Default: empty

    URL of your imprint / "Impressum". Linked next to the privacy
    policy. **Required in Germany (TMG/MStV).**

..  confval:: simplecmp.floatingTriggerLabel

    :type: string
    :Default: :code:`Cookie settings`

    Visible text and accessible label of the persistent floating
    button visitors can click to reopen their cookie settings.
    Localize for non-English sites (e.g. :code:`Cookie-Einstellungen`
    for German).

..  confval:: simplecmp.respectGPC

    :type: boolean
    :Default: true

    When the visitor's browser signals
    `Global Privacy Control <https://globalprivacycontrol.org/>`__,
    default all non-required services to opt-out on the first visit.
    The banner is still shown — only the default switch state
    changes.

..  confval:: simplecmp.serviceDbUrl

    :type: string
    :Default: empty

    Base URL of a Service DB that implements the SimpleCMP protocol.
    For same-install setups, this is your own TYPO3 site's host,
    e.g. :code:`https://www.example.com/api/simplecmp`. Leave empty
    to use only the locally curated services list.

    The JS client appends the protocol version (:code:`/v1`)
    automatically — **do not include it** in the configured URL. A
    trailing :code:`/v1` or trailing slash is auto-stripped at
    render time and logged as a warning.

..  confval:: simplecmp.cmsBridgeUrl

    :type: string
    :Default: empty

    POST target for unknown-tracker alerts in production. For
    same-install setups, point this at your own webhook endpoint:
    :code:`https://www.example.com/api/simplecmp/webhook`. Leave
    empty to disable the bridge.

    Requires a bridge secret to be configured — see
    :ref:`installation`. Without the secret, the bridge will not
    emit traffic and the BE module surfaces a callout.

..  confval:: simplecmp.bridgeRateLimit

    :type: integer
    :Default: 500

    Maximum number of webhook POSTs accepted from a single IP
    address within a sliding 1-hour window. Set to :code:`0` to
    disable rate limiting (not recommended in production).

..  confval:: simplecmp.libraryUpstreamUrl

    :type: string
    :Default: :code:`https://library.simplecmp.eu/v1`

    Base URL (including the :code:`/v1` segment) of the canonical
    hosted services-library. When set, the classifier queries it as a
    third tier — after the local registry and the bundled library both
    miss — so newly-added trackers classify correctly without waiting
    for a :code:`composer update` to refresh the bundled snapshot.

    Visitor IPs never reach this URL; only your server queries it
    (server-to-server), and responses are cached locally for 24 h
    (positive and negative). Set to an empty string to disable the
    upstream and rely solely on the bundled library. Freshness is
    surfaced in the Bibliothek tab — see :ref:`administration`.

..  confval:: simplecmp.libraryUpstreamDailyBudget

    :type: integer
    :Default: 5000

    Maximum number of upstream library calls per UTC day. Cache hits
    are free and never count; only fresh lookups (cache miss with the
    URL configured) consume the budget. Once today's count reaches this
    value, further misses skip the upstream silently for the rest of
    the day (the bundled library still matches what it can). Set to
    :code:`0` for unlimited.

..  confval:: simplecmp.universalBlocking.enabled

    :type: boolean
    :Default: true

    When on, the pipeline rewrites every third-party
    :code:`<script src>` / :code:`<iframe src>` / :code:`<img src>` /
    :code:`<link href>` to the engine's gated
    :code:`data-name` + :code:`data-src` + :code:`src="about:blank"`
    shape before the response is flushed, so visitors see the
    click-to-enable placeholder until consent is granted. Third-party
    hosts are recognised via the bundled library's origin matchers.
    Turn off only if your site embeds nothing third-party. (See
    ADR-0013 for the design.) Also gates the server-side recording of
    blocked embeds during a :ref:`Discover sweep <discover-trackers>`.

..  confval:: simplecmp.universalBlocking.allowlist

    :type: list of strings (one host per line)
    :Default: empty

    Hosts the rewriter passes through without gating, in addition to
    the site's own host (which is always allowed). Exact match
    (:code:`cdn.example.com`) or wildcard form (:code:`*.example.com`,
    matching the apex and every subdomain). Use for vendor CDNs, your
    own infrastructure, or known-safe hosts you don't want surfaced as
    consent decisions.

..  confval:: simplecmp.trackers

    :type: list of maps
    :Default: empty

    Managed trackers the deployment declares, each a map of
    :code:`type` plus that provider's fields:

    ..  code-block:: yaml

        simplecmp:
          trackers:
            - type: gtm
              containerId: GTM-XXXXXXX
            - type: ga4
              measurementId: G-XXXXXXX

    **These are proposals and load nothing on their own.** Like every
    other banner-content setting (see :ref:`settings-are-proposals`), a
    declared tracker has to be adopted before it exists as a managed
    tracker — until then the frontend has no loader for it, no service
    record, and no consent toggle, and nothing anywhere says so.

    Adopt them in *SimpleCMP → Einstellungen* (the pending ones are
    listed with an *Als managed_tracker anlegen* button) or with:

    ..  code-block:: bash

        vendor/bin/typo3 simplecmp:setup-tracker --site=main --be-user=admin --from-settings

    :code:`simplecmp:status` counts the unadopted ones per site, which
    is the quickest way to catch a tracker that was declared but never
    went live.

    Unlike the scalar settings, this key is deliberately **not** declared
    in :file:`settings.definitions.yaml`: TYPO3 site settings have no
    nested-array type, so it cannot appear in the backend settings form
    and is maintained in the YAML file only.

..  confval:: simplecmp.storagePid

    :type: integer
    :Default: 0

    TYPO3 page UID under which new :sql:`tx_t3simplecmp_service`
    and :sql:`tx_t3simplecmp_detection` records are created —
    adoption from the library, curation from a detection, and the
    service row a managed tracker materialises all land here.
    Typically a dedicated SysFolder.

    Records created before this setting was changed are **not** moved,
    and a record an editor moves by hand stays where they put it: a
    re-adopt or a tracker refresh updates its content, never its
    location.

    On a multi-site install the registry is global but the setting is
    per site; the first site that carries a value decides.

Service registry
================

Services are managed as TYPO3 records in
:sql:`tx_t3simplecmp_service`. The standard way to edit them is
*Web → List* on the page selected by :confval:`simplecmp.storagePid`.

Each service represents one third-party service and carries:

*   **Service ID** — the consent key the manager tracks state under.
    Slug-like, unique. Auto-generated from the service name on
    creation; admin-editable. Slug-collision UX uses TYPO3's
    inline-feedback :code:`type: slug` mechanism.
*   **Name** — display name shown in the consent UI.
*   **Vendor / Vendor country** — operator and country of legal
    residence. Surfaced in the modal for transparency.
*   **Purposes** — list of consent purposes (analytics, marketing,
    functional, …). Drives which services are toggled by which
    "Accept analytics" / "Accept marketing" group switches. Rendered
    as a side-by-side multi-select with a filter textbox; the
    available options are auto-discovered from the bundled
    `simplecmp/services-library` (so a new purpose category appearing
    in a future library release shows up in the BE form without a
    TCA edit). Stored in the DB as a JSON array — the form's CSV
    value is encoded on save and decoded on load.
*   **Privacy policy URL** — vendor's own privacy policy.
*   **Description** — short paragraph explaining what the service
    does. Surfaced in the modal.
*   **Cookies** — JSON array of cookie-name matchers. Exact strings
    or regex patterns wrapped in slashes (:code:`/^_ga/`).
*   **Origins** — JSON array of host matchers. Exact hosts or
    wildcard suffixes (:code:`*.google.com`).
*   **i18n** — per-language overrides for title and description.

See *Web → List → SimpleCMP services* for the full TCA form. Any
entry the admin has adopted from the bundled library is a good
reference example for the JSON shape of matchers, purposes, and
i18n overrides.
