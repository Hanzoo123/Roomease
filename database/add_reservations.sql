-- =========================================================
-- RoomEase: add the reservations table to an existing database
-- =========================================================
-- For a database imported before reservations existed. Keeps every
-- existing row; safe to run twice. A fresh import of roomease.sql
-- already includes this table.
--
-- Run: phpMyAdmin > roomease > Import, or
--      mysql -u root roomease < database/add_reservations.sql
-- =========================================================

USE roomease;

-- A boarder's request for one room, which the landlord accepts or declines.
-- A request, not a payment: any fee is settled with the landlord directly.
-- Accepting takes one of the room's slots (rooms.slots_taken).
CREATE TABLE IF NOT EXISTS reservations (
    reservation_id      INT AUTO_INCREMENT PRIMARY KEY,
    room_id             INT NOT NULL,
    boarder_id          INT NOT NULL,
    move_in_date        DATE NOT NULL,
    message             VARCHAR(500) DEFAULT NULL,
    status              ENUM('pending', 'accepted', 'declined', 'cancelled') NOT NULL DEFAULT 'pending',
    -- The landlord's reason when declining.
    landlord_note       VARCHAR(500) DEFAULT NULL,
    decided_at          TIMESTAMP NULL DEFAULT NULL,
    created_by          INT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by          INT NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- A room's requests by status, and a boarder's requests, newest first.
    KEY idx_res_room_status (room_id, status),
    KEY idx_res_boarder_recent (boarder_id, created_at),
    CONSTRAINT fk_res_room FOREIGN KEY (room_id)
        REFERENCES rooms(room_id) ON DELETE CASCADE,
    CONSTRAINT fk_res_boarder FOREIGN KEY (boarder_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_res_created_by FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_res_updated_by FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
