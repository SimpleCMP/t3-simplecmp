import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

/**
 * Playwright config for the simplecmp BE suite.
 *
 * Drives the real TYPO3 v14 backend against a local ddev instance.
 * Mutates the database — each test resets `tx_t3simplecmp_*` tables
 * via the `db` fixture in `fixtures/db.ts` so specs are independent.
 *
 * Auth: a single login at startup writes a Playwright `storageState`
 * (cookies + localStorage). Test workers reuse the state so we don't
 * hammer the BE login form for every spec.
 *
 * Run with `pnpm test:be` from this extension's repo root. The
 * `BE_URL` and `BE_USER`/`BE_PASSWORD` env vars override defaults so
 * the same config can run against staging without code changes.
 */
const BE_URL = process.env.BE_URL ?? 'https://dev14.ddev.site';

export default defineConfig({
  testDir: './specs',
  testMatch: '**/*.spec.ts',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  // BE tests share the database — running in parallel would step on
  // each other's `tx_t3simplecmp_*` rows. One worker, fully serial.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: BE_URL,
    // The dev14 cert is locally signed by ddev's mkcert root. Skip
    // strict verification so Playwright doesn't refuse to talk to it
    // (alternative: install the ddev cert into the system trust store).
    ignoreHTTPSErrors: true,
    storageState: path.resolve(__dirname, 'fixtures/be-storage-state.json'),
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  // Authenticate once before any spec runs — the resulting storage
  // state is written to disk and reused.
  globalSetup: require.resolve('./fixtures/global-setup'),
});
