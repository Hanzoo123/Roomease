<?php
/**
 * The icons every page carries, plus the description and link-preview tags
 * for pages a visitor might share. Included from inside <head> by all three
 * layouts, so a new page gets the icons without asking for them.
 *
 * Without these tags a link pasted into Messenger or Facebook shows a bare
 * address; with them it shows the page's name, a sentence, and a picture.
 *
 * Expects (all optional):
 *   $pageTitle        already set by the layout
 *   $metaDescription  one sentence, for search results and link previews
 *   $ogImage          absolute URL of the preview picture; the RoomEase card
 *                     is used when the page has no picture of its own
 *   $ogType           'website' (default) or 'article'
 *   $metaSocial       false on pages nobody shares (the panel, sign-in), where
 *                     a preview would be pointless
 */
$metaDescription = $metaDescription ?? '';
$metaSocial = $metaSocial ?? true;
$ogType = $ogType ?? 'website';
$ogImage = $ogImage ?? absolute_url('assets/img/og-default.png');
?>
<link rel="icon" href="<?= base_url('assets/img/favicon.svg') ?>" type="image/svg+xml">
<link rel="icon" href="<?= base_url('assets/img/favicon-32.png') ?>" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="<?= base_url('assets/img/apple-touch-icon.png') ?>">
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
