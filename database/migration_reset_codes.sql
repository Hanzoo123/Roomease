-- =========================================================
-- RoomEase migration: password reset by emailed code
-- =========================================================
-- "Forgot password" now emails a 6-digit code instead of a link. Three
-- columns are added to password_resets:
--
--   code_hash     password_hash() of the code. A plain SHA-256 would not do:
--                 there are only a million codes, so a fast hash would be
--                 guessed in moments if the table ever leaked. Cleared once
--                 the code is used.
--   attempts      wrong guesses so far. The code stops working after five.
--   verified_at   when the right code was entered. Only a verified row can
--                 set a password, so an old emailed link from before this
--                 change can never be used.
--
--   mysql -u root -p roomease < database/migration_reset_codes.sql
--
-- Safe to run more than once. A fresh import of roomease.sql already has
-- these columns.
-- =========================================================

USE roomease;

DELIMITER $$

DROP PROCEDURE IF EXISTS roomease_add_reset_codes $$

CREATE PROCEDURE roomease_add_reset_codes()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'password_resets'
           AND COLUMN_NAME  = 'code_hash'
    ) THEN
        ALTER TABLE password_resets
            ADD COLUMN code_hash   VARCHAR(255) NULL DEFAULT NULL AFTER token_hash,
            ADD COLUMN attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER code_hash,
            ADD COLUMN verified_at DATETIME NULL DEFAULT NULL AFTER expires_at;
    END IF;
END $$

DELIMITER ;

CALL roomease_add_reset_codes();
DROP PROCEDURE IF EXISTS roomease_add_reset_codes;

-- Links issued before this change cannot be used any more, so clear them.
DELETE FROM password_resets WHERE verified_at IS NULL AND code_hash IS NULL;
