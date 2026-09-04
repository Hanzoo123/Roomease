-- =========================================================
-- RoomEase: A Web-Based Boarding House Information and
-- Listing System — Database Schema
-- =========================================================
-- Import this file in phpMyAdmin (or `mysql -u root -p < roomease.sql`)
-- before running the application.
-- =========================================================

CREATE DATABASE IF NOT EXISTS roomease CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE roomease;

-- ---------------------------------------------------------
-- Table: users
-- Holds Administrators, Landlords, and Prospective Boarders.
-- ---------------------------------------------------------
CREATE TABLE users (
    user_id         INT AUTO_INCREMENT PRIMARY KEY,
    role            ENUM('admin', 'landlord', 'boarder') NOT NULL,
    full_name       VARCHAR(120) NOT NULL,
    username        VARCHAR(50)  NOT NULL UNIQUE,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    contact_number  VARCHAR(30)  DEFAULT NULL,
    status          ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: listings
-- Boarding house listings created by Landlords.
-- ---------------------------------------------------------
CREATE TABLE listings (
    listing_id           INT AUTO_INCREMENT PRIMARY KEY,
    landlord_id          INT NOT NULL,
    boarding_house_name  VARCHAR(150) NOT NULL,
    address               VARCHAR(255) NOT NULL,
    city                 VARCHAR(100) NOT NULL,
    monthly_rent         DECIMAL(10,2) NOT NULL,
    reservation_fee      DECIMAL(10,2) DEFAULT NULL,
    room_type            ENUM('Private Room', 'Bed Spacer') NOT NULL,
    room_capacity        INT NOT NULL DEFAULT 1,
    electricity_payment  VARCHAR(150) DEFAULT NULL,
    water_payment        VARCHAR(150) DEFAULT NULL,
    house_rules          TEXT DEFAULT NULL,
    contact_info          VARCHAR(150) NOT NULL,
    status               ENUM('available', 'full', 'inactive') NOT NULL DEFAULT 'available',
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_listings_landlord FOREIGN KEY (landlord_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: listing_photos
-- Property photographs, one listing to many photos.
-- ---------------------------------------------------------
CREATE TABLE listing_photos (
    photo_id     INT AUTO_INCREMENT PRIMARY KEY,
    listing_id   INT NOT NULL,
    photo_path   VARCHAR(255) NOT NULL,
    is_primary   TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_photos_listing FOREIGN KEY (listing_id)
        REFERENCES listings(listing_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: amenities
-- Lookup table for boarding house amenities.
-- ---------------------------------------------------------
CREATE TABLE amenities (
    amenity_id   INT AUTO_INCREMENT PRIMARY KEY,
    amenity_name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: listing_amenities
-- Junction table mapping listings to amenities (Many-to-Many).
-- ---------------------------------------------------------
CREATE TABLE listing_amenities (
    listing_id INT NOT NULL,
    amenity_id INT NOT NULL,
    PRIMARY KEY (listing_id, amenity_id),
    CONSTRAINT fk_listing_amenities_listing FOREIGN KEY (listing_id)
        REFERENCES listings(listing_id) ON DELETE CASCADE,
    CONSTRAINT fk_listing_amenities_amenity FOREIGN KEY (amenity_id)
        REFERENCES amenities(amenity_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Seed data: default amenities
-- ---------------------------------------------------------
INSERT INTO amenities (amenity_name) VALUES
('WiFi'),
('Air Conditioning'),
('Own Comfort Room'),
('Shared Kitchen'),
('Parking Space'),
('Laundry Area'),
('CCTV'),
('24/7 Security'),
('Furnished'),
('Study Table'),
('Near Transport Terminal');

-- ---------------------------------------------------------
-- Seed data: default Administrator account
-- Username: admin   Password: Admin@123
-- (hash below is password_hash('Admin@123', PASSWORD_DEFAULT))
-- CHANGE THIS PASSWORD after first login in a real deployment.
-- ---------------------------------------------------------
INSERT INTO users (role, full_name, username, email, password_hash, contact_number, status)
VALUES (
    'admin',
    'System Administrator',
    'admin',
    'admin@roomease.local',
    '$2y$10$dnDMGIJZtl7IoMXgwVtmE.rk3PcAAXOuHXuMCa28LDmRJ6zMpfPAi',
    '09000000000',
    'active'
);
