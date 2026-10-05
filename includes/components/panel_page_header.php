<?php
/**
 * The header at the top of every panel page.
 *
 *   $title     page name
 *   $options:
 *     'subtitle'  one line under the title
 *     'back'      URL of the "back" link (omit on top-level pages)
 *     'backLabel' label of the back link, for screen readers
 *     'actions'   HTML for the buttons on the right
 *     'lead'      HTML before the title (e.g. an avatar)
 *     'tabs'      [['id' => tabpanel id, 'label' => ..., 'count' => optional], ...]
 */

/** Print the page header. See the notes above for $options. */
function panel_page_header($title, array $options = [])
{
    $back      = $options['back'] ?? null;
    $backLabel = $options['backLabel'] ?? 'Go back';
    $subtitle  = $options['subtitle'] ?? '';
    $actions   = $options['actions'] ?? '';
    $lead      = $options['lead'] ?? '';
    $tabs      = $options['tabs'] ?? [];
    ?>
    <div class="content-header">
      <div class="container-fluid">
        <div class="page-head">
          <div class="page-head-main">
            <?php if ($back !== null): ?>
              <a href="<?= base_url($back) ?>" class="page-back" title="<?= h($backLabel) ?>">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
                <span class="sr-only"><?= h($backLabel) ?></span>
              </a>
            <?php endif; ?>
            <?= $lead ?>
            <div style="min-width: 0;">
              <h1 class="page-title"><?= h($title) ?></h1>
              <?php if ($subtitle !== ''): ?>
                <p class="page-subtitle"><?= h($subtitle) ?></p>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($actions !== ''): ?>
            <div class="page-actions no-print"><?= $actions ?></div>
          <?php endif; ?>
        </div>

        <?php if ($tabs): ?>
          <div class="re-tabs no-print" role="tablist" data-panel-tabs>
            <?php foreach (array_values($tabs) as $i => $tab): ?>
              <a class="re-tab <?= $i === 0 ? 'is-active' : '' ?>" role="tab"
                id="tab-<?= h($tab['id']) ?>" href="#<?= h($tab['id']) ?>"
                aria-controls="<?= h($tab['id']) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
                <?= h($tab['label']) ?>
                <?php if (isset($tab['count'])): ?>
                  <span class="re-tab-count"><?= (int) $tab['count'] ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

/** A card header: title, grey subtitle, and $tools (HTML) on the right. */
function panel_card_header($title, $subtitle = '', $tools = '')
{
    ?>
    <div class="card-header<?= $tools !== '' ? ' card-header--split' : '' ?>">
      <div style="min-width: 0;">
        <h3 class="card-title"><?= h($title) ?></h3>
        <?php if ($subtitle !== ''): ?>
          <span class="card-subtitle"><?= h($subtitle) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($tools !== ''): ?>
        <div class="card-tools"><?= $tools ?></div>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * The row of figures at the top of a dashboard. Each figure:
 *   'value'  the number
 *   'of'     optional: shown after it as "/ of" (beds taken of beds)
 *   'label'  what it counts
 *   'note'   optional small line under the label
 *   'href'   where it leads
 *   'tone'   optional: 'waiting' (marigold) or 'problem' (red), meant only
 *            for a figure above 0
 * Styles: "Figures" in panel.css.
 */
function panel_figures(array $figures)
{
    ?>
    <div class="figure-row">
      <?php foreach ($figures as $f): ?>
        <a class="figure<?= !empty($f['tone']) ? ' figure--' . h($f['tone']) : '' ?>" href="<?= h($f['href']) ?>">
          <span class="figure-value">
            <?= h((string) $f['value']) ?><?php if (isset($f['of'])): ?><span class="figure-of"> / <?= h((string) $f['of']) ?></span><?php endif; ?>
          </span>
          <span class="figure-label"><?= h($f['label']) ?></span>
          <?php if (!empty($f['note'])): ?>
            <span class="figure-note"><?= h($f['note']) ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php
}

/** A label above a value. $value is escaped unless $raw is true. */
function re_field($label, $value, $raw = false)
{
    return '<div><span class="re-field-label">' . h($label) . '</span>'
        . '<span class="re-field-value">' . ($raw ? $value : h($value)) . '</span></div>';
}

/** Empty state for a card: an icon, a sentence, and an optional $action (HTML). */
function re_empty($title, $text = '', $icon = 'fa-inbox', $action = '')
{
    $html = '<div class="re-empty">'
        . '<span class="re-empty-icon" aria-hidden="true"><i class="fas ' . h($icon) . '"></i></span>'
        . '<p class="re-empty-title">' . h($title) . '</p>';
    if ($text !== '') {
        $html .= '<p class="re-empty-text">' . h($text) . '</p>';
    }
    if ($action !== '') {
        $html .= '<div class="re-empty-action">' . $action . '</div>';
    }
    return $html . '</div>';
}
