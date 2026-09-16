-- Two shifts in a day, for part-time monitors.
--
-- A part-time monitor works a school run in the morning and another in the afternoon: two
-- check-ins and two check-outs, with hours off in between. The register held one row per
-- person per day with one check_in_at and one check_out_at, so recording the afternoon
-- either overwrote the morning or was refused as a duplicate.
--
-- Sessions are numbered rather than given their own table. A second row for the same date
-- is what the day actually is, and every query that already reads att_attendance by
-- (employee, date) keeps working — it simply sees two rows where there used to be one.

ALTER TABLE att_employee_settings
    ADD COLUMN sessions_per_day TINYINT NOT NULL DEFAULT 1 AFTER shift_end;

ALTER TABLE att_attendance
    ADD COLUMN session_no TINYINT NOT NULL DEFAULT 1 AFTER work_date;

-- The old key allowed one row per person per day, which is exactly what a split shift
-- needs to break. Replaced rather than dropped: without a unique key a retried check-in
-- could quietly create a third session.
-- Added before the old one is dropped, because a foreign key on att_employee_id is
-- using uq_emp_date as its supporting index and MySQL will not leave it without one.
-- The new key leads with the same column, so it takes that job over.
ALTER TABLE att_attendance
    ADD UNIQUE KEY uq_emp_date_session (att_employee_id, work_date, session_no);
ALTER TABLE att_attendance DROP INDEX uq_emp_date;

-- A monitor's account has no hr_employees row, so this copy of the person id has nothing
-- to hold for them. Nothing reads the column — it is write-only denormalisation from
-- before monitors existed — so it becomes nullable rather than being filled with a
-- monitor id that would look like an employee id to anyone who found it later.
ALTER TABLE att_attendance MODIFY COLUMN employee_id INT NULL;
