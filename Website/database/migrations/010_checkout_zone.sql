-- 010_checkout_zone.sql
--
-- Whether this employee must be inside a work area to check out.
--
-- Check-in enforcement was already per-employee, but check-out never blocked at all:
-- the position was recorded and flagged for review instead. That is the right default
-- and stays the default (0) — refusing to let someone clock off because they are away
-- from site only manufactures "still working" records that an admin has to correct by
-- hand. Some roles do need it enforced, so it becomes a per-person choice rather than
-- a decision baked into the code.

ALTER TABLE `att_employee_settings`
    ADD COLUMN `enforce_geofence_checkout` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `enforce_geofence`;
