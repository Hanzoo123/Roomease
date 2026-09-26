<?php
/**
 * ApexCharts and the panel's chart setup, for a page that has .re-chart
 * elements (admin/dashboard.php, admin/reports.php). Included after the
 * charts' markup, so the script finds them. Only these pages load the
 * library, which is self-hosted: the CSP allows no script from elsewhere,
 * and the panel has to work with no connection.
 */
$chartAsset = function ($path) {
    return base_url($path) . '?v=' . (@filemtime(__DIR__ . '/../../' . $path) ?: 0);
};
?>
<script src="<?= $chartAsset('assets/vendor/apexcharts/apexcharts.min.js') ?>"></script>
<script src="<?= $chartAsset('assets/js/panel-charts.js') ?>"></script>
