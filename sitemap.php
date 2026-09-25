<?php
/**
 * The sitemap, served at /sitemap.xml by a rule in .htaccess.
 *
 * It is built from the database on each request rather than kept as a file,
 * so a listing approved this morning is in it this afternoon and one that was
 * taken down is gone. Only listings a signed-out visitor can actually open are
 * included: approved, not archived, and with a room to show.
 *
 * Dates use the format sitemaps.org asks for; a listing with no recorded
 * change simply has no <lastmod>, which is allowed.
 */
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/core/functions.php';

$pages = [
    ['loc' => absolute_url('index.php'), 'priority' => '1.0', 'freq' => 'daily'],
    ['loc' => absolute_url('boarder/browse.php'), 'priority' => '0.9', 'freq' => 'daily'],
    ['loc' => absolute_url('about.php'), 'priority' => '0.4', 'freq' => 'yearly'],
    ['loc' => absolute_url('contact.php'), 'priority' => '0.4', 'freq' => 'yearly'],
    ['loc' => absolute_url('terms.php'), 'priority' => '0.2', 'freq' => 'yearly'],
    ['loc' => absolute_url('privacy.php'), 'priority' => '0.2', 'freq' => 'yearly'],
];

try {
    $stmt = $pdo->query(
        "SELECT bh.boarding_house_id, bh.updated_at
           FROM boarding_houses bh
          WHERE bh.moderation_status = 'approved'
            AND bh.deleted_at IS NULL
            AND EXISTS (SELECT 1 FROM rooms r WHERE r.boarding_house_id = bh.boarding_house_id)
          ORDER BY bh.updated_at DESC
          LIMIT 5000"
    );
    $listings = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('RoomEase: could not build the sitemap - ' . $e->getMessage());
    $listings = [];
}

foreach ($listings as $listing) {
    $pages[] = [
        'loc' => absolute_url('boarder/view_listing.php?id=' . (int) $listing['boarding_house_id']),
        'lastmod' => $listing['updated_at'] ? date('Y-m-d', strtotime($listing['updated_at'])) : null,
        'priority' => '0.7',
        'freq' => 'weekly',
    ];
}

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as $page): ?>
  <url>
    <loc><?= h($page['loc']) ?></loc>
<?php if (!empty($page['lastmod'])): ?>
    <lastmod><?= h($page['lastmod']) ?></lastmod>
<?php endif; ?>
    <changefreq><?= h($page['freq']) ?></changefreq>
    <priority><?= h($page['priority']) ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
