<?php
/**
 * One row of the Manage Listings table, with the query and tab counts it
 * needs. Manage Listings draws its rows here, and so does
 * admin/listing_action.php when it answers fetch(), so a row swapped in
 * after Approve / Reject always matches the rest of the table.
 */

/** The SELECT for the table (add WHERE / ORDER BY): listing, rooms, cover, landlord, who removed it. */
function admin_listings_select()
{
    return "SELECT bh.*, " . ROOM_SUMMARY_COLUMNS . ",
                   " . COVER_PHOTO_SELECT . ",
                   CONCAT(u.first_name, ' ', u.last_name) AS landlord_name,
                   u.email AS landlord_email,
                   u.is_active AS landlord_active,
                   u.deleted_at AS landlord_deleted_at,
                   CONCAT(del.first_name, ' ', del.last_name) AS deleted_by_name, del.role AS deleted_by_role
              FROM boarding_houses bh
              JOIN users u ON u.user_id = bh.landlord_id
              LEFT JOIN users del ON del.user_id = bh.deleted_by
              " . room_summary_join();
}

/** Counts for the status tabs, the Removed button and "Review n pending". */
function admin_listing_tally()
{
    global $pdo;
    $tally = $pdo->query(
        "SELECT SUM(deleted_at IS NULL) AS all_listings,
                SUM(deleted_at IS NULL AND moderation_status = 'pending')  AS pending,
                SUM(deleted_at IS NULL AND moderation_status = 'approved') AS approved,
                SUM(deleted_at IS NULL AND moderation_status = 'rejected') AS rejected,
                SUM(deleted_at IS NOT NULL) AS removed
           FROM boarding_houses"
    )->fetch();
    return array_map('intval', $tally);
}

/** Whether a listing belongs in the tab being shown (Removed view, or a status filter or All). */
function admin_listing_in_view(array $l, $showRemoved, $statusFilter)
{
    if ($showRemoved) {
        return $l['deleted_at'] !== null;
    }
    return $l['deleted_at'] === null && ($statusFilter === '' || $l['moderation_status'] === $statusFilter);
}

/** The table row. Approve and Restore are sent without a reload (listing_actions_js.php). */
function admin_listing_row(array $l, $showRemoved, $statusFilter)
{
    $id = (int) $l['boarding_house_id'];
    $avail = listing_availability($l);
    // A listing whose landlord is removed or deactivated is off the public
    // site whatever its approval says, so the table says so too.
    $landlordLive = $l['landlord_deleted_at'] === null && (int) $l['landlord_active'] === 1;
    ob_start();
    ?>
    <tr data-listing-row="<?= $id ?>">
      <td class="font-weight-bold">
        <span class="d-flex align-items-center" style="gap: 10px;">
          <?= listing_thumb_html($l) ?>
          <a href="<?= base_url('admin/listing.php?id=' . $id) ?>" title="Review this listing">
            <?= h($l['name']) ?>
          </a>
        </span>
      </td>
      <td>
        <span class="font-weight-bold"><?= h($l['landlord_name']) ?></span>
        <br>
        <small class="text-muted"><?= h($l['contact_number']) ?></small>
        <?php if ($l['landlord_deleted_at'] !== null): ?>
          <br><span class="badge badge-dark">Landlord removed</span>
        <?php elseif (!$landlordLive): ?>
          <br><span class="badge badge-secondary">Landlord deactivated</span>
        <?php endif; ?>
      </td>
      <td>
        <i class="fas fa-map-marker-alt text-danger mr-1"></i>
        <?= h($l['address']) ?>
      </td>
      <td data-order="<?= (int) $avail['room_count'] ?>">
        <?php if ($avail['room_count'] === 0): ?>
          <span class="badge badge-warning px-2 py-1">No rooms yet</span>
          <br><small class="text-muted">Cannot be approved until it has one</small>
        <?php else: ?>
          <span class="font-weight-bold"><?= h($avail['summary']) ?></span>
          <br>
          <small class="text-muted">
            From &#8369;<?= number_format((float) $avail['rent_from'], 2) ?> &middot; <?= h($l['room_types']) ?>
          </small>
        <?php endif; ?>
      </td>
      <td>
        <?= moderation_badge($l['moderation_status']) ?>
        <?php if ($l['moderation_status'] === 'rejected' && $l['rejection_reason']): ?>
          <br>
          <small class="text-muted" title="<?= h($l['rejection_reason']) ?>">
            <?= h(mb_strimwidth($l['rejection_reason'], 0, 40, '...')) ?>
          </small>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($showRemoved): ?>
          <?php /* Who archived it: a landlord deleting their own listing reads
               differently from an administrator taking it down. */ ?>
          <?php if ($l['deleted_by_role'] === 'landlord'): ?>
            <span class="badge badge-secondary px-2 py-1"><i class="fas fa-trash mr-1"></i> Deleted by the landlord</span>
          <?php else: ?>
            <span class="badge badge-dark px-2 py-1"><i class="fas fa-archive mr-1"></i> Removed by an administrator</span>
          <?php endif; ?>
          <?php if ($l['deleted_by_name'] !== null && $l['deleted_by_role'] !== 'landlord'): ?>
            <small class="text-muted d-block mt-1">by <?= h($l['deleted_by_name']) ?></small>
          <?php endif; ?>
        <?php elseif ($l['moderation_status'] === 'approved'): ?>
          <span class="badge badge-success px-2 py-1"><i class="fas fa-eye mr-1"></i> Shown</span>
        <?php else: ?>
          <span class="badge badge-secondary px-2 py-1"><i class="fas fa-eye-slash mr-1"></i>
            Not shown</span>
        <?php endif; ?>
      </td>
      <td class="text-sm text-muted" data-order="<?= h($showRemoved ? $l['deleted_at'] : $l['created_at']) ?>">
        <?= h(date('M j, Y', strtotime($showRemoved ? $l['deleted_at'] : $l['created_at']))) ?>
      </td>
      <td>
        <div class="d-flex align-items-center flex-wrap" style="gap: 5px;">
          <?php if ($showRemoved): ?>
            <form method="post" action="<?= base_url('admin/listing_action.php') ?>" class="d-inline" data-listing-action="<?= $id ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="boarding_house_id" value="<?= $id ?>">
              <input type="hidden" name="action" value="restore">
              <input type="hidden" name="return_view" value="removed">
              <button type="submit" class="btn btn-xs btn-outline-success" title="Restore Listing">
                <i class="fas fa-trash-restore mr-1" data-action-icon></i> Restore
              </button>
            </form>
          <?php else: ?>
            <?php if ($l['moderation_status'] !== 'approved'): ?>
              <?php
              // Approve only once the listing has a room to show and its landlord is live.
              $approveBlocked = $avail['room_count'] === 0
                ? 'Needs at least one room before it can be approved'
                : (!$landlordLive ? 'The landlord\'s account is removed or deactivated' : '');
              ?>
              <form method="post" action="<?= base_url('admin/listing_action.php') ?>" class="d-inline" data-listing-action="<?= $id ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="boarding_house_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="return_status" value="<?= h($statusFilter) ?>">
                <button type="submit" class="btn btn-xs btn-outline-success"
                  title="<?= h($approveBlocked !== '' ? $approveBlocked : 'Approve Listing') ?>"
                  aria-label="<?= h('Approve ' . $l['name']) ?>"
                  <?= $approveBlocked !== '' ? 'disabled' : '' ?>>
                  <i class="fas fa-check" data-action-icon></i>
                </button>
              </form>
            <?php endif; ?>

            <?php if ($l['moderation_status'] !== 'rejected'): ?>
              <!-- Reject Button (opens the reason dialog) -->
              <button type="button" class="btn btn-xs btn-outline-warning js-reject"
                data-id="<?= $id ?>" data-name="<?= h($l['name']) ?>"
                title="Reject Listing" aria-label="<?= h('Reject ' . $l['name']) ?>">
                <i class="fas fa-ban"></i>
              </button>
            <?php endif; ?>
          <?php endif; ?>

          <!-- View Button -->
          <a href="<?= base_url('boarder/view_listing.php?id=' . $id) ?>" target="_blank"
            class="btn btn-xs btn-outline-info" title="Preview on Website">
            <i class="fas fa-eye"></i>
          </a>

          <?php if (!$showRemoved): ?>
            <!-- Remove Button (opens the removal dialog) -->
            <button type="button" class="btn btn-xs btn-outline-danger js-remove"
              data-id="<?= $id ?>" data-name="<?= h($l['name']) ?>"
              title="Remove Listing" aria-label="<?= h('Remove ' . $l['name']) ?>">
              <i class="fas fa-trash"></i>
            </button>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php
    return ob_get_clean();
}
