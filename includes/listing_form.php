<?php
/**
 * Shared boarding house form fields.
 * Included by landlord/add_listing.php and landlord/edit_listing.php.
 * Expects:
 *   $listing        - assoc array of existing values, or [] for new listing
 *   $selectedAmens  - array of amenity names currently selected
 *   $selectedUtils  - assoc array of utility_id => billing_policy
 *   $existingImages - array of existing images from images table (for edit mode)
 */
$listing        = $listing ?? [];
$selectedAmens  = $selectedAmens ?? [];
$selectedUtils  = $selectedUtils ?? [];
$existingImages = $existingImages ?? [];
$val = fn($key, $default = '') => h($listing[$key] ?? $default);

$commonRoomTypes = ['Single', 'Double', 'Dormitory', 'Private Room', 'Bed Spacer'];
$policyPresets = [
    'Included in Rent',
    'Separate Meter / Submeter',
    'Split Equally',
    'Fixed Monthly Rate',
    'Pay as you consume',
    'Not Available / Self-provided'
];
?>

<label for="name">Boarding house name</label>
<input type="text" id="name" name="name" value="<?= $val('name') ?>" placeholder="e.g. Greenview Student Dormitory" required>

<label for="address">Complete address (Baybay City)</label>
<input type="text" id="address" name="address" value="<?= $val('address') ?>" placeholder="e.g. Purok 3, Brgy. Pangasugan, Baybay City, Leyte" required>

<div class="field-row">
  <div>
    <label for="monthly_rent">Monthly rent (₱)</label>
    <input type="number" step="0.01" min="0" id="monthly_rent" name="monthly_rent" value="<?= $val('monthly_rent') ?>" placeholder="e.g. 3500.00" required>
  </div>
  <div>
    <label for="contact_number">Landlord contact number</label>
    <input type="text" id="contact_number" name="contact_number" value="<?= $val('contact_number') ?>" placeholder="e.g. 09171234567" required>
  </div>
</div>

<div class="field-row">
  <div>
    <label for="room_type">Room type</label>
    <select id="room_type" name="room_type" required>
      <?php foreach ($commonRoomTypes as $rt): ?>
        <option value="<?= h($rt) ?>" <?= ($listing['room_type'] ?? '') === $rt ? 'selected' : '' ?>><?= h($rt) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="room_capacity">Max occupants (capacity)</label>
    <input type="number" min="1" id="room_capacity" name="room_capacity" value="<?= $val('room_capacity', 1) ?>" required>
  </div>
</div>

<label for="availability_status">Availability status</label>
<select id="availability_status" name="availability_status">
  <option value="available" <?= ($listing['availability_status'] ?? 'available') === 'available' ? 'selected' : '' ?>>Available (Open for boarders)</option>
  <option value="unavailable" <?= ($listing['availability_status'] ?? '') === 'unavailable' ? 'selected' : '' ?>>Unavailable (Fully occupied / closed)</option>
</select>

<label for="description">Brief description</label>
<textarea id="description" name="description" rows="3" placeholder="Highlight location proximity, student atmosphere, building features..."><?= $val('description') ?></textarea>

<label for="house_rules">House rules</label>
<textarea id="house_rules" name="house_rules" rows="3" placeholder="e.g. Curfew at 10:00 PM, quiet study hours, no outside overnight guests..."><?= $val('house_rules') ?></textarea>

<hr style="border:0; border-top:1px dashed var(--line, #e2e8f0); margin:24px 0;">

<h3 style="font-size:16px; margin-bottom:8px;">Amenities Offered</h3>
<p class="field-hint" style="margin-top:-4px; margin-bottom:12px;">Select all amenities currently accessible to boarders:</p>
<div class="checkbox-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:8px; margin-bottom:20px;">
  <?php foreach (amenity_options() as $a): ?>
    <label style="display:flex; align-items:center; gap:8px; font-size:14px; margin-bottom:0; cursor:pointer;">
      <input type="checkbox" name="amenities[]" value="<?= h($a) ?>" <?= in_array($a, $selectedAmens, true) ? 'checked' : '' ?>>
      <?= h($a) ?>
    </label>
  <?php endforeach; ?>
</div>

<hr style="border:0; border-top:1px dashed var(--line, #e2e8f0); margin:24px 0;">

<h3 style="font-size:16px; margin-bottom:8px;">Utilities &amp; Billing Policies</h3>
<p class="field-hint" style="margin-top:-4px; margin-bottom:14px;">Define billing arrangements for each utility provided:</p>

<div style="display:flex; flex-direction:column; gap:12px; margin-bottom:24px;">
  <?php foreach (utility_options() as $u): 
    $uId = $u['utility_id'];
    $currentPolicy = $selectedUtils[$uId] ?? '';
    $isChecked = isset($selectedUtils[$uId]);
  ?>
    <div style="display:grid; grid-template-columns: 180px 1fr; gap:12px; align-items:center; background:rgba(0,0,0,0.02); padding:10px 14px; border-radius:6px; border:1px solid var(--board-border, #e2e8f0);">
      <label style="margin:0; font-weight:600; display:flex; align-items:center; gap:8px; cursor:pointer;">
        <input type="checkbox" name="utilities[]" value="<?= (int)$uId ?>" <?= $isChecked ? 'checked' : '' ?>>
        <?= h($u['utility_name']) ?>
      </label>
      <div>
        <input type="text" name="billing_policy[<?= (int)$uId ?>]" value="<?= h($currentPolicy) ?>" placeholder="e.g. Included in Rent, Separate Meter, Split Equally..." list="policy-presets" style="margin:0; font-size:13px; height:36px;">
      </div>
    </div>
  <?php endforeach; ?>
  <datalist id="policy-presets">
    <?php foreach ($policyPresets as $preset): ?>
      <option value="<?= h($preset) ?>">
    <?php endforeach; ?>
  </datalist>
</div>

<hr style="border:0; border-top:1px dashed var(--line, #e2e8f0); margin:24px 0;">

<h3 style="font-size:16px; margin-bottom:8px;">Property Photographs</h3>
<p class="field-hint" style="margin-top:-4px; margin-bottom:12px;">Upload high quality images of the room, facade, or facilities (JPG, PNG, WEBP — max 5MB each):</p>

<?php if (!empty($existingImages)): ?>
  <div style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
    <?php foreach ($existingImages as $img): ?>
      <div style="position:relative; width:120px; height:90px; border-radius:6px; overflow:hidden; border:2px solid <?= $img['is_primary'] ? '#28a745' : '#cbd5e1' ?>; background:#f1f5f9;">
        <img src="<?= h(base_url($img['image_path'])) ?>" alt="Photo" style="width:100%; height:100%; object-fit:cover;">
        <?php if ($img['is_primary']): ?>
          <span style="position:absolute; top:4px; left:4px; background:#28a745; color:#fff; font-size:10px; font-weight:bold; padding:2px 6px; border-radius:3px;">Cover</span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<label for="photo"><?= !empty($listing['boarding_house_id']) ? 'Add an image' : 'Upload cover image' ?></label>
<input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp">
