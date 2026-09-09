-- =========================================================
-- RoomEase migration: password reset tokens
-- =========================================================
-- Run once against an EXISTING roomease database. A fresh import of
-- roomease.sql already includes this table.
--
--   mysql -u root -p roomease < database/migration_password_resets.sql
--
-- Only a SHA-256 hash of each reset token is stored, the same principle
-- used for passwords: whoever holds the database still cannot reset an
-- account, because the value that goes in the link is never written down.
-- Tokens expire after one hour and can be used once.
-- =========================================================

USE roomease;

CREATE TABLE IF NOT EXISTS password_resets (
    reset_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_user (user_id),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT COUNT(*) AS reset_requests FROM password_resets;
