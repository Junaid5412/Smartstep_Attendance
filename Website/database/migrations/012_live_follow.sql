-- 012_live_follow.sql
--
-- Time-boxed "follow this person" mode for the live map.
--
-- Normal recording is every few minutes, which is right for a route but useless for
-- watching somebody move. While a follow window is open the device reports every few
-- seconds instead.
--
-- It is deliberately time-boxed rather than a switch that can be left on:
--   * a few-second reporting rate is a real drain on the employee's battery, and
--   * following a named person in real time is a monitoring action, so it should
--     expire by itself rather than quietly persist after the admin stops watching.
--
-- One row per employee: a second admin asking to follow the same person extends the
-- same window instead of creating a competing one.

CREATE TABLE IF NOT EXISTS `att_live_follow` (
  `att_employee_id` INT(11) NOT NULL,
  `interval_seconds` INT(11) NOT NULL DEFAULT 8,
  `until` DATETIME NOT NULL,
  `requested_by` INT(11) DEFAULT NULL COMMENT 'users.id - who is watching',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`att_employee_id`),
  KEY `idx_until` (`until`),
  CONSTRAINT `fk_follow_employee` FOREIGN KEY (`att_employee_id`)
      REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
