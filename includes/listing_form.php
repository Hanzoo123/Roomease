<?php
/**
 * Shared listing form fields. Included by landlord/add_listing.php and
 * landlord/edit_listing.php. Expects:
 *   $listing        - assoc array of existing values, or [] for a new listing
 *   $selectedAmens  - array of amenity strings currently selected
 */
$listing = $listing ?? [];
$selectedAmens = $selectedAmens ?? [];
$val = fn($key, $default = '') => h($listing[$key] ?? $default);
?>
<label for="boarding_house_name">Boarding house name</label>
<input type="text" id="boarding_house_name" name="boarding_house_name" value="<?= $val('boarding_house_name') ?>" required>

<div class="field-row">
  <div>
    <label for="address">Complete address</label>
    <input type="text" id="address" name="address" value="<?= $val('address') ?>" placeholder="Street, Barangay" required>
  </div>
  <div>
    <label for="city">City / Municipality</label>
    <input type="text" id="city" name="city" value="<?= $val('city') ?>" required>
  </div>
</div>

<div class="field-row">
  <div>
    <label for="monthly_rent">Monthly rent (₱)</label>
    <input type="number" step="0.01" min="0" id="monthly_rent" name="monthly_rent" value="<?= $val('monthly_rent') ?>" required>
  </div>
  <div>
    <label for="reservation_fee">Reservation fee (₱, optional)</label>
    <input type="number" step="0.01" min="0" id="reservation_fee" name="reservation_fee" value="<?= $val('reservation_fee') ?>">
  </div>
</div>

<div class="field-row">
  <div>
    <label for="room_type">Room type</label>
    <select id="room_type" name="room_type" required>
      <?php foreach (['Private Room', 'Bed Spacer'] as $rt): ?>
        <option value="<?= h($rt) ?>" <?= ($listing['room_type'] ?? '') === $rt ? 'selected' : '' ?>><?= h($rt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="room_capacity">Room capacity (persons)</label>
    <input type="number" min="1" id="room_capacity" name="room_capacity" value="<?= $val('room_capacity', 1) ?>" required>
  </div>
</div>

<div class="field-row">
  <div>
    <label for="electricity_payment">Electricity payment terms</label>
    <input type="text" id="electricity_payment" name="electricity_payment" value="<?= $val('electricity_payment') ?>" placeholder="e.g. Submetered, ₱12/kWh">
  </div>
  <div>
    <label for="water_payment">Water payment terms</label>
    <input type="text" id="water_payment" name="water_payment" value="<?= $val('water_payment') ?>" placeholder="e.g. Included, flat ₱150/mo">
  </div>
</div>

<label>Amenities</label>
<div class="checkbox-grid">
  <?php foreach (amenity_options() as $a): ?>
    <label>
      <input type="checkbox" name="amenities[]" value="<?= h($a) ?>" <?= in_array($a, $selectedAmens, true) ? 'checked' : '' ?>>
      <?= h($a) ?>
    </label>
  <?php endforeach; ?>
</div>

<label for="house_rules">House rules</label>
<textarea id="house_rules" name="house_rules" placeholder="e.g. No visitors after 10PM, curfew 11PM..."><?= $val('house_rules') ?></textarea>

<label for="contact_info">Contact information</label>
<input type="text" id="contact_info" name="contact_info" value="<?= $val('contact_info') ?>" placeholder="Phone number, Facebook page, etc." required>

<?php if (isset($listing['status'])): ?>
<label for="status">Listing status</label>
<select id="status" name="status">
  <?php foreach (['available' => 'Available', 'full' => 'Full', 'inactive' => 'Inactive (hidden from search)'] as $val2 => $label): ?>
    <option value="<?= $val2 ?>" <?= $listing['status'] === $val2 ? 'selected' : '' ?>><?= $label ?></option>
  <?php endforeach; ?>
</select>
<?php endif; ?>

<label for="photo">Property photo <?= isset($listing['listing_id']) ? '(upload to add another)' : '' ?></label>
<input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
<p class="field-hint">JPG, PNG, or WEBP — max 5MB.</p>
