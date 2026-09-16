-- WhatsApp notifications, delivered through the operator's existing Baileys connector
-- (the "WA Server" Node service). The panel connects a device by QR, and events are
-- pushed as messages to a list of admin phone numbers.

-- Where the connector lives and which of its sessions is ours. One URL serves both PHP
-- (REST /send) and the admin browser (WebSocket /ws for the QR), so on an HTTPS panel it
-- must be an https:// URL with WebSocket upgrade allowed.
ALTER TABLE att_settings
    ADD COLUMN whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER alert_sound,
    ADD COLUMN whatsapp_server_url VARCHAR(255) NOT NULL DEFAULT 'https://wa.rifarealestate.com' AFTER whatsapp_enabled,
    ADD COLUMN whatsapp_session_id VARCHAR(64) NOT NULL DEFAULT 'sst-attendance' AFTER whatsapp_server_url;

-- Who receives the notifications. Multiple numbers, each with its own switch, so one
-- manager can be muted for a holiday without losing the number.
CREATE TABLE IF NOT EXISTS att_whatsapp_numbers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL,
    label VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
