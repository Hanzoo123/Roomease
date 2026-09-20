<?php
/**
 * The "My Boarding Houses" card: a landlord's listings with their rooms,
 * photos, approval, and actions. Shown on landlord/dashboard.php and
 * landlord/listings.php.
 *
 * Expects:
 *   $listings  rows from landlord_listings()
 */
?>
<div class="card card-primary card-outline shadow-sm" id="myListings">
  <?php panel_card_header(
    'My boarding houses',
    'Each listing, its rooms, and whether boarders can see it yet.',
    '<a href="' . base_url('landlord/add_listing.php') . '" class="btn btn-sm btn-primary">'
      . '<i class="fas fa-plus mr-1"></i> Add listing</a>'
  ); ?>

  <div class="card-body<?= $listings ? '' : ' p-0' ?>">
    <?php if (!$listings): ?>
      <?= re_empty(
        'No boarding houses yet',
        'Post your first listing and an administrator will review it before boarders can see it.',
        'fa-house-user',
        '<a href="' . base_url('landlord/add_listing.php') . '" class="btn btn-primary btn-sm">'
          . '<i class="fas fa-plus mr-1"></i> Create your first listing</a>'
      ) ?>
    <?php else: ?>
      <table id="listingsTable" class="table table-bordered table-striped table-hover">
        <thead>
          <tr>
            <th>Boarding House</th>
            <th>Address</th>
            <th>Rooms</th>
            <th>Photos</th>
            <th>Approval</th>
            <th>Website</th>
            <th>Posted Date</th>
            <th style="width: 130px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($listings as $l): ?>
            <tr>
              <td class="font-weight-bold">
                <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" target="_blank"
                  class="text-dark" title="View Listing Details">
                  <i class="fas fa-external-link-alt text-xs text-primary mr-1"></i>
                  <?= h($l['name']) ?>
                </a>
              </td>
              <td>
                <i class="fas fa-map-marker-alt text-danger mr-1"></i>
                <?= h($l['address']) ?>
              </td>
              <?php $avail = listing_availability($l); ?>
              <td data-order="<?= (int) $avail['room_count'] ?>">
                <?php if ($avail['room_count'] === 0): ?>
                  <a href="<?= base_url('landlord/room_form.php?house=' . $l['boarding_house_id']) ?>"
                    class="badge badge-warning px-2 py-1">
                    <i class="fas fa-plus mr-1"></i> Add a room
                  </a>
                  <br><small class="text-muted">Hidden until it has one</small>
                <?php else: ?>
                  <a href="<?= base_url('landlord/edit_listing.php?id=' . $l['boarding_house_id']) ?>#rooms"
                    class="font-weight-bold"><?= h($avail['summary']) ?></a>
                  <br>
                  <small class="text-muted">
                    From &#8369;<?= number_format((float) $avail['rent_from'], 2) ?>
                    &middot; <?= h($l['room_types']) ?>
                  </small>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int) $l['photo_count'] === 0): ?>
                  <span class="badge badge-warning px-2 py-1"><i class="fas fa-image mr-1"></i> None</span>
                <?php else: ?>
                  <span class="badge badge-secondary px-2 py-1"><i class="fas fa-images mr-1"></i>
                    <?= (int) $l['photo_count'] ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?= moderation_badge($l['moderation_status']) ?>
                <?php if ($l['moderation_status'] === 'rejected' && $l['rejection_reason']): ?>
                  <br>
                  <small class="text-danger d-inline-block mt-1" style="max-width:220px;">
                    <i class="fas fa-info-circle mr-1"></i><?= h($l['rejection_reason']) ?>
                  </small>
                <?php elseif ($l['moderation_status'] === 'pending'): ?>
                  <br>
                  <small class="text-muted">Hidden until approved</small>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($l['availability_status'] === 'available'): ?>
                  <span class="badge badge-success px-2 py-1"><i class="fas fa-eye mr-1"></i> Shown</span>
                <?php else: ?>
                  <span class="badge badge-secondary px-2 py-1"><i class="fas fa-eye-slash mr-1"></i>
                    Hidden</span>
                <?php endif; ?>
              </td>
              <td class="text-sm text-muted" data-order="<?= h($l['created_at']) ?>">
                <?= h(date('M j, Y', strtotime($l['created_at']))) ?>
              </td>
              <td>
                <div class="d-flex align-items-center" style="gap: 5px;">
                  <!-- Edit Button -->
                  <a href="<?= base_url('landlord/edit_listing.php?id=' . $l['boarding_house_id']) ?>"
                    class="btn btn-xs btn-outline-primary" title="Edit Listing">
                    <i class="fas fa-edit"></i>
                  </a>

                  <!-- Rooms Button -->
                  <a href="<?= base_url('landlord/edit_listing.php?id=' . $l['boarding_house_id']) ?>#rooms"
                    class="btn btn-xs btn-outline-secondary" title="Rooms and tenants">
                    <i class="fas fa-door-open"></i>
                  </a>

                  <!-- View Button -->
                  <a href="<?= base_url('boarder/view_listing.php?id=' . $l['boarding_house_id']) ?>" target="_blank"
                    class="btn btn-xs btn-outline-info" title="Preview on Website">
                    <i class="fas fa-eye"></i>
                  </a>

                  <!-- Delete Button -->
                  <form method="post" action="<?= base_url('landlord/delete_listing.php') ?>" class="d-inline js-confirm"
                    data-confirm="Delete &quot;<?= h($l['name']) ?>&quot;? This cannot be undone.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="boarding_house_id" value="<?= (int) $l['boarding_house_id'] ?>">
                    <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete Listing">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <script>
        // jQuery and DataTables load at the end of the page (panel_footer.php),
        // and have run by the time the document has finished loading.
        document.addEventListener('DOMContentLoaded', function () {
          $("#listingsTable").DataTable({
            "responsive": true,
            "lengthChange": true,
            "autoWidth": false,
            "order": [[6, "desc"]],
            "pageLength": 10
          });
        });
      </script>
    <?php endif; ?>
  </div>
  <!-- /.card-body -->
</div>
<!-- /.card -->
