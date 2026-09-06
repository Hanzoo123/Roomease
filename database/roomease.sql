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

DROP TABLE IF EXISTS images;
DROP TABLE IF EXISTS boarding_house_utilities;
DROP TABLE IF EXISTS boarding_house_amenities;
DROP TABLE IF EXISTS utilities;
DROP TABLE IF EXISTS amenities;
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
    password_hash   VARCHAR(255) NOT NULL,
    first_name      VARCHAR(100) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    phone_number    VARCHAR(30) DEFAULT NULL,
    role            ENUM('administrator', 'landlord', 'boarder') NOT NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
    room_type           VARCHAR(50) DEFAULT NULL,
    room_capacity       INT NOT NULL DEFAULT 1,
    availability_status ENUM('available', 'unavailable') NOT NULL DEFAULT 'available',
    description         TEXT DEFAULT NULL,
    contact_number      VARCHAR(50) DEFAULT NULL,
    house_rules         TEXT DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
-- 5. Table: boarding_house_amenities
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
-- 6. Table: boarding_house_utilities
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
-- 7. Table: images
-- Stores property photographs uploaded by landlords.
-- ---------------------------------------------------------
CREATE TABLE images (
    image_id            INT AUTO_INCREMENT PRIMARY KEY,
    boarding_house_id   INT NOT NULL,
    image_path          VARCHAR(255) NOT NULL,
    is_primary          BOOLEAN NOT NULL DEFAULT FALSE,
    uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_images_bh FOREIGN KEY (boarding_house_id)
        REFERENCES boarding_houses(boarding_house_id) ON DELETE CASCADE
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

-- 3. Default Users
-- Passwords below are hashed for 'Admin@123' and 'Password@123'
INSERT INTO users (user_id, email, password_hash, first_name, last_name, phone_number, role, is_active) VALUES
(1, 'admin@roomease.local', '$2y$10$dnDMGIJZtl7IoMXgwVtmE.rk3PcAAXOuHXuMCa28LDmRJ6zMpfPAi', 'System', 'Administrator', '09000000000', 'administrator', TRUE),
(2, 'landlord@roomease.local', '$2y$10$dnDMGIJZtl7IoMXgwVtmE.rk3PcAAXOuHXuMCa28LDmRJ6zMpfPAi', 'Juan', 'Dela Cruz', '09171234567', 'landlord', TRUE),
(3, 'boarder@roomease.local', '$2y$10$dnDMGIJZtl7IoMXgwVtmE.rk3PcAAXOuHXuMCa28LDmRJ6zMpfPAi', 'Maria', 'Santos', '09281234567', 'boarder', TRUE);

-- 4. Sample Boarding Houses
INSERT INTO boarding_houses (boarding_house_id, landlord_id, name, address, monthly_rent, room_type, room_capacity, availability_status, description, contact_number, house_rules) VALUES
(1, 2, 'Baybay Greenview Residences', 'Purok 4, Brgy. Pangasugan, Baybay City, Leyte', 3500.00, 'Single Room', 1, 'available', 'Clean, quiet, and breezy boarding house just 5 minutes walking distance to VSU main campus.', '09171234567', 'No visitors allowed after 9:00 PM.\nKeep common spaces clean.\nNo smoking or alcoholic beverages inside.'),
(2, 2, 'Sunshine Villa Boarding House', 'Brgy. Guadalupe, Baybay City, Leyte', 2800.00, 'Double Sharing', 2, 'available', 'Spacious double sharing rooms with study tables and personal storage lockers for college students.', '09171234567', 'Curfew at 10:00 PM.\nConserve water and electricity.\nRespect roommates quiet hours after 10:00 PM.'),
(3, 2, 'Coastal Breeze Ladies Dorm', 'Brgy. Zone 12, Baybay City, Leyte', 4500.00, 'Air-conditioned Private Room', 1, 'available', 'Exclusive all-female dormitory with fully air-conditioned rooms and 24/7 CCTV surveillance.', '09171234567', 'All-female dormitory, strictly no male visitors inside rooms.\nQuiet hours from 10:00 PM to 6:00 AM.');

-- 5. Junction: Boarding House Amenities
INSERT INTO boarding_house_amenities (boarding_house_id, amenity_id, is_available) VALUES
(1, 1, TRUE),  -- Wi-Fi
(1, 3, TRUE),  -- Private Bathroom
(1, 5, TRUE),  -- Laundry Area
(1, 6, TRUE),  -- Study Table & Chair
(1, 10, TRUE), -- Near VSU
(2, 1, TRUE),  -- Wi-Fi
(2, 4, TRUE),  -- Kitchen Access
(2, 6, TRUE),  -- Study Table & Chair
(2, 9, TRUE),  -- Gated Compound
(3, 1, TRUE),  -- Wi-Fi
(3, 2, TRUE),  -- Air Conditioning
(3, 3, TRUE),  -- Private Bathroom
(3, 7, TRUE);  -- CCTV

-- 6. Junction: Boarding House Utilities
INSERT INTO boarding_house_utilities (boarding_house_id, utility_id, billing_policy) VALUES
(1, 1, 'Included in Rent'),
(1, 2, 'Separate Submeter (₱14/kWh)'),
(1, 3, 'Included in Rent'),
(1, 4, 'Free High-speed Wi-Fi'),
(2, 1, 'Fixed ₱150 per month'),
(2, 2, 'Split equally among boarders'),
(2, 3, 'Included in Rent'),
(3, 1, 'Included in Rent'),
(3, 2, 'Separate Meter'),
(3, 3, 'Included in Rent'),
(3, 4, 'Free fiber connection');

-- Reset auto-increment counter to avoid conflicts
ALTER TABLE users AUTO_INCREMENT = 10;
ALTER TABLE boarding_houses AUTO_INCREMENT = 10;
