-- A tolerance ring outside every work area, in metres.
--
-- Without it a boundary is treated as infinitely sharp while the position judged
-- against it is accurate to tens of metres. Somebody sitting at a desk near the edge
-- of their area is then reported as having left it, and re-reported every time the fix
-- wobbles back out — which is what made the alarm fire repeatedly for people who had
-- not moved at all.
--
-- 50 m by default: larger than a typical good GPS fix, small enough that genuinely
-- leaving the site still registers.
ALTER TABLE att_settings
    ADD COLUMN geofence_buffer_m INT NOT NULL DEFAULT 50 AFTER default_max_accuracy_m;
