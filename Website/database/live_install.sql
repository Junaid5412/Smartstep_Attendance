-- ===========================================================================
-- SST Attendance — live install
--
-- IMPORT THIS INTO THE SAME DATABASE AS THE ERP. Not a new one.
--
-- att_employees.employee_id is a foreign key to hr_employees.id, and the module
-- joins hr_employees, hr_departments and users directly. In a separate database the
-- foreign key cannot be created and every one of those joins fails.
--
-- Structure only. No employees, devices, tokens, routes or attendance rows are
-- included: those are test data from the development machine and the live hr_employees
-- ids will not match anyway. Enrol staff through the admin panel after importing.
--
-- Includes every schema change to date (migrations 002-013). Do not also run
-- schema.sql or the migration files — this supersedes them.
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL COMMENT 'denormalised hr_employees.id for reporting',
  `work_date` date NOT NULL,
  `geofence_id` int(11) DEFAULT NULL COMMENT 'area assigned at the time of check-in',
  `check_in_at` datetime DEFAULT NULL,
  `check_in_lat` decimal(10,7) DEFAULT NULL,
  `check_in_lng` decimal(10,7) DEFAULT NULL,
  `check_in_accuracy_m` decimal(8,2) DEFAULT NULL,
  `check_in_address` varchar(255) DEFAULT NULL,
  `check_in_photo` varchar(255) DEFAULT NULL,
  `check_in_inside_fence` tinyint(1) DEFAULT NULL,
  `check_in_device_id` int(11) DEFAULT NULL,
  `check_out_at` datetime DEFAULT NULL,
  `check_out_lat` decimal(10,7) DEFAULT NULL,
  `check_out_lng` decimal(10,7) DEFAULT NULL,
  `check_out_accuracy_m` decimal(8,2) DEFAULT NULL,
  `check_out_address` varchar(255) DEFAULT NULL,
  `check_out_photo` varchar(255) DEFAULT NULL,
  `check_out_inside_fence` tinyint(1) DEFAULT NULL,
  `check_out_device_id` int(11) DEFAULT NULL,
  `worked_minutes` int(11) DEFAULT NULL,
  `outside_minutes` int(11) NOT NULL DEFAULT 0 COMMENT 'total time outside the fence',
  `late_minutes` int(11) NOT NULL DEFAULT 0,
  `early_leave_minutes` int(11) NOT NULL DEFAULT 0,
  `overtime_minutes` int(11) NOT NULL DEFAULT 0,
  `status` enum('present','late','half-day','absent','incomplete','missing-checkout','holiday','leave') NOT NULL DEFAULT 'absent',
  `source` enum('app','manual') NOT NULL DEFAULT 'app',
  `admin_note` varchar(500) DEFAULT NULL,
  `edited_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emp_date` (`att_employee_id`,`work_date`),
  KEY `idx_date` (`work_date`),
  KEY `idx_employee_date` (`employee_id`,`work_date`),
  CONSTRAINT `fk_att_att_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_audit_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `actor_type` enum('admin','employee','system') NOT NULL DEFAULT 'admin',
  `actor_id` int(11) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `entity` varchar(60) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_action` (`action`),
  KEY `idx_entity` (`entity`,`entity_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_device_commands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` int(11) NOT NULL,
  `device_id` int(11) DEFAULT NULL,
  `command` enum('locate','sync','signout') NOT NULL DEFAULT 'locate',
  `status` enum('pending','delivered','done','expired') NOT NULL DEFAULT 'pending',
  `requested_by` int(11) DEFAULT NULL COMMENT 'users.id of the admin who asked',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `delivered_at` datetime DEFAULT NULL,
  `done_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pending` (`att_employee_id`,`status`),
  KEY `idx_device` (`device_id`,`status`),
  CONSTRAINT `fk_att_cmd_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_device_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` int(11) NOT NULL,
  `device_id` int(11) DEFAULT NULL,
  `old_device_uid` varchar(128) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `reset_by` int(11) DEFAULT NULL COMMENT 'users.id',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_emp` (`att_employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` int(11) NOT NULL,
  `device_uid` varchar(128) NOT NULL COMMENT 'persistent composite id from the app',
  `device_model` varchar(150) DEFAULT NULL,
  `device_brand` varchar(100) DEFAULT NULL,
  `os_version` varchar(50) DEFAULT NULL,
  `app_version` varchar(30) DEFAULT NULL,
  `platform` enum('android','ios') NOT NULL DEFAULT 'android',
  `fcm_token` varchar(255) DEFAULT NULL,
  `status` enum('active','reset','blocked') NOT NULL DEFAULT 'active',
  `bound_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `active_lock` int(11) GENERATED ALWAYS AS (case when `status` = 'active' then `att_employee_id` else NULL end) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_one_active_device` (`active_lock`),
  KEY `idx_emp_status` (`att_employee_id`,`status`),
  KEY `idx_device_uid` (`device_uid`),
  CONSTRAINT `fk_att_dev_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_employee_geofences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` int(11) NOT NULL,
  `geofence_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_fence` (`att_employee_id`,`geofence_id`),
  KEY `idx_fence` (`geofence_id`),
  CONSTRAINT `fk_aeg_employee` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aeg_fence` FOREIGN KEY (`geofence_id`) REFERENCES `att_geofences` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_employee_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` int(11) NOT NULL,
  `geofence_id` int(11) DEFAULT NULL,
  `home_lat` decimal(10,7) DEFAULT NULL,
  `home_lng` decimal(10,7) DEFAULT NULL,
  `home_radius_m` int(11) NOT NULL DEFAULT 150,
  `home_label` varchar(150) DEFAULT NULL,
  `shift_start` time NOT NULL DEFAULT '08:00:00',
  `shift_end` time NOT NULL DEFAULT '17:00:00',
  `overnight_shift` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'shift_end falls next day',
  `work_days` varchar(20) NOT NULL DEFAULT '1,2,3,4,5,6' COMMENT 'ISO days 1=Mon..7=Sun',
  `tracking_interval_min` int(11) NOT NULL DEFAULT 10 COMMENT 'location ping interval',
  `late_grace_min` int(11) NOT NULL DEFAULT 15,
  `max_overtime_hours` int(11) NOT NULL DEFAULT 6,
  `require_photo` tinyint(1) NOT NULL DEFAULT 1,
  `enforce_geofence` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = allow check-in anywhere but flag it',
  `enforce_geofence_checkout` tinyint(1) NOT NULL DEFAULT 0,
  `max_accuracy_m` int(11) NOT NULL DEFAULT 50 COMMENT 'reject GPS fixes worse than this',
  `allow_mock_location` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_employee` (`att_employee_id`),
  KEY `idx_geofence` (`geofence_id`),
  CONSTRAINT `fk_att_set_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_att_set_fence` FOREIGN KEY (`geofence_id`) REFERENCES `att_geofences` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL COMMENT 'FK hr_employees.id',
  `login_code` varchar(50) NOT NULL COMMENT 'what the employee types in the app',
  `password` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `consent_accepted_at` datetime DEFAULT NULL COMMENT 'location-tracking disclosure',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login_code` (`login_code`),
  UNIQUE KEY `uq_employee` (`employee_id`),
  CONSTRAINT `fk_att_emp_hr` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_geofence_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` int(11) NOT NULL,
  `attendance_id` int(11) DEFAULT NULL,
  `geofence_id` int(11) DEFAULT NULL,
  `left_geofence_id` int(11) DEFAULT NULL,
  `work_date` date NOT NULL,
  `exit_at` datetime NOT NULL,
  `exit_lat` decimal(10,7) DEFAULT NULL,
  `exit_lng` decimal(10,7) DEFAULT NULL,
  `entry_at` datetime DEFAULT NULL COMMENT 'NULL while still outside',
  `entry_lat` decimal(10,7) DEFAULT NULL,
  `entry_lng` decimal(10,7) DEFAULT NULL,
  `duration_min` int(11) DEFAULT NULL,
  `max_distance_m` int(11) DEFAULT NULL COMMENT 'furthest point from the fence',
  `farthest_lat` decimal(10,7) DEFAULT NULL,
  `farthest_lng` decimal(10,7) DEFAULT NULL,
  `farthest_address` varchar(255) DEFAULT NULL,
  `category` enum('unspecified','company_work','client_visit','personal','break','transit','other') NOT NULL DEFAULT 'unspecified',
  `employee_reason` varchar(500) DEFAULT NULL,
  `reason_submitted_at` datetime DEFAULT NULL,
  `review_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_emp_date` (`att_employee_id`,`work_date`),
  KEY `idx_open` (`att_employee_id`,`entry_at`),
  KEY `idx_review` (`review_status`),
  CONSTRAINT `fk_att_ev_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_geofences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `type` enum('circle','polygon') NOT NULL DEFAULT 'circle',
  `center_lat` decimal(10,7) DEFAULT NULL,
  `center_lng` decimal(10,7) DEFAULT NULL,
  `radius_m` int(11) DEFAULT NULL COMMENT 'circle radius in metres',
  `polygon` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '[[lat,lng],...] for type=polygon' CHECK (json_valid(`polygon`)),
  `color` varchar(20) DEFAULT '#2563eb',
  `project_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_live_follow` (
  `att_employee_id` int(11) NOT NULL,
  `interval_seconds` int(11) NOT NULL DEFAULT 8,
  `until` datetime NOT NULL,
  `requested_by` int(11) DEFAULT NULL COMMENT 'users.id - who is watching',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`att_employee_id`),
  KEY `idx_until` (`until`),
  CONSTRAINT `fk_follow_employee` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_location_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `att_employee_id` int(11) NOT NULL,
  `attendance_id` int(11) DEFAULT NULL,
  `work_date` date NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL,
  `altitude_m` decimal(8,2) DEFAULT NULL,
  `speed_kmh` decimal(6,2) DEFAULT NULL,
  `heading` decimal(6,2) DEFAULT NULL,
  `battery_pct` tinyint(4) DEFAULT NULL,
  `is_charging` tinyint(1) DEFAULT NULL,
  `is_mock` tinyint(1) NOT NULL DEFAULT 0,
  `inside_fence` tinyint(1) DEFAULT NULL,
  `matched_geofence_id` int(11) DEFAULT NULL,
  `distance_from_fence_m` int(11) DEFAULT NULL COMMENT '0 when inside',
  `provider` varchar(30) DEFAULT NULL,
  `recorded_at` datetime NOT NULL COMMENT 'device clock at capture',
  `received_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'server receipt',
  `client_uid` varchar(64) DEFAULT NULL COMMENT 'app-side unique id, idempotency key',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_uid` (`att_employee_id`,`client_uid`),
  KEY `idx_emp_date` (`att_employee_id`,`work_date`),
  KEY `idx_recorded` (`recorded_at`),
  KEY `idx_attendance` (`attendance_id`),
  CONSTRAINT `fk_att_loc_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=259 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `default_shift_start` time NOT NULL DEFAULT '08:00:00',
  `default_shift_end` time NOT NULL DEFAULT '17:00:00',
  `default_interval_min` int(11) NOT NULL DEFAULT 10,
  `default_radius_m` int(11) NOT NULL DEFAULT 150,
  `default_max_accuracy_m` int(11) NOT NULL DEFAULT 50,
  `token_lifetime_days` int(11) NOT NULL DEFAULT 30,
  `photo_max_kb` int(11) NOT NULL DEFAULT 1024,
  `tile_url` varchar(255) NOT NULL DEFAULT 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  `tile_attribution` varchar(255) NOT NULL DEFAULT '© OpenStreetMap contributors',
  `min_app_version` varchar(20) DEFAULT NULL COMMENT 'force-update gate',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `map_provider` enum('osm','google') NOT NULL DEFAULT 'osm' COMMENT 'which tile source the panel and app use',
  `google_api_key` varchar(255) DEFAULT NULL COMMENT 'Google Maps Platform key with Map Tiles API enabled',
  `protomaps_api_key` varchar(255) NOT NULL DEFAULT '',
  `offline_map_file` varchar(100) NOT NULL DEFAULT 'qatar.pmtiles',
  `google_map_type` enum('roadmap','satellite','terrain') NOT NULL DEFAULT 'roadmap',
  `google_language` varchar(15) NOT NULL DEFAULT 'en' COMMENT 'label language; en keeps English labels over Arabic place names',
  `google_region` varchar(5) NOT NULL DEFAULT 'QA',
  `google_session_token` varchar(600) DEFAULT NULL,
  `google_session_expires_at` datetime DEFAULT NULL,
  `google_session_error` varchar(400) DEFAULT NULL COMMENT 'last createSession failure, surfaced in the settings page',
  `map_style` varchar(30) NOT NULL DEFAULT 'google_like' COMMENT 'osm_raw | google_like | google_light | custom',
  `tile_filter_css` varchar(255) DEFAULT NULL COMMENT 'CSS filter applied to tiles; NULL means use the preset for map_style',
  `app_logo` varchar(255) DEFAULT NULL COMMENT 'path under Attendance/Website/uploads; NULL = use company_settings.company_logo',
  `app_display_name` varchar(120) DEFAULT NULL COMMENT 'name shown in the app header; NULL = use company_settings.company_name',
  `splash_seconds` decimal(3,1) NOT NULL DEFAULT 3.0 COMMENT 'minimum time the branded loading screen is shown, in seconds',
  `outside_confirm_points` tinyint(4) NOT NULL DEFAULT 2 COMMENT 'consecutive confident-outside fixes needed before a trip opens',
  `command_poll_seconds` smallint(6) NOT NULL DEFAULT 45 COMMENT 'how often the device checks for pending commands, in seconds',
  `geofence_watch_seconds` smallint(6) NOT NULL DEFAULT 10 COMMENT 'how often the device checks whether it has left the area; 0 disables',
  `alert_sound` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'play a sound with the leaving-the-area warning',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `att_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'sha256 of the bearer token',
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `idx_emp` (`att_employee_id`),
  KEY `fk_att_tok_dev` (`device_id`),
  CONSTRAINT `fk_att_tok_dev` FOREIGN KEY (`device_id`) REFERENCES `att_devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_att_tok_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;



-- The single settings row. API keys are deliberately blank: paste them into
-- Settings on the live panel so they are never carried in a file.
INSERT INTO `att_settings` (`id`, `default_shift_start`, `default_shift_end`, `default_interval_min`, `default_radius_m`, `default_max_accuracy_m`, `token_lifetime_days`, `photo_max_kb`, `tile_url`, `tile_attribution`, `min_app_version`, `updated_at`, `map_provider`, `google_api_key`, `protomaps_api_key`, `offline_map_file`, `google_map_type`, `google_language`, `google_region`, `google_session_token`, `google_session_expires_at`, `google_session_error`, `map_style`, `tile_filter_css`, `app_logo`, `app_display_name`, `splash_seconds`, `outside_confirm_points`, `command_poll_seconds`, `geofence_watch_seconds`, `alert_sound`)
VALUES ('1', '07:30:00', '16:30:00', '1', '200', '45', '30', '1024', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', '© OpenStreetMap contributors', '1.0.0', '2026-08-08 00:06:03', 'osm', '', '', 'qatar.pmtiles', 'roadmap', 'en', 'QA', NULL, NULL, NULL, 'google_direct', NULL, NULL, NULL, '3.0', '2', '15', '10', '1')
ON DUPLICATE KEY UPDATE `id` = `id`;

SET FOREIGN_KEY_CHECKS = 1;
