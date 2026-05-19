import { test as base, type TestInfo } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

/**
 * DB helper fixture for BE specs.
 *
 * Resets `tx_simplecmptypo3_*` tables between specs via `ddev mysql`
 * so each spec gets a known-empty DB. Tests that need fixtures call
 * `db.insertService(...)` / `db.insertDetection(...)` explicitly.
 *
 * Schema source of truth: `ext_tables.sql`. Columns covered here
 * mirror that file. If the schema changes (3-table refactor moved
 * `status` / `matched_service` out of the detection table and onto
 * a derived view computed by ClassifierLookup), update the inserts
 * below — the BE never writes those columns directly.
 *
 * Shelling out to `ddev mysql` keeps the fixture zero-dep on the
 * network side and avoids hardcoding ddev's variable port mapping.
 */

const DEV14_DIR = path.resolve(__dirname, '../../../../../..');

function runDdevMysql(sql: string): string {
  try {
    return execFileSync('ddev', ['mysql', '-N', '-B', '-e', sql], {
      cwd: DEV14_DIR,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : String(err);
    throw new Error(`ddev mysql failed for SQL [${sql}]: ${message}`);
  }
}

export interface SimpleCmpDb {
  /** Truncate every `tx_simplecmptypo3_*` table. */
  truncateAll(): void;
  /**
   * Insert a service registry row. Returns the new uid.
   *
   * `cookies`/`origins`/`purposes` are stored as JSON in the registry
   * table (see `ServiceRepository::buildRowForInsert()`). Pass arrays
   * here — the fixture JSON-encodes for you so the classifier's
   * matcher can decode them.
   */
  insertService(row: {
    serviceId: string;
    name?: string;
    vendor?: string;
    purposes?: string[];
    cookies?: string[];
    origins?: string[];
    privacyPolicyUrl?: string;
    description?: string;
    pid?: number;
  }): number;
  /** Insert a detection log row. Returns the new uid. */
  insertDetection(row: {
    source: string;
    kind: string;
    identifier: string;
    origin?: string;
    pageUrl?: string;
    pid?: number;
  }): number;
  /** Count rows in a `tx_simplecmptypo3_*` table. */
  count(table: 'service' | 'detection'): number;
  /** Raw SQL escape-hatch for assertions. Returns tab-separated rows. */
  query(sql: string): string;
}

function escape(value: string | number | undefined | null): string {
  if (value === null || value === undefined) return 'NULL';
  if (typeof value === 'number') return String(value);
  return `'${value.replace(/'/g, "''")}'`;
}

export const test = base.extend<{ db: SimpleCmpDb }>({
  db: async ({}, use, _testInfo: TestInfo) => {
    const db: SimpleCmpDb = {
      truncateAll() {
        runDdevMysql(
          'SET FOREIGN_KEY_CHECKS=0; ' +
            'TRUNCATE TABLE tx_simplecmptypo3_service; ' +
            'TRUNCATE TABLE tx_simplecmptypo3_detection; ' +
            'SET FOREIGN_KEY_CHECKS=1;',
        );
      },
      insertService(row) {
        const pid = row.pid ?? 0;
        const now = Math.floor(Date.now() / 1000);
        // JSON-encode array fields. The repository decodes them via
        // `json_decode(..., JSON_THROW_ON_ERROR)` and a non-JSON value
        // ends up as `null`, silently disabling matchers.
        const purposesJson = JSON.stringify(row.purposes ?? ['functional']);
        const cookiesJson = JSON.stringify(row.cookies ?? []);
        const originsJson = JSON.stringify(row.origins ?? []);
        const sql =
          'INSERT INTO tx_simplecmptypo3_service ' +
          '(pid, tstamp, crdate, service_id, name, vendor, purposes, ' +
          'privacy_policy_url, description, cookies, origins) VALUES (' +
          [
            pid,
            now,
            now,
            escape(row.serviceId),
            escape(row.name ?? row.serviceId),
            escape(row.vendor ?? ''),
            escape(purposesJson),
            escape(row.privacyPolicyUrl ?? ''),
            escape(row.description ?? ''),
            escape(cookiesJson),
            escape(originsJson),
          ].join(', ') +
          '); SELECT LAST_INSERT_ID();';
        const out = runDdevMysql(sql);
        const uid = Number(out.trim().split(/\s+/).pop());
        if (!Number.isFinite(uid) || uid <= 0) {
          throw new Error(`insertService: could not parse LAST_INSERT_ID from [${out}]`);
        }
        return uid;
      },
      insertDetection(row) {
        const pid = row.pid ?? 0;
        const now = Math.floor(Date.now() / 1000);
        const sql =
          'INSERT INTO tx_simplecmptypo3_detection ' +
          '(pid, tstamp, crdate, source, kind, identifier, origin, ' +
          'page_url, first_seen, last_seen, received_at, occurrences, payload) VALUES (' +
          [
            pid,
            now,
            now,
            escape(row.source),
            escape(row.kind),
            escape(row.identifier),
            escape(row.origin ?? ''),
            escape(row.pageUrl ?? ''),
            now,
            now,
            now,
            1,
            escape('{}'),
          ].join(', ') +
          '); SELECT LAST_INSERT_ID();';
        const out = runDdevMysql(sql);
        const uid = Number(out.trim().split(/\s+/).pop());
        if (!Number.isFinite(uid) || uid <= 0) {
          throw new Error(`insertDetection: could not parse LAST_INSERT_ID from [${out}]`);
        }
        return uid;
      },
      count(table) {
        const fullName = `tx_simplecmptypo3_${table}`;
        const out = runDdevMysql(`SELECT COUNT(*) FROM ${fullName}`);
        return Number(out.trim());
      },
      query(sql) {
        return runDdevMysql(sql);
      },
    };
    db.truncateAll();
    await use(db);
  },
});

export { expect } from '@playwright/test';
