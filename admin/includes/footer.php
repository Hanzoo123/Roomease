<?php
/**
 * AdminLTE Footer Include for RoomEase Admin
 */
$flash = flash_get();
?>
  <!-- Main Footer -->
  <footer class="main-footer">
    <div class="float-right d-none d-sm-inline">
      RoomEase v1.0 &middot; Admin Panel
    </div>
    <strong>Copyright &copy; <?= date('Y') ?> <a href="<?= base_url('boarder/browse.php') ?>" target="_blank">RoomEase</a>.</strong> All rights reserved.
  </footer>
</div>
<!-- ./wrapper -->

<!-- REQUIRED SCRIPTS -->
<!-- jQuery -->
<script src="<?= base_url('AdminLTE/plugins/jquery/jquery.min.js') ?>"></script>
<!-- Bootstrap 4 -->
<script src="<?= base_url('AdminLTE/plugins/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<!-- DataTables & Plugins -->
<script src="<?= base_url('AdminLTE/plugins/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= base_url('AdminLTE/plugins/datatables-bs4/js/dataTables.bootstrap4.min.js') ?>"></script>
<script src="<?= base_url('AdminLTE/plugins/datatables-responsive/js/dataTables.responsive.min.js') ?>"></script>
<script src="<?= base_url('AdminLTE/plugins/datatables-responsive/js/responsive.bootstrap4.min.js') ?>"></script>
<!-- Toastr JS -->
<script src="<?= base_url('AdminLTE/plugins/toastr/toastr.min.js') ?>"></script>
<!-- AdminLTE App -->
<script src="<?= base_url('AdminLTE/dist/js/adminlte.min.js') ?>"></script>

<!-- Flash Notification Trigger via Toastr -->
<?php if ($flash): ?>
<script>
  $(function() {
    toastr.options = {
      "closeButton": true,
      "progressBar": true,
      "positionClass": "toast-top-right",
      "timeOut": "5000"
    };
    <?php if ($flash['type'] === 'error'): ?>
      toastr.error(<?= json_encode($flash['message']) ?>, 'Error');
    <?php else: ?>
      toastr.success(<?= json_encode($flash['message']) ?>, 'Success');
    <?php endif; ?>
  });
</script>
<?php endif; ?>
</body>
</html>
