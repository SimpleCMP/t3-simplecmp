..  _command-line:

============
Command line
============

Everything the backend module can change is also available as a console
command, so an environment can be configured from a deployment script
and a curated registry can travel between installs as a reviewable JSON
document.

One capability has no console equivalent, by its nature rather than by
omission: **Tracker entdecken** (:ref:`Discover sweeps <discover-trackers>`)
drives a real browser through the site's sitemap so it sees the page the
way a visitor does, including trackers injected by JavaScript. A
server-side process cannot observe that, so the sweep stays a backend
action. Everything it *produces* — the detections — is triageable from
the console.

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

:code:`--set <key>=<value>` stores a custom active value the deployment
does not carry, and :code:`--reset <key>` hands the key back to the YAML
— the per-key *Speichern* / *Zurücksetzen* of the Einstellungen tab.
Values are parsed as JSON where possible, so
:code:`--set simplecmp.respectGPC=false` stores a boolean rather than the
string ``"false"``. A custom value survives the next deploy, which is the
point and also why it is worth being deliberate about: nobody reading
:file:`settings.yaml` alone will see it.

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

:code:`--list` shows the site's managed trackers, :code:`--remove=<serviceId>`
deletes one. Removing a tracker leaves its service record in the
registry: by then it is an ordinary curated service, and silently
withdrawing a consent toggle because a loader went away would be the
wrong default. Drop it with
:code:`simplecmp:curate-service <id> --remove` if that is what you mean.

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

simplecmp:curate-service
------------------------

Create, update or delete a registry service by hand — the TCA form
behind *Kuratieren* / *Anpassen*, and the Dienste tab's delete.

This is the path for a vendor the bundled library does not know, which is
the common case for regional embeds and small SaaS widgets. It matters
more than it sounds: with Universal Blocking on, such a host is gated and
the visitor gets a placeholder that **no consent choice can unlock**,
because there is no service to consent to. Curating one turns the block
into a decision the visitor can actually make.

..  code-block:: bash

    vendor/bin/typo3 simplecmp:curate-service --list

    vendor/bin/typo3 simplecmp:curate-service flipsnack --site=main --be-user=admin \
        --set name=Flipsnack --set vendor='Flipsnack SRL' \
        --set purposes=functional,marketing \
        --set origins=player.flipsnack.com,cdn.flipsnack.com \
        --set privacyPolicyUrl=https://www.flipsnack.com/privacy-policy

    vendor/bin/typo3 simplecmp:curate-service flipsnack --remove --site=main --be-user=admin

Editing starts from the stored record, so one :code:`--set` changes one
field instead of blanking the rest. :code:`--from-json` reads the whole
service in the library/export shape, which is how a service curated on
one install moves to another. A service is refused without at least one
purpose and at least one cookie or origin matcher — without those it
matches nothing and gates nothing, which is a silent way to think a
vendor is handled when it is not.

simplecmp:detections
--------------------

List and triage what the recorder reported — the Detektionen tab. The
four states are derived by the same presenter the module uses, so the
console and the tab never disagree about what still needs a decision.

..  code-block:: bash

    vendor/bin/typo3 simplecmp:detections                       # what needs action
    vendor/bin/typo3 simplecmp:detections --state=unbekannt
    vendor/bin/typo3 simplecmp:detections --uid=12 --adopt --site=main --be-user=admin
    vendor/bin/typo3 simplecmp:detections --uid=12 --dismiss --site=main --be-user=admin
    vendor/bin/typo3 simplecmp:detections --uid=12 --purge --site=main --be-user=admin

Removal is two steps here as it is in the module: :code:`--dismiss` flags
a row and keeps it as an audit trail, :code:`--purge` deletes it and only
ever touches rows that were dismissed first — a uid that was never
dismissed is refused, not silently destroyed. A purge re-arms
re-detection for the affected sources, so a tracker that is still on the
site comes back instead of staying invisible for the dedup TTL.

simplecmp:set-theme
-------------------

Banner appearance — the Design tab, minus the live preview. Tokens are
stored as a diff from the bundle defaults, so a site that never set a
token follows a future default automatically.

..  code-block:: bash

    vendor/bin/typo3 simplecmp:set-theme --site=main --show
    vendor/bin/typo3 simplecmp:set-theme --site=main --be-user=admin --set position=middle-center
    vendor/bin/typo3 simplecmp:set-theme --site=main --reset --be-user=admin
    vendor/bin/typo3 simplecmp:set-theme --site=main --check

:code:`--check` runs the same compliance audit the designer shows inline
and exits non-zero on a critical finding — the one part of the banner
that can be wrong in a way nobody notices until it matters, which makes
it worth a CI step. Setting a token to an empty value stops overriding
it, which is not the same as setting it to an empty string.

simplecmp:set-texts
-------------------

Banner wording per language. :code:`--tone` picks the formal/informal
overlay for languages that ship one (German Sie/Du), which is usually all
a site needs; :code:`--set <key>=<text>` rewrites an individual bundle
string.

..  code-block:: bash

    vendor/bin/typo3 simplecmp:set-texts --site=main --show
    vendor/bin/typo3 simplecmp:set-texts --site=main --language=de --tone=informal --be-user=admin
    vendor/bin/typo3 simplecmp:set-texts --site=main --language=de --be-user=admin --set banner.accept='Alles klar'

An empty text clears that one override instead of storing an empty
string, so a string can be handed back to the bundle default without
losing the rest of the language.

simplecmp:draft
---------------

Draft-session control — the module's draft banner. Writing commands open
and publish on their own, so this is for when the session itself is the
subject:

..  code-block:: bash

    vendor/bin/typo3 simplecmp:draft --site=main                          # who holds it, is anything staged
    vendor/bin/typo3 simplecmp:draft --site=main --open    --be-user=admin
    vendor/bin/typo3 simplecmp:draft --site=main --discard --be-user=admin
    vendor/bin/typo3 simplecmp:draft --site=main --takeover --force --be-user=admin

Discarding someone else's draft is refused, and a takeover additionally
needs :code:`--force`: both destroy work a person may still be in the
middle of, and neither should be reachable by a typo.

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
