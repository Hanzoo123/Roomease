-- =========================================================
-- RoomEase: A Web-Based Boarding House Information and
-- Listing System — Full database export
-- =========================================================
-- Target: MySQL 8.0+ / MariaDB 10.4+
--
-- Creates every table, with only the admin account and the lists the forms
-- need (room types, amenities, utilities). No landlords, boarders or listings.
--
-- Import: mysql -u root < database/roomease.sql  (or phpMyAdmin > Import).
-- It creates the `roomease` database if needed.
--
-- WARNING: drops and recreates every table. Existing data is lost.
-- =========================================================

CREATE DATABASE IF NOT EXISTS roomease
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE roomease;

-- Foreign key checks off, so the tables can be dropped in any order.
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS account_notes;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS admin_actions;
DROP TABLE IF EXISTS site_settings;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS remember_tokens;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS favorites;
DROP TABLE IF EXISTS images;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS boarding_house_utilities;
DROP TABLE IF EXISTS boarding_house_amenities;
DROP TABLE IF EXISTS utilities;
DROP TABLE IF EXISTS amenities;
DROP TABLE IF EXISTS room_types;
DROP TABLE IF EXISTS boarding_houses;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;


-- =========================================================
-- ACCOUNTS
-- =========================================================

-- ---------------------------------------------------------
-- 1. users
-- Admins, landlords and boarders. Removing an account sets deleted_at
-- instead of deleting the row, so its listings and photos are kept.
-- ---------------------------------------------------------
CREATE TABLE users (
    user_id         INT AUTO_INCREMENT PRIMARY KEY,
    -- Empty only for a new admin, until their first sign-in.
    email           VARCHAR(150) NULL,
    -- Admins only: sign in with this or the email.
    username        VARCHAR(30) NULL DEFAULT NULL,
    google_id       VARCHAR(64) DEFAULT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    first_name      VARCHAR(100) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    phone_number    VARCHAR(30) DEFAULT NULL,
    -- Profile photo path. NULL shows initials instead.
    avatar_path     VARCHAR(255) NULL DEFAULT NULL,
    role            ENUM('administrator', 'landlord', 'boarder') NOT NULL,
    -- 1 = super admin: can also manage other admins and Appearance.
    is_super_admin  TINYINT(1) NOT NULL DEFAULT 0,
    -- 1 = temporary password set by a super admin; must be changed.
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at      DATETIME NULL DEFAULT NULL,
    deleted_by      INT NULL,
    -- Who created / last changed it. NULL = the system.
    created_by      INT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by      INT NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY email (email),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_google_id (google_id),
    -- Account lists filter on these three together.
    KEY idx_users_live (deleted_at, is_active, role),
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_users_updated_by FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_users_deleted_by FOREIGN KEY (deleted_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- LISTINGS
-- =========================================================

-- ---------------------------------------------------------
-- 2. boarding_houses
-- A listing, owned by one landlord. Rent, type and capacity are per room.
-- Only 'approved' listings are public. Removing sets deleted_at (restorable).
-- ---------------------------------------------------------
CREATE TABLE boarding_houses (
    boarding_house_id   INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id         INT NOT NULL,
    name                VARCHAR(150) NOT NULL,
    address             TEXT NOT NULL,
    moderation_status   ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    rejection_reason    VARCHAR(500) DEFAULT NULL,
    moderated_at        TIMESTAMP NULL DEFAULT NULL,
    moderated_by        INT DEFAULT NULL,
    description         TEXT DEFAULT NULL,
    contact_number      VARCHAR(50) DEFAULT NULL,
    house_rules         TEXT DEFAULT NULL,
    -- Stay terms and map pin. NULL = not stated.
    curfew              VARCHAR(60) DEFAULT NULL,
    minimum_stay_months TINYINT UNSIGNED DEFAULT NULL,
    payment_methods     VARCHAR(100) DEFAULT NULL,
    gender_policy       ENUM('any', 'female', 'male') DEFAULT NULL,
    visitors_allowed    TINYINT(1) DEFAULT NULL,
    pets_allowed        TINYINT(1) DEFAULT NULL,
    cooking_allowed     TINYINT(1) DEFAULT NULL,
    latitude            DECIMAL(9, 6) DEFAULT NULL,
    longitude           DECIMAL(9, 6) DEFAULT NULL,
    -- Who created / last changed it.
    created_by          INT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by          INT NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME NULL DEFAULT NULL,
    -- Who removed it (an admin, or the landlord).
    deleted_by          INT NULL,
    -- For browse: filter by status, sort by date.
    KEY idx_bh_public_recent   (moderation_status, created_at),
    KEY idx_bh_created         (created_at),
    KEY idx_bh_landlord_recent (landlord_id, created_at),
    KEY idx_bh_deleted         (deleted_at),
    CONSTRAINT fk_bh_landlord FOREIGN KEY (landlord_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_bh_moderated_by FOREIGN KEY (moderated_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_bh_created_by FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_bh_updated_by FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_bh_deleted_by FOREIGN KEY (deleted_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 3. room_types
-- The room types the room form and browse filter offer.
-- ---------------------------------------------------------
CREATE TABLE room_types (
    room_type_id    INT AUTO_INCREMENT PRIMARY KEY,
    room_type_name  VARCHAR(50) NOT NULL,
    created_by      INT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by      INT NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY room_type_name (room_type_name),
    CONSTRAINT fk_room_types_created_by FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_room_types_updated_by FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 4. amenities
-- landlord_id NULL = made by the admin, usable by all landlords.
-- Otherwise only that landlord can use it.
-- ---------------------------------------------------------
CREATE TABLE amenities (
    amenity_id      INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id     INT DEFAULT NULL,
    amenity_name    VARCHAR(100) NOT NULL,
    created_by      INT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by      INT NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_amenities_owner_name (landlord_id, amenity_name),
    CONSTRAINT fk_amenities_landlord FOREIGN KEY (landlord_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_amenities_created_by FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_amenities_updated_by FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 5. utilities
-- Same ownership rule as amenities.
-- ---------------------------------------------------------
CREATE TABLE utilities (
    utility_id      INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id     INT DEFAULT NULL,
    utility_name    VARCHAR(100) NOT NULL,
    created_by      INT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by      INT NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_utilities_owner_name (landlord_id, utility_name),
    CONSTRAINT fk_utilities_landlord FOREIGN KEY (landlord_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_utilities_created_by FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_utilities_updated_by FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 6. boarding_house_amenities
-- Which amenities a listing offers (many-to-many).
-- ---------------------------------------------------------
CREATE TABLE boarding_house_amenities (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    boarding_house_id   INT NOT NULL,
    amenity_id          INT NOT NULL,
    UNIQUE KEY uq_bh_amenity (boarding_house_id, amenity_id),
    CONSTRAINT fk_bha_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE,
    CONSTRAINT fk_bha_amenity FOREIGN KEY (amenity_id)
        REFERENCES amenities(amenity_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 7. boarding_house_utilities
-- Which utilities a listing bills for, and how (many-to-many).
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
-- 8. rooms
-- Availability is not stored: it comes from is_open, capacity and slots_taken.
-- RESTRICT: a room type in use can't be deleted.
-- ---------------------------------------------------------
CREATE TABLE rooms (
    room_id             INT AUTO_INCREMENT PRIMARY KEY,
    boarding_house_id   INT NOT NULL,
    name                VARCHAR(60) NOT NULL,
    room_type_id        INT NOT NULL,
    monthly_rent        DECIMAL(10, 2) NOT NULL,
    capacity            SMALLINT NOT NULL DEFAULT 1,
    slots_taken         SMALLINT NOT NULL DEFAULT 0,
    is_open             TINYINT(1) NOT NULL DEFAULT 1,
    description         VARCHAR(500) DEFAULT NULL,
    created_by          INT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by          INT NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rooms_house_name (boarding_house_id, name),
    KEY idx_rooms_type_rent (room_type_id, is_open, monthly_rent),
    CONSTRAINT chk_rooms_capacity CHECK (capacity BETWEEN 1 AND 100),
    CONSTRAINT chk_rooms_slots CHECK (slots_taken >= 0 AND slots_taken <= capacity),
    CONSTRAINT fk_rooms_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE,
    CONSTRAINT fk_rooms_type FOREIGN KEY (room_type_id)
        REFERENCES room_types(room_type_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rooms_created_by FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_rooms_updated_by FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 9. images
-- room_id NULL = a house photo. is_primary = the cover (or a room's main
-- photo). The files are in assets/uploads/; only the path is stored.
-- ---------------------------------------------------------
CREATE TABLE images (
    image_id            INT AUTO_INCREMENT PRIMARY KEY,
    boarding_house_id   INT NOT NULL,
    room_id             INT DEFAULT NULL,
    image_path          VARCHAR(255) NOT NULL,
    is_primary          TINYINT(1) NOT NULL DEFAULT 0,
    created_by          INT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by          INT NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- For finding each listing's cover photo.
    KEY idx_images_cover (boarding_house_id, is_primary, image_id),
    KEY idx_images_room  (room_id, is_primary, image_id),
    CONSTRAINT fk_images_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE,
    CONSTRAINT fk_images_room FOREIGN KEY (room_id)
        REFERENCES rooms(room_id) ON DELETE CASCADE,
    CONSTRAINT fk_images_created_by FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_images_updated_by FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 10. favorites
-- Listings a boarder saved. The unique key stops duplicates.
-- ---------------------------------------------------------
CREATE TABLE favorites (
    favorite_id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    boarding_house_id   INT NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_favorite (user_id, boarding_house_id),
    -- A user's saved list, newest first.
    KEY idx_fav_user_recent (user_id, created_at),
    CONSTRAINT fk_fav_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_fav_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- SIGN-IN AND SECURITY
-- =========================================================

-- ---------------------------------------------------------
-- 11. password_resets
-- One row per reset request. Only hashes of the code and token are stored.
-- The code dies after 5 wrong tries; each row is single use and expires.
-- ---------------------------------------------------------
CREATE TABLE password_resets (
    reset_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    -- A random placeholder until the code is entered correctly.
    token_hash  CHAR(64) NOT NULL,
    code_hash   VARCHAR(255) DEFAULT NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    verified_at DATETIME DEFAULT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_user (user_id),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 12. remember_tokens
-- "Remember me" devices. Only a hash of the cookie's validator is stored.
-- Each token is used once, then replaced.
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
-- 13. login_attempts
-- Failed logins and reset requests, counted per account and per IP
-- to limit guessing.
-- ---------------------------------------------------------
CREATE TABLE login_attempts (
    attempt_id   BIGINT AUTO_INCREMENT PRIMARY KEY,
    -- 'login', 'reset' or 'contact'.
    kind         VARCHAR(20) NOT NULL,
    -- The email (or username) typed, lowercased.
    identifier   VARCHAR(190) NOT NULL,
    -- Its account, or NULL if none.
    user_id      INT NULL,
    -- 45 characters fits IPv6.
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_kind_identifier_time (kind, identifier, attempted_at),
    KEY idx_kind_ip_time (kind, ip_address, attempted_at),
    KEY idx_attempted_at (attempted_at),
    CONSTRAINT fk_attempts_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- ADMINISTRATION
-- =========================================================

-- ---------------------------------------------------------
-- 14. site_settings
-- Settings an admin changes, e.g. the sign-in background. Key/value, so a
-- new setting needs no new column.
-- ---------------------------------------------------------
CREATE TABLE site_settings (
    setting_key     VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value   TEXT DEFAULT NULL,
    -- Who created / last changed it.
    created_by      INT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by      INT NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_created_by FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 15. audit_logs
-- Who did what, and when (admin/activity.php). target_label keeps the name
-- as it was, so entries still make sense after a rename. IP and browser are
-- kept for sign-ins only, and deleted after 90 days.
-- ---------------------------------------------------------
CREATE TABLE audit_logs (
    log_id        INT AUTO_INCREMENT PRIMARY KEY,
    actor_id      INT NULL,
    actor_role    VARCHAR(20) NULL,
    action        VARCHAR(40) NOT NULL,
    target_type   VARCHAR(20) NOT NULL,
    target_id     INT NULL,
    target_label  VARCHAR(200) NOT NULL DEFAULT '',
    detail        VARCHAR(500) DEFAULT NULL,
    ip_address    VARCHAR(45) NULL,
    user_agent    VARCHAR(120) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_created (created_at),
    KEY idx_audit_target  (target_type, target_id, created_at),
    KEY idx_audit_actor   (actor_id, created_at),
    KEY idx_audit_action  (action, created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- =========================================================
-- SEED DATA: only what the site needs to work.
-- =========================================================

-- 1. The super admin. Sign in at /admin/login.php as "admin".
--    Only a bcrypt hash of the password is stored. To change it:
--    php database/set_admin_password.php "YourNewPassword" admin@roomease.com
INSERT INTO users
    (user_id, email, username, password_hash, first_name, last_name, phone_number, role, is_super_admin, is_active)
VALUES
    (1, 'admin@roomease.com', 'admin', '$2y$10$ASqK/NDvxXriS5IfnBqLfuLZNdx2/d8zssHfHm3D6Q.fhB8.cG.ki',
     'System', 'Administrator', '09000000000', 'administrator', 1, 1);

-- 2. Room types (needed before any room can be added).
INSERT INTO room_types (room_type_id, room_type_name) VALUES
    (1, 'Single Room'),
    (2, 'Double Sharing'),
    (3, 'Bed Spacer'),
    (4, 'Dormitory'),
    (5, 'Private Room');

-- 3. The admin's amenities, offered to every landlord.
INSERT INTO amenities (amenity_id, landlord_id, amenity_name) VALUES
    (1, NULL, 'Wi-Fi'),
    (2, NULL, 'Air Conditioning'),
    (3, NULL, 'Private Bathroom'),
    (4, NULL, 'Kitchen Access'),
    (5, NULL, 'Laundry Area'),
    (6, NULL, 'Study Table & Chair'),
    (7, NULL, 'CCTV & 24/7 Security'),
    (8, NULL, 'Refrigerator Access'),
    (9, NULL, 'Gated Compound');

-- 4. The admin's utilities.
INSERT INTO utilities (utility_id, landlord_id, utility_name) VALUES
    (1, NULL, 'Water'),
    (2, NULL, 'Electricity'),
    (3, NULL, 'Trash Collection'),
    (4, NULL, 'Internet / Wi-Fi'),
    (5, NULL, 'Cooking Gas');

-- 5. Sign-in page background.
INSERT INTO site_settings (setting_key, setting_value) VALUES
    ('auth_background_type', 'colour'),
    ('auth_background_colour', '#E6EFEA');

-- New accounts start at user_id 2.
ALTER TABLE users AUTO_INCREMENT = 2;
