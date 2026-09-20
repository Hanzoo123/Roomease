-- =========================================================
-- RoomEase migration: profile photos
-- =========================================================
-- Adds users.avatar_path, the relative path of an account's profile photo,
-- e.g. assets/uploads/avatars/av-7-a1b2c3d4e5f60718.jpg. NULL means the
-- account has no photo and is drawn as its initials instead.
--
--   mysql -u root -p roomease < database/migration_avatars.sql
--
-- Why a path rather than the image itself: photos already live on disk under
-- assets/uploads/, where .htaccess turns PHP off and pins the content type, so
-- a profile photo is protected by the same lock as a listing photo and the
-- database stays small enough to export.
--
-- The column is deliberately not UNIQUE and has no foreign key: it is a file
-- reference, and the application is what keeps it in step with the disk
-- (includes/core/functions.php, handle_avatar_upload and delete_avatar).
--
-- Safe to run more than once. A fresh import of roomease.sql already has it.
-- =========================================================

USE roomease;

DELIMITER $$

DROP PROCEDURE IF EXISTS roomease_add_avatars $$

CREATE PROCEDURE roomease_add_avatars()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'users'
           AND COLUMN_NAME  = 'avatar_path'
    ) THEN
        ALTER TABLE users
            ADD COLUMN avatar_path VARCHAR(255) NULL DEFAULT NULL AFTER phone_number;
    END IF;
END $$

DELIMITER ;

CALL roomease_add_avatars();
DROP PROCEDURE IF EXISTS roomease_add_avatars;
