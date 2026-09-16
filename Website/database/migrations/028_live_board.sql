-- Always-on position for the personal live board.
--
-- Route recording stays shift-only by design (att_location_logs is untouched by
-- everything here). This is a separate, tiny channel: one row per employee,
-- always the latest ping, read only by Attendance/Live/data.php. The register,
-- routes, trips, worked hours and home visits cannot see it, so the main
-- attendance product is unaffected.
--
-- The two att_settings columns are the board's kill switch and cadence. They
-- travel to the app inside app-config; the app sends nothing when disabled.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `att_live_pings` (
  `att_employee_id` INT(11) NOT NULL COMMENT 'one row per employee — always the latest ping',
  `lat` DECIMAL(10,7) NOT NULL,
  `lng` DECIMAL(10,7) NOT NULL,
  `accuracy_m` DECIMAL(8,2) DEFAULT NULL,
  `battery_pct` TINYINT(4) DEFAULT NULL,
  `is_mock` TINYINT(1) NOT NULL DEFAULT 0,
  `recorded_at` DATETIME NOT NULL COMMENT 'device clock at capture',
  `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'server receipt',
  PRIMARY KEY (`att_employee_id`),
  CONSTRAINT `fk_liveping_emp` FOREIGN KEY (`att_employee_id`)
      REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE att_settings
    ADD COLUMN live_board_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = apps send no off-shift pings',
    ADD COLUMN live_ping_interval_min INT(11) NOT NULL DEFAULT 15 COMMENT 'minutes between off-shift pings';
