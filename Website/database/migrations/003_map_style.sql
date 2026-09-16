-- ============================================================================
-- Map styling.
--
-- The tiles stay OpenStreetMap — free, no key, no third-party terms to breach —
-- but a colour transform is applied so they read like a modern Google basemap
-- rather than OSM's default beige-and-green. The transform is stored rather than
-- hardcoded so it can be retuned without a release, and it is served to the app
-- alongside the tile URL so the phone and the panel look identical.
-- ============================================================================

ALTER TABLE `att_settings`
  ADD COLUMN `map_style` VARCHAR(30) NOT NULL DEFAULT 'google_like'
      COMMENT 'osm_raw | google_like | google_light | custom',
  ADD COLUMN `tile_filter_css` VARCHAR(255) DEFAULT NULL
      COMMENT 'CSS filter applied to tiles; NULL means use the preset for map_style';
