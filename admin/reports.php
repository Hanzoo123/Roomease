<?php
/**
 * Reports: current totals (accounts, listings, rents, occupancy), monthly
 * trends, and the CSV exports. Charts are plain CSS bars.
 */
require __DIR__ . '/../includes/init.php';

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
     FROM audit_logs
    WHERE created_at >= ? AND action IN ('listing_approve', 'listing_reject')
    GROUP BY ym",
  [$since]
);
// When administrators' decisions started being logged, which is what the
// approvals and rejections above are counted from.
$adminActions = audit_actions_in_group('admin');
$logStarted = $pdo->prepare(
  'SELECT MIN(created_at) FROM audit_logs WHERE action IN (' . sql_placeholders(count($adminActions)) . ')'
);
$logStarted->execute($adminActions);
$logStarted = $logStarted->fetchColumn();

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
// 'short' is the chart's axis label, which has a column's width to fit in.
$rentRows = [
  ['label' => 'Under ₱1,000', 'short' => 'Under ₱1k', 'value' => (int) $rentBands['b1']],
  ['label' => '₱1,000 – ₱1,999', 'short' => '₱1k–2k', 'value' => (int) $rentBands['b2']],
  ['label' => '₱2,000 – ₱2,999', 'short' => '₱2k–3k', 'value' => (int) $rentBands['b3']],
  ['label' => '₱3,000 – ₱4,999', 'short' => '₱3k–5k', 'value' => (int) $rentBands['b4']],
  ['label' => '₱5,000 and up', 'short' => '₱5k and up', 'value' => (int) $rentBands['b5']],
];

/** One labelled horizontal bar per row, scaled to the largest value. */
function bar_rows(array $rows, $tone = '')
{
  $max = max(1, max(array_map('intval', array_column($rows, 'value')) ?: [0]));
  $html = '<div class="bars">';
  foreach ($rows as $row) {
    $value = (int) $row['value'];
    $html .= '<div class="bar-row"><span class="bar-label">' . h($row['label']) . '</span>'
      . '<span class="bar bar--' . h($row['tone'] ?? $tone) . '" aria-hidden="true"><span style="width:' . round(100 * $value / $max, 1) . '%"></span></span>'
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
    <?php
    $reportActions = '<div class="btn-group" role="group" aria-label="Period">'
      . '<a href="' . base_url('admin/reports.php?months=6') . '" class="btn btn-sm btn-outline-primary '
      . ($months === 6 ? 'active' : '') . '">6 months</a>'
      . '<a href="' . base_url('admin/reports.php?months=12') . '" class="btn btn-sm btn-outline-primary '
      . ($months === 12 ? 'active' : '') . '">12 months</a></div>'
      . '<a href="' . base_url('admin/export.php?type=users') . '" class="btn btn-sm btn-outline-secondary">'
      . '<i class="fas fa-file-csv mr-1"></i> Users CSV</a>'
      . '<a href="' . base_url('admin/export.php?type=listings') . '" class="btn btn-sm btn-outline-secondary">'
      . '<i class="fas fa-file-csv mr-1"></i> Listings CSV</a>'
      . '<button type="button" class="btn btn-sm btn-outline-secondary js-print">'
      . '<i class="fas fa-print mr-1"></i> Print</button>';

    panel_page_header('Reports', [
      'subtitle' => 'How RoomEase is doing, as of ' . date('F j, Y g:i A', strtotime(db_now())) . '.',
      'actions' => $reportActions,
    ]);
    ?>

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
        <div class="card-header"><h3 class="card-title">Month by month</h3>
              <span class="card-subtitle">New accounts and new listings, with the decisions made on them. Hover a month for its figures.</span></div>
        <div class="card-body pb-0">
          <div class="re-chart" style="min-height: 300px;" data-chart="<?= h(json_encode([
            'kind' => 'mixed',
            'height' => 300,
            'categories' => array_map(function ($k) { return date('M Y', strtotime($k . '-01')); }, array_keys($monthRows)),
            'leftTitle' => 'Accounts',
            'series' => [
              ['name' => 'New landlords', 'type' => 'column', 'data' => array_column($monthRows, 'landlords'), 'color' => 'teal'],
              ['name' => 'New boarders', 'type' => 'column', 'data' => array_column($monthRows, 'boarders'), 'color' => 'green'],
              ['name' => 'New listings', 'type' => 'line', 'data' => array_column($monthRows, 'listings'), 'color' => 'terracotta', 'axis' => 1],
            ],
          ])) ?>"></div>
        </div>
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
                    <span class="bar bar--teal" aria-hidden="true"><span style="width:<?= round(100 * $accountsTotal / $maxAccounts, 1) ?>%"></span></span>
                    <span class="bar-value"><?= $accountsTotal ?></span>
                  </td>
                  <td class="text-right tabular"><?= $r['landlords'] ?></td>
                  <td class="text-right tabular"><?= $r['boarders'] ?></td>
                  <td class="bar-cell">
                    <span class="bar bar--terracotta" aria-hidden="true"><span style="width:<?= round(100 * $r['listings'] / $maxListings, 1) ?>%"></span></span>
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
            Approvals and rejections are counted from the audit log, which started on
            <?= h(date('F j, Y', strtotime($logStarted))) ?>. Accounts and listings count everything created, including
            ones removed since.
          <?php else: ?>
            Approvals and rejections are counted from the audit log, which has no entries yet.
          <?php endif; ?>
        </div>
      </div>

      <div class="row">
        <div class="col-lg-4">
          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title">Listings by status</h3>
              <span class="card-subtitle">Where every listing stands with approval.</span></div>
            <?php $statusRows = [
              ['label' => 'Pending', 'value' => $statusCounts['pending'], 'tone' => 'marigold'],
              ['label' => 'Approved', 'value' => $statusCounts['approved'], 'tone' => 'green'],
              ['label' => 'Rejected', 'value' => $statusCounts['rejected'], 'tone' => 'red'],
              ['label' => 'Removed', 'value' => $statusCounts['removed'], 'tone' => 'slate'],
            ]; ?>
            <div class="card-body">
              <div class="re-chart" style="min-height: 260px;" data-chart="<?= h(json_encode([
                'kind' => 'donut',
                'height' => 260,
                'labels' => array_column($statusRows, 'label'),
                'values' => array_map('intval', array_column($statusRows, 'value')),
                'colors' => array_column($statusRows, 'tone'),
                'totalLabel' => 'Listings',
              ])) ?>"><?= bar_rows($statusRows) ?></div>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title">Open rooms by type</h3>
              <span class="card-subtitle">What boarders can actually book right now.</span></div>
            <div class="card-body"><div class="re-chart" style="min-height: 260px;" data-chart="<?= h(json_encode(['kind' => 'hbar', 'height' => 260, 'seriesName' => 'Open rooms', 'categories' => array_column($roomTypes, 'label'), 'values' => array_map('intval', array_column($roomTypes, 'value')), 'colors' => ['terracotta']])) ?>"><?= bar_rows($roomTypes, 'terracotta') ?></div></div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title">Open rooms by monthly rent</h3>
              <span class="card-subtitle">How the available rooms are priced.</span></div>
            <div class="card-body"><div class="re-chart" style="min-height: 260px;" data-chart="<?= h(json_encode(['kind' => 'column', 'height' => 260, 'seriesName' => 'Open rooms', 'categories' => array_column($rentRows, 'short'), 'values' => array_map('intval', array_column($rentRows, 'value')), 'colors' => ['terracotta']])) ?>"><?= bar_rows($rentRows, 'terracotta') ?></div></div>
          </div>
        </div>
      </div>

    </div>
  </section>
</div>

<?php require __DIR__ . '/../includes/scripts/panel_charts.php'; ?>
<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
<script>
  document.querySelector('.js-print').addEventListener('click', function () { window.print(); });
</script>
