<?php
/**
 * "Page not found". Shown for unknown addresses (see .htaccess).
 * Styles: .notfound in style.css.
 */
require __DIR__ . '/includes/init.php';

// Must be set by hand, or Apache would answer 200.
http_response_code(404);

$pageTitle = 'Page not found';
$metaSocial = false;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#F6F7F5">
  <meta name="robots" content="noindex">
  <title><?= h($pageTitle) ?> · RoomEase</title>
  <?php require __DIR__ . '/includes/components/head_meta.php'; ?>
  <link rel="preload" href="<?= base_url('assets/fonts/bricolage-grotesque-var-latin.woff2') ?>" as="font" type="font/woff2" crossorigin>
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
