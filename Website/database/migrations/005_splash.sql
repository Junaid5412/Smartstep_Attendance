-- ============================================================================
-- Splash screen duration.
--
-- Held server-side so the branding of the app can be tuned without a release,
-- alongside the logo and display name it belongs with.
-- ============================================================================

ALTER TABLE `att_settings`
  ADD COLUMN `splash_seconds` DECIMAL(3,1) NOT NULL DEFAULT 3.0
      COMMENT 'minimum time the branded loading screen is shown, in seconds';
