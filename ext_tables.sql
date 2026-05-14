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
