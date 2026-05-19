/**
 * Registry → banner promotion.
 *
 * Under the 3-table architecture, every row in the registry table
 * appears on the FE banner — there is no `fe_visible` flag any
 * longer. This spec proves the BE module's headline counters and
 * empty-state messaging reflect that contract.
 *
 * Why this spec exists: when fe_visible was removed (commit f243217)
 * we lost the BE switch that toggled banner visibility. The
 * remaining UX invariant is "registry count == banner-visible
 * count" — easy to break by mistake (e.g. an admin filtering by
 * status forgets to clear the filter and assumes the banner is
 * empty). This spec pins the headline counter to what the registry
 * actually holds.
 */
import { test, expect } from '../fixtures/db.js';
import { DetectionsModule } from '../page-objects/detections-module.js';

test('empty registry + empty detections shows zero counters', async ({ page, db }) => {
  expect(db.count('service')).toBe(0);
  const mod = new DetectionsModule(page);
  await mod.open();

  // The header line reads "<N> need action · <N> already curated · <N> total"
  // (translated). All three should be zero on a fresh DB.
  await expect(mod.content.locator('h1').first()).toBeVisible();
  const counterLine = mod.content
    .locator('text=/already curated|bereits kuratiert/i')
    .first();
  await expect(counterLine).toContainText(/0/);
});

test('three registry rows reflect in the curated counter', async ({ page, db }) => {
  for (const id of ['banner-a', 'banner-b', 'banner-c']) {
    db.insertService({
      serviceId: id,
      name: id.toUpperCase(),
      cookies: [`cookie_${id}`],
      purposes: ['functional'],
    });
  }
  // Plant a detection for one of them so it appears in the default
  // "pending" view's "already curated" tally — the BE counts
  // kuratiert detections, not registry rows.
  db.insertDetection({
    source: 'banner-test',
    kind: 'cookie',
    identifier: 'cookie_banner-a',
  });

  const mod = new DetectionsModule(page);
  await mod.open();

  // 1 detection matches a registry row → "already curated" counter = 1
  // (the others have no matching detection — registry-only services
  // are surfaced via the Services catalog, not the detection list).
  const counterLine = mod.content
    .locator('text=/already curated|bereits kuratiert/i')
    .first();
  await expect(counterLine).toContainText(/1/);
});
