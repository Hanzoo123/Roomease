<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('admin');

$roleFilter = $_GET['role'] ?? '';
$where = "role != 'admin'";
$params = [];
if (in_array($roleFilter, ['landlord', 'boarder'], true)) {
    $where .= ' AND role = ?';
    $params[] = $roleFilter;
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

$perPage = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$totalPages = ceil($totalCount / $perPage);
if ($totalPages < 1) $totalPages = 1;
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM users WHERE $where ORDER BY created_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Manage Users';
require __DIR__ . '/../includes/header.php';
?>

<div class="section-head">
  <h2>Manage Users</h2>
  <div>
    <a href="?role=" class="btn btn-ghost btn-sm <?= $roleFilter===''?'':'' ?>">All</a>
    <a href="?role=landlord" class="btn btn-ghost btn-sm">Landlords</a>
    <a href="?role=boarder" class="btn btn-ghost btn-sm">Boarders</a>
  </div>
</div>

<div class="panel">
  <table>
    <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Action</th></tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= h($u['full_name']) ?></td>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['email']) ?></td>
        <td><span class="badge badge-<?= h($u['role']) ?>"><?= h(ucfirst($u['role'])) ?></span></td>
        <td><span class="badge badge-<?= $u['status']==='active'?'active':'inactive' ?>"><?= h(ucfirst($u['status'])) ?></span></td>
        <td><?= h(date('M j, Y', strtotime($u['created_at']))) ?></td>
        <td style="display:flex; gap:6px;">
          <form method="post" action="<?= base_url('admin/user_action.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
            <input type="hidden" name="action" value="toggle_status">
            <button type="submit" class="btn btn-ghost btn-sm"><?= $u['status']==='active' ? 'Deactivate' : 'Activate' ?></button>
          </form>
          <form method="post" action="<?= base_url('admin/user_action.php') ?>" onsubmit="return confirm('Permanently delete this user and all their listings?');">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="7">No users found.</td></tr><?php endif; ?>
  </table>
</div>
<?php render_pagination($page, $totalPages); ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
