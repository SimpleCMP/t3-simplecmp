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
