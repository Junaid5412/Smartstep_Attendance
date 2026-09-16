-- 1. How much of an approved trip counts as duty.
--
-- Until now approving a trip was a verdict with no consequence for pay: the whole
-- excursion still came off the day's worked time, so approving and rejecting paid the
-- same. This column is the missing half of the decision — the admin approves the trip
-- AND says how much of it counts (30 minutes, 1 hour ... 6 hours). Time beyond the
-- credit is still deducted. NULL means no credit, which is exactly what every
-- already-reviewed trip should mean, so old rows need no backfill.
ALTER TABLE att_geofence_events
    ADD COLUMN approved_minutes INT NULL AFTER review_note;

-- 2. Where the phone REALLY was while a fake position was being reported.
--
-- When the app detects the reported fix is simulated, it also asks the raw providers
-- for a second opinion. If one of them returns a non-simulated position, it rides
-- along here. Nullable because it frequently cannot be captured: most fake-GPS apps
-- override every provider, and then there is nothing genuine left to read.
ALTER TABLE att_location_logs
    ADD COLUMN real_lat DECIMAL(10,7) NULL AFTER is_mock,
    ADD COLUMN real_lng DECIMAL(10,7) NULL AFTER real_lat;
