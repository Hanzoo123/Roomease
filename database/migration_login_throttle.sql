-- =========================================================
-- RoomEase migration: brute-force throttling
--
-- Records failed sign-in attempts and password-reset requests so both can be
-- rate limited. Without this table the application still runs, but throttling
-- is off and it says so in the PHP error log.
--
-- Import into the existing `roomease` database:
--   mysql -u root -p roomease < database/migration_login_throttle.sql
-- or open phpMyAdmin, select `roomease`, and use the Import tab.
-- =========================================================

USE roomease;

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id   BIGINT AUTO_INCREMENT PRIMARY KEY,

    -- 'login' for a failed sign-in, 'reset' for a password-reset request.
    kind         VARCHAR(20)  NOT NULL,

    -- The email address the attempt was aimed at, lowercased.
    identifier   VARCHAR(190) NOT NULL,

    -- The address the request actually came from. IPv6 needs up to 45 chars.
    ip_address   VARCHAR(45)  NOT NULL,

    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- One index per thing that gets counted: the account being targeted, and
    -- the source doing the targeting.
    KEY idx_kind_identifier_time (kind, identifier, attempted_at),
    KEY idx_kind_ip_time (kind, ip_address, attempted_at),
    KEY idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
