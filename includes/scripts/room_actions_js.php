<?php
/**
 * Makes the Rooms card's buttons (slots − / +, Close / Reopen, delete) save
 * without a page reload. Included after panel_footer.php, so toastr is there.
 * Every button is a real form, so all of this is optional.
 */
?>
<script>
  (function () {
    function notify(type, message) {
      if (window.toastr) {
        toastr.options = { closeButton: true, timeOut: 2500, escapeHtml: true, positionClass: 'toast-bottom-right' };
        toastr[type](message);
      }
    }

    function paint(row, room) {
      row.querySelector('[data-slots-text]').textContent = room.slots_taken + ' / ' + room.capacity;
      row.querySelector('[data-slot-minus]').disabled = room.slots_taken <= 0;
      row.querySelector('[data-slot-plus]').disabled = room.slots_taken >= room.capacity;

      var badge = row.querySelector('[data-room-state]');
      badge.className = 'badge ' + room.state_badge + ' px-2 py-1';
      badge.textContent = room.state_label;

      var toggle = row.querySelector('[data-toggle-open]');
      toggle.textContent = room.is_open ? 'Close' : 'Reopen';
      toggle.title = room.is_open ? 'Stop taking tenants in this room' : 'Take tenants in this room again';
    }

    document.addEventListener('submit', function (e) {
      var form = e.target.closest ? e.target.closest('form[data-room-action]') : null;
      if (!form) return;

      var question = form.getAttribute('data-confirm');
      if (question && !window.confirm(question)) {
        e.preventDefault();
        return;
      }

      e.preventDefault();
      var row = form.closest('[data-room-row]');
      var buttons = row.querySelectorAll('button');
      Array.prototype.forEach.call(buttons, function (b) { b.dataset.wasDisabled = b.disabled ? '1' : ''; b.disabled = true; });

      // getAttribute, not form.action: these forms have a field named
      // "action", and form.action returns that field instead of the URL.
      fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          Array.prototype.forEach.call(buttons, function (b) { b.disabled = b.dataset.wasDisabled === '1'; });
          if (!data.ok) {
            notify('error', data.message || 'That did not work. Please try again.');
            if (data.reload) setTimeout(function () { window.location.reload(); }, 1500);
            return;
          }
          if (data.summary) {
            var summary = document.querySelector('[data-rooms-summary]');
            if (summary) summary.textContent = data.summary;
          }
          if (data.deleted) {
            row.remove();
            if (data.reload) {
              window.location.reload();
              return;
            }
          } else if (data.room) {
            paint(row, data.room);
          }
          notify('success', data.message);
        })
        .catch(function () {
          // Offline, or not JSON: send the form the ordinary way instead.
          form.removeAttribute('data-confirm');
          HTMLFormElement.prototype.submit.call(form);
        });
    });
  })();
</script>
