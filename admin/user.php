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
  // Every listing, removed ones included, with its room figures and its
  // cover photo: the Portfolio card puts the newest one's photo beside the
  // figures, the way the reference puts the room beside its booking.
  $listStmt = $pdo->prepare(
    'SELECT bh.*, ' . ROOM_SUMMARY_COLUMNS . ', ' . COVER_PHOTO_SELECT . '
       FROM boarding_houses bh
       ' . room_summary_join() . '
      WHERE bh.landlord_id = ?
      ORDER BY bh.deleted_at IS NOT NULL, bh.created_at DESC'
  );
  $listStmt->execute([$userId]);
  $listings = $listStmt->fetchAll();
} else {
  $savedStmt = $pdo->prepare(
    'SELECT bh.boarding_house_id, bh.name, bh.address, bh.moderation_status, bh.deleted_at,
            f.created_at AS saved_at, ' . COVER_PHOTO_SELECT . '
       FROM favorites f
       JOIN boarding_houses bh ON bh.boarding_house_id = f.boarding_house_id
      WHERE f.user_id = ?
      ORDER BY f.created_at DESC'
  );
  $savedStmt->execute([$userId]);
  $saved = $savedStmt->fetchAll();
}

// The portfolio figures, counted once here rather than in the markup.
$byStatus = ['approved' => 0, 'pending' => 0, 'rejected' => 0, 'removed' => 0];
$roomTotal = 0;
$roomsOpen = 0;
foreach ($listings as $l) {
  if ($l['deleted_at'] !== null) {
    $byStatus['removed']++;
    continue;
  }
  if (isset($byStatus[$l['moderation_status']])) {
    $byStatus[$l['moderation_status']]++;
  }
  $roomTotal += (int) $l['room_count'];
  $roomsOpen += (int) $l['rooms_available'];
}

// The picture for the Portfolio card: the newest listing that still has one.
$showcase = null;
foreach ($listings as $l) {
  if (!empty($l['cover_photo'])) {
    $showcase = $l;
    break;
  }
}
if ($showcase === null && $listings) {
  $showcase = $listings[0];
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
$notes = account_notes($userId);
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

  // The heading names the kind of page and the line under it names the person,
  // rather than the other way round. The name has to be here as well as in the
  // Profile card: on the Listings and History tabs that card is not on screen,
  // and a list of listings with no indication whose they are is a page you can
  // misread.
  panel_page_header($isLandlord ? 'Landlord Profile' : 'Boarder Profile', [
    'subtitle' => $fullName,
    'back' => 'admin/manage_users.php' . ($removed ? '?view=archived' : ''),
    'backLabel' => 'Back to Manage Users',
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
      <div class="re-tabpanel" id="panel-overview" role="tabpanel" aria-labelledby="tab-panel-overview">
        <div class="row">

          <!-- Who they are -->
          <div class="col-lg-5">
            <div class="card shadow-sm">
              <?php panel_card_header('Profile', 'Who this account belongs to, and how to reach them.'); ?>
              <div class="card-body">
                <div class="re-profile-head">
                  <?= avatar_html($user, 56) ?>
                  <div style="min-width: 0;">
                    <h4 class="re-profile-name">
                      <?= h($fullName) ?>
                      <?php if ($removed): ?>
                        <span class="badge badge-dark"><i class="fas fa-archive mr-1"></i> Removed</span>
                      <?php elseif ($user['is_active']): ?>
                        <span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i> Active</span>
                      <?php else: ?>
                        <span class="badge badge-danger"><i class="fas fa-ban mr-1"></i> Deactivated</span>
                      <?php endif; ?>
                    </h4>
                    <p class="re-profile-meta">
                      <?= $isLandlord ? 'Landlord' : 'Boarder' ?> &middot; account #<?= (int) $userId ?>
                      <?php if ($removed): ?>
                        &middot; removed <?= h(date('M j, Y', strtotime($user['deleted_at']))) ?>
                      <?php endif; ?>
                    </p>
                  </div>
                </div>

                <div class="re-section">
                  <span class="re-section-label">Contact information</span>
                  <?php
                  // The phone is a tel: link so it can be dialled from a laptop
                  // or a phone without being retyped.
                  $dial = preg_replace('/[^0-9+]/', '', (string) $user['phone_number']);
                  ?>
                  <?php if ($dial !== ''): ?>
                    <a class="re-contact" href="tel:<?= h($dial) ?>">
                      <span class="re-contact-icon"><i class="fas fa-phone" aria-hidden="true"></i></span>
                      <span class="re-contact-value"><?= h($user['phone_number']) ?></span>
                    </a>
                  <?php else: ?>
                    <span class="re-contact re-contact--muted">
                      <span class="re-contact-icon"><i class="fas fa-phone" aria-hidden="true"></i></span>
                      <span class="re-contact-value">No phone number given</span>
                    </span>
                  <?php endif; ?>
                  <a class="re-contact" href="mailto:<?= h($user['email']) ?>">
                    <span class="re-contact-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span>
                    <span class="re-contact-value"><?= h($user['email']) ?></span>
                  </a>
                </div>

                <div class="re-section">
                  <span class="re-section-label">Account</span>
                  <div class="re-fields re-fields--tight">
                    <?= re_field('Joined', date('M j, Y', strtotime($user['created_at']))) ?>
                    <?= re_field('Signs in with', $user['google_id'] ? 'Google' : 'Email and password') ?>
        
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- What they have here -->
          <div class="col-lg-7">
            <div class="card shadow-sm">
              <?php panel_card_header(
                $isLandlord ? 'Portfolio' : 'Shortlist',
                $isLandlord
                  ? 'What this landlord has on RoomEase, and where each listing stands.'
                  : 'The boarding houses this boarder has saved for later.',
                '<span class="re-count-pill">' . ($isLandlord ? count($listings) : count($saved)) . ' '
                  . ($isLandlord
                      ? (count($listings) === 1 ? 'listing' : 'listings')
                      : (count($saved) === 1 ? 'saved' : 'saved')) . '</span>'
              ); ?>
              <div class="card-body">
                <?php if ($isLandlord): ?>
                  <div class="d-flex flex-wrap" style="gap: 20px;">
                    <div class="flex-grow-1" style="min-width: 210px;">
                      <div class="re-fields re-fields--tight">
                        <?= re_field('On the site', '<span class="tabular">' . $byStatus['approved'] . '</span>', true) ?>
                        <?= re_field('Rooms', '<span class="tabular">' . $roomTotal . '</span>', true) ?>
                        <?= re_field('Rooms free', '<span class="tabular">' . $roomsOpen . '</span>', true) ?>
                      </div>

                      <div class="re-section">
                        <span class="re-section-label">By approval</span>
                        <div class="re-breakdown">
                          <?php foreach ([
                            'approved' => 'Approved',
                            'pending'  => 'Waiting on approval',
                            'rejected' => 'Rejected',
                            'removed'  => 'Removed by an administrator',
                          ] as $key => $label): ?>
                            <div class="re-breakdown-row">
                              <span><span class="re-dot re-dot--<?= $key ?>" aria-hidden="true"></span><?= h($label) ?></span>
                              <span class="re-breakdown-value"><?= $byStatus[$key] ?></span>
                            </div>
                          <?php endforeach; ?>
                          <div class="re-breakdown-row re-breakdown-total">
                            <span>Total posted</span>
                            <span class="re-breakdown-value"><?= count($listings) ?></span>
                          </div>
                        </div>
                      </div>
                    </div>

                    <?php if ($showcase !== null): ?>
                      <div style="flex: 0 0 auto;">
                        <?php if (!empty($showcase['cover_photo'])): ?>
                          <img class="re-media" src="<?= h(base_url($showcase['cover_photo'])) ?>" alt="" loading="lazy">
                        <?php else: ?>
                          <span class="re-media re-media--empty" aria-hidden="true"><i class="fas fa-camera"></i></span>
                        <?php endif; ?>
                        <p class="re-profile-meta" style="max-width: 220px;">
                          <?= h($showcase['name']) ?>
                        </p>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <?php if (!$saved): ?>
                    <?= re_empty('Nothing saved yet', 'This boarder has not shortlisted a boarding house.', 'fa-heart') ?>
                  <?php else: ?>
                    <div class="d-flex flex-wrap" style="gap: 20px;">
                      <div class="flex-grow-1" style="min-width: 210px;">
                        <div class="re-fields re-fields--tight">
                          <?= re_field('Listings saved', '<span class="tabular">' . count($saved) . '</span>', true) ?>
                          <?= re_field('Most recent', date('M j, Y', strtotime($saved[0]['saved_at']))) ?>
                        </div>
                      </div>
                      <div style="flex: 0 0 auto;">
                        <?php if (!empty($saved[0]['cover_photo'])): ?>
                          <img class="re-media" src="<?= h(base_url($saved[0]['cover_photo'])) ?>" alt="" loading="lazy">
                        <?php else: ?>
                          <span class="re-media re-media--empty" aria-hidden="true"><i class="fas fa-camera"></i></span>
                        <?php endif; ?>
                        <p class="re-profile-meta" style="max-width: 220px;"><?= h($saved[0]['name']) ?></p>
                      </div>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="row">
          <!-- A few of the things, with the full list a tab away -->
          <div class="col-lg-7">
            <div class="card shadow-sm">
              <?php
              $recent = $isLandlord ? array_slice($listings, 0, 4) : array_slice($saved, 0, 4);
              $allCount = $isLandlord ? count($listings) : count($saved);
              panel_card_header(
                $isLandlord ? 'Recent listings' : 'Recently saved',
                $isLandlord
                  ? 'The newest first. The full list is under the Listings tab.'
                  : 'The newest first. The full list is under the Saved tab.',
                $allCount > count($recent)
                  ? '<a href="#panel-things" class="btn btn-tool">View all ' . $allCount . '</a>'
                  : ''
              );
              ?>
              <div class="card-body p-0">
                <?php if (!$recent): ?>
                  <?php /* Worded differently from the card above it, which is
                       empty at the same time and would otherwise say the same
                       sentence twice on one screen. */ ?>
                  <?= $isLandlord
                    ? re_empty('Nothing to show', 'Boarding houses appear here as this landlord posts them.', 'fa-home')
                    : re_empty('Nothing to show', 'Boarding houses appear here as this boarder saves them.', 'fa-heart') ?>
                <?php else: ?>
                  <table class="table mb-0">
                    <tbody>
                      <?php foreach ($recent as $r): ?>
                        <tr>
                          <td>
                            <span class="d-flex align-items-center" style="gap: 10px;">
                              <?= listing_thumb_html($r) ?>
                              <span style="min-width: 0;">
                                <a class="font-weight-bold" href="<?= base_url('admin/listing.php?id=' . (int) $r['boarding_house_id']) ?>"><?= h($r['name']) ?></a>
                                <small class="text-muted d-block"><?= h($r['address'] ?? '') ?></small>
                              </span>
                            </span>
                          </td>
                          <td class="text-right" style="width: 140px;">
                            <?php if ($r['deleted_at'] !== null): ?>
                              <span class="badge badge-dark">Removed</span>
                            <?php else: ?>
                              <?= moderation_badge($r['moderation_status']) ?>
                            <?php endif; ?>
                            <small class="text-muted d-block mt-1">
                              <?= h(date('M j, Y', strtotime($isLandlord ? $r['created_at'] : $r['saved_at']))) ?>
                            </small>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- What administrators have noticed about this account -->
          <div class="col-lg-5">
            <div class="card shadow-sm">
              <?php panel_card_header(
                'Notes',
                'Only administrators see these. ' . ($isLandlord ? 'The landlord' : 'The boarder') . ' never does.',
                $notes ? '<span class="re-count-pill">' . count($notes) . '</span>' : ''
              ); ?>
              <div class="card-body">
                <form method="post" action="<?= base_url('admin/user_action.php') ?>" class="re-note-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
                  <input type="hidden" name="action" value="add_note">
                  <label class="sr-only" for="note-body">Write a note about this account</label>
                  <textarea class="form-control" id="note-body" name="body" rows="3"
                    maxlength="<?= ACCOUNT_NOTE_MAX ?>" required
                    placeholder="e.g. Asked to re-upload clearer photos, says they will by Friday."></textarea>
                  <div class="text-right mt-2">
                    <button type="submit" class="btn btn-sm btn-primary">
                      <i class="fas fa-plus mr-1"></i> Add note
                    </button>
                  </div>
                </form>

                <?php if (!$notes): ?>
                  <div class="re-section">
                    <p class="text-muted mb-0" style="font-size: .845rem;">
                      No notes yet. Anything written here stays with the account for whoever picks it up next.
                    </p>
                  </div>
                <?php else: ?>
                  <?php foreach ($notes as $n): ?>
                    <div class="re-note">
                      <?= avatar_html($n, 32) ?>
                      <div style="min-width: 0; flex: 1 1 auto;">
                        <div class="re-note-head">
                          <span class="re-note-author">
                            <?= $n['admin_name'] !== null ? h($n['admin_name']) : 'An administrator' ?>
                          </span>
                          <span class="re-note-when"><?= h(time_ago($n['created_at'])) ?></span>
                        </div>
                        <p class="re-note-body"><?= h($n['body']) ?></p>
                      </div>
                      <?php if ((int) $n['admin_id'] === (int) $_SESSION['user_id']): ?>
                        <?php /* Only the author can delete a note: someone who
                             disagrees should add their own rather than quietly
                             remove the first. */ ?>
                        <form method="post" action="<?= base_url('admin/user_action.php') ?>" class="js-confirm"
                          data-confirm="Delete this note? It cannot be undone.">
                          <?= csrf_field() ?>
                          <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
                          <input type="hidden" name="note_id" value="<?= (int) $n['note_id'] ?>">
                          <input type="hidden" name="action" value="delete_note">
                          <button type="submit" class="re-note-delete" title="Delete this note"
                            aria-label="Delete this note">
                            <i class="fas fa-times" aria-hidden="true"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
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
