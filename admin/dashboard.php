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

// Waiting longest first (admin_review_queue() in includes/core/listings.php,
// which the notification bell uses too).
$needsReview = admin_review_queue(6);

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
// cannot see, rejected ones waiting on their landlord, and approved ones with
// no map pin (admin_attention_counts(), which the notification bell uses too).
$attention = admin_attention_counts();

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

// The sentence under the greeting: the queue first, in plain words. Every
// number is an integer and the wait comes from time_ago(), so it is escaped
// where it is joined in.
$attentionCount = $attention['approved_hidden'] + $attention['rejected'] + $attention['no_pin'];
if ($counts['pending'] > 0) {
  $oldestWait = $needsReview ? preg_replace('/ ago$/', '', time_ago($needsReview[0]['updated_at'])) : null;
  $status = '<strong>' . (int) $counts['pending'] . ' ' . ($counts['pending'] == 1 ? 'listing is' : 'listings are')
    . '</strong> waiting for your review'
    . ($oldestWait && $oldestWait !== 'just now' ? ($counts['pending'] == 1 ? ', for ' : ', the oldest for ') . h($oldestWait) : '') . '.';
} elseif ($attentionCount > 0) {
  $status = 'The review queue is clear. Some listings need attention below.';
} else {
  $status = 'The review queue is clear, and every approved listing can be seen.';
}

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<main class="content-wrapper" id="main" tabindex="-1">
  <?php panel_page_header('Dashboard', [
    'subtitleHtml' => $status,
  ]); ?>

  <section class="content">
    <div class="container-fluid dash">

      <?php /* The figures first. What waits on an administrator leads the row:
               marigold while something is waiting, a pale wash at zero. The
               same work is also in the bell in the top bar, on every page. */ ?>
      <div class="stat-row stat-row--dash">
        <a class="stat stat--filled <?= $counts['pending'] > 0 ? 'stat--attention' : 'stat--calm' ?>" href="<?= base_url('admin/manage_listings.php?status=pending') ?>">
          <i class="fas fa-clipboard-check stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['pending'] ?></span>
          <span class="stat-label">Awaiting approval</span>
          <span class="stat-more">
            <?= $counts['pending'] > 0 ? 'Review queue' : 'The queue is clear' ?>
            <i class="fas fa-arrow-right" aria-hidden="true"></i>
          </span>
        </a>
        <a class="stat stat--filled stat--terracotta" href="<?= base_url('admin/manage_listings.php') ?>">
          <i class="fas fa-home stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['listings'] ?></span>
          <span class="stat-label">Listings &middot; <?= (int) $live['listings'] ?> on the site</span>
          <span class="stat-more">View listings <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
        </a>
        <a class="stat stat--filled stat--teal" href="<?= base_url('admin/manage_users.php?role=landlord') ?>">
          <i class="fas fa-user-tie stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['landlords'] ?></span>
          <span class="stat-label">Landlords<?= $counts['landlords_off'] ? ' &middot; ' . (int) $counts['landlords_off'] . ' deactivated' : '' ?></span>
          <span class="stat-more">View landlords <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
        </a>
        <a class="stat stat--filled stat--green" href="<?= base_url('admin/manage_users.php?role=boarder') ?>">
          <i class="fas fa-users stat-icon" aria-hidden="true"></i>
          <span class="stat-value"><?= (int) $counts['boarders'] ?></span>
          <span class="stat-label">Boarders<?= $counts['boarders_off'] ? ' &middot; ' . (int) $counts['boarders_off'] . ' deactivated' : '' ?></span>
          <span class="stat-more">View boarders <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
        </a>
      </div>

      <?php /* Then the listing work, only while there is some: the queue waiting
               on a decision, and the listings that are not where they should
               be. Empty, they are left out; the line under the title says so. */ ?>
      <?php
      $showQueue = $needsReview || $counts['pending_hidden'] > 0;
      $showAttention = $attentionCount > 0;
      ?>
      <?php if ($showQueue || $showAttention): ?>
        <div class="row">
          <?php if ($showQueue): ?>
            <div class="<?= $showAttention ? 'col-lg-8' : 'col-12' ?>">
              <div class="card<?= $needsReview ? ' card--waiting' : '' ?>">
                <?php panel_card_header('Needs your review', '',
                  panel_card_link(base_url('admin/manage_listings.php?status=pending'), 'See all')); ?>
                <?php if ($needsReview): ?>
                  <ul class="list-group list-group-flush review-queue">
                    <?php foreach ($needsReview as $l): ?>
                      <?php $avail = listing_availability($l); ?>
                      <li class="list-group-item row-link d-flex flex-wrap align-items-center justify-content-between" style="gap: 8px;">
                        <div class="d-flex align-items-center" style="gap: 12px; min-width: 0;">
                          <?= listing_thumb_html($l) ?>
                          <div style="min-width: 0;">
                            <a class="font-weight-bold stretched-link" href="<?= base_url('admin/listing.php?id=' . (int) $l['boarding_house_id']) ?>"><?= h($l['name']) ?></a>
                            <small class="text-muted d-block">
                              <?= h($l['landlord_name']) ?>
                              <?php if ($avail['rent_from'] !== null): ?> &middot; from <?= h(peso_round($avail['rent_from'])) ?><?php endif; ?>
                            </small>
                          </div>
                        </div>
                        <div class="d-flex align-items-center" style="gap: 12px;">
                          <?php $wait = preg_replace('/ ago$/', '', time_ago($l['updated_at'])); ?>
                          <?php /* Over two days, the wait itself is the signal. */ ?>
                          <?php if (strtotime(db_now()) - strtotime($l['updated_at']) > 2 * 86400): ?>
                            <span class="wait-pill"><?= h($wait) ?></span>
                          <?php else: ?>
                            <small class="text-muted"><?= h($wait) ?></small>
                          <?php endif; ?>
                          <a href="<?= base_url('admin/listing.php?id=' . (int) $l['boarding_house_id']) ?>" class="btn btn-sm btn-primary">
                            Review<span class="sr-only"> <?= h($l['name']) ?></span>
                          </a>
                        </div>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
                <?php $moreWaiting = $counts['pending'] - count($needsReview); ?>
                <?php if ($moreWaiting > 0 || $counts['pending_hidden'] > 0): ?>
                  <div class="card-footer small text-muted">
                    <?php if ($moreWaiting > 0): ?>
                      <div><?= $moreWaiting ?> more waiting.</div>
                    <?php endif; ?>
                    <?php if ($counts['pending_hidden'] > 0): ?>
                      <div>
                        <?= (int) $counts['pending_hidden'] ?> more from deactivated landlords, held until they are active again.
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($showAttention): ?>
            <div class="<?= $showQueue ? 'col-lg-4' : 'col-12' ?>">
              <div class="card">
                <?php panel_card_header('Needs attention'); ?>
                <?php /* One short line each; the whole row is the link. */ ?>
                <ul class="list-group list-group-flush">
                  <?php if ($attention['approved_hidden']): ?>
                    <li class="list-group-item row-link dash-link-row">
                      <a class="stretched-link" href="<?= base_url('admin/manage_listings.php?status=approved') ?>">
                        <strong><?= (int) $attention['approved_hidden'] ?></strong> approved, hidden from boarders
                      </a>
                      <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </li>
                  <?php endif; ?>
                  <?php if ($attention['rejected']): ?>
                    <li class="list-group-item row-link dash-link-row">
                      <a class="stretched-link" href="<?= base_url('admin/manage_listings.php?status=rejected') ?>">
                        <strong><?= (int) $attention['rejected'] ?></strong> rejected, waiting on <?= $attention['rejected'] == 1 ? 'its landlord' : 'their landlords' ?>
                      </a>
                      <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </li>
                  <?php endif; ?>
                  <?php if ($attention['no_pin']): ?>
                    <li class="list-group-item row-link dash-link-row">
                      <a class="stretched-link" href="<?= base_url('admin/manage_listings.php?status=approved') ?>">
                        <strong><?= (int) $attention['no_pin'] ?></strong> approved without a map pin
                      </a>
                      <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    </li>
                  <?php endif; ?>
                </ul>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php /* Then the period and the people. A super admin also gets Security
               and Recent activity beside them; anyone else gets the full width. */ ?>
      <?php $superSide = $security !== null || $recentActivity !== null; ?>
      <div class="row">
        <div class="<?= $superSide ? 'col-lg-8' : 'col-12' ?>">
          <?php $totalAccounts = array_sum(array_column($days, 'accounts')); ?>
          <?php $totalListings = array_sum(array_column($days, 'listings')); ?>
          <div class="card card-primary card-outline shadow-sm">
            <?php panel_card_header(
              'Last 30 days', '',
              panel_card_link(base_url('admin/reports.php'), 'Reports')
            ); ?>
            <div class="card-body">
              <div class="chart-totals">
                <div><span class="chart-key chart-key--teal"></span> New accounts
                  <strong class="tabular"><?= $totalAccounts ?></strong></div>
                <div><span class="chart-key chart-key--terracotta"></span> New listings
                  <strong class="tabular"><?= $totalListings ?></strong></div>
                <div class="chart-totals-aside">
                  Approved <strong class="tabular"><?= (int) $decided['approved'] ?></strong>
                  &middot; Rejected <strong class="tabular"><?= (int) $decided['rejected'] ?></strong>
                </div>
              </div>
              <?php /* Accounts as columns, listings as a line over them, one day per
                   step, on one scale; hovering or tapping a day shows both. The
                   totals above are the key, so the chart has no legend of its
                   own. The sparks inside are what shows until the chart draws,
                   and if it never does; the label goes to the drawn chart. */ ?>
              <div class="re-chart" style="min-height: 180px;" data-chart="<?= h(json_encode([
                'kind' => 'mixed',
                'height' => 180,
                'legend' => false,
                'label' => 'New accounts and new listings per day over the last 30 days: '
                  . $totalAccounts . ' accounts and ' . $totalListings . ' listings in total.',
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
            </div>
          </div>

          <div class="card card-success card-outline shadow-sm">
            <?php panel_card_header(
              'New accounts', '',
              panel_card_link(base_url('admin/manage_users.php'), 'See all')
            ); ?>
            <ul class="list-group list-group-flush">
              <?php if (!$recentUsers): ?>
                <li class="list-group-item p-0"><?= re_empty('No accounts yet', 'Landlords and boarders appear here as they sign up.', 'fa-user-plus') ?></li>
              <?php endif; ?>
              <?php foreach ($recentUsers as $ru): ?>
                <li class="list-group-item row-link d-flex align-items-center" style="gap: 10px;">
                  <?= avatar_html($ru, 32) ?>
                  <div class="flex-grow-1" style="min-width: 0;">
                    <a class="font-weight-bold stretched-link" href="<?= base_url('admin/user.php?id=' . (int) $ru['user_id']) ?>"><?= h($ru['full_name']) ?></a>
                    <small class="text-muted d-block text-truncate">
                      <?= h($ru['email']) ?> &middot; <?= h(time_ago($ru['created_at'])) ?>
                      <?php if ($ru['deleted_at'] !== null): ?> &middot; removed<?php elseif (!$ru['is_active']): ?> &middot; deactivated<?php endif; ?>
                    </small>
                  </div>
                  <span class="badge badge-light border"><?= h(ucfirst($ru['role'])) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>

        <?php if ($superSide): ?>
          <div class="col-lg-4">
            <?php if ($security !== null): ?>
              <div class="card card-danger card-outline shadow-sm">
                <?php panel_card_header('Security'); ?>
                <ul class="list-group list-group-flush">
                  <li class="list-group-item row-link d-flex justify-content-between align-items-center" style="gap: 10px;">
                    <span>
                      <a class="stretched-link" href="<?= base_url('admin/activity.php?tab=signin&kind=failed') ?>">Failed sign-ins (7 days)</a>
                    </span>
                    <strong class="tabular"><?= (int) $security['failed_week'] ?></strong>
                  </li>
                  <?php if ($security['locked']): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                      <span>Accounts paused now</span>
                      <strong class="tabular text-danger"><?= (int) $security['locked'] ?></strong>
                    </li>
                  <?php endif; ?>
                  <li class="list-group-item row-link d-flex justify-content-between align-items-center" style="gap: 10px;">
                    <span>
                      <a class="stretched-link" href="<?= base_url('admin/admins.php') ?>">Administrators</a>
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
                  'Recent activity', '',
                  panel_card_link(base_url('admin/activity.php'), 'Full log')
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
                              <?= avatar_html($e, 24) ?><?= h($e['admin_name']) ?>
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
          </div>
        <?php endif; ?>
      </div>

    </div>
  </section>
</main>

<?php require __DIR__ . '/../includes/scripts/panel_charts.php'; ?>
<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
