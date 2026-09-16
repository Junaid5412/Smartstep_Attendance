-- 016_live_map_index.sql
--
-- The live map finds each employee's newest position with a correlated subquery
-- (ORDER BY recorded_at DESC LIMIT 1, per employee). Polling every 5 seconds runs that
-- for every employee twelve times a minute.
--
-- The existing indexes are (att_employee_id, work_date) and (recorded_at) alone —
-- neither serves "newest row for this employee" without scanning. At 164 rows that is
-- invisible; at a one-minute recording interval the table grows by roughly 10,000 rows a
-- day for 20 staff, and this becomes the slowest thing on the server.

ALTER TABLE `att_location_logs`
    ADD INDEX `idx_emp_recorded` (`att_employee_id`, `recorded_at`);
