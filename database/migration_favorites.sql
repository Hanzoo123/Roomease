-- =========================================================
-- RoomEase migration: saved listings for boarders
-- =========================================================
-- Run once against an EXISTING roomease database. A fresh import of
-- roomease.sql already includes this table.
--
--   mysql -u root -p roomease < database/migration_favorites.sql
--
-- The unique key on (user_id, boarding_house_id) makes saving idempotent,
-- so a double-submitted form cannot create a duplicate row. Both foreign
-- keys cascade, so deleting a user or a listing clears its saves.
-- =========================================================

USE roomease;

CREATE TABLE IF NOT EXISTS favorites (
    favorite_id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    boarding_house_id   INT NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_favorite (user_id, boarding_house_id),
    CONSTRAINT fk_fav_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_fav_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT COUNT(*) AS saved_listings FROM favorites;
