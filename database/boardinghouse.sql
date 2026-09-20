-- =========================================================
-- RoomEase: A Web-Based Boarding House Information and
-- Listing System — Full database export
-- =========================================================
-- File:     database/boardinghouse.sql
-- Target:   MySQL 8.0+ / MariaDB 10.4+
-- Database: roomease
--
-- Every table the running application uses, and nothing else. There are no
-- landlords, no boarders, no listings, no rooms, no photos and no logs in
-- here: the only row in `users` is the administrator, so importing this file
-- gives a working but empty system, ready to be filled from the site itself.
--
-- The only other seeded data is the controlled vocabulary the forms need in
-- order to work at all — room types, and the administrator-owned amenities
-- and utilities every landlord can pick from — plus the two appearance
-- settings the sign-in pages read.
--
-- Importing:
--   mysql -u root roomease < database/boardinghouse.sql
-- or in phpMyAdmin, open the Import tab and choose this file. It creates the
-- `roomease` database if it is missing and selects it, so nothing has to be
-- selected first. The database keeps the name `roomease` because that is what
-- config/db.php connects to; only the file is called boardinghouse.sql.
--
-- WARNING: this file drops every table listed below before recreating them.
-- Importing it over a database with real data destroys that data.
-- =========================================================

CREATE DATABASE IF NOT EXISTS roomease
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE roomease;

-- The tables reference each other, so the old copies come out with the
-- constraint checks switched off rather than in a carefully chosen order.
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS account_notes;
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
-- Everyone who can sign in: administrators, landlords and boarders.
-- google_id is set for accounts that sign in with Google and NULL otherwise.
-- deleted_at is set when an administrator archives an account; archiving
-- rather than deleting keeps the cascades below from destroying the
-- listings, photos and saved copies attached to it.
-- ---------------------------------------------------------
CREATE TABLE users (
    user_id         INT AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(150) NOT NULL,
    google_id       VARCHAR(64) DEFAULT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    first_name      VARCHAR(100) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    phone_number    VARCHAR(30) DEFAULT NULL,
    -- Profile photo, as a path under assets/uploads/avatars/. NULL means the
    -- account is drawn as its initials instead.
    avatar_path     VARCHAR(255) NULL DEFAULT NULL,
    role            ENUM('administrator', 'landlord', 'boarder') NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at      DATETIME NULL DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY email (email),
    UNIQUE KEY uq_users_google_id (google_id),
    -- Every account listing filters on these three columns together.
    KEY idx_users_live (deleted_at, is_active, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- LISTINGS
-- =========================================================

-- ---------------------------------------------------------
-- 2. boarding_houses
-- A property listing, owned by one landlord. Rent, room type and capacity
-- belong to each room, not to the house (see `rooms`).
--
-- availability_status is the landlord's switch: 'unavailable' hides the
-- listing from the public site. moderation_status is the administrator's:
-- only an 'approved' listing appears publicly, and moderated_by records who
-- made the latest decision. deleted_at is set when an administrator removes
-- a listing — it is archived rather than destroyed, and can be restored.
-- ---------------------------------------------------------
CREATE TABLE boarding_houses (
    boarding_house_id   INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id         INT NOT NULL,
    name                VARCHAR(150) NOT NULL,
    address             TEXT NOT NULL,
    reservation_fee     DECIMAL(10, 2) DEFAULT NULL,
    availability_status ENUM('available', 'unavailable') NOT NULL DEFAULT 'available',
    moderation_status   ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    rejection_reason    VARCHAR(500) DEFAULT NULL,
    moderated_at        TIMESTAMP NULL DEFAULT NULL,
    moderated_by        INT DEFAULT NULL,
    description         TEXT DEFAULT NULL,
    contact_number      VARCHAR(50) DEFAULT NULL,
    house_rules         TEXT DEFAULT NULL,
    -- Stay terms and map pin. NULL means the landlord has not stated it, and
    -- the listing page leaves the line out rather than guessing.
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
    deleted_at          DATETIME NULL DEFAULT NULL,
    -- The two status columns lead because every browse query fixes both, and
    -- the ordering column comes last so one index supplies the sort as well.
    KEY idx_bh_public_recent   (moderation_status, availability_status, created_at),
    KEY idx_bh_created         (created_at),
    KEY idx_bh_landlord_recent (landlord_id, created_at),
    KEY idx_bh_deleted         (deleted_at),
    CONSTRAINT fk_bh_landlord FOREIGN KEY (landlord_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_bh_moderated_by FOREIGN KEY (moderated_by)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 3. room_types
-- Controlled vocabulary for rooms.room_type_id, so the room form and the
-- browse filter always offer exactly the same values.
-- ---------------------------------------------------------
CREATE TABLE room_types (
    room_type_id    INT AUTO_INCREMENT PRIMARY KEY,
    room_type_name  VARCHAR(50) NOT NULL,
    UNIQUE KEY room_type_name (room_type_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 4. amenities
-- Amenities a listing can offer. landlord_id is NULL for an item the
-- administrator made, which every landlord can use, and set for an item a
-- landlord made, which only that landlord sees. Names are unique per owner.
-- ---------------------------------------------------------
CREATE TABLE amenities (
    amenity_id      INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id     INT DEFAULT NULL,
    amenity_name    VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_amenities_owner_name (landlord_id, amenity_name),
    CONSTRAINT fk_amenities_landlord FOREIGN KEY (landlord_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 5. utilities
-- Utilities a listing can bill for. Owned exactly the same way as amenities.
-- ---------------------------------------------------------
CREATE TABLE utilities (
    utility_id      INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id     INT DEFAULT NULL,
    utility_name    VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_utilities_owner_name (landlord_id, utility_name),
    CONSTRAINT fk_utilities_landlord FOREIGN KEY (landlord_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 6. boarding_house_amenities
-- Which amenities a listing offers (many-to-many).
-- ---------------------------------------------------------
CREATE TABLE boarding_house_amenities (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    boarding_house_id   INT NOT NULL,
    amenity_id          INT NOT NULL,
    is_available        TINYINT(1) NOT NULL DEFAULT 1,
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
-- The rooms inside a boarding house. Availability is never stored: a room is
-- "Available" while open with slots left, "Full" when every slot is taken,
-- and "Not available" when the landlord has closed it.
--
-- RESTRICT on the room type, not CASCADE: deleting a room type that rooms
-- are still using should be refused, never silently delete those rooms.
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
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rooms_house_name (boarding_house_id, name),
    KEY idx_rooms_type_rent (room_type_id, is_open, monthly_rent),
    CONSTRAINT chk_rooms_capacity CHECK (capacity BETWEEN 1 AND 100),
    CONSTRAINT chk_rooms_slots CHECK (slots_taken >= 0 AND slots_taken <= capacity),
    CONSTRAINT fk_rooms_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE,
    CONSTRAINT fk_rooms_type FOREIGN KEY (room_type_id)
        REFERENCES room_types(room_type_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 9. images
-- Photos uploaded by landlords. room_id is NULL for a photo of the house and
-- set for a photo of one room. is_primary marks the house's cover among the
-- house photos, and a room's main photo among that room's photos.
--
-- Only the path is stored here; the file itself lives under storage/.
-- ---------------------------------------------------------
CREATE TABLE images (
    image_id            INT AUTO_INCREMENT PRIMARY KEY,
    boarding_house_id   INT NOT NULL,
    room_id             INT DEFAULT NULL,
    image_path          VARCHAR(255) NOT NULL,
    is_primary          TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Serves the per-listing cover photo lookup in browse and both dashboards.
    KEY idx_images_cover (boarding_house_id, is_primary, image_id),
    KEY idx_images_room  (room_id, is_primary, image_id),
    CONSTRAINT fk_images_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE,
    CONSTRAINT fk_images_room FOREIGN KEY (room_id)
        REFERENCES rooms(room_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 10. favorites
-- Listings a prospective boarder has saved for later. The unique key makes
-- saving idempotent, so a double submit cannot duplicate a row.
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


-- =========================================================
-- SIGN-IN AND SECURITY
-- =========================================================

-- ---------------------------------------------------------
-- 11. password_resets
-- One row per "forgot password" request. The emailed 6-digit code is stored
-- only as a password_hash() (code_hash) and dies after five wrong guesses
-- (attempts). The right code is exchanged for a token kept in the visitor's
-- session, of which only a SHA-256 hash is stored, so a leaked database still
-- cannot be used to reset anyone's password. Rows are single use (used_at)
-- and short lived (expires_at).
-- ---------------------------------------------------------
CREATE TABLE password_resets (
    reset_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    -- Never NULL. Until the emailed code is proven this holds the hash of
    -- random bytes nobody keeps, which no token can ever match; verifying the
    -- code overwrites it with the hash of the real token. A worktree
    -- experiment once relaxed this column to NULL, which is why a database
    -- built before this file may disagree; main has always required a value
    -- (see create_password_reset_code() in includes/core/functions.php).
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
-- "Remember me" devices. The cookie holds selector:validator and only a
-- SHA-256 hash of the validator is stored. Each row is single use: signing in
-- from the cookie replaces it.
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
-- Failed sign-ins and password-reset requests, so both can be rate limited.
-- Without this table the application still runs, but throttling is off and it
-- says so in the PHP error log. One index per thing that gets counted: the
-- account being targeted, and the source doing the targeting.
-- ---------------------------------------------------------
CREATE TABLE login_attempts (
    attempt_id   BIGINT AUTO_INCREMENT PRIMARY KEY,
    -- 'login' for a failed sign-in, 'reset' for a password-reset request.
    kind         VARCHAR(20) NOT NULL,
    -- The email address the attempt was aimed at, lowercased.
    identifier   VARCHAR(190) NOT NULL,
    -- The address the request came from. IPv6 needs up to 45 characters.
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_kind_identifier_time (kind, identifier, attempted_at),
    KEY idx_kind_ip_time (kind, ip_address, attempted_at),
    KEY idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- ADMINISTRATION
-- =========================================================

-- ---------------------------------------------------------
-- 14. site_settings
-- Settings an administrator changes from the panel, such as the sign-in
-- pages' background (admin/appearance.php). A key/value table rather than
-- columns, because the panel gains settings faster than a schema should
-- change for them.
-- ---------------------------------------------------------
CREATE TABLE site_settings (
    setting_key     VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value   TEXT DEFAULT NULL,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 15. admin_actions
-- The activity log (admin/activity.php): which administrator approved,
-- rejected, removed or restored a listing, changed an account, or exported
-- data, when, and the reason given. target_label keeps the listing or account
-- name as it read at the time, so the log still makes sense after the thing
-- it refers to has been renamed or removed.
-- ---------------------------------------------------------
CREATE TABLE admin_actions (
    action_id     INT AUTO_INCREMENT PRIMARY KEY,
    admin_id      INT DEFAULT NULL,
    action        VARCHAR(40) NOT NULL,
    target_type   VARCHAR(20) NOT NULL,
    target_id     INT DEFAULT NULL,
    target_label  VARCHAR(200) NOT NULL DEFAULT '',
    detail        VARCHAR(500) DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_actions_created (created_at),
    KEY idx_actions_target  (target_type, target_id, created_at),
    KEY idx_actions_admin   (admin_id, created_at),
    CONSTRAINT fk_actions_admin FOREIGN KEY (admin_id)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- Table: account_notes
-- Short notes an administrator leaves on a landlord's or boarder's account,
-- seen only inside the admin panel. Separate from admin_actions because that
-- table records what was *done* to an account and is written by the code,
-- while a note is what an administrator *observed* and can be deleted by
-- whoever wrote it.
-- ---------------------------------------------------------
CREATE TABLE account_notes (
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

-- =========================================================
-- SEED DATA
--
-- Only what the application needs in order to work: the administrator
-- account, the lists the listing and room forms offer, and the sign-in
-- page's appearance. No landlords, boarders, listings, rooms or photos.
-- =========================================================

-- 1. The administrator account — the only account in this file.
--
-- password_hash holds a bcrypt hash of the current administrator password.
-- The password itself is not stored and cannot be read back out of here.
-- Sign in at /admin/login.php with the email below and that password.
--
-- To set a different password after importing:
--   php database/set_admin_password.php "YourNewPassword" admin@roomease.com
INSERT INTO users
    (user_id, email, password_hash, first_name, last_name, phone_number, role, is_active)
VALUES
    (1, 'admin@roomease.com', '$2y$10$ASqK/NDvxXriS5IfnBqLfuLZNdx2/d8zssHfHm3D6Q.fhB8.cG.ki',
     'System', 'Administrator', '09000000000', 'administrator', 1);

-- 2. Room types. rooms.room_type_id points here, so no room can be added
--    until these exist.
INSERT INTO room_types (room_type_id, room_type_name) VALUES
    (1, 'Single Room'),
    (2, 'Double Sharing'),
    (3, 'Bed Spacer'),
    (4, 'Dormitory'),
    (5, 'Private Room');

-- 3. Administrator-owned amenities (landlord_id NULL), offered to every
--    landlord on the listing form. Landlords add their own alongside these.
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

-- 4. Administrator-owned utilities, owned the same way.
INSERT INTO utilities (utility_id, landlord_id, utility_name) VALUES
    (1, NULL, 'Water'),
    (2, NULL, 'Electricity'),
    (3, NULL, 'Trash Collection'),
    (4, NULL, 'Internet / Wi-Fi'),
    (5, NULL, 'Cooking Gas');

-- 5. Appearance of the sign-in pages, as the panel currently has it.
INSERT INTO site_settings (setting_key, setting_value) VALUES
    ('auth_background_type', 'colour'),
    ('auth_background_colour', '#E6EFEA');

-- The next account the site creates is user_id 2. Every other table starts
-- empty, counting from 1.
ALTER TABLE users AUTO_INCREMENT = 2;
