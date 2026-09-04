<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/functions.php';
require_login('admin');

$counts = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE role='landlord') AS landlords,
        (SELECT COUNT(*) FROM users WHERE role='boarder') AS boarders,
        (SELECT COUNT(*) FROM listings) AS listings,
        (SELECT COUNT(*) FROM listings WHERE status='available') AS available"
)->fetch();

$recentListings = $pdo->query(
    "SELECT l.listing_id, l.boarding_house_name, l.city, l.status, l.created_at, u.full_name AS landlord_name
     FROM listings l JOIN users u ON u.user_id = l.landlord_id
     ORDER BY l.created_at DESC LIMIT 8"
)->fetchAll();

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<h1>Admin Panel</h1>
<p class="auth-sub">Overview of RoomEase activity.</p>

<div class="listing-grid" style="grid-template-columns: repeat(auto-fill, minmax(180px,1fr));">
  <div class="panel panel-pad">
    <div class="count-tag">LANDLORDS</div>
    <div style="font-family:'Zilla Slab',serif; font-size:32px; color:var(--board);"><?= (int)$counts['landlords'] ?></div>
  </div>
  <div class="panel panel-pad">
    <div class="count-tag">BOARDERS</div>
    <div style="font-family:'Zilla Slab',serif; font-size:32px; color:var(--board);"><?= (int)$counts['boarders'] ?></div>
  </div>
  <div class="panel panel-pad">
    <div class="count-tag">TOTAL LISTINGS</div>
    <div style="font-family:'Zilla Slab',serif; font-size:32px; color:var(--board);"><?= (int)$counts['listings'] ?></div>
  </div>
  <div class="panel panel-pad">
    <div class="count-tag">AVAILABLE NOW</div>
    <div style="font-family:'Zilla Slab',serif; font-size:32px; color:var(--brass);"><?= (int)$counts['available'] ?></div>
  </div>
</div>

<div class="section-head">
  <h2>Quick Links</h2>
</div>
<div style="display:flex; gap:12px; margin-bottom:10px;">
  <a href="<?= base_url('admin/manage_users.php') ?>" class="btn btn-primary">Manage Users</a>
  <a href="<?= base_url('admin/manage_listings.php') ?>" class="btn btn-primary">Manage Listings</a>
</div>

<div class="section-head">
  <h2>Recently Added Listings</h2>
</div>
<div class="panel">
  <table>
    <tr><th>Listing</th><th>City</th><th>Landlord</th><th>Status</th><th>Posted</th></tr>
    <?php foreach ($recentListings as $l): ?>
      <tr>
        <td><a href="<?= base_url('boarder/view_listing.php?id=' . $l['listing_id']) ?>"><?= h($l['boarding_house_name']) ?></a></td>
        <td><?= h($l['city']) ?></td>
        <td><?= h($l['landlord_name']) ?></td>
        <td><span class="badge badge-<?= $l['status']==='available'?'active':'inactive' ?>"><?= h(ucfirst($l['status'])) ?></span></td>
        <td><?= h(date('M j, Y', strtotime($l['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recentListings): ?><tr><td colspan="5">No listings yet.</td></tr><?php endif; ?>
  </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
