<?php
/** A landlord accepts or declines a pending room request (landlord/reservations.php). */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require_login('landlord');

$returnStatus = $_POST['return_status'] ?? '';
$back = 'landlord/reservations.php'
    . (in_array($returnStatus, ['pending', 'accepted', 'declined', 'cancelled'], true) ? '?status=' . $returnStatus : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('landlord/reservations.php');
}
verify_csrf();

$landlordId = (int) $_SESSION['user_id'];
$reservationId = (int) ($_POST['reservation_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($action === 'accept') {
    [$ok, $message] = accept_reservation($reservationId, $landlordId);
} elseif ($action === 'decline') {
    $note = is_string($_POST['landlord_note'] ?? null) ? $_POST['landlord_note'] : '';
    [$ok, $message] = decline_reservation($reservationId, $landlordId, $note);
} else {
    [$ok, $message] = [false, 'Unknown action.'];
}

flash_set($message, $ok ? 'success' : 'error');
redirect($back);
