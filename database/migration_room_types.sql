-- =========================================================
-- RoomEase migration: room_types lookup table
-- =========================================================
-- Run this against an EXISTING roomease database that was created
-- before room_types was added. A fresh import of roomease.sql
-- already includes everything below.
--
--   mysql -u root -p roomease < database/migration_room_types.sql
--
-- Background: the listing form and the browse filter each carried
-- their own hard-coded room type list, and neither matched the
-- values already stored in boarding_houses, so filtering by room
-- type returned no results. Both now read from this table.
-- =========================================================

USE roomease;

CREATE TABLE IF NOT EXISTS room_types (
    room_type_id    INT AUTO_INCREMENT PRIMARY KEY,
    room_type_name  VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO room_types (room_type_name) VALUES
('Single Room'),
('Double Sharing'),
('Bed Spacer'),
('Dormitory'),
('Private Room');

-- Normalize values written by the old listing form.
UPDATE boarding_houses SET room_type = 'Single Room'    WHERE room_type = 'Single';
UPDATE boarding_houses SET room_type = 'Double Sharing' WHERE room_type = 'Double';

-- 'Air-conditioned Private Room' collapses to 'Private Room'; air
-- conditioning is already recorded separately as an amenity.
UPDATE boarding_houses SET room_type = 'Private Room'
    WHERE room_type = 'Air-conditioned Private Room';

-- Any remaining non-standard value is left alone on purpose: the
-- listing form keeps a listing's stored room type in its dropdown,
-- so editing the listing will not silently reassign it.
SELECT room_type, COUNT(*) AS listings
FROM boarding_houses
GROUP BY room_type
ORDER BY room_type;
