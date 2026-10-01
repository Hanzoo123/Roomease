<?php
/**
 * Save or unsave a listing (boarders only). Replies with JSON for the heart
 * button's fetch(), or a redirect when JavaScript is off.
 */
require __DIR__ . '/../includes/init.php';

$wantsJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

/** Send a JSON reply and stop (no flash message, or it would pop up on the next page). */
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

/** Where to go back to, picked from a fixed list so it can't redirect to another site. */
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

// A guest logs in first, then the listing is saved. The CSRF token is checked first.
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
