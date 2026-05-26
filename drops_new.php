<?php
require_once __DIR__ . '/api/config.php';

$page_title = __('Item Drop Table') . ' - PSOBB Private Server';
$current_page = 'drops_new';
include 'includes/header.php';
?>

<link rel="stylesheet" href="css/drops.css?v=<?php echo time(); ?>">

<?php
$drops_i18n = [
    'typeRare' => __('Rare'),
    'typeMonster' => __('Monster (common)'),
    'typeBox' => __('Box (common)'),
    'typeDisk' => __('Disk'),
    'emptyFilter' => __('Enter a search parameter to populate the table.'),
    'noMatch' => __('No matching rows.'),
    'summaryTpl' => __('{{n}} row(s) (of {{total}} total lines)'),
    'partialLoad' => __('Partial load: '),
    'partialLoadSuffix' => __(' data unavailable.'),
    'rareLive' => __('Rare drops loaded from live server data.'),
    'rareFallback' => __('Rare drops loaded from cached/fallback data (API unavailable).'),
];

$drops_i18n_json = json_encode($drops_i18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
if ($drops_i18n_json === false) {
    $drops_i18n_json = '{}';
}
?>

    <div class="pso-spinner-svg">
        <canvas id="star-canvas-stats"></canvas>
        <svg class="hex2"><!-- hex SVG --></svg>
    </div>

    <main class="container">
        <div class="main-header" style="margin-bottom: 1rem;">
            <h1><?= __('Item Drop Table') ?></h1>

            <p class="drops-intro"><?= __('Enter a search parameter to populate the table.') ?></p>

            <p class="drops-note-muted"><?= __('Rare probabilities are calculated against other rare items that share the same drop source, not on drop chance as a whole. Monster and box commons (Normal mode) appear in the same table with Type set accordingly. After you pick an episode, Area filters box drops and monsters that spawn in that region (e.g. Forest, Cave).') ?></p>
        </div>

        <script>
            window.__DROPS_I18N = <?= $drops_i18n_json ?>;
        </script>

        <div class="layout-grid layout-drop-pages">
            <section class="main-content">

                <div id="drops-rare-block">
                    <div id="drops-status" class="alert-box drops-status"><?= __('Loading drop data…') ?></div>
                    <div id="drops-load-warn" class="alert-box drops-status" style="display:none;"></div>
                    <p id="drops-rare-source-note" class="drops-note-muted" style="display:none;"></p>

                    <div id="drops-filters" class="server-status-widget drops-filters" style="display:none;">
                        <div class="drops-filter-grid">
                            <label class="drops-field">
                                <span><?= __('Episode') ?></span>
                                <select id="drops-episode"><option value=""><?= __('All') ?></option></select>
                            </label>
                            <label class="drops-field">
                                <span><?= __('Area') ?></span>
                                <select id="drops-area" disabled><option value=""><?= __('All') ?></option></select>
                            </label>
                            <label class="drops-field">
                                <span><?= __('Difficulty') ?></span>
                                <select id="drops-difficulty"><option value=""><?= __('All') ?></option></select>
                            </label>
                            <label class="drops-field">
                                <span><?= __('Section ID') ?></span>
                                <select id="drops-section"><option value=""><?= __('All') ?></option></select>
                            </label>
                            <label class="drops-field drops-field-grow">
                                <span><?= __('Enemy / box') ?></span>
                                <input type="search" id="drops-source-q" autocomplete="off" placeholder="RAG_RAPPY">
                            </label>
                            <label class="drops-field drops-field-grow">
                                <span><?= __('Item search') ?></span>
                                <input type="search" id="drops-item-q" autocomplete="off" placeholder="<?= htmlspecialchars(__('Guilty Light')) ?>">
                            </label>
                            <button type="button" class="drops-reset-btn" id="drops-reset"><?= __('Clear filters') ?></button>
                        </div>
                        <div class="drops-summary" id="drops-summary"></div>
                    </div>

                    <div class="table-responsive drops-matrix-wrap" id="drops-matrix-wrap" style="display:none;">
                        <table class="drops-table drops-matrix">
                            <thead id="drops-matrix-thead"></thead>
                            <tbody id="drops-matrix-tbody"></tbody>
                        </table>
                    </div>

                    <div class="table-responsive drops-sec-wrap" id="drops-sec-wrap" style="display:none;">
                        <table class="drops-table drops-sec-table">
                            <thead>
                                <tr>
                                    <th class="sortable-th" data-sort="episode" tabindex="0" scope="col"><?= __('Episode') ?></th>
                                    <th class="sortable-th" data-sort="difficulty" tabindex="0" scope="col"><?= __('Difficulty') ?></th>
                                    <th class="sortable-th" data-sort="source" tabindex="0" scope="col"><?= __('Enemy / box') ?></th>
                                    <th class="sortable-th" data-sort="row_kind" tabindex="0" scope="col"><?= __('Type') ?></th>
                                    <th class="sortable-th" data-sort="probability" tabindex="0" scope="col"><?= __('Probability') ?></th>
                                    <th class="sortable-th" data-sort="approx_percent" tabindex="0" scope="col"><?= __('≈ %') ?></th>
                                    <th class="sortable-th" data-sort="item_name" tabindex="0" scope="col"><?= __('Item') ?></th>
                                    <th class="sortable-th" data-sort="item_hex" tabindex="0" scope="col"><?= __('Hex') ?></th>
                                </tr>
                            </thead>
                            <tbody id="drops-sec-tbody"></tbody>
                        </table>
                    </div>

                    <div class="table-responsive drops-table-wrap" id="drops-table-wrap" style="display:none;">
                        <table class="drops-table">
                            <thead>
                                <tr>
                                    <th class="sortable-th" data-sort="row_kind" tabindex="0" scope="col"><?= __('Type') ?></th>
                                    <th class="sortable-th" data-sort="episode" tabindex="0" scope="col"><?= __('Episode') ?></th>
                                    <th class="sortable-th" data-sort="difficulty" tabindex="0" scope="col"><?= __('Difficulty') ?></th>
                                    <th class="sortable-th" data-sort="section_id" tabindex="0" scope="col"><?= __('Section ID') ?></th>
                                    <th class="sortable-th" data-sort="source" tabindex="0" scope="col"><?= __('Enemy / box') ?></th>
                                    <th class="sortable-th" data-sort="probability" tabindex="0" scope="col"><?= __('Probability') ?></th>
                                    <th class="sortable-th" data-sort="approx_percent" tabindex="0" scope="col"><?= __('≈ %') ?></th>
                                    <th class="sortable-th" data-sort="item_name" tabindex="0" scope="col"><?= __('Item') ?></th>
                                    <th class="sortable-th" data-sort="item_hex" tabindex="0" scope="col"><?= __('Hex') ?></th>
                                </tr>
                            </thead>
                            <tbody id="drops-tbody"></tbody>
                        </table>
                    </div>

                    <nav class="drops-pager" id="drops-pager" style="display:none;" aria-label="<?= htmlspecialchars(__('Pagination', ENT_QUOTES, 'UTF-8')) ?>">
                        <button type="button" id="drops-prev"><?= __('Previous') ?></button>
                        <span id="drops-page-info"></span>
                        <button type="button" id="drops-next"><?= __('Next') ?></button>
                    </nav>
                </div>

                <h2 class="drops-section-title drops-section-title--spaced"><?= __('Section ID Weapon Chart') ?></h2>

                <div id="weapon-chart-status" class="alert-box drops-status"><?= __('Loading chart…') ?></div>

                <div id="weapon-mix-panel" class="weapon-mix-panel" style="display:none;">
                    <div class="table-responsive weapon-mix-chart-wrap">
                        <table class="drops-table weapon-mix-chart">
                            <thead id="weapon-mix-thead"></thead>
                            <tbody id="weapon-mix-tbody"></tbody>
                        </table>
                    </div>
                </div>

            </section>
        </div>
    </main>

    <script src="/js/drops.js?v=<?php echo time(); ?>" defer></script>
<?php include 'includes/footer.php'; ?>
