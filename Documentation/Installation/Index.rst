..  include:: /Includes.rst.txt

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

    composer require wapplersystems/simplecmp-typo3

Until the package is registered on Packagist, point composer at the
GitHub repository in your project's :file:`composer.json`:

..  code-block:: json

    {
        "repositories": [
            {
                "type": "vcs",
                "url": "https://github.com/WapplerSystems/simplecmp-typo3"
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
under :php:`$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret']`.

**Option 2 — CLI.** Run:

..  code-block:: bash

    vendor/bin/typo3 simplecmp:generate-bridge-secret

The command prints both the value and a paste-ready snippet for
your TYPO3 configuration. Environment-variable interpolation is
recommended for production deployments:

..  code-block:: php

    // In config/system/additional.php:
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['simplecmp_typo3']['bridgeSecret']
        = getenv('SIMPLECMP_BRIDGE_SECRET') ?: null;

One secret per TYPO3 installation. If you run multiple installs and
one POSTs bridge webhooks to another, configure the **same** value on
both ends.

Database schema
===============

Two tables ship with the extension and are created automatically by
TYPO3's database compare on first install:

*   :sql:`tx_simplecmptypo3_service` — the service registry.
*   :sql:`tx_simplecmptypo3_detection` — the webhook receiver's
    landing table.

Importing the known-trackers library
====================================

To pre-fill the classifier with the bundled services library:

..  code-block:: bash

    vendor/bin/typo3 simplecmp:import-known-trackers

The bundled library covers hundreds of well-known third-party
services — analytics (Mixpanel, Hotjar, Plausible, Fathom, Amplitude,
Heap), ad networks (LinkedIn Insight, TikTok Pixel, Pinterest Tag, X
Pixel, Snapchat Pixel, Microsoft Bing UET, Outbrain, Taboola), embeds
(Vimeo, Instagram, Spotify, SoundCloud, Twitch), forms / captcha
(hCaptcha, Cloudflare Turnstile, Typeform, JotForm), chat widgets
(Intercom, Drift, Crisp, Tawk.to, Zendesk Chat, HubSpot), payments
(Stripe, PayPal, Klarna), maps (Mapbox), monitoring (Bugsnag,
LogRocket, Rollbar), fonts (Adobe Fonts / Typekit), Google Tag
Manager, Mailchimp, Disqus, and many more.

Imported entries land with :code:`fe_visible = 0` — they pre-fill
the classifier server-side (so the recorder + Service-DB middleware
recognise their cookies and origins) but stay off the visitor's
banner until an admin promotes them. The two promotion paths are:

-   Click *Übernehmen* / *Anpassen* on a real detection in the
    *Detektionen* tab (the recorder caught the cookie on the FE).
-   Browse the *Dienste* tab (BE service catalog) and click "Show on
    banner" for any entry the admin knows is in use on their site.

Default behaviour is **skip-if-exists** so admin-edited services
aren't clobbered when an admin re-runs after an extension update.
Pass :code:`--force` to overwrite existing services with the
bundled values. :code:`fe_visible = 1` is preserved across
re-imports so admin's promotions survive :code:`--force`.

Verifying the installation
==========================

1.  Load any frontend page on the configured site.
2.  Open the browser DevTools console — no SimpleCMP errors should
    appear.
3.  Verify the consent banner appears (clear localStorage if a
    previous decision is cached).
4.  Visit :file:`/api/simplecmp/v1/health` — should return
    :code:`{"ok":true,...}`.
