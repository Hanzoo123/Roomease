-- =========================================================
-- RoomEase migration: administrator notes on an account
-- =========================================================
-- Adds account_notes: short notes administrators leave on a landlord's or
-- boarder's account, visible only inside the admin panel.
--
--   mysql -u root -p roomease < database/migration_account_notes.sql
--
-- Why this is separate from admin_actions: the activity log records what was
-- *done* to an account and is written by the code, never by hand. A note is
-- what an administrator *observed* — "warned about photo quality", "says they
-- will re-upload on Monday" — and putting the two in one table would mean
-- either a log full of prose or notes that cannot be deleted. They are
-- different kinds of record, so they get different tables.
--
-- Notes are deliberately not written to the activity log either: each one
-- already carries its author and its time, and a log entry per note would
-- bury the approvals and removals that log exists for.
--
-- admin_id is SET NULL rather than CASCADE, matching admin_actions: if the
-- administrator who wrote a note is ever hard-deleted, the note survives and
-- reads as written by "an administrator". user_id cascades, because a note
-- about an account that no longer exists is not about anything.
--
-- Safe to run more than once. A fresh import of roomease.sql already has it.
-- =========================================================

USE roomease;

CREATE TABLE IF NOT EXISTS account_notes (
    note_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    admin_id    INT NULL,
    body        VARCHAR(1000) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Serves the per-account lookup, newest first, which is the only read.
    KEY idx_account_notes (user_id, created_at),
    CONSTRAINT fk_notes_user  FOREIGN KEY (user_id)  REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_admin FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
