-- 014_security_block.sql
--
-- Run this on the LIVE database.
--
-- Only the security columns. The home_* columns were already present in
-- live_install.sql, and including them here aborted the whole file on a duplicate-column
-- error before the security columns were reached — leaving blocking silently inert.
--
-- Kept separate from is_active, which an admin uses to retire someone. Mixing the two
-- would make "why can this person not log in" unanswerable, and would let a routine
-- reactivation quietly clear a block nobody had reviewed.

ALTER TABLE `att_employees`
    ADD COLUMN `security_blocked_at` DATETIME DEFAULT NULL AFTER `is_active`,
    ADD COLUMN `security_block_reason` VARCHAR(255) DEFAULT NULL AFTER `security_blocked_at`,
    ADD COLUMN `security_unblock_note` VARCHAR(500) DEFAULT NULL AFTER `security_block_reason`,
    ADD COLUMN `security_unblocked_by` INT(11) DEFAULT NULL AFTER `security_unblock_note`,
    ADD COLUMN `security_unblocked_at` DATETIME DEFAULT NULL AFTER `security_unblocked_by`;
