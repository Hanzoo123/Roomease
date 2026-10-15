<?php
/** A boarder cancels one of their own pending room requests. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require_login('boarder');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('boarder/reservations.php');
}
verify_csrf();

if (($_POST['action'] ?? '') === 'cancel') {
    [$ok, $message] = cancel_reservation($_POST['reservation_id'] ?? 0, $_SESSION['user_id']);
    flash_set($message, $ok ? 'success' : 'error');
} else {
    flash_set('Unknown action.', 'error');
}

redirect('boarder/reservations.php');
