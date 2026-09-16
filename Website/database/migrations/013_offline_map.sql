-- 013_offline_map.sql
--
-- Offline map settings.
--
-- protomaps_api_key is used once, server-side, to download a map style. The app never
-- sees it: the style and the tiles are both served from this server, so the key stays
-- in one place instead of being compiled into every APK — where it could not be
-- revoked without shipping a new build.
--
-- offline_map_file names which archive to serve, so a higher-detail extract can be
-- swapped in by dropping the file in uploads/mappacks/ and changing this value.

ALTER TABLE `att_settings`
    ADD COLUMN `protomaps_api_key` VARCHAR(255) NOT NULL DEFAULT '' AFTER `google_api_key`,
    ADD COLUMN `offline_map_file` VARCHAR(100) NOT NULL DEFAULT 'qatar.pmtiles' AFTER `protomaps_api_key`;
