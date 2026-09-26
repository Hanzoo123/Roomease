<?php
/**
 * Contact RoomEase: the address to write to, and a form that sends there.
 *
 * The address comes from ROOMEASE_CONTACT_EMAIL in .env. Without it the page
 * still explains how to reach the team, but the form is not offered: a form
 * that silently goes nowhere is worse than no form.
 *
 * Messages are sent through the same Gmail account as the password reset
 * codes. The sender's own address is written into the body rather than into a
 * Reply-To header, because a header built from a form field is how mail
 * injection gets in.
 */
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/core/functions.php';

$contactEmail = trim(env_value('ROOMEASE_CONTACT_EMAIL'));
$canSend = $contactEmail !== '' && mail_enabled();

$errors = [];
$old = ['name' => '', 'email' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canSend) {
    verify_csrf();

    $old['name'] = trim($_POST['name'] ?? '');
    $old['email'] = trim($_POST['email'] ?? '');
    $old['message'] = trim($_POST['message'] ?? '');

    // A field kept out of sight by CSS. A person never fills it in; the scripts
    // that post to every form they find fill in everything they see.
    $trap = trim($_POST['website'] ?? '');

    if ($old['name'] === '') {
        $errors[] = 'Please tell us your name.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please give an email address we can reply to.';
    }
    if (mb_strlen($old['message']) < 10) {
        $errors[] = 'Please write a little more, so we know how to help.';
    }

    // Counted the same way as sign-ins and reset requests, so one address
    // cannot post all afternoon.
    $retryAfter = $errors ? 0 : throttle_retry_after('contact', $old['email']);
    if ($retryAfter > 0) {
        $errors[] = 'You have sent several messages already. Please try again in ' . format_wait($retryAfter) . '.';
    }

    if (!$errors) {
        if ($trap !== '') {
            // Nothing is sent, and nothing says why.
            flash_set('Thank you. Your message has been sent.', 'success');
            redirect('legal/contact.php');
        }

        record_failed_attempt('contact', $old['email']);

        $body = "A message from the RoomEase contact page.\n\n"
            . 'From: ' . $old['name'] . ' <' . $old['email'] . ">\n"
            . 'Sent: ' . date('j F Y, g:i a') . "\n\n"
            . $old['message'] . "\n";

        if (send_mail($contactEmail, 'RoomEase: message from ' . $old['name'], $body)) {
            flash_set('Thank you. Your message has been sent, and we will reply to ' . $old['email'] . '.', 'success');
            redirect('legal/contact.php');
        }

        $errors[] = 'The message could not be sent just now. Please write to '
            . $contactEmail . ' instead, and we will answer there.';
    }
}

$pageTitle = 'Contact';
$metaDescription = 'Reach the RoomEase team about a listing that needs attention, '
  . 'a question about an account, or anything else about the site.';
$band = [
  'title' => 'Contact RoomEase',
  'lede' => 'A listing that is no longer accurate, a question about your account, or anything else.',
];
require __DIR__ . '/../includes/layouts/header.php';
?>

<article class="legal panel panel-pad on-seam">
  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $e)
        echo h($e) . '<br>'; ?>
    </div>
  <?php endif; ?>

  <p>
    RoomEase is run by a small team in Baybay City. We answer messages ourselves, usually within a
    few days. For anything urgent about a room, the landlord's number is on the listing itself and
    will always be faster than we are.
  </p>

  <?php if ($contactEmail !== ''): ?>
    <p>
      Write to <a href="mailto:<?= h($contactEmail) ?>"><?= h($contactEmail) ?></a>,
      <?= $canSend ? 'or use the form below.' : 'and we will answer there.' ?>
    </p>
  <?php endif; ?>

  <h2>What to tell us</h2>
  <ul>
    <li><strong>A listing that is wrong or gone.</strong> Send the link, and what is out of date.</li>
    <li><strong>An account problem.</strong> The email address on the account is enough; never send
      your password to us, and we will never ask for it.</li>
    <li><strong>A landlord who wants to list.</strong> You can sign up yourself, and we will review
      the listing within a few days.</li>
  </ul>

  <?php if ($canSend): ?>
    <h2>Send a message</h2>
    <form method="post" novalidate>
      <?= csrf_field() ?>

      <div class="field-row">
        <div>
          <label for="name">Your name</label>
          <input type="text" id="name" name="name" value="<?= h($old['name']) ?>" required>
        </div>
        <div>
          <label for="email">Your email</label>
          <input type="email" id="email" name="email" value="<?= h($old['email']) ?>"
            autocomplete="email" required>
        </div>
      </div>

      <label for="message">Message</label>
      <textarea id="message" name="message" rows="6" required><?= h($old['message']) ?></textarea>

      <?php /* The trap: off-screen rather than display:none, which some scripts
           check for, and never announced to a screen reader. */ ?>
      <div style="position:absolute; left:-9999px;" aria-hidden="true">
        <label for="website">Leave this empty</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <button type="submit" class="btn btn-accent" style="margin-top:12px;">Send message</button>
    </form>
  <?php else: ?>
    <p class="legal-updated">
      <?php if ($contactEmail === ''): ?>
        The message form is off because this copy of RoomEase has no contact address set
        (<code>ROOMEASE_CONTACT_EMAIL</code> in <code>.env</code>).
      <?php else: ?>
        The message form is off because email is not set up on this server yet.
      <?php endif; ?>
    </p>
  <?php endif; ?>
</article>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
