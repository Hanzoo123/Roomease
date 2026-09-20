<?php
/**
 * The block at the top of every panel page: an optional way back, the title, a
 * line saying what the page is for, and the actions.
 *
 * Every admin and landlord page used to hand-roll this out of AdminLTE's
 * content-header, a row, two columns and a breadcrumb. Nineteen copies drifted
 * from one another — some had a subtitle, some a coloured icon, some put their
 * buttons in the card below instead. One function ends that, and gives the
 * panel a single place to look for "what can I do on this screen".
 *
 *   $title     the page's name, plain text
 *   $options:
 *     'subtitle' string  one line under the title, saying what the page is for
 *     'back'     string  app-relative URL for the way back, e.g. the list this
 *                        record came from. Omit on a top-level page.
 *     'backLabel'string  what the way back is for, read out to a screen reader
 *     'actions'  string  ready-made HTML for the buttons on the right
 *     'lead'     string  ready-made HTML placed before the title, such as an
 *                        avatar or a listing's cover photo
 *     'tabs'     array   the sections of this record, for a page that shows one
 *                        thing from several angles. Each entry:
 *                          'id'    the id of the matching .re-tabpanel
 *                          'label' what the tab says
 *                          'count' optional number drawn beside the label
 *                        The first is the one shown, and every panel is
 *                        rendered whether or not its tab is current, so the
 *                        page still works with no JavaScript.
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

/**
 * A card header with a title, the line of grey under it, and whatever tools
 * belong on the right. $tools is ready-made HTML, because what sits there
 * ranges from a single link to a whole filter form.
 */
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
 * A label above its value, the pair the panel repeats everywhere. $value is
 * escaped unless $raw is true, which is how a badge or a link gets in.
 */
function re_field($label, $value, $raw = false)
{
    return '<div><span class="re-field-label">' . h($label) . '</span>'
        . '<span class="re-field-value">' . ($raw ? $value : h($value)) . '</span></div>';
}

/**
 * What a card says when it has nothing to show: an icon, a sentence, and
 * where there is one, the thing to do about it. $action is ready-made HTML.
 */
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
