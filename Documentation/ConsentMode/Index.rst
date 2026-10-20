..  _consent-mode:

===================================
Google Tag Manager and Consent Mode
===================================

A Google Tag Manager container is a tag host: it loads the tags that are
configured in Google's console, and those tags are where the tracking
happens. SimpleCMP can gate such a container in two ways, chosen per
managed tracker with :code:`consentPosture`.

..  contents::
    :local:
    :depth: 2

..  _consent-mode-postures:

The two postures
================

``block`` (default)
    The container loads only after the visitor consents to the
    *Google Tag Manager* service. Until then nothing from the container
    runs. Once it is loaded, every tag in it runs — a visitor who
    accepted the Tag Manager but switched off, say, LinkedIn in the
    banner still gets the LinkedIn tag. The per-service switches in the
    banner do not reach inside the container.

``signal-gate``
    The container loads before any decision. SimpleCMP sends Google
    Consent Mode v2 signals instead, and each tag in the container
    decides from those signals whether it may run. This is the posture
    in which the banner's choices actually apply per tag — but only if
    the container is set up for it, see :ref:`consent-mode-container`.

The posture of the GA4 tracker works the same way.

..  _consent-mode-switch:

Switching posture
=================

The posture is part of the managed tracker, not of
:file:`settings.yaml` — tracker entries in :code:`simplecmp.trackers` are
only proposals (see :ref:`settings-are-proposals`). Switch with the
*Tracker setup* in the backend module or on the command line:

..  code-block:: bash

    vendor/bin/typo3 simplecmp:setup-tracker --site=main --be-user=admin \
        --type=gtm --set containerId=GTM-XXXXXXX --set consentPosture=signal-gate

Flush the page cache afterwards: the loader and the init configuration
are part of the cached page.

The way back is the same command with :code:`consentPosture=block`.

..  important::

    Do not switch before the container is ready. A tag without a consent
    setting runs before consent as soon as the container loads
    unconditionally. Prepare the container first, test it in Google's
    preview mode, then switch and publish together.

..  _consent-mode-signal-gate:

What SimpleCMP does in signal-gate
==================================

*   **Loads the container at once.** The loader is a plain
    :code:`<script src>` marked :code:`data-no-rewrite`, so Universal
    Blocking leaves it alone.

*   **Shows the Tag Manager as technically necessary.** The
    *Google Tag Manager* service is required (always on, locked in the
    banner) and carries the purpose ``functional``. It grants no Consent
    Mode signal itself; a ``purposes`` override on the tracker is ignored
    in this posture. Being required also keeps the scripts the container
    loads from :code:`www.googletagmanager.com` (e.g. :code:`gtag/js`)
    from being blocked.

*   **Sends** :code:`gtag('consent', 'default', …)` before the container
    runs, with every mapped signal ``denied`` (opt-in regime), plus
    ``security_storage: granted`` and ``wait_for_update: 500``.

*   **Sends one** :code:`gtag('consent', 'update', …)` **per decision** —
    when the visitor saves, and again on withdrawal. Toggling switches in
    the preferences dialog without saving grants nothing. A returning
    visitor gets the stored decision as an update right after the
    default.

*   **Maps purposes to signals.** ``analytics`` grants
    ``analytics_storage``; ``marketing`` grants ``ad_storage``,
    ``ad_user_data`` and ``ad_personalization``. A signal is granted as
    soon as *any* consented service carries the purpose.

*   **Pushes a GTM event with each update**, carrying the decision per
    service and per purpose:

    ..  code-block:: javascript

        {
          event: 'simplecmp_consent_update',
          simplecmp: {
            services: { 'linkedin-insight': false, 'google-analytics': true, … },
            purposes: { analytics: true, marketing: false, … }
          }
        }

..  _consent-mode-container:

Preparing the container
=======================

Go through every tag in the container before switching.

Remove tags that set consent themselves
    A custom tag that calls :code:`gtag('consent', 'default', …)` with
    ``granted`` (often left over from an earlier banner) overrides the
    banner: every tag then counts as consented the moment the container
    loads. Pause or delete it in the same publish that goes with the
    switch.

Give every tag a consent setting
    In the tag under *Advanced settings → Consent settings*. Google's own
    tags have *built-in consent checks*; with only those, a Google tag
    still sends cookieless pings to Google while consent is denied
    (Consent Mode "advanced"). If those are not wanted, additionally
    require ``analytics_storage`` (Analytics) or ``ad_storage`` (Ads) —
    then the tag does not fire at all without consent. Third-party tags
    (LinkedIn, Bing, Clarity, custom HTML) need the additional consent
    in any case.

Do not use the *Consent Initialization* trigger for ordinary tags
    It fires first of all, before the banner's decision is known, and is
    meant only for tags that set consent. A tag that requires consent and
    fires there is blocked and does not run again later on that page.
    Use *Initialization – All Pages* or *All Pages*; the consent setting
    does the gating.

Decide between purpose groups and per-vendor switches
    Consent Mode signals are per purpose: a visitor who consents to one
    marketing service grants ``ad_storage`` to every marketing tag. If the
    banner's per-service switches should apply per tag, create a
    *Data Layer Variable* :code:`simplecmp.services.<serviceId>` (for
    example :code:`simplecmp.services.linkedin-insight`) and add the
    condition *equals* ``true`` to that tag's trigger, or use it in an
    exception trigger. The service ids are the ones in the registry.

Custom scripts that store data in the browser
    A custom HTML tag writing cookies or :code:`localStorage` (lead
    attribution, A/B tests) needs consent like any tracker: give it a
    consent setting, and register a service for it in the registry so
    visitors see it in the banner.

..  _consent-mode-verify:

Verifying
=========

Check three cases in a private window — *decline all*, *only
statistics*, *accept all* — first against the unpublished workspace in
Google's preview mode, then once more on the live site after publishing:

*   :code:`window.dataLayer` shows SimpleCMP's ``default`` first and no
    other ``default`` with ``granted`` after it.
*   After *decline all* no tracking request leaves the page and no
    third-party cookie is set (e.g. ``_gcl_au``, ``_ga``).
*   After *accept all* there is exactly one ``update`` and one
    ``simplecmp_consent_update`` event, and the tags fire on that page
    without a reload.

To go back, revert the container to its previous version in the Tag
Manager and switch the tracker to :code:`consentPosture=block`.
