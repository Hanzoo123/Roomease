<?php
/**
 * <head> tags for every page: icons, description, and link previews (how a
 * link looks when shared on Messenger or Facebook).
 *
 * Optional: $metaDescription, $ogImage (preview picture), $ogType,
 * $metaSocial (false = no preview tags, e.g. for the panel).
 */
$metaDescription = $metaDescription ?? '';
$metaSocial = $metaSocial ?? true;
$ogType = $ogType ?? 'website';
$ogImage = $ogImage ?? absolute_url('assets/img/og-default.png');
?>
<?php /* ?v= makes browsers fetch the icon again when the file changes. */ ?>
<link rel="icon" href="<?= base_url('assets/img/favicon-32.png') ?>?v=<?= @filemtime(__DIR__ . '/../../assets/img/favicon-32.png') ?: 0 ?>" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="<?= base_url('assets/img/apple-touch-icon.png') ?>?v=<?= @filemtime(__DIR__ . '/../../assets/img/apple-touch-icon.png') ?: 0 ?>">
<?php if ($metaDescription !== ''): ?>
  <meta name="description" content="<?= h($metaDescription) ?>">
<?php endif; ?>
<?php if ($metaSocial): ?>
  <meta property="og:site_name" content="RoomEase">
  <meta property="og:type" content="<?= h($ogType) ?>">
  <meta property="og:title" content="<?= h($pageTitle ?? 'RoomEase') ?>">
  <meta property="og:url" content="<?= h(current_url()) ?>">
  <meta property="og:image" content="<?= h($ogImage) ?>">
  <?php if ($metaDescription !== ''): ?>
    <meta property="og:description" content="<?= h($metaDescription) ?>">
  <?php endif; ?>
  <?php /* Without this the picture arrives as a thumbnail beside the text. */ ?>
  <meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
