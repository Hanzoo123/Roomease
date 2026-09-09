-- =========================================================
-- RoomEase migration: listing approval workflow
-- =========================================================
-- Run once against an EXISTING roomease database. A fresh import of
-- roomease.sql already includes everything below.
--
--   mysql -u root -p roomease < database/migration_moderation.sql
--
-- New listings start as 'pending' and stay off the public browse page
-- until an administrator approves them. Listings that already existed
-- before this migration were already publicly visible, so they are
-- grandfathered in as 'approved' rather than suddenly disappearing.
-- =========================================================

USE roomease;

ALTER TABLE boarding_houses
    ADD COLUMN moderation_status ENUM('pending', 'approved', 'rejected')
        NOT NULL DEFAULT 'pending' AFTER availability_status,
    ADD COLUMN rejection_reason VARCHAR(500) DEFAULT NULL AFTER moderation_status,
    ADD COLUMN moderated_at TIMESTAMP NULL DEFAULT NULL AFTER rejection_reason;

-- Grandfather every pre-existing listing.
UPDATE boarding_houses
   SET moderation_status = 'approved',
       moderated_at = NOW();

SELECT moderation_status, COUNT(*) AS listings
FROM boarding_houses
GROUP BY moderation_status;
