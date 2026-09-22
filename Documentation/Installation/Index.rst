
..  _installation:

============
Installation
============

Requirements
============

*   TYPO3 v14.0 or later
*   PHP 8.3 or later
*   A composer-managed TYPO3 installation

Composer install
================

..  code-block:: bash

    composer require simplecmp/t3-simplecmp

Until the package is registered on Packagist, point composer at the
GitHub repository in your project's :file:`composer.json`:

..  code-block:: json

    {
        "repositories": [
            {
                "type": "vcs",
                "url": "https://github.com/SimpleCMP/t3-simplecmp"
            }
        ]
    }

Activate the Site Set
=====================

In the TYPO3 backend, go to *Site Management → Sites*, edit the site
that should run SimpleCMP, and add **SimpleCMP — consent manager** to
the *Site Set Dependencies* of the site set.

After the Site Set is added, all of SimpleCMP's settings appear
under *Site Management → Settings* for the site. See
:ref:`configuration` for the full settings reference.

Bridge webhook secret (required if `cmsBridgeUrl` is set)
=========================================================

The CMS-bridge webhook receiver requires a shared secret to be
configured before it will accept POSTs. There are two ways to
bootstrap one:

**Option 1 — Backend button.** Open *Site Management → SimpleCMP
detections*. When no secret is configured, the page shows a
yellow callout with a *Generate bridge secret* button. Clicking
it writes a fresh value into :file:`config/system/settings.php`
under :php:`$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp']['bridgeSecret']`.

**Option 2 — CLI.** Run:

..  code-block:: bash

    vendor/bin/typo3 simplecmp:generate-bridge-secret

The command prints both the value and a paste-ready snippet for
your TYPO3 configuration. Environment-variable interpolation is
recommended for production deployments:

..  code-block:: php

    // In config/system/additional.php:
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp']['bridgeSecret']
        = getenv('SIMPLECMP_BRIDGE_SECRET') ?: null;

One secret per TYPO3 installation. If you run multiple installs and
one POSTs bridge webhooks to another, configure the **same** value on
both ends.

Database schema
===============

Seventeen tables ship with the extension. TYPO3's database compare
creates them on first install; in a deployment, run:

..  code-block:: bash

    vendor/bin/typo3 extension:setup --extension=simplecmp

The ones worth knowing by name:

*   :sql:`tx_t3simplecmp_service` — the service registry: what the
    banner manages consent for.
*   :sql:`tx_t3simplecmp_managed_tracker` — trackers you run on
    purpose (GTM, GA4, Matomo, …).
*   :sql:`tx_t3simplecmp_detection` — the webhook receiver's landing
    table for trackers the recorder spotted.
*   :sql:`tx_t3simplecmp_active_settings` — the values an editor has
    confirmed (see :ref:`settings-are-proposals`).
*   :sql:`tx_t3simplecmp_consent_log` and
    :sql:`tx_t3simplecmp_config_snapshot` — the audit trail.

Most of the rest are the :sql:`*_draft` counterparts of the editable
tables plus the publish lock, which together make up the draft
workspace, and two caches.

The bundled services library
============================

The :code:`simplecmp/services-library` composer package ships with
hundreds of well-known third-party services — analytics (Mixpanel,
Hotjar, Plausible, Fathom, Amplitude, Heap), ad networks (LinkedIn
Insight, TikTok Pixel, Pinterest Tag, X Pixel, Snapchat Pixel,
Microsoft Bing UET, Outbrain, Taboola), embeds (Vimeo, Instagram,
Spotify, SoundCloud, Twitch), forms / captcha (hCaptcha, Cloudflare
Turnstile, Typeform, JotForm), chat widgets (Intercom, Drift, Crisp,
Tawk.to, Zendesk Chat, HubSpot), payments (Stripe, PayPal, Klarna),
maps (Mapbox), monitoring (Bugsnag, LogRocket, Rollbar), fonts (Adobe
Fonts / Typekit), Google Tag Manager, Mailchimp, Disqus, and many
more.

**No import step needed** — the library lives in the composer vendor
tree and is consulted directly by the Service-DB middleware at
lookup time via the :code:`ClassifierLookup` service. Cookies covered
by the library classify as :code:`known` from day one without any
admin action.

The registry (:code:`tx_t3simplecmp_service`) starts empty and
only ever holds admin-curated services. Three ways for the admin to
adopt a library entry into the registry — required so visitors see
the consent toggle in the banner:

-   **Bibliothek tab** (BE module): browse the full library, filter
    by Available / Adopted, search by id / name / vendor / matchers,
    click *Übernehmen* on any entry.
-   **Detektionen tab**: when the recorder catches the cookie on the
    FE, the resulting detection row offers *Übernehmen* (silent adopt
    with confirmation modal) or *Anpassen* (TCA edit with library
    pre-fill).
-   **Console**: :code:`simplecmp:adopt-service <id>…`, with
    :code:`--search` to find a slug by vendor name. See
    :ref:`command-line`.

First setup
===========

A freshly installed site has an **empty registry**: the banner renders,
but it manages consent for nothing and no tracker loads. Installing the
extension is not the same as setting it up.

Either walk the backend wizard — *Site Management → SimpleCMP* offers
*Assistent starten* on a new install and covers tracker, design and
publish — or do the same from the console:

..  code-block:: bash

    vendor/bin/typo3 simplecmp:adopt-settings --site=main --be-user=admin
    vendor/bin/typo3 simplecmp:setup-tracker  --site=main --be-user=admin --from-settings
    vendor/bin/typo3 simplecmp:adopt-service  --site=main --be-user=admin google-analytics
    vendor/bin/typo3 simplecmp:status         --site=main

On a second environment, import the configuration you already curated on
the first instead of repeating the clicks — see :ref:`command-line`.

Verifying the installation
==========================

1.  Run :code:`vendor/bin/typo3 simplecmp:status`. Every site that
    should run the CMP wants *Bootstrapped: yes*, no drift, and no
    pending tracker proposals; the registry should hold more than zero
    services.
2.  Load any frontend page on the configured site.
3.  Open the browser DevTools console — no SimpleCMP errors should
    appear.
4.  Verify the consent banner appears (clear localStorage if a
    previous decision is cached) **and that it lists the services you
    adopted**. A banner with no services means an empty registry, not a
    working installation.
5.  Visit :file:`/api/simplecmp/v1/health` — should return
    :code:`{"ok":true,...}`.
