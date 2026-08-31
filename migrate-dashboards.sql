-- Migration: Add bwOutdatedPagesList widget to all existing user dashboards
-- Skips dashboards that already have the widget and Direct Mail dashboards.
--
-- Run on live: ddev exec mysql -u db -pdb db < packages/bw_outdated_pages/migrate-dashboards.sql

UPDATE be_dashboards
SET
    widgets = JSON_SET(
        widgets,
        CONCAT('$."', SHA1(CONCAT(uid, UUID())), '"'),
        JSON_OBJECT('identifier', 'bwOutdatedPagesList')
    ),
    tstamp = UNIX_TIMESTAMP()
WHERE deleted = 0
  AND title != 'Direct Mail - Dashboard'
  AND (widgets IS NULL OR JSON_SEARCH(widgets, 'one', 'bwOutdatedPagesList') IS NULL);
