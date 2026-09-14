<?php
/**
 * The Rooms section of the Add Listing form: one card per room, with its own
 * photos, plus "Add another room". Included by includes/listing_form.php when
 * $formRooms is set, which only landlord/add_listing.php does; an existing
 * listing's rooms are managed on its Rooms card instead.
 *
 * Expects:
 *   $formRooms        index => room fields (blank_room() / room_from_input()).
 *                     Indexes need not be contiguous: they only pair each
 *                     room's fields with its photo input, room_photos_<index>.
 *   $formRoomsPosted  true when the form is being shown again after a failed
 *                     submit, so file inputs (which browsers empty) need a note
 */
$formRoomTypes = room_type_options();
$formRoomsPosted = $formRoomsPosted ?? false;

/** One room card. $idx is a number, or the literal "__INDEX__" for the template. */
$renderRoomCard = function ($idx, array $room) use ($formRoomTypes) {
    $id = fn($field) => 'room_' . $idx . '_' . $field;
    $name = fn($field) => 'rooms[' . $idx . '][' . $field . ']';
    ?>
    <div class="card card-outline card-secondary shadow-none mb-3" data-room-row>
      <div class="card-header py-2 d-flex align-items-center">
        <h6 class="mb-0 font-weight-bold">
          <i class="fas fa-door-open text-primary mr-1"></i>
          <span data-room-title><?= h($room['name'] !== '' ? $room['name'] : 'New room') ?></span>
        </h6>
        <button type="button" class="btn btn-xs btn-outline-danger ml-auto" data-room-remove>
          <i class="fas fa-times mr-1"></i> Remove
        </button>
      </div>
      <div class="card-body pb-1">
        <div class="form-row">
          <div class="col-md-6 form-group">
            <label for="<?= $id('name') ?>">Room name</label>
            <input type="text" class="form-control" id="<?= $id('name') ?>" name="<?= $name('name') ?>" maxlength="60"
              value="<?= h($room['name']) ?>" placeholder="e.g. Room 1, 2nd floor front" required data-room-name>
          </div>
          <div class="col-md-6 form-group">
            <label for="<?= $id('type') ?>">Room type</label>
            <select class="form-control" id="<?= $id('type') ?>" name="<?= $name('room_type_id') ?>" required>
              <option value="">Choose a type</option>
              <?php foreach ($formRoomTypes as $rtId => $rtName): ?>
                <option value="<?= (int) $rtId ?>" <?= (string) $room['room_type_id'] === (string) $rtId ? 'selected' : '' ?>><?= h($rtName) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="col-md-4 form-group">
            <label for="<?= $id('rent') ?>">Monthly rent (&#8369;)</label>
            <input type="number" step="0.01" min="0" class="form-control" id="<?= $id('rent') ?>"
              name="<?= $name('monthly_rent') ?>" value="<?= h($room['monthly_rent']) ?>" placeholder="e.g. 2500" required>
            <small class="form-text text-muted">Per person for bed spacers and dorms.</small>
          </div>
          <div class="col-md-4 form-group">
            <label for="<?= $id('capacity') ?>">Capacity (people)</label>
            <input type="number" step="1" min="1" max="100" class="form-control" id="<?= $id('capacity') ?>"
              name="<?= $name('capacity') ?>" value="<?= h($room['capacity']) ?>" required>
          </div>
          <div class="col-md-4 form-group">
            <label for="<?= $id('slots') ?>">Slots taken</label>
            <input type="number" step="1" min="0" max="100" class="form-control" id="<?= $id('slots') ?>"
              name="<?= $name('slots_taken') ?>" value="<?= h($room['slots_taken']) ?>" required>
            <small class="form-text text-muted">Tenants already living here.</small>
          </div>
        </div>

        <div class="form-group">
          <div class="custom-control custom-switch">
            <input type="checkbox" class="custom-control-input" id="<?= $id('open') ?>" name="<?= $name('is_open') ?>"
              value="1" <?= $room['is_open'] ? 'checked' : '' ?>>
            <label class="custom-control-label" for="<?= $id('open') ?>">Open to new tenants</label>
          </div>
        </div>

        <div class="form-row">
          <div class="col-md-7 form-group">
            <label for="<?= $id('description') ?>">Short description <span class="text-muted font-weight-normal">(optional)</span></label>
            <textarea class="form-control" id="<?= $id('description') ?>" name="<?= $name('description') ?>" rows="2"
              maxlength="500" placeholder="e.g. Window facing the garden, own cabinet."><?= h($room['description']) ?></textarea>
          </div>
          <div class="col-md-5 form-group">
            <label for="<?= $id('photos') ?>">Room photos <span class="text-muted font-weight-normal">(optional)</span></label>
            <input type="file" class="form-control-file" id="<?= $id('photos') ?>" name="room_photos_<?= $idx ?>[]"
              accept="image/jpeg,image/png,image/webp" multiple>
            <small class="form-text text-muted">The first photo becomes the room's main photo.</small>
          </div>
        </div>
      </div>
    </div>
    <?php
};
?>

<hr>

<h5 class="font-weight-bold text-primary mb-1" id="rooms">
  <i class="fas fa-door-open mr-1"></i> Rooms
</h5>
<p class="text-muted small mb-3">
  Add every room in this boarding house, each with its own type, rent, capacity, and photos. At least one room
  is needed. You can add more rooms and update slots taken later from the listing page.
</p>

<?php if (!$formRoomTypes): ?>
  <?= lookup_unavailable_notice('room types', 'room_types') ?>
<?php endif; ?>

<?php if ($formRoomsPosted): ?>
  <div class="alert alert-info py-2 small">
    <i class="fas fa-info-circle mr-1"></i> Your room details are kept, but browsers clear chosen photos when a form
    is shown again. Choose the photos again before saving.
  </div>
<?php endif; ?>

<div data-room-rows>
  <?php foreach ($formRooms as $idx => $room): ?>
    <?php $renderRoomCard((int) $idx, $room); ?>
  <?php endforeach; ?>
</div>

<button type="button" class="btn btn-outline-primary mb-3" data-room-add hidden>
  <i class="fas fa-plus mr-1"></i> Add another room
</button>

<template data-room-template>
  <?php $renderRoomCard('__INDEX__', blank_room()); ?>
</template>

<script>
  // Add and remove room cards. Without JavaScript the form still submits the
  // rooms already on the page; more can be added from the listing page.
  (function () {
    var rows = document.querySelector('[data-room-rows]');
    var addBtn = document.querySelector('[data-room-add]');
    var template = document.querySelector('[data-room-template]');
    if (!rows || !addBtn || !template) return;

    var nextIndex = 0;
    Array.prototype.forEach.call(rows.querySelectorAll('[name^="rooms["]'), function (input) {
      var match = input.name.match(/^rooms\[(\d+)\]/);
      if (match) nextIndex = Math.max(nextIndex, Number(match[1]) + 1);
    });

    function cards() {
      return rows.querySelectorAll('[data-room-row]');
    }

    function refresh() {
      var only = cards().length === 1;
      Array.prototype.forEach.call(cards(), function (card) {
        card.querySelector('[data-room-remove]').hidden = only;
      });
    }

    function freeName() {
      var used = Array.prototype.map.call(rows.querySelectorAll('[data-room-name]'), function (input) {
        return input.value.trim().toLowerCase();
      });
      var n = cards().length + 1;
      while (used.indexOf('room ' + n) !== -1) n++;
      return 'Room ' + n;
    }

    addBtn.hidden = false;
    addBtn.addEventListener('click', function () {
      var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
      var holder = document.createElement('div');
      holder.innerHTML = html.trim();
      var card = holder.firstElementChild;
      var name = card.querySelector('[data-room-name]');
      name.value = freeName();
      card.querySelector('[data-room-title]').textContent = name.value;
      rows.appendChild(card);
      refresh();
      name.focus();
      name.select();
    });

    rows.addEventListener('click', function (e) {
      var remove = e.target.closest('[data-room-remove]');
      if (!remove || cards().length === 1) return;
      remove.closest('[data-room-row]').remove();
      refresh();
    });

    // The card heading follows the room's name as it is typed.
    rows.addEventListener('input', function (e) {
      if (!e.target.matches('[data-room-name]')) return;
      e.target.closest('[data-room-row]').querySelector('[data-room-title]').textContent =
        e.target.value.trim() || 'New room';
    });

    refresh();
  })();
</script>
