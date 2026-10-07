<?php
/**
 * Approve / Reject / Remove / Restore on Manage Listings without reloading.
 * Include after panel_footer.php. Row buttons and the Reject and Remove
 * dialogs (forms with data-listing-action) send in the background; the row
 * is redrawn from the server's copy, or taken out if it no longer belongs in
 * this tab, and the tab counts follow. The screen changes only once the
 * server confirms; if the request can't be made, the form is sent the
 * ordinary way, so every button still works.
 */
?>
<script>
  (function () {
    function notify(type, message) {
      if (window.toastr) {
        // toastr announces each message to screen readers (aria-live).
        toastr.options = { closeButton: true, timeOut: 4000, escapeHtml: true, positionClass: 'toast-top-right' };
        toastr[type](message);
      }
    }

    function table() {
      return window.jQuery && jQuery.fn.dataTable && jQuery.fn.dataTable.isDataTable('#listingsTable')
        ? jQuery('#listingsTable').DataTable() : null;
    }

    function setBusy(form, busy) {
      var button = form.querySelector('button[type="submit"]');
      if (!button) return;
      button.disabled = busy;
      button.setAttribute('aria-busy', busy ? 'true' : 'false');
      var icon = button.querySelector('[data-action-icon]');
      if (icon) {
        // Row buttons: their icon turns into a spinner.
        if (busy) {
          icon.dataset.iconClass = icon.className;
          icon.className = 'fas fa-spinner fa-spin' + (/\bmr-1\b/.test(icon.className) ? ' mr-1' : '');
        } else if (icon.dataset.iconClass) {
          icon.className = icon.dataset.iconClass;
        }
      } else if (busy) {
        // Dialog buttons: a spinner goes in front of the label.
        button.insertAdjacentHTML('afterbegin', '<i class="fas fa-spinner fa-spin mr-1" data-busy-spinner></i>');
      } else {
        var spinner = button.querySelector('[data-busy-spinner]');
        if (spinner) spinner.remove();
      }
    }

    function updateTally(tally) {
      Object.keys(tally || {}).forEach(function (key) {
        Array.prototype.forEach.call(document.querySelectorAll('[data-tally="' + key + '"]'), function (el) {
          el.textContent = tally[key];
        });
      });
      // "Review n pending" only makes sense while something is waiting.
      var review = document.querySelector('[data-review-pending]');
      if (review && tally && tally.pending === 0) review.remove();
    }

    // Redraw the row from the server's HTML, or fade it out of this tab.
    function updateRow(id, data) {
      var row = document.querySelector('tr[data-listing-row="' + id + '"]');
      var dt = table();
      if (!row || !dt) return;

      if (data.in_view && data.row) {
        var holder = document.createElement('tbody');
        holder.innerHTML = data.row.trim();
        var fresh = holder.firstElementChild;
        dt.row(row).remove();
        dt.row.add(fresh);
        dt.draw(false);
        var first = fresh.querySelector('button:not([disabled]), a.btn');
        if (first) first.focus();
        return;
      }

      row.style.transition = 'opacity .3s';
      row.style.opacity = '0';
      setTimeout(function () { dt.row(row).remove().draw(false); }, 300);
    }

    document.addEventListener('submit', function (e) {
      var form = e.target.closest ? e.target.closest('form[data-listing-action]') : null;
      if (!form) return;
      e.preventDefault();

      var inDialog = form.getAttribute('data-listing-action') === 'modal';
      var id = inDialog
        ? form.querySelector('.js-modal-id').value
        : form.getAttribute('data-listing-action');
      var modal = inDialog ? form.closest('.modal') : null;
      var errorBox = inDialog ? form.querySelector('[data-modal-error]') : null;
      if (errorBox) errorBox.classList.add('d-none');

      setBusy(form, true);

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
          if (data.redirect) {
            window.location.href = data.redirect;
            return;
          }
          setBusy(form, false);
          if (!data.ok) {
            var message = data.message || 'That did not work. Please try again.';
            // In a dialog the error stays beside what was typed, so it can be fixed.
            if (errorBox && !data.reload) {
              errorBox.textContent = message;
              errorBox.classList.remove('d-none');
            } else {
              notify('error', message);
            }
            if (data.reload) setTimeout(function () { window.location.reload(); }, 1500);
            return;
          }

          if (modal && window.jQuery) jQuery(modal).modal('hide');
          updateRow(id, data);
          updateTally(data.tally);
          notify('success', data.message);
        })
        .catch(function () {
          // Offline, or the reply wasn't JSON: send the form the ordinary way.
          HTMLFormElement.prototype.submit.call(form);
        });
    });
  })();
</script>
