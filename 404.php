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
 *
 * It stands alone, without the site header and footer: a lost visitor gets
 * one message and two ways out, nothing else to read. Styles are the
 * .notfound section of style.css.
 */
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/core/functions.php';

http_response_code(404);

$pageTitle = 'Page not found';
$metaSocial = false;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#FAF8F3">
  <meta name="robots" content="noindex">
  <title><?= h($pageTitle) ?> · RoomEase</title>
  <?php require __DIR__ . '/includes/components/head_meta.php'; ?>
  <link rel="preload" href="<?= base_url('assets/fonts/fraunces-soft-var-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet"
    href="<?= base_url('assets/css/style.css') ?>?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?: 0 ?>">
</head>

<body>
  <main class="notfound">
    <div class="notfound-inner">
      <p class="notfound-code">404</p>
      <h1 class="notfound-title">Page not found</h1>
      <p class="notfound-lede">
        The address may be mistyped, or the room it pointed to may have been taken down.
      </p>
      <div class="notfound-actions">
        <a class="btn btn-accent" href="<?= base_url('boarder/browse.php') ?>">Browse rooms</a>
        <a class="notfound-link" href="<?= base_url('index.php') ?>">Go to the home page &rarr;</a>
      </div>
    </div>
  </main>
</body>

</html>
