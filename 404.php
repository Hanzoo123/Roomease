<?php
/**
 * The page a wrong address lands on, in RoomEase's own theme.
 *
 * Apache serves this through ErrorDocument in .htaccess, which means it also
 * answers the folders that .htaccess hides (config/, database/, includes/,
 * storage/ and .claude/), so a poke at those looks like an address that was
 * never there rather than a door with a lock on it.
 *
 * The status code has to be set by hand: Apache runs this page as a normal
 * request, and without this it would answer 200 and tell a search engine the
 * missing page exists.
 */
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/core/functions.php';

http_response_code(404);

$pageTitle = 'Page not found';
$metaSocial = false;
$band = [
  'title' => 'This page is not here',
  'lede' => 'The address may be mistyped, or the listing it pointed to may have been taken down.',
];
require __DIR__ . '/includes/layouts/header.php';
?>

<article class="legal panel panel-pad on-seam">
  <p>
    Nothing is wrong with your connection. The page simply does not exist at that address.
    You can start again from the rooms, or go back to the home page.
  </p>

  <p style="display:flex; flex-wrap:wrap; gap:12px; margin-top:20px;">
    <a class="btn btn-accent" href="<?= base_url('boarder/browse.php') ?>">Browse rooms</a>
    <a class="btn btn-ghost" href="<?= base_url('index.php') ?>">Go to the home page</a>
  </p>

  <p class="legal-updated" style="margin-top:24px;">
    If you followed a link from inside RoomEase and it brought you here,
    <a href="<?= base_url('contact.php') ?>">tell us</a> so we can fix it.
  </p>
</article>

<?php require __DIR__ . '/includes/layouts/footer.php'; ?>
