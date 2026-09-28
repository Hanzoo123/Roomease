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
require __DIR__ . '/../includes/core/functions.php';

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

$boardingHouseId = (int) ($_POST['boarding_house_id'] ?? 0);

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
} elseif ($return === 'home') {
    $target = 'index.php';
} else {
    $target = browse_path(browse_filters($_POST, room_type_options()));
}

// A guest's tap is not lost: they log in, the listing is saved, and they land
// back where they tapped. The token is checked first, so another site cannot
// queue a save for whoever signs in next on this browser.
if (!is_logged_in()) {
    if (csrf_ok()) {
        remember_after_login($target, $boardingHouseId);
    }
    // The log-in page itself says what the sign-in is for, so no flash here.
    if ($wantsJson) {
        favorite_json(401, [
            'ok'       => false,
            'redirect' => base_url('auth/login.php'),
            'message'  => 'Log in to save this listing.',
        ]);
    }
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
if ($wantsJson && !csrf_ok()) {
    favorite_json(403, [
        'ok'      => false,
        'reload'  => true,
        'message' => 'Your session expired. Please refresh the page and try again.',
    ]);
}
verify_csrf();

$action = $_POST['action'] ?? 'save';
$userId = $_SESSION['user_id'];

// Only approved listings can be saved, matching what browse actually shows.
$check = $pdo->prepare(
    "SELECT boarding_house_id FROM boarding_houses
      WHERE boarding_house_id = ? AND moderation_status = 'approved' AND deleted_at IS NULL"
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
