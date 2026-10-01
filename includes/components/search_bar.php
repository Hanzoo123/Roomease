<?php
/**
 * Search form, shared by the home page and browse. Options: 'action', 'anchor',
 * 'id', 'room_types', current values ('q', 'room_type', 'max_rent', 'vacant',
 * 'amenities'), and 'amenity_options'.
 */
function render_search_bar(array $opts)
{
    $roomType = $opts['room_type'] ?? '';
    $maxRent = $opts['max_rent'] ?? '';
    $vacant = !empty($opts['vacant']);
    $amenityOptions = $opts['amenity_options'] ?? filter_amenity_options();
    $ticked = array_flip($opts['amenities'] ?? []);
    $action = ($opts['action'] ?? '') . (!empty($opts['anchor']) ? '#' . $opts['anchor'] : '');

    // On phones, open "More filters" if any of them are in use.
    $moreSet = ($roomType !== '' ? 1 : 0) + ($maxRent !== '' ? 1 : 0) + ($vacant ? 1 : 0) + count($ticked);

    // Landlords' own amenities wait behind "+ N more", unless one of them is
    // ticked, in which case the whole list is shown so it can be seen.
    $extraCount = count(array_filter($amenityOptions, function ($a) { return $a['extra']; }));
    $extraTicked = (bool) array_filter(array_intersect_key($amenityOptions, $ticked), function ($a) { return $a['extra']; });
    ?>
    <form method="get" class="search-bar" role="search"
      <?= $action !== '' ? 'action="' . h($action) . '"' : '' ?>
      <?= !empty($opts['id']) ? 'id="' . h($opts['id']) . '"' : '' ?>>
      <div>
        <label for="q">Search</label>
        <input type="text" id="q" name="q" value="<?= h($opts['q'] ?? '') ?>" placeholder="Name, barangay, or street">
      </div>
      <?php /* A real button, so it can be reached and opened from the keyboard.
               It only appears where search_bar's script runs (html.js) and the
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
        <div>
          <label for="max_rent">Max rent (₱)</label>
          <input type="number" id="max_rent" name="max_rent" value="<?= h($maxRent) ?>"
            min="100" step="100" inputmode="numeric">
        </div>
        <div class="filter-extras">
          <?php if ($amenityOptions): ?>
            <?php /* Like "More filters": a real button, shown only where the
                     script runs. Without it the amenities are simply listed. */ ?>
            <button type="button" class="amenity-toggle" data-amenity-toggle
              aria-expanded="<?= $ticked ? 'true' : 'false' ?>" aria-controls="amenity-options">
              Amenities<?= $ticked ? ' (' . count($ticked) . ')' : '' ?>
            </button>
          <?php endif; ?>
          <label class="vacant-check">
            <input type="checkbox" name="vacant" value="1" <?= $vacant ? 'checked' : '' ?>>
            Has a free slot
          </label>
        </div>
        <?php if ($amenityOptions): ?>
          <fieldset class="amenity-options<?= $ticked ? ' is-open' : '' ?><?= $extraTicked ? ' show-all' : '' ?>" id="amenity-options">
            <legend class="sr-only">Amenities the boarding house must have</legend>
            <?php foreach ($amenityOptions as $id => $a): ?>
              <label class="amenity-pill<?= $a['extra'] ? ' is-extra' : '' ?>">
                <input type="checkbox" name="amenities[]" value="<?= (int) $id ?>" <?= isset($ticked[$id]) ? 'checked' : '' ?>>
                <?= h($a['name']) ?>
              </label>
            <?php endforeach; ?>
            <?php if ($extraCount && !$extraTicked): ?>
              <button type="button" class="amenity-more" data-amenity-more>+ <?= $extraCount ?> more</button>
            <?php endif; ?>
          </fieldset>
        <?php endif; ?>
      </div>
      <?php /* The magnifying glass is drawn inline rather than loaded, because the
           public theme has no icon font; stroke="currentColor" keeps it the
           colour of the button's label. .search-bar .btn lays the two out. */ ?>
      <button type="submit" class="btn btn-accent">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
          fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
          stroke-linejoin="round" aria-hidden="true" focusable="false">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <span>Search</span>
      </button>
    </form>
    <script>
      (function () {
        var toggle = document.querySelector('[data-filter-toggle]');
        var fields = toggle && document.getElementById(toggle.getAttribute('aria-controls'));
        if (!fields) return;
        toggle.addEventListener('click', function () {
          var open = toggle.getAttribute('aria-expanded') !== 'true';
          toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
          fields.classList.toggle('is-open', open);
        });
      })();
      (function () {
        var toggle = document.querySelector('[data-amenity-toggle]');
        var list = toggle && document.getElementById(toggle.getAttribute('aria-controls'));
        if (!list) return;
        toggle.addEventListener('click', function () {
          var open = toggle.getAttribute('aria-expanded') !== 'true';
          toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
          list.classList.toggle('is-open', open);
        });
        var more = list.querySelector('[data-amenity-more]');
        if (more) {
          more.addEventListener('click', function () {
            list.classList.add('show-all');
            more.remove();
            // Keyboard focus moves to the first amenity that just appeared.
            var first = list.querySelector('.is-extra input');
            if (first) first.focus();
          });
        }
      })();
    </script>
    <?php
}
