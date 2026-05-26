# Bundle-sync failure triage SOP

When the automated upstream bundle sync (`.github/workflows/sync-bundle.yml`)
fails, it opens a pull request labelled **`needs-triage`** with
reviewers `ille216` and `svewap`. This document is the walkthrough
for getting that PR closed cleanly.

## When you see a `needs-triage` PR

The PR title looks like `[bundle-sync FAILED] upstream <sha>`. The PR
body links to the workflow run that failed and to the upstream
commit.

### Step 1 — Open the workflow run

Click the **Workflow run** link in the PR body (or navigate
Actions → Sync upstream bundle → the failed run).

### Step 2 — Identify the failing step

Workflow steps in order:

| # | Step | If failed, means |
|---|------|------------------|
| 1 | Checkout ext | GitHub git access broke or branch protection blocks the bot push. Look at the error verbatim. |
| 2 | Resolve upstream SHA | Upstream SHA doesn't exist (force-push?) or the commits API returned an error. |
| 3 | Checkout upstream | Upstream repo access broke; might be a private-repo permissions change. |
| 4 | Setup pnpm / Node | Toolchain installer broke. Almost always GitHub or registry transient. Re-run. |
| 5 | Build upstream bundle | `pnpm install --frozen-lockfile` or `pnpm build` failed in upstream. Real upstream regression — fix upstream first. |
| 6 | Verify bundle integrity | Bundle too small / too large / not parseable as JS / missing expected public exports. Real upstream API surface change — see "API drift" below. |
| 7 | Copy bundle into ext | Local file op; should never fail. If it does, file a bug. |
| 8 | Setup PHP / Composer install | Toolchain transient. Re-run. |
| 9 | PHPUnit unit | PHP code in ext doesn't match what the new bundle exposes. See "API drift" below. |
| 10 | PHPUnit functional | Same shape as 9, but DB/integration-side. Same triage. |
| 11 | Compose commit message / Commit + push | Branch protection or git auth issue. Almost always config. |

### Step 3 — Triage by failure category

**A. Transient infrastructure failure** (Node/PHP installer broke,
network blip, GitHub status incident). Re-run the workflow from the
PR (Actions → Re-run all jobs). If the second run succeeds, close
the PR — the auto-merge path doesn't fire here, so you have to
delete the branch manually.

**B. Real upstream regression** (build broken, bundle invalid, public
API export missing). Don't fix-forward in the ext. Close the PR. Open
an issue against `SimpleCMP/simplecmp` describing the breakage. When
upstream ships a fix, the next push there fires a fresh sync that
overwrites this one.

**C. API drift** (bundle is fine but the ext's PHPUnit suite fails
because the FE wire shape changed). This is the genuine
fix-forward case:
- Pull the PR branch locally: `gh pr checkout <pr-number>`
- Reproduce the failure: `composer test:unit` (or `:functional`)
- Adjust the PHP code to match the new bundle's shape
- Commit on the same branch; the failed CI re-runs automatically
- When green, merge the PR

**D. Bundle integrity check tripped** but cause isn't obvious. Look
at the verification logs — message says exactly which expected
symbol is missing or what size threshold tripped. If a real API
surface was renamed upstream, this needs a coordinated upstream
change first; if it's a threshold that needs adjusting (e.g.
`BUNDLE_MAX_BYTES`), fix-forward on the branch.

### Step 4 — Close out

- **Re-ran and now green:** merge the PR, delete the branch.
- **Closed without fix:** delete the branch. The label sticks around
  for searchability.
- **Fix-forward landed:** PR auto-merges once CI is green (assumes
  branch protection is configured to allow `github-actions[bot]` to
  push). Otherwise merge manually.

## Why this exists

The failure PR mechanism replaces the older "just look at red CI runs
periodically" pattern. The PR is the work item; closing it (one way
or the other) is the explicit signal that the failure has been
addressed. Don't leave stale `needs-triage` PRs open — they obscure
real new failures.

## Related

- `.github/workflows/sync-bundle.yml` — the workflow itself
- `bundle_sync_automation.md` in author memory — full design rationale
  (out of band, for context when picking the work up again)
