<?php
/**
 * The Reject and Remove dialogs for listings, shared by Manage Listings and a
 * listing's review page. One of each serves every row: a button with the
 * class js-reject or js-remove, carrying data-id and data-name, fills it in
 * and opens it.
 *
 * Set before including:
 *   $returnStatus    optional; the approval tab to come back to
 *   $returnTo        optional; 'review' to come back to the listing's review
 *                    page, or 'next' to go on to the next listing waiting
 *   $rejectReturnTo  optional; where rejecting goes when that differs from
 *                    where removing goes. The review page sends a rejection on
 *                    to the next listing in the queue but keeps a removal in
 *                    place, because a removal is worth seeing land.
 *
 * Include it before panel_footer.php. Its script waits for DOMContentLoaded,
 * by which time the footer has loaded jQuery and Bootstrap.
 */
$returnStatus = $returnStatus ?? '';
$returnTo = $returnTo ?? '';
$rejectReturnTo = $rejectReturnTo ?? $returnTo;
?>
<div class="modal fade" id="rejectModal" tabindex="-1" role="dialog" aria-labelledby="rejectModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form method="post" action="<?= base_url('admin/listing_action.php') ?>">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="rejectModalLabel">Reject listing</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="return_status" value="<?= h($returnStatus) ?>">
          <input type="hidden" name="return_to" value="<?= h($rejectReturnTo) ?>">
          <input type="hidden" name="boarding_house_id" class="js-modal-id" value="">
          <p class="mb-3">Rejecting <strong class="js-modal-name"></strong>. It stays hidden from boarders until the
            landlord fixes it and it is approved.</p>
          <div class="form-group mb-0">
            <label for="rejection_reason">What the landlord needs to change</label>
            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" maxlength="500"
              placeholder="e.g. The address is incomplete, or the photos do not show the actual room." required></textarea>
            <small class="form-text text-muted">The landlord is emailed this reason and sees it on their dashboard.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning">Reject listing</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="removeModal" tabindex="-1" role="dialog" aria-labelledby="removeModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form method="post" action="<?= base_url('admin/listing_action.php') ?>">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="removeModalLabel">Remove listing</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove">
          <input type="hidden" name="return_status" value="<?= h($returnStatus) ?>">
          <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
          <input type="hidden" name="boarding_house_id" class="js-modal-id" value="">
          <p class="mb-3">Removing <strong class="js-modal-name"></strong> hides it from boarders and from its landlord.
            Nothing is deleted, and it can be restored from the Removed tab.</p>
          <div class="form-group mb-0">
            <label for="removal_reason">Reason <span class="text-muted font-weight-normal">(optional)</span></label>
            <textarea class="form-control" id="removal_reason" name="removal_reason" rows="3" maxlength="500"
              placeholder="e.g. Duplicate of another listing, or the property no longer exists."></textarea>
            <small class="form-text text-muted">If given, the landlord is emailed this reason.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Remove listing</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    // Fill the shared dialog with whichever listing's button was clicked.
    function wire(buttonClass, modalId) {
      $(document).on('click', buttonClass, function () {
        var modal = $(modalId);
        modal.find('.js-modal-id').val($(this).data('id'));
        modal.find('.js-modal-name').text($(this).data('name'));
        modal.find('textarea').val('');
        modal.modal('show');
      });
    }
    wire('.js-reject', '#rejectModal');
    wire('.js-remove', '#removeModal');
  });
</script>
