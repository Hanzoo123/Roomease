-- =========================================================
-- RoomEase: A Web-Based Boarding House Information and
-- Listing System — Database Schema
-- =========================================================
-- Target DBMS: MySQL 8.0+ / MariaDB 10.4+
-- Database: roomease
-- =========================================================

CREATE DATABASE IF NOT EXISTS roomease CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE roomease;

-- Disable foreign key checks for clean teardown/rebuild
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS favorites;
DROP TABLE IF EXISTS images;
DROP TABLE IF EXISTS boarding_house_utilities;
DROP TABLE IF EXISTS boarding_house_amenities;
DROP TABLE IF EXISTS utilities;
DROP TABLE IF EXISTS amenities;
DROP TABLE IF EXISTS room_types;
DROP TABLE IF EXISTS boarding_houses;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------
-- 1. Table: users
-- Stores information for all registered individuals accessing the system.
-- ---------------------------------------------------------
CREATE TABLE users (
    user_id         INT AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(150) NOT NULL UNIQUE,
    -- Google's stable account id ("sub") for accounts that sign in with Google.
    google_id       VARCHAR(64) DEFAULT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    first_name      VARCHAR(100) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    phone_number    VARCHAR(30) DEFAULT NULL,
    role            ENUM('administrator', 'landlord', 'boarder') NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    -- Set when an administrator removes the account. Archiving instead of
    -- deleting keeps the cascades below from destroying the listings,
    -- photos and saved copies attached to it.
    deleted_at      DATETIME NULL DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_users_live (deleted_at, is_active, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 2. Table: boarding_houses
-- The central table for property listings, managed by landlords.
-- ---------------------------------------------------------
CREATE TABLE boarding_houses (
    boarding_house_id   INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id         INT NOT NULL,
    name                VARCHAR(150) NOT NULL,
    address             TEXT NOT NULL,
    monthly_rent        DECIMAL(10, 2) NOT NULL,
    reservation_fee     DECIMAL(10, 2) DEFAULT NULL,
    room_type_id        INT DEFAULT NULL,
    room_capacity       INT NOT NULL DEFAULT 1,
    availability_status ENUM('available', 'unavailable') NOT NULL DEFAULT 'available',
    moderation_status   ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    rejection_reason    VARCHAR(500) DEFAULT NULL,
    moderated_at        TIMESTAMP NULL DEFAULT NULL,
    description         TEXT DEFAULT NULL,
    contact_number      VARCHAR(50) DEFAULT NULL,
    house_rules         TEXT DEFAULT NULL,
    -- Stay terms and map pin (see migration_stay_terms.sql). NULL means the
    -- landlord has not stated it, and the listing page leaves it out.
    curfew              VARCHAR(60) DEFAULT NULL,
    security_deposit    DECIMAL(10, 2) DEFAULT NULL,
    minimum_stay_months TINYINT UNSIGNED DEFAULT NULL,
    payment_methods     VARCHAR(100) DEFAULT NULL,
    gender_policy       ENUM('any', 'female', 'male') DEFAULT NULL,
    visitors_allowed    TINYINT(1) DEFAULT NULL,
    pets_allowed        TINYINT(1) DEFAULT NULL,
    cooking_allowed     TINYINT(1) DEFAULT NULL,
    latitude            DECIMAL(9, 6) DEFAULT NULL,
    longitude           DECIMAL(9, 6) DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Indexes for the queries the application actually runs. The two status
    -- columns lead because every browse query fixes both, and the ordering
    -- column comes last so the same index supplies the sort as well.
    KEY idx_bh_public_recent   (moderation_status, availability_status, created_at),
    KEY idx_bh_public_rent     (moderation_status, availability_status, monthly_rent),
    KEY idx_bh_public_type     (moderation_status, availability_status, room_type_id, created_at),
    KEY idx_bh_created         (created_at),
    KEY idx_bh_landlord_recent (landlord_id, created_at),
    -- fk_bh_room_type is added further down, once room_types exists.
    CONSTRAINT fk_bh_landlord FOREIGN KEY (landlord_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 3. Table: amenities
-- Stores a pre-defined list of possible amenities.
-- ---------------------------------------------------------
CREATE TABLE amenities (
    amenity_id      INT AUTO_INCREMENT PRIMARY KEY,
    amenity_name    VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 4. Table: utilities
-- Stores a pre-defined list of possible utilities.
-- ---------------------------------------------------------
CREATE TABLE utilities (
    utility_id      INT AUTO_INCREMENT PRIMARY KEY,
    utility_name    VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 5. Table: room_types
-- Controlled vocabulary for boarding_houses.room_type_id, so the
-- listing form and the browse filter always offer the same values.
-- ---------------------------------------------------------
CREATE TABLE room_types (
    room_type_id    INT AUTO_INCREMENT PRIMARY KEY,
    room_type_name  VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- boarding_houses is declared above this table, so the constraint that ties a
-- listing's room type to this vocabulary is added here, once it exists.
--
-- RESTRICT, not CASCADE: deleting a room type that listings are using should
-- be refused, never silently delete those listings. This is the one
-- relationship in the schema that deliberately does not cascade.
ALTER TABLE boarding_houses
    ADD CONSTRAINT fk_bh_room_type FOREIGN KEY (room_type_id)
        REFERENCES room_types(room_type_id) ON DELETE RESTRICT;

-- ---------------------------------------------------------
-- 6. Table: boarding_house_amenities
-- Junction (many-to-many) table linking boarding houses to amenities.
-- ---------------------------------------------------------
CREATE TABLE boarding_house_amenities (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    boarding_house_id   INT NOT NULL,
    amenity_id          INT NOT NULL,
    is_available        BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_bh_amenity (boarding_house_id, amenity_id),
    CONSTRAINT fk_bha_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE,
    CONSTRAINT fk_bha_amenity FOREIGN KEY (amenity_id)
        REFERENCES amenities(amenity_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 7. Table: boarding_house_utilities
-- Junction (many-to-many) linking boarding houses to utilities and policies.
-- ---------------------------------------------------------
CREATE TABLE boarding_house_utilities (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    boarding_house_id   INT NOT NULL,
    utility_id          INT NOT NULL,
    billing_policy      VARCHAR(150) DEFAULT NULL,
    UNIQUE KEY uq_bh_utility (boarding_house_id, utility_id),
    CONSTRAINT fk_bhu_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE,
    CONSTRAINT fk_bhu_utility FOREIGN KEY (utility_id)
        REFERENCES utilities(utility_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 8. Table: images
-- Stores property photographs uploaded by landlords.
-- ---------------------------------------------------------
CREATE TABLE images (
    image_id            INT AUTO_INCREMENT PRIMARY KEY,
    boarding_house_id   INT NOT NULL,
    image_path          VARCHAR(255) NOT NULL,
    is_primary          BOOLEAN NOT NULL DEFAULT FALSE,
    uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Serves the per-listing cover photo lookup in browse and both dashboards.
    KEY idx_images_cover (boarding_house_id, is_primary, image_id),
    CONSTRAINT fk_images_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 9. Table: favorites
-- Listings a prospective boarder has saved for later. The unique key
-- makes saving idempotent, so a double submit cannot duplicate a row.
-- ---------------------------------------------------------
CREATE TABLE favorites (
    favorite_id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    boarding_house_id   INT NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_favorite (user_id, boarding_house_id),
    -- The saved list is read by user, newest first.
    KEY idx_fav_user_recent (user_id, created_at),
    CONSTRAINT fk_fav_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_fav_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 10. Table: password_resets
-- One row per "forgot password" request. Only a SHA-256 hash of the
-- token is stored, so a leaked database still cannot be used to reset
-- anyone's password. Rows are single use (used_at) and short lived
-- (expires_at).
-- ---------------------------------------------------------
CREATE TABLE password_resets (
    reset_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_user (user_id),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 11. Table: remember_tokens
-- "Remember me" devices. The cookie holds selector:validator and only a
-- SHA-256 hash of the validator is stored. Each row is single use: signing
-- in from the cookie replaces it (see migration_auth_extras.sql).
-- ---------------------------------------------------------
CREATE TABLE remember_tokens (
    token_id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    selector        CHAR(24) NOT NULL,
    validator_hash  CHAR(64) NOT NULL,
    expires_at      DATETIME NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_remember_selector (selector),
    KEY idx_remember_user (user_id),
    KEY idx_remember_expires (expires_at),
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 12. Table: site_settings
-- Settings an administrator changes from the panel, such as the sign-in
-- pages' background (admin/appearance.php).
-- ---------------------------------------------------------
CREATE TABLE site_settings (
    setting_key     VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value   TEXT DEFAULT NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- SEED DATA
-- =========================================================

-- 1. Default Amenities
INSERT INTO amenities (amenity_name) VALUES
('Wi-Fi'),
('Air Conditioning'),
('Private Bathroom'),
('Kitchen Access'),
('Laundry Area'),
('Study Table & Chair'),
('CCTV & 24/7 Security'),
('Refrigerator Access'),
('Gated Compound'),
('Near VSU / Transport Terminal');

-- 2. Default Utilities
INSERT INTO utilities (utility_name) VALUES
('Water'),
('Electricity'),
('Trash Collection'),
('Internet / Wi-Fi'),
('Cooking Gas');

-- 3. Default Room Types
INSERT INTO room_types (room_type_name) VALUES
('Single Room'),
('Double Sharing'),
('Bed Spacer'),
('Dormitory'),
('Private Room');

-- 4. Administrator account
--
-- The password hash below is a deliberate placeholder: it is not a valid
-- bcrypt hash, so password_verify() can never match it and the account
-- cannot be signed into until a password is set. This replaces the old
-- seeded password, which was printed in the README and was therefore
-- public knowledge. Set a real password before first use:
--
--   php database/set_admin_password.php "YourStrongPassword"
--
INSERT INTO users (user_id, email, password_hash, first_name, last_name, phone_number, role, is_active) VALUES
(1, 'admin@roomease.local', 'LOCKED-run-database/set_admin_password.php', 'System', 'Administrator', '09000000000', 'administrator', TRUE);

-- Demo accounts and sample listings are NOT part of this schema. They live in
-- database/seed_demo.sql, so a real deployment can import the schema without
-- also creating accounts whose password is published in this repository.
-- For a walkthrough or a defence demo, import that file as well.

-- Reset auto-increment counter to avoid conflicts
ALTER TABLE users AUTO_INCREMENT = 10;
ALTER TABLE boarding_houses AUTO_INCREMENT = 10;
