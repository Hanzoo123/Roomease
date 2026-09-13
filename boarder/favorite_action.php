<?php
/**
 * Save or unsave a listing for the logged-in boarder.
 *
 * Saving is a boarder feature: landlords and administrators manage listings
 * rather than shortlist them, so this endpoint is restricted to that role.
 *
 * The heart on a listing card posts here with fetch(), so the reply is JSON
 * when the caller asks for it and a flash + redirect otherwise. Keeping both
 * paths means the button still works with JavaScript turned off.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

$wantsJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

/**
 * Send a JSON reply and stop. Only ever reached on the fetch() path, so the
 * flash message is left alone: it would otherwise sit in the session and pop
 * up on whatever page the boarder happens to open next.
 */
function favorite_json($status, array $payload)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($wantsJson) {
        favorite_json(405, ['ok' => false, 'message' => 'Unsupported request.']);
    }
    redirect('boarder/browse.php');
}

// Guests get sent to log in rather than silently losing the click.
if (!is_logged_in()) {
    if ($wantsJson) {
        favorite_json(401, [
            'ok'       => false,
            'redirect' => base_url('auth/login.php'),
            'message'  => 'Please log in to save listings.',
        ]);
    }
    flash_set('Please log in to save listings.', 'error');
    redirect('auth/login.php');
}
if (!can_save_listings()) {
    if ($wantsJson) {
        favorite_json(403, ['ok' => false, 'message' => 'Only boarder accounts can save listings.']);
    }
    flash_set('Only boarder accounts can save listings.', 'error');
    redirect('index.php');
}

// A stale token means the page has been open since the session rolled over.
// Answer that as JSON instead of the plain-text die() inside verify_csrf().
if ($wantsJson && !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    favorite_json(403, [
        'ok'      => false,
        'reload'  => true,
        'message' => 'Your session expired. Please refresh the page and try again.',
    ]);
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
    if ($wantsJson) {
        favorite_json(404, ['ok' => false, 'message' => 'That listing is no longer available.']);
    }
    flash_set('That listing is no longer available.', 'error');
    redirect($target);
}

if ($action === 'unsave') {
    $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND boarding_house_id = ?')
        ->execute([$userId, $boardingHouseId]);
    $saved = false;
    $message = 'Removed from your saved listings.';
} else {
    // INSERT IGNORE leans on the unique key, so a double submit is harmless.
    $pdo->prepare('INSERT IGNORE INTO favorites (user_id, boarding_house_id) VALUES (?, ?)')
        ->execute([$userId, $boardingHouseId]);
    $saved = true;
    $message = 'Saved to your listings.';
}

if ($wantsJson) {
    favorite_json(200, ['ok' => true, 'saved' => $saved, 'message' => $message]);
}

flash_set($message, 'success');
redirect($target);
