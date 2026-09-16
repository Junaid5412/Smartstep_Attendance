-- ============================================================================
-- Real-time geofence breach detection.
--
-- Recording position every few minutes is right for a route, but far too slow to
-- warn someone they have just left their work area. This adds a separate, much
-- faster watch used only for breach detection — the recording interval is
-- unchanged, so the route table does not grow by a factor of thirty.
-- ============================================================================

ALTER TABLE `att_settings`
  ADD COLUMN `geofence_watch_seconds` SMALLINT NOT NULL DEFAULT 10
      COMMENT 'how often the device checks whether it has left the area; 0 disables',
  ADD COLUMN `alert_sound` TINYINT(1) NOT NULL DEFAULT 1
      COMMENT 'play a sound with the leaving-the-area warning';
