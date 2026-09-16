-- ============================================================================
-- Google Maps Platform tile support.
--
-- Adds a provider switch to att_settings so the panel and the app can be pointed
-- at Google's Map Tiles API instead of OpenStreetMap. The session token Google
-- requires is cached here rather than re-created per page load: createSession is
-- a billable call and the token is valid for roughly two weeks.
-- ============================================================================

ALTER TABLE `att_settings`
  ADD COLUMN `map_provider` ENUM('osm','google') NOT NULL DEFAULT 'osm'
      COMMENT 'which tile source the panel and app use',
  ADD COLUMN `google_api_key` VARCHAR(255) DEFAULT NULL
      COMMENT 'Google Maps Platform key with Map Tiles API enabled',
  ADD COLUMN `google_map_type` ENUM('roadmap','satellite','terrain') NOT NULL DEFAULT 'roadmap',
  ADD COLUMN `google_language` VARCHAR(15) NOT NULL DEFAULT 'en'
      COMMENT 'label language; en keeps English labels over Arabic place names',
  ADD COLUMN `google_region` VARCHAR(5) NOT NULL DEFAULT 'QA',
  ADD COLUMN `google_session_token` VARCHAR(600) DEFAULT NULL,
  ADD COLUMN `google_session_expires_at` DATETIME DEFAULT NULL,
  ADD COLUMN `google_session_error` VARCHAR(400) DEFAULT NULL
      COMMENT 'last createSession failure, surfaced in the settings page';
