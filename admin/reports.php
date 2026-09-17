<?php
/**
 * RoomEase Admin - Reports
 *
 * Where RoomEase stands (accounts, what boarders can see, rents, occupancy)
 * and how it has moved month by month (new accounts, new listings, approval
 * decisions), with the users and listings CSV exports alongside. The charts
 * are plain CSS bars: no chart library, nothing loaded from outside, and they
 * print.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_login('admin');

$months = (int) ($_GET['months'] ?? 6) === 12 ? 12 : 6;

// Month keys, oldest first, ending with this month on the database's clock.
$thisMonth = substr(db_now(), 0, 7) . '-01';
$monthKeys = [];
for ($i = $months - 1; $i >= 0; $i--) {
  $monthKeys[] = date('Y-m', strtotime("$thisMonth -$i month"));
}
$since = $monthKeys[0] . '-01';

/** Rows of [month, value columns...] from a query, folded into $monthKeys. */
$byMonth = function ($sql, array $params) use ($pdo, $monthKeys) {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $result = array_fill_keys($monthKeys, []);
  foreach ($stmt as $row) {
    if (isset($result[$row['ym']])) {
      $result[$row['ym']] = $row;
    }
  }
  return $result;
};

$accounts = $byMonth(
  "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
          SUM(role = 'landlord') AS landlords, SUM(role = 'boarder') AS boarders
     FROM users
    WHERE role <> 'administrator' AND created_at >= ?
    GROUP BY ym",
  [$since]
);
$newListings = $byMonth(
  "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS listings
     FROM boarding_houses
    WHERE created_at >= ?
    GROUP BY ym",
  [$since]
);
$decisions = $byMonth(
  "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
          SUM(action = 'listing_approve') AS approved, SUM(action = 'listing_reject') AS rejected
     FROM admin_actions
    WHERE created_at >= ? AND action IN ('listing_approve', 'listing_reject')
    GROUP BY ym",
  [$since]
);
$logStarted = $pdo->query('SELECT MIN(created_at) FROM admin_actions')->fetchColumn();

$monthRows = [];
foreach ($monthKeys as $key) {
  $monthRows[$key] = [
    'landlords' => (int) ($accounts[$key]['landlords'] ?? 0),
    'boarders'  => (int) ($accounts[$key]['boarders'] ?? 0),
    'listings'  => (int) ($newListings[$key]['listings'] ?? 0),
    'approved'  => (int) ($decisions[$key]['approved'] ?? 0),
    'rejected'  => (int) ($decisions[$key]['rejected'] ?? 0),
  ];
}
$maxAccounts = max(1, max(array_map(function ($r) { return $r['landlords'] + $r['boarders']; }, $monthRows)));
$maxListings = max(1, max(array_column($monthRows, 'listings')));

// Where things stand now.
$people = $pdo->query(
  "SELECT SUM(role = 'landlord') AS landlords, SUM(role = 'boarder') AS boarders
     FROM users WHERE role <> 'administrator' AND deleted_at IS NULL"
)->fetch();
$live = live_listing_stats();
$roomFigures = $pdo->query(
  'SELECT COUNT(*) AS rooms, AVG(r.monthly_rent) AS avg_rent,
          SUM(r.capacity) AS capacity, SUM(LEAST(r.slots_taken, r.capacity)) AS taken
     FROM rooms r
     JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id
     ' . LIVE_LANDLORD_JOIN . '
    WHERE r.is_open = 1 AND ' . LIVE_STATUS_WHERE
)->fetch();
$occupancy = (int) $roomFigures['capacity'] > 0
  ? (int) round(100 * (int) $roomFigures['taken'] / (int) $roomFigures['capacity']) : null;

$statusCounts = $pdo->query(
  "SELECT SUM(deleted_at IS NULL AND moderation_status = 'pending')  AS pending,
          SUM(deleted_at IS NULL AND moderation_status = 'approved') AS approved,
          SUM(deleted_at IS NULL AND moderation_status = 'rejected') AS rejected,
          SUM(deleted_at IS NOT NULL) AS removed
     FROM boarding_houses"
)->fetch();

// Open rooms in listings boarders can see, by type and by rent.
$roomTypes = $pdo->query(
  'SELECT rt.room_type_name AS label, COUNT(r.room_id) AS value
     FROM room_types rt
     LEFT JOIN (rooms r
       JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id
       ' . LIVE_LANDLORD_JOIN . ')
       ON r.room_type_id = rt.room_type_id AND r.is_open = 1 AND ' . LIVE_STATUS_WHERE . '
    GROUP BY rt.room_type_id, rt.room_type_name
    ORDER BY rt.room_type_id'
)->fetchAll();

$rentBands = $pdo->query(
  "SELECT SUM(r.monthly_rent < 1000) AS b1,
          SUM(r.monthly_rent >= 1000 AND r.monthly_rent < 2000) AS b2,
          SUM(r.monthly_rent >= 2000 AND r.monthly_rent < 3000) AS b3,
          SUM(r.monthly_rent >= 3000 AND r.monthly_rent < 5000) AS b4,
          SUM(r.monthly_rent >= 5000) AS b5
     FROM rooms r
     JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id
     " . LIVE_LANDLORD_JOIN . "
    WHERE r.is_open = 1 AND " . LIVE_STATUS_WHERE
)->fetch();
$rentRows = [
  ['label' => 'Under ₱1,000', 'value' => (int) $rentBands['b1']],
  ['label' => '₱1,000 – ₱1,999', 'value' => (int) $rentBands['b2']],
  ['label' => '₱2,000 – ₱2,999', 'value' => (int) $rentBands['b3']],
  ['label' => '₱3,000 – ₱4,999', 'value' => (int) $rentBands['b4']],
  ['label' => '₱5,000 and up', 'value' => (int) $rentBands['b5']],
];

/** One labelled horizontal bar per row, scaled to the largest value. */
function bar_rows(array $rows)
{
  $max = max(1, max(array_map('intval', array_column($rows, 'value')) ?: [0]));
  $html = '<div class="bars">';
  foreach ($rows as $row) {
    $value = (int) $row['value'];
    $html .= '<div class="bar-row"><span class="bar-label">' . h($row['label']) . '</span>'
      . '<span class="bar" aria-hidden="true"><span style="width:' . round(100 * $value / $max, 1) . '%"></span></span>'
      . '<span class="bar-value">' . $value . '</span></div>';
  }
  return $html . '</div>';
}

$pageTitle = 'Reports';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<div class="content-wrapper">
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold"><i class="fas fa-chart-bar text-primary mr-2"></i>Reports</h1>
          <p class="text-muted mb-0 mt-1">As of <?= h(date('F j, Y g:i A', strtotime(db_now()))) ?></p>
        </div>
        <div class="col-sm-6 d-flex flex-wrap justify-content-sm-end no-print" style="gap: 8px;">
          <div class="btn-group" role="group" aria-label="Period">
            <a href="<?= base_url('admin/reports.php?months=6') ?>" class="btn btn-sm btn-outline-primary <?= $months === 6 ? 'active' : '' ?>">6 months</a>
            <a href="<?= base_url('admin/reports.php?months=12') ?>" class="btn btn-sm btn-outline-primary <?= $months === 12 ? 'active' : '' ?>">12 months</a>
          </div>
          <a href="<?= base_url('admin/export.php?type=users') ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-csv mr-1"></i> Users CSV</a>
          <a href="<?= base_url('admin/export.php?type=listings') ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-csv mr-1"></i> Listings CSV</a>
          <button type="button" class="btn btn-sm btn-outline-secondary js-print"><i class="fas fa-print mr-1"></i> Print</button>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">

      <div class="stat-row stat-row--six">
        <div class="stat"><span class="stat-value"><?= (int) $people['landlords'] ?></span><span class="stat-label">Landlords</span></div>
        <div class="stat"><span class="stat-value"><?= (int) $people['boarders'] ?></span><span class="stat-label">Boarders</span></div>
        <div class="stat"><span class="stat-value"><?= (int) $live['listings'] ?></span><span class="stat-label">Listings boarders can see</span></div>
        <div class="stat"><span class="stat-value"><?= (int) $roomFigures['rooms'] ?></span><span class="stat-label">Open rooms in them</span></div>
        <div class="stat">
          <span class="stat-value"><?= $roomFigures['avg_rent'] !== null ? '&#8369;' . number_format(round((float) $roomFigures['avg_rent'])) : '—' ?></span>
          <span class="stat-label">Average rent</span>
        </div>
        <div class="stat">
          <span class="stat-value"><?= $occupancy !== null ? $occupancy . '%' : '—' ?></span>
          <span class="stat-label">Of their slots taken</span>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header"><h3 class="card-title font-weight-bold">Month by month</h3></div>
        <div class="card-body p-0 table-responsive">
          <table class="table mb-0 report-table">
            <thead>
              <tr>
                <th>Month</th>
                <th>New accounts</th>
                <th class="text-right">Landlords</th>
                <th class="text-right">Boarders</th>
                <th>New listings</th>
                <th class="text-right">Approved</th>
                <th class="text-right">Rejected</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (array_reverse($monthRows, true) as $key => $r): ?>
                <?php $accountsTotal = $r['landlords'] + $r['boarders']; ?>
                <tr>
                  <td class="font-weight-bold"><?= h(date('F Y', strtotime($key . '-01'))) ?></td>
                  <td class="bar-cell">
                    <span class="bar" aria-hidden="true"><span style="width:<?= round(100 * $accountsTotal / $maxAccounts, 1) ?>%"></span></span>
                    <span class="bar-value"><?= $accountsTotal ?></span>
                  </td>
                  <td class="text-right tabular"><?= $r['landlords'] ?></td>
                  <td class="text-right tabular"><?= $r['boarders'] ?></td>
                  <td class="bar-cell">
                    <span class="bar" aria-hidden="true"><span style="width:<?= round(100 * $r['listings'] / $maxListings, 1) ?>%"></span></span>
                    <span class="bar-value"><?= $r['listings'] ?></span>
                  </td>
                  <td class="text-right tabular"><?= $r['approved'] ?></td>
                  <td class="text-right tabular"><?= $r['rejected'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer text-muted small">
          <?php if ($logStarted): ?>
            Approvals and rejections are counted from the activity log, which started on
            <?= h(date('F j, Y', strtotime($logStarted))) ?>. Accounts and listings count everything created, including
            ones removed since.
          <?php else: ?>
            Approvals and rejections are counted from the activity log, which has no entries yet.
          <?php endif; ?>
        </div>
      </div>

      <div class="row">
        <div class="col-lg-4">
          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title font-weight-bold">Listings by status</h3></div>
            <div class="card-body">
              <?= bar_rows([
                ['label' => 'Pending', 'value' => $statusCounts['pending']],
                ['label' => 'Approved', 'value' => $statusCounts['approved']],
                ['label' => 'Rejected', 'value' => $statusCounts['rejected']],
                ['label' => 'Removed', 'value' => $statusCounts['removed']],
              ]) ?>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title font-weight-bold">Open rooms by type</h3></div>
            <div class="card-body"><?= bar_rows($roomTypes) ?></div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title font-weight-bold">Open rooms by monthly rent</h3></div>
            <div class="card-body"><?= bar_rows($rentRows) ?></div>
          </div>
        </div>
      </div>

    </div>
  </section>
</div>

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
<script>
  document.querySelector('.js-print').addEventListener('click', function () { window.print(); });
</script>
