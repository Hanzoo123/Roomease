/**
 * Panel charts (ApexCharts). PHP writes the data into
 * <div class="re-chart" data-chart='{...}'>; the CSS bars inside stay as the
 * fallback without JavaScript.
 *
 * kind: 'mixed' (columns + line), 'donut', 'hbar' or 'column'.
 * legend: false hides the chart's own legend.
 * Colours come from panel.css, so charts follow light and dark mode.
 */
(function () {
  'use strict';

  if (typeof ApexCharts === 'undefined') return;

  var root = document.documentElement;

  function token(name) {
    return getComputedStyle(root).getPropertyValue('--re-' + name).trim();
  }

  function isDark() {
    return root.getAttribute('data-theme') === 'dark';
  }

  function whole(value) {
    return Math.round(value).toLocaleString();
  }

  function build(spec) {
    var colors = (spec.series || spec.colors || []).map(function (s) {
      return token(typeof s === 'string' ? s : s.color);
    });

    var options = {
      chart: {
        type: 'line',
        height: spec.height || 300,
        fontFamily: "'IBM Plex Sans', 'Segoe UI', sans-serif",
        toolbar: { show: false },
        zoom: { enabled: false },
        background: 'transparent',
        animations: { speed: 400 },
      },
      colors: colors,
      dataLabels: { enabled: false },
      legend: { position: 'bottom', fontSize: '13px', markers: { radius: 12 } },
      grid: { strokeDashArray: 3, padding: { left: 8, right: 8 } },
      states: { hover: { filter: { type: 'darken', value: 0.12 } } },
      yaxis: { labels: { formatter: whole }, forceNiceScale: true, min: 0 },
      tooltip: { shared: true, intersect: false, y: { formatter: whole } },
    };

    if (spec.kind === 'mixed') {
      options.series = spec.series.map(function (s) {
        return { name: s.name, type: s.type, data: s.data };
      });
      options.chart.stacked = !!spec.stacked;
      options.xaxis = {
        // Text, never dates: left to guess, ApexCharts reads "Apr 2026" as a
        // date and puts it in the wrong year.
        type: 'category',
        categories: spec.categories,
        tickAmount: spec.tickAmount,
        labels: { rotate: 0, hideOverlappingLabels: true },
        tooltip: { enabled: false },
      };
      options.stroke = {
        width: spec.series.map(function (s) { return s.type === 'line' ? 3 : 0; }),
        curve: 'smooth',
      };
      options.markers = { size: 0, hover: { size: 5 } };
      options.plotOptions = { bar: { columnWidth: spec.columnWidth || '55%', borderRadius: 4 } };

      // One y axis per series; the ones on axis 0 share the left scale.
      var leftName = null;
      options.yaxis = spec.series.map(function (s) {
        var right = s.axis === 1;
        if (!right && leftName === null) leftName = s.name;
        return {
          seriesName: right ? s.name : leftName,
          opposite: right,
          show: right || s.name === leftName,
          min: 0,
          forceNiceScale: true,
          title: { text: right ? s.name : spec.leftTitle || '' },
          labels: { formatter: whole },
        };
      });
    } else if (spec.kind === 'donut') {
      options.chart.type = 'donut';
      options.series = spec.values;
      options.labels = spec.labels;
      options.stroke = { width: 2 };
      options.tooltip = { y: { formatter: whole } };
      options.plotOptions = {
        pie: {
          donut: {
            size: '68%',
            labels: {
              show: true,
              value: { fontSize: '26px', fontWeight: 700, formatter: whole },
              total: { show: true, label: spec.totalLabel || 'Total', formatter: function (w) {
                return whole(w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0));
              } },
            },
          },
        },
      };
    } else {
      // 'hbar' and 'column': a single series, each bar in the same colour.
      var horizontal = spec.kind === 'hbar';
      options.chart.type = 'bar';
      options.series = [{ name: spec.seriesName, data: spec.values }];
      options.plotOptions = {
        bar: { horizontal: horizontal, borderRadius: 4, barHeight: '60%', columnWidth: '55%' },
      };
      options.xaxis = {
        type: 'category',
        categories: spec.categories,
        // Tilted rather than cut short when the columns are narrow.
        labels: { formatter: horizontal ? whole : undefined, rotate: -40, trim: false, hideOverlappingLabels: false },
      };
      if (horizontal) {
        options.yaxis = { labels: { maxWidth: 140 } };
        options.xaxis.tickAmount = Math.min(5, Math.max(1, Math.max.apply(null, spec.values)));
      }
      options.tooltip = { y: { formatter: whole } };
    }

    // legend: false when the page prints its own key above the chart.
    if (spec.legend === false) options.legend.show = false;

    // Everything that depends on the theme, read fresh on every build.
    options.chart.foreColor = token('ink-soft');
    options.grid.borderColor = token('line-soft');
    options.tooltip.theme = isDark() ? 'dark' : 'light';
    options.theme = { mode: isDark() ? 'dark' : 'light' };
    if (spec.kind === 'donut') options.stroke.colors = [token('panel')];
    return options;
  }

  var charts = [];

  document.querySelectorAll('.re-chart[data-chart]').forEach(function (el) {
    var spec;
    try {
      spec = JSON.parse(el.getAttribute('data-chart'));
    } catch (e) {
      return; // The fallback bars stay.
    }
    el.innerHTML = '';
    el.classList.add('re-chart--live');
    var chart = new ApexCharts(el, build(spec));
    chart.render();
    charts.push({ chart: chart, spec: spec });
  });

  // The navbar switch changes data-theme on <html>; follow it.
  new MutationObserver(function () {
    charts.forEach(function (c) { c.chart.updateOptions(build(c.spec), false, false); });
  }).observe(root, { attributes: true, attributeFilter: ['data-theme'] });
})();
