-- ============================================================================
-- App branding.
--
-- The logo and name shown in the mobile app. Both are optional overrides: left
-- empty, the app falls back to company_settings, so the ERP stays the single place
-- a company's identity is maintained and nobody has to upload the same logo twice.
-- ============================================================================

ALTER TABLE `att_settings`
  ADD COLUMN `app_logo` VARCHAR(255) DEFAULT NULL
      COMMENT 'path under Attendance/Website/uploads; NULL = use company_settings.company_logo',
  ADD COLUMN `app_display_name` VARCHAR(120) DEFAULT NULL
      COMMENT 'name shown in the app header; NULL = use company_settings.company_name';
