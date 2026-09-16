-- ============================================================================
-- Noise guard for geofence exit detection.
--
-- A single position report outside the fence used to open a trip. With indoor
-- fixes running at ±100 m accuracy against a fence of comparable radius, GPS drift
-- alone can place a stationary employee outside — so trips were opened that never
-- happened, employees were asked to explain journeys they never took, and the
-- review queue filled with noise that hides the real cases.
-- ============================================================================

ALTER TABLE `att_settings`
  ADD COLUMN `outside_confirm_points` TINYINT NOT NULL DEFAULT 2
      COMMENT 'consecutive confident-outside fixes needed before a trip opens';
