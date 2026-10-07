<?php
/**
 * Admin dashboard: pending listings (oldest first), the last 30 days, and
 * recent admin activity. Removed accounts and listings aren't counted.
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';

require_login('admin');

// Accounts include deactivated ones (the tiles say how many), and listings
// include those of deactivated landlords. Pending listings of a deactivated
// landlord are left out of the queue, so they are counted on their own.
$counts = $pdo->query(
  "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'landlord' AND deleted_at IS NULL) AS landlords,
        (SELECT COUNT(*) FROM users WHERE role = 'landlord' AND deleted_at IS NULL AND is_active = 0) AS landlords_off,
        (SELECT COUNT(*) FROM users WHERE role = 'boarder' AND deleted_at IS NULL) AS boarders,
        (SELECT COUNT(*) FROM users WHERE role = 'boarder' AND deleted_at IS NULL AND is_active = 0) AS boarders_off,
        (SELECT COUNT(*) FROM boarding_houses bh
           JOIN users u ON u.user_id = bh.landlord_id AND u.deleted_at IS NULL
          WHERE bh.deleted_at IS NULL) AS listings,
        (SELECT COUNT(*) FROM boarding_houses bh
           JOIN users u ON u.user_id = bh.landlord_id AND u.deleted_at IS NULL AND u.is_active = 0
          WHERE bh.deleted_at IS NULL AND bh.moderation_status = 'pending') AS pending_hidden"
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
// database's clock, which is the one created_at was written with. Like the
// tiles, it leaves out removed accounts and listings.
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
         WHERE role NOT IN " . ADMIN_ROLES_SQL . " AND deleted_at IS NULL AND created_at >= ? GROUP BY d", 'accounts');
$daily("SELECT DATE(bh.created_at) AS d, COUNT(*) AS n FROM boarding_houses bh
          JOIN users u ON u.user_id = bh.landlord_id AND u.deleted_at IS NULL
         WHERE bh.deleted_at IS NULL AND bh.created_at >= ? GROUP BY d", 'listings');
$decided = $pdo->prepare(
  "SELECT SUM(action = 'listing_approve') AS approved, SUM(action = 'listing_reject') AS rejected
     FROM audit_logs WHERE created_at >= ?"
);
$decided->execute([$since]);
$decided = $decided->fetch();

// Needs attention, for every administrator: approved listings boarders
// cannot see (no room, or a deactivated landlord), rejected ones waiting on
// their landlord, and approved ones with no map pin for Find places near me.
$attention = $pdo->query(
  "SELECT COALESCE(SUM(bh.moderation_status = 'approved' AND (u.is_active = 0 OR NOT EXISTS (
            SELECT 1 FROM rooms r WHERE r.boarding_house_id = bh.boarding_house_id))), 0) AS approved_hidden,
          COALESCE(SUM(bh.moderation_status = 'rejected'), 0) AS rejected,
          MIN(CASE WHEN bh.moderation_status = 'rejected' THEN COALESCE(bh.moderated_at, bh.updated_at) END) AS rejected_since,
          COALESCE(SUM(bh.moderation_status = 'approved' AND bh.latitude IS NULL), 0) AS no_pin
     FROM boarding_houses bh
     JOIN users u ON u.user_id = bh.landlord_id AND u.deleted_at IS NULL
    WHERE bh.deleted_at IS NULL"
)->fetch();

// Security, for super admins only: failed sign-ins this week (from the audit
// log, which keeps them for AUDIT_SIGNIN_DAYS), accounts paused right now by
// too many wrong passwords, and the administrators.
$security = null;
if (is_super_admin()) {
  $security = $pdo->query(
    "SELECT (SELECT COUNT(*) FROM audit_logs
              WHERE action = 'signin_failed' AND created_at > NOW() - INTERVAL 7 DAY) AS failed_week,
            (SELECT COUNT(*) FROM users WHERE role IN " . ADMIN_ROLES_SQL . " AND deleted_at IS NULL) AS admins,
            (SELECT COUNT(*) FROM users
              WHERE role IN " . ADMIN_ROLES_SQL . " AND deleted_at IS NULL AND must_change_password = 1) AS admins_must_change"
  )->fetch();
  $security['locked'] = 0;
  if (throttle_available()) {
    $security['locked'] = (int) $pdo->query(
      "SELECT COUNT(*) FROM (SELECT identifier FROM login_attempts
          WHERE kind = 'login' AND attempted_at > NOW() - INTERVAL " . LOGIN_WINDOW_SECONDS . " SECOND
          GROUP BY identifier HAVING COUNT(*) >= " . LOGIN_MAX_PER_ACCOUNT . ") paused"
    )->fetchColumn();
  }
}

// Recent admin actions. Super admins only (others get null, and no card).
$recentActivity = null;
if (is_super_admin()) {
  $adminActions = audit_actions_in_group('admin');
  $recentActivity = $pdo->prepare(
    "SELECT a.*, " . account_name_sql('u') . " AS admin_name, u.avatar_path
       FROM audit_logs a LEFT JOIN users u ON u.user_id = a.actor_id
      WHERE a.action IN (" . sql_placeholders(count($adminActions)) . ")
      ORDER BY a.created_at DESC, a.log_id DESC
      LIMIT 4"
  );
  $recentActivity->execute($adminActions);
  $recentActivity = $recentActivity->fetchAll();
}
$types = audit_action_types();

$recentUsers = $pdo->query(
  "SELECT user_id, CONCAT(first_name, ' ', last_name) AS full_name, email, role, is_active,
          deleted_at, created_at, avatar_path
     FROM users
    WHERE role NOT IN " . ADMIN_ROLES_SQL . (is_super_admin() ? '' : ' AND deleted_at IS NULL') . "
    ORDER BY created_at DESC
    LIMIT 4"
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
    'subtitle' => 'What is waiting for a decision, who has joined, and the last 30 days on RoomEase.',
  ]); ?>

  <section class="content">
    <div class="container-fluid">

      <div class="stat-row">
        <a class="stat stat--filled stat--teal" href="<?= base_url('admin/manage_users.php?role=landlord') ?>">
          <i class="fas fa-user-tie stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['landlords'] ?></span>
          <span class="stat-label">Landlords<?= $counts['landlords_off'] ? ' &middot; ' . (int) $counts['landlords_off'] . ' deactivated' : '' ?></span>
          <span class="stat-more">View landlords <i class="fas fa-arrow-circle-right" aria-hidden="true"></i></span>
        </a>
        <a class="stat stat--filled stat--green" href="<?= base_url('admin/manage_users.php?role=boarder') ?>">
          <i class="fas fa-users stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['boarders'] ?></span>
          <span class="stat-label">Boarders<?= $counts['boarders_off'] ? ' &middot; ' . (int) $counts['boarders_off'] . ' deactivated' : '' ?></span>
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
            <?php $moreWaiting = $counts['pending'] - count($needsReview); ?>
            <?php if ($moreWaiting > 0 || $counts['pending_hidden'] > 0): ?>
              <div class="card-footer small text-muted">
                <?php if ($moreWaiting > 0): ?>
                  <div>
                    <?= $moreWaiting ?> more waiting.
                    <a href="<?= base_url('admin/manage_listings.php?status=pending') ?>">See the whole queue</a>
                  </div>
                <?php endif; ?>
                <?php if ($counts['pending_hidden'] > 0): ?>
                  <div>
                    <?= $counts['pending_hidden'] == 1
                      ? '1 more pending listing belongs to a deactivated landlord. It stays'
                      : (int) $counts['pending_hidden'] . ' more pending listings belong to deactivated landlords. They stay' ?>
                    out of this queue until the account is active again.
                    <a href="<?= base_url('admin/manage_listings.php?status=pending') ?>">See every pending listing</a>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="card card-primary card-outline shadow-sm">
            <?php panel_card_header(
              'Last 30 days',
              'New accounts and new listings, one day per step. Hover a day for its figures.',
              '<a href="' . base_url('admin/reports.php') . '" class="btn btn-tool">Reports</a>'
            ); ?>
            <div class="card-body">
              <div class="chart-totals">
                <div><span class="chart-key chart-key--teal"></span> New accounts
                  <strong class="tabular"><?= array_sum(array_column($days, 'accounts')) ?></strong></div>
                <div><span class="chart-key chart-key--terracotta"></span> New listings
                  <strong class="tabular"><?= array_sum(array_column($days, 'listings')) ?></strong></div>
              </div>
              <?php /* Accounts as columns, listings as a line over them, one day per
                   step, on one scale; hovering a day shows both. The totals above
                   are the key, so the chart has no legend of its own. The sparks
                   inside are what shows until the chart draws, and if it never does. */ ?>
              <div class="re-chart" style="min-height: 260px;" data-chart="<?= h(json_encode([
                'kind' => 'mixed',
                'height' => 260,
                'legend' => false,
                'categories' => array_map(function ($d) { return date('M j', strtotime($d)); }, array_keys($days)),
                'tickAmount' => 6,
                'columnWidth' => '60%',
                'series' => [
                  ['name' => 'New accounts', 'type' => 'column', 'data' => array_column($days, 'accounts'), 'color' => 'teal'],
                  ['name' => 'New listings', 'type' => 'line', 'data' => array_column($days, 'listings'), 'color' => 'terracotta'],
                ],
              ])) ?>">
                <div class="row">
                  <div class="col-md-6 mb-3 mb-md-0"><?= spark($days, 'accounts', 'New accounts', 'teal') ?></div>
                  <div class="col-md-6"><?= spark($days, 'listings', 'New listings', 'terracotta') ?></div>
                </div>
              </div>
              <p class="text-muted small mb-0 mt-3">
                <?= (int) $decided['approved'] ?> approved and <?= (int) $decided['rejected'] ?> rejected in the same period.
              </p>
            </div>
          </div>
        </div>

        <div class="col-lg-4">
          <?php $attentionCount = $attention['approved_hidden'] + $attention['rejected'] + $attention['no_pin']; ?>
          <div class="card card-warning card-outline shadow-sm">
            <?php panel_card_header('Needs attention', 'Listings that are not where they should be.'); ?>
            <?php if (!$attentionCount): ?>
              <?= re_empty_line('Nothing needs attention. Every approved listing can be seen and has a map pin.') ?>
            <?php else: ?>
              <ul class="list-group list-group-flush">
                <?php if ($attention['approved_hidden']): ?>
                  <li class="list-group-item">
                    <strong><?= (int) $attention['approved_hidden'] ?> approved</strong>
                    <?= $attention['approved_hidden'] == 1 ? 'listing boarders cannot see' : 'listings boarders cannot see' ?>:
                    no room yet, or the landlord is deactivated.
                    <a class="d-block small" href="<?= base_url('admin/manage_listings.php?status=approved') ?>">See approved listings</a>
                  </li>
                <?php endif; ?>
                <?php if ($attention['rejected']): ?>
                  <li class="list-group-item">
                    <?php $oldest = $attention['rejected_since'] ? preg_replace('/ ago$/', '', time_ago($attention['rejected_since'])) : null; ?>
                    <strong><?= (int) $attention['rejected'] ?> rejected</strong>, waiting on the landlord to fix
                    <?= $attention['rejected'] == 1 ? 'it' : 'them' ?><?= $oldest ? ' (the oldest for ' . h($oldest) . ')' : '' ?>.
                    <a class="d-block small" href="<?= base_url('admin/manage_listings.php?status=rejected') ?>">See rejected listings</a>
                  </li>
                <?php endif; ?>
                <?php if ($attention['no_pin']): ?>
                  <li class="list-group-item">
                    <strong><?= (int) $attention['no_pin'] ?> approved</strong> without a map pin, so left out of
                    Find places near me.
                    <a class="d-block small" href="<?= base_url('admin/manage_listings.php?status=approved') ?>">See approved listings</a>
                  </li>
                <?php endif; ?>
              </ul>
            <?php endif; ?>
          </div>

          <?php if ($security !== null): ?>
            <div class="card card-danger card-outline shadow-sm">
              <?php panel_card_header('Security', 'Sign-ins and administrators. Super admins only.'); ?>
              <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <a href="<?= base_url('admin/activity.php?tab=signin&kind=failed') ?>">Failed sign-ins, last 7 days</a>
                  <strong class="tabular"><?= (int) $security['failed_week'] ?></strong>
                </li>
                <?php if ($security['locked']): ?>
                  <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span>
                      Accounts paused now
                      <small class="text-muted d-block"><?= LOGIN_MAX_PER_ACCOUNT ?> wrong passwords in <?= LOGIN_WINDOW_SECONDS / 60 ?> minutes</small>
                    </span>
                    <strong class="tabular text-danger"><?= (int) $security['locked'] ?></strong>
                  </li>
                <?php endif; ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <span>
                    <a href="<?= base_url('admin/admins.php') ?>">Administrators</a>
                    <?php if ($security['admins_must_change']): ?>
                      <small class="text-muted d-block"><?= (int) $security['admins_must_change'] ?> must still change their password</small>
                    <?php endif; ?>
                  </span>
                  <strong class="tabular"><?= (int) $security['admins'] ?></strong>
                </li>
              </ul>
            </div>
          <?php endif; ?>

          <?php /* The audit log is for super admins only, this glimpse of it included. */ ?>
          <?php if ($recentActivity !== null): ?>
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
          <?php endif; ?>

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

<?php require __DIR__ . '/../includes/scripts/panel_charts.php'; ?>
<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
