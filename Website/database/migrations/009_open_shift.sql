-- 009_open_shift.sql
--
-- Keeps a shift open for check-out after its scheduled end time.
--
-- Before this, the work date advanced purely by the clock. An overnight shift
-- (20:00-05:00) rolled over at 05:00, and a day shift at midnight; the moment it
-- rolled, the employee's still-open row was abandoned and the app offered a fresh
-- Check In for the new date. Anyone who stayed late was shown the wrong button and
-- their check-out landed on the following day, leaving yesterday open forever.
--
-- max_overtime_hours is how long past the scheduled end a shift stays claimable.
-- Past that the day is flagged missing-checkout so it is visible to an admin
-- instead of blocking the next day's check-in.

ALTER TABLE `att_attendance`
    ADD COLUMN `overtime_minutes` INT(11) NOT NULL DEFAULT 0 AFTER `early_leave_minutes`;

ALTER TABLE `att_attendance`
    MODIFY COLUMN `status` ENUM('present','late','half-day','absent','incomplete',
                                'missing-checkout','holiday','leave')
    NOT NULL DEFAULT 'absent';

ALTER TABLE `att_employee_settings`
    ADD COLUMN `max_overtime_hours` INT(11) NOT NULL DEFAULT 6 AFTER `late_grace_min`;
