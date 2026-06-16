CREATE TABLE tx_t3simplecmp_service (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    service_id varchar(100) DEFAULT '' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    vendor varchar(255) DEFAULT NULL,
    vendor_country varchar(8) DEFAULT NULL,
    vendor_address text,
    vendor_opt_out_url varchar(500) DEFAULT NULL,
    vendor_partner text,
    vendor_description text,
    purposes text,
    privacy_policy_url varchar(500) DEFAULT NULL,
    description text,
    retention text,
    i18n text,
    cookies text,
    origins text,
    extensions text,
    placeholder_title varchar(255) DEFAULT NULL,
    placeholder_description text,
    library_adopted_at int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY service_id (service_id),
    KEY pid (pid),
    KEY library_adopted_at (library_adopted_at)
);

CREATE TABLE tx_t3simplecmp_theme (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    site varchar(100) DEFAULT '' NOT NULL,
    tokens text,

    PRIMARY KEY (uid),
    UNIQUE KEY site (site)
);

CREATE TABLE tx_t3simplecmp_translation_override (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    site varchar(100) DEFAULT '' NOT NULL,
    overrides text,

    PRIMARY KEY (uid),
    UNIQUE KEY site (site)
);

CREATE TABLE tx_t3simplecmp_managed_tracker (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    site varchar(100) DEFAULT '' NOT NULL,
    tracker_type varchar(50) DEFAULT '' NOT NULL,
    service_id varchar(100) DEFAULT '' NOT NULL,
    config text,

    PRIMARY KEY (uid),
    KEY site (site),
    KEY tracker_type (tracker_type),
    KEY deleted (deleted)
);

CREATE TABLE tx_t3simplecmp_library_cache (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    query_type varchar(16) DEFAULT '' NOT NULL,
    query_value varchar(255) DEFAULT '' NOT NULL,
    response_json mediumtext,
    fetched_at int(11) unsigned DEFAULT '0' NOT NULL,
    expires_at int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY query_key (query_type, query_value),
    KEY expires_at (expires_at)
);

CREATE TABLE tx_t3simplecmp_detection (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    source varchar(100) DEFAULT '' NOT NULL,
    kind varchar(32) DEFAULT '' NOT NULL,
    identifier varchar(500) DEFAULT '' NOT NULL,
    origin varchar(255) DEFAULT NULL,
    page_url varchar(500) DEFAULT NULL,
    first_seen_on varchar(500) DEFAULT NULL,
    sent_at varchar(40) DEFAULT NULL,
    first_seen bigint(20) DEFAULT NULL,
    last_seen bigint(20) DEFAULT NULL,
    received_at int(11) unsigned DEFAULT '0' NOT NULL,
    occurrences int(11) unsigned DEFAULT '1' NOT NULL,
    library_version varchar(40) DEFAULT NULL,
    user_agent varchar(500) DEFAULT NULL,
    referrer varchar(500) DEFAULT NULL,
    payload text,
    dismissed_at int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY pid (pid),
    -- UNIQUE on the full triple so the ingest upsert can't race two POSTs
    -- into duplicate rows. Full-length (not a 191 prefix): a prefix would
    -- collide distinct long identifiers and the catch-fallback UPDATE keys on
    -- the full identifier. (100+32+500)*4 = 2528 bytes < InnoDB's 3072 limit.
    UNIQUE KEY dedup_key (source, kind, identifier),
    KEY received_at (received_at),
    KEY dismissed_at (dismissed_at)
);

-- Admin-allowed third-party stylesheet hosts (REQ-N8 Phase C2). When
-- `blockStylesheets` is on, a host listed here has its `rel="stylesheet"`
-- passed through (loaded normally) — but only its stylesheets: scripts /
-- iframes from the same host are still gated by universal blocking.
-- Keyed by `source` (= DiscoverSource::forSite() = the site's storageName),
-- the same value stored on discover-recorded detections, so the BE "allow"
-- action (from a blocked-stylesheet row) and the HtmlRewriter agree on the
-- key. Distinct from the host-wide `universalBlocking.allowlist` setting
-- (which passes ALL resource types).
CREATE TABLE tx_t3simplecmp_allowed_stylesheet_host (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    source varchar(100) DEFAULT '' NOT NULL,
    host varchar(255) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY source_host (source, host)
);

-- Append-only audit snapshots of the resolved banner configuration
-- (services + theme + translation overrides + relevant Site Settings).
-- One row is written each time the editor saves a change to any of the
-- three source tables, or via the CLI command for YAML-only edits.
-- Identical content produces the same `version_hash` (sha256 of the
-- canonicalised JSON) so the UNIQUE constraint deduplicates no-op
-- saves into a single historical entry.
--
-- Append-only is enforced via TCA (`readOnly` + `hideTable`) plus
-- DataHandler hooks that refuse update/delete via the BE editor API.
-- Direct SQL DELETE remains possible by design — production retention
-- is an explicit Phase-3 CLI workflow, not a hidden database trigger.
CREATE TABLE tx_t3simplecmp_config_snapshot (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    site varchar(64) DEFAULT '' NOT NULL,
    version_hash char(64) DEFAULT '' NOT NULL,
    canonical_json mediumtext,
    trigger_event varchar(64) DEFAULT '' NOT NULL,
    creator_be_user int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY site_version (site, version_hash),
    KEY by_site_date (site, crdate),
    KEY version (version_hash)
);

-- Append-only visitor consent decisions (Phase 2 of the audit trail).
-- Each row records a single accept/decline/save-selected click bound to:
--   * the snapshot `version_hash` that was active at decision time
--     (soft FK → tx_t3simplecmp_config_snapshot.version_hash);
--   * the pseudonymized visitor identifier (sha256(uuid || bridge_secret)),
--     so the same visitor's repeated identical decisions deduplicate;
--   * the canonical-hash of the decisions map (sha256 of the sorted JSON),
--     so the editor can prove the EXACT decision payload that was given.
--
-- UNIQUE on (site, visitor_id_sha256, version_hash, decision_hash) drops
-- no-op re-confirmations into a single row. Genuinely changed decisions
-- (visitor flipped accept → decline) produce a new row because the
-- decision_hash differs.
--
-- Editor-level append-only is enforced by TCA `readOnly`/`hideTable`
-- plus the EnforceConsentLogAppendOnly DataHandler hook. Direct SQL
-- DELETE remains possible by design — Phase-3 ships an explicit CLI
-- retention workflow with its own audit log.
-- Append-only self-audit log of retention actions (Phase 3). Every
-- invocation of `simplecmp:audit-retention` — successful or dry-run —
-- writes one row per (target_table, target_site) it acted on. The row
-- records what was deleted, when, by whom, and the operator's reason
-- text (mandatory CLI flag). This is the DSGVO Art. 5 (1) (e)
-- "Speicherbegrenzung" audit surface: if a consent_log or snapshot row
-- vanishes, this log explains why.
--
-- Genuinely append-only: this table itself MUST NOT be touched by the
-- retention command (the command refuses `--target` values that would
-- include it), and editor-level mutations are blocked the same way as
-- Phase 1+2. Direct SQL DELETE still works — same trade-off as the
-- other audit tables: silent triggers would surprise migrations and
-- restores; explicit operator discipline + this log's visibility in
-- the BE Auskunfts-tab is the intended deterrent.
CREATE TABLE tx_t3simplecmp_audit_retention_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    target_table varchar(64) DEFAULT '' NOT NULL,
    target_site varchar(64) DEFAULT '' NOT NULL,
    rows_deleted int(11) unsigned DEFAULT '0' NOT NULL,
    keep_days int(11) unsigned DEFAULT '0' NOT NULL,
    oldest_kept_crdate int(11) unsigned DEFAULT '0' NOT NULL,
    invoked_by varchar(64) DEFAULT '' NOT NULL,
    invocation_reason text,
    dry_run tinyint(1) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY by_table_date (target_table, crdate),
    KEY by_date (crdate)
);

CREATE TABLE tx_t3simplecmp_consent_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    site varchar(64) NOT NULL DEFAULT '',
    version_hash char(64) NOT NULL DEFAULT '',
    visitor_id_sha256 char(64) NOT NULL DEFAULT '',
    decision_hash char(64) NOT NULL DEFAULT '',
    decisions_json text,
    decision_type varchar(32) NOT NULL DEFAULT '',
    ua_family varchar(32) DEFAULT NULL,
    page_url_host varchar(255) DEFAULT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY visitor_version_decision (site, visitor_id_sha256, version_hash, decision_hash),
    KEY by_version (version_hash),
    KEY by_visitor (visitor_id_sha256, site),
    KEY by_date (crdate),
    KEY by_site_date (site, crdate)
);
