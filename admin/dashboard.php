<?php
/**
 * RoomEase Admin Dashboard
 *
 * What needs the administrator now (listings waiting for a decision, oldest
 * first), how the last 30 days went, and what administrators have done
 * lately. Removed accounts and removed listings are not counted.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_login('admin');

$counts = $pdo->query(
  "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'landlord' AND deleted_at IS NULL) AS landlords,
        (SELECT COUNT(*) FROM users WHERE role = 'boarder' AND deleted_at IS NULL) AS boarders,
        (SELECT COUNT(*) FROM boarding_houses bh
           JOIN users u ON u.user_id = bh.landlord_id AND u.deleted_at IS NULL
          WHERE bh.deleted_at IS NULL) AS listings"
)->fetch();
$counts['pending'] = pending_listing_count();
$live = live_listing_stats();

// Waiting longest first. A listing edited after a rejection goes back to
// pending, so "waiting since" is its last change rather than when it was posted.
$needsReview = $pdo->query(
  "SELECT bh.boarding_house_id, bh.name, bh.address, bh.created_at, bh.updated_at, " . ROOM_SUMMARY_COLUMNS . ",
          " . COVER_PHOTO_SELECT . ",
          CONCAT(u.first_name, ' ', u.last_name) AS landlord_name
     FROM boarding_houses bh
     JOIN users u ON u.user_id = bh.landlord_id AND u.is_active = 1 AND u.deleted_at IS NULL
     " . room_summary_join() . "
    WHERE bh.moderation_status = 'pending' AND bh.deleted_at IS NULL
    ORDER BY bh.updated_at ASC
    LIMIT 6"
)->fetchAll();

// The last 30 days, one bucket per day, oldest first. Days are counted on the
// database's clock, which is the one created_at was written with.
$today = substr(db_now(), 0, 10);
$days = [];
for ($i = 29; $i >= 0; $i--) {
  $days[date('Y-m-d', strtotime("$today -$i day"))] = ['accounts' => 0, 'listings' => 0];
}
$since = array_key_first($days);
$daily = function ($sql, $column) use ($pdo, $since, &$days) {
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$since]);
  foreach ($stmt as $row) {
    if (isset($days[$row['d']])) {
      $days[$row['d']][$column] = (int) $row['n'];
    }
  }
};
$daily("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM users
         WHERE role <> 'administrator' AND created_at >= ? GROUP BY d", 'accounts');
$daily("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM boarding_houses
         WHERE created_at >= ? GROUP BY d", 'listings');
$decided = $pdo->prepare(
  "SELECT SUM(action = 'listing_approve') AS approved, SUM(action = 'listing_reject') AS rejected
     FROM admin_actions WHERE created_at >= ?"
);
$decided->execute([$since]);
$decided = $decided->fetch();

$recentActivity = $pdo->query(
  "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS admin_name, u.avatar_path
     FROM admin_actions a LEFT JOIN users u ON u.user_id = a.admin_id
    ORDER BY a.created_at DESC, a.action_id DESC
    LIMIT 6"
)->fetchAll();
$types = admin_action_types();

$recentUsers = $pdo->query(
  "SELECT user_id, CONCAT(first_name, ' ', last_name) AS full_name, email, role, is_active,
          deleted_at, created_at, avatar_path
     FROM users
    WHERE role <> 'administrator'
    ORDER BY created_at DESC
    LIMIT 5"
)->fetchAll();

/** A row of 30 thin columns, one per day, scaled to the busiest day. */
function spark(array $days, $column, $label, $tone)
{
  $values = array_column($days, $column);
  $max = max(1, max($values));
  $total = array_sum($values);
  $html = '<div class="spark spark--' . $tone . '" role="img" aria-label="' . h($label . ' per day over the last 30 days: ' . $total . ' in total') . '">';
  foreach ($days as $date => $counts) {
    $value = $counts[$column];
    $html .= '<span style="height:' . ($value ? max(8, round(100 * $value / $max)) : 0) . '%" title="'
      . h(date('M j', strtotime($date)) . ': ' . $value) . '"></span>';
  }
  return $html . '</div>';
}

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<div class="content-wrapper">
  <?php panel_page_header('Dashboard', [
    'subtitle' => 'What needs you now, how the last 30 days went, and what administrators have done lately.',
    'actions' => '<a href="' . base_url('admin/manage_listings.php?status=pending')
      . '" class="btn btn-sm btn-primary"><i class="fas fa-clipboard-check mr-1"></i> Review queue</a>'
      . '<a href="' . base_url('admin/reports.php') . '" class="btn btn-sm btn-outline-secondary">'
      . '<i class="fas fa-chart-bar mr-1"></i> Reports</a>',
  ]); ?>

  <section class="content">
    <div class="container-fluid">

      <div class="stat-row">
        <a class="stat stat--filled stat--teal" href="<?= base_url('admin/manage_users.php?role=landlord') ?>">
          <i class="fas fa-user-tie stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['landlords'] ?></span>
          <span class="stat-label">Landlords</span>
          <span class="stat-more">View landlords <i class="fas fa-arrow-circle-right" aria-hidden="true"></i></span>
        </a>
        <a class="stat stat--filled stat--green" href="<?= base_url('admin/manage_users.php?role=boarder') ?>">
          <i class="fas fa-users stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['boarders'] ?></span>
          <span class="stat-label">Boarders</span>
          <span class="stat-more">View boarders <i class="fas fa-arrow-circle-right" aria-hidden="true"></i></span>
        </a>
        <a class="stat stat--filled stat--terracotta" href="<?= base_url('admin/manage_listings.php') ?>">
          <i class="fas fa-home stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['listings'] ?></span>
          <span class="stat-label">Listings &middot; <?= (int) $live['listings'] ?> on the site</span>
          <span class="stat-more">View listings <i class="fas fa-arrow-circle-right" aria-hidden="true"></i></span>
        </a>
        <a class="stat stat--filled stat--attention" href="<?= base_url('admin/manage_listings.php?status=pending') ?>">
          <i class="fas fa-clipboard-check stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['pending'] ?></span>
          <span class="stat-label">Awaiting approval</span>
          <span class="stat-more">Review queue <i class="fas fa-arrow-circle-right" aria-hidden="true"></i></span>
        </a>
      </div>

      <div class="row">
        <div class="col-lg-8">
          <div class="card card-warning card-outline shadow-sm">
            <?php panel_card_header(
              'Needs your review',
              'Listings waiting for a decision, the one that has waited longest first.',
              '<a href="' . base_url('admin/manage_listings.php?status=pending') . '" class="btn btn-tool">All pending</a>'
            ); ?>
            <?php if (!$needsReview): ?>
              <?= re_empty(
                'The queue is clear',
                'Nothing is waiting for a decision. New listings will appear here as landlords post them.',
                'fa-clipboard-check'
              ) ?>
            <?php else: ?>
              <ul class="list-group list-group-flush review-queue">
                <?php foreach ($needsReview as $l): ?>
                  <?php $avail = listing_availability($l); ?>
                  <li class="list-group-item d-flex flex-wrap align-items-center justify-content-between" style="gap: 8px;">
                    <div class="d-flex align-items-center" style="gap: 12px;">
                      <?= listing_thumb_html($l) ?>
                      <div>
                        <a class="font-weight-bold" href="<?= base_url('admin/listing.php?id=' . (int) $l['boarding_house_id']) ?>"><?= h($l['name']) ?></a>
                        <small class="text-muted d-block">
                          <?= h($l['landlord_name']) ?> &middot; <?= h($avail['summary']) ?>
                          <?php if ($avail['rent_from'] !== null): ?> &middot; from <?= h(peso_round($avail['rent_from'])) ?><?php endif; ?>
                        </small>
                      </div>
                    </div>
                    <div class="d-flex align-items-center" style="gap: 12px;">
                      <small class="text-muted">waiting <?= h(preg_replace('/ ago$/', '', time_ago($l['updated_at']))) ?></small>
                      <a href="<?= base_url('admin/listing.php?id=' . (int) $l['boarding_house_id']) ?>" class="btn btn-sm btn-primary">Review</a>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>

          <div class="card card-primary card-outline shadow-sm">
            <div class="card-header card-header--split">
              <div style="min-width: 0;">
                <h3 class="card-title">Last 30 days</h3>
                <span class="card-subtitle">New accounts and new listings, one bar per day.</span>
              </div>
              <div class="card-tools">
                <a href="<?= base_url('admin/reports.php') ?>" class="btn btn-tool">Reports</a>
              </div>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6 mb-3 mb-md-0">
                  <div class="d-flex justify-content-between align-items-baseline">
                    <span class="text-muted">New accounts</span>
                    <strong class="tabular"><?= array_sum(array_column($days, 'accounts')) ?></strong>
                  </div>
                  <?= spark($days, 'accounts', 'New accounts', 'teal') ?>
                </div>
                <div class="col-md-6">
                  <div class="d-flex justify-content-between align-items-baseline">
                    <span class="text-muted">New listings</span>
                    <strong class="tabular"><?= array_sum(array_column($days, 'listings')) ?></strong>
                  </div>
                  <?= spark($days, 'listings', 'New listings', 'terracotta') ?>
                </div>
              </div>
              <p class="text-muted small mb-0 mt-3">
                <?= (int) $decided['approved'] ?> approved and <?= (int) $decided['rejected'] ?> rejected in the same period.
              </p>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <div class="card card-info card-outline shadow-sm">
            <?php panel_card_header(
              'Recent activity',
              'The last few things an administrator did.',
              '<a href="' . base_url('admin/activity.php') . '" class="btn btn-tool">Full log</a>'
            ); ?>
            <div class="card-body<?= $recentActivity ? '' : ' p-0' ?>">
              <?php if (!$recentActivity): ?>
                <?= re_empty('Nothing logged yet', 'Approvals, rejections and account changes will appear here.', 'fa-history') ?>
              <?php else: ?>
                <ul class="review-history">
                  <?php foreach ($recentActivity as $e): ?>
                    <?php $type = $types[$e['action']] ?? ['label' => $e['action'], 'badge' => 'badge-secondary']; ?>
                    <?php $url = admin_target_url($e['target_type'], $e['target_id']); ?>
                    <li>
                      <span class="badge <?= h($type['badge']) ?>"><?= h($type['label']) ?></span>
                      <?php if ($url !== null): ?>
                        <a href="<?= h($url) ?>"><?= h($e['target_label']) ?></a>
                      <?php else: ?>
                        <?= h($e['target_label']) ?>
                      <?php endif; ?>
                      <small class="text-muted d-flex align-items-center" style="gap: 6px;">
                        <?php if ($e['admin_name'] !== null): ?>
                          <?= avatar_html($e, 18) ?><?= h($e['admin_name']) ?>
                        <?php else: ?>
                          Unknown
                        <?php endif; ?>
                        &middot; <?= h(time_ago($e['created_at'])) ?>
                      </small>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>

          <div class="card card-success card-outline shadow-sm">
            <?php panel_card_header(
              'New accounts',
              'The most recent landlords and boarders to sign up.',
              '<a href="' . base_url('admin/manage_users.php') . '" class="btn btn-tool">All users</a>'
            ); ?>
            <ul class="list-group list-group-flush">
              <?php if (!$recentUsers): ?>
                <li class="list-group-item p-0"><?= re_empty('No accounts yet', 'Landlords and boarders appear here as they sign up.', 'fa-user-plus') ?></li>
              <?php endif; ?>
              <?php foreach ($recentUsers as $ru): ?>
                <li class="list-group-item d-flex align-items-center" style="gap: 10px;">
                  <?= avatar_html($ru, 32) ?>
                  <div class="flex-grow-1" style="min-width: 0;">
                    <a class="font-weight-bold" href="<?= base_url('admin/user.php?id=' . (int) $ru['user_id']) ?>"><?= h($ru['full_name']) ?></a>
                    <span class="badge badge-light border float-right"><?= h(ucfirst($ru['role'])) ?></span>
                    <small class="text-muted d-block text-truncate">
                      <?= h($ru['email']) ?> &middot; <?= h(time_ago($ru['created_at'])) ?>
                      <?php if ($ru['deleted_at'] !== null): ?> &middot; removed<?php elseif (!$ru['is_active']): ?> &middot; deactivated<?php endif; ?>
                    </small>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>

    </div>
  </section>
</div>

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
