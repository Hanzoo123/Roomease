<?php
/**
 * A listing card, used by the home, browse and saved pages.
 *
 * $l: listing row with cover_photo and room totals (plus match_* from browse,
 *     and distance_km while browse's "Find places near me" is on).
 * $save: null if the user can't save, else:
 *   'saved'  already saved?
 *   'return' where to go back to without JavaScript
 *   'fields' extra hidden fields (browse filters)
 *   'drop'   remove the card when unsaved (saved page)
 *
 * The whole card is one link, whose label includes the price for screen readers.
 * The cover photo on top, or the house icon on a green tile while there is
 * none, then the rent, room types and state, name, street (and how far away,
 * with Near me on), when its availability was last updated, and slots left. Styles: "Listing cards" in style.css.
 */
require_once __DIR__ . '/icons.php';

function render_listing_card(array $l, ?array $save = null)
{
    $id = (int) $l['boarding_house_id'];
    $avail = listing_availability($l);
    $saved = $save && !empty($save['saved']);
    $types = (string) ($l['open_room_types'] ?? $l['room_types'] ?? '');
    $photo = !empty($l['cover_photo']) && photo_on_disk($l['cover_photo']);

    // Two room types fit on a phone's card; any more are counted, not named.
    $typeList = $types !== '' ? explode(', ', $types) : [];
    $moreTypes = count($typeList) - 2;
    $typeList = array_slice($typeList, 0, 2);

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

    // With Near me on: how far away the listing is, or that it has no map pin
    // to measure from.
    $hasDistance = array_key_exists('distance_km', $l);
    $distance = $hasDistance && $l['distance_km'] !== null ? distance_label((float) $l['distance_km']) : null;

    // When the landlord last changed or confirmed a room.
    $freshness = availability_freshness($l['rooms_updated_at'] ?? null);

    // Everything the card shows above the name is drawn for the eye and hidden
    // from screen readers, which hear it in the link's label instead, after
    // the name, in one sentence.
    $spoken = [];
    if ($types !== '') {
        $spoken[] = $types;
    }
    if ($rent !== null) {
        $spoken[] = ($rentLabel !== '' ? $rentLabel . ' ' : '') . peso_round($rent) . ' a month';
    }
    $spoken[] = $meta;
    if ($distance !== null) {
        $spoken[] = $distance;
    }
    if ($freshness) {
        $spoken[] = 'availability ' . lcfirst($freshness['text']);
    }
    ?>
    <article class="room-card room-card--<?= h($avail['key']) ?>">
      <div class="room-card-media">
        <?php if ($photo): ?>
          <img src="<?= h(base_url($l['cover_photo'])) ?>" alt="" loading="lazy">
        <?php else: ?>
          <?php /* No photo yet: the house icon holds the photo's place, so the
                   cards in a row still line up. */ ?>
          <span class="room-card-empty" aria-hidden="true">
            <?= icon('home', 36) ?>
            <span>No photo yet</span>
          </span>
        <?php endif; ?>
      </div>

      <?php if ($save): ?>
        <form method="post" action="<?= base_url('boarder/favorite_action.php') ?>" class="save-form"
          <?= !empty($save['drop']) ? 'data-drop-on-unsave="1"' : '' ?>>
          <?= csrf_field() ?>
          <input type="hidden" name="boarding_house_id" value="<?= $id ?>">
          <input type="hidden" name="action" value="<?= $saved ? 'unsave' : 'save' ?>">
          <input type="hidden" name="return" value="<?= h($save['return'] ?? 'browse') ?>">
          <?= hidden_fields($save['fields'] ?? []) ?>
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

      <div class="room-card-body">
        <?php if ($rent !== null): ?>
          <p class="room-card-price" aria-hidden="true">
            <?php if ($rentLabel !== ''): ?><span class="room-card-from<?= $rentLabel !== 'From' ? ' room-card-from--type' : '' ?>"><?= h($rentLabel) ?></span><?php endif; ?>
            <span class="room-card-rent"><?= peso_round($rent) ?></span> <span class="room-card-per">/ month</span>
          </p>
        <?php endif; ?>

        <ul class="room-card-tags" aria-hidden="true">
          <?php foreach ($typeList as $typeName): ?>
            <li class="tag"><?= h($typeName) ?></li>
          <?php endforeach; ?>
          <?php if ($moreTypes > 0): ?>
            <li class="tag">+<?= $moreTypes ?> more</li>
          <?php endif; ?>
          <li class="pill <?= h($avail['pill']) ?>"><?= h($avail['label']) ?></li>
        </ul>

        <h3 class="room-card-title">
          <a class="room-card-link" href="<?= base_url('boarder/view_listing.php?id=' . $id) ?>"><?= h($l['name']) ?><span class="sr-only">, <?= h(implode(', ', $spoken)) ?></span></a>
        </h3>
        <p class="room-card-addr" title="<?= h($l['address']) ?>"><?= icon('pin', 15) ?><span><?= h(short_address($l['address'])) ?></span></p>
        <?php if ($hasDistance): ?>
          <p class="room-card-distance<?= $distance === null ? ' is-unknown' : '' ?>"><?= icon('locate', 15) ?><span><?= h($distance ?? 'No map pin yet') ?></span></p>
        <?php endif; ?>
        <?php if ($freshness): ?>
          <p class="room-card-updated<?= $freshness['stale'] ? ' is-stale' : '' ?>" aria-hidden="true"><?= icon('clock', 15) ?><span><?= h($freshness['text']) ?></span></p>
        <?php endif; ?>

        <div class="room-card-foot" aria-hidden="true">
          <span class="room-card-meta"><?= icon('door', 16) ?><?= h($meta) ?></span>
          <span class="room-card-cta">View details <?= icon('chevron-right', 15) ?></span>
        </div>
      </div>
    </article>
    <?php
}
