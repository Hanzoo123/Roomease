-- =========================================================
-- RoomEase migration: room_type becomes a real foreign key
-- =========================================================
-- Replaces boarding_houses.room_type (a free VARCHAR(50)) with
-- boarding_houses.room_type_id, constrained to room_types.
--
--   mysql -u root -p roomease < database/migration_room_type_fk.sql
--
-- Why: room_types already existed as a lookup table, and both the listing
-- form and the browse filter read from it, but nothing stopped the stored
-- value from being anything at all. The two agreed only because the form
-- offered no other choice. That lookup table was added in the first place
-- because the form and the filter had each carried their own hard-coded
-- list and the values had drifted apart, so filtering returned nothing.
-- This makes the drift impossible rather than merely unlikely.
--
-- ON DELETE RESTRICT, not CASCADE: deleting a room type that listings are
-- using should be refused, not silently delete those listings. This is the
-- one relationship in the schema that deliberately does not cascade.
--
-- THIS MIGRATION ABORTS if any existing row's room_type does not match a
-- row in room_types, rather than quietly leaving those listings with no
-- room type. If it stops, fix or add the offending values and re-run.
--
-- Safe to run more than once.
-- =========================================================

USE roomease;

DELIMITER $$

DROP PROCEDURE IF EXISTS roomease_room_type_fk $$

CREATE PROCEDURE roomease_room_type_fk()
BEGIN
    DECLARE v_unmatched INT DEFAULT 0;
    DECLARE v_has_old   INT DEFAULT 0;
    DECLARE v_msg       VARCHAR(255);

    SELECT COUNT(*) INTO v_has_old
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'boarding_houses'
       AND COLUMN_NAME  = 'room_type';

    -- Already migrated: the old column is gone. Nothing to do.
    IF v_has_old = 0 THEN
        SELECT 'room_type_id is already in place; nothing to migrate.' AS status;
    ELSE
        -- 1. Refuse to run if the data would not survive the move.
        SELECT COUNT(*) INTO v_unmatched
          FROM boarding_houses bh
          LEFT JOIN room_types rt ON rt.room_type_name = bh.room_type
         WHERE bh.room_type IS NOT NULL
           AND bh.room_type <> ''
           AND rt.room_type_id IS NULL;

        IF v_unmatched > 0 THEN
            SET v_msg = CONCAT('Aborted: ', v_unmatched,
                ' listing(s) have a room_type with no matching row in room_types. ',
                'Add those values to room_types (or correct the listings) and re-run.');
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
        END IF;

        -- 2. Add the new column.
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'boarding_houses'
               AND COLUMN_NAME  = 'room_type_id'
        ) THEN
            ALTER TABLE boarding_houses
                ADD COLUMN room_type_id INT NULL DEFAULT NULL AFTER room_type;
        END IF;

        -- 3. Backfill by name.
        UPDATE boarding_houses bh
          JOIN room_types rt ON rt.room_type_name = bh.room_type
           SET bh.room_type_id = rt.room_type_id
         WHERE bh.room_type_id IS NULL;

        -- 4. The old composite index names the column we are about to drop.
        IF EXISTS (
            SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'boarding_houses'
               AND INDEX_NAME   = 'idx_bh_public_type'
        ) THEN
            DROP INDEX idx_bh_public_type ON boarding_houses;
        END IF;

        -- 5. Constrain it. InnoDB adds its own index on room_type_id here.
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME   = 'fk_bh_room_type'
        ) THEN
            ALTER TABLE boarding_houses
                ADD CONSTRAINT fk_bh_room_type FOREIGN KEY (room_type_id)
                    REFERENCES room_types(room_type_id) ON DELETE RESTRICT;
        END IF;

        -- 6. Rebuild the browse index against the new column.
        CREATE INDEX idx_bh_public_type ON boarding_houses
            (moderation_status, availability_status, room_type_id, created_at);

        -- 7. Retire the free-text column.
        ALTER TABLE boarding_houses DROP COLUMN room_type;

        SELECT 'room_type replaced by room_type_id.' AS status;
    END IF;
END $$

DELIMITER ;

CALL roomease_room_type_fk();
DROP PROCEDURE IF EXISTS roomease_room_type_fk;
