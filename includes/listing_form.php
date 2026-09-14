<?php
/**
 * Shared boarding house form fields, styled for the AdminLTE panel.
 * Included by landlord/add_listing.php and landlord/edit_listing.php.
 *
 * Rent, room type and capacity belong to each room. A new listing's rooms are
 * added in this form (includes/room_rows_form.php, when $formRooms is set);
 * an existing listing's rooms are managed on its Rooms card instead.
 *
 * Expects:
 *   $listing         - assoc array of existing values, or [] for new listing
 *   $selectedAmens   - array of amenity_id currently selected
 *   $selectedUtils   - assoc array of utility_id => billing_policy
 *   $newAmenityNames - amenity names typed into "Add your own" (redisplay only)
 *   $newUtilityRows  - [['name', 'policy']] typed into "Add your own" (redisplay only)
 *   $existingImages  - array of existing house photos (for edit mode)
 */
$listing = $listing ?? [];
$selectedAmens = array_map('intval', $selectedAmens ?? []);
$selectedUtils = $selectedUtils ?? [];
$newAmenityNames = $newAmenityNames ?? [];
$newUtilityRows = $newUtilityRows ?? [];
$existingImages = $existingImages ?? [];
$val = fn($key, $default = '') => h($listing[$key] ?? $default);
$formLandlordId = (int) $_SESSION['user_id'];

$policyPresets = [
  'Included in Rent',
  'Separate Meter / Submeter',
  'Split Equally',
  'Fixed Monthly Rate',
  'Pay as you consume',
  'Not Available / Self-provided'
];
?>

<h5 class="font-weight-bold text-primary mb-3">
  <i class="fas fa-info-circle mr-1"></i> Property Details
</h5>

<div class="form-group">
  <label for="name">Boarding house name</label>
  <input type="text" class="form-control" id="name" name="name" value="<?= $val('name') ?>"
    placeholder="e.g. Greenview Student Dormitory" required>
</div>

<div class="form-group">
  <label for="address">Complete address (Baybay City)</label>
  <input type="text" class="form-control" id="address" name="address" value="<?= $val('address') ?>"
    placeholder="e.g. Purok 3, Brgy. Pangasugan, Baybay City, Leyte" required>
</div>

<div class="form-row">
  <div class="col-md-6 form-group">
    <label for="reservation_fee">Reservation fee (&#8369;)</label>
    <input type="number" step="0.01" min="0" class="form-control" id="reservation_fee" name="reservation_fee"
      value="<?= $val('reservation_fee') ?>" placeholder="e.g. 1500.00">
    <small class="form-text text-muted">Optional &mdash; leave blank if no reservation fee is required.</small>
  </div>
  <div class="col-md-6 form-group">
    <label for="contact_number">Landlord contact number</label>
    <input type="text" class="form-control" id="contact_number" name="contact_number"
      value="<?= $val('contact_number') ?>" placeholder="e.g. 09171234567" required>
  </div>
</div>

<div class="form-group">
  <label for="availability_status">Show this listing on the website</label>
  <select class="form-control" id="availability_status" name="availability_status">
    <option value="available" <?= ($listing['availability_status'] ?? 'available') === 'available' ? 'selected' : '' ?>>
      Shown (boarders can find it once it is approved)</option>
    <option value="unavailable" <?= ($listing['availability_status'] ?? '') === 'unavailable' ? 'selected' : '' ?>>
      Hidden (take the whole listing off the website)</option>
  </select>
  <small class="form-text text-muted">
    To show a room as full or not available, update it on the Rooms card instead.
  </small>
</div>

<div class="form-group">
  <label for="description">Brief description</label>
  <textarea class="form-control" id="description" name="description" rows="3"
    placeholder="Highlight location proximity, student atmosphere, building features..."><?= $val('description') ?></textarea>
</div>

<div class="form-group">
  <label for="house_rules">House rules</label>
  <textarea class="form-control" id="house_rules" name="house_rules" rows="3"
    placeholder="e.g. Curfew at 10:00 PM, quiet study hours, no outside overnight guests..."><?= $val('house_rules') ?></textarea>
</div>

<?php if (isset($formRooms)): ?>
  <?php /* New listings add their rooms right here; existing ones use the Rooms card. */ ?>
  <?php require __DIR__ . '/room_rows_form.php'; ?>
<?php endif; ?>

<hr>

<h5 class="font-weight-bold text-primary mb-1">
  <i class="fas fa-concierge-bell mr-1"></i> Amenities Offered
</h5>
<p class="text-muted small mb-3">
  Select all amenities currently accessible to boarders. Items marked <span class="badge badge-light border">Yours</span>
  are ones you added; only you see them here.
</p>

<?php $amenityChoices = lookup_choices('amenity', $formLandlordId); ?>
<?php if (!$amenityChoices): ?>
  <?= lookup_unavailable_notice('amenities', 'amenities') ?>
<?php endif; ?>
<div class="row mb-2">
  <?php foreach ($amenityChoices as $a): ?>
    <div class="col-md-4 col-sm-6 mb-2">
      <div class="custom-control custom-checkbox">
        <input type="checkbox" class="custom-control-input" id="amenity_<?= $a['id'] ?>" name="amenities[]"
          value="<?= $a['id'] ?>" <?= in_array($a['id'], $selectedAmens, true) ? 'checked' : '' ?>>
        <label class="custom-control-label" for="amenity_<?= $a['id'] ?>">
          <?= h($a['name']) ?>
          <?php if ($a['own']): ?><span class="badge badge-light border ml-1">Yours</span><?php endif; ?>
        </label>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="form-group" data-custom-list>
  <label class="small font-weight-bold mb-1">Add your own amenity</label>
  <?php foreach (($newAmenityNames ?: ['']) as $name): ?>
    <div class="mb-2" data-custom-row>
      <input type="text" class="form-control form-control-sm" name="new_amenity_name[]" maxlength="100"
        value="<?= h($name) ?>" placeholder="e.g. Rooftop deck">
    </div>
  <?php endforeach; ?>
  <button type="button" class="btn btn-xs btn-outline-secondary" data-custom-add>
    <i class="fas fa-plus mr-1"></i> Another amenity
  </button>
  <small class="form-text text-muted">Saved to your own list when you save this listing.</small>
</div>

<hr>

<h5 class="font-weight-bold text-primary mb-1">
  <i class="fas fa-bolt mr-1"></i> Utilities &amp; Billing Policies
</h5>
<p class="text-muted small mb-3">Tick each utility you provide and describe how it is billed.</p>

<?php $utilityChoices = lookup_choices('utility', $formLandlordId); ?>
<?php if (!$utilityChoices): ?>
  <?= lookup_unavailable_notice('utilities', 'utilities') ?>
<?php endif; ?>

<?php foreach ($utilityChoices as $u):
  $uId = $u['id'];
  $currentPolicy = $selectedUtils[$uId] ?? '';
  $isChecked = isset($selectedUtils[$uId]);
  ?>
  <div class="form-row align-items-center bg-light rounded border p-2 mb-2 mx-0">
    <div class="col-md-4">
      <div class="custom-control custom-checkbox">
        <input type="checkbox" class="custom-control-input" id="utility_<?= (int) $uId ?>" name="utilities[]"
          value="<?= (int) $uId ?>" <?= $isChecked ? 'checked' : '' ?>>
        <label class="custom-control-label font-weight-bold" for="utility_<?= (int) $uId ?>">
          <?= h($u['name']) ?>
          <?php if ($u['own']): ?><span class="badge badge-light border ml-1 font-weight-normal">Yours</span><?php endif; ?>
        </label>
      </div>
    </div>
    <div class="col-md-8">
      <input type="text" class="form-control form-control-sm" name="billing_policy[<?= (int) $uId ?>]"
        value="<?= h($currentPolicy) ?>" list="policy-presets"
        placeholder="e.g. Included in Rent, Separate Meter, Split Equally...">
    </div>
  </div>
<?php endforeach; ?>

<div class="form-group mt-3" data-custom-list>
  <label class="small font-weight-bold mb-1">Add your own utility</label>
  <?php foreach (($newUtilityRows ?: [['name' => '', 'policy' => '']]) as $row): ?>
    <div class="form-row mb-2" data-custom-row>
      <div class="col-md-4">
        <input type="text" class="form-control form-control-sm" name="new_utility_name[]" maxlength="100"
          value="<?= h($row['name']) ?>" placeholder="e.g. Parking fee" aria-label="Utility name">
      </div>
      <div class="col-md-8">
        <input type="text" class="form-control form-control-sm" name="new_utility_policy[]" maxlength="150"
          value="<?= h($row['policy']) ?>" list="policy-presets" placeholder="How it is billed"
          aria-label="How it is billed">
      </div>
    </div>
  <?php endforeach; ?>
  <button type="button" class="btn btn-xs btn-outline-secondary" data-custom-add>
    <i class="fas fa-plus mr-1"></i> Another utility
  </button>
  <small class="form-text text-muted">
    Saved to your own list and added to this listing when you save. Manage your list under
    <a href="<?= base_url('landlord/extras.php') ?>">Utilities &amp; Amenities</a>.
  </small>
</div>

<script>
  // "Another utility / amenity": copies the last empty row. Without
  // JavaScript the single row still works; add more after saving.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-custom-add]');
    if (!btn) return;
    var list = btn.closest('[data-custom-list]');
    var rows = list.querySelectorAll('[data-custom-row]');
    var copy = rows[rows.length - 1].cloneNode(true);
    Array.prototype.forEach.call(copy.querySelectorAll('input'), function (input) { input.value = ''; });
    rows[rows.length - 1].after(copy);
    copy.querySelector('input').focus();
  });
</script>
<datalist id="policy-presets">
  <?php foreach ($policyPresets as $preset): ?>
    <option value="<?= h($preset) ?>">
  <?php endforeach; ?>
</datalist>

<hr>

<?php
/* Stay terms. Every field is optional: anything left blank or "Not stated"
   is simply left off the listing page rather than shown as a guess. The
   yes/no rules come back from the database as '1'/'0'/NULL and from a failed
   submit as 1/0/NULL, so they are compared as strings. */
$selectedPayments = explode(',', (string) ($listing['payment_methods'] ?? ''));
$houseRuleFlags = [
  'visitors_allowed' => 'Visitors',
  'pets_allowed' => 'Pets',
  'cooking_allowed' => 'Cooking',
];
?>
<h5 class="font-weight-bold text-primary mb-1">
  <i class="fas fa-clipboard-check mr-1"></i> Stay Terms
</h5>
<p class="text-muted small mb-3">Optional. Anything you leave blank is not shown on your listing.</p>

<div class="form-row">
  <div class="col-md-6 form-group">
    <label for="curfew">Curfew</label>
    <input type="text" maxlength="60" class="form-control" id="curfew" name="curfew" value="<?= $val('curfew') ?>"
      placeholder="e.g. 10:00 PM, or No curfew">
  </div>
  <div class="col-md-6 form-group">
    <label for="gender_policy">Who can stay</label>
    <select class="form-control" id="gender_policy" name="gender_policy">
      <option value="">Not stated</option>
      <?php foreach (gender_policy_options() as $value => $label): ?>
        <option value="<?= h($value) ?>" <?= ($listing['gender_policy'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<div class="form-row">
  <div class="col-md-6 form-group">
    <label for="security_deposit">Security deposit (&#8369;)</label>
    <input type="number" step="0.01" min="0" class="form-control" id="security_deposit" name="security_deposit"
      value="<?= $val('security_deposit') ?>" placeholder="e.g. 3000.00">
    <small class="form-text text-muted">Enter 0 if no deposit is required.</small>
  </div>
  <div class="col-md-6 form-group">
    <label for="minimum_stay_months">Minimum stay (months)</label>
    <input type="number" step="1" min="1" max="60" class="form-control" id="minimum_stay_months"
      name="minimum_stay_months" value="<?= $val('minimum_stay_months') ?>" placeholder="e.g. 6">
  </div>
</div>

<div class="form-group">
  <label class="d-block">Payment methods accepted</label>
  <?php foreach (payment_method_options() as $value => $label): ?>
    <div class="custom-control custom-checkbox custom-control-inline">
      <input type="checkbox" class="custom-control-input" id="payment_<?= h($value) ?>" name="payment_methods[]"
        value="<?= h($value) ?>" <?= in_array($value, $selectedPayments, true) ? 'checked' : '' ?>>
      <label class="custom-control-label" for="payment_<?= h($value) ?>"><?= h($label) ?></label>
    </div>
  <?php endforeach; ?>
</div>

<div class="form-row">
  <?php foreach ($houseRuleFlags as $field => $label): ?>
    <?php $current = (string) ($listing[$field] ?? ''); ?>
    <div class="col-md-4 form-group">
      <label for="<?= $field ?>"><?= $label ?></label>
      <select class="form-control" id="<?= $field ?>" name="<?= $field ?>">
        <option value="" <?= $current === '' ? 'selected' : '' ?>>Not stated</option>
        <option value="1" <?= $current === '1' ? 'selected' : '' ?>>Allowed</option>
        <option value="0" <?= $current === '0' ? 'selected' : '' ?>>Not allowed</option>
      </select>
    </div>
  <?php endforeach; ?>
</div>

<hr>

<h5 class="font-weight-bold text-primary mb-1">
  <i class="fas fa-map-marker-alt mr-1"></i> Location on Map
</h5>
<p class="text-muted small mb-3">
  Click the map to drop a pin on the boarding house, then drag the pin to adjust it. Boarders see this pin on
  your listing. The map needs an internet connection; without one, you can type the coordinates instead.
</p>

<link rel="stylesheet" href="<?= base_url('assets/vendor/leaflet/leaflet.css') ?>">
<div id="location-picker" class="rounded border mb-2" style="height: 320px;"></div>
<p id="location-picker-offline" class="text-muted small d-none">
  <i class="fas fa-wifi mr-1"></i> The map could not load. Check the internet connection, or type the
  coordinates below.
</p>

<div class="form-row">
  <div class="col-md-5 form-group">
    <label for="latitude">Latitude</label>
    <input type="text" inputmode="decimal" class="form-control" id="latitude" name="latitude"
      value="<?= $val('latitude') ?>" placeholder="e.g. 10.678100">
  </div>
  <div class="col-md-5 form-group">
    <label for="longitude">Longitude</label>
    <input type="text" inputmode="decimal" class="form-control" id="longitude" name="longitude"
      value="<?= $val('longitude') ?>" placeholder="e.g. 124.800300">
  </div>
  <div class="col-md-2 form-group d-flex align-items-end">
    <button type="button" class="btn btn-outline-secondary btn-block" id="location-clear">Clear</button>
  </div>
</div>

<script src="<?= base_url('assets/vendor/leaflet/leaflet.js') ?>"></script>
<script>
  (function () {
    var el = document.getElementById('location-picker');
    var offline = document.getElementById('location-picker-offline');
    var latIn = document.getElementById('latitude');
    var lngIn = document.getElementById('longitude');
    if (!el || !latIn || !lngIn) return;
    if (!window.L) {
      el.classList.add('d-none');
      offline.classList.remove('d-none');
      return;
    }

    // Centre of Baybay City, used until the landlord drops a pin.
    var BAYBAY = [10.6781, 124.8003];

    function readInputs() {
      var lat = parseFloat(latIn.value);
      var lng = parseFloat(lngIn.value);
      return (isFinite(lat) && isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180) ? [lat, lng] : null;
    }

    var start = readInputs();
    var map = L.map(el).setView(start || BAYBAY, start ? 17 : 14);
    var tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);
    tiles.once('tileerror', function () {
      offline.classList.remove('d-none');
    });

    var marker = null;

    function write(latlng) {
      latIn.value = latlng.lat.toFixed(6);
      lngIn.value = latlng.lng.toFixed(6);
    }

    function place(latlng, pan) {
      if (!marker) {
        marker = L.marker(latlng, { draggable: true }).addTo(map);
        marker.on('dragend', function () { write(marker.getLatLng()); });
      } else {
        marker.setLatLng(latlng);
      }
      write(L.latLng(latlng));
      if (pan) map.panTo(latlng);
    }

    if (start) place(start, false);
    map.on('click', function (e) { place(e.latlng, false); });

    function fromInputs() {
      var typed = readInputs();
      if (typed) place(typed, true);
    }
    latIn.addEventListener('change', fromInputs);
    lngIn.addEventListener('change', fromInputs);

    document.getElementById('location-clear').addEventListener('click', function () {
      latIn.value = '';
      lngIn.value = '';
      if (marker) {
        map.removeLayer(marker);
        marker = null;
      }
    });
  })();
</script>

<hr>

<h5 class="font-weight-bold text-primary mb-1">
  <i class="fas fa-images mr-1"></i> House Photos
</h5>
<p class="text-muted small mb-3">
  Photos of the building, entrance, and shared areas. Each room gets its own photos on the room's page.
  You can pick several at once &mdash; JPG, PNG or WEBP, up to <?= format_bytes(max_upload_bytes()) ?> each and
  <?= max_photos_per_upload() ?> per upload.
  <?php if (empty($existingImages)): ?>The first photo becomes the cover.<?php endif; ?>
</p>

<div class="form-group">
  <label for="photos"><?= !empty($listing['boarding_house_id']) ? 'Add more house photos' : 'Upload house photos' ?></label>
  <input type="file" class="form-control-file" id="photos" name="photos[]"
    accept="image/jpeg,image/png,image/webp" multiple>
</div>
