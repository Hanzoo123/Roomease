<?php
/**
 * My Profile: the account as it stands, with the way to change it.
 *
 * Nothing is edited here. Edit Profile (auth/edit_profile.php) changes the
 * photo and details, and Change Password (auth/change_password.php) the
 * password, each on its own page, and both come back here once saved.
 */
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/core/google_auth.php';

require_login();

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
  die('User not found.');
}

$googleLinked = !empty($user['google_id']);
$role = $user['role'];
$roleLabels = ['administrator' => 'Administrator', 'landlord' => 'Landlord', 'boarder' => 'Boarder'];
// A new administrator has only a username until they add their name.
$fullName = account_display_name($user);
$user['full_name'] = $fullName;

// What the account has on RoomEase, so the page is worth opening for more
// than a copy of the form. Administrators have neither.
$activity = null;
if ($role === 'boarder') {
  $saved = count(saved_listing_ids($userId));
  $activity = [
    'label' => 'Saved rooms',
    'value' => $saved === 0 ? 'None yet' : (string) $saved,
    'link' => [
      'href' => $saved === 0 ? 'boarder/browse.php' : 'boarder/saved.php',
      'label' => $saved === 0 ? 'Browse rooms' : 'View'
    ],
  ];
} elseif ($role === 'landlord') {
  // "Live" as boarders see it: approved and open, the same test as browse.
  $countStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
                COALESCE(SUM(moderation_status = 'approved' AND availability_status = 'available'), 0) AS live
           FROM boarding_houses
          WHERE landlord_id = ? AND deleted_at IS NULL"
  );
  $countStmt->execute([$userId]);
  $counts = $countStmt->fetch();
  $total = (int) $counts['total'];
  $activity = [
    'label' => 'Listings',
    'value' => $total === 0 ? 'None yet' : $total . ', ' . (int) $counts['live'] . ' live',
    'link' => [
      'href' => $total === 0 ? 'landlord/add_listing.php' : 'landlord/listings.php',
      'label' => $total === 0 ? 'Add a listing' : 'Manage'
    ],
  ];
}

$pageTitle = 'My Profile';

require __DIR__ . '/../includes/layouts/profile_top.php';

$roleBadge = $usePanel
  ? ['administrator' => 'badge badge-primary', 'landlord' => 'badge badge-info', 'boarder' => 'badge badge-secondary'][$role]
  : 'profile-role';
?>

<!-- my profile account settings -->
<div class="<?= $usePanel ? 'card profile-card' : 'panel on-seam profile-card' ?>">
  <?php if ($usePanel): ?>
    <div class="card-header">
      <h3 class="card-title">Profile</h3>
    </div>
  <?php endif; ?>

  <div class="profile-head">
    <div class="profile-avatar"><?= avatar_html($user, 112, $cls['avatar']) ?></div>
    <div class="profile-id">
      <h2 class="profile-name"><?= h($fullName) ?></h2>
      <p class="profile-email"><?= (string) $user['email'] !== '' ? h($user['email']) : 'No email yet' ?></p>
      <span class="<?= $roleBadge ?>"><?= h($roleLabels[$role] ?? ucfirst($role)) ?></span>
    </div>
    <div class="profile-actions">
      <a href="<?= base_url('auth/edit_profile.php') ?>" class="<?= $cls['btn'] ?>">Edit profile</a>
      <a href="<?= base_url('auth/change_password.php') ?>" class="<?= $cls['btn_quiet'] ?>">Change password</a>
    </div>
  </div>

  <dl class="profile-facts">
    <div>
      <dt>Phone</dt>
      <dd>
        <?php if (($user['phone_number'] ?? '') !== ''): ?>
          <?= h($user['phone_number']) ?>
        <?php else: ?>
          <span class="profile-empty">Not added</span>
          <a href="<?= base_url('auth/edit_profile.php') ?>#phone_number" class="profile-fact-link">Add &rarr;</a>
        <?php endif; ?>
      </dd>
    </div>
    <div>
      <dt>Member since</dt>
      <dd><?= h(date('F Y', strtotime($user['created_at']))) ?></dd>
    </div>
    <div>
      <dt>Signs in with</dt>
      <dd class="profile-signin">
        <?php if ($googleLinked): ?>
          <?= google_logo_svg(16) ?> Google
        <?php elseif ((string) ($user['username'] ?? '') !== ''): ?>
          Username <strong>@<?= h($user['username']) ?></strong> or email, and password
        <?php else: ?>
          Email and password
        <?php endif; ?>
      </dd>
    </div>
    <?php if ($activity): ?>
      <div>
        <dt><?= h($activity['label']) ?></dt>
        <dd>
          <?= h($activity['value']) ?>
          <a href="<?= base_url($activity['link']['href']) ?>"
            class="profile-fact-link"><?= h($activity['link']['label']) ?> &rarr;</a>
        </dd>
      </div>
    <?php endif; ?>
  </dl>
</div>

<?php require __DIR__ . '/../includes/layouts/profile_bottom.php'; ?>