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
  <?php
  // The account's own actions belong in the header, beside its name, rather
  // than buried in the card below: they are what this page is for.
  $csrf = csrf_field();
  $act = function ($action, $class, $icon, $label, $confirm = null) use ($userId, $csrf) {
      return '<form method="post" action="' . base_url('admin/user_action.php') . '"'
          . ($confirm !== null ? ' class="js-confirm" data-confirm="' . h($confirm) . '"' : '') . '>'
          . $csrf
          . '<input type="hidden" name="user_id" value="' . (int) $userId . '">'
          . '<input type="hidden" name="action" value="' . $action . '">'
          . '<input type="hidden" name="return_to" value="user">'
          . '<button type="submit" class="btn btn-sm ' . $class . '">'
          . '<i class="fas ' . $icon . ' mr-1"></i> ' . $label . '</button></form>';
  };

  if ($removed) {
      $pageActions = $act('restore', 'btn-success', 'fa-trash-restore', 'Restore account');
  } else {
      $pageActions = $user['is_active']
          ? $act('toggle_status', 'btn-outline-warning', 'fa-user-slash', 'Deactivate')
          : $act('toggle_status', 'btn-success', 'fa-user-check', 'Activate');
      $pageActions .= $act(
          'delete',
          'btn-outline-danger',
          'fa-trash',
          'Remove',
          'Remove ' . $fullName . '? Their account' . ($isLandlord ? ' and listings' : '')
            . ' will be hidden from the site. Nothing is deleted, and it can be restored.'
      );
  }

  panel_page_header($fullName, [
    'subtitle' => ($isLandlord ? 'Landlord' : 'Boarder') . ' · joined '
      . date('F j, Y', strtotime($user['created_at'])),
    'back' => 'admin/manage_users.php' . ($removed ? '?view=archived' : ''),
    'backLabel' => 'Back to Manage Users',
    'lead' => avatar_html($user, 44),
    'actions' => $pageActions,
    'tabs' => [
      ['id' => 'panel-overview', 'label' => 'Overview'],
      $isLandlord
        ? ['id' => 'panel-things', 'label' => 'Listings', 'count' => count($listings)]
        : ['id' => 'panel-things', 'label' => 'Saved', 'count' => count($saved)],
      ['id' => 'panel-history', 'label' => 'History', 'count' => count($history)],
    ],
  ]);
  ?>

  <section class="content">
    <div class="container-fluid">
      <div class="row re-tabpanel" id="panel-overview" role="tabpanel" aria-labelledby="tab-panel-overview">
        <div class="col-lg-5">
          <div class="card shadow-sm">
            <div class="card-header"><h3 class="card-title">Account</h3>
              <span class="card-subtitle">How this person signs in, and what you can do about it.</span></div>
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
              <div class="re-fields">
                <?= re_field('Email', '<a href="mailto:' . h($user['email']) . '">' . h($user['email']) . '</a>', true) ?>
                <?= re_field('Phone', $user['phone_number'] ?: 'Not given') ?>
                <?= re_field('Signs in with', $user['google_id'] ? 'Google, and a password if one was set' : 'Email and password') ?>
                <?= re_field('Remembered devices', '<span class="tabular">' . $devices . '</span>', true) ?>
                <?= re_field('Failed sign-ins, last 24h', '<span class="tabular">' . $failedLogins . '</span>', true) ?>
              </div>

            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card shadow-sm">
            <?php panel_card_header(
              'At a glance',
              $isLandlord
                ? 'What this landlord has on RoomEase right now.'
                : 'What this boarder has done on RoomEase so far.'
            ); ?>
            <div class="card-body">
              <div class="re-fields">
                <?php if ($isLandlord): ?>
                  <?php
                  $live = 0;
                  $waiting = 0;
                  foreach ($listings as $l) {
                      if ($l['deleted_at'] !== null) {
                          continue;
                      }
                      if ($l['moderation_status'] === 'approved') {
                          $live++;
                      } elseif ($l['moderation_status'] === 'pending') {
                          $waiting++;
                      }
                  }
                  ?>
                  <?= re_field('Listings posted', '<span class="tabular">' . count($listings) . '</span>', true) ?>
                  <?= re_field('Approved', '<span class="tabular">' . $live . '</span>', true) ?>
                  <?= re_field('Waiting on approval', '<span class="tabular">' . $waiting . '</span>', true) ?>
                <?php else: ?>
                  <?= re_field('Listings saved', '<span class="tabular">' . count($saved) . '</span>', true) ?>
                <?php endif; ?>
                <?= re_field('Administrator changes', '<span class="tabular">' . count($history) . '</span>', true) ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="re-tabpanel" id="panel-things" role="tabpanel" aria-labelledby="tab-panel-things" hidden>
          <?php if ($isLandlord): ?>
            <div class="card shadow-sm">
              <div class="card-header">
                <h3 class="card-title">Listings <span class="text-muted font-weight-normal">(<?= count($listings) ?>)</span></h3>
              <span class="card-subtitle">Everything this landlord has posted, removed ones included.</span>
              </div>
              <div class="card-body p-0 table-responsive">
                <?php if (!$listings): ?>
                  <?= re_empty('No listings yet', 'This landlord has not posted a boarding house.', 'fa-home') ?>
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
                <h3 class="card-title">Saved listings <span class="text-muted font-weight-normal">(<?= count($saved) ?>)</span></h3>
              <span class="card-subtitle">Boarding houses this boarder shortlisted.</span>
              </div>
              <div class="card-body p-0 table-responsive">
                <?php if (!$saved): ?>
                  <?= re_empty('Nothing saved yet', 'This boarder has not shortlisted a boarding house.', 'fa-heart') ?>
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

      <div class="re-tabpanel" id="panel-history" role="tabpanel" aria-labelledby="tab-panel-history" hidden>
        <div class="card shadow-sm">
          <?php panel_card_header('History', 'Changes an administrator made to this account.'); ?>
          <div class="card-body<?= $history ? '' : ' p-0' ?>">
            <?php if (!$history): ?>
              <?= re_empty(
                'Nothing recorded',
                'No administrator has changed this account since the activity log started.',
                'fa-history'
              ) ?>
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
    </div>
  </section>
</div>

<?php /* The confirmation listener now lives in includes/scripts/panel_tabs.php,
     which the footer loads on every panel page. */ ?>
<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
