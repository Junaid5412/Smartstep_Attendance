-- Permissions for the attendance panel, so access is granted rather than hard-coded.
--
-- Until now attAdminRoles() listed six role names in PHP: super_admin, it_administrator,
-- manager, hr_office, hr and hr_executive. Every one of those roles was already inside
-- the panel, and the only way to change that was to edit the source. These two rows move
-- the decision into the roles screen where the rest of the system's access lives.
--
-- Deliberately nothing is granted here. After this runs, only super_admin can open the
-- panel — super admin bypasses permission checks everywhere — and HR, managers and IT
-- lose the access they had until somebody grants it on purpose. That is the point: the
-- request was for super-admin-only with HR available as a choice.
--
-- Safe to run twice: INSERT IGNORE against the unique permission_name.

INSERT IGNORE INTO permissions (permission_name, permission_display_name, module, description)
VALUES
    ('attendance_panel.view',
     'Open Attendance Dashboard',
     'attendance_panel',
     'Open the SST Attendance dashboard: live map, routes, registers and reports. Read-only without the manage permission.'),

    ('attendance_panel.manage',
     'Manage Attendance Dashboard',
     'attendance_panel',
     'Change attendance settings, work areas, employee rules, devices and app releases. Includes deleting attendance records and lifting security blocks, so grant it sparingly.');
