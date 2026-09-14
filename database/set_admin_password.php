<?php
/**
 * Set the administrator password from the command line.
 *
 * database/roomease.sql seeds the admin account with a placeholder hash that
 * no password can match, because the previous seeded password was printed in
 * the README and was therefore public knowledge. This script is how a real
 * password gets set, so the credential never has to live in a tracked file.
 *
 *   php database/set_admin_password.php "YourStrongPassword"
 *   php database/set_admin_password.php "YourStrongPassword" admin@roomease.local
 *
 * CLI only: it refuses to run over HTTP, and database/.htaccess already blocks
 * that folder, so it cannot be reached from a browser even if that changed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

// Named so as not to collide with anything an included file defines. This is
// not hypothetical: config/db.php used to assign a global $password, so an
// earlier version of this script hashed the (empty) database password instead
// of the one given on the command line, and locked the admin out silently.
$newPassword = $argv[1] ?? '';
$adminEmail  = $argv[2] ?? 'admin@roomease.local';

if ($newPassword === '') {
    fwrite(STDERR, "Usage: php database/set_admin_password.php \"NewPassword\" [email]\n");
    exit(1);
}
if (strlen($newPassword) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);

require __DIR__ . '/../config/db.php';

$stmt = $pdo->prepare("SELECT user_id, first_name, last_name FROM users WHERE email = ? AND role = 'administrator'");
$stmt->execute([$adminEmail]);
$admin = $stmt->fetch();

if (!$admin) {
    fwrite(STDERR, "No administrator account found for $adminEmail.\n");
    fwrite(STDERR, "Import database/roomease.sql first, or pass the correct email as the second argument.\n");
    exit(1);
}

$update = $pdo->prepare('UPDATE users SET password_hash = ?, is_active = 1 WHERE user_id = ?');
$update->execute([$hash, $admin['user_id']]);

// Prove the stored hash actually matches, rather than trusting that it does.
$stored = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
$stored->execute([$admin['user_id']]);

if (!password_verify($newPassword, (string) $stored->fetchColumn())) {
    fwrite(STDERR, "The password was written but does not verify. Nothing has been changed you can rely on.\n");
    exit(1);
}

echo "Password updated for {$admin['first_name']} {$admin['last_name']} <{$adminEmail}>.\n";
echo "You can now sign in at admin/login.php.\n";
