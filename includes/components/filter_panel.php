<?php
/**
 * The browse page's filter panel: a card beside the results on a laptop, and
 * on top of them on a phone, where everything after the search box folds
 * under "More filters". It is the page's one search form; the Sort by menu
 * above the results joins it with form="browse-filters".
 *
 * Options: 'action', 'room_types', 'amenity_options', and the current values
 * 'q', 'room_type', 'min_rent', 'max_rent', 'vacant', 'amenities' and 'sort'.
 * Styles: "Browse" in style.css.
 */
require_once __DIR__ . '/icons.php';

function render_filter_panel(array $opts)
{
    $q = $opts['q'] ?? '';
    $roomType = $opts['room_type'] ?? '';
    $minRent = $opts['min_rent'] ?? '';
    $maxRent = $opts['max_rent'] ?? '';
    $vacant = !empty($opts['vacant']);
    $amenityOptions = $opts['amenity_options'] ?? filter_amenity_options();
    $ticked = array_flip($opts['amenities'] ?? []);

    // On phones, "More filters" starts open when a field under it is in use,
    // and says how many are.
    $moreSet = ($roomType !== '' ? 1 : 0) + ($minRent !== '' ? 1 : 0) + ($maxRent !== '' ? 1 : 0)
        + ($vacant ? 1 : 0) + count($ticked);
    $anySet = $moreSet > 0 || $q !== '' || ($opts['sort'] ?? '') !== '';

    // Landlords' own amenities wait behind "+ N more", unless one of them is
    // ticked, in which case the whole list is shown so it can be seen.
    $extraCount = count(array_filter($amenityOptions, fn($a) => $a['extra']));
    $extraTicked = (bool) array_filter(array_intersect_key($amenityOptions, $ticked), fn($a) => $a['extra']);
    ?>
    <form method="get" action="<?= h($opts['action'] ?? '') ?>" class="filter-panel" id="browse-filters"
      role="search" aria-label="Filter boarding houses">
      <div class="filter-body">
        <div>
          <label for="q">Search</label>
          <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Name, barangay, or street">
        </div>

        <?php /* A real button, so it can be reached and opened from the keyboard.
                 It only shows where the script below runs (html.js) and the
                 screen is narrow; everywhere else the fields are simply shown. */ ?>
        <button type="button" class="filter-toggle" data-filter-toggle
          aria-expanded="<?= $moreSet ? 'true' : 'false' ?>" aria-controls="filter-fields">
          More filters<?= $moreSet ? ' (' . $moreSet . ')' : '' ?>
        </button>

        <div class="filter-fields<?= $moreSet ? ' is-open' : '' ?>" id="filter-fields">
          <div>
            <label for="room_type">Room type</label>
            <select id="room_type" name="room_type">
              <option value="">Any</option>
              <?php foreach (($opts['room_types'] ?? []) as $rtId => $rtName): ?>
                <option value="<?= (int) $rtId ?>" <?= $roomType === $rtId ? 'selected' : '' ?>><?= h($rtName) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <fieldset>
            <legend>Rent per month (₱)</legend>
            <div class="filter-rent">
              <label class="sr-only" for="min_rent">Lowest rent per month</label>
              <input type="number" id="min_rent" name="min_rent" value="<?= h($minRent) ?>" placeholder="Min"
                min="0" step="1" inputmode="numeric">
              <span aria-hidden="true">to</span>
              <label class="sr-only" for="max_rent">Highest rent per month</label>
              <input type="number" id="max_rent" name="max_rent" value="<?= h($maxRent) ?>" placeholder="Max"
                min="0" step="1" inputmode="numeric">
            </div>
          </fieldset>

          <label class="filter-check">
            <input type="checkbox" name="vacant" value="1" <?= $vacant ? 'checked' : '' ?>>
            Has a free slot
          </label>

          <?php if ($amenityOptions): ?>
            <fieldset class="filter-amenities<?= $extraTicked ? ' show-all' : '' ?>">
              <legend>Amenities</legend>
              <div class="filter-amenity-list">
                <?php foreach ($amenityOptions as $id => $a): ?>
                  <label class="filter-check<?= $a['extra'] ? ' is-extra' : '' ?>">
                    <input type="checkbox" name="amenities[]" value="<?= (int) $id ?>" <?= isset($ticked[$id]) ? 'checked' : '' ?>>
                    <?= h($a['name']) ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <?php if ($extraCount && !$extraTicked): ?>
                <button type="button" class="amenity-more" data-amenity-more>+ <?= $extraCount ?> more</button>
              <?php endif; ?>
            </fieldset>
          <?php endif; ?>
        </div>
      </div>

      <div class="filter-actions">
        <button type="submit" class="btn btn-accent"><?= icon('search', 18) ?><span>Apply filters</span></button>
        <?php if ($anySet): ?>
          <a class="filter-reset" href="<?= h(base_url(browse_path([], 'results'))) ?>">Clear filters</a>
        <?php endif; ?>
      </div>
    </form>
    <script>
      (function () {
        var form = document.getElementById('browse-filters');
        if (!form) return;

        // Phones: everything after the search box folds under "More filters".
        var toggle = form.querySelector('[data-filter-toggle]');
        var fields = document.getElementById(toggle.getAttribute('aria-controls'));
        toggle.addEventListener('click', function () {
          var open = toggle.getAttribute('aria-expanded') !== 'true';
          toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
          fields.classList.toggle('is-open', open);
        });

        // "+ N more" brings in the landlords' own amenities, and keyboard focus
        // moves to the first of them.
        var more = form.querySelector('[data-amenity-more]');
        if (more) {
          more.addEventListener('click', function () {
            more.parentNode.classList.add('show-all');
            more.remove();
            var first = form.querySelector('.filter-amenities .is-extra input');
            if (first) first.focus();
          });
        }

        // Controls elsewhere on the page that belong to this form (Sort by,
        // above the results) apply as soon as they change. Listened for on the
        // document, because they come later in the page than this script.
        document.addEventListener('change', function (e) {
          if (e.target.matches('[form="browse-filters"][data-auto-submit]')) {
            if (form.requestSubmit) {
              form.requestSubmit();
            } else {
              form.submit();
            }
          }
        });
      })();
    </script>
    <?php
}
