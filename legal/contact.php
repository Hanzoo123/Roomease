<?php
/**
 * Contact RoomEase: the ways to reach the team, a form that sends to the
 * team's address, and answers to the questions people ask most.
 *
 * The address comes from ROOMEASE_CONTACT_EMAIL in .env. Without it the page
 * still explains how to reach the team, but the form is not offered: a form
 * that silently goes nowhere is worse than no form. The Facebook and
 * Messenger rows likewise appear only when ROOMEASE_FACEBOOK_URL and
 * ROOMEASE_MESSENGER_URL are set.
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

// Only an http(s) address is linked: anything else in .env is a typo, and a
// javascript: link written into the page would be worse than no link.
$socialUrl = function ($key) {
    $url = trim(env_value($key));
    return preg_match('#^https?://#i', $url) ? $url : '';
};
$facebookUrl = $socialUrl('ROOMEASE_FACEBOOK_URL');
$messengerUrl = $socialUrl('ROOMEASE_MESSENGER_URL');

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
    $errors = array_merge($errors, array_filter([
        too_long($old['name'], 100, 'Your name'),
        too_long($old['email'], 150, 'Email'),
        too_long($old['message'], 5000, 'Your message'),
    ]));

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

// Answers checked against what the site does: review is by hand, a listing is
// public only while approved and open, and there is no cap on listings.
$faqs = [
  [
    'q' => 'Is RoomEase free?',
    'a' => 'Yes. Searching, saving rooms and listing a boarding house cost nothing, for boarders and landlords alike.',
  ],
  [
    'q' => 'How long before my listing appears?',
    'a' => 'An administrator reviews every new listing, usually within a few days. Until then it shows as pending '
      . 'in your listings, and boarders cannot see it yet.',
  ],
  [
    'q' => 'Why can boarders not see my listing?',
    'a' => 'A listing is shown only once it has been approved, and only while it is marked available. If it was '
      . 'not approved, the reason is written in your listings; correct it and save, and it goes back for another review.',
  ],
  [
    'q' => 'Can I list more than one boarding house?',
    'a' => 'Yes. Each boarding house is its own listing, with its own rooms, photos and review.',
  ],
  [
    'q' => 'Do I need an account to look at rooms?',
    'a' => 'No. Anyone can browse and open a listing. An account is only needed to save rooms to a shortlist, '
      . 'or to post a boarding house.',
  ],
  [
    'q' => 'Will you ever ask for my password?',
    'a' => 'Never, by email, message or phone. If you have forgotten it, use "Forgot password" on the log-in page '
      . 'and a code is sent to the email address on your account.',
  ],
];

$bleed = true;
require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="band">
  <div class="container">
    <div class="band-head">
      <div>
        <h1 class="band-title">Contact RoomEase</h1>
        <p class="band-lede">A listing that is no longer accurate, a question about your account, or anything else.</p>
      </div>
    </div>
  </div>
</section>

<div class="container seam">
  <section class="panel on-seam channels" aria-labelledby="channels-title">
    <h2 id="channels-title" class="channels-title">Ways to reach us</h2>
    <ul class="channels-list">
      <?php if ($contactEmail !== ''): ?>
        <li>
          <span class="channel-icon"><?= icon('mail', 20) ?></span>
          <div>
            <h3>Email</h3>
            <p>For your account, a listing, or anything about the site. We answer ourselves, usually within a few days.</p>
          </div>
          <a class="arrow-link" href="mailto:<?= h($contactEmail) ?>"><?= h($contactEmail) ?></a>
        </li>
      <?php endif; ?>
      <?php if ($messengerUrl !== ''): ?>
        <li>
          <span class="channel-icon"><?= icon('message', 20) ?></span>
          <div>
            <h3>Messenger</h3>
            <p>A quick question for the team, in a chat.</p>
          </div>
          <a class="arrow-link" href="<?= h($messengerUrl) ?>" rel="noopener" target="_blank">Send a message &rarr;</a>
        </li>
      <?php endif; ?>
      <?php if ($facebookUrl !== ''): ?>
        <li>
          <span class="channel-icon"><?= icon('facebook', 20) ?></span>
          <div>
            <h3>Facebook</h3>
            <p>News about RoomEase and the boarding houses on it.</p>
          </div>
          <a class="arrow-link" href="<?= h($facebookUrl) ?>" rel="noopener" target="_blank">Visit the page &rarr;</a>
        </li>
      <?php endif; ?>
      <li>
        <span class="channel-icon"><?= icon('phone', 20) ?></span>
        <div>
          <h3>A question about a room</h3>
          <p>The landlord's number is on every listing. For the rent, a visit or a free slot, they will answer faster than we can.</p>
        </div>
        <a class="arrow-link" href="<?= base_url('boarder/browse.php') ?>">Browse rooms &rarr;</a>
      </li>
    </ul>
  </section>
</div>

<section class="section section--after-seam">
  <div class="container contact-split">
    <div>
      <h2>What to tell us</h2>
      <ul class="contact-topics">
        <li>
          <span class="channel-icon"><?= icon('home', 18) ?></span>
          <div>
            <h3>A listing that is wrong or gone</h3>
            <p>Send the link, and what is out of date.</p>
          </div>
        </li>
        <li>
          <span class="channel-icon"><?= icon('key', 18) ?></span>
          <div>
            <h3>An account problem</h3>
            <p>The email address on the account is enough. Never send us your password; we will never ask for it.</p>
          </div>
        </li>
        <li>
          <span class="channel-icon"><?= icon('door', 18) ?></span>
          <div>
            <h3>A landlord who wants to list</h3>
            <p>You can <a href="<?= base_url('auth/register.php?role=landlord') ?>">sign up yourself</a>, and we will review the listing within a few days.</p>
          </div>
        </li>
      </ul>
    </div>

    <div class="panel panel-pad contact-form" id="send">
      <h2>Send a message</h2>
      <?php if ($canSend): ?>
        <p class="contact-form-note">It goes straight to the team, and we reply to the address you give.</p>

        <?php if ($errors): ?>
          <div class="alert alert-error">
            <?php foreach ($errors as $e)
              echo h($e) . '<br>'; ?>
          </div>
        <?php endif; ?>

        <?php /* #send brings the visitor back to the form, and to any error on
             it, instead of to the top of a long page. */ ?>
        <form method="post" action="<?= base_url('legal/contact.php') ?>#send" novalidate>
          <?= csrf_field() ?>

          <div class="field-row">
            <div>
              <label for="name">Your name</label>
              <input type="text" id="name" name="name" value="<?= h($old['name']) ?>" autocomplete="name" required>
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

          <button type="submit" class="btn btn-accent contact-submit">Send message</button>
        </form>
      <?php else: ?>
        <p class="contact-form-note">
          <?php if ($contactEmail !== ''): ?>
            The message form is not available right now. Please write to
            <a href="mailto:<?= h($contactEmail) ?>"><?= h($contactEmail) ?></a> instead, and we will answer there.
          <?php else: ?>
            The message form is not available yet. For a question about a room, the landlord's number is on the listing.
          <?php endif; ?>
        </p>
        <?php /* The reason is for whoever runs the site, not for a visitor. */ ?>
        <?php if (is_admin()): ?>
          <p class="contact-admin-note">
            Only administrators see this.
            <?php if ($contactEmail === ''): ?>
              The form is off because <code>ROOMEASE_CONTACT_EMAIL</code> is not set in <code>.env</code>.
            <?php else: ?>
              The form is off because email is not set up on this server yet.
            <?php endif; ?>
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="section section--white">
  <div class="container faq">
    <h2>Questions people ask</h2>
    <div class="faq-list">
      <?php foreach ($faqs as $faq): ?>
        <details>
          <summary><?= h($faq['q']) ?></summary>
          <p><?= h($faq['a']) ?></p>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
