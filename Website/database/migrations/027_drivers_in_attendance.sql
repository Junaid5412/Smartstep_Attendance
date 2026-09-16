-- Drivers get attendance accounts alongside HR employees and monitors.
--
-- Same shape as migration 022: fleet_drivers is its own table with its own codes
-- and projects, so att_employees learns a third person type rather than copying
-- drivers into hr_employees. The att_person view gains a third branch so every
-- existing join keeps working untouched.
--
-- NOTE: the chk_att_person CHECK from 022 never materialised on MariaDB, so
-- there is nothing to drop here; the exactly-one-person rule lives in the
-- enrol code (one of employee_id / monitor_id / driver_id is set).

SET NAMES utf8mb4;

-- 1. The new person type and its pointer.
ALTER TABLE att_employees
    MODIFY COLUMN person_type ENUM('employee','monitor','driver') NOT NULL DEFAULT 'employee',
    ADD COLUMN driver_id INT NULL AFTER monitor_id;

ALTER TABLE att_employees
    ADD CONSTRAINT fk_att_emp_driver FOREIGN KEY (driver_id) REFERENCES fleet_drivers(id)
        ON DELETE CASCADE;

-- One account per driver. NULLs repeat freely in a MySQL unique index, so
-- employee and monitor accounts are unaffected.
ALTER TABLE att_employees
    ADD UNIQUE KEY uniq_driver (driver_id);

-- 2. The single column the joins use, now spanning all three tables.
ALTER TABLE att_employees
    MODIFY COLUMN person_ref INT GENERATED ALWAYS AS
        (COALESCE(employee_id, monitor_id, driver_id)) STORED;

-- 3. One shape for all three kinds of person. Column names match hr_employees
-- so every existing join keeps working; a driver's project and the word Driver
-- stand in for department and position, and fleet statuses are mapped onto the
-- values the app already understands (terminated/resigned accounts cannot sign
-- in — see login.php).
CREATE OR REPLACE VIEW att_person AS
    SELECT
        'employee' AS person_type,
        id         AS person_id,
        id,
        employee_code,
        first_name,
        last_name,
        photo,
        position,
        department_id,
        employment_status,
        is_active,
        NULL       AS project_id
    FROM hr_employees
    UNION ALL
    SELECT
        'monitor'    AS person_type,
        id           AS person_id,
        id,
        monitor_code COLLATE utf8mb4_general_ci AS employee_code,
        first_name   COLLATE utf8mb4_general_ci AS first_name,
        last_name    COLLATE utf8mb4_general_ci AS last_name,
        photo_path   COLLATE utf8mb4_general_ci AS photo,
        designation  COLLATE utf8mb4_general_ci AS position,
        NULL         AS department_id,
        CASE status
            WHEN 'active'     THEN 'active'
            WHEN 'on_leave'   THEN 'on-leave'
            WHEN 'terminated' THEN 'terminated'
            ELSE 'resigned'
        END COLLATE utf8mb4_general_ci AS employment_status,
        is_active,
        project_id
    FROM monitors
    UNION ALL
    SELECT
        'driver'     AS person_type,
        id           AS person_id,
        id,
        driver_code  COLLATE utf8mb4_general_ci AS employee_code,
        first_name   COLLATE utf8mb4_general_ci AS first_name,
        last_name    COLLATE utf8mb4_general_ci AS last_name,
        photo_path   COLLATE utf8mb4_general_ci AS photo,
        'Driver'     COLLATE utf8mb4_general_ci AS position,
        NULL         AS department_id,
        CASE status
            WHEN 'active'     THEN 'active'
            WHEN 'available'  THEN 'active'
            WHEN 'on-leave'   THEN 'on-leave'
            WHEN 'terminated' THEN 'terminated'
            ELSE 'resigned'
        END COLLATE utf8mb4_general_ci AS employment_status,
        is_active,
        project_id
    FROM fleet_drivers;
