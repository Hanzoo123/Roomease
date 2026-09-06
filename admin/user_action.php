<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';

// Ensure user is logged in as administrator
if (!is_logged_in() || !is_admin()) {
    redirect('admin/manage_users.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/manage_users.php');
}
verify_csrf();

$userId = (int) ($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? '';

// Never let an admin deactivate/delete their own account or another administrator.
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role != 'administrator'");
$stmt->execute([$userId]);
$target = $stmt->fetch();

if (!$target) {
    flash_set('User not found or cannot be modified.', 'error');
    redirect('admin/manage_users.php');
}

if ($action === 'toggle_status') {
    $newStatus = $target['is_active'] ? 0 : 1;
    $pdo->prepare('UPDATE users SET is_active = ? WHERE user_id = ?')->execute([$newStatus, $userId]);
    flash_set('User ' . ($newStatus ? 'activated' : 'deactivated') . '.', 'success');
} elseif ($action === 'delete') {
    // Clean up any uploaded images belonging to this user's boarding houses first
    $bhouses = $pdo->prepare('SELECT boarding_house_id FROM boarding_houses WHERE landlord_id = ?');
    $bhouses->execute([$userId]);
    foreach ($bhouses->fetchAll() as $bh) {
        $dir = __DIR__ . '/../assets/uploads/boarding_houses/' . $bh['boarding_house_id'];
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
