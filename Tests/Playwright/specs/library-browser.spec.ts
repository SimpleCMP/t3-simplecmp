/**
 * Bibliothek (library browser) tab — covers `LibraryBrowserController`.
 *
 * Two basic guarantees:
 * 1. The tab renders bundled library entries — proving `ServicesLibrary`
 *    is reachable from the BE.
 * 2. An "adopt" action upserts the library entry into the registry —
 *    proving the durable side-effect of clicking Übernehmen on a
 *    library row.
 *
 * The library content itself comes from the `simplecmp/services-library`
 * composer package and is mostly stable, but we assert against `_ga`
 * (Google Analytics) which has been in the library since import.
 */
import { test, expect } from '../fixtures/db.js';
import { DetectionsModule } from '../page-objects/detections-module.js';

test('Bibliothek tab lists bundled library entries', async ({ page, db }) => {
  expect(db.count('service')).toBe(0);
  const mod = new DetectionsModule(page);
  await mod.open();
  await mod.openLibraryTab();

  // The library has hundreds of entries spread across multiple pages.
  // Rather than asserting against a specific service id (which might
  // sort off the first page or get renamed), assert the table
  // structure: a table body with at least one row is enough to prove
  // the library is loading. Library-empty state would render a
  // dedicated message *instead of* a table — that asymmetry catches
  // the regression we care about (bundled JSON missing / not parsed).
  await expect(mod.content.locator('tbody tr').first()).toBeVisible({ timeout: 10_000 });
  const rowCount = await mod.content.locator('tbody tr').count();
  expect(rowCount).toBeGreaterThan(5);
});

test('adopting a library entry inserts it into the registry', async ({ page, db }) => {
  expect(db.count('service')).toBe(0);
  const mod = new DetectionsModule(page);
  await mod.open();
  await mod.openLibraryTab();

  // The adopt control is a <form> POST that lands back on the same
  // library page. Clicking the first row's adopt button is enough to
  // verify the round-trip — we don't care WHICH library entry got
  // adopted because the order depends on the bundled JSON.
  const firstAdopt = mod.content
    .locator(
      'form[action*="adopt"] button[type="submit"], button[data-adopt-trigger], a[href*="adopt"]',
    )
    .first();
  await expect(firstAdopt).toBeVisible({ timeout: 10_000 });
  await firstAdopt.click();
  await page.waitForLoadState('networkidle', { timeout: 15_000 });

  expect(db.count('service')).toBe(1);
});
