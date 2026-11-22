<?php
/**
 * --------------------------------------------------------------------------
 * PSOBB Boss & Floor Tracker — High-Frequency Cron (5-second intervals)
 * --------------------------------------------------------------------------
 * Lightweight daemon that polls newserv every 5 seconds to track:
 *   - Boss floor entry/exit transitions + EXP/item deltas → boss kills
 *   - All floor transitions with timestamps → speedrun floor clears
 *
 * Writes to two event files consumed by cron_missions.php:
 *   db/.boss_kills.json  — Confirmed boss kill events
 *   db/.floor_clears.json — Floor transition events with timing
 *
 * Boss arenas are judged per episode, from the room's episode in /y/lobbies: floor numbers repeat
 * across episodes, and 9 (the Episode 4 arena) is Ruins 2 in Episode 1 and the Seaside in Episode 2,
 * while 11 (the Episode 1 Dragon) is the lower Seabed in Episode 2. Events carry the episode.
 * --------------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

// Enforce single instance
$lock_file = fopen(__DIR__ . '/../db/.cron_boss_tracker.lock', 'c');
if (!flock($lock_file, LOCK_EX | LOCK_NB)) {
    echo "[TRACKER] Another instance is running. Exiting.\n";
    exit;
}

/**
 * Boss arena floors in a room of this episode. Unknown episode or non-game lobby returns [].
 */
function tracker_boss_floors($episode, $is_game = true)
{
    return $is_game ? pso_boss_arena_floors($episode, true) : [];
}

/**
 * Human-readable boss name for verified episode + arena floor.
 */
function tracker_boss_name(?int $episode, int $floor): string
{
    if ($episode === 1) {
        $map = [
            11 => 'Dragon / Sil Dragon',
            12 => 'De Rol Le',
            13 => 'Vol Opt',
            14 => 'Dark Falz',
        ];
        return $map[$floor] ?? "Ep1 Boss (Floor {$floor})";
    }
    if ($episode === 2) {
        $map = [
            12 => 'Gal Gryphon',
            13 => 'Olga Flow',
            14 => 'Barba Ray',
            15 => 'Gol Dragon',
        ];
        return $map[$floor] ?? "Ep2 Boss (Floor {$floor})";
    }
    if ($episode === 4 && $floor === 9) {
        return 'Saint-Milion / Shambertin / Kondrieu';
    }
    return "Boss (Floor {$floor})";
}

/**
 * Append one episode-verified boss kill entry to api/.debug_telemetry.json for Admin Telemetry.
 */
function tracker_append_debug_telemetry(string $charName, ?int $episode, int $floor, int $expDelta, int $itemDelta): void
{
    $debugLogFile = __DIR__ . '/.debug_telemetry.json';
    $logs = [];
    if (file_exists($debugLogFile)) {
        $decoded = json_decode((string)@file_get_contents($debugLogFile), true);
        if (is_array($decoded)) {
            $logs = $decoded;
        }
    }
    array_unshift($logs, [
        'time'       => time(),
        'char'       => $charName ?: 'Unknown',
        'boss'       => tracker_boss_name($episode, $floor),
        'episode'    => $episode,
        'exp_delta'  => max(0, $expDelta),
        'loot_delta' => max(0, $itemDelta),
    ]);
    if (count($logs) > 50) {
        $logs = array_slice($logs, 0, 50);
    }
    @file_put_contents($debugLogFile, json_encode($logs), LOCK_EX);
}

$rooms = []; // lobby ID => ['episode' => 1|2|4|null, 'is_game' => bool], kept if a fetch fails
$state_file = __DIR__ . '/../db/.boss_tracker_state.json';
$kills_file = __DIR__ . '/../db/.boss_kills.json';
$floor_clears_file = __DIR__ . '/../db/.floor_clears.json';

$script_start = time();

// Run for up to 55 seconds (fits within 1-minute crontab)
while (time() - $script_start < 55) {
    $clients = newserv_fetch_clients();
    if (empty($clients)) {
        sleep(5);
        continue;
    }

    // Each room's episode, so a floor number is read as the right area
    $lobbies = newserv_fetch_lobbies();
    if ($lobbies !== null) {
        $rooms = [];
        foreach ($lobbies as $l) {
            if (!isset($l['ID'])) continue;
            $ep = (isset($l['Episode']) && preg_match('/(\d)/', (string) $l['Episode'], $mm)) ? (int) $mm[1] : null;
            $rooms[$l['ID']] = ['episode' => $ep, 'is_game' => !empty($l['IsGame'])];
        }
    }

    // Load previous state
    $prev_states = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];
    if (!is_array($prev_states)) $prev_states = [];

    // Load existing events
    $kill_events = file_exists($kills_file) ? json_decode(file_get_contents($kills_file), true) : [];
    if (!is_array($kill_events)) $kill_events = [];
    $floor_clear_events = file_exists($floor_clears_file) ? json_decode(file_get_contents($floor_clears_file), true) : [];
    if (!is_array($floor_clear_events)) $floor_clear_events = [];

    $new_states = [];

    foreach ($clients as $client) {
        if (!isset($client['Account']['AccountID'])) continue;
        if (!isset($client['EXP'])) continue;

        $accId = (string)$client['Account']['AccountID'];
        $charName = $client['Name'] ?? 'Unknown';
        $key = $accId . '_' . $charName;

        $curr_floor = (int)($client['LocationFloor'] ?? -1);
        $curr_exp = (int)($client['EXP'] ?? 0);
        $curr_items = count($client['InventoryItems'] ?? []);
        $curr_level = (int)($client['Level'] ?? 1);
        $lobby_id = $client['LobbyID'] ?? null;
        $room = ($lobby_id !== null) ? ($rooms[$lobby_id] ?? null) : null;
        $episode = $room['episode'] ?? null;
        $boss_floors = $room ? tracker_boss_floors($episode, $room['is_game']) : tracker_boss_floors(null);

        $prev = $prev_states[$key] ?? null;
        $prev_floor = $prev ? (int)$prev['floor'] : -1;
        // The previous floor is read in the room the player was in then (states from before this
        // change carry no episode: same room as now means same episode).
        if ($prev && array_key_exists('episode', $prev)) {
            $prev_boss_floors = tracker_boss_floors($prev['episode'], $prev['is_game'] ?? true);
        } elseif ($prev && ($prev['lobby_id'] ?? null) === $lobby_id) {
            $prev_boss_floors = $boss_floors;
        } else {
            $prev_boss_floors = tracker_boss_floors(null);
        }

        // Skip zero-EXP loading screen artifacts
        if ($curr_exp === 0 && $prev && $prev['exp'] > 0) {
            $new_states[$key] = $prev;
            continue;
        }

        $in_boss = in_array($curr_floor, $boss_floors);
        $was_in_boss = $prev ? in_array($prev_floor, $prev_boss_floors) : false;
        $room_fields = ['episode' => $episode, 'is_game' => $room ? $room['is_game'] : true];

        // ================================================================
        // FLOOR TRANSITION TRACKING (for SPEEDRUN_FLOOR)
        // Record every floor-to-floor transition with timing
        // ================================================================
        if ($prev && $curr_floor !== $prev_floor && $curr_floor >= 0 && $prev_floor >= 0) {
            $time_on_prev_floor = time() - ($prev['floor_entry_time'] ?? time());
            
            // Only record meaningful dungeon transitions (not town/lobby)
            if ($prev_floor > 0) {
                $floor_clear_events[$key][] = [
                    'from_floor' => $prev_floor,
                    'to_floor' => $curr_floor,
                    'time_on_floor' => $time_on_prev_floor,
                    'time' => time(),
                    'lobby_id' => $lobby_id,
                    'episode' => $episode,
                ];
            }
        }

        // ================================================================
        // BOSS KILL TRACKING
        // ================================================================

        // ── Player just entered a boss room ──
        if ($in_boss && !$was_in_boss) {
            $pre_boss = ($prev && $prev_floor > 0) // the previous floor wasn't an arena
                ? $prev_floor
                : ($prev['pre_boss_floor'] ?? -1);
            
            $new_states[$key] = $room_fields + [
                'floor' => $curr_floor,
                'exp' => $curr_exp,
                'items' => $curr_items,
                'level' => $curr_level,
                'lobby_id' => $lobby_id,
                'floor_entry_time' => time(),
                'entry_exp' => $curr_exp,
                'entry_items' => $curr_items,
                'pre_boss_floor' => $pre_boss,
                'in_boss' => true,
            ];

            continue;
        }

        // ── Player is still in the boss room ──
        if ($in_boss && $was_in_boss && $curr_floor === $prev_floor) {
            // (no entry reading: the arena was entered under an older state, so count from the last pass)
            $entry_exp = $prev['entry_exp'] ?? $prev['exp'] ?? $curr_exp;
            $entry_items = $prev['entry_items'] ?? $prev['items'] ?? $curr_items;
            $exp_delta = $curr_exp - $entry_exp;
            $item_delta = $curr_items - $entry_items;

            $min_exp = 20;
            $killed = ($exp_delta >= $min_exp) || ($curr_level >= 200 && $item_delta > 0);

            if ($killed && empty($prev['kill_recorded'])) {
                $entry_time = $prev['floor_entry_time'] ?? time();
                $pre_boss = $prev['pre_boss_floor'] ?? -1;
                $time_in_arena = time() - $entry_time;

                echo "[TRACKER] KILL! {$charName} (Acc {$accId}) on floor {$curr_floor} | entered " . date('H:i:s', $entry_time) . " | killed " . date('H:i:s') . " | {$time_in_arena}s in arena | EXP +{$exp_delta} items +{$item_delta}\n";

                $kill_events[$key][] = [
                    'floor' => $curr_floor,
                    'time' => time(),
                    'exp_delta' => $exp_delta,
                    'item_delta' => $item_delta,
                    'entry_time' => $entry_time,
                    'time_in_arena' => $time_in_arena,
                    'pre_boss_floor' => $pre_boss,
                    'lobby_id' => $lobby_id,
                    'episode' => $episode,
                ];
                tracker_append_debug_telemetry($charName, $episode, $curr_floor, (int)$exp_delta, (int)$item_delta);

                $new_states[$key] = array_merge($prev, $room_fields, [
                    'floor' => $curr_floor,
                    'exp' => $curr_exp,
                    'items' => $curr_items,
                    'kill_recorded' => true,
                ]);
                continue;
            }

            // Still fighting
            $new_states[$key] = array_merge($prev, $room_fields, [
                'floor' => $curr_floor,
                'exp' => $curr_exp,
                'items' => $curr_items,
            ]);
            continue;
        }

        // ── Player just left a boss room ──
        if (!$in_boss && $was_in_boss && $prev) {
            $entry_exp = $prev['entry_exp'] ?? $prev['exp'];
            $entry_items = $prev['entry_items'] ?? $prev['items'];
            $exp_delta = $curr_exp - $entry_exp;
            $item_delta = $curr_items - $entry_items;

            $min_exp = 20;
            $killed = ($exp_delta >= $min_exp) || ($curr_level >= 200 && $item_delta > 0);

            if ($killed && empty($prev['kill_recorded'])) {
                $entry_time = $prev['floor_entry_time'] ?? time();
                $pre_boss = $prev['pre_boss_floor'] ?? -1;
                $time_in_arena = time() - $entry_time;
                $boss_floor = $prev_floor;

                echo "[TRACKER] EXIT KILL! {$charName} (Acc {$accId}) floor {$boss_floor} | entered " . date('H:i:s', $entry_time) . " | exited " . date('H:i:s') . " | {$time_in_arena}s in arena | EXP +{$exp_delta}\n";

                $kill_ep = array_key_exists('episode', $prev) ? $prev['episode'] : $episode;
                $kill_events[$key][] = [
                    'floor' => $boss_floor,
                    'time' => time(),
                    'exp_delta' => $exp_delta,
                    'item_delta' => $item_delta,
                    'entry_time' => $entry_time,
                    'time_in_arena' => $time_in_arena,
                    'pre_boss_floor' => $pre_boss,
                    'lobby_id' => $prev['lobby_id'] ?? $lobby_id, // the room the arena was in
                    'episode' => $kill_ep,
                ];
                tracker_append_debug_telemetry($charName, $kill_ep !== null ? (int)$kill_ep : null, (int)$boss_floor, (int)$exp_delta, (int)$item_delta);
            }
        }

        // ── Default state ──
        $pre_boss = $prev['pre_boss_floor'] ?? -1;
        if (!$in_boss && $curr_floor > 0) {
            $pre_boss = $curr_floor;
        }

        // Track floor entry time — reset on transition, preserve if same floor
        $floor_entry_time = ($prev && $curr_floor === $prev_floor)
            ? ($prev['floor_entry_time'] ?? time())
            : time();

        $new_states[$key] = $room_fields + [
            'floor' => $curr_floor,
            'exp' => $curr_exp,
            'items' => $curr_items,
            'level' => $curr_level,
            'lobby_id' => $lobby_id,
            'pre_boss_floor' => $pre_boss,
            'floor_entry_time' => $floor_entry_time,
            'in_boss' => false,
        ];
    }

    // Prune old events (older than 5 minutes)
    $cutoff = time() - 300;
    foreach ($kill_events as $key => &$events) {
        $events = array_filter($events, fn($e) => $e['time'] > $cutoff);
        $events = array_values($events);
        if (empty($events)) unset($kill_events[$key]);
    }
    unset($events);
    foreach ($floor_clear_events as $key => &$events) {
        $events = array_filter($events, fn($e) => $e['time'] > $cutoff);
        $events = array_values($events);
        if (empty($events)) unset($floor_clear_events[$key]);
    }
    unset($events);

    // Save
    file_put_contents($state_file, json_encode($new_states));
    file_put_contents($kills_file, json_encode($kill_events));
    file_put_contents($floor_clears_file, json_encode($floor_clear_events));

    sleep(5);
}


?>
