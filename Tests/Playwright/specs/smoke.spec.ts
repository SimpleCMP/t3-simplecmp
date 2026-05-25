/**
 * Smoke test — proves the harness works end to end.
 *
 * - The storage state loads (we're logged in without a manual login).
 * - The Detections BE module renders inside its iframe.
 * - The DB fixture truncates `tx_t3simplecmp_*` and `insertService`
 *   round-trips through `ddev mysql`.
 */
import { test, expect } from '../fixtures/db.js';
import { DetectionsModule } from '../page-objects/detections-module.js';

test('logged-in admin can open the Detections module', async ({ page, db }) => {
  expect(db.count('service')).toBe(0);
  expect(db.count('detection')).toBe(0);

  const mod = new DetectionsModule(page);
  await mod.open();

  // The module-router iframe holds the inner h1 — the open() helper
  // already waits for it, so reaching this line means the BE module
  // page rendered. Asserting once here makes failures point at the
  // right place if the iframe selector ever drifts.
  await expect(
    mod.content.locator('h1', { hasText: /tracker detections|Tracker-Erkennungen/i }).first(),
  ).toBeVisible();
});

test('db.insertService round-trips through ddev mysql', async ({ db }) => {
  const uid = db.insertService({ serviceId: 'smoke-test-svc', name: 'Smoke Test' });
  expect(uid).toBeGreaterThan(0);
  expect(db.count('service')).toBe(1);
});
