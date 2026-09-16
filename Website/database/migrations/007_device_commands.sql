-- ============================================================================
-- On-demand device commands.
--
-- The server cannot reach a phone, so a "locate now" request is left here for the
-- device to collect on its next poll. Kept as a table rather than a flag on
-- att_devices for two reasons: several requests can be outstanding, and — more
-- importantly — asking for a person's live position is exactly the kind of action
-- that should leave a record of who asked and when.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `att_device_commands` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `att_employee_id` INT(11) NOT NULL,
  `device_id` INT(11) DEFAULT NULL,
  `command` ENUM('locate','sync','signout') NOT NULL DEFAULT 'locate',
  `status` ENUM('pending','delivered','done','expired') NOT NULL DEFAULT 'pending',
  `requested_by` INT(11) DEFAULT NULL COMMENT 'users.id of the admin who asked',
  `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `delivered_at` DATETIME DEFAULT NULL,
  `done_at` DATETIME DEFAULT NULL,
  -- A stale request must not make a phone report its position hours later, when
  -- whoever asked has long stopped looking.
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pending` (`att_employee_id`,`status`),
  KEY `idx_device` (`device_id`,`status`),
  CONSTRAINT `fk_att_cmd_emp` FOREIGN KEY (`att_employee_id`) REFERENCES `att_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `att_settings`
  ADD COLUMN `command_poll_seconds` SMALLINT NOT NULL DEFAULT 45
      COMMENT 'how often the device checks for pending commands, in seconds';
