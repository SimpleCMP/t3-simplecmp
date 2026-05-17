CREATE TABLE tx_simplecmptypo3_service (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    service_id varchar(100) DEFAULT '' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    vendor varchar(255) DEFAULT NULL,
    vendor_country varchar(8) DEFAULT NULL,
    purposes text,
    privacy_policy_url varchar(500) DEFAULT NULL,
    description text,
    retention text,
    i18n text,
    cookies text,
    origins text,
    extensions text,

    PRIMARY KEY (uid),
    UNIQUE KEY service_id (service_id),
    KEY pid (pid)
);

CREATE TABLE tx_simplecmptypo3_theme (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    site varchar(100) DEFAULT '' NOT NULL,
    tokens text,

    PRIMARY KEY (uid),
    UNIQUE KEY site (site)
);

CREATE TABLE tx_simplecmptypo3_detection (
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

    PRIMARY KEY (uid),
    KEY pid (pid),
    KEY dedup_key (source, kind, identifier(191)),
    KEY received_at (received_at)
);
