..  _command-line:

============
Command line
============

Everything the backend module does to *set up* a site is also available
as a console command, so an environment can be configured from a
deployment script and a curated registry can travel between installs as
a reviewable JSON document.

..  contents::
    :local:
    :depth: 2

Why a CLI at all
================

The consent registry lives in database tables, not in :file:`config/`.
Without these commands a curated setup has no way to travel: staging it
on one environment and reproducing it on another means clicking through
the backend twice and hoping the results match, with no diff to review
and no way to repeat the steps.

The commands are not a second implementation — they drive the same
repositories, the same draft workspace and the same publish step as the
module. Anything set up from the command line appears in the backend
exactly as if an editor had done it, audit trail included.

The `--be-user` option
======================

Every writing command requires :code:`--be-user=<uid|username>`, and the
user must be an existing, enabled **admin**.

Two reasons this is not optional. The draft lock is stored in
:sql:`owner_be_user`, and uid 0 reads as "nobody" — a lock taken as 0
would be invisible and two runs could interleave. And a publish is an
audit event: the tables record who published, and *"some deploy script"*
is not an answer a DSGVO audit accepts.

A human editor's lock is never stolen. If someone holds the scope in the
backend, the command stops and says whose lock it is; taking over stays a
deliberate action by a person who can see whose work they would
interrupt.

Draft and publish
=================

Each writing command opens the site's draft, writes, publishes and
releases — one atomic step. Pass :code:`--no-publish` to stage several
commands into a single publish:

..  code-block:: bash

    vendor/bin/typo3 simplecmp:adopt-service google-analytics --site=main --be-user=admin --no-publish
    vendor/bin/typo3 simplecmp:setup-tracker --site=main --be-user=admin --type=gtm --set containerId=GTM-XXXXXXX --no-publish
    vendor/bin/typo3 simplecmp:publish --site=main --be-user=admin

Most commands also accept :code:`--dry-run`, which prints what would
change and exits.

Commands
========

..  _cli-status:

simplecmp:status
----------------

Read-only report per site: whether settings are bootstrapped, whether
YAML has drifted from the adopted values, how many managed trackers and
registry services exist, and whether a draft is open (and whose).

Written for the two moments that need it most — right after a rollout,
and when a banner is not doing what someone expected. It surfaces the
two states that are invisible from the frontend and easy to misread as
"the extension is broken":

*   *settings not bootstrapped* — the site runs on raw YAML because no
    editor has confirmed the values yet;
*   *trackers declared in settings.yaml but never adopted* — they are
    proposals, and **they do not load**.

:code:`--json` makes it usable as a deployment gate.

..  code-block:: bash

    vendor/bin/typo3 simplecmp:status
    vendor/bin/typo3 simplecmp:status --site=main --json

simplecmp:adopt-settings
------------------------

Confirm a site's YAML banner settings as the active values — the CLI
equivalent of *Aus Site-Konfiguration übernehmen*.

Banner-content settings ship in :file:`config/sites/<id>/settings.yaml`,
but what a visitor is shown must be something a person confirmed, not
whatever the last deploy happened to carry. A deployed value is therefore
a *proposal* until adopted. **Run this after any deploy that changes
`simplecmp.*`** — otherwise the new values sit as drift and the old ones
keep rendering, silently.

..  code-block:: bash

    vendor/bin/typo3 simplecmp:adopt-settings --site=main --be-user=admin --dry-run
    vendor/bin/typo3 simplecmp:adopt-settings --site=main --be-user=admin
    vendor/bin/typo3 simplecmp:adopt-settings --site=main --be-user=admin --key=simplecmp.floatingTriggerLabel

simplecmp:setup-tracker
-----------------------

Create or update a managed tracker so it loads behind consent. One saved
entry produces the service record, the consent-gated loader and the
inline bootstrap.

Idempotent by ``(site, serviceId)`` — re-running updates the row instead
of adding a second one, which is what a repeatable deployment needs.

:code:`--from-settings` adopts whatever :code:`simplecmp.trackers`
proposes in :file:`settings.yaml`, so a tracker declared in the
deployment reaches the registry without anyone retyping its IDs.

..  code-block:: bash

    vendor/bin/typo3 simplecmp:setup-tracker --site=main --be-user=admin \
        --type=gtm --set containerId=GTM-XXXXXXX --set consentPosture=block

    vendor/bin/typo3 simplecmp:setup-tracker --site=main --be-user=admin --from-settings

Unknown field names and values outside an enum are refused with the list
of what the provider accepts, so a typo fails loudly instead of being
stored and ignored.

simplecmp:adopt-service
-----------------------

Adopt entries from the bundled services library into the registry — the
CLI equivalent of the Bibliothek tab's *Übernehmen*. Adoption is how a
third-party service becomes something the banner manages consent for,
with the vendor data, purposes and cookie/origin matchers the library
curates.

:code:`--search` finds the slug when you only know the vendor's name.

..  code-block:: bash

    vendor/bin/typo3 simplecmp:adopt-service --search=linkedin
    vendor/bin/typo3 simplecmp:adopt-service google-analytics linkedin --site=main --be-user=admin

simplecmp:publish
-----------------

Promote a site's staged draft and release the lock. Only needed after
:code:`--no-publish` runs. Publishing an empty draft is not an error — it
closes the session.

simplecmp:export-registry
-------------------------

Serialise the live configuration to JSON: the global service registry,
and per site the managed trackers, banner theme, translation overrides,
allowed stylesheet hosts and adopted settings. Output is stably ordered,
so two exports of the same state are byte-identical and :command:`git
diff` shows only real changes.

Detections, consent logs and audit snapshots are deliberately **not**
exported. They are observations of one environment's visitors — copying
them into another would fabricate evidence.

..  code-block:: bash

    vendor/bin/typo3 simplecmp:export-registry --file=config/simplecmp-registry.json

simplecmp:import-registry
-------------------------

Apply an exported document. Idempotent, so it belongs in a deployment
script.

**It adds and updates; it never removes.** A service missing from the
document is left alone rather than deleted: withdrawing a service revokes
the consent UI for something that may still be loading, so it stays a
deliberate act instead of a side effect of importing a file someone
trimmed.

Sites are matched by identifier; one the target does not have is skipped
with a warning. :code:`--skip-settings` leaves adopted settings alone,
which is what you want when per-environment URLs differ.

..  code-block:: bash

    vendor/bin/typo3 simplecmp:import-registry --file=config/simplecmp-registry.json --be-user=admin --dry-run
    vendor/bin/typo3 simplecmp:import-registry --file=config/simplecmp-registry.json --be-user=admin

A rollout, end to end
=====================

Setting up a fresh production install from a configuration staged
elsewhere:

..  code-block:: bash

    # on the staging install, once the setup is curated
    vendor/bin/typo3 simplecmp:export-registry --file=config/simplecmp-registry.json
    # review the diff, commit it

    # on the target install, after the deploy
    vendor/bin/typo3 simplecmp:import-registry --file=config/simplecmp-registry.json --be-user=deploy
    vendor/bin/typo3 simplecmp:status --site=main

Building one from scratch instead:

..  code-block:: bash

    vendor/bin/typo3 simplecmp:adopt-settings  --site=main --be-user=admin
    vendor/bin/typo3 simplecmp:setup-tracker   --site=main --be-user=admin --from-settings
    vendor/bin/typo3 simplecmp:adopt-service   --site=main --be-user=admin google-analytics linkedin
    vendor/bin/typo3 simplecmp:status          --site=main

Related commands
================

Three older commands cover operations rather than setup:

*   :code:`simplecmp:generate-bridge-secret` — HMAC secret for the bridge
    webhook (see :ref:`installation`).
*   :code:`simplecmp:snapshot-config` — manual audit snapshot after a
    YAML-only edit, or as a pre-rollout freeze checkpoint.
*   :code:`simplecmp:audit-retention` / :code:`simplecmp:export-audit` —
    DSGVO retention and audit bundles (see :ref:`administration`).
