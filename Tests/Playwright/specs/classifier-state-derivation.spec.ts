/**
 * Classifier state derivation — the keystone spec for the 3-table
 * architecture.
 *
 * The BE never stores `kuratiert | erkannt | unbekannt` in the
 * `tx_t3simplecmp_detection` row. The state is derived at view
 * time by `DetectionListPresenter::deriveState()` from three inputs:
 *
 * - The detection itself (kind + identifier).
 * - The bundled service library (read via `ServicesLibrary`).
 * - The admin-curated registry (`tx_t3simplecmp_service`).
 *
 * Order matters: registry > library > unknown. This spec plants a
 * detection in each of those three classes and asserts the rendered
 * badge CSS class (locale-agnostic — the label text varies by BE
 * language, the class doesn't).
 */
import { test, expect } from '../fixtures/db.js';
import { DetectionsModule } from '../page-objects/detections-module.js';

// CSS classes set by DetectionListPresenter::decorate(). Stable across
// locales; the text inside is i18n-flipped.
const BADGE_CURATED = '.badge.bg-success';
const BADGE_RECOGNIZED = '.badge.bg-info';
const BADGE_UNKNOWN = '.badge.bg-secondary, .badge.bg-warning, .badge.bg-light';

test('detection matching a registry row renders as kuratiert', async ({ page, db }) => {
  db.insertService({
    serviceId: 'state-test-stripe',
    name: 'Stripe',
    cookies: ['__stripe_mid'],
    purposes: ['functional'],
  });
  db.insertDetection({
    source: 'state-test',
    kind: 'cookie',
    identifier: '__stripe_mid',
  });
  // The default filter is `pending` (erkannt + unbekannt); kuratiert
  // rows are hidden until the filter flips. Drive via URL so we don't
  // depend on the dropdown widget.
  const mod = new DetectionsModule(page);
  await mod.open('status=kuratiert');

  const row = mod.detectionRow('__stripe_mid');
  await expect(row).toBeVisible();
  await expect(row.locator(BADGE_CURATED)).toBeVisible();
  // Sub-label is the registry entry's display name — proves the join
  // actually carried the matched-service info through.
  await expect(row).toContainText('Stripe');
});

test('detection matching a bundled library entry renders as erkannt', async ({ page, db }) => {
  // `simplecmp/services-library` ships well-known cookies. `_ga` is
  // Google Analytics — stable bundled entry. If the library ever
  // drops it, this test fails loudly with that diagnosis.
  db.insertDetection({
    source: 'state-test',
    kind: 'cookie',
    identifier: '_ga',
  });
  const mod = new DetectionsModule(page);
  await mod.open();

  const row = mod.detectionRow('_ga');
  await expect(row).toBeVisible();
  await expect(row.locator(BADGE_RECOGNIZED)).toBeVisible();
});

test('detection with no registry + no library match renders as unbekannt', async ({ page, db }) => {
  db.insertDetection({
    source: 'state-test',
    kind: 'cookie',
    identifier: '_completely_made_up_xyz_42',
  });
  const mod = new DetectionsModule(page);
  await mod.open();

  const row = mod.detectionRow('_completely_made_up_xyz_42');
  await expect(row).toBeVisible();
  // Unknown rows use `bg-secondary` (or `bg-warning` in the older
  // styling). Match either since the choice is a cosmetic decision
  // and could legitimately change.
  await expect(row.locator(BADGE_UNKNOWN).first()).toBeVisible();
});

test('registry wins over library — same identifier matched both ways', async ({ page, db }) => {
  // Admin curates `_ga` under their own service id — the library also
  // knows `_ga` (Google Analytics). Order in deriveState() is registry
  // first, so the badge must be kuratiert (bg-success), not erkannt.
  db.insertService({
    serviceId: 'state-test-custom-ga',
    name: 'Custom Analytics',
    cookies: ['_ga'],
    purposes: ['analytics'],
  });
  db.insertDetection({
    source: 'state-test',
    kind: 'cookie',
    identifier: '_ga',
  });

  const mod = new DetectionsModule(page);
  await mod.open('status=kuratiert');

  const row = mod.detectionRow('_ga');
  await expect(row).toBeVisible();
  await expect(row.locator(BADGE_CURATED)).toBeVisible();
  await expect(row).toContainText('Custom Analytics');
});
