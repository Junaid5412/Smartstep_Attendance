-- Whether the handset is reporting uninstall protection, and when it last said so.
--
-- Reported by the app rather than asked for: the event that matters is protection being
-- switched OFF, and only the phone knows that the moment it happens.
ALTER TABLE att_devices
    ADD COLUMN admin_active TINYINT(1) NOT NULL DEFAULT 0 AFTER app_version,
    ADD COLUMN admin_reported_at DATETIME NULL AFTER admin_active;

-- The command column is an enum, so a new command has to be declared or MySQL rejects
-- the insert (in strict mode) or silently stores an empty string (outside it) — the
-- authorisation would appear to have been given and never reach the phone.
ALTER TABLE att_device_commands
    MODIFY COLUMN command ENUM('locate','sync','signout','release_admin') NOT NULL;
