-- =========================================================
-- RoomEase migration: admin tools
-- =========================================================
-- Three additions for the administrator's side:
--
--   boarding_houses.deleted_at    a removed listing is archived, not deleted.
--                                 Removing used to erase the listing, its
--                                 photos from disk, and every boarder's saved
--                                 copy, with no undo. An archived listing is
--                                 off the site for everyone but
--                                 administrators, and can be restored.
--   boarding_houses.moderated_by  the administrator who made the latest
--                                 decision, beside moderated_at.
--   admin_actions                 the activity log: which administrator
--                                 approved, rejected, removed or restored a
--                                 listing, changed an account, or exported
--                                 data, when, and the reason they gave.
--                                 target_label keeps the name as it was, so
--                                 an entry still reads correctly after the
--                                 listing or account is renamed.
--
--   mysql -u root -p roomease < database/migration_admin_tools.sql
--
-- Safe to run more than once. A fresh import of roomease.sql already has all
-- three.
-- =========================================================

USE roomease;

DELIMITER $$

DROP PROCEDURE IF EXISTS roomease_admin_tools $$

CREATE PROCEDURE roomease_admin_tools()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'boarding_houses'
           AND COLUMN_NAME  = 'deleted_at'
    ) THEN
        ALTER TABLE boarding_houses
            ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER updated_at,
            ADD KEY idx_bh_deleted (deleted_at);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'boarding_houses'
           AND COLUMN_NAME  = 'moderated_by'
    ) THEN
        ALTER TABLE boarding_houses
            ADD COLUMN moderated_by INT NULL DEFAULT NULL AFTER moderated_at,
            ADD CONSTRAINT fk_bh_moderated_by FOREIGN KEY (moderated_by)
                REFERENCES users(user_id) ON DELETE SET NULL;
    END IF;
END $$

DELIMITER ;

CALL roomease_admin_tools();
DROP PROCEDURE IF EXISTS roomease_admin_tools;

CREATE TABLE IF NOT EXISTS admin_actions (
    action_id     INT AUTO_INCREMENT PRIMARY KEY,
    admin_id      INT NULL,
    action        VARCHAR(40) NOT NULL,
    target_type   VARCHAR(20) NOT NULL,
    target_id     INT NULL,
    target_label  VARCHAR(200) NOT NULL DEFAULT '',
    detail        VARCHAR(500) DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_actions_created (created_at),
    KEY idx_actions_target  (target_type, target_id, created_at),
    KEY idx_actions_admin   (admin_id, created_at),
    CONSTRAINT fk_actions_admin FOREIGN KEY (admin_id)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
