-- ============================================================================
-- SST Attendance - Database Schema
-- Lives inside the existing sstqa database; every employee row links back to
-- hr_employees so HR/payroll modules can consume attendance without a sync job.
-- All tables are prefixed att_ to keep them clearly separated from ERP tables.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Geofences: a named area, either a circle (center + radius) or a polygon.
-- Polygons are stored as a JSON array of [lat, lng] pairs so the same payload
-- can be handed to Leaflet on the web and to the Flutter app for offline checks.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_geofences` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `type` ENUM('circle','polygon') NOT NULL DEFAULT 'circle',
  `center_lat` DECIMAL(10,7) DEFAULT NULL,
  `center_lng` DECIMAL(10,7) DEFAULT NULL,
  `radius_m` INT(11) DEFAULT NULL COMMENT 'circle radius in metres',
  `polygon` JSON DEFAULT NULL COMMENT '[[lat,lng],...] for type=polygon',
  `color` VARCHAR(20) DEFAULT '#2563eb',
  `project_id` INT(11) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- App accounts. One row per employee who is allowed to use the mobile app.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_employees` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `employee_id` INT(11) NOT NULL COMMENT 'FK hr_employees.id',
  `login_code` VARCHAR(50) NOT NULL COMMENT 'what the employee types in the app',
  password VARCHAR(255) NOT NULL,
  initial_password_cipher VARCHAR(512) DEFAULT NULL COMMENT 'Encrypted generated credential',
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME DEFAULT NULL,
  `consent_accepted_at` DATETIME DEFAULT NULL COMMENT 'location-tracking disclosure',
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login_code` (`login_code`),
  UNIQUE KEY `uq_employee` (`employee_id`),
  CONSTRAINT `fk_att_emp_hr` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Per-employee attendance rules: which area, which shift, how often to ping.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_employee_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` INT(11) NOT NULL,
  `geofence_id` INT(11) DEFAULT NULL,
  `shift_start` TIME NOT NULL DEFAULT '08:00:00',
  `shift_end` TIME NOT NULL DEFAULT '17:00:00',
  `overnight_shift` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'shift_end falls next day',
  `work_days` VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6' COMMENT 'ISO days 1=Mon..7=Sun',
  `tracking_interval_min` INT(11) NOT NULL DEFAULT 10 COMMENT 'location ping interval',
  `late_grace_min` INT(11) NOT NULL DEFAULT 15,
  -- How long after the shift ends the employee can still check out. Overtime is
  -- normal, so the day cannot close on the clock; past this it is flagged instead
  -- of blocking every future check-in.
  `max_overtime_hours` INT(11) NOT NULL DEFAULT 6,
  `require_photo` TINYINT(1) NOT NULL DEFAULT 1,
  `enforce_geofence` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = allow check-in anywhere but flag it',
  -- Off by default: refusing a check-out because someone finished away from site only
  -- leaves the shift open for an admin to correct by hand. Either way the position is
  -- recorded and flagged for review.
  `enforce_geofence_checkout` TINYINT(1) NOT NULL DEFAULT 0,
  `max_accuracy_m` INT(11) NOT NULL DEFAULT 50 COMMENT 'reject GPS fixes worse than this',
  `allow_mock_location` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_employee` (`att_employee_id`),
  KEY `idx_geofence` (`geofence_id`),
  CONSTRAINT `fk_att_set_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_att_set_fence` FOREIGN KEY (`geofence_id`) REFERENCES `att_geofences` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- One-device login. device_uid is the app's persistent fingerprint; a second
-- device is refused while an active binding exists until an admin resets it.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_devices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` INT(11) NOT NULL,
  `device_uid` VARCHAR(128) NOT NULL COMMENT 'persistent composite id from the app',
  `device_model` VARCHAR(150) DEFAULT NULL,
  `device_brand` VARCHAR(100) DEFAULT NULL,
  `os_version` VARCHAR(50) DEFAULT NULL,
  `app_version` VARCHAR(30) DEFAULT NULL,
  `platform` ENUM('android','ios') NOT NULL DEFAULT 'android',
  `fcm_token` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active','reset','blocked') NOT NULL DEFAULT 'active',
  `bound_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME DEFAULT NULL,
  `released_at` DATETIME DEFAULT NULL,
  -- Only one active binding per employee: the generated column collapses to the
  -- employee id while active and to NULL otherwise, so the unique key below lets
  -- the database itself refuse a second active device rather than trusting code.
  `active_lock` INT(11)
    GENERATED ALWAYS AS (CASE WHEN `status` = 'active' THEN `att_employee_id` ELSE NULL END) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_one_active_device` (`active_lock`),
  KEY `idx_emp_status` (`att_employee_id`,`status`),
  KEY `idx_device_uid` (`device_uid`),
  CONSTRAINT `fk_att_dev_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `att_device_resets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` INT(11) NOT NULL,
  `device_id` INT(11) DEFAULT NULL,
  `old_device_uid` VARCHAR(128) DEFAULT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `reset_by` INT(11) DEFAULT NULL COMMENT 'users.id',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_emp` (`att_employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- App sessions. Tokens are device-bound: a token is invalid if the device
-- binding it was issued under is no longer the active one.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_tokens` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` INT(11) NOT NULL,
  `device_id` INT(11) NOT NULL,
  `token_hash` CHAR(64) NOT NULL COMMENT 'sha256 of the bearer token',
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `idx_emp` (`att_employee_id`),
  CONSTRAINT `fk_att_tok_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_att_tok_dev` FOREIGN KEY (`device_id`) REFERENCES `att_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- The attendance register: one row per employee per working day.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_attendance` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` INT(11) NOT NULL,
  `employee_id` INT(11) NOT NULL COMMENT 'denormalised hr_employees.id for reporting',
  `work_date` DATE NOT NULL,
  `geofence_id` INT(11) DEFAULT NULL COMMENT 'area assigned at the time of check-in',

  `check_in_at` DATETIME DEFAULT NULL,
  `check_in_lat` DECIMAL(10,7) DEFAULT NULL,
  `check_in_lng` DECIMAL(10,7) DEFAULT NULL,
  `check_in_accuracy_m` DECIMAL(8,2) DEFAULT NULL,
  `check_in_address` VARCHAR(255) DEFAULT NULL,
  `check_in_photo` VARCHAR(255) DEFAULT NULL,
  `check_in_inside_fence` TINYINT(1) DEFAULT NULL,
  `check_in_device_id` INT(11) DEFAULT NULL,

  `check_out_at` DATETIME DEFAULT NULL,
  `check_out_lat` DECIMAL(10,7) DEFAULT NULL,
  `check_out_lng` DECIMAL(10,7) DEFAULT NULL,
  `check_out_accuracy_m` DECIMAL(8,2) DEFAULT NULL,
  `check_out_address` VARCHAR(255) DEFAULT NULL,
  `check_out_photo` VARCHAR(255) DEFAULT NULL,
  `check_out_inside_fence` TINYINT(1) DEFAULT NULL,
  `check_out_device_id` INT(11) DEFAULT NULL,

  `worked_minutes` INT(11) DEFAULT NULL,
  `outside_minutes` INT(11) NOT NULL DEFAULT 0 COMMENT 'total time outside the fence',
  `late_minutes` INT(11) NOT NULL DEFAULT 0,
  `early_leave_minutes` INT(11) NOT NULL DEFAULT 0,
  -- Worked beyond the shift *length*, so a late start followed by a late finish is
  -- not credited as overtime. Stored, not derived, so reassigning a shift later
  -- does not retroactively rewrite days already worked.
  `overtime_minutes` INT(11) NOT NULL DEFAULT 0,
  `status` ENUM('present','late','half-day','absent','incomplete',
                'missing-checkout','holiday','leave')
      NOT NULL DEFAULT 'incomplete',
  `source` ENUM('app','manual') NOT NULL DEFAULT 'app',
  `admin_note` VARCHAR(500) DEFAULT NULL,
  `edited_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_date` (`att_employee_id`,`work_date`),
  KEY `idx_date` (`work_date`),
  KEY `idx_employee_date` (`employee_id`,`work_date`),
  CONSTRAINT `fk_att_att_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Raw route points captured during the shift. This is the high-volume table.
-- client_uid lets the app retry a batch without creating duplicates.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_location_logs` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `att_employee_id` INT(11) NOT NULL,
  `attendance_id` INT(11) DEFAULT NULL,
  `work_date` DATE NOT NULL,
  `lat` DECIMAL(10,7) NOT NULL,
  `lng` DECIMAL(10,7) NOT NULL,
  `accuracy_m` DECIMAL(8,2) DEFAULT NULL,
  `altitude_m` DECIMAL(8,2) DEFAULT NULL,
  `speed_kmh` DECIMAL(6,2) DEFAULT NULL,
  `heading` DECIMAL(6,2) DEFAULT NULL,
  `battery_pct` TINYINT(4) DEFAULT NULL,
  `is_charging` TINYINT(1) DEFAULT NULL,
  `is_mock` TINYINT(1) NOT NULL DEFAULT 0,
  `inside_fence` TINYINT(1) DEFAULT NULL,
  -- Which assigned area contained this point; null when outside them all.
  `matched_geofence_id` INT(11) DEFAULT NULL,
  `distance_from_fence_m` INT(11) DEFAULT NULL COMMENT '0 when inside',
  `provider` VARCHAR(30) DEFAULT NULL,
  `recorded_at` DATETIME NOT NULL COMMENT 'device clock at capture',
  `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'server receipt',
  `client_uid` VARCHAR(64) DEFAULT NULL COMMENT 'app-side unique id, idempotency key',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_uid` (`att_employee_id`,`client_uid`),
  KEY `idx_emp_date` (`att_employee_id`,`work_date`),
  KEY `idx_recorded` (`recorded_at`),
  KEY `idx_attendance` (`attendance_id`),
  CONSTRAINT `fk_att_loc_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Geofence exit/entry episodes. One row opens on exit and closes on re-entry,
-- carrying the employee's stated reason and the admin's verdict.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_geofence_events` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` INT(11) NOT NULL,
  `attendance_id` INT(11) DEFAULT NULL,
  `geofence_id` INT(11) DEFAULT NULL,
  `work_date` DATE NOT NULL,
  `exit_at` DATETIME NOT NULL,
  `exit_lat` DECIMAL(10,7) DEFAULT NULL,
  `exit_lng` DECIMAL(10,7) DEFAULT NULL,
  `entry_at` DATETIME DEFAULT NULL COMMENT 'NULL while still outside',
  `entry_lat` DECIMAL(10,7) DEFAULT NULL,
  `entry_lng` DECIMAL(10,7) DEFAULT NULL,
  `duration_min` INT(11) DEFAULT NULL,
  `max_distance_m` INT(11) DEFAULT NULL COMMENT 'furthest point from the fence',
  `farthest_lat` DECIMAL(10,7) DEFAULT NULL,
  `farthest_lng` DECIMAL(10,7) DEFAULT NULL,
  `farthest_address` VARCHAR(255) DEFAULT NULL,
  `category` ENUM('unspecified','company_work','client_visit','personal','break','transit','other')
      NOT NULL DEFAULT 'unspecified',
  `employee_reason` VARCHAR(500) DEFAULT NULL,
  `reason_submitted_at` DATETIME DEFAULT NULL,
  `review_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` INT(11) DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `review_note` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_emp_date` (`att_employee_id`,`work_date`),
  KEY `idx_open` (`att_employee_id`,`entry_at`),
  KEY `idx_review` (`review_status`),
  CONSTRAINT `fk_att_ev_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Global module settings (single row) and an audit trail for admin actions.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `default_shift_start` TIME NOT NULL DEFAULT '08:00:00',
  `default_shift_end` TIME NOT NULL DEFAULT '17:00:00',
  `default_interval_min` INT(11) NOT NULL DEFAULT 10,
  `default_radius_m` INT(11) NOT NULL DEFAULT 150,
  `default_max_accuracy_m` INT(11) NOT NULL DEFAULT 50,
  `token_lifetime_days` INT(11) NOT NULL DEFAULT 30,
  `photo_max_kb` INT(11) NOT NULL DEFAULT 1024,
  `tile_url` VARCHAR(255) NOT NULL DEFAULT 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  `tile_attribution` VARCHAR(255) NOT NULL DEFAULT '© OpenStreetMap contributors',
  `min_app_version` VARCHAR(20) DEFAULT NULL COMMENT 'force-update gate',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `att_settings` (`id`) SELECT 1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `att_settings` WHERE `id` = 1);

CREATE TABLE IF NOT EXISTS `att_audit_logs` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `actor_type` ENUM('admin','employee','system') NOT NULL DEFAULT 'admin',
  `actor_id` INT(11) DEFAULT NULL,
  `action` VARCHAR(60) NOT NULL,
  `entity` VARCHAR(60) DEFAULT NULL,
  `entity_id` INT(11) DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity` (`entity`,`entity_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;


-- ---------------------------------------------------------------------------
-- Extra work areas per employee.
--
-- att_employee_settings.geofence_id remains the primary area (what a check-in is
-- stamped with, and what the panel shows as their base). This table adds the rest:
-- "inside" means inside ANY assigned area, so somebody rostered across two offices
-- is at work in either instead of being asked to justify being at their own site.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `att_employee_geofences` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` INT(11) NOT NULL,
  `geofence_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Assigning the same area twice would double-count it in every lookup.
  UNIQUE KEY `uq_employee_fence` (`att_employee_id`, `geofence_id`),
  KEY `idx_fence` (`geofence_id`),
  CONSTRAINT `fk_aeg_employee` FOREIGN KEY (`att_employee_id`)
      REFERENCES `att_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aeg_fence` FOREIGN KEY (`geofence_id`)
      REFERENCES `att_geofences` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
