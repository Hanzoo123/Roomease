-- =========================================================
-- RoomEase migration: soft-deleted user accounts
-- =========================================================
-- Adds users.deleted_at so an administrator can retire an account
-- without destroying everything attached to it.
--
--   mysql -u root -p roomease < database/migration_soft_delete.sql
--
-- Why: every foreign key in this schema uses ON DELETE CASCADE, and the
-- chain users -> boarding_houses -> images means one DELETE on a landlord
-- erased their listings, every photo row attached to them, and every
-- boarder's saved copy of those listings, with no undo. The admin panel
-- now sets deleted_at instead, which is reversible.
--
-- The cascades themselves are deliberately left in place: they are still
-- the correct behaviour for a genuine, intentional hard delete run
-- against the database. What changed is that the application no longer
-- issues one.
--
-- Safe to run more than once.
-- =========================================================

USE roomease;

DELIMITER $$

DROP PROCEDURE IF EXISTS roomease_add_soft_delete $$

CREATE PROCEDURE roomease_add_soft_delete()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'users'
           AND COLUMN_NAME  = 'deleted_at'
    ) THEN
        ALTER TABLE users
            ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER is_active;
    END IF;

    -- Every account lookup now also asks "and not archived", so the two
    -- columns that decide whether an account is usable are indexed together.
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'users'
           AND INDEX_NAME   = 'idx_users_live'
    ) THEN
        CREATE INDEX idx_users_live ON users (deleted_at, is_active, role);
    END IF;
END $$

DELIMITER ;

CALL roomease_add_soft_delete();
DROP PROCEDURE IF EXISTS roomease_add_soft_delete;

-- No backfill is needed: the column defaults to NULL, and NULL is exactly
-- what "has never been archived" means, which is true of every existing row.
