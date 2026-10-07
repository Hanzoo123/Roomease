<?php
/**
 * About RoomEase. Survey figures are from the capstone paper (19 respondents);
 * live figures use the same query as the home page.
 */
require __DIR__ . '/../includes/init.php';

$pageTitle = 'About';
$metaDescription = 'RoomEase is a boarding house listing site for Baybay City, Leyte, '
  . 'where landlords post their rooms and boarders can compare them before visiting.';

$stats = live_listing_stats();

// The team, in the order the capstone paper lists them.
$team = [
  'James Andrew P. Angcay',
  'David Kristoff Corpuz',
  'Christian Peter Dave D. Gatoc',
  'Hanz Christian Lingatong',
  'Ram Tomada',
];

// Each value names something the site actually does, not a slogan.
$values = [
  [
    'title' => 'Checked before it is shown',
    'text' => 'An administrator reviews every new listing before boarders can see it. '
      . 'A listing that turns out to be wrong comes down the same way.',
  ],
  [
    'title' => 'The rent, up front',
    'text' => 'Every room carries its own monthly rent, with the house rules and '
      . 'what is included beside it, so nothing has to be asked before deciding whether to visit.',
  ],
  [
    'title' => 'Slots, counted',
    'text' => 'Landlords record each tenant who moves in or out, so a room shows how many slots are '
      . 'left instead of a photo from last semester.',
  ],
  [
    'title' => 'The landlord, directly',
    'text' => 'Boarders call the landlord on the number in the listing. RoomEase takes no fee and '
      . 'sits in no conversation.',
  ],
];

$bleed = true;
require __DIR__ . '/../includes/layouts/header.php';
?>

<section class="band band--photo">
  <img class="band-photo" src="<?= base_url('assets/img/pages/about-header.webp') ?>" alt="">
  <div class="container">
    <div class="band-head">
      <div> 
        <h1 class="band-title">About RoomEase</h1>
        <p class="band-lede">A boarding house directory for Baybay City, built so a room can be compared before it is visited.</p>
      </div>
    </div>
  </div>
</section>

<section class="section about-story">
  <div class="container about-story-inner">
    <?php /* Two photos laid over each other, with the survey's headline figure
             on a card across them. Atmosphere only, so the photos are passed
             over by screen readers; the figure is read as text. */ ?>
    <div class="about-collage">
      <img class="about-collage-back" src="<?= base_url('assets/img/pages/about-story-1.webp') ?>" alt="" loading="lazy">
      <img class="about-collage-front" src="<?= base_url('assets/img/pages/about-story-2.webp') ?>" alt="" loading="lazy">
      <p class="about-collage-stat">
        <strong>16 of 19</strong>
        <span>had visited or called a boarding house only to find no room free</span>
      </p>
    </div>

    <div class="about-story-text">
      <h2>Why we built it</h2>
      <p>
        Looking for a room in Baybay City usually means scrolling Facebook groups, asking friends,
        or walking street by street. When we surveyed nineteen people who had done it, most had
        found the rent missing from the post, and nearly all of them had made the trip, or the call,
        only to hear the rooms were already taken.
      </p>
      <p>
        RoomEase puts that information in one place: what a room costs, what it includes, where it
        is, whether a slot is free, and how to reach the person who owns it.
      </p>

      <dl class="about-figures">
        <div>
          <dt>11 of 19</dt>
          <dd>found the rent missing or unclear in the post</dd>
        </div>
        <?php if ($stats['rooms_available'] > 0): ?>
          <div>
            <dt><?= $stats['rooms_available'] ?></dt>
            <dd>
              <?= $stats['rooms_available'] === 1 ? 'room' : 'rooms' ?> available on RoomEase right now,
              in <?= $stats['listings'] ?> boarding <?= $stats['listings'] === 1 ? 'house' : 'houses' ?>
            </dd>
          </div>
        <?php endif; ?>
      </dl>
    </div>
  </div>
</section>

<section class="section section--snug about-aims">
  <div class="container">
    <h2>Our mission</h2>
    <p class="about-mission">
      To move the search for a boarding house in Baybay City out of scattered posts and word of
      mouth, into one place where landlords keep their listings current and boarders can see the
      rent, the rooms, the rules and the landlord's number before they make the trip.
    </p>

    <div class="about-aims-cards">
      <div class="about-card">
        <h3>What we stand for</h3>
        <ol class="about-values">
          <?php foreach ($values as $i => $value): ?>
            <li>
              <span class="about-values-no" aria-hidden="true"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
              <div>
                <h4><?= h($value['title']) ?></h4>
                <p><?= h($value['text']) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>

      <div class="about-card">
        <h3>Our vision</h3>
        <blockquote class="about-vision">
          <p>&ldquo;A Baybay City where no one walks to a boarding house only to learn the rooms are gone.&rdquo;</p>
        </blockquote>
        <p class="about-vision-more">Where boarders can compare rooms honestly, and landlords can fill them without the guesswork.</p>
      </div>
    </div>
  </div>
</section>

<section class="section section--white about-team">
  <div class="container">
    <div class="about-team-head">
      <h2>Who built it</h2>
      <p>
        RoomEase is a capstone project by five students of the College of Information Technology,
        for ITE 303: System Analysis and Design.
      </p>
    </div>
    <ul class="about-team-list">
      <?php foreach ($team as $name): ?>
        <?php
        // First and last name, so "David Kristoff Corpuz" is DC.
        $nameWords = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        $initials = mb_strtoupper(mb_substr($nameWords[0], 0, 1) . mb_substr(end($nameWords), 0, 1));
        ?>
        <li>
          <span class="about-team-initials" aria-hidden="true"><?= h($initials) ?></span>
          <span class="about-team-name"><?= h($name) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="section about-cta">
  <div class="container">
    <div class="about-cta-box">
      <img class="about-cta-photo" src="<?= base_url('assets/img/pages/about-cta.webp') ?>" alt="" loading="lazy">
      <div class="about-cta-content">
        <h2>Find a room in Baybay City</h2>
        <p>Search by barangay, room type and budget, and see what is free before you go.</p>
        <a class="btn btn-accent" href="<?= base_url('boarder/browse.php') ?>">Browse rooms</a>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
