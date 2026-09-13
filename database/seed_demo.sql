-- =========================================================
-- RoomEase: OPTIONAL demo data
-- =========================================================
-- Sample accounts and listings for a walkthrough or a defence demo.
-- This file is deliberately NOT part of roomease.sql, so a real
-- deployment can import the schema without also creating accounts
-- whose password is published in this repository.
--
--   mysql -u root -p roomease < database/seed_demo.sql
--
-- Both demo accounts below use the password: Password@123
-- Do not import this file anywhere that is reachable from the internet.
--
-- Safe to run more than once: the users are matched on their email
-- address and the listings on their id, so a second run updates the
-- existing rows instead of creating duplicates.
-- =========================================================

USE roomease;

-- ---------------------------------------------------------
-- 1. Demo accounts (password: Password@123)
-- ---------------------------------------------------------
INSERT INTO users (user_id, email, password_hash, first_name, last_name, phone_number, role, is_active) VALUES
(2, 'landlord@roomease.local', '$2y$10$nriKLkI77Ak9/WCeuAipJ.NCEa0abWHhIwFriKKDy84tcIOpoHrHe', 'Juan', 'Dela Cruz', '09171234567', 'landlord', TRUE),
(3, 'boarder@roomease.local',  '$2y$10$nriKLkI77Ak9/WCeuAipJ.NCEa0abWHhIwFriKKDy84tcIOpoHrHe', 'Maria', 'Santos',    '09281234567', 'boarder',  TRUE)
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    first_name    = VALUES(first_name),
    last_name     = VALUES(last_name),
    phone_number  = VALUES(phone_number),
    role          = VALUES(role),
    is_active     = VALUES(is_active);

-- ---------------------------------------------------------
-- 2. Sample boarding houses, owned by the demo landlord
-- ---------------------------------------------------------
INSERT INTO boarding_houses (boarding_house_id, landlord_id, name, address, monthly_rent, reservation_fee, room_type_id, room_capacity, availability_status, moderation_status, moderated_at, description, contact_number, house_rules) VALUES
(1, 2, 'Baybay Greenview Residences', 'Purok 4, Brgy. Pangasugan, Baybay City, Leyte', 3500.00, 1500.00, 1, 1, 'available', 'approved', NOW(), 'Clean, quiet, and breezy boarding house just 5 minutes walking distance to VSU main campus.', '09171234567', 'No visitors allowed after 9:00 PM.\nKeep common spaces clean.\nNo smoking or alcoholic beverages inside.'),
(2, 2, 'Sunshine Villa Boarding House', 'Brgy. Guadalupe, Baybay City, Leyte', 2800.00, 1000.00, 2, 2, 'available', 'approved', NOW(), 'Spacious double sharing rooms with study tables and personal storage lockers for college students.', '09171234567', 'Curfew at 10:00 PM.\nConserve water and electricity.\nRespect roommates quiet hours after 10:00 PM.'),
(3, 2, 'Coastal Breeze Ladies Dorm', 'Brgy. Zone 12, Baybay City, Leyte', 4500.00, 2000.00, 5, 1, 'available', 'approved', NOW(), 'Exclusive all-female dormitory with fully air-conditioned rooms and 24/7 CCTV surveillance.', '09171234567', 'All-female dormitory, strictly no male visitors inside rooms.\nQuiet hours from 10:00 PM to 6:00 AM.')
ON DUPLICATE KEY UPDATE
    landlord_id         = VALUES(landlord_id),
    name                = VALUES(name),
    address             = VALUES(address),
    monthly_rent        = VALUES(monthly_rent),
    reservation_fee     = VALUES(reservation_fee),
    room_type_id        = VALUES(room_type_id),
    room_capacity       = VALUES(room_capacity),
    availability_status = VALUES(availability_status),
    moderation_status   = VALUES(moderation_status),
    moderated_at        = VALUES(moderated_at),
    description         = VALUES(description),
    contact_number      = VALUES(contact_number),
    house_rules         = VALUES(house_rules);

-- ---------------------------------------------------------
-- 3. Amenities offered by each sample listing
-- ---------------------------------------------------------
INSERT IGNORE INTO boarding_house_amenities (boarding_house_id, amenity_id, is_available) VALUES
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

-- ---------------------------------------------------------
-- 4. Utilities and billing policies for each sample listing
-- ---------------------------------------------------------
INSERT IGNORE INTO boarding_house_utilities (boarding_house_id, utility_id, billing_policy) VALUES
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

-- Keep new rows clear of the fixed ids used above.
ALTER TABLE users AUTO_INCREMENT = 10;
ALTER TABLE boarding_houses AUTO_INCREMENT = 10;
