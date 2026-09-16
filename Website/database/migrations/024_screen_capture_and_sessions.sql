-- 1. Screenshots and screen recording, controllable without shipping a new APK.
--
-- The app blocked capture unconditionally. That is right for a workforce app holding other
-- people's locations, but it also blocks the operator photographing their own screen to
-- report a problem — which is how most support requests start. A setting rather than a
-- code change means turning it on does not cost a build and a rollout.
ALTER TABLE att_settings
    ADD COLUMN allow_screen_capture TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_session_id;

-- 2. A part-time monitor's second shift has its own hours.
--
-- The account carries one shift_start/shift_end, so an afternoon run was being measured
-- for lateness against the morning's start time — a monitor arriving at 12:00 for a 12:00
-- shift was recorded as four hours late. Null means "use the account's own times", so a
-- full-time account is unaffected and a part-timer whose second shift is not yet
-- configured behaves exactly as before rather than breaking.
ALTER TABLE att_employee_settings
    ADD COLUMN shift2_start TIME NULL AFTER sessions_per_day,
    ADD COLUMN shift2_end TIME NULL AFTER shift2_start;
