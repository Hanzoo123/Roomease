<?php
/**
 * Activate / Deactivate without reloading, on Manage Users and the account
 * page. Include after panel_footer.php. The screen changes only once the
 * server confirms; if the request can't be made, the form is sent the
 * ordinary way, so the button always works.
 */
?>
<script>
  (function () {
    function notify(type, message) {
      if (window.toastr) {
        // toastr announces each message to screen readers (aria-live).
        toastr.options = { closeButton: true, timeOut: 3000, escapeHtml: true, positionClass: 'toast-top-right' };
        toastr[type](message);
      }
    }

    // Swap every copy of an element: DataTables' responsive mode can show a
    // row's cells a second time in its expanded child row.
    function replaceAll(selector, html) {
      Array.prototype.forEach.call(document.querySelectorAll(selector), function (el) {
        el.outerHTML = html;
      });
    }

    // Let DataTables re-read the row, so sorting and search see the new status.
    function refreshTableRow(userId) {
      var row = document.querySelector('tr[data-user-row="' + userId + '"]');
      if (!row || !window.jQuery || !jQuery.fn.dataTable) return;
      var table = row.closest('table');
      if (table && jQuery.fn.dataTable.isDataTable(table)) {
        jQuery(table).DataTable().row(row).invalidate('dom');
      }
    }

    function setBusy(form, busy) {
      var button = form.querySelector('button[type="submit"]');
      var icon = form.querySelector('[data-status-icon]');
      if (!button) return;
      button.disabled = busy;
      button.setAttribute('aria-busy', busy ? 'true' : 'false');
      if (icon) {
        if (busy) {
          icon.dataset.iconClass = icon.className;
          icon.className = icon.className.replace(/fa-user-[a-z]+/, 'fa-spinner fa-spin');
        } else if (icon.dataset.iconClass) {
          icon.className = icon.dataset.iconClass;
        }
      }
    }

    document.addEventListener('submit', function (e) {
      var form = e.target.closest ? e.target.closest('form[data-user-action]') : null;
      if (!form) return;
      e.preventDefault();

      var question = form.getAttribute('data-confirm');
      if (question && !window.confirm(question)) return;

      var userId = form.getAttribute('data-user-action');
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
          if (!data.ok) {
            setBusy(form, false);
            notify('error', data.message || 'That did not work. Please try again.');
            if (data.reload) setTimeout(function () { window.location.reload(); }, 1500);
            return;
          }

          replaceAll('[data-user-status="' + userId + '"]', data.badge);
          replaceAll('form[data-user-action="' + userId + '"]', data.form);
          refreshTableRow(userId);

          // Keep keyboard focus on the button that replaced the one pressed.
          var next = document.querySelector('form[data-user-action="' + userId + '"] button');
          if (next) next.focus();

          notify('success', data.message);
        })
        .catch(function () {
          // Offline, or the reply wasn't JSON: send the form the ordinary way.
          // (submit() skips this listener, so it isn't asked again.)
          HTMLFormElement.prototype.submit.call(form);
        });
    });
  })();
</script>
