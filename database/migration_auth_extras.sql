-- =========================================================
-- RoomEase migration: sign-in extras
-- =========================================================
-- Three additions for the redesigned sign-in pages:
--
--   site_settings     key/value settings an administrator changes from the
--                     panel. First use: the sign-in pages' background
--                     (admin/appearance.php).
--   remember_tokens   "Remember me". One row per remembered device. The
--                     cookie holds selector:validator; only a SHA-256 hash of
--                     the validator is stored, so a leaked table cannot be
--                     replayed as a login. Rows are single use: every
--                     successful sign-in from a cookie replaces its row.
--   users.google_id   the stable Google account id ("sub") for accounts that
--                     sign in with Google.
--
--   mysql -u root -p roomease < database/migration_auth_extras.sql
--
-- Safe to run more than once.
-- =========================================================

USE roomease;

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key     VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value   TEXT DEFAULT NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
    token_id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    selector        CHAR(24) NOT NULL,
    validator_hash  CHAR(64) NOT NULL,
    expires_at      DATETIME NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_remember_selector (selector),
    KEY idx_remember_user (user_id),
    KEY idx_remember_expires (expires_at),
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

DROP PROCEDURE IF EXISTS roomease_add_google_id $$

CREATE PROCEDURE roomease_add_google_id()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'users'
           AND COLUMN_NAME  = 'google_id'
    ) THEN
        ALTER TABLE users
            ADD COLUMN google_id VARCHAR(64) NULL DEFAULT NULL AFTER email,
            ADD UNIQUE KEY uq_users_google_id (google_id);
    END IF;
END $$

DELIMITER ;

CALL roomease_add_google_id();
DROP PROCEDURE IF EXISTS roomease_add_google_id;
