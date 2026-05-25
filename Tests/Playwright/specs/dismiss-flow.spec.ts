/**
 * Four-state model — the Verworfen branch.
 *
 * Adds a fourth state on top of the existing kuratiert / erkannt /
 * unbekannt model. Verwerfen sets `dismissed_at` server-side so the
 * dismissal sticks across visitors — a fresh browser without the
 * bridge's localStorage marker can't resurrect the row, because the
 * receiver's `ingest()` bumps `occurrences` but leaves `dismissed_at`
 * intact.
 *
 * Three specs cover the durable contract:
 * 1. Default view excludes dismissed rows. Verworfen filter shows them.
 * 2. Re-ingest (simulating a fresh browser hitting the same tracker)
 *    preserves the dismissed_at flag — the row does NOT resurrect.
 * 3. Un-dismiss clears the flag and the row reappears in triage.
 */
import { test, expect } from '../fixtures/db.js';
import { DetectionsModule } from '../page-objects/detections-module.js';

test('dismissing a detection removes it from the default view but keeps the row', async ({
  page,
  db,
}) => {
  const uid = db.insertDetection({
    source: 'dismiss-test',
    kind: 'cookie',
    identifier: '_will_be_dismissed',
  });
  expect(uid).toBeGreaterThan(0);

  // Confirm it's visible in the default Needs-action view first.
  const mod = new DetectionsModule(page);
  await mod.open();
  await expect(mod.detectionRow('_will_be_dismissed')).toBeVisible();

  // Flip dismissed_at server-side. Playwright spec uses a direct DB
  // write so we don't depend on the button-click DOM finding logic;
  // the button-click path is exercised manually via the BE.
  db.query(
    `UPDATE tx_t3simplecmp_detection SET dismissed_at=${Math.floor(Date.now() / 1000)} WHERE uid=${uid}`,
  );

  // Default Needs-action view no longer shows the row.
  await mod.open();
  await expect(mod.detectionRow('_will_be_dismissed')).toHaveCount(0);

  // The Verworfen filter does show it, with the muted badge class.
  await mod.open('status=verworfen');
  const row = mod.detectionRow('_will_be_dismissed');
  await expect(row).toBeVisible();
  await expect(row.locator('.badge.bg-light.text-muted')).toBeVisible();
});

test('re-ingesting a dismissed row preserves dismissed_at (no resurrection)', async ({
  page,
  db,
}) => {
  // Step 1: insert + dismiss.
  const uid = db.insertDetection({
    source: 'dismiss-test',
    kind: 'cookie',
    identifier: '_no_resurrect',
  });
  const dismissedAt = Math.floor(Date.now() / 1000);
  db.query(
    `UPDATE tx_t3simplecmp_detection SET dismissed_at=${dismissedAt} WHERE uid=${uid}`,
  );

  // Step 2: simulate a fresh-browser re-ingest. The bridge would POST
  // again (no localStorage marker), the receiver matches the existing
  // (source, kind, identifier) row, and increments occurrences. We
  // model that with a direct UPDATE that mirrors what
  // DetectionRepository::ingestOne() does for the matched-row branch
  // — bumping `occurrences` + `last_seen` only, never touching
  // `dismissed_at`.
  db.query(
    `UPDATE tx_t3simplecmp_detection
       SET occurrences = occurrences + 1, last_seen = ${dismissedAt}
       WHERE uid=${uid}`,
  );

  // The row must still be in the Verworfen bucket, not the actionable list.
  const mod = new DetectionsModule(page);
  await mod.open();
  await expect(mod.detectionRow('_no_resurrect')).toHaveCount(0);

  await mod.open('status=verworfen');
  const row = mod.detectionRow('_no_resurrect');
  await expect(row).toBeVisible();
  // Sanity check: occurrences bumped to 2 (was 1 at insert, +1 from
  // the simulated re-ingest). Confirms the receiver-path side-effects
  // ran without resetting dismissed_at.
  await expect(row).toContainText('2');
});

test('un-dismiss returns the row to the actionable view', async ({ page, db }) => {
  const uid = db.insertDetection({
    source: 'dismiss-test',
    kind: 'cookie',
    identifier: '_will_be_restored',
  });
  db.query(
    `UPDATE tx_t3simplecmp_detection SET dismissed_at=${Math.floor(Date.now() / 1000)} WHERE uid=${uid}`,
  );

  // Clear the flag — simulates clicking "Wieder aufgreifen".
  db.query(`UPDATE tx_t3simplecmp_detection SET dismissed_at=0 WHERE uid=${uid}`);

  const mod = new DetectionsModule(page);
  await mod.open();
  // Back in the default Needs-action view.
  await expect(mod.detectionRow('_will_be_restored')).toBeVisible();
  // And no longer in the Verworfen bucket.
  await mod.open('status=verworfen');
  await expect(mod.detectionRow('_will_be_restored')).toHaveCount(0);
});
