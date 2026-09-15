<?php
/**
 * Terms & Conditions, in plain language. Linked from the sign-in and sign-up
 * pages ("By continuing, you agree to...") and from the site footer.
 *
 * A draft written to match what RoomEase actually does. Review it, and change
 * the date below whenever the wording changes.
 */
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Terms & Conditions';
$band = [
  'title' => 'Terms & Conditions',
  'lede' => 'The rules for using RoomEase, in plain language.',
];
require __DIR__ . '/includes/header.php';
?>

<article class="legal panel panel-pad on-seam">
  <p class="legal-updated">Last updated September 15, 2026</p>

  <p>
    RoomEase is a web-based boarding house information and listing system for Baybay City, Leyte. It helps
    boarders find rooms and landlords list them. By creating an account or signing in, including with Google,
    you agree to these terms and to our <a href="<?= base_url('privacy.php') ?>">Privacy Policy</a>.
  </p>

  <h2>What RoomEase does, and does not do</h2>
  <ul>
    <li>RoomEase shows boarding houses, their rooms, rent, and how to contact the landlord.</li>
    <li>It does not rent out rooms, take reservations, or handle payments. Any reservation fee, deposit, or rent
      is agreed and paid between you and the landlord directly.</li>
    <li>RoomEase is not a party to any agreement between a boarder and a landlord.</li>
  </ul>

  <h2>Your account</h2>
  <ul>
    <li>Give accurate details and keep them up to date.</li>
    <li>Keep your password, and your Google account if you sign in with it, to yourself. You are responsible for
      what happens on your account.</li>
    <li>Use your own account only, with the role that fits you: boarder or landlord.</li>
  </ul>

  <h2>Landlords and listings</h2>
  <ul>
    <li>Only list boarding houses in Baybay City that you own or are allowed to rent out.</li>
    <li>Keep rent, rooms, slots taken, photos, and contact details accurate and current.</li>
    <li>Only upload photos of the actual property that you have the right to use.</li>
    <li>An administrator reviews each new listing before boarders can see it, and may reject or remove a listing
      that breaks these terms.</li>
  </ul>

  <h2>Boarders</h2>
  <ul>
    <li>Visit the room and confirm the terms with the landlord before paying anything.</li>
    <li>Use a landlord's contact details only to ask about their listing.</li>
  </ul>

  <h2>Not allowed</h2>
  <ul>
    <li>False or misleading listings, fake accounts, or pretending to be someone else.</li>
    <li>Harassment, spam, or collecting other people's details from the site.</li>
    <li>Trying to break into, overload, or get around the security of RoomEase.</li>
  </ul>

  <h2>When the rules are broken</h2>
  <p>
    An administrator may reject or remove listings, and deactivate or remove accounts, that break these terms.
    A removed account is archived rather than erased straight away.
  </p>

  <h2>No guarantees</h2>
  <ul>
    <li>Landlords write their own listings. RoomEase reviews them, but cannot promise every detail is correct or
      that a room is still free.</li>
    <li>The site is provided as it is and may sometimes be unavailable, for example during maintenance. Google
      sign-in and maps need an internet connection.</li>
    <li>RoomEase is not responsible for losses that come from dealings between boarders and landlords.</li>
  </ul>

  <h2>Changes to these terms</h2>
  <p>
    These terms may be updated. The date at the top shows when they last changed, and using RoomEase after that
    means you accept the new version.
  </p>

  <h2>Questions</h2>
  <p>If anything here is unclear, contact the RoomEase administrator.</p>
</article>

<?php require __DIR__ . '/includes/footer.php'; ?>
