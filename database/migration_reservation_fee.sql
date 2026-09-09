-- =========================================================
-- RoomEase migration: boarding_houses.reservation_fee
-- =========================================================
-- Run this against an EXISTING roomease database that was created
-- before reservation_fee was added. A fresh import of roomease.sql
-- already includes it. Run once:
--
--   mysql -u root -p roomease < database/migration_reservation_fee.sql
--
-- The column is nullable on purpose: not every boarding house charges
-- a reservation fee, and NULL reads as "not required" in the UI rather
-- than as a fee of zero.
--
-- Note this stores the fee as listing information only. It does not
-- introduce online reservations or payments, which remain out of scope
-- per the Limitations of the Study.
-- =========================================================

USE roomease;

ALTER TABLE boarding_houses
    ADD COLUMN reservation_fee DECIMAL(10, 2) DEFAULT NULL AFTER monthly_rent;

-- Existing listings keep NULL until their landlord edits them.
SELECT boarding_house_id, name, monthly_rent, reservation_fee
FROM boarding_houses
ORDER BY boarding_house_id;
