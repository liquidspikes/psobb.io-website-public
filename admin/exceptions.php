<?php
require_once __DIR__ . '/../api/config.php';
start_secure_session();
if (empty($_SESSION['user']) || empty($_SESSION['user']['is_admin'])) {
    header("Location: ../login.php");
    exit;
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
$current_page = 'exceptions';
$page_title = __('Player Exceptions') . ' - ' . get_server_name();
include '../includes/header.php';

/**
 * Tail up to $limit JSONL records across primary and first rotated exception logs.
 */
function load_recent_player_exceptions(string $logPath, int $limit = 1000): array {
    $readFile = static function (string $path, int $max): array {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        if (count($lines) > $max) {
            $lines = array_slice($lines, -$max);
        }
        $items = [];
        foreach ($lines as $ln) {
            $row = json_decode($ln, true);
            if (is_array($row) && !empty($row['ts'])) {
                $items[] = $row;
            }
        }
        return $items;
    };

    $rows = $readFile($logPath, $limit);
    if (count($rows) < $limit && is_file($logPath . '.1')) {
        $prev = $readFile($logPath . '.1', $limit - count($rows));
        $rows = array_merge($prev, $rows);
    }
    return array_reverse($rows);
}

$logPath = psobb_get_exception_log_path();
$records = load_recent_player_exceptions($logPath, 1000);
$cutoffTs = time() - 86400;

$server5xxCount = 0;
$api4xxCount = 0;
$clientJsCount = 0;
$affectedPlayers = [];
$grouped = [];

foreach ($records as $rec) {
    $tsEpoch = strtotime((string)($rec['ts'] ?? '')) ?: 0;
    if ($tsEpoch < $cutoffTs) {
        continue;
    }

    $kind = (string)($rec['kind'] ?? '');
    $httpStatus = (int)($rec['http_status'] ?? 0);

    if ($kind === 'client_js_error' || $kind === 'client_promise_rejection') {
        $clientJsCount++;
    } elseif ($kind === 'php_fatal' || $kind === 'uncaught_exception' || $httpStatus >= 500) {
        $server5xxCount++;
    } else {
        $api4xxCount++;
    }

    $playerKey = '';
    if (!empty($rec['account_id'])) {
        $playerKey = 'acc:' . (int)$rec['account_id'];
    } elseif (!empty($rec['username'])) {
        $playerKey = 'user:' . strtolower((string)$rec['username']);
    }
    if ($playerKey !== '') {
        $affectedPlayers[$playerKey] = true;
    }

    $endpoint = (string)($rec['endpoint'] ?? ($rec['extra']['page'] ?? '-'));
    $msg = (string)($rec['message'] ?? '');
    $gKey = $kind . '|' . $endpoint . '|' . $msg;
    if (!isset($grouped[$gKey])) {
        $grouped[$gKey] = [
            'count'       => 0,
            'players'     => [],
            'kind'        => $kind,
            'http_status' => $httpStatus,
            'endpoint'    => $endpoint,
            'message'     => $msg,
            'last_ts'     => (string)($rec['ts'] ?? ''),
        ];
    }
    $grouped[$gKey]['count']++;
    if ($playerKey !== '') {
        $grouped[$gKey]['players'][$playerKey] = true;
    }
    if (strcmp((string)($rec['ts'] ?? ''), $grouped[$gKey]['last_ts']) > 0) {
        $grouped[$gKey]['last_ts'] = (string)$rec['ts'];
    }
}

$topGrouped = array_values($grouped);
usort($topGrouped, static function (array $a, array $b): int {
    if ($a['count'] !== $b['count']) {
        return $b['count'] <=> $a['count'];
    }
    return strcmp($b['last_ts'], $a['last_ts']);
});
$topGrouped = array_slice($topGrouped, 0, 25);
$feedRows = array_slice($records, 0, 150);
?>

<style>
    .exc-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 1rem;
        margin: 1.5rem 0;
    }
    .exc-card {
        background: rgba(0, 0, 0, 0.6);
        border: 1px solid var(--primary-color);
        padding: 1.25rem;
        border-radius: 8px;
    }
    .exc-card h3 {
        margin-top: 0;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        padding-bottom: 0.5rem;
        margin-bottom: 0.9rem;
        color: var(--secondary-color);
    }
    .exc-stat-num {
        font-size: 2rem;
        font-weight: 700;
        font-family: 'Share Tech Mono', monospace;
        line-height: 1.1;
    }
    .exc-stat-label {
        font-size: 0.85rem;
        opacity: 0.78;
        margin-top: 0.35rem;
    }
    .exc-table th, .exc-table td {
        font-size: 0.84rem;
        padding: 0.5rem 0.6rem;
        vertical-align: top;
    }
    .exc-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.74rem;
        font-family: 'Share Tech Mono', monospace;
        white-space: nowrap;
    }
    .exc-badge-crit { background: rgba(255, 68, 68, 0.2); border: 1px solid #ff4444; color: #ff6b6b; }
    .exc-badge-warn { background: rgba(255, 170, 0, 0.16); border: 1px solid #ffaa00; color: #ffc107; }
    .exc-badge-js   { background: rgba(56, 189, 248, 0.16); border: 1px solid #38bdf8; color: #7dd3fc; }
    .exc-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1rem;
    }
    .exc-filter-group {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .exc-filter-btn {
        padding: 6px 12px;
        border-radius: 6px;
        border: 1px solid rgba(255,255,255,0.2);
        background: rgba(255,255,255,0.05);
        color: #ccc;
        cursor: pointer;
        font-size: 0.82rem;
    }
    .exc-filter-btn.active {
        background: var(--pso-blue, #00ffff);
        color: #000;
        border-color: var(--pso-blue, #00ffff);
        font-weight: 700;
    }
    .exc-search {
        padding: 6px 10px;
        border-radius: 6px;
        border: 1px solid rgba(0, 255, 255, 0.3);
        background: rgba(0, 0, 0, 0.6);
        color: #fff;
        min-width: 240px;
    }
    .exc-details {
        font-size: 0.76rem;
        color: #9ca3af;
        margin-top: 3px;
        font-family: monospace;
        word-break: break-word;
    }
    .exc-controls-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }
    .exc-refresh-btn {
        min-height: 40px;
    }
    @media (max-width: 768px) {
        .exc-stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.65rem;
            margin: 1rem 0;
        }
        .exc-card {
            padding: 0.9rem;
        }
        .exc-stat-num {
            font-size: 1.55rem;
        }
        .exc-toolbar {
            flex-direction: column;
            align-items: stretch;
        }
        .exc-controls-wrap,
        .exc-filter-group {
            width: 100%;
        }
        .exc-filter-btn {
            flex: 1 1 calc(50% - 6px);
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .exc-search {
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
            font-size: 16px;
            min-height: 40px;
        }
        .exc-table,
        .exc-table tbody {
            display: block;
            width: 100%;
        }
        .exc-table thead {
            display: none;
        }
        .exc-table tbody tr {
            display: block;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(0, 255, 255, 0.18);
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .exc-table tbody td {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            padding: 0.32rem 0;
            border: none;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            white-space: normal !important;
            max-width: 100% !important;
            overflow-wrap: anywhere;
            word-break: break-word;
            text-align: right;
        }
        .exc-table tbody td:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .exc-table tbody td::before {
            content: attr(data-label);
            font-weight: 600;
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            flex-shrink: 0;
            text-align: left;
        }
        .exc-table tbody td.exc-msg-cell {
            flex-direction: column;
            align-items: stretch;
            text-align: left;
            gap: 4px;
            padding-top: 0.45rem;
        }
        .exc-table tbody td[colspan] {
            display: block;
            text-align: center;
            border-bottom: none;
        }
        .exc-table tbody td[colspan]::before {
            display: none;
        }
    }
    @media (max-width: 640px) {
        .exc-page-header h1 {
            font-size: 1.35rem;
        }
        .exc-refresh-btn {
            width: 100%;
            min-height: 40px;
        }
    }
    @media (max-width: 340px) {
        .exc-stats-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<main class="container">
    <div class="exc-page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
            <h1><?= __('Player Exceptions Log') ?></h1>
            <div style="font-size:0.9rem; opacity:0.7;">
                <?= __('Unreported player-visible errors, API failures, PHP fatals, and client JS exceptions.') ?>
            </div>
        </div>
        <div>
            <button onclick="window.location.reload();" class="dl-btn exc-refresh-btn" style="border-color:#00C851; color:#00C851;"><?= __('Refresh') ?></button>
        </div>
    </div>

    <?php
    $admin_active_tab = 'exceptions';
    include __DIR__ . '/../includes/admin_subnav.php';
    ?>

    <div class="exc-stats-grid">
        <div class="exc-card" style="border-color: rgba(255, 68, 68, 0.5);">
            <div class="exc-stat-num" style="color:#ff6b6b;"><?= number_format($server5xxCount) ?></div>
            <div class="exc-stat-label"><?= __('Server 5xx / Fatal / Uncaught (24h)') ?></div>
        </div>
        <div class="exc-card" style="border-color: rgba(255, 170, 0, 0.5);">
            <div class="exc-stat-num" style="color:#ffc107;"><?= number_format($api4xxCount) ?></div>
            <div class="exc-stat-label"><?= __('API / Action Errors 4xx (24h)') ?></div>
        </div>
        <div class="exc-card" style="border-color: rgba(56, 189, 248, 0.5);">
            <div class="exc-stat-num" style="color:#7dd3fc;"><?= number_format($clientJsCount) ?></div>
            <div class="exc-stat-label"><?= __('Client JS Errors (24h)') ?></div>
        </div>
        <div class="exc-card" style="border-color: rgba(0, 255, 204, 0.45);">
            <div class="exc-stat-num" style="color:#00ffcc;"><?= number_format(count($affectedPlayers)) ?></div>
            <div class="exc-stat-label"><?= __('Unique Accounts Affected (24h)') ?></div>
        </div>
    </div>

    <div class="exc-card" style="margin-bottom: 1.5rem;">
        <h3><?= __('Top Recurring Player Exceptions (Last 24h)') ?></h3>
        <div class="table-responsive">
            <table class="exc-table">
                <thead>
                    <tr>
                        <th><?= __('Count') ?></th>
                        <th><?= __('Players') ?></th>
                        <th><?= __('Kind') ?></th>
                        <th><?= __('Endpoint') ?></th>
                        <th><?= __('Message') ?></th>
                        <th><?= __('Last Seen (UTC)') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topGrouped)): ?>
                        <tr><td colspan="6" style="color:#888;"><?= __('No player exceptions recorded in the last 24 hours.') ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($topGrouped as $g): ?>
                            <?php
                            $gKind = $g['kind'];
                            $badgeClass = ($gKind === 'client_js_error' || $gKind === 'client_promise_rejection')
                                ? 'exc-badge-js'
                                : (($gKind === 'php_fatal' || $gKind === 'uncaught_exception' || $g['http_status'] >= 500) ? 'exc-badge-crit' : 'exc-badge-warn');
                            ?>
                            <tr>
                                <td data-label="<?= htmlspecialchars(__('Count'), ENT_QUOTES, 'UTF-8') ?>" style="font-weight:700; font-family:monospace;"><?= (int)$g['count'] ?></td>
                                <td data-label="<?= htmlspecialchars(__('Players'), ENT_QUOTES, 'UTF-8') ?>" style="font-family:monospace;"><?= count($g['players']) ?></td>
                                <td data-label="<?= htmlspecialchars(__('Kind'), ENT_QUOTES, 'UTF-8') ?>"><span class="exc-badge <?= $badgeClass ?>"><?= htmlspecialchars($gKind, ENT_QUOTES, 'UTF-8') ?><?= $g['http_status'] ? ' (' . (int)$g['http_status'] . ')' : '' ?></span></td>
                                <td data-label="<?= htmlspecialchars(__('Endpoint'), ENT_QUOTES, 'UTF-8') ?>" style="font-family:monospace;"><?= htmlspecialchars($g['endpoint'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td data-label="<?= htmlspecialchars(__('Message'), ENT_QUOTES, 'UTF-8') ?>" class="exc-msg-cell" style="word-break:break-word; max-width:460px;"><?= htmlspecialchars($g['message'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td data-label="<?= htmlspecialchars(__('Last Seen'), ENT_QUOTES, 'UTF-8') ?>" style="font-family:monospace; white-space:nowrap; opacity:0.8;"><?= htmlspecialchars(substr($g['last_ts'], 0, 19), ENT_QUOTES, 'UTF-8') ?>Z</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="exc-card">
        <div class="exc-toolbar">
            <h3 style="margin:0; border:none; padding:0;"><?= __('Recent Player Exceptions (Latest 150)') ?></h3>
            <div class="exc-controls-wrap">
                <div class="exc-filter-group">
                    <button type="button" class="exc-filter-btn active" data-filter="all"><?= __('All') ?></button>
                    <button type="button" class="exc-filter-btn" data-filter="server"><?= __('Server 5xx / Fatal') ?></button>
                    <button type="button" class="exc-filter-btn" data-filter="api"><?= __('API / Action Errors') ?></button>
                    <button type="button" class="exc-filter-btn" data-filter="client"><?= __('Client JS') ?></button>
                </div>
                <input type="text" id="exc-search-input" class="exc-search" placeholder="<?= __('Search endpoint, player, message...') ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="exc-table" id="exc-feed-table">
                <thead>
                    <tr>
                        <th><?= __('UTC Time') ?></th>
                        <th><?= __('Kind / HTTP') ?></th>
                        <th><?= __('Endpoint') ?></th>
                        <th><?= __('Player') ?></th>
                        <th><?= __('Message & Details') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($feedRows)): ?>
                        <tr><td colspan="5" style="color:#888;"><?= __('No exception records found yet.') ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($feedRows as $r): ?>
                            <?php
                            $rKind = (string)($r['kind'] ?? '');
                            $rStatus = (int)($r['http_status'] ?? 0);
                            if ($rKind === 'client_js_error' || $rKind === 'client_promise_rejection') {
                                $cat = 'client';
                                $badgeClass = 'exc-badge-js';
                            } elseif ($rKind === 'php_fatal' || $rKind === 'uncaught_exception' || $rStatus >= 500) {
                                $cat = 'server';
                                $badgeClass = 'exc-badge-crit';
                            } else {
                                $cat = 'api';
                                $badgeClass = 'exc-badge-warn';
                            }
                            $rEndpoint = (string)($r['endpoint'] ?? '');
                            $rPlayer = '';
                            if (!empty($r['username'])) {
                                $rPlayer = (string)$r['username'];
                            }
                            if (!empty($r['account_id'])) {
                                $rPlayer .= ($rPlayer !== '' ? ' ' : '') . '#' . (int)$r['account_id'];
                            }
                            if ($rPlayer === '') {
                                $rPlayer = 'anon';
                            }
                            $detailParts = [];
                            if (!empty($r['exception_class'])) {
                                $loc = (string)($r['file'] ?? '');
                                $lineNo = !empty($r['line']) ? ':' . (int)$r['line'] : '';
                                $detailParts[] = $r['exception_class'] . ($loc !== '' ? ' @ ' . $loc . $lineNo : '');
                            }
                            if (!empty($r['params']) && is_array($r['params'])) {
                                $detailParts[] = 'params=' . json_encode($r['params'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            }
                            if (!empty($r['extra']) && is_array($r['extra'])) {
                                $detailParts[] = 'extra=' . json_encode($r['extra'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            }
                            $detailStr = implode(' | ', $detailParts);
                            $searchBlob = strtolower($rKind . ' ' . $rEndpoint . ' ' . $rPlayer . ' ' . ($r['message'] ?? '') . ' ' . $detailStr);
                            ?>
                            <tr data-cat="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>">
                                <td data-label="<?= htmlspecialchars(__('UTC Time'), ENT_QUOTES, 'UTF-8') ?>" style="font-family:monospace; white-space:nowrap; opacity:0.85;"><?= htmlspecialchars(substr((string)($r['ts'] ?? ''), 0, 19), ENT_QUOTES, 'UTF-8') ?>Z</td>
                                <td data-label="<?= htmlspecialchars(__('Kind / HTTP'), ENT_QUOTES, 'UTF-8') ?>"><span class="exc-badge <?= $badgeClass ?>"><?= htmlspecialchars($rKind, ENT_QUOTES, 'UTF-8') ?><?= $rStatus ? ' ' . $rStatus : '' ?></span></td>
                                <td data-label="<?= htmlspecialchars(__('Endpoint'), ENT_QUOTES, 'UTF-8') ?>" style="font-family:monospace;"><?= htmlspecialchars(($r['method'] ?? '') . ' ' . $rEndpoint, ENT_QUOTES, 'UTF-8') ?></td>
                                <td data-label="<?= htmlspecialchars(__('Player'), ENT_QUOTES, 'UTF-8') ?>" style="font-family:monospace; white-space:nowrap;"><?= htmlspecialchars($rPlayer, ENT_QUOTES, 'UTF-8') ?></td>
                                <td data-label="<?= htmlspecialchars(__('Message & Details'), ENT_QUOTES, 'UTF-8') ?>" class="exc-msg-cell" style="max-width:520px; word-break:break-word;">
                                    <div><?= htmlspecialchars((string)($r['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if ($detailStr !== ''): ?>
                                        <div class="exc-details"><?= htmlspecialchars($detailStr, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const filterBtns = document.querySelectorAll('.exc-filter-btn');
    const searchInput = document.getElementById('exc-search-input');
    const rows = document.querySelectorAll('#exc-feed-table tbody tr[data-cat]');
    let currentCat = 'all';

    function applyFilter() {
        const q = (searchInput ? searchInput.value : '').trim().toLowerCase();
        rows.forEach((tr) => {
            const catOk = currentCat === 'all' || tr.getAttribute('data-cat') === currentCat;
            const qOk = !q || (tr.getAttribute('data-search') || '').indexOf(q) !== -1;
            tr.style.display = (catOk && qOk) ? '' : 'none';
        });
    }

    filterBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
            filterBtns.forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            currentCat = btn.getAttribute('data-filter') || 'all';
            applyFilter();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', applyFilter);
    }
});
</script>
<?php include '../includes/footer.php'; ?>
