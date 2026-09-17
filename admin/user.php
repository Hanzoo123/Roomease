<?php
/**
 * RoomEase Admin - One account
 *
 * A landlord's or boarder's profile and status, their listings or saved
 * listings, how they sign in, and every change an administrator made to the
 * account, with the same actions as Manage Users. Administrator accounts are
 * not managed here, exactly as in Manage Users.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

require_login('admin');

$userId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role <> 'administrator'");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
  flash_set('User not found.', 'error');
  redirect('admin/manage_users.php');
}

$fullName = trim($user['first_name'] . ' ' . $user['last_name']);
$isLandlord = $user['role'] === 'landlord';
$removed = $user['deleted_at'] !== null;

$listings = [];
$saved = [];
if ($isLandlord) {
  // Every listing, removed ones included, with its room figures.
  $listStmt = $pdo->prepare(
    'SELECT bh.*, ' . ROOM_SUMMARY_COLUMNS . '
       FROM boarding_houses bh
       ' . room_summary_join() . '
      WHERE bh.landlord_id = ?
      ORDER BY bh.deleted_at IS NOT NULL, bh.created_at DESC'
  );
  $listStmt->execute([$userId]);
  $listings = $listStmt->fetchAll();
} else {
  $savedStmt = $pdo->prepare(
    'SELECT bh.boarding_house_id, bh.name, bh.moderation_status, bh.deleted_at, f.created_at AS saved_at
       FROM favorites f
       JOIN boarding_houses bh ON bh.boarding_house_id = f.boarding_house_id
      WHERE f.user_id = ?
      ORDER BY f.created_at DESC'
  );
  $savedStmt->execute([$userId]);
  $saved = $savedStmt->fetchAll();
}

// How the account signs in right now.
$devices = 0;
$failedLogins = 0;
try {
  $d = $pdo->prepare('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ? AND expires_at > NOW()');
  $d->execute([$userId]);
  $devices = (int) $d->fetchColumn();
  $f = $pdo->prepare(
    "SELECT COUNT(*) FROM login_attempts WHERE kind = 'login' AND identifier = ? AND attempted_at > NOW() - INTERVAL 1 DAY"
  );
  $f->execute([mb_strtolower($user['email'])]);
  $failedLogins = (int) $f->fetchColumn();
} catch (PDOException $e) {
  // Either table may be missing on a database that skipped a migration.
}

$history = admin_actions_for('user', $userId);
$types = admin_action_types();

$pageTitle = $fullName;
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>

<div class="content-wrapper">
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-7">
          <h1 class="m-0 font-weight-bold"><?= h($fullName) ?></h1>
          <p class="text-muted mb-0 mt-1"><?= $isLandlord ? 'Landlord' : 'Boarder' ?> &middot; joined <?= h(date('F j, Y', strtotime($user['created_at']))) ?></p>
        </div>
        <div class="col-sm-5">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= base_url('admin/dashboard.php') ?>">Home</a></li>
            <li class="breadcrumb-item">
              <a href="<?= base_url('admin/manage_users.php' . ($removed ? '?view=archived' : '')) ?>">Manage Users</a>
            </li>
            <li class="breadcrumb-item active"><?= h($fullName) ?></li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <div class="row">
        <div class="col-lg-4">
          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title font-weight-bold">Account</h3></div>
            <div class="card-body">
              <p class="mb-3">
                <?php if ($removed): ?>
                  <span class="badge badge-dark px-2 py-1"><i class="fas fa-archive mr-1"></i> Removed <?= h(date('M j, Y', strtotime($user['deleted_at']))) ?></span>
                <?php elseif ($user['is_active']): ?>
                  <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Active</span>
                <?php else: ?>
                  <span class="badge badge-danger px-2 py-1"><i class="fas fa-ban mr-1"></i> Deactivated</span>
                <?php endif; ?>
              </p>
              <dl class="review-terms review-terms--stacked">
                <dt>Email</dt>
                <dd><a href="mailto:<?= h($user['email']) ?>"><?= h($user['email']) ?></a></dd>
                <dt>Phone</dt>
                <dd><?= h($user['phone_number'] ?: 'Not given') ?></dd>
                <dt>Signs in with</dt>
                <dd><?= $user['google_id'] ? 'Google, and a password if one was set' : 'Email and password' ?></dd>
                <dt>Remembered devices</dt>
                <dd class="tabular"><?= $devices ?></dd>
                <dt>Failed sign-ins, last 24 hours</dt>
                <dd class="tabular"><?= $failedLogins ?></dd>
              </dl>

              <div class="d-flex flex-wrap mt-3" style="gap: 8px;">
                <?php if ($removed): ?>
                  <form method="post" action="<?= base_url('admin/user_action.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="return_to" value="user">
                    <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-trash-restore mr-1"></i> Restore account</button>
                  </form>
                <?php else: ?>
                  <form method="post" action="<?= base_url('admin/user_action.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="return_to" value="user">
                    <?php if ($user['is_active']): ?>
                      <button type="submit" class="btn btn-outline-warning btn-sm"><i class="fas fa-user-slash mr-1"></i> Deactivate</button>
                    <?php else: ?>
                      <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-user-check mr-1"></i> Activate</button>
                    <?php endif; ?>
                  </form>
                  <form method="post" action="<?= base_url('admin/user_action.php') ?>" class="js-confirm"
                    data-confirm="Remove <?= h($fullName) ?>? Their account<?= $isLandlord ? ' and listings' : '' ?> will be hidden from the site. Nothing is deleted, and it can be restored.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="return_to" value="user">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash mr-1"></i> Remove</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title font-weight-bold">History</h3></div>
            <div class="card-body">
              <?php if (!$history): ?>
                <p class="text-muted mb-0">No administrator has changed this account since the activity log started.</p>
              <?php else: ?>
                <ul class="review-history">
                  <?php foreach ($history as $e): ?>
                    <?php $type = $types[$e['action']] ?? ['label' => $e['action'], 'badge' => 'badge-secondary']; ?>
                    <li>
                      <span class="badge <?= h($type['badge']) ?>"><?= h(preg_replace('/ account$/', '', $type['label'])) ?></span>
                      by <?= $e['admin_name'] !== null ? h($e['admin_name']) : 'an administrator' ?>
                      <small class="text-muted d-block"><?= h(date('M j, Y g:i A', strtotime($e['created_at']))) ?></small>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-lg-8">
          <?php if ($isLandlord): ?>
            <div class="card shadow-sm">
              <div class="card-header">
                <h3 class="card-title font-weight-bold">Listings <span class="text-muted font-weight-normal">(<?= count($listings) ?>)</span></h3>
              </div>
              <div class="card-body p-0 table-responsive">
                <?php if (!$listings): ?>
                  <p class="text-muted m-3">This landlord has not added a listing.</p>
                <?php else: ?>
                  <table class="table mb-0">
                    <thead><tr><th>Boarding house</th><th>Rooms</th><th>Approval</th><th>Posted</th></tr></thead>
                    <tbody>
                      <?php foreach ($listings as $l): ?>
                        <?php $avail = listing_availability($l); ?>
                        <tr>
                          <td class="font-weight-bold">
                            <a href="<?= base_url('admin/listing.php?id=' . (int) $l['boarding_house_id']) ?>"><?= h($l['name']) ?></a>
                            <small class="text-muted d-block font-weight-normal"><?= h($l['address']) ?></small>
                          </td>
                          <td><?= h($avail['summary']) ?></td>
                          <td>
                            <?php if ($l['deleted_at'] !== null): ?>
                              <span class="badge badge-dark">Removed</span>
                            <?php else: ?>
                              <?= moderation_badge($l['moderation_status']) ?>
                            <?php endif; ?>
                          </td>
                          <td class="text-muted"><?= h(date('M j, Y', strtotime($l['created_at']))) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="card shadow-sm">
              <div class="card-header">
                <h3 class="card-title font-weight-bold">Saved listings <span class="text-muted font-weight-normal">(<?= count($saved) ?>)</span></h3>
              </div>
              <div class="card-body p-0 table-responsive">
                <?php if (!$saved): ?>
                  <p class="text-muted m-3">This boarder has not saved a listing.</p>
                <?php else: ?>
                  <table class="table mb-0">
                    <thead><tr><th>Boarding house</th><th>Approval</th><th>Saved</th></tr></thead>
                    <tbody>
                      <?php foreach ($saved as $s): ?>
                        <tr>
                          <td class="font-weight-bold">
                            <a href="<?= base_url('admin/listing.php?id=' . (int) $s['boarding_house_id']) ?>"><?= h($s['name']) ?></a>
                          </td>
                          <td>
                            <?php if ($s['deleted_at'] !== null): ?>
                              <span class="badge badge-dark">Removed</span>
                            <?php else: ?>
                              <?= moderation_badge($s['moderation_status']) ?>
                            <?php endif; ?>
                          </td>
                          <td class="text-muted"><?= h(date('M j, Y', strtotime($s['saved_at']))) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</div>

<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
<script>
  // Removing asks first. The question is text in an attribute, not script.
  document.querySelectorAll('form.js-confirm').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault();
    });
  });
</script>
