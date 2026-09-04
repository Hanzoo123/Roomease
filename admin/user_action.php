<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/manage_users.php');
}
verify_csrf();

$userId = (int) ($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? '';

// Never let an admin deactivate/delete their own account or another admin.
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role != 'admin'");
$stmt->execute([$userId]);
$target = $stmt->fetch();

if (!$target) {
    flash_set('User not found or cannot be modified.', 'error');
    redirect('admin/manage_users.php');
}

if ($action === 'toggle_status') {
    $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
    $pdo->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$newStatus, $userId]);
    flash_set('User ' . ($newStatus === 'active' ? 'activated' : 'deactivated') . '.', 'success');
} elseif ($action === 'delete') {
    // Clean up any uploaded photos belonging to this user's listings first.
    $listings = $pdo->prepare('SELECT listing_id FROM listings WHERE landlord_id = ?');
    $listings->execute([$userId]);
    foreach ($listings->fetchAll() as $l) {
        $dir = __DIR__ . '/../assets/uploads/listings/' . $l['listing_id'];
        if (is_dir($dir)) {
            array_map('unlink', glob("$dir/*.*") ?: []);
            rmdir($dir);
        }
    }
    $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$userId]);
    flash_set('User deleted.', 'success');
} else {
    flash_set('Unknown action.', 'error');
}

redirect('admin/manage_users.php');
