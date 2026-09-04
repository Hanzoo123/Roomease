<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('admin');

$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM listings")->fetchColumn();

$perPage = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$totalPages = ceil($totalCount / $perPage);
if ($totalPages < 1) $totalPages = 1;
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$listings = $pdo->query(
    "SELECT l.*, u.full_name AS landlord_name
     FROM listings l JOIN users u ON u.user_id = l.landlord_id
     ORDER BY l.created_at DESC
     LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
)->fetchAll();

$pageTitle = 'Manage Listings';
require __DIR__ . '/../includes/header.php';
?>

<div class="section-head">
  <h2>Manage Listings</h2>
  <span class="count-tag"><?= (int)$totalCount ?> total</span>
</div>

<div class="panel">
  <table>
    <tr><th>Listing</th><th>City</th><th>Landlord</th><th>Rent</th><th>Status</th><th>Posted</th><th>Action</th></tr>
    <?php foreach ($listings as $l): ?>
      <tr>
        <td><a href="<?= base_url('boarder/view_listing.php?id=' . $l['listing_id']) ?>"><?= h($l['boarding_house_name']) ?></a></td>
        <td><?= h($l['city']) ?></td>
        <td><?= h($l['landlord_name']) ?></td>
        <td><?= peso($l['monthly_rent']) ?></td>
        <td><span class="badge badge-<?= $l['status']==='available'?'active':'inactive' ?>"><?= h(ucfirst($l['status'])) ?></span></td>
        <td><?= h(date('M j, Y', strtotime($l['created_at']))) ?></td>
        <td>
          <form method="post" action="<?= base_url('admin/listing_action.php') ?>" onsubmit="return confirm('Remove this listing from RoomEase?');">
            <?= csrf_field() ?>
            <input type="hidden" name="listing_id" value="<?= (int)$l['listing_id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$listings): ?><tr><td colspan="7">No listings yet.</td></tr><?php endif; ?>
  </table>
</div>
<?php render_pagination($page, $totalPages); ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
