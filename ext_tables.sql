CREATE TABLE tx_bwoutdatedpages_review (
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,
    deleted smallint(5) unsigned DEFAULT 0 NOT NULL,

    page_uid int(11) DEFAULT 0 NOT NULL,
    language_tag varchar(16) DEFAULT '' NOT NULL,
    reviewed_at int(11) unsigned DEFAULT 0 NOT NULL,
    reviewed_by int(11) unsigned DEFAULT 0 NOT NULL,

    KEY page_language (page_uid, language_tag)
);
