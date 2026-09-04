<?php
/**
 * RoomEase - One-time migration script for Amenities normalization (3NF)
 */
require __DIR__ . '/../config/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    echo "Starting Amenities normalization migration...\n";

    // 1. Create the lookup table: amenities
    echo "Creating 'amenities' table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS amenities (
            amenity_id INT AUTO_INCREMENT PRIMARY KEY,
            amenity_name VARCHAR(100) NOT NULL UNIQUE
        ) ENGINE=InnoDB;
    ");

    // 2. Create the junction table: listing_amenities
    echo "Creating 'listing_amenities' table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS listing_amenities (
            listing_id INT NOT NULL,
            amenity_id INT NOT NULL,
            PRIMARY KEY (listing_id, amenity_id),
            CONSTRAINT fk_listing_amenities_listing FOREIGN KEY (listing_id)
                REFERENCES listings(listing_id) ON DELETE CASCADE,
            CONSTRAINT fk_listing_amenities_amenity FOREIGN KEY (amenity_id)
                REFERENCES amenities(amenity_id) ON DELETE CASCADE
        ) ENGINE=InnoDB;
    ");

    // 3. Pre-seed default amenities
    echo "Seeding default amenities...\n";
    $defaults = [
        'WiFi', 'Air Conditioning', 'Own Comfort Room', 'Shared Kitchen',
        'Parking Space', 'Laundry Area', 'CCTV', '24/7 Security',
        'Furnished', 'Study Table', 'Near Transport Terminal'
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO amenities (amenity_name) VALUES (?)");
    foreach ($defaults as $amen) {
        $ins->execute([$amen]);
    }

    // 4. Map existing listing data if the 'amenities' column still exists in listings
    $checkCol = $pdo->query("SHOW COLUMNS FROM listings LIKE 'amenities'");
    $hasCol = $checkCol->fetch();

    if ($hasCol) {
        echo "Found 'amenities' column in 'listings'. Migrating existing data...\n";
        
        // Fetch all amenities for mapping
        $stmt = $pdo->query("SELECT amenity_id, amenity_name FROM amenities");
        $amenityMap = [];
        while ($row = $stmt->fetch()) {
            // Keep map lowercase for case-insensitive matching if needed
            $amenityMap[strtolower($row['amenity_name'])] = $row['amenity_id'];
        }

        // Fetch existing listings
        $listings = $pdo->query("SELECT listing_id, amenities FROM listings")->fetchAll();
        $insertedCount = 0;

        foreach ($listings as $l) {
            if (empty($l['amenities'])) {
                continue;
            }
            $amens = array_filter(array_map('trim', explode(',', $l['amenities'])));
            foreach ($amens as $name) {
                $lowerName = strtolower($name);
                // If it's a new custom amenity not in defaults, insert it
                if (!isset($amenityMap[$lowerName])) {
                    echo "Adding new amenity to table: '{$name}'\n";
                    $ins->execute([$name]);
                    $newId = $pdo->lastInsertId();
                    $amenityMap[$lowerName] = $newId;
                }
                
                $amenityId = $amenityMap[$lowerName];
                
                // Insert relation
                $relIns = $pdo->prepare("INSERT IGNORE INTO listing_amenities (listing_id, amenity_id) VALUES (?, ?)");
                $relIns->execute([$l['listing_id'], $amenityId]);
                $insertedCount++;
            }
        }
        echo "Migrated {$insertedCount} listing-amenity relations.\n";

        // 5. Drop the 'amenities' column from listings
        echo "Dropping 'amenities' column from 'listings' table to complete 3NF...\n";
        $pdo->exec("ALTER TABLE listings DROP COLUMN amenities");
        echo "Successfully dropped 'amenities' column.\n";
    } else {
        echo "'amenities' column already dropped or does not exist in 'listings' table. Skipping migration of data.\n";
    }

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "ERROR: Migration failed: " . $e->getMessage() . "\n";
}
