<?php
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/functions.php';

if (!function_exists('telemetry_floor_name')) {
    /**
     * Resolve exact floor/area name from newserv StaticGameData.cc floor_defs for a room episode.
     */
    function telemetry_floor_name(int $floor, ?int $ep, bool $isGame): string
    {
        if (!$isGame) {
            return $floor === 15 ? 'Visual Lobby' : "Lobby (Floor {$floor})";
        }
        if ($ep === 2) {
            $map = PSO_EP2_FLOORS + [
                0  => 'Lab (Pioneer 2)',
                12 => 'Gal Gryphon',
                13 => 'Olga Flow',
                14 => 'Barba Ray',
                15 => 'Gol Dragon',
                16 => 'Seaside (Night)',
                17 => 'Control Tower',
            ];
            return $map[$floor] ?? "Ep2 Floor {$floor}";
        }
        if ($ep === 4) {
            $map = PSO_EP4_FLOORS + [
                0  => 'Pioneer 2',
                9  => 'Meteor Impact Site (Boss)',
                10 => 'Test Map',
                15 => 'Lobby (Transition)',
            ];
            return $map[$floor] ?? "Ep4 Floor {$floor}";
        }
        $map = PSO_EP1_FLOORS + [
            0  => 'Pioneer 2',
            11 => 'Dragon',
            12 => 'De Rol Le',
            13 => 'Vol Opt',
            14 => 'Dark Falz',
            15 => 'Lobby (Transition)',
            16 => 'Battle Spaceship',
            17 => 'Battle Palace',
        ];
        return $map[$floor] ?? "Floor {$floor}";
    }
}

if (!function_exists('telemetry_is_boss_arena')) {
    /**
     * Check whether a floor is a genuine boss arena for the active game episode.
     */
    function telemetry_is_boss_arena(int $floor, ?int $ep, bool $isGame): bool
    {
        if (!$isGame) {
            return false;
        }
        if ($ep === 1) {
            return in_array($floor, [11, 12, 13, 14], true);
        }
        if ($ep === 2) {
            return in_array($floor, [12, 13, 14, 15], true);
        }
        if ($ep === 4) {
            return $floor === 9;
        }
        return false;
    }
}

if (PHP_SAPI === 'cli' && defined('TELEMETRY_HELPERS_ONLY')) {
    return;
}

start_secure_session();
if (empty($_SESSION['user']) || empty($_SESSION['user']['is_admin'])) {
    header("Location: ../login.php");
    exit;
}

$debug_log_file = __DIR__ . '/../api/.debug_telemetry.json';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_debug_log') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    @file_put_contents($debug_log_file, '[]', LOCK_EX);
    header('Location: telemetry.php');
    exit;
}

$page_title = __('Live Telemetry Debug') . ' - ' . get_server_name();
include '../includes/header.php';

// Fetch Live Game State (/y/clients + /y/lobbies)
$clients = newserv_fetch_clients(2.0) ?? [];

$decoded_lobbies = newserv_fetch_lobbies(2.0);
$lobbyMap = [];
if ($decoded_lobbies !== null) {
    foreach ($decoded_lobbies as $lobby) {
        if (!isset($lobby['ID'])) {
            continue;
        }
        $isGame = !empty($lobby['IsGame']);
        $ep = null;
        if ($isGame && isset($lobby['Episode']) && preg_match('/(\d)/', (string)$lobby['Episode'], $m)) {
            $ep = (int)$m[1];
        }
        $lobbyMap[(string)$lobby['ID']] = [
            'name'       => trim((string)($lobby['Name'] ?? '')),
            'is_game'    => $isGame,
            'difficulty' => trim((string)($lobby['Difficulty'] ?? '')),
            'mode'       => trim((string)($lobby['Mode'] ?? '')),
            'episode'    => $ep,
        ];
    }
}

// Load runtime state caches keyed by "{AccountID}_{CharName}"
$loadJsonArray = static function (string $path): array {
    if (!is_readable($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};

$player_states    = $loadJsonArray(__DIR__ . '/../db/.cron_player_state.json');
$tracker_states   = $loadJsonArray(__DIR__ . '/../db/.boss_tracker_state.json');
$community_states = $loadJsonArray(__DIR__ . '/../api/.cron_community_state.json');
$telemetry_logs   = $loadJsonArray($debug_log_file);
?>

<style>
    .admin-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1.5rem;
        margin-top: 2rem;
    }
    .admin-card {
        background: rgba(0, 0, 0, 0.6);
        border: 1px solid var(--primary-color);
        padding: 1.5rem;
        border-radius: 8px;
        min-width: 0;
    }
    .admin-card h3 {
        margin-top: 0;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        padding-bottom: 0.5rem;
        margin-bottom: 1rem;
        color: var(--secondary-color);
    }
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .telemetry-table {
        width: 100%;
        border-collapse: collapse;
    }
    .telemetry-table th, .telemetry-table td {
        font-size: 0.85rem;
        padding: 0.45rem 0.55rem;
        white-space: nowrap;
        text-align: left;
    }
    @media (max-width: 640px) {
        .admin-card {
            padding: 1rem;
        }
    }
</style>

<main class="container">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
            <h1><?= __("Live Telemetry Debug") ?></h1>
            <div style="font-size:0.9rem; opacity:0.7;"><?= __("Monitor real-time player locations and server hook detections.") ?></div>
        </div>
        <div>
            <button onclick="window.location.reload();" class="dl-btn" style="border-color:#00C851; color:#00C851;"><?= __("Refresh Data") ?></button>
        </div>
    </div>

    <!-- Admin Subnav & Language Switcher -->
    <?php 
    $admin_active_tab = 'telemetry';
    include __DIR__ . '/../includes/admin_subnav.php'; 
    ?>

    <div class="admin-grid">
        <!-- Live Players Table -->
        <div class="admin-card">
            <h3><?= __("Live Connected Players") ?> (<?= count($clients) ?>)</h3>
            <div class="table-responsive">
                <table class="telemetry-table">
                    <thead style="position: sticky; top: 0; z-index: 1;">
                        <tr>
                            <th><?= __("Char") ?></th>
                            <th><?= __("Acc ID") ?></th>
                            <th><?= __("Lv") ?></th>
                            <th><?= __("Room") ?></th>
                            <th><?= __("Last Area") ?></th>
                            <th><?= __("Curr Area") ?></th>
                            <th><?= __("Time") ?></th>
                            <th><?= __("EXP") ?></th>
                            <th><?= __("1m Delta") ?></th>
                            <th><?= __("Kills") ?></th>
                            <th><?= __("Status") ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($clients)): ?>
                            <tr><td colspan="11" style="opacity:0.7; padding:1rem 0.5rem;"><?= __("No players currently online.") ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($clients as $client): ?>
                                <?php
                                    $charName = trim((string)($client['Name'] ?? 'Unknown')) ?: 'Unknown';
                                    $accId = (string)($client['Account']['AccountID'] ?? 'Unknown');
                                    $stateKey = "{$accId}_{$charName}";
                                    $level = $client['Level'] ?? '?';
                                    $currFloorId = (int)($client['LocationFloor'] ?? 0);

                                    $lobbyId = $client['LobbyID'] ?? null;
                                    $lobby = ($lobbyId !== null && isset($lobbyMap[(string)$lobbyId])) ? $lobbyMap[(string)$lobbyId] : null;
                                    $isGame = $lobby ? !empty($lobby['is_game']) : false;
                                    $ep = $lobby ? $lobby['episode'] : null;

                                    $bts = $tracker_states[$stateKey] ?? [];
                                    if ($lobby === null && isset($bts['is_game'])) {
                                        $isGame = !empty($bts['is_game']);
                                        $ep = isset($bts['episode']) ? (int)$bts['episode'] : null;
                                    }

                                    $cps = $player_states[$stateKey] ?? (($player_states[$accId]['char_name'] ?? null) === $charName ? $player_states[$accId] : []);
                                    $ccsRaw = $community_states[$stateKey] ?? ($community_states[$accId] ?? []);
                                    $ccs = (is_array($ccsRaw) && (empty($ccsRaw['char_name']) || $ccsRaw['char_name'] === $charName)) ? $ccsRaw : [];

                                    $currFloorName = telemetry_floor_name($currFloorId, $ep, $isGame);
                                    $inBossRoom = telemetry_is_boss_arena($currFloorId, $ep, $isGame);

                                    $roomLabel = $isGame
                                        ? ('Ep' . ($ep ?: '?') . ' · ' . ($lobby['name'] ?: "Game #" . ($lobbyId ?? '?')) . (!empty($lobby['difficulty']) ? " ({$lobby['difficulty']})" : ''))
                                        : ('Lobby #' . ($lobbyId ?? '?'));

                                    if (isset($cps['floor']) && (int)$cps['floor'] !== $currFloorId && (int)$cps['floor'] >= 0) {
                                        $prevEp = $isGame ? ($ep ?? 1) : 1;
                                        $prevFloorName = telemetry_floor_name((int)$cps['floor'], $prevEp, $isGame || ((int)$cps['floor'] > 0 && (int)$cps['floor'] !== 15));
                                    } elseif (isset($bts['pre_boss_floor']) && (int)$bts['pre_boss_floor'] >= 0 && (int)$bts['pre_boss_floor'] !== $currFloorId) {
                                        $prevFloorName = telemetry_floor_name((int)$bts['pre_boss_floor'], $ep ?? 1, true);
                                    } else {
                                        $prevFloorName = '—';
                                    }

                                    if (isset($bts['floor'], $bts['floor_entry_time']) && (int)$bts['floor'] === $currFloorId) {
                                        $floorEnteredTime = (int)$bts['floor_entry_time'];
                                    } elseif (isset($cps['floor'], $cps['floor_entered_time']) && (int)$cps['floor'] === $currFloorId) {
                                        $floorEnteredTime = (int)$cps['floor_entered_time'];
                                    } else {
                                        $floorEnteredTime = time();
                                    }
                                    $secondsOnFloor = max(0, time() - $floorEnteredTime);
                                    $timeOnFloorStr = sprintf("%d:%02d", floor($secondsOnFloor / 60), $secondsOnFloor % 60);

                                    $currExp = (int)($client['EXP'] ?? 0);
                                    if ($inBossRoom && isset($bts['entry_exp'])) {
                                        $prevExp = (int)$bts['entry_exp'];
                                    } elseif (isset($ccs['exp'])) {
                                        $prevExp = (int)$ccs['exp'];
                                    } elseif (isset($cps['exp'])) {
                                        $prevExp = (int)$cps['exp'];
                                    } else {
                                        $prevExp = $currExp;
                                    }
                                    $expDelta = max(0, $currExp - $prevExp);
                                    $spikeStyle = ($expDelta >= 10) ? 'color:#00C851; font-weight:bold;' : 'opacity:0.75;';

                                    $kills = (int)($client['EnemyKillCount'] ?? 0);
                                    $rareKills = (int)($client['RareEnemyKillCount'] ?? 0);
                                    $killsDisplay = number_format($kills) . ($rareKills > 0 ? " (+{$rareKills}R)" : '');
                                ?>
                                <tr>
                                    <td style="font-weight:bold; color:var(--pso-blue);"><?= htmlspecialchars($charName) ?></td>
                                    <td style="font-family:monospace;"><?= htmlspecialchars($accId) ?></td>
                                    <td><?= htmlspecialchars((string)$level) ?></td>
                                    <td style="opacity:0.85;"><?= htmlspecialchars($roomLabel) ?></td>
                                    <td style="opacity:0.7;"><?= htmlspecialchars($prevFloorName) ?></td>
                                    <td style="<?= $inBossRoom ? 'color:#ffaa00; font-weight:bold;' : '' ?>">
                                        <?= htmlspecialchars($currFloorName) ?>
                                    </td>
                                    <td style="opacity:0.75;"><?= htmlspecialchars($timeOnFloorStr) ?></td>
                                    <td><?= number_format($currExp) ?></td>
                                    <td style="<?= $spikeStyle ?>">+<?= number_format($expDelta) ?></td>
                                    <td style="opacity:0.85;"><?= htmlspecialchars($killsDisplay) ?></td>
                                    <td>
                                        <?php if ($inBossRoom): ?>
                                            <span style="color:#ffaa00; font-size:0.7rem; border:1px solid #ffaa00; padding:2px 6px; border-radius:3px;">BOSS ARENA</span>
                                        <?php elseif ($isGame && $currFloorId !== 15): ?>
                                            <span style="color:#00C851; font-size:0.7rem; border:1px solid #00C851; padding:2px 6px; border-radius:3px;">IN GAME</span>
                                        <?php else: ?>
                                            <span style="color:#7ec8e3; font-size:0.7rem; border:1px solid rgba(0,255,255,0.35); padding:2px 6px; border-radius:3px;">LOBBY</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Telemetry Log -->
        <div class="admin-card">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom:0.5rem; margin-bottom:1rem;">
                <h3 style="color: #00ffcc; margin:0; border:none; padding:0;"><?= __('Recent Hook Detections') ?></h3>
                <?php if (!empty($telemetry_logs)): ?>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Clear recorded hook detections log?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="clear_debug_log">
                        <button type="submit" class="dl-btn" style="padding:3px 10px; font-size:0.75rem; border-color:rgba(255,68,68,0.6); color:#ff7777; background:rgba(255,68,68,0.1);"><?= __('Clear Log') ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <div style="background: rgba(0,0,0,0.8); border: 1px solid #333; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 0.85rem;">
                <?php if (empty($telemetry_logs)): ?>
                    <div style="color: #666; text-align: center; padding: 24px 0;"><?= __('No telemetry events recorded yet.') ?></div>
                <?php else: ?>
                    <?php foreach ($telemetry_logs as $log): ?>
                        <div style="margin-bottom: 10px; border-bottom: 1px solid #222; padding-bottom: 5px;">
                            <div style="color: #888; font-size: 0.75rem;"><?= date('Y-m-d H:i:s', (int)($log['time'] ?? 0)) ?> | <?= htmlspecialchars((string)($log['char'] ?? 'Unknown')) ?></div>
                            <div style="color: #00ffcc; margin-top: 3px;">
                                &gt; Killed <strong><?= htmlspecialchars((string)($log['boss'] ?? '?')) ?></strong> (+<?= number_format((int)($log['exp_delta'] ?? 0)) ?> EXP)
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php include '../includes/footer.php'; ?>
