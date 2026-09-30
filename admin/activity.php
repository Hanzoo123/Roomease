<?php
/**
 * RoomEase Admin - Audit Log
 *
 * Who did what, from audit_logs (database/roomease.sql), on three tabs:
 *
 *   Administrators  every approval, rejection, removal and restore of a
 *                   listing, every change to an account, and every export
 *   Landlords       what landlords changed in their listings and rooms
 *   Sign-ins        every sign-in, failed sign-in, sign-out, new account and
 *                   password change, with the IP address and browser
 *
 * Super admins only: the log shows what every administrator did.
 *
 * The log only grows, so it is filtered and paged in the database rather than
 * in the browser. Opening the page also clears out sign-in records older than
 * AUDIT_SIGNIN_DAYS, as the Privacy Policy promises.
 */
require __DIR__ . '/../includes/init.php';

require_super_admin();

audit_purge_old_signins();

$types = audit_action_types();
$tabs = [
  'admin'    => ['label' => 'Administrators', 'who' => 'Administrator', 'search' => 'Listing, account, or reason'],
  'landlord' => ['label' => 'Landlords',      'who' => 'Landlord',      'search' => 'Listing, room, or change'],
  'signin'   => ['label' => 'Sign-ins',       'who' => 'Account',       'search' => 'Name or email'],
];
$group = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'admin';
$groupActions = audit_actions_in_group($group);

// Administrators: what it was done to. Sign-ins: only the failures.
$kind = $_GET['kind'] ?? '';
if (!in_array($kind, ['listing', 'user', 'administrator', 'export', 'failed'], true)) {
  $kind = '';
}
$personFilter = is_string($_GET['who'] ?? null) ? (int) $_GET['who'] : 0;
$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

$where = ['a.action IN (' . sql_placeholders(count($groupActions)) . ')'];
$params = $groupActions;
if ($group === 'admin' && in_array($kind, ['listing', 'user', 'administrator', 'export'], true)) {
  $where[] = 'a.target_type = ?';
  $params[] = $kind;
}
if ($group === 'signin' && $kind === 'failed') {
  $where[] = "a.action = 'signin_failed'";
}
if ($personFilter > 0) {
  $where[] = 'a.actor_id = ?';
  $params[] = $personFilter;
}
if ($q !== '') {
  $where[] = '(a.target_label LIKE ? OR a.detail LIKE ?)';
  $like = '%' . addcslashes($q, '%_\\') . '%';
  $params[] = $like;
  $params[] = $like;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$perPage = 25;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));

$stmt = $pdo->prepare(
  "SELECT a.*, " . account_name_sql('u') . " AS admin_name, u.avatar_path
     FROM audit_logs a
     LEFT JOIN users u ON u.user_id = a.actor_id
     $whereSql
    ORDER BY a.created_at DESC, a.log_id DESC
    LIMIT " . $perPage . " OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Everyone who appears on this tab, for the "who" filter.
$people = $pdo->prepare(
  "SELECT DISTINCT u.user_id, " . account_name_sql('u') . " AS name
     FROM audit_logs a JOIN users u ON u.user_id = a.actor_id
    WHERE a.action IN (" . sql_placeholders(count($groupActions)) . ")
    ORDER BY name"
);
$people->execute($groupActions);
$people = $people->fetchAll();

// How many entries each tab holds, for the counts beside the tab names.
$tabCounts = [];
foreach (array_keys($tabs) as $key) {
  $actions = audit_actions_in_group($key);
  $c = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action IN (' . sql_placeholders(count($actions)) . ')');
  $c->execute($actions);
  $tabCounts[$key] = (int) $c->fetchColumn();
}

/** This page's URL with the current tab and filters, changed by $overrides. */
$pageUrl = function (array $overrides = []) use ($group, $kind, $personFilter, $q) {
  $query = array_filter(array_merge(['tab' => $group, 'kind' => $kind, 'who' => $personFilter ?: '', 'q' => $q], $overrides),
    function ($value) { return $value !== '' && $value !== null; });
  return base_url('admin/activity.php' . ($query ? '?' . http_build_query($query) : ''));
};

$filtered = $kind !== '' || $personFilter || $q !== '';
$subtitles = [
  'admin'    => 'Every decision an administrator has made, with who made it and when.',
  'landlord' => 'What landlords changed in their listings and rooms, and when.',
  'signin'   => 'Every sign-in, failed attempt and password change. Kept for ' . AUDIT_SIGNIN_DAYS . ' days.',
];

$pageTitle = 'Audit Log';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<div class="content-wrapper">
  <?php panel_page_header('Audit Log', [
    'subtitle' => $subtitles[$group] . ' ' . number_format($total) . ' ' . ($total === 1 ? 'entry' : 'entries')
      . ($filtered ? ' match.' : '.'),
  ]); ?>

  <section class="content">
    <div class="container-fluid">
      <?php /* Each tab is its own page, with its own filters and paging, so these
           are plain links rather than the header's in-page tabs. */ ?>
      <nav class="re-tabs audit-tabs no-print" aria-label="Audit log">
        <?php foreach ($tabs as $key => $tab): ?>
          <a class="re-tab <?= $key === $group ? 'is-active' : '' ?>" href="<?= base_url('admin/activity.php?tab=' . $key) ?>"
            <?= $key === $group ? 'aria-current="page"' : '' ?>>
            <?= h($tab['label']) ?>
            <span class="re-tab-count"><?= number_format($tabCounts[$key]) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="card card-primary card-outline shadow-sm">
        <div class="card-header">
          <form method="get" class="form-inline flex-wrap" style="gap: 8px;">
            <input type="hidden" name="tab" value="<?= h($group) ?>">
            <?php if ($group === 'admin'): ?>
              <label class="sr-only" for="kind">Show</label>
              <select class="form-control form-control-sm" id="kind" name="kind">
                <option value="">Everything</option>
                <option value="listing" <?= $kind === 'listing' ? 'selected' : '' ?>>Listings</option>
                <option value="user" <?= $kind === 'user' ? 'selected' : '' ?>>Accounts</option>
                <option value="administrator" <?= $kind === 'administrator' ? 'selected' : '' ?>>Administrators</option>
                <option value="export" <?= $kind === 'export' ? 'selected' : '' ?>>Exports</option>
              </select>
            <?php elseif ($group === 'signin'): ?>
              <label class="sr-only" for="kind">Show</label>
              <select class="form-control form-control-sm" id="kind" name="kind">
                <option value="">Everything</option>
                <option value="failed" <?= $kind === 'failed' ? 'selected' : '' ?>>Failed sign-ins only</option>
              </select>
            <?php endif; ?>
            <?php if (count($people) > 1 || $personFilter): ?>
              <label class="sr-only" for="who"><?= h($tabs[$group]['who']) ?></label>
              <select class="form-control form-control-sm" id="who" name="who">
                <option value="">Everyone</option>
                <?php foreach ($people as $p): ?>
                  <option value="<?= (int) $p['user_id'] ?>" <?= $personFilter === (int) $p['user_id'] ? 'selected' : '' ?>>
                    <?= h($p['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
            <label class="sr-only" for="q">Search</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= h($q) ?>"
              placeholder="<?= h($tabs[$group]['search']) ?>">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <?php if ($filtered): ?>
              <a href="<?= base_url('admin/activity.php?tab=' . $group) ?>" class="btn btn-sm btn-link">Clear</a>
            <?php endif; ?>
          </form>
        </div>

        <div class="card-body p-0 table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th style="width: 170px;">When</th>
                <th><?= h($tabs[$group]['who']) ?></th>
                <th>Action</th>
                <th><?= $group === 'signin' ? 'Signed in as' : 'About' ?></th>
                <th><?= $group === 'signin' ? 'From' : 'Details' ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$entries): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted py-4">
                    <?= $filtered ? 'Nothing matches these filters.' : 'Nothing has been logged here yet.' ?>
                  </td>
                </tr>
              <?php endif; ?>
              <?php foreach ($entries as $e): ?>
                <?php $type = $types[$e['action']] ?? ['label' => $e['action'], 'badge' => 'badge-secondary']; ?>
                <tr>
                  <td>
                    <?= h(date('M j, Y g:i A', strtotime($e['created_at']))) ?>
                    <small class="text-muted d-block"><?= h(time_ago($e['created_at'])) ?></small>
                  </td>
                  <td>
                    <?php if ($e['admin_name'] !== null): ?>
                      <span class="d-flex align-items-center" style="gap: 8px;">
                        <?= avatar_html($e, 26) ?><?= h($e['admin_name']) ?>
                      </span>
                    <?php elseif ($e['action'] === 'signin_failed'): ?>
                      <span class="text-muted">No account</span>
                    <?php else: ?>
                      <span class="text-muted">Unknown</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="badge <?= h($type['badge']) ?>"><?= h($type['label']) ?></span></td>
                  <td>
                    <?php $url = admin_target_url($e['target_type'], $e['target_id']); ?>
                    <?php if ($url !== null): ?>
                      <a href="<?= h($url) ?>"><?= h($e['target_label']) ?></a>
                    <?php else: ?>
                      <?= h($e['target_label']) ?>
                    <?php endif; ?>
                  </td>
                  <td class="text-muted">
                    <?php if ($group === 'signin'): ?>
                      <?php if ($e['detail'] !== null): ?><?= h($e['detail']) ?><br><?php endif; ?>
                      <small><?= h(implode(' · ', array_filter([$e['user_agent'], $e['ip_address']]))) ?></small>
                    <?php else: ?>
                      <?= $e['detail'] !== null ? h($e['detail']) : '' ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($pages > 1): ?>
          <div class="card-footer d-flex justify-content-between align-items-center flex-wrap" style="gap: 8px;">
            <span class="text-muted">
              <?= (($page - 1) * $perPage) + 1 ?>&ndash;<?= min($total, $page * $perPage) ?> of <?= $total ?>
            </span>
            <ul class="pagination pagination-sm m-0">
              <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h($pageUrl(['page' => $page - 1])) ?>">Newer</a>
              </li>
              <li class="page-item disabled"><span class="page-link">Page <?= $page ?> of <?= $pages ?></span></li>
              <li class="page-item <?= $page === $pages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h($pageUrl(['page' => $page + 1])) ?>">Older</a>
              </li>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
</div>

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
