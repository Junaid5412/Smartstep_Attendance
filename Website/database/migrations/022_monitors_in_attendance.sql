-- Monitors get attendance accounts alongsdie HR employees.
--
-- The attendance system was built assuming one kind of person: every query joined
-- hr_employees on att_employees.employee_id. Monitors live in their own table with their
-- own codes and projects, and copying them into hr_employees would create two records of
-- the same person that drift apart. So att_employees learns to point at either table, and
-- a view gives the 21 existing queries one shape to join against.

-- 1. Which table the account belongs to.
ALTER TABLE att_employees
    ADD COLUMN person_type ENUM('employee','monitor') NOT NULL DEFAULT 'employee' AFTER id,
    ADD COLUMN monitor_id INT NULL AFTER employee_id;

-- The old column was NOT NULL because there was nothing else it could be. A monitor's
-- account has no hr_employees row, so it has to be allowed to be absent. The foreign key
-- survives: MySQL does not enforce it on NULL.
ALTER TABLE att_employees
    MODIFY COLUMN employee_id INT NULL;

ALTER TABLE att_employees
    ADD CONSTRAINT fk_att_emp_monitor FOREIGN KEY (monitor_id) REFERENCES monitors(id)
        ON DELETE CASCADE;

-- One account per monitor, the same rule employee_id already had. NULLs repeat freely in
-- a MySQL unique index, so employee accounts are unaffected.
ALTER TABLE att_employees
    ADD UNIQUE KEY uniq_monitor (monitor_id);

-- 2. The single column the joins can use, kept correct by the database rather than by
--    every INSERT remembering to fill it.
ALTER TABLE att_employees
    ADD COLUMN person_ref INT AS (COALESCE(employee_id, monitor_id)) STORED AFTER monitor_id;

-- An account must point at exactly one person. Without this a row with both columns set,
-- or neither, would join to something arbitrary or vanish from every list.
ALTER TABLE att_employees
    ADD CONSTRAINT chk_att_person CHECK (
        (person_type = 'employee' AND employee_id IS NOT NULL AND monitor_id IS NULL)
     OR (person_type = 'monitor'  AND monitor_id  IS NOT NULL AND employee_id IS NULL)
    );

-- 3. One shape for both kinds of person.
--
-- Column names deliberately match hr_employees, so every existing `emp.first_name`,
-- `emp.employee_code`, `emp.photo` keeps working untouched — only the join line changes.
-- A monitor's designation stands in for position and their project for department, which
-- is what each actually means on the two sides.
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
    -- monitors was created with utf8mb4_unicode_ci while hr_employees uses
    -- utf8mb4_general_ci, and MySQL refuses to UNION mismatched collations. Every text
    -- column is forced to the hr_employees collation so the view has one consistent
    -- sort order rather than a per-branch one.
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
        -- The two tables spell the same states differently; mapped so that anything
        -- reading employment_status keeps getting a value it understands.
        CASE status
            WHEN 'active'     THEN 'active'
            WHEN 'on_leave'   THEN 'on-leave'
            WHEN 'terminated' THEN 'terminated'
            ELSE 'resigned'
        END COLLATE utf8mb4_general_ci AS employment_status,
        is_active,
        project_id
    FROM monitors;
