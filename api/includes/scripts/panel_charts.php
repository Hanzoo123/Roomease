<?php
/**
 * Loads ApexCharts (self-hosted) for pages with .re-chart elements.
 * Include after the charts' markup.
 */
$chartAsset = function ($path) {
    return base_url($path) . '?v=' . (@filemtime(__DIR__ . '/../../' . $path) ?: 0);
};
?>
<script src="<?= $chartAsset('assets/vendor/apexcharts/apexcharts.min.js') ?>"></script>
<script src="<?= $chartAsset('assets/js/panel-charts.js') ?>"></script>
