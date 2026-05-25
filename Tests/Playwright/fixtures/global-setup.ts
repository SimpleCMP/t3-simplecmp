import { chromium, type FullConfig } from '@playwright/test';
import path from 'node:path';

/**
 * Authenticates against the TYPO3 v14 backend once per test run and
 * writes the resulting cookies + localStorage to
 * `fixtures/be-storage-state.json`. The Playwright config's
 * `use.storageState` then loads that snapshot for every spec — so
 * individual tests start already logged in.
 *
 * Login is required because the t3_simplecmp module is a BE-only
 * module. Without a session cookie the modules render the login form
 * and every spec would have to re-authenticate.
 */
async function globalSetup(config: FullConfig): Promise<void> {
  const beUrl = process.env.BE_URL ?? 'https://dev14.ddev.site';
  // Dedicated test admin created via `ddev exec vendor/bin/typo3
  // backend:createadmin -n simplecmp-pw simplecmp-pw-password`. This
  // user is local-only and has no production parallel — keeping its
  // credentials hardcoded is fine, and far cleaner than depending on
  // whatever the developer's personal admin password happens to be.
  const user = process.env.BE_USER ?? 'simplecmp-pw';
  const password = process.env.BE_PASSWORD ?? 'simplecmp-pw-password';
  const storagePath = path.resolve(__dirname, 'be-storage-state.json');

  const browser = await chromium.launch();
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();

  await page.goto(`${beUrl}/typo3/`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="username"]').fill(user);
  // TYPO3 v14: the visible input is `p_field`; a small JS handler
  // copies/derives its value into the hidden `userident` on submit.
  await page.locator('input[name="p_field"]').fill(password);
  await Promise.all([
    page.waitForURL(
      (url) => url.pathname.startsWith('/typo3/') && !url.pathname.endsWith('/typo3/login'),
      { timeout: 30_000 },
    ),
    page.locator('button[type="submit"], input[type="submit"]').first().click(),
  ]);
  // Confirm we're past the login form. `#typo3-login-form` is the
  // login wrapper; if it's gone, we authenticated. Probing for it
  // negative-side is more reliable than guessing at the BE chrome
  // markup, which shifted between v13 and v14.
  await page.waitForFunction(
    () => document.getElementById('typo3-login-form') === null,
    null,
    { timeout: 15_000 },
  );

  await context.storageState({ path: storagePath });
  await browser.close();
  // Reference the config arg so TS doesn't complain about it being
  // unused; future setup steps (e.g. seeding fixtures) will read it.
  void config;
}

export default globalSetup;
