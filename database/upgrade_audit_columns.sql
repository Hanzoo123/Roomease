-- =========================================================
-- Upgrade: created_by, created_at, updated_by, updated_at
-- =========================================================
-- For a database created from an older boardinghouse.sql. Run it once, after
-- upgrade_audit_logs.sql if that one is also needed. A fresh import of
-- boardinghouse.sql already has these columns.
--
--   mysql -u root -p roomease < database/upgrade_audit_columns.sql
--
-- Every table people create and edit records in gets the same four columns,
-- in that order: who created the row and when, and who last changed it and
-- when. The *_by columns point at users and are set to NULL, never deleted,
-- when that account goes; NULL also means "created by the system or the seed
-- data". The full history of each change is in audit_logs.
--
-- login_attempts gets an optional user_id, set when the email typed belongs
-- to an account, so it is no longer a table on its own.
--
-- Existing rows are filled in from what is already known (a listing's rows
-- were created by its landlord), and "updated_at = updated_at" keeps each
-- row's last-changed time from jumping to the moment of this upgrade.
-- =========================================================

-- ---- users -------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN created_by INT NULL AFTER deleted_at,
    ADD COLUMN updated_by INT NULL AFTER created_at,
    ADD CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_users_updated_by FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL;

-- Landlords and boarders signed themselves up; administrators came with the system.
UPDATE users SET created_by = user_id, updated_at = updated_at WHERE role <> 'administrator';

-- ---- boarding_houses ---------------------------------------------------
ALTER TABLE boarding_houses
    ADD COLUMN created_by INT NULL AFTER longitude,
    ADD COLUMN updated_by INT NULL AFTER created_at,
    ADD CONSTRAINT fk_bh_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_bh_updated_by FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL;

UPDATE boarding_houses SET created_by = landlord_id, updated_by = landlord_id, updated_at = updated_at;

-- ---- rooms -------------------------------------------------------------
ALTER TABLE rooms
    ADD COLUMN created_by INT NULL AFTER description,
    ADD COLUMN updated_by INT NULL AFTER created_at,
    ADD CONSTRAINT fk_rooms_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_rooms_updated_by FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL;

UPDATE rooms r JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id
   SET r.created_by = bh.landlord_id, r.updated_by = bh.landlord_id, r.updated_at = r.updated_at;

-- ---- images ------------------------------------------------------------
-- uploaded_at already held when a photo was added; it becomes created_at.
ALTER TABLE images
    RENAME COLUMN uploaded_at TO created_at,
    ADD COLUMN created_by INT NULL AFTER is_primary,
    ADD COLUMN updated_by INT NULL AFTER created_at,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER updated_by,
    ADD CONSTRAINT fk_images_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_images_updated_by FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL;

UPDATE images i JOIN boarding_houses bh ON bh.boarding_house_id = i.boarding_house_id
   SET i.created_by = bh.landlord_id, i.updated_by = bh.landlord_id, i.updated_at = i.created_at;

-- ---- amenities, utilities, room_types ----------------------------------
-- When these rows were added was never recorded, so existing ones are dated
-- to this upgrade. A landlord's own entries were created by that landlord;
-- the built-in list has no creator.
ALTER TABLE amenities
    ADD COLUMN created_by INT NULL,
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN updated_by INT NULL,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_amenities_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_amenities_updated_by FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL;

UPDATE amenities SET created_by = landlord_id, updated_by = landlord_id, updated_at = updated_at
 WHERE landlord_id IS NOT NULL;

ALTER TABLE utilities
    ADD COLUMN created_by INT NULL,
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN updated_by INT NULL,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_utilities_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_utilities_updated_by FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL;

UPDATE utilities SET created_by = landlord_id, updated_by = landlord_id, updated_at = updated_at
 WHERE landlord_id IS NOT NULL;

ALTER TABLE room_types
    ADD COLUMN created_by INT NULL,
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN updated_by INT NULL,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_room_types_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_room_types_updated_by FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL;

-- ---- site_settings -----------------------------------------------------
ALTER TABLE site_settings
    ADD COLUMN created_by INT NULL AFTER setting_value,
    ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by,
    ADD COLUMN updated_by INT NULL AFTER created_at,
    ADD CONSTRAINT fk_settings_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL;

-- A setting was created no later than it was last changed.
UPDATE site_settings SET created_at = updated_at, updated_at = updated_at;

-- ---- login_attempts ----------------------------------------------------
ALTER TABLE login_attempts
    ADD COLUMN user_id INT NULL AFTER identifier,
    ADD CONSTRAINT fk_attempts_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL;

UPDATE login_attempts a JOIN users u ON u.email = a.identifier SET a.user_id = u.user_id;

-- ---- deleted_by --------------------------------------------------------
-- Accounts and listings are archived, not erased: deleted_at says when, and
-- deleted_by says who (an administrator, or a landlord deleting their own
-- listing). Restoring clears both. Earlier removals take their remover from
-- the audit log where it has one.
ALTER TABLE users
    ADD COLUMN deleted_by INT NULL AFTER deleted_at,
    ADD CONSTRAINT fk_users_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(user_id) ON DELETE SET NULL;

ALTER TABLE boarding_houses
    ADD COLUMN deleted_by INT NULL AFTER deleted_at,
    ADD CONSTRAINT fk_bh_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(user_id) ON DELETE SET NULL;

UPDATE users u
  JOIN (SELECT target_id, MAX(log_id) AS log_id FROM audit_logs
         WHERE action = 'user_remove' GROUP BY target_id) latest ON latest.target_id = u.user_id
  JOIN audit_logs a ON a.log_id = latest.log_id
   SET u.deleted_by = a.actor_id, u.updated_at = u.updated_at
 WHERE u.deleted_at IS NOT NULL;

UPDATE boarding_houses bh
  JOIN (SELECT target_id, MAX(log_id) AS log_id FROM audit_logs
         WHERE action IN ('listing_remove', 'listing_delete') GROUP BY target_id) latest
    ON latest.target_id = bh.boarding_house_id
  JOIN audit_logs a ON a.log_id = latest.log_id
   SET bh.deleted_by = a.actor_id, bh.updated_at = bh.updated_at
 WHERE bh.deleted_at IS NOT NULL;
