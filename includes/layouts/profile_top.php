<?php
/**
 * The frame around the three account pages: My Profile, Edit Profile and
 * Change Password. Close it with profile_bottom.php.
 *
 * Admins and landlords work inside the management panel, so their account
 * pages render there. Boarders only ever see the public site, so theirs sit
 * on the band like every other public page. The pages themselves are written
 * once; $cls maps each shared role onto the class names of the theme in use.
 *
 * Set these before including this file:
 *
 *   $pageTitle        the tab title, and the page heading
 *   $profileSubtitle  one line under the heading
 *   $profileBack      optional ['href' => path, 'label' => ...]
 */
$usePanel = is_admin() || current_role() === 'landlord';
$profileBack = $profileBack ?? null;

$cls = $usePanel
    ? ['row' => 'form-row', 'col' => 'col-md-6 form-group', 'group' => 'form-group',
       'input' => 'form-control', 'hint' => 'form-text text-muted',
       'alert' => 'alert alert-danger', 'note' => 'alert alert-light border',
       'btn' => 'btn btn-primary', 'btn_quiet' => 'btn btn-outline-secondary',
       'btn_small' => 'btn btn-sm btn-outline-secondary', 'avatar' => 're-avatar']
    : ['row' => 'field-row', 'col' => '', 'group' => '',
       'input' => '', 'hint' => 'field-hint',
       'alert' => 'alert alert-error', 'note' => 'alert alert-success',
       'btn' => 'btn btn-primary', 'btn_quiet' => 'btn btn-ghost',
       'btn_small' => 'btn btn-ghost btn-sm', 'avatar' => 'avatar'];

if ($usePanel) {
    require __DIR__ . '/panel_head.php';
    require __DIR__ . '/panel_navbar.php';
    require __DIR__ . '/panel_sidebar.php';
    ?>
    <div class="content-wrapper">
      <?php panel_page_header($pageTitle, [
        'subtitle' => $profileSubtitle,
        'back' => $profileBack['href'] ?? panel_config()['home'],
        'backLabel' => $profileBack['label'] ?? 'Back to the dashboard',
      ]); ?>
      <section class="content">
        <div class="container-fluid">
          <div class="profile-page">
    <?php
} else {
    $band = ['title' => $pageTitle, 'lede' => $profileSubtitle];
    if ($profileBack) {
        $band['back'] = ['href' => base_url($profileBack['href']), 'label' => $profileBack['label']];
    }
    require __DIR__ . '/header.php';
    ?>
    <div class="profile-page">
    <?php
}
