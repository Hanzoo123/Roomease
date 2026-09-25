<?php
/**
 * About RoomEase: what it is, who it is for, and who built it.
 *
 * The team names below are placeholders. Replace them with the real names,
 * roles, course and school before the site is shown to anyone.
 */
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/core/functions.php';

$pageTitle = 'About';
$metaDescription = 'RoomEase is a boarding house listing site for Baybay City, Leyte, '
  . 'where landlords post their rooms and boarders can compare them before visiting.';
$band = [
  'title' => 'About RoomEase',
  'lede' => 'A boarding house directory for Baybay City, built so a room can be compared before it is visited.',
];

// Placeholders. Change these to the real names and roles.
$team = [
  ['name' => 'Juan Dela Cruz', 'role' => 'Project leader'],
  ['name' => 'Maria Santos', 'role' => 'Front-end developer'],
  ['name' => 'Jose Ramirez', 'role' => 'Back-end developer'],
  ['name' => 'Ana Villanueva', 'role' => 'Database and testing'],
  ['name' => 'Pedro Alvarez', 'role' => 'Documentation and research'],
];

require __DIR__ . '/includes/layouts/header.php';
?>

<article class="legal panel panel-pad on-seam">
  <p>
    Looking for a room in Baybay City usually means asking around, walking street by street, or
    trusting a photo in a group chat. RoomEase puts the same information in one place: what a room
    costs, what it includes, where it is, and how to reach the person who owns it.
  </p>

  <h2>What it does</h2>
  <ul>
    <li><strong>For boarders.</strong> Search by name, barangay, room type and budget, see photos and
      a map pin, save the rooms worth a second look, and call the landlord directly.</li>
    <li><strong>For landlords.</strong> Post a boarding house, list each room with its own rent and
      capacity, add photos, and keep the details current from one page.</li>
    <li><strong>For the administrators.</strong> Review every listing before boarders see it, keep
      accounts in order, and answer reports about a listing that is no longer accurate.</li>
  </ul>

  <h2>Why listings are checked first</h2>
  <p>
    A new listing is not visible to boarders until an administrator has looked at it. It is a slower
    way to publish, and it is the reason the rooms here can be trusted more than a photo passed
    around a chat group. A listing that turns out to be wrong can be reported, and it is taken down
    the same way.
  </p>

  <h2>Where RoomEase works</h2>
  <p>
    Baybay City, Leyte. It is deliberately a general rental directory rather than a campus service:
    students, workers and families all look for the same thing, and no listing here assumes a
    semester or a school.
  </p>

  <h2>Who built it</h2>
  <p>
    RoomEase was built by five students as a capstone project.
  </p>
  <ul>
    <?php foreach ($team as $member): ?>
      <li><strong><?= h($member['name']) ?></strong> &mdash; <?= h($member['role']) ?></li>
    <?php endforeach; ?>
  </ul>
  <p>
    Questions, corrections, or a listing that needs attention? Write to us on the
    <a href="<?= base_url('contact.php') ?>">contact page</a>.
  </p>
</article>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>
