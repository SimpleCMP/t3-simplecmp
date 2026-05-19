..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

All extension configuration lives in **Site Settings**, exposed once
you add **SimpleCMP — consent manager** as a Site Set dependency.

Open the BE at *Site Management → Settings*, select the site, and
the settings appear under the *simplecmp* heading.

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

..  confval:: simplecmp.storagePid

    :type: integer
    :Default: 0

    TYPO3 page UID under which new :sql:`tx_simplecmptypo3_service`
    and :sql:`tx_simplecmptypo3_detection` records are created.
    Typically a dedicated SysFolder. Records created before this
    setting was changed are **not** moved.

Service registry
================

Services are managed as TYPO3 records in
:sql:`tx_simplecmptypo3_service`. The standard way to edit them is
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
entry imported via :code:`simplecmp:import-known-trackers` is a
good reference example for the JSON shape of matchers, purposes,
and i18n overrides.
