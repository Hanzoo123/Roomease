<?php
/**
 * RoomEase Admin - CSV export of users or listings
 *
 *   admin/export.php?type=users     with Manage Users' filters: role, view=archived
 *   admin/export.php?type=listings  with Manage Listings' filters: status, view=removed
 *
 * The file opens in Excel or Google Sheets. It starts with a byte-order mark so
 * Excel reads it as UTF-8 (the peso sign, names with ñ), and any cell that
 * begins like a formula is prefixed with an apostrophe: a landlord could
 * otherwise type "=HYPERLINK(...)" as a listing name and have it run in the
 * spreadsheet of the administrator who opens the export.
 *
 * Every export is written to the activity log.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_login('admin');

$type = $_GET['type'] ?? '';
if (!in_array($type, ['users', 'listings'], true)) {
  flash_set('Unknown export.', 'error');
  redirect('admin/dashboard.php');
}

/** A cell made safe to open in a spreadsheet. */
function csv_cell($value)
{
  $value = (string) ($value ?? '');
  return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

$filters = [];

if ($type === 'users') {
  $archived = ($_GET['view'] ?? '') === 'archived';
  $role = $_GET['role'] ?? '';
  $where = ["role <> 'administrator'", 'deleted_at IS ' . ($archived ? 'NOT NULL' : 'NULL')];
  $params = [];
  if (in_array($role, ['landlord', 'boarder'], true)) {
    $where[] = 'role = ?';
    $params[] = $role;
    $filters[] = $role . 's';
  }
  if ($archived) {
    $filters[] = 'removed';
  }

  $stmt = $pdo->prepare('SELECT * FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC');
  $stmt->execute($params);

  $header = ['ID', 'First name', 'Last name', 'Email', 'Phone', 'Role', 'Status', 'Signs in with Google', 'Joined', 'Removed on'];
  $rows = [];
  foreach ($stmt as $u) {
    $rows[] = [
      $u['user_id'], $u['first_name'], $u['last_name'], $u['email'], $u['phone_number'], ucfirst($u['role']),
      $u['deleted_at'] !== null ? 'Removed' : ($u['is_active'] ? 'Active' : 'Deactivated'),
      $u['google_id'] ? 'Yes' : 'No',
      date('Y-m-d', strtotime($u['created_at'])),
      $u['deleted_at'] !== null ? date('Y-m-d', strtotime($u['deleted_at'])) : '',
    ];
  }
} else {
  $removed = ($_GET['view'] ?? '') === 'removed';
  $status = $_GET['status'] ?? '';
  $where = ['bh.deleted_at IS ' . ($removed ? 'NOT NULL' : 'NULL')];
  $params = [];
  if (!$removed && in_array($status, ['pending', 'approved', 'rejected'], true)) {
    $where[] = 'bh.moderation_status = ?';
    $params[] = $status;
    $filters[] = $status;
  }
  if ($removed) {
    $filters[] = 'removed';
  }

  $stmt = $pdo->prepare(
    "SELECT bh.*, " . ROOM_SUMMARY_COLUMNS . ",
            CONCAT(u.first_name, ' ', u.last_name) AS landlord_name, u.email AS landlord_email,
            u.is_active AS landlord_active, u.deleted_at AS landlord_deleted_at,
            CONCAT(m.first_name, ' ', m.last_name) AS moderator_name
       FROM boarding_houses bh
       JOIN users u ON u.user_id = bh.landlord_id
       LEFT JOIN users m ON m.user_id = bh.moderated_by
       " . room_summary_join() . "
      WHERE " . implode(' AND ', $where) . "
      ORDER BY bh.created_at DESC"
  );
  $stmt->execute($params);

  $header = ['ID', 'Boarding house', 'Address', 'Contact number', 'Landlord', 'Landlord email', 'Landlord account',
    'Approval', 'Decided by', 'Decided on', 'Rejection reason', 'Visibility', 'Removed on',
    'Rooms', 'Rooms available', 'Rent from (PHP)', 'Room types', 'Posted'];
  $rows = [];
  foreach ($stmt as $l) {
    $avail = listing_availability($l);
    $rows[] = [
      $l['boarding_house_id'], $l['name'], $l['address'], $l['contact_number'], $l['landlord_name'], $l['landlord_email'],
      $l['landlord_deleted_at'] !== null ? 'Removed' : ($l['landlord_active'] ? 'Active' : 'Deactivated'),
      ucfirst($l['moderation_status']), $l['moderator_name'],
      $l['moderated_at'] ? date('Y-m-d', strtotime($l['moderated_at'])) : '',
      $l['rejection_reason'],
      $l['availability_status'] === 'available' ? 'Shown' : 'Hidden by landlord',
      $l['deleted_at'] !== null ? date('Y-m-d', strtotime($l['deleted_at'])) : '',
      $avail['room_count'], (int) ($l['rooms_available'] ?? 0),
      $avail['rent_from'] !== null ? number_format((float) $avail['rent_from'], 2, '.', '') : '',
      $l['room_types'], date('Y-m-d', strtotime($l['created_at'])),
    ];
  }
}

log_admin_action('export_' . $type, null, ucfirst($type) . ' CSV',
  count($rows) . ' ' . (count($rows) === 1 ? 'row' : 'rows') . ($filters ? ' (' . implode(', ', $filters) . ')' : ''));

$filename = 'roomease-' . $type . ($filters ? '-' . implode('-', $filters) : '') . '-' . substr(db_now(), 0, 10) . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
// The escape character is given explicitly: newer PHP versions warn when it is
// left to the default, and '' is what spreadsheets expect anyway.
fputcsv($out, $header, ',', '"', '');
foreach ($rows as $row) {
  fputcsv($out, array_map('csv_cell', $row), ',', '"', '');
}
fclose($out);
