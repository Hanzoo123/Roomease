-- =========================================================
-- RoomEase migration: remove the old one-room columns
-- =========================================================
-- Run this AFTER database/migration_rooms.sql, once you have checked that
-- every listing's rooms look right on the website. It drops the house-level
-- columns that rooms replaced:
--
--   boarding_houses.monthly_rent
--   boarding_houses.room_type_id  (and its foreign key and indexes)
--   boarding_houses.room_capacity
--
--   mysql -u root -p roomease < database/migration_rooms_cleanup.sql
--
-- This cannot be undone without a backup: the values are gone afterwards.
-- They were already copied into each listing's "Room 1" by migration_rooms.sql,
-- so nothing the application uses is lost.
--
-- Safe to run more than once.
-- =========================================================

USE roomease;

DELIMITER $$

DROP PROCEDURE IF EXISTS roomease_rooms_cleanup $$

CREATE PROCEDURE roomease_rooms_cleanup()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Run database/migration_rooms.sql first: the rooms table does not exist.';
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boarding_houses'
                  AND CONSTRAINT_NAME = 'fk_bh_room_type') THEN
        ALTER TABLE boarding_houses DROP FOREIGN KEY fk_bh_room_type;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boarding_houses'
                  AND INDEX_NAME = 'idx_bh_public_rent') THEN
        ALTER TABLE boarding_houses DROP INDEX idx_bh_public_rent;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boarding_houses'
                  AND INDEX_NAME = 'idx_bh_public_type') THEN
        ALTER TABLE boarding_houses DROP INDEX idx_bh_public_type;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boarding_houses'
                  AND INDEX_NAME = 'fk_bh_room_type') THEN
        ALTER TABLE boarding_houses DROP INDEX fk_bh_room_type;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boarding_houses'
                  AND COLUMN_NAME = 'monthly_rent') THEN
        ALTER TABLE boarding_houses DROP COLUMN monthly_rent;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boarding_houses'
                  AND COLUMN_NAME = 'room_type_id') THEN
        ALTER TABLE boarding_houses DROP COLUMN room_type_id;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boarding_houses'
                  AND COLUMN_NAME = 'room_capacity') THEN
        ALTER TABLE boarding_houses DROP COLUMN room_capacity;
    END IF;
END $$

DELIMITER ;

CALL roomease_rooms_cleanup();
DROP PROCEDURE IF EXISTS roomease_rooms_cleanup;
