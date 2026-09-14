<?php
/**
 * One listing as a photo card, shared by the home, browse, and saved pages so
 * the three cannot drift apart.
 *
 * $l needs the boarding_houses columns, cover_photo (COVER_PHOTO_SELECT), and
 * the room figures from ROOM_SUMMARY_COLUMNS / room_summary_join(). $save is
 * null when the viewer cannot save listings; otherwise:
 *
 *   'saved'   bool    whether this listing is already saved
 *   'return'  string  where favorite_action.php sends a no-JavaScript submit
 *   'fields'  array   extra hidden fields, e.g. the browse filters
 *   'drop'    bool    remove the card when it is unsaved (the saved page)
 *
 * The title link is stretched over the whole card, so the card is one link to
 * a screen reader and one big target to a thumb. "View details" is drawn as a
 * button but is part of that same link; the heart sits above it.
 */
require_once __DIR__ . '/icons.php';

function render_listing_card(array $l, ?array $save = null)
{
    $id = (int) $l['boarding_house_id'];
    $avail = listing_availability($l);
    $saved = $save && !empty($save['saved']);
    $types = (string) ($l['room_types'] ?? '');
    ?>
    <article class="room-card room-card--<?= h($avail['key']) ?>">
      <div class="room-card-media">
        <?php if (!empty($l['cover_photo'])): ?>
          <img src="<?= h(base_url($l['cover_photo'])) ?>" alt="" loading="lazy">
        <?php else: ?>
          <?php /* No photo yet: the room types hold the space instead. */ ?>
          <div class="room-card-placeholder">
            <?= icon('home', 40) ?>
            <span><?= h($types !== '' ? explode(', ', $types)[0] : 'Boarding house') ?></span>
          </div>
        <?php endif; ?>

        <span class="pill pill--on-photo <?= h($avail['pill']) ?>"><?= h($avail['label']) ?></span>

        <?php if ($save): ?>
          <form method="post" action="<?= base_url('boarder/favorite_action.php') ?>" class="save-form"
            <?= !empty($save['drop']) ? 'data-drop-on-unsave="1"' : '' ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="boarding_house_id" value="<?= $id ?>">
            <input type="hidden" name="action" value="<?= $saved ? 'unsave' : 'save' ?>">
            <input type="hidden" name="return" value="<?= h($save['return'] ?? 'browse') ?>">
            <?php foreach (($save['fields'] ?? []) as $name => $value): ?>
              <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
            <?php endforeach; ?>
            <button type="submit" class="save-btn <?= $saved ? 'is-saved' : '' ?>"
              title="<?= $saved ? 'Remove from saved' : 'Save this listing' ?>"
              aria-label="<?= $saved ? 'Remove from saved' : 'Save this listing' ?>"
              aria-pressed="<?= $saved ? 'true' : 'false' ?>">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="<?= $saved ? 'currentColor' : 'none' ?>"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
              </svg>
            </button>
          </form>
        <?php endif; ?>
      </div>

      <div class="room-card-body">
        <h3 class="room-card-title">
          <a class="room-card-link" href="<?= base_url('boarder/view_listing.php?id=' . $id) ?>"><?= h($l['name']) ?></a>
        </h3>
        <p class="room-card-addr"><?= icon('pin', 15) ?><span><?= h($l['address']) ?></span></p>

        <?php if ($avail['rent_from'] !== null): ?>
          <p class="room-card-price">
            <?php if ($avail['room_count'] > 1): ?><span class="room-card-from">From</span><?php endif; ?>
            <?= peso_round($avail['rent_from']) ?> <span>/ month</span>
          </p>
        <?php endif; ?>
        <?php if ($types !== ''): ?>
          <p class="room-card-type"><?= h($types) ?></p>
        <?php endif; ?>

        <div class="room-card-foot">
          <span class="room-card-meta"><?= icon('door', 16) ?><?= h($avail['summary']) ?></span>
          <span class="btn btn-primary btn-sm room-card-cta" aria-hidden="true">View details <?= icon('chevron-right', 15) ?></span>
        </div>
      </div>
    </article>
    <?php
}
