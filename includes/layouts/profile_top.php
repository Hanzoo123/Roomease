<?php
/**
 * Layout for My Profile, Edit Profile and Change Password (close with
 * profile_bottom.php). Admins and landlords see it in the panel, boarders on
 * the public site; $cls holds the CSS class names for each.
 *
 * Set first: $pageTitle, $profileSubtitle, optional $profileBack.
 */
$usePanel = is_admin() || current_role() === 'landlord';
$profileBack = $profileBack ?? null;

$cls = $usePanel
  ? [
    'row' => 'form-row',
    'col' => 'col-md-6 form-group',
    'group' => 'form-group',
    'input' => 'form-control',
    'hint' => 'form-text text-muted',
    'alert' => 'alert alert-danger',
    'note' => 'alert alert-light border',
    'btn' => 'btn btn-primary',
    'btn_quiet' => 'btn btn-outline-secondary',
    'btn_small' => 'btn btn-sm btn-outline-secondary',
    'avatar' => 're-avatar'
  ]
  : [
    'row' => 'field-row',
    'col' => '',
    'group' => '',
    'input' => '',
    'hint' => 'field-hint',
    'alert' => 'alert alert-error',
    'note' => 'alert alert-success',
    'btn' => 'btn btn-primary',
    'btn_quiet' => 'btn btn-ghost',
    'btn_small' => 'btn btn-ghost btn-sm',
    'avatar' => 'avatar'
  ];

if ($usePanel) {
  require __DIR__ . '/panel_head.php';
  require __DIR__ . '/panel_navbar.php';
  require __DIR__ . '/panel_sidebar.php';
  ?>
  <div class="content-wrapper">
    <!--myprofile-->
    <?php panel_page_header($pageTitle); ?>
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
