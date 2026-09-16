-- Default shift timetable per project, for monitors.
--
-- Every school keeps different hours, and setting the same shift one monitor at
-- a time is what the panel required until now. This table holds one row per
-- project: the morning run, how many check-in/out sessions a day holds, the
-- optional afternoon run, and the work area new accounts start with.
--
-- These are defaults, not live rules. Saving them changes nothing by itself;
-- they are copied onto accounts when those accounts are enrolled (or when an
-- admin presses "Apply to enrolled accounts"). Per-account exceptions set
-- afterwards are left alone by later edits here.

CREATE TABLE IF NOT EXISTS `att_project_shifts` (
  `project_id` INT(11) NOT NULL COMMENT 'FK projects.id — one row per project',
  `shift1_start` TIME NOT NULL DEFAULT '08:00:00',
  `shift1_end` TIME NOT NULL DEFAULT '17:00:00',
  `sessions_default` TINYINT NOT NULL DEFAULT 1 COMMENT '1 = one check-in/out, 2 = split shift',
  `shift2_start` TIME DEFAULT NULL COMMENT 'afternoon run; NULL = reuse the morning hours',
  `shift2_end` TIME DEFAULT NULL,
  `default_geofence_id` INT(11) DEFAULT NULL COMMENT 'work area new accounts start with',
  `updated_by` INT(11) DEFAULT NULL COMMENT 'users.id',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`project_id`),
  KEY `idx_geofence` (`default_geofence_id`),
  CONSTRAINT `fk_aps_project` FOREIGN KEY (`project_id`)
      REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aps_fence` FOREIGN KEY (`default_geofence_id`)
      REFERENCES `att_geofences` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
