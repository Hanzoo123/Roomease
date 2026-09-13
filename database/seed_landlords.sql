-- =========================================================
-- RoomEase: OPTIONAL extra landlord demo data
-- =========================================================
-- Five landlord accounts with five approved listings each (25 in total),
-- on top of whatever database/seed_demo.sql already created. Enough rows
-- to show the browse filters, pagination and the landlord dashboard with
-- a realistic amount of data during a walkthrough or defence.
--
--   mysql -u root -p roomease < database/seed_landlords.sql
--
-- Every account below uses the password: Password@123
-- Do not import this file anywhere that is reachable from the internet.
--
-- Safe to run more than once: users and listings carry fixed ids and are
-- updated on a second run rather than duplicated.
--
-- Fixed ids are used so the listings can name their owner directly:
--   users             201-205
--   boarding_houses   201-225
-- The amenity, utility and room type ids are the ones seeded by
-- database/roomease.sql, in the order they are listed there.
-- =========================================================

USE roomease;

-- ---------------------------------------------------------
-- 1. Landlord accounts (password: Password@123)
-- ---------------------------------------------------------
INSERT INTO users (user_id, email, password_hash, first_name, last_name, phone_number, role, is_active) VALUES
(201, 'landlord01@roomease.local', '$2y$10$nriKLkI77Ak9/WCeuAipJ.NCEa0abWHhIwFriKKDy84tcIOpoHrHe', 'Ramon',   'Villaflor', '09171110001', 'landlord', TRUE),
(202, 'landlord02@roomease.local', '$2y$10$nriKLkI77Ak9/WCeuAipJ.NCEa0abWHhIwFriKKDy84tcIOpoHrHe', 'Lourdes', 'Bacalso',   '09171110002', 'landlord', TRUE),
(203, 'landlord03@roomease.local', '$2y$10$nriKLkI77Ak9/WCeuAipJ.NCEa0abWHhIwFriKKDy84tcIOpoHrHe', 'Efren',   'Tabada',    '09171110003', 'landlord', TRUE),
(204, 'landlord04@roomease.local', '$2y$10$nriKLkI77Ak9/WCeuAipJ.NCEa0abWHhIwFriKKDy84tcIOpoHrHe', 'Miriam',  'Cabrera',   '09171110004', 'landlord', TRUE),
(205, 'landlord05@roomease.local', '$2y$10$nriKLkI77Ak9/WCeuAipJ.NCEa0abWHhIwFriKKDy84tcIOpoHrHe', 'Noel',    'Espinosa',  '09171110005', 'landlord', TRUE)
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    first_name    = VALUES(first_name),
    last_name     = VALUES(last_name),
    phone_number  = VALUES(phone_number),
    role          = VALUES(role),
    is_active     = VALUES(is_active);

-- ---------------------------------------------------------
-- 2. Five listings per landlord, all approved and available
--
-- Room types: 1 Single Room, 2 Double Sharing, 3 Bed Spacer,
--             4 Dormitory, 5 Private Room
-- ---------------------------------------------------------
INSERT INTO boarding_houses
    (boarding_house_id, landlord_id, name, address, monthly_rent, reservation_fee,
     room_type_id, room_capacity, availability_status, moderation_status, moderated_at,
     description, contact_number, house_rules) VALUES

-- Ramon Villaflor (201)
(201, 201, 'Villaflor Student Residences', 'Purok 2, Brgy. Pangasugan, Baybay City, Leyte', 3200.00, 1500.00, 1, 1, 'available', 'approved', NOW(), 'Single rooms a short walk from the VSU main gate, each with its own window and study corner.', '09171110001', 'No visitors after 9:00 PM.\nNo smoking inside the rooms.\nKeep the corridor clear at all times.'),
(202, 201, 'Villaflor Annex Bedspace', 'Purok 2, Brgy. Pangasugan, Baybay City, Leyte', 1800.00, 800.00, 3, 4, 'available', 'approved', NOW(), 'Affordable bedspacer arrangement for students on a tight budget, four beds to a room.', '09171110001', 'Lights out at 11:00 PM.\nLabel your own food and belongings.\nNo cooking inside the bedroom.'),
(203, 201, 'Greenfield Ladies Dorm', 'Brgy. Gabas, Baybay City, Leyte', 4200.00, 2000.00, 4, 8, 'available', 'approved', NOW(), 'All-female dormitory with a common study hall, water dispenser and 24/7 CCTV coverage.', '09171110001', 'Strictly for female boarders.\nNo male visitors beyond the reception area.\nQuiet hours 10:00 PM to 6:00 AM.'),
(204, 201, 'Villaflor Family Rooms', 'Brgy. Pangasugan, Baybay City, Leyte', 5000.00, 2500.00, 5, 2, 'available', 'approved', NOW(), 'Larger private rooms suitable for two siblings or a working couple, with private bathroom.', '09171110001', 'One month advance, one month deposit.\nNo pets.\nVisitors must be logged at the gate.'),
(205, 201, 'Campus Gate Double Sharing', 'Purok 5, Brgy. Pangasugan, Baybay City, Leyte', 2600.00, 1200.00, 2, 2, 'available', 'approved', NOW(), 'Double sharing rooms directly across the campus gate, ideal for first year students.', '09171110001', 'Curfew at 10:00 PM on weekdays.\nShare cleaning duties for the common area.\nNo alcoholic drinks inside.'),

-- Lourdes Bacalso (202)
(206, 202, 'Bacalso Boarding House', 'Brgy. Guadalupe, Baybay City, Leyte', 2400.00, 1000.00, 2, 2, 'available', 'approved', NOW(), 'Long running family boarding house with a shaded courtyard and reliable water supply.', '09171110002', 'Curfew at 10:00 PM.\nConserve water and electricity.\nNo loud music after 9:00 PM.'),
(207, 202, 'Casa Lourdes Dormitory', 'Brgy. Zone 15, Baybay City, Leyte', 1600.00, NULL, 3, 6, 'available', 'approved', NOW(), 'Budget bedspace near the public market and jeepney terminal. No reservation fee required.', '09171110002', 'Keep your own bunk tidy.\nNo overnight guests.\nGate is locked at 11:00 PM.'),
(208, 202, 'Lourdes Private Suites', 'Brgy. Zone 15, Baybay City, Leyte', 5500.00, 2500.00, 5, 1, 'available', 'approved', NOW(), 'Fully furnished private suite with air conditioning, private bathroom and a small kitchenette.', '09171110002', 'No subletting.\nNo pets.\nElectricity is billed separately per submeter.'),
(209, 202, 'Bacalso Riverside Rooms', 'Brgy. Plaridel, Baybay City, Leyte', 3000.00, 1500.00, 1, 1, 'available', 'approved', NOW(), 'Quiet single rooms beside the river, breezy in the afternoon and away from main road noise.', '09171110002', 'No visitors inside the rooms.\nDispose of trash in the bins provided.\nReport leaks and damage right away.'),
(210, 202, 'Guadalupe Student Hub', 'Brgy. Guadalupe, Baybay City, Leyte', 3800.00, 1800.00, 4, 10, 'available', 'approved', NOW(), 'Dormitory style accommodation with a shared study lounge and free fiber internet.', '09171110002', 'Quiet hours 9:00 PM to 6:00 AM during exam week.\nNo cooking inside the sleeping area.\nSign the logbook when bringing guests.'),

-- Efren Tabada (203)
(211, 203, 'Tabada Hilltop Boarding House', 'Brgy. Patag, Baybay City, Leyte', 2200.00, 1000.00, 2, 2, 'available', 'approved', NOW(), 'Double sharing rooms on higher ground, cool at night and never flooded during heavy rain.', '09171110003', 'Curfew at 10:00 PM.\nNo smoking anywhere on the property.\nTake turns cleaning the shared bathroom.'),
(212, 203, 'Patag Bedspacer Inn', 'Brgy. Patag, Baybay City, Leyte', 1500.00, NULL, 3, 8, 'available', 'approved', NOW(), 'The cheapest bedspace on the hill, good for students who only need a place to sleep and study.', '09171110003', 'No overnight guests.\nLights out at 11:00 PM.\nKeep personal items inside your locker.'),
(213, 203, 'Tabada Executive Rooms', 'Brgy. Kilim, Baybay City, Leyte', 6000.00, 3000.00, 5, 2, 'available', 'approved', NOW(), 'Premium private rooms for faculty and working professionals, with parking space included.', '09171110003', 'No parties.\nParking is for tenants only.\nOne month advance, one month deposit.'),
(214, 203, 'Kilim Breeze Dormitory', 'Brgy. Kilim, Baybay City, Leyte', 2000.00, 900.00, 4, 12, 'available', 'approved', NOW(), 'Spacious dormitory with twelve beds, laundry area and a covered dining space.', '09171110003', 'Quiet hours after 10:00 PM.\nWash your own dishes immediately.\nNo cooking after 9:00 PM.'),
(215, 203, 'Tabada Single Units', 'Brgy. Patag, Baybay City, Leyte', 3400.00, 1500.00, 1, 1, 'available', 'approved', NOW(), 'Self contained single rooms with their own door and window, for boarders who value privacy.', '09171110003', 'No visitors inside the rooms.\nNo smoking.\nGate closes at 11:00 PM.'),

-- Miriam Cabrera (204)
(216, 204, 'Cabrera Ladies Residence', 'Brgy. Cogon, Baybay City, Leyte', 4000.00, 2000.00, 1, 1, 'available', 'approved', NOW(), 'Exclusive all-female single rooms with CCTV at every entrance and a live-in caretaker.', '09171110004', 'Strictly for female boarders.\nNo male visitors inside the house.\nCurfew at 9:30 PM.'),
(217, 204, 'Mahayahay Student Quarters', 'Brgy. Mahayahay, Baybay City, Leyte', 2700.00, 1200.00, 2, 2, 'available', 'approved', NOW(), 'Double sharing rooms with study tables, a few minutes by tricycle from the campus.', '09171110004', 'Curfew at 10:00 PM.\nNo alcoholic beverages inside.\nKeep the common area clean.'),
(218, 204, 'Cabrera Budget Bedspace', 'Brgy. Cogon, Baybay City, Leyte', 1700.00, 700.00, 3, 6, 'available', 'approved', NOW(), 'Simple, clean bedspace with free water and trash collection already included in the rent.', '09171110004', 'No overnight guests.\nLabel your own food in the refrigerator.\nLights out at 11:00 PM.'),
(219, 204, 'Casa Miriam Private Room', 'Brgy. Mahayahay, Baybay City, Leyte', 5200.00, 2600.00, 5, 1, 'available', 'approved', NOW(), 'Air conditioned private room with its own bathroom, cabinet and study desk.', '09171110004', 'No pets.\nNo subletting.\nElectricity billed per submeter.'),
(220, 204, 'Cogon Central Dormitory', 'Brgy. Cogon, Baybay City, Leyte', 2100.00, 1000.00, 4, 10, 'available', 'approved', NOW(), 'Central dormitory within walking distance of the market, church and transport terminal.', '09171110004', 'Quiet hours 10:00 PM to 6:00 AM.\nNo cooking inside the sleeping area.\nSign in visitors at the gate.'),

-- Noel Espinosa (205)
(221, 205, 'Espinosa Seaview Boarding House', 'Brgy. Punta, Baybay City, Leyte', 3600.00, 1800.00, 2, 2, 'available', 'approved', NOW(), 'Double sharing rooms facing the sea, with a roof deck that catches the afternoon breeze.', '09171110005', 'No swimming after dark.\nCurfew at 10:00 PM.\nKeep the roof deck clean after use.'),
(222, 205, 'Punta Bayside Bedspace', 'Brgy. Punta, Baybay City, Leyte', 1900.00, NULL, 3, 5, 'available', 'approved', NOW(), 'Bedspace a short walk from the bay, with a shared kitchen and no reservation fee.', '09171110005', 'No overnight guests.\nWash your dishes right after eating.\nLights out at 11:00 PM.'),
(223, 205, 'Espinosa Garden Rooms', 'Brgy. Maypatag, Baybay City, Leyte', 3100.00, 1400.00, 1, 1, 'available', 'approved', NOW(), 'Single rooms opening onto a small garden, quiet enough for review and thesis writing.', '09171110005', 'No loud music at any time.\nNo smoking in the garden.\nGate locked at 10:30 PM.'),
(224, 205, 'Hipusong Scholars Dorm', 'Brgy. Hipusong, Baybay City, Leyte', 2300.00, 1000.00, 4, 9, 'available', 'approved', NOW(), 'Dormitory favoured by scholars, with a long study table and free Wi-Fi throughout.', '09171110005', 'Quiet hours after 9:00 PM.\nNo visitors in the sleeping area.\nShare the cleaning schedule.'),
(225, 205, 'Espinosa Premium Suite', 'Brgy. Maypatag, Baybay City, Leyte', 5800.00, 3000.00, 5, 2, 'available', 'approved', NOW(), 'The largest unit on offer: private room, private bathroom, kitchenette and parking slot.', '09171110005', 'No parties.\nNo pets.\nOne month advance, one month deposit.')

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
-- 3. Amenities
--
-- Derived from each listing's own figures rather than typed out row by
-- row, so the amenity list has a realistic spread: every listing gets
-- Wi-Fi, a study table and the "near VSU" tag, and the rest follow from
-- the rent, room type and capacity.
-- ---------------------------------------------------------
INSERT IGNORE INTO boarding_house_amenities (boarding_house_id, amenity_id, is_available)
SELECT bh.boarding_house_id, a.amenity_id, TRUE
  FROM boarding_houses bh
  JOIN amenities a
    ON a.amenity_id IN (1, 6, 10)                             -- Wi-Fi, Study Table, Near VSU
    OR (a.amenity_id = 2 AND bh.monthly_rent >= 4000)         -- Air Conditioning
    OR (a.amenity_id = 3 AND bh.room_type_id IN (1, 5))       -- Private Bathroom
    OR (a.amenity_id = 4 AND bh.room_capacity >= 4)           -- Kitchen Access
    OR (a.amenity_id = 5 AND bh.room_capacity >= 2)           -- Laundry Area
    OR (a.amenity_id = 7 AND bh.monthly_rent >= 3000)         -- CCTV & 24/7 Security
    OR (a.amenity_id = 8 AND bh.room_capacity >= 4)           -- Refrigerator Access
    OR (a.amenity_id = 9 AND bh.boarding_house_id % 2 = 0)    -- Gated Compound
 WHERE bh.boarding_house_id BETWEEN 201 AND 225;

-- ---------------------------------------------------------
-- 4. Utilities and billing policies
-- ---------------------------------------------------------
INSERT IGNORE INTO boarding_house_utilities (boarding_house_id, utility_id, billing_policy)
SELECT bh.boarding_house_id, u.utility_id,
       CASE u.utility_id
           WHEN 1 THEN 'Included in Rent'
           WHEN 2 THEN 'Separate submeter'
           WHEN 3 THEN 'Included in Rent'
           WHEN 4 THEN 'Free Wi-Fi'
           WHEN 5 THEN 'Shared LPG tank'
       END
  FROM boarding_houses bh
  JOIN utilities u
    ON u.utility_id IN (1, 2, 3)                              -- Water, Electricity, Trash
    OR (u.utility_id = 4 AND bh.monthly_rent >= 2500)         -- Internet / Wi-Fi
    OR (u.utility_id = 5 AND bh.room_capacity >= 4)           -- Cooking Gas
 WHERE bh.boarding_house_id BETWEEN 201 AND 225;
