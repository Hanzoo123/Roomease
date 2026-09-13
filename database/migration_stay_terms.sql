-- =========================================================
-- RoomEase migration: stay terms and map location
-- =========================================================
-- Adds the details a boarder asks about before visiting, so the listing
-- page can answer them instead of the boarder having to call first:
--
--   curfew               free text, e.g. "10:00 PM" or "No curfew"
--   security_deposit     pesos; NULL when the landlord has not said,
--                        0 when no deposit is required
--   minimum_stay_months  shortest stay the landlord accepts
--   payment_methods      comma list from payment_method_options() in
--                        includes/functions.php, e.g. "cash,gcash"
--   gender_policy        'any', 'female' or 'male'
--   visitors_allowed,
--   pets_allowed,
--   cooking_allowed      1 allowed, 0 not allowed, NULL not stated
--   latitude, longitude  the pin on the listing's map
--
--   mysql -u root -p roomease < database/migration_stay_terms.sql
--
-- Every column is nullable and NULL means "not stated", which is true of
-- every existing listing, so no backfill is needed and the listing page
-- simply leaves out whatever a landlord has not filled in.
--
-- Safe to run more than once.
-- =========================================================

USE roomease;

DELIMITER $$

DROP PROCEDURE IF EXISTS roomease_add_column $$

CREATE PROCEDURE roomease_add_column(IN col_name VARCHAR(64), IN col_definition VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'boarding_houses'
           AND COLUMN_NAME  = col_name
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE boarding_houses ADD COLUMN ', col_name, ' ', col_definition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DELIMITER ;

CALL roomease_add_column('curfew',              'VARCHAR(60) NULL DEFAULT NULL AFTER house_rules');
CALL roomease_add_column('security_deposit',    'DECIMAL(10, 2) NULL DEFAULT NULL AFTER curfew');
CALL roomease_add_column('minimum_stay_months', 'TINYINT UNSIGNED NULL DEFAULT NULL AFTER security_deposit');
CALL roomease_add_column('payment_methods',     'VARCHAR(100) NULL DEFAULT NULL AFTER minimum_stay_months');
CALL roomease_add_column('gender_policy',       "ENUM('any', 'female', 'male') NULL DEFAULT NULL AFTER payment_methods");
CALL roomease_add_column('visitors_allowed',    'TINYINT(1) NULL DEFAULT NULL AFTER gender_policy');
CALL roomease_add_column('pets_allowed',        'TINYINT(1) NULL DEFAULT NULL AFTER visitors_allowed');
CALL roomease_add_column('cooking_allowed',     'TINYINT(1) NULL DEFAULT NULL AFTER pets_allowed');
CALL roomease_add_column('latitude',            'DECIMAL(9, 6) NULL DEFAULT NULL AFTER cooking_allowed');
CALL roomease_add_column('longitude',           'DECIMAL(9, 6) NULL DEFAULT NULL AFTER latitude');

DROP PROCEDURE IF EXISTS roomease_add_column;
