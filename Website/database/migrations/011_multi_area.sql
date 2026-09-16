-- 011_multi_area.sql
--
-- Lets one employee be assigned several work areas.
--
-- Until now each employee had exactly one area, so anybody who legitimately works
-- across two offices was recorded as having left their work area every time they were
-- at the other one — and then asked to justify it. "Inside" now means inside ANY
-- assigned area.
--
-- att_employee_settings.geofence_id is deliberately kept. It stays the employee's
-- primary area: what the panel shows as their base, and what a check-in is stamped
-- with when no area contains them. Dropping it would have meant rewriting every
-- report, live-map and register query in the same change as the behavioural one.
--
-- Existing single assignments are copied in so nothing needs re-assigning by hand.

CREATE TABLE IF NOT EXISTS `att_employee_geofences` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` INT(11) NOT NULL,
  `geofence_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- One row per pair; assigning the same area twice is meaningless, and a duplicate
  -- would double-count the area in every "which areas" lookup.
  UNIQUE KEY `uq_employee_fence` (`att_employee_id`, `geofence_id`),
  KEY `idx_fence` (`geofence_id`),
  CONSTRAINT `fk_aeg_employee` FOREIGN KEY (`att_employee_id`)
      REFERENCES `att_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aeg_fence` FOREIGN KEY (`geofence_id`)
      REFERENCES `att_geofences` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `att_employee_geofences` (`att_employee_id`, `geofence_id`)
SELECT `att_employee_id`, `geofence_id`
FROM `att_employee_settings`
WHERE `geofence_id` IS NOT NULL;

-- Which of the assigned areas the employee was actually inside. Without this a
-- multi-office report can say "he was on site" but never which site.
ALTER TABLE `att_location_logs`
    ADD COLUMN `matched_geofence_id` INT(11) DEFAULT NULL AFTER `inside_fence`;

ALTER TABLE `att_geofence_events`
    ADD COLUMN `left_geofence_id` INT(11) DEFAULT NULL AFTER `geofence_id`;
