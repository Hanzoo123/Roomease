<?php
/**
 * Save or unsave a listing for the logged-in boarder.
 *
 * Saving is a boarder feature: landlords and administrators manage listings
 * rather than shortlist them, so this endpoint is restricted to that role.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('boarder/browse.php');
}

// Guests get sent to log in rather than silently losing the click.
if (!is_logged_in()) {
    flash_set('Please log in to save listings.', 'error');
    redirect('auth/login.php');
}
if (!can_save_listings()) {
    flash_set('Only boarder accounts can save listings.', 'error');
    redirect('index.php');
}

verify_csrf();

$boardingHouseId = (int) ($_POST['boarding_house_id'] ?? 0);
$action = $_POST['action'] ?? 'save';
$userId = $_SESSION['user_id'];

/**
 * Work out where to send the boarder back to. The destination is chosen from
 * a fixed set rather than taken from the request, so this cannot be turned
 * into an open redirect. Browse filters are rebuilt from known keys only.
 */
$return = $_POST['return'] ?? 'browse';
if ($return === 'view') {
    $target = 'boarder/view_listing.php?id=' . $boardingHouseId;
} elseif ($return === 'saved') {
    $target = 'boarder/saved.php';
} else {
    $filters = [];
    foreach (['q', 'room_type', 'max_rent', 'page'] as $key) {
        $value = trim($_POST[$key] ?? '');
        if ($value !== '') {
            $filters[$key] = $value;
        }
    }
    $target = 'boarder/browse.php' . ($filters ? '?' . http_build_query($filters) : '');
}

// Only approved listings can be saved, matching what browse actually shows.
$check = $pdo->prepare(
    "SELECT boarding_house_id FROM boarding_houses
      WHERE boarding_house_id = ? AND moderation_status = 'approved'"
);
$check->execute([$boardingHouseId]);
if (!$check->fetch()) {
    flash_set('That listing is no longer available.', 'error');
    redirect($target);
}

if ($action === 'unsave') {
    $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND boarding_house_id = ?')
        ->execute([$userId, $boardingHouseId]);
    flash_set('Removed from your saved listings.', 'success');
} else {
    // INSERT IGNORE leans on the unique key, so a double submit is harmless.
    $pdo->prepare('INSERT IGNORE INTO favorites (user_id, boarding_house_id) VALUES (?, ?)')
        ->execute([$userId, $boardingHouseId]);
    flash_set('Saved to your listings.', 'success');
}

redirect($target);
