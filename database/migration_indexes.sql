-- =========================================================
-- RoomEase migration: query indexes
-- =========================================================
-- Adds the indexes the application's own queries need. Before this,
-- the only indexes in the schema were the primary keys, the unique
-- keys, and the ones InnoDB creates for foreign keys — so the browse
-- page read every row of boarding_houses and then sorted the result
-- in memory on every single request.
--
--   mysql -u root -p roomease < database/migration_indexes.sql
--
-- This changes no data and no application code, and every statement is
-- reversible with DROP INDEX. A fresh import of roomease.sql already
-- includes all of these.
--
-- Safe to run more than once: each index is created only if a index of
-- that name is not already on the table.
-- =========================================================

USE roomease;

DELIMITER $$

DROP PROCEDURE IF EXISTS roomease_add_index $$

CREATE PROCEDURE roomease_add_index(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_cols  VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = p_table
           AND INDEX_NAME   = p_index
    ) THEN
        SET @ddl = CONCAT('CREATE INDEX `', p_index, '` ON `', p_table, '` (', p_cols, ')');
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DELIMITER ;

-- ---------------------------------------------------------
-- boarding_houses — the public browse page
--
-- Every browse query fixes both status columns and then orders by
-- created_at. Leading with the two equality columns lets InnoDB seek
-- straight to the matching rows and read created_at already in order,
-- which removes the sort as well as the scan.
-- ---------------------------------------------------------
CALL roomease_add_index('boarding_houses', 'idx_bh_public_recent',
     'moderation_status, availability_status, created_at');

-- Same two equality columns, then the range column, for "max rent"
-- and for ordering by price.
CALL roomease_add_index('boarding_houses', 'idx_bh_public_rent',
     'moderation_status, availability_status, monthly_rent');

-- Same again for the room type filter, with created_at on the end so that
-- filtering by room type keeps the ordering too. Without that fourth column
-- MySQL seeks correctly but then still sorts the matches in memory.
CALL roomease_add_index('boarding_houses', 'idx_bh_public_type',
     'moderation_status, availability_status, room_type, created_at');

-- ---------------------------------------------------------
-- boarding_houses — the two dashboards
--
-- The admin listing table and the admin dashboard order by created_at
-- with no status filter, so they cannot use the composites above.
-- ---------------------------------------------------------
CALL roomease_add_index('boarding_houses', 'idx_bh_created', 'created_at');

-- The landlord dashboard selects one landlord's listings, newest first.
-- The foreign key's own index covers landlord_id but not the ordering.
CALL roomease_add_index('boarding_houses', 'idx_bh_landlord_recent',
     'landlord_id, created_at');

-- ---------------------------------------------------------
-- images — the cover photo lookup
--
-- browse.php, saved.php and the landlord dashboard each run a
-- correlated subquery per listing:
--   SELECT image_path ... WHERE boarding_house_id = ?
--   ORDER BY is_primary DESC, image_id ASC LIMIT 1
-- Indexing all three columns in that order turns the per-listing lookup
-- into an index seek.
--
-- The ORDER BY mixes directions, so MySQL still sorts the rows it finds
-- rather than reading them in order. Matching that exactly would need
-- `is_primary DESC`, and it is not worth it: the sort covers only the
-- handful of photos belonging to one house. It would also be awkward to
-- change later, because once this index exists it is the one supporting
-- fk_images_bh and cannot simply be dropped.
-- ---------------------------------------------------------
CALL roomease_add_index('images', 'idx_images_cover',
     'boarding_house_id, is_primary, image_id');

-- ---------------------------------------------------------
-- favorites — a boarder's saved list
--
-- uq_favorite starts with user_id so it finds the rows, but it cannot
-- supply created_at order, which left a filesort on every visit.
-- ---------------------------------------------------------
CALL roomease_add_index('favorites', 'idx_fav_user_recent',
     'user_id, created_at');

DROP PROCEDURE IF EXISTS roomease_add_index;

-- ---------------------------------------------------------
-- Deliberately NOT included: a FULLTEXT index on
-- boarding_houses(name, address, description).
--
-- The search box uses LIKE '%term%', whose leading wildcard no index
-- can serve, so a FULLTEXT index would sit unused until browse.php is
-- rewritten to use MATCH ... AGAINST. That is a code change, and this
-- migration is deliberately schema-only.
-- ---------------------------------------------------------
