<?php
/**
 * The search form that sits on the band's seam, shared by the home page and
 * browse so both submit exactly the same filters.
 *
 *   'action'     where the form submits; '' submits to the current page
 *   'id'         optional id, used by browse as the #listings anchor
 *   'room_types' room_type_id => name, from room_type_options()
 *   'q', 'room_type', 'max_rent'  current values, to keep them filled in
 */
function render_search_bar(array $opts)
{
    $roomType = $opts['room_type'] ?? '';
    ?>
    <form method="get" class="search-bar" role="search"
      <?= ($opts['action'] ?? '') !== '' ? 'action="' . h($opts['action']) . '"' : '' ?>
      <?= !empty($opts['id']) ? 'id="' . h($opts['id']) . '"' : '' ?>>
      <div>
        <label for="q">Search</label>
        <input type="text" id="q" name="q" value="<?= h($opts['q'] ?? '') ?>" placeholder="Name, barangay, or street">
      </div>
      <?php /* Presentation only, and deliberately without a name attribute so it
               never reaches the query string. Hidden entirely above 720px. */ ?>
      <input type="checkbox" id="more-filters" class="filter-toggle">
      <label for="more-filters" class="filter-toggle-label">More filters</label>
      <div class="filter-fields">
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
          <input type="number" id="max_rent" name="max_rent" value="<?= h($opts['max_rent'] ?? '') ?>"
            min="0" step="100" inputmode="numeric">
        </div>
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
    <?php
}
