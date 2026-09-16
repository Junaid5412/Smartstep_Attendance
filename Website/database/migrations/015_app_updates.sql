-- 015_app_updates.sql
--
-- In-app updates. Run on the live database.
--
-- min_app_version already existed and was already reported to the app — but nothing
-- enforced it, so "force" was advertised and not real. Enforcement is in
-- attAuthenticate(); these columns are what it needs to point an outdated app at.

ALTER TABLE `att_settings`
    ADD COLUMN `latest_app_version` VARCHAR(30) DEFAULT NULL AFTER `min_app_version`,
    ADD COLUMN `latest_apk_file` VARCHAR(255) DEFAULT NULL AFTER `latest_app_version`,
    ADD COLUMN `release_notes` VARCHAR(1000) DEFAULT NULL AFTER `latest_apk_file`,
    ADD COLUMN `release_published_at` DATETIME DEFAULT NULL AFTER `release_notes`;
