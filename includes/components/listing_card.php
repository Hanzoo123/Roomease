<?php
/**
 * One listing as a photo card, shared by the home, browse, and saved pages so
 * the three cannot drift apart.
 *
 * $l needs the boarding_houses columns, cover_photo (COVER_PHOTO_SELECT), and
 * the room figures from ROOM_SUMMARY_COLUMNS / room_summary_join(). Browse
 * adds match_rent, match_count and match_type when a room filter is on, so the
 * card quotes the room that matched rather than the cheapest room of any kind.
 *
 * $save is null when the viewer cannot save listings; otherwise:
 *
 *   'saved'   bool    whether this listing is already saved
 *   'return'  string  where favorite_action.php sends a no-JavaScript submit
 *   'fields'  array   extra hidden fields, e.g. the browse filters
 *   'drop'    bool    remove the card when it is unsaved (the saved page)
 *
 * The title link is stretched over the whole card, so the card is one link to
 * a screen reader and one big target to a thumb. Its accessible name carries
 * the price and what is free, because the picture, the pill and "View details"
 * are all hidden from screen readers; the heart sits above the link.
 */
require_once __DIR__ . '/icons.php';

function render_listing_card(array $l, ?array $save = null)
{
    $id = (int) $l['boarding_house_id'];
    $avail = listing_availability($l);
    $saved = $save && !empty($save['saved']);
    $types = (string) ($l['open_room_types'] ?? $l['room_types'] ?? '');
    $photo = !empty($l['cover_photo']) && photo_on_disk($l['cover_photo']);

    // The rent to quote: the matching room's when browse filtered by room, the
    // listing's cheapest free room otherwise.
    $matchRent = $l['match_rent'] ?? null;
    if ($matchRent !== null) {
        $rent = $matchRent;
        $many = (int) ($l['match_count'] ?? 1) > 1;
        $type = $l['match_type'] ?? '';
        $rentLabel = $type !== '' ? ($many ? $type . ' from' : $type) : ($many ? 'From' : '');
    } else {
        $rent = $avail['rent_from'];
        $rentLabel = $avail['room_count'] > 1 ? 'From' : '';
    }

    // What is free: the beds still open, where there are any; otherwise the
    // listing's own summary, in the same words as its page.
    $slots = (int) ($l['slots_left'] ?? 0);
    $meta = $avail['key'] === 'available' && $slots > 0
        ? $slots . ' ' . ($slots === 1 ? 'slot' : 'slots') . ' left'
        : $avail['summary'];

    $spoken = [];
    if ($rent !== null) {
        $spoken[] = ($rentLabel !== '' ? $rentLabel . ' ' : '') . peso_round($rent) . ' a month';
    }
    $spoken[] = $meta;
    ?>
    <article class="room-card room-card--<?= h($avail['key']) ?>">
      <div class="room-card-media">
        <?php if ($photo): ?>
          <img src="<?= h(base_url($l['cover_photo'])) ?>" alt="" loading="lazy">
        <?php else: ?>
          <?php /* No photo yet: the room type holds the space, quietly, so the
                   listing's own name still reads first. */ ?>
          <div class="room-card-placeholder" aria-hidden="true">
            <?= icon('home', 34) ?>
            <span><?= h(($l['match_type'] ?? '') ?: ($types !== '' ? explode(', ', $types)[0] : 'Boarding house')) ?></span>
          </div>
        <?php endif; ?>

        <span class="pill pill--on-photo <?= h($avail['pill']) ?>" aria-hidden="true"><?= h($avail['label']) ?></span>

        <?php if ($save): ?>
          <form method="post" action="<?= base_url('boarder/actions/favorite_action.php') ?>" class="save-form"
            <?= !empty($save['drop']) ? 'data-drop-on-unsave="1"' : '' ?>>
            <?= csrf_field() ?>
            <input type="hidden" name="boarding_house_id" value="<?= $id ?>">
            <input type="hidden" name="action" value="<?= $saved ? 'unsave' : 'save' ?>">
            <input type="hidden" name="return" value="<?= h($save['return'] ?? 'browse') ?>">
            <?php foreach (($save['fields'] ?? []) as $name => $value): ?>
              <?php /* A list, such as the ticked amenities, goes back as name[]. */ ?>
              <?php foreach ((array) $value as $item): ?>
                <input type="hidden" name="<?= h($name) . (is_array($value) ? '[]' : '') ?>" value="<?= h($item) ?>">
              <?php endforeach; ?>
            <?php endforeach; ?>
            <button type="submit" class="save-btn <?= $saved ? 'is-saved' : '' ?>" data-name="<?= h($l['name']) ?>"
              title="<?= $saved ? 'Remove from saved' : 'Save this listing' ?>"
              aria-label="<?= $saved ? 'Remove ' . h($l['name']) . ' from saved' : 'Save ' . h($l['name']) ?>"
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
          <a class="room-card-link" href="<?= base_url('boarder/view_listing.php?id=' . $id) ?>"><?= h($l['name']) ?><span class="sr-only">, <?= h(implode(', ', $spoken)) ?></span></a>
        </h3>
        <p class="room-card-addr" title="<?= h($l['address']) ?>"><?= icon('pin', 15) ?><span><?= h(short_address($l['address'])) ?></span></p>

        <?php if ($rent !== null): ?>
          <p class="room-card-price" aria-hidden="true">
            <?php if ($rentLabel !== ''): ?><span class="room-card-from"><?= h($rentLabel) ?></span><?php endif; ?>
            <?= peso_round($rent) ?> <span>/ month</span>
          </p>
        <?php endif; ?>
        <?php if ($types !== ''): ?>
          <p class="room-card-type"><?= h($types) ?></p>
        <?php endif; ?>

        <div class="room-card-foot" aria-hidden="true">
          <span class="room-card-meta"><?= icon('door', 16) ?><?= h($meta) ?></span>
          <span class="room-card-cta">View details <?= icon('chevron-right', 15) ?></span>
        </div>
      </div>
    </article>
    <?php
}
