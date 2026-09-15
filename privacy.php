<?php
/**
 * Privacy Policy, in plain language. Linked from the sign-in and sign-up
 * pages ("By continuing, you agree to...") and from the site footer.
 *
 * A draft written to match what RoomEase actually stores (see
 * database/roomease.sql) and how long it keeps it. If that changes, change
 * this page and the date below with it.
 */
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Privacy Policy';
$band = [
  'title' => 'Privacy Policy',
  'lede' => 'What RoomEase keeps about you, why, and who can see it.',
];
require __DIR__ . '/includes/header.php';
?>

<article class="legal panel panel-pad on-seam">
  <p class="legal-updated">Last updated September 15, 2026</p>

  <p>
    This policy explains the personal information RoomEase keeps and how it is used. RoomEase aims to handle it
    in line with the Data Privacy Act of 2012 (Republic Act No. 10173). Using RoomEase also means agreeing to our
    <a href="<?= base_url('terms.php') ?>">Terms &amp; Conditions</a>.
  </p>

  <h2>What we keep</h2>
  <ul>
    <li><strong>Account details:</strong> your first and last name, email address, phone number if you give one,
      and whether you are a boarder or a landlord.</li>
    <li><strong>Your password,</strong> stored only in scrambled form that cannot be turned back into the password.
      Nobody at RoomEase can read it.</li>
    <li><strong>If you sign in with Google:</strong> the name, email address, and Google account ID that Google
      shares when you continue. RoomEase never receives your Google password.</li>
    <li><strong>Listings, for landlords:</strong> the boarding house name, address, map pin, contact number,
      description, house rules, rooms, rent, and photos.</li>
    <li><strong>Saved listings, for boarders.</strong></li>
    <li><strong>Security records:</strong> failed sign-in and password reset attempts, with the email address and
      IP address used, so password guessing can be stopped.</li>
  </ul>

  <h2>Cookies</h2>
  <ul>
    <li>A session cookie keeps you signed in while you use the site.</li>
    <li>If you tick &ldquo;Remember me&rdquo;, a cookie keeps that device signed in for up to 30 days. Logging out
      or changing your password removes it.</li>
    <li>RoomEase uses no advertising or tracking cookies.</li>
  </ul>

  <h2>How it is used</h2>
  <ul>
    <li>To run your account and sign you in.</li>
    <li>To show approved listings to people looking for a room.</li>
    <li>To email you a link when you ask to reset or set a password.</li>
    <li>To review listings and keep the site secure.</li>
  </ul>
  <p>RoomEase does not sell your information or use it for advertising.</p>

  <h2>Who can see it</h2>
  <ul>
    <li><strong>Anyone visiting RoomEase</strong> can see approved listings, including the landlord's name and the
      listing's address, map pin, and contact number. Signed-in users may also see the landlord's account phone
      number when a listing has no contact number of its own.</li>
    <li><strong>Administrators</strong> can see account details and all listings, to review and manage the site.</li>
    <li><strong>Your saved listings</strong> are not shown to anyone else.</li>
    <li><strong>Google</strong> knows you signed in to RoomEase if you choose to continue with Google. Google's own
      privacy policy applies to that.</li>
    <li><strong>OpenStreetMap</strong> supplies the map images on listing pages, and receives your IP address when
      they load, as any website does when you load its images.</li>
  </ul>

  <h2>How long it is kept</h2>
  <ul>
    <li>Account and listing details are kept while your account exists. A removed account is archived rather than
      erased straight away.</li>
    <li>Password reset links stop working after <?= password_reset_ttl_minutes() ?> minutes.</li>
    <li>Records of sign-in attempts are cleared after about a day.</li>
  </ul>

  <h2>Your choices</h2>
  <ul>
    <li>Change your name, email address, and phone number on your profile at any time.</li>
    <li>Landlords can edit or delete their own listings.</li>
    <li>To have your account removed, or to ask what information RoomEase holds about you, contact the RoomEase
      administrator.</li>
  </ul>

  <h2>Keeping it safe</h2>
  <p>
    Passwords, password reset links, and &ldquo;Remember me&rdquo; cookies are stored only in scrambled form, forms
    are protected against being submitted from other sites, and uploaded photos are checked before they are saved.
  </p>

  <h2>Changes to this policy</h2>
  <p>This policy may be updated. The date at the top shows when it last changed.</p>
</article>

<?php require __DIR__ . '/includes/footer.php'; ?>
