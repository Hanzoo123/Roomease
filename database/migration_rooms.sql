-- =========================================================
-- RoomEase migration: rooms inside a boarding house, room photos,
-- and landlord-owned utilities and amenities
-- =========================================================
-- A boarding house used to be one room: rent, room type and capacity
-- lived on boarding_houses itself. This migration moves them down a level.
--
--   rooms              one row per room: name, room type, monthly rent,
--                      capacity, slots taken, and an open/closed switch.
--                      "Available" / "Full" / "Not available" is worked
--                      out from those, never stored.
--   images.room_id     NULL for a photo of the house, set for a room photo.
--   utilities.landlord_id,
--   amenities.landlord_id
--                      NULL for an item the administrator made, which every
--                      landlord can use; set for an item a landlord made,
--                      which only that landlord sees.
--
--   mysql -u root -p roomease < database/migration_rooms.sql
--
-- Every existing listing gets one room, "Room 1", copied from its current
-- rent, room type and capacity. The old house columns are left in place and
-- simply stop being used; once everything looks right, drop them with
-- database/migration_rooms_cleanup.sql.
--
-- Safe to run more than once.
-- =========================================================

USE roomease;

CREATE TABLE IF NOT EXISTS rooms (
    room_id             INT AUTO_INCREMENT PRIMARY KEY,
    boarding_house_id   INT NOT NULL,
    name                VARCHAR(60) NOT NULL,
    room_type_id        INT NOT NULL,
    monthly_rent        DECIMAL(10, 2) NOT NULL,
    capacity            SMALLINT NOT NULL DEFAULT 1,
    slots_taken         SMALLINT NOT NULL DEFAULT 0,
    is_open             TINYINT(1) NOT NULL DEFAULT 1,
    description         VARCHAR(500) DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rooms_house_name (boarding_house_id, name),
    KEY idx_rooms_type_rent (room_type_id, is_open, monthly_rent),
    CONSTRAINT chk_rooms_capacity CHECK (capacity BETWEEN 1 AND 100),
    CONSTRAINT chk_rooms_slots CHECK (slots_taken >= 0 AND slots_taken <= capacity),
    CONSTRAINT fk_rooms_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE,
    CONSTRAINT fk_rooms_type FOREIGN KEY (room_type_id)
        REFERENCES room_types(room_type_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

DROP PROCEDURE IF EXISTS roomease_migrate_rooms $$

CREATE PROCEDURE roomease_migrate_rooms()
BEGIN
    -- Room photos -------------------------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'images' AND COLUMN_NAME = 'room_id'
    ) THEN
        ALTER TABLE images
            ADD COLUMN room_id INT NULL DEFAULT NULL AFTER boarding_house_id,
            ADD KEY idx_images_room (room_id, is_primary, image_id),
            ADD CONSTRAINT fk_images_room FOREIGN KEY (room_id)
                REFERENCES rooms(room_id) ON DELETE CASCADE;
    END IF;

    -- Landlord-owned utilities -----------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilities' AND COLUMN_NAME = 'landlord_id'
    ) THEN
        ALTER TABLE utilities
            ADD COLUMN landlord_id INT NULL DEFAULT NULL AFTER utility_id,
            ADD CONSTRAINT fk_utilities_landlord FOREIGN KEY (landlord_id)
                REFERENCES users(user_id) ON DELETE CASCADE;
    END IF;
    IF EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilities' AND INDEX_NAME = 'utility_name'
    ) THEN
        ALTER TABLE utilities DROP INDEX utility_name;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilities' AND INDEX_NAME = 'uq_utilities_owner_name'
    ) THEN
        -- Names are unique per landlord. Administrator items have a NULL
        -- landlord_id, which a unique key does not compare, so the
        -- application checks those names itself.
        ALTER TABLE utilities ADD UNIQUE KEY uq_utilities_owner_name (landlord_id, utility_name);
    END IF;

    -- Landlord-owned amenities -----------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'amenities' AND COLUMN_NAME = 'landlord_id'
    ) THEN
        ALTER TABLE amenities
            ADD COLUMN landlord_id INT NULL DEFAULT NULL AFTER amenity_id,
            ADD CONSTRAINT fk_amenities_landlord FOREIGN KEY (landlord_id)
                REFERENCES users(user_id) ON DELETE CASCADE;
    END IF;
    IF EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'amenities' AND INDEX_NAME = 'amenity_name'
    ) THEN
        ALTER TABLE amenities DROP INDEX amenity_name;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'amenities' AND INDEX_NAME = 'uq_amenities_owner_name'
    ) THEN
        ALTER TABLE amenities ADD UNIQUE KEY uq_amenities_owner_name (landlord_id, amenity_name);
    END IF;

    -- Old one-room columns: only while they still exist ----------------
    IF EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boarding_houses' AND COLUMN_NAME = 'monthly_rent'
    ) THEN
        -- New listings no longer write a house rent, so the column must
        -- accept NULL until it is dropped.
        ALTER TABLE boarding_houses MODIFY monthly_rent DECIMAL(10, 2) NULL DEFAULT NULL;

        -- One room per listing that has none yet. Listings created after
        -- this migration have no house rent or room type, so a second run
        -- never invents a room for them.
        INSERT INTO rooms (boarding_house_id, name, room_type_id, monthly_rent, capacity, slots_taken, is_open)
        SELECT bh.boarding_house_id, 'Room 1', bh.room_type_id, bh.monthly_rent,
               LEAST(GREATEST(bh.room_capacity, 1), 100), 0, 1
          FROM boarding_houses bh
         WHERE bh.monthly_rent IS NOT NULL
           AND bh.room_type_id IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM rooms r WHERE r.boarding_house_id = bh.boarding_house_id);
    END IF;
END $$

DELIMITER ;

CALL roomease_migrate_rooms();
DROP PROCEDURE IF EXISTS roomease_migrate_rooms;
