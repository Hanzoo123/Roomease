<?php
/**
 * Shared boarding house form fields, styled for the AdminLTE panel.
 * Included by landlord/add_listing.php and landlord/edit_listing.php.
 * Expects:
 *   $listing        - assoc array of existing values, or [] for new listing
 *   $selectedAmens  - array of amenity names currently selected
 *   $selectedUtils  - assoc array of utility_id => billing_policy
 *   $existingImages - array of existing images from images table (for edit mode)
 */
$listing = $listing ?? [];
$selectedAmens = $selectedAmens ?? [];
$selectedUtils = $selectedUtils ?? [];
$existingImages = $existingImages ?? [];
$val = fn($key, $default = '') => h($listing[$key] ?? $default);

$commonRoomTypes = room_type_options();
// If this listing predates the current vocabulary, keep its stored value in the
// list so editing another field cannot silently reassign its room type.
if (!empty($listing['room_type']) && !in_array($listing['room_type'], $commonRoomTypes, true)) {
  array_unshift($commonRoomTypes, $listing['room_type']);
}

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
    <label for="monthly_rent">Monthly rent (&#8369;)</label>
    <input type="number" step="0.01" min="0" class="form-control" id="monthly_rent" name="monthly_rent"
      value="<?= $val('monthly_rent') ?>" placeholder="e.g. 3500.00" required>
  </div>
  <div class="col-md-6 form-group">
    <label for="reservation_fee">Reservation fee (&#8369;)</label>
    <input type="number" step="0.01" min="0" class="form-control" id="reservation_fee" name="reservation_fee"
      value="<?= $val('reservation_fee') ?>" placeholder="e.g. 1500.00">
    <small class="form-text text-muted">Optional &mdash; leave blank if no reservation fee is required.</small>
  </div>
</div>

<div class="form-row">
  <div class="col-md-6 form-group">
    <label for="room_type">Room type</label>
    <select class="form-control" id="room_type" name="room_type" required>
      <?php foreach ($commonRoomTypes as $rt): ?>
        <option value="<?= h($rt) ?>" <?= ($listing['room_type'] ?? '') === $rt ? 'selected' : '' ?>><?= h($rt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-6 form-group">
    <label for="room_capacity">Max occupants (capacity)</label>
    <input type="number" min="1" class="form-control" id="room_capacity" name="room_capacity"
      value="<?= $val('room_capacity', 1) ?>" required>
  </div>
</div>

<div class="form-row">
  <div class="col-md-6 form-group">
    <label for="contact_number">Landlord contact number</label>
    <input type="text" class="form-control" id="contact_number" name="contact_number"
      value="<?= $val('contact_number') ?>" placeholder="e.g. 09171234567" required>
  </div>
  <div class="col-md-6 form-group">
    <label for="availability_status">Availability status</label>
    <select class="form-control" id="availability_status" name="availability_status">
      <option value="available" <?= ($listing['availability_status'] ?? 'available') === 'available' ? 'selected' : '' ?>>
        Available (Open for boarders)</option>
      <option value="unavailable" <?= ($listing['availability_status'] ?? '') === 'unavailable' ? 'selected' : '' ?>>
        Unavailable (Fully occupied / closed)</option>
    </select>
  </div>
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

<hr>

<h5 class="font-weight-bold text-primary mb-1">
  <i class="fas fa-concierge-bell mr-1"></i> Amenities Offered
</h5>
<p class="text-muted small mb-3">Select all amenities currently accessible to boarders.</p>

<div class="row mb-3">
  <?php foreach (amenity_options() as $i => $a): ?>
    <div class="col-md-4 col-sm-6 mb-2">
      <div class="custom-control custom-checkbox">
        <input type="checkbox" class="custom-control-input" id="amenity_<?= (int) $i ?>" name="amenities[]"
          value="<?= h($a) ?>" <?= in_array($a, $selectedAmens, true) ? 'checked' : '' ?>>
        <label class="custom-control-label" for="amenity_<?= (int) $i ?>"><?= h($a) ?></label>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<hr>

<h5 class="font-weight-bold text-primary mb-1">
  <i class="fas fa-bolt mr-1"></i> Utilities &amp; Billing Policies
</h5>
<p class="text-muted small mb-3">Tick each utility you provide and describe how it is billed.</p>

<?php foreach (utility_options() as $u):
  $uId = $u['utility_id'];
  $currentPolicy = $selectedUtils[$uId] ?? '';
  $isChecked = isset($selectedUtils[$uId]);
  ?>
  <div class="form-row align-items-center bg-light rounded border p-2 mb-2 mx-0">
    <div class="col-md-4">
      <div class="custom-control custom-checkbox">
        <input type="checkbox" class="custom-control-input" id="utility_<?= (int) $uId ?>" name="utilities[]"
          value="<?= (int) $uId ?>" <?= $isChecked ? 'checked' : '' ?>>
        <label class="custom-control-label font-weight-bold"
          for="utility_<?= (int) $uId ?>"><?= h($u['utility_name']) ?></label>
      </div>
    </div>
    <div class="col-md-8">
      <input type="text" class="form-control form-control-sm" name="billing_policy[<?= (int) $uId ?>]"
        value="<?= h($currentPolicy) ?>" list="policy-presets"
        placeholder="e.g. Included in Rent, Separate Meter, Split Equally...">
    </div>
  </div>
<?php endforeach; ?>
<datalist id="policy-presets">
  <?php foreach ($policyPresets as $preset): ?>
    <option value="<?= h($preset) ?>">
  <?php endforeach; ?>
</datalist>

<hr>

<h5 class="font-weight-bold text-primary mb-1">
  <i class="fas fa-images mr-1"></i> Property Photographs
</h5>
<p class="text-muted small mb-3">
  Upload high quality images of the room, facade, or facilities. You can pick several at
  once &mdash; JPG, PNG or WEBP, up to <?= format_bytes(max_upload_bytes()) ?> each and
  <?= max_photos_per_upload() ?> per upload.
  <?php if (empty($existingImages)): ?>The first photo becomes the cover.<?php endif; ?>
</p>

<div class="form-group">
  <label for="photos"><?= !empty($listing['boarding_house_id']) ? 'Add more photos' : 'Upload photos' ?></label>
  <input type="file" class="form-control-file" id="photos" name="photos[]"
    accept="image/jpeg,image/png,image/webp" multiple>
</div>
