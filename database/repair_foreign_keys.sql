-- =========================================================
-- Repair: the foreign keys a drifted database is missing
-- =========================================================
-- For a database that no longer matches boardinghouse.sql: rooms stored in
-- the old MyISAM engine, which cannot hold a foreign key, and seven links the
-- schema defines but the database lacks. A database imported from
-- boardinghouse.sql already has all of this and does not need it.
--
--   mysql -u root -p roomease < database/repair_foreign_keys.sql
--
-- Nothing is deleted. The first query lists rows that point at something
-- that no longer exists; if any count is above 0, MySQL refuses the matching
-- ALTER below with a foreign key error, and those rows need fixing first.
-- =========================================================

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'rooms whose listing is gone' AS orphan_check, COUNT(*) AS found
  FROM rooms r LEFT JOIN boarding_houses b ON b.boarding_house_id = r.boarding_house_id
 WHERE b.boarding_house_id IS NULL
UNION ALL
SELECT 'rooms whose room type is gone', COUNT(*)
  FROM rooms r LEFT JOIN room_types t ON t.room_type_id = r.room_type_id
 WHERE t.room_type_id IS NULL
UNION ALL
SELECT 'photos whose listing is gone', COUNT(*)
  FROM images i LEFT JOIN boarding_houses b ON b.boarding_house_id = i.boarding_house_id
 WHERE b.boarding_house_id IS NULL
UNION ALL
SELECT 'photos whose room is gone', COUNT(*)
  FROM images i LEFT JOIN rooms r ON r.room_id = i.room_id
 WHERE i.room_id IS NOT NULL AND r.room_id IS NULL
UNION ALL
SELECT 'reset codes whose account is gone', COUNT(*)
  FROM password_resets p LEFT JOIN users u ON u.user_id = p.user_id
 WHERE u.user_id IS NULL
UNION ALL
SELECT 'remembered devices whose account is gone', COUNT(*)
  FROM remember_tokens t LEFT JOIN users u ON u.user_id = t.user_id
 WHERE u.user_id IS NULL
UNION ALL
SELECT 'custom utilities whose landlord is gone', COUNT(*)
  FROM utilities x LEFT JOIN users u ON u.user_id = x.landlord_id
 WHERE x.landlord_id IS NOT NULL AND u.user_id IS NULL;

-- InnoDB, like every other table, so rooms can hold foreign keys at all.
ALTER TABLE rooms ENGINE = InnoDB;

ALTER TABLE rooms
    ADD CONSTRAINT fk_rooms_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_rooms_type FOREIGN KEY (room_type_id)
        REFERENCES room_types(room_type_id) ON DELETE RESTRICT;

ALTER TABLE images
    ADD CONSTRAINT fk_images_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_images_room FOREIGN KEY (room_id)
        REFERENCES rooms(room_id) ON DELETE CASCADE;

ALTER TABLE password_resets
    ADD CONSTRAINT fk_reset_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE;

ALTER TABLE remember_tokens
    ADD CONSTRAINT fk_remember_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE;

ALTER TABLE utilities
    ADD CONSTRAINT fk_utilities_landlord FOREIGN KEY (landlord_id)
        REFERENCES users(user_id) ON DELETE CASCADE;
