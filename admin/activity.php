<?php
/**
 * RoomEase Admin - Activity Log
 *
 * Every approval, rejection, removal and restore of a listing, every change to
 * an account, and every export, with the administrator who did it and when.
 * Read from admin_actions (database/migration_admin_tools.sql). The log only
 * grows, so it is filtered and paged in the database rather than in the
 * browser.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_login('admin');

$types = admin_action_types();

$kind = $_GET['kind'] ?? '';
if (!in_array($kind, ['listing', 'user', 'export'], true)) {
  $kind = '';
}
$adminFilter = is_string($_GET['admin'] ?? null) ? (int) $_GET['admin'] : 0;
$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

$where = [];
$params = [];
if ($kind !== '') {
  $where[] = 'a.target_type = ?';
  $params[] = $kind;
}
if ($adminFilter > 0) {
  $where[] = 'a.admin_id = ?';
  $params[] = $adminFilter;
}
if ($q !== '') {
  $where[] = '(a.target_label LIKE ? OR a.detail LIKE ?)';
  $like = '%' . addcslashes($q, '%_\\') . '%';
  $params[] = $like;
  $params[] = $like;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 25;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_actions a $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));

$stmt = $pdo->prepare(
  "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS admin_name, u.avatar_path
     FROM admin_actions a
     LEFT JOIN users u ON u.user_id = a.admin_id
     $whereSql
    ORDER BY a.created_at DESC, a.action_id DESC
    LIMIT " . $perPage . " OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$entries = $stmt->fetchAll();

// Administrators who appear in the log, for the filter.
$admins = $pdo->query(
  "SELECT DISTINCT u.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name
     FROM admin_actions a JOIN users u ON u.user_id = a.admin_id
    ORDER BY name"
)->fetchAll();

/** This page's URL with the current filters, changed by $overrides. */
$pageUrl = function (array $overrides = []) use ($kind, $adminFilter, $q) {
  $query = array_filter(array_merge(['kind' => $kind, 'admin' => $adminFilter ?: '', 'q' => $q], $overrides),
    function ($value) { return $value !== '' && $value !== null; });
  return base_url('admin/activity.php' . ($query ? '?' . http_build_query($query) : ''));
};

$pageTitle = 'Activity Log';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<div class="content-wrapper">
  <?php panel_page_header('Activity Log', [
    'subtitle' => 'Every decision an administrator has made, with who made it and when. '
      . number_format($total) . ' ' . ($total === 1 ? 'entry' : 'entries') . '.',
  ]); ?>

  <section class="content">
    <div class="container-fluid">
      <div class="card card-primary card-outline shadow-sm">
        <div class="card-header">
          <form method="get" class="form-inline flex-wrap" style="gap: 8px;">
            <label class="sr-only" for="kind">Show</label>
            <select class="form-control form-control-sm" id="kind" name="kind">
              <option value="">Everything</option>
              <option value="listing" <?= $kind === 'listing' ? 'selected' : '' ?>>Listings</option>
              <option value="user" <?= $kind === 'user' ? 'selected' : '' ?>>Accounts</option>
              <option value="export" <?= $kind === 'export' ? 'selected' : '' ?>>Exports</option>
            </select>
            <?php if (count($admins) > 1 || $adminFilter): ?>
              <label class="sr-only" for="admin">Administrator</label>
              <select class="form-control form-control-sm" id="admin" name="admin">
                <option value="">Every administrator</option>
                <?php foreach ($admins as $a): ?>
                  <option value="<?= (int) $a['user_id'] ?>" <?= $adminFilter === (int) $a['user_id'] ? 'selected' : '' ?>>
                    <?= h($a['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
            <label class="sr-only" for="q">Search</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= h($q) ?>"
              placeholder="Listing, account, or reason">
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <?php if ($kind !== '' || $adminFilter || $q !== ''): ?>
              <a href="<?= base_url('admin/activity.php') ?>" class="btn btn-sm btn-link">Clear</a>
            <?php endif; ?>
          </form>
        </div>

        <div class="card-body p-0 table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th style="width: 170px;">When</th>
                <th>Administrator</th>
                <th>Action</th>
                <th>About</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$entries): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted py-4">
                    <?= ($kind !== '' || $adminFilter || $q !== '') ? 'Nothing matches these filters.' : 'Nothing has been logged yet.' ?>
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
                  <td class="text-muted"><?= $e['detail'] !== null ? h($e['detail']) : '' ?></td>
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
