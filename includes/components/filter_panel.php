<?php
/**
 * The browse page's filter panel: a card beside the results on a laptop, and
 * above them on a tablet. On a phone, Find places near me stays on the page
 * with an "Open filters" button under it, and the rest of the filters wait in
 * a drawer that slides in from the left. It is the page's one search form;
 * the Sort by menu above the results joins it with form="browse-filters".
 *
 * Options: 'action', 'room_types', 'amenity_options', 'filters' (the search
 * as browse has it, without the page), 'near_off' (the same search with Near
 * me turned off, for its Turn off link) and 'near_outside' (the visitor seems
 * to be outside Baybay City). Styles: "Browse" in style.css.
 */
require_once __DIR__ . '/icons.php';

function render_filter_panel(array $opts)
{
    $f = $opts['filters'] ?? [];
    $q = $f['q'] ?? '';
    $roomType = $f['room_type'] ?? '';
    $minRent = $f['min_rent'] ?? '';
    $maxRent = $f['max_rent'] ?? '';
    $vacant = !empty($f['vacant']);
    $ticked = array_flip($f['amenities'] ?? []);
    $near = isset($f['near']);
    $within = $f['within'] ?? '';
    $amenityOptions = $opts['amenity_options'] ?? filter_amenity_options();

    // How many of the filters in the drawer are in use, for its button on a phone.
    $filterCount = ($q !== '' ? 1 : 0) + ($roomType !== '' ? 1 : 0) + ($minRent !== '' ? 1 : 0)
        + ($maxRent !== '' ? 1 : 0) + ($vacant ? 1 : 0) + count($ticked);
    $anySet = $filterCount > 0 || $near || isset($f['sort']);

    // Landlords' own amenities wait behind "+ N more", unless one of them is
    // ticked, in which case the whole list is shown so it can be seen.
    $extraCount = count(array_filter($amenityOptions, fn($a) => $a['extra']));
    $extraTicked = (bool) array_filter(array_intersect_key($amenityOptions, $ticked), fn($a) => $a['extra']);

    // Under Near me: what happens to the location, or why the distances are long.
    if (!$near) {
        $nearNote = 'Your location only sorts this list. It is never saved.';
    } elseif (!empty($opts['near_outside'])) {
        $nearNote = 'You seem to be outside Baybay City, so every place is far from you.';
    } else {
        $nearNote = 'Your location is rounded to about 100 m, and forgotten when you turn this off.';
    }
    ?>
    <form method="get" action="<?= h($opts['action'] ?? '') ?>" class="filter-panel" id="browse-filters"
      role="search" aria-label="Filter boarding houses">
      <?php /* Find places near me needs the browser's location, so it only
               shows where the script below runs (html.js). The location goes
               to the server in the small form after this one. */ ?>
      <div class="near-me<?= $near ? ' is-on' : '' ?>" data-near>
        <?php if ($near): ?>
          <p class="near-me-state"><?= icon('locate', 16) ?><span>Showing how far each place is from you</span></p>
          <div>
            <label for="within">Distance</label>
            <select id="within" name="within">
              <option value="">Any distance</option>
              <?php foreach (NEAR_RADII_KM as $km): ?>
                <option value="<?= $km ?>" <?= $within === $km ? 'selected' : '' ?>>Within <?= $km ?> km</option>
              <?php endforeach; ?>
            </select>
          </div>
          <input type="hidden" name="near" value="1">
          <p class="near-me-actions">
            <button type="button" class="near-me-update" data-near-me>Update my location</button>
            <a href="<?= h(base_url(browse_path($opts['near_off'] ?? [], 'results'))) ?>">Turn off</a>
          </p>
        <?php else: ?>
          <button type="button" class="btn btn-near" data-near-me><?= icon('locate', 18) ?><span>Find places near me</span></button>
        <?php endif; ?>
        <p class="near-me-note" data-near-status role="status" aria-live="polite"><?= h($nearNote) ?></p>
      </div>

      <?php /* Phones: the filters below wait in a drawer, opened by this button.
               Both only work where the script below runs (html.js); without
               it, the filters are simply shown. */ ?>
      <button type="button" class="btn btn-ghost filter-open" data-filter-open
        aria-controls="filter-drawer" aria-expanded="false">
        <?= icon('filter', 18) ?><span>Open filters<?= $filterCount ? ' (' . $filterCount . ')' : '' ?></span>
      </button>

      <div class="filter-drawer" id="filter-drawer" aria-labelledby="filter-drawer-title">
        <div class="filter-drawer-head">
          <h2 id="filter-drawer-title">Filters</h2>
          <button type="button" class="filter-close" data-filter-close aria-label="Close filters"><?= icon('x', 22) ?></button>
        </div>

        <div class="filter-body">
          <div>
            <label for="q">Search</label>
            <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Name, barangay, or street">
          </div>

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
      </div>

      <div class="filter-backdrop" data-filter-close hidden></div>
    </form>

    <?php /* Sends the location that Find places near me found (see the script),
             with the search as it is now. A form of its own, because it posts,
             and the filter form above is a GET search. */ ?>
    <form method="post" action="<?= h(base_url('boarder/near_action.php')) ?>" id="near-form" hidden>
      <?= csrf_field() ?>
      <input type="hidden" name="lat" value="">
      <input type="hidden" name="lng" value="">
      <?= hidden_fields($f) ?>
    </form>

    <script>
      (function () {
        var form = document.getElementById('browse-filters');
        if (!form) return;

        // Phones: the filters slide in from the left over a tinted page, the
        // way the site header's menu opens. The stylesheet decides at which
        // width this happens; while the opener is on screen, it does.
        var root = document.documentElement;
        var opener = form.querySelector('[data-filter-open]');
        var drawer = document.getElementById(opener.getAttribute('aria-controls'));
        var backdrop = form.querySelector('.filter-backdrop');

        var drawerStops = function () {
          return Array.prototype.filter.call(
            drawer.querySelectorAll('a[href], button:not([disabled]), input:not([type="hidden"]), select'),
            function (el) { return el.offsetParent !== null; }
          );
        };

        var openDrawer = function () {
          drawer.setAttribute('role', 'dialog');
          drawer.setAttribute('aria-modal', 'true');
          drawer.classList.add('is-open');
          backdrop.hidden = false;
          opener.setAttribute('aria-expanded', 'true');
          root.classList.add('filters-open');
          drawer.querySelector('[data-filter-close]').focus();
        };

        var closeDrawer = function (returnFocus) {
          if (!drawer.classList.contains('is-open')) return;
          drawer.classList.remove('is-open');
          drawer.removeAttribute('role');
          drawer.removeAttribute('aria-modal');
          backdrop.hidden = true;
          opener.setAttribute('aria-expanded', 'false');
          root.classList.remove('filters-open');
          if (returnFocus) opener.focus();
        };

        opener.addEventListener('click', openDrawer);
        form.querySelectorAll('[data-filter-close]').forEach(function (el) {
          el.addEventListener('click', function () { closeDrawer(true); });
        });

        document.addEventListener('keydown', function (e) {
          if (!drawer.classList.contains('is-open')) return;
          if (e.key === 'Escape') {
            closeDrawer(true);
            return;
          }
          if (e.key !== 'Tab') return;
          // Tab cycles within the drawer rather than into the page behind it.
          var stops = drawerStops();
          var first = stops[0];
          var last = stops[stops.length - 1];
          if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
          } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
          }
        });

        // Turning the phone, or widening the window, puts the filters back in
        // the page; an open drawer would be left over them.
        window.addEventListener('resize', function () {
          if (opener.offsetParent === null) closeDrawer(false);
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

        // Find places near me: on a tap, ask the browser where the visitor is,
        // then post it, rounded to about 100 m, with the search as it is.
        var near = form.querySelector('[data-near]');
        var nearStatus = near.querySelector('[data-near-status]');
        var NEAR_ERRORS = {
          1: 'Location is blocked for this site. Allow it in your browser settings, or search by name or barangay instead.',
          2: 'Your location could not be found. Try again, or search by name or barangay instead.',
          3: 'Finding your location took too long. Please try again.'
        };
        if (!navigator.geolocation) {
          near.hidden = true; // nothing to offer without it
          return;
        }
        near.querySelectorAll('[data-near-me]').forEach(function (button) {
          button.addEventListener('click', function () {
            // Browsers only share a location with https pages and localhost.
            if (!window.isSecureContext) {
              nearStatus.textContent = 'Your browser only shares your location over a secure (https) connection.';
              return;
            }
            button.disabled = true;
            nearStatus.textContent = 'Finding your location…';
            navigator.geolocation.getCurrentPosition(function (pos) {
              var send = document.getElementById('near-form');
              send.elements.lat.value = pos.coords.latitude.toFixed(3);
              send.elements.lng.value = pos.coords.longitude.toFixed(3);
              send.submit();
            }, function (err) {
              button.disabled = false;
              nearStatus.textContent = NEAR_ERRORS[err.code] || NEAR_ERRORS[2];
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
          });
        });
      })();
    </script>
    <?php
}
