<?php
/**
 * --------------------------------------------------------------------------
 * PSOBB Mission Cron Job Pipeline
 * --------------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/reward_tables.php';
require_once __DIR__ . '/bounty_lore.php';
require_once __DIR__ . '/bounty_generator.php';
require_once __DIR__ . '/bounty_system.php';

// Enforce CLI execution.
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

$db = get_db();


// Ensure only one instance of the daemon runs at a time
$lock_file = fopen(__DIR__ . '/../db/.cron_missions.lock', 'c');
if (!flock($lock_file, LOCK_EX | LOCK_NB)) {
    echo "[CRON] Another instance is running. Exiting.\n";
    exit;
}

// Load shared utility functions (send_personal_mail, etc.)
require_once __DIR__ . '/functions.php';


if (empty($GEMINI_API_KEY)) {
    echo "[CRON] Error: GEMINI_API_KEY is not defined in .env. Cannot generate new quests.\n";
}

// Fetch the last 15 active/recent mission titles to prevent AI trope repetition
$recent_missions = [];
$rm_res = $db->query("SELECT title FROM missions WHERE title != '' ORDER BY id DESC LIMIT 15");
if ($rm_res) {
    while ($row = $rm_res->fetchArray(SQLITE3_ASSOC)) {
        $recent_missions[] = $row['title'];
    }
}
$recent_missions_str = empty($recent_missions) ? "None" : implode(", ", $recent_missions);

// State cache file path (defined once, read/written inside loop)
$state_cache_file = __DIR__ . '/../db/.cron_player_state.json';

$script_start = time();
// Loop for up to 55 seconds to fit within a standard 1-minute crontab resolution
while (time() - $script_start < 55) {
    // 1. Fetch live clients and lobbies
    $url = $NEWSERV_API_URL . "/y/clients";
    $data = @file_get_contents($url);
    if (!$data) {
        echo "[CRON] Failed to connect to newserv API\n";
        exit;
    }

    // PSO character names may contain Shift-JIS private-use bytes (custom symbols like hearts,
    // stars, etc.) that are not valid UTF-8. Strip only those invalid bytes so json_decode
    // succeeds. Standard Unicode — including Japanese Hiragana, Katakana, and CJK — is valid
    // UTF-8 and passes through iconv unchanged.
    $data = iconv('UTF-8', 'UTF-8//IGNORE', $data);
    $clients = json_decode($data, true);

    if (!is_array($clients) || empty($clients)) {
        sleep(10);
        continue;
    }

    $lobbies_url = $NEWSERV_API_URL . "/y/lobbies";
    $lobbies_data = @file_get_contents($lobbies_url);
    $lobbies = [];
    if ($lobbies_data) {
        $lobbies = json_decode(iconv('UTF-8', 'UTF-8//IGNORE', $lobbies_data), true) ?: [];
    }
    $lobby_episode_map = [];
    foreach ($lobbies as $l) {
        if (isset($l['ID']) && isset($l['Episode'])) {
            $lobby_episode_map[$l['ID']] = $l['Episode'];
        }
    }

    echo "[CRON] Processing " . count($clients) . " clients...\n";

    // Room facts (episode, difficulty, mode, party size) for the Bounty System Rework
    $lobby_info_map = bounty_lobby_info_map($lobbies);
    if (bounty_rework_enabled()) {
        $expired_n = bounty_expire_sweep($db);
        if ($expired_n) echo "[BOUNTY] Expired {$expired_n} bounties at the reset.\n";
    }

    // Load state cache each tick to calculate real-time deltas
    $player_states = file_exists($state_cache_file) ? json_decode(file_get_contents($state_cache_file), true) : [];
    if (!is_array($player_states)) $player_states = [];

    // Load boss kill events from the high-frequency tracker (cron_boss_tracker.php, 5s intervals)
    $boss_kills_file = __DIR__ . '/../db/.boss_kills.json';
    $boss_kill_events = file_exists($boss_kills_file) ? json_decode(file_get_contents($boss_kills_file), true) : [];
    if (!is_array($boss_kill_events)) $boss_kill_events = [];

    // Load floor clear events from the tracker (for SPEEDRUN_FLOOR)
    $floor_clears_file = __DIR__ . '/../db/.floor_clears.json';
    $floor_clear_events = file_exists($floor_clears_file) ? json_decode(file_get_contents($floor_clears_file), true) : [];
    if (!is_array($floor_clear_events)) $floor_clear_events = [];

    // Each player's level when they joined their current game. Carry objectives measure the level gap
    // with these, so carried players who level up on the way to the boss still count.
    $game_join_level = [];
    // Contracts: who is in each game (a carry or a mixed party is checked at each boss, and everyone
    // there gets a share), and the rare creatures killed in each game since the last pass (a kill by
    // anyone in the game counts for every hunt contract there).
    $lobby_members = [];
    $lobby_rare_kills = [];
    foreach ($clients as $gc) {
        if (!isset($gc['Account']['AccountID'], $gc['EXP'])) continue;
        $gk = $gc['Account']['AccountID'] . '_' . ($gc['Name'] ?? 'Unknown');
        $glob = $gc['LobbyID'] ?? null;
        $gps = $player_states[$gk] ?? [];
        $game_join_level[$gk] = ($glob !== null && ($gps['game_lobby'] ?? null) === $glob && isset($gps['game_join_level']))
            ? (int) $gps['game_join_level'] : (int) ($gc['Level'] ?? 1);
        // (the main loop skips a loading screen's zero-EXP reading without saving state; so does this)
        if ($glob === null || ((int) $gc['EXP'] === 0 && (int) ($gps['exp'] ?? 0) > 0)) continue;
        $lobby_members[$glob][] = [
            'key' => $gk, 'acc' => (int) $gc['Account']['AccountID'], 'name' => $gc['Name'] ?? 'Unknown',
            'class' => (string) ($gc['CharClass'] ?? ''), 'join_level' => $game_join_level[$gk], 'client' => $gc,
        ];
        if (isset($gc['RareEnemyKillCount'], $gps['bounty_rare_kills']) && !empty($gc['LastRareEnemyKilled'])) {
            $cur = (int) $gc['RareEnemyKillCount'];
            $was = (int) $gps['bounty_rare_kills'];
            $d = ($cur >= $was) ? $cur - $was : $cur; // a lower reading: newserv's counter restarted
            if ($d > 0) {
                $lobby_rare_kills[$glob][$gc['LastRareEnemyKilled']] = ($lobby_rare_kills[$glob][$gc['LastRareEnemyKilled']] ?? 0) + $d;
            }
        }
    }
    $join_level_of = function (array $c) use ($game_join_level) {
        return $game_join_level[($c['Account']['AccountID'] ?? '') . '_' . ($c['Name'] ?? 'Unknown')] ?? (int) ($c['Level'] ?? 200);
    };

// 2. Iterate through all currently active players.
foreach ($clients as $client) {
    if (!isset($client['Account']['AccountID'])) continue;
    $accId = (string)$client['Account']['AccountID'];
    // Collect daily logins during the loop; batch-write them after to minimize
    // the number of separate write lock acquisitions.
    if (!isset($pending_logins)) $pending_logins = [];
    $charName = $client['Name'] ?? 'Unknown';
    $stateKey = $accId . '_' . $charName;
    
    // Skip players who are still connecting/loading (no character data yet)
    if (!isset($client['EXP'])) continue;
    $current_exp = $client['EXP'] ?? 0;
    $current_item_count = count($client['InventoryItems'] ?? []);
    $just_logged_in = !isset($player_states[$stateKey]);
    $prev_state = $player_states[$stateKey] ?? [];
    $prev_exp = $prev_state['exp'] ?? $current_exp;
    $prev_items = $prev_state['items'] ?? $current_item_count;
    $curr_level = $client['Level'] ?? 1;
    $prev_level = $prev_state['level'] ?? $curr_level;

    // Prevent false level-ups when level temporarily drops during loading screens
    // or when switching to a lower level character on the same account
    if ($curr_level < $prev_level) {
        $prev_level = $curr_level;
    }
    $curr_f = (int)($client['LocationFloor'] ?? -1);
    $prev_f = (int)($prev_state['floor'] ?? -1);
    
    $lobby_id = $client['LobbyID'] ?? null;
    $lobby_episode = ($lobby_id !== null && isset($lobby_episode_map[$lobby_id])) ? $lobby_episode_map[$lobby_id] : null;
    
    $floor_entered_time = ($curr_f === $prev_f) ? ($prev_state['floor_entered_time'] ?? time()) : time();
    
    // Protect against Zero-EXP spikes during loading screens where NewServ briefly reports 0 EXP.
    // If we don't skip this tick, $prev_exp gets saved as 0, and the NEXT tick will show a massive delta!
    if ($current_exp < $prev_exp && $current_exp === 0) {
        continue;
    }
    
    // Delayed EXP Sync Memory
    $last_boss_arena = $prev_state['last_boss_arena'] ?? null;
    $last_boss_arena_time = $prev_state['last_boss_arena_time'] ?? 0;
    // Track when a player is in a boss arena for delayed EXP sync detection.
    // These are the RAW LocationFloor values as reported by newserv /y/clients:
    //   Ep1: 11=Dragon, 12=De Rol Le, 13=Vol Opt, 14=Dark Falz
    //   Ep2: 12=Gal Gryphon, 13=Olga Flow, 14=Barba Ray, 15=Gol Dragon
    //   Ep4: 9=Saint-Milion
    // Note: Floor IDs overlap across episodes (e.g. 12 = De Rol Le in Ep1, Gal Gryphon in Ep2).
    // This is acceptable because we only use these for EXP-delta-based kill detection.
    $room_episode = ($lobby_id !== null) ? ($lobby_info_map[$lobby_id]['episode'] ?? null) : null;
    $arena_floors = bounty_boss_arena_floors($room_episode); // floor numbers repeat across episodes
    if (in_array($curr_f, $arena_floors)) {
        $last_boss_arena = $curr_f;
        $last_boss_arena_time = time();
    }

    // Track the last non-boss floor the player was on.
    // This is critical for episode disambiguation: when the player enters a boss room
    // (which reuses floor IDs across episodes), we need to know which dungeon they came from.
    // We strictly check $curr_f > 0 so that town/lobby (floor 0) is ignored, preserving 
    // the actual preceding dungeon floor if they go back to Pioneer 2/Lab to restock.
    $pre_boss_floor = $prev_state['pre_boss_floor'] ?? -1;
    if (!in_array($curr_f, $arena_floors) && $curr_f > 0) {
        $pre_boss_floor = $curr_f;
    }

    // =====================================================================
    // NEWSERV FLOOR ID REFERENCE (from /y/clients -> LocationFloor)
    // Each episode reuses the same floor numbering starting from 0.
    // =====================================================================
    //  ID | Episode 1       | Episode 2       | Episode 4
    // ----+-----------------+-----------------+-----------------
    //   0 | Pioneer 2       | Lab             | Pioneer 2
    //   1 | Forest 1        | VR Temple Alpha | Crater Route 1
    //   2 | Forest 2        | VR Temple Beta  | Crater Route 2
    //   3 | Cave 1          | VR Ship Alpha   | Crater Route 3
    //   4 | Cave 2          | VR Ship Beta    | Crater Route 4
    //   5 | Cave 3          | CCA             | Crater Interior
    //   6 | Mine 1          | Jungle North    | Desert 1
    //   7 | Mine 2          | Jungle South    | Desert 2
    //   8 | Ruins 1         | Mountain        | Desert 3
    //   9 | Ruins 2         | Seaside         | *Saint-Milion*
    //  10 | Ruins 3         | Seabed Upper    | —
    //  11 | *Dragon*        | Seabed Lower    | *Sil Dragon*
    //  12 | *De Rol Le*     | *Gal Gryphon*   | —
    //  13 | *Vol Opt*       | *Olga Flow*     | —
    //  14 | *Dark Falz*     | *Barba Ray*     | —
    //  15 | —               | *Gol Dragon*    | —
    // =====================================================================

    // Fetch user language preference (must be before milestone notifications)
    $lang_stmt = $db->prepare("SELECT language FROM users WHERE account_id = :acc");
    $lang_stmt->bindValue(':acc', $accId, SQLITE3_INTEGER);
    $lang_res = $lang_stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $user_lang = $lang_res ? $lang_res['language'] : 'en';

    // Feature: Automatic Milestone Unlock Notifications
    if ($curr_level > $prev_level) {
        $unlocked_milestones = [];
        for ($lvl = $prev_level + 1; $lvl <= $curr_level; $lvl++) {
            if ($lvl >= 5 && $lvl % 5 === 0) {
                $unlocked_milestones[] = $lvl;
            }
        }
        if (!empty($unlocked_milestones)) {
            $max_m = end($unlocked_milestones);
            $srvAddr = get_server_address();
            if ($user_lang === 'jp') {
                $msg = ($client['Name'] ?? 'ハンター') . " おめでとうございます！\nLv{$max_m}のマイルストーン報酬をアンロックしました！\n{$srvAddr}で受け取ってください。";
                send_personal_mail($accId, "ハンターズギルド", $msg);
            } elseif ($user_lang === 'ru') {
                $msg = "Поздравляем, " . ($client['Name'] ?? 'Охотник') . "!\nВы разблокировали награду за ур.{$max_m}!\nЗаберите её на сайте: {$srvAddr}";
                send_personal_mail($accId, "Гильдия Охотников", $msg);
            } else {
                $msg = "Congrats " . ($client['Name'] ?? 'Hunter') . "!\nYou unlocked a Lv{$max_m} Milestone Reward!\nRedeem it on the website: {$srvAddr}";
                send_personal_mail($accId, "Hunters Guild", $msg);
            }
        }
    }

    // Defer daily login INSERT until after the loop (see batch-write below)
    $pending_logins[] = $accId;

    // Feature: Unclaimed Rewards Login Notification
    if ($just_logged_in) {
        $today = date('Y-m-d');
        // 1. Check for unclaimed daily reward
        $stmt_daily = $db->prepare("SELECT COUNT(*) FROM daily_rewards WHERE account_id = :aid AND claim_date = :date");
        $stmt_daily->bindValue(':aid', $accId, SQLITE3_INTEGER);
        $stmt_daily->bindValue(':date', $today, SQLITE3_TEXT);
        $has_claimed_daily = $stmt_daily->execute()->fetchArray(SQLITE3_NUM)[0] > 0;

        // 2. Check for unclaimed level milestones
        $max_milestones = floor($curr_level / 5);
        $stmt_ms = $db->prepare("SELECT COUNT(*) FROM rewards_claimed WHERE account_id = :aid AND character_name = :cname");
        $stmt_ms->bindValue(':aid', $accId, SQLITE3_INTEGER);
        $stmt_ms->bindValue(':cname', $charName, SQLITE3_TEXT);
        $claimed_milestones = $stmt_ms->execute()->fetchArray(SQLITE3_NUM)[0];
        $has_unclaimed_ms = $max_milestones > $claimed_milestones;

        if (!$has_claimed_daily || $has_unclaimed_ms) {
            $srvAddr = get_server_address();
            if ($user_lang === 'jp') {
                $msg = "ようこそ！\n{$srvAddr}で受け取っていない報酬があります。\nぜひ確認してください！";
                send_personal_mail($accId, "ハンターズギルド", $msg);
            } elseif ($user_lang === 'ru') {
                $msg = "С возвращением!\nУ вас есть неполученные награды на {$srvAddr}.\nНе забудьте забрать их!";
                send_personal_mail($accId, "Гильдия Охотников", $msg);
            } else {
                $msg = "Welcome back!\nYou have rewards waiting to be claimed on {$srvAddr}.\nDon't forget to check them!";
                send_personal_mail($accId, "Hunters Guild", $msg);
            }
        }
    }

    // 3. Fetch all active "in-progress" missions for the CURRENT CHARACTER.
    // If character_name is null/empty (legacy missions), it will still be evaluated for backwards compatibility.
    // However, new missions will strictly be tied to a character.
    // New kills since the last pass (boss events from cron_boss_tracker.php; enemy and rare-enemy
    // counts from newserv's EnemyKillCount / RareEnemyKillCount when the server reports them)
    $lobby_info = ($lobby_id !== null) ? ($lobby_info_map[$lobby_id] ?? null) : null;
    $kills = bounty_kill_deltas($client, $prev_state, $boss_kill_events[$stateKey] ?? [], $lobby_info);
    $completed_this_pass = [];

    $stmt = $db->prepare("SELECT pm.id, pm.mission_id, u.discord_id, pm.character_name, m.title AS mission_title, m.description AS mission_desc, m.goal_type, m.goal_target, m.reward_item_string,
                                 pm.activated_at, pm.progress, m.tier, m.cadence, m.pool, m.meta
                          FROM player_missions pm 
                          JOIN missions m ON pm.mission_id = m.id 
                          JOIN users u ON pm.account_id = u.account_id
                          WHERE pm.account_id = :acc AND pm.status = 'in_progress'
                          AND (pm.character_name = :cname OR pm.character_name IS NULL OR pm.character_name = '')");
    $stmt->bindValue(':acc', $accId, SQLITE3_INTEGER);
    $stmt->bindValue(':cname', $charName, SQLITE3_TEXT);
    $res = $stmt->execute();
    
    $has_active_missions = false; 
    $active_mission_count = 0;
    $completed_any = false;
    $last_completed_type = "training";
    $current_patrols = [];
    $current_patrol_seen = [];
    $active_mission_types = [];

    while ($m = $res->fetchArray(SQLITE3_ASSOC)) {
        $has_active_missions = true;
        $active_mission_count++;
        $active_mission_types[] = $m['goal_type'];
        $completed = false;

        // Uncommon and Rare dailies in an area or at a boss count only on their difficulty or higher
        // (missions.meta min_difficulty, set when the bounty starts)
        $min_diff = bounty_meta_min_difficulty($m['meta'] ?? null);
        $saved_boss_events = null;
        if ($min_diff && in_array($m['goal_type'], BOUNTY_DAILY_PLACE_GOALS, true)) {
            $need_rank = bounty_difficulty_rank($min_diff);
            $here_ok = $lobby_info && !empty($lobby_info['is_game']) && bounty_difficulty_rank($lobby_info['difficulty'] ?? 'Normal') >= $need_rank;
            if (in_array($m['goal_type'], ['EXPLORATION', 'PATROL', 'SPEEDRUN_FLOOR'], true) && !$here_ok) {
                if ($m['goal_type'] === 'PATROL') {
                    $current_patrols[$m['id']] = (int) ($prev_state['patrol'][$m['id']] ?? 0);
                }
                continue;
            }
            if (isset($boss_kill_events[$stateKey])) {
                // only boss kills in a game of that difficulty (the kill's own game, or this one)
                $saved_boss_events = $boss_kill_events[$stateKey];
                $boss_kill_events[$stateKey] = array_values(array_filter($saved_boss_events, function ($e) use ($lobby_info_map, $lobby_info, $need_rank) {
                    $l = $lobby_info_map[$e['lobby_id'] ?? -1] ?? $lobby_info;
                    return !$l || bounty_difficulty_rank($l['difficulty'] ?? 'Normal') >= $need_rank;
                }));
            }
        }
        
        // 4. Mission Evaluation Engine
        if ($m['goal_type'] === 'MESETA' && ($client['Meseta'] ?? 0) >= (int)$m['goal_target']) {
            $completed = true;
        } elseif ($m['goal_type'] === 'LEVEL' && ($client['Level'] ?? 1) >= (int)$m['goal_target']) {
            $completed = true;
        } elseif ($m['goal_type'] === 'PLAYTIME' && ($client['PlayTimeSeconds'] ?? 0) >= (int)$m['goal_target']) {
            $completed = true;
        } elseif ($m['goal_type'] === 'ITEM') {
            $inventory = $client['InventoryItems'] ?? [];
            $target_val = trim((string)$m['goal_target']);
            $found_match = false;
            
            // Map of generic weapon types to their Hex IDs for backwards compatibility
            $generic_weapon_hex_map = [
                'Saber' => '000100', 'Brand' => '000101', 'Buster' => '000102', 'Pallasch' => '000103', 'Gladius' => '000104',
                'Sword' => '000200', 'Gigush' => '000201', 'Breaker' => '000202', 'Claymore' => '000203', 'Calibur' => '000204',
                'Dagger' => '000300', 'Knife' => '000301', 'Blade' => '000302', 'Edge' => '000303', 'Ripper' => '000304',
                'Partisan' => '000400', 'Halbert' => '000401', 'Glaive' => '000402', 'Berdys' => '000403', 'Gungnir' => '000404',
                'Slicer' => '000500', 'Spinner' => '000501', 'Cutter' => '000502', 'Sawcer' => '000503', 'Diska' => '000504',
                'Handgun' => '000600', 'Autogun' => '000601', 'Lockgun' => '000602', 'Railgun' => '000603', 'Raygun' => '000604',
                'Rifle' => '000700', 'Sniper' => '000701', 'Blaster' => '000702', 'Beam' => '000703', 'Laser' => '000704',
                'Mechgun' => '000800', 'Assault' => '000801', 'Repeater' => '000802', 'Gatling' => '000803', 'Vulcan' => '000804',
                'Shot' => '000900', 'Spread' => '000901', 'Cannon' => '000902', 'Launcher' => '000903', 'Arms' => '000904',
                'Cane' => '000A00', 'Stick' => '000A01', 'Mace' => '000A02', 'Club' => '000A03',
                'Rod' => '000B00', 'Pole' => '000B01', 'Pillar' => '000B02', 'Striker' => '000B03',
                'Wand' => '000C00', 'Staff' => '000C01', 'Baton' => '000C02', 'Scepter' => '000C03',
                'Talis' => '008C00', 'Mahu' => '008C01', 'Hitogata' => '008C02'
            ];
            
            // Backwards compatibility for existing string-based missions (e.g. 'Stick')
            if (!ctype_xdigit($target_val)) {
                $target_val = $generic_weapon_hex_map[$target_val] ?? null;
            } else {
                // Remove any modifiers from the hex if present
                $target_val = explode(' ', $target_val)[0];
            }
            
            if ($target_val) {
                foreach ($inventory as $inv_item) {
                    if (isset($inv_item['Data']) && strpos($inv_item['Data'], $target_val) === 0) {
                        $completed = true;
                        $found_match = true;
                        break;
                    }
                }
            }
        } elseif ($m['goal_type'] === 'TECHNIQUE') {
            $tech_levels = $client['TechniqueLevels'] ?? [];
            foreach ($tech_levels as $tech_name => $level) {
                if ($level !== null && strpos(strtolower($tech_name), strtolower((string)$m['goal_target'])) !== false) {
                    $completed = true;
                    break;
                }
            }
        } elseif ($m['goal_type'] === 'BATTLE_WINS') {
            $battle_counts = $client['BattlePlaceCounts'] ?? [0,0,0,0];
            if (($battle_counts[0] ?? 0) >= (int)$m['goal_target']) {
                $completed = true;
            }
        } elseif ($m['goal_type'] === 'CHALLENGE_STAGES') {
            // newserv always reports all 9 + 5 stage times (65535 = not cleared), so count the cleared ones
            if (challenge_stages_cleared($client) >= (int)$m['goal_target']) {
                $completed = true;
            }
        } elseif (strpos($m['goal_type'], 'MAT') === 0) {
            $mat_map = [
                'MAT_HP' => 'NumHPMaterialsUsed',
                'MAT_TP' => 'NumTPMaterialsUsed',
                'MAT_POWER' => 'NumPowerMaterialsUsed',
                'MAT_DEF' => 'NumDefMaterialsUsed',
                'MAT_MIND' => 'NumMindMaterialsUsed',
                'MAT_EVADE' => 'NumEvadeMaterialsUsed',
                'MAT_LUCK' => 'NumLuckMaterialsUsed'
            ];
            $key = $mat_map[$m['goal_type']] ?? null;
            if ($key && ($client[$key] ?? 0) >= (int)$m['goal_target']) {
                $completed = true;
            }
        } elseif ($m['goal_type'] === 'PATROL') { 
            // "Survive and patrol the area for 10 minutes": seconds actually spent on the floor. It used to
            // count cron passes (about 10 s apart), so ten of them finished it in under two minutes.
            // A gap between passes counts for at most a minute, so a pause or a restart adds little.
            $patrol_secs = (int) ($prev_state['patrol'][$m['id']] ?? 0);
            if (($client['LocationFloor'] ?? -1) === (int)$m['goal_target']) {
                $seen = (int) ($prev_state['patrol_seen'][$m['id']] ?? 0);
                if ($seen > 0) {
                    $patrol_secs += min(60, max(0, time() - $seen));
                }
                $current_patrol_seen[$m['id']] = time();
                if ($patrol_secs >= 600) {
                    $completed = true;
                }
            }
            $current_patrols[$m['id']] = $patrol_secs;
        } elseif ($m['goal_type'] === 'EXPLORATION') {
            // LocationFloor tracks exactly which area the player is currently occupying
            if (($client['LocationFloor'] ?? -1) === (int)$m['goal_target']) {
                $completed = true;
            }
        } elseif ($m['goal_type'] === 'BOSS_ARENA' || $m['goal_type'] === 'MENTOR_BOSS' || $m['goal_type'] === 'HARDCORE_MENTOR' || $m['goal_type'] === 'DIVERSE_PARTY_BOSS') { 
            // ── PRIMARY: Check the high-frequency boss tracker for confirmed kills ──
            $tracker_kill = null;
            $tracker_key = $stateKey;
            if (isset($boss_kill_events[$tracker_key])) {
                $original_target = $m['goal_target'];
                $check_floor = ($original_target === 'ANY_DRAGON') ? null : (int)$original_target;
                
                foreach ($boss_kill_events[$tracker_key] as $kill_evt) {
                    // A kill from before the bounty became active does not count
                    if (!empty($m['activated_at']) && (int) $kill_evt['time'] < (int) $m['activated_at']) continue;
                    // For ANY_DRAGON, match any dragon floor
                    if ($original_target === 'ANY_DRAGON') {
                        $kep = $kill_evt['episode'] ?? null; // the tracker's room episode, when it knew it
                        if (($kill_evt['floor'] === 11 && ($kep === null || (int) $kep === 1)) || ($kill_evt['floor'] === 15 && ($kep === null || (int) $kep === 2))) {
                            $tracker_kill = $kill_evt;
                            break;
                        }
                    } else if ($kill_evt['floor'] === $check_floor) {
                        // Episode validation using pre_boss_floor
                        $episode = get_boss_episode_by_context($m['mission_title'], $m['mission_desc'] ?? '', $check_floor, parse_boss_target($original_target)['episode']);
                        $valid_preceding_floors = [
                            '11_1' => [1, 2], '12_1' => [3, 4, 5], '12_2' => [5, 6, 7, 8, 9],
                            '13_1' => [6, 7], '13_2' => [10, 11], '14_1' => [8, 9, 10],
                            '14_2' => [1, 2], '15_2' => [3, 4], '9_4' => [5, 6, 7, 8],
                        ];
                        $comp_key = "{$check_floor}_{$episode}";
                        if (isset($kill_evt['episode']) && (int) $kill_evt['episode'] !== (int) $episode) {
                            echo "[CRON] Boss tracker kill on floor {$check_floor} rejected: Episode {$kill_evt['episode']} room, bounty is Episode {$episode}\n";
                            continue;
                        }
                        $valid = $valid_preceding_floors[$comp_key] ?? null;
                        if ($valid !== null && $kill_evt['pre_boss_floor'] >= 0 && !in_array($kill_evt['pre_boss_floor'], $valid)) {
                            echo "[CRON] Boss tracker kill on floor {$check_floor} rejected: pre_boss_floor={$kill_evt['pre_boss_floor']} wrong episode\n";
                            continue;
                        }
                        $tracker_kill = $kill_evt;
                        break;
                    }
                }
            }
            
            if ($tracker_kill) {
                echo "[CRON] Boss kill confirmed via tracker for {$charName} on floor {$tracker_kill['floor']} (EXP +{$tracker_kill['exp_delta']}, arena time {$tracker_kill['time_in_arena']}s)\n";
                if ($m['goal_type'] === 'MENTOR_BOSS') {
                    $mentored = false;
                    $my_level = $join_level_of($client); // levels as they were when each joined the game
                    $my_lobby = $client['LobbyID'] ?? -1;
                    
                    if ($my_lobby !== -1) {
                        foreach ($clients as $other_client) {
                            if (($other_client['LobbyID'] ?? -2) === $my_lobby && ($other_client['Account']['AccountID'] ?? -1) !== ($client['Account']['AccountID'] ?? -1)) {
                                if ($join_level_of($other_client) <= ($my_level - 5)) {
                                    $mentored = true;
                                    break;
                                }
                            }
                        }
                    }
                    if ($mentored) {
                        $completed = true;
                    }
                // =====================================================================
                // HARDCORE_MENTOR — Team Bounty: Veteran carries 3+ rookies through a boss.
                // Requires the mentor (bounty owner) to be Level 30+ and have 3 or more
                // party members who are at least 10 levels below them.
                // 
                // On completion, ALL mentees receive an auto-generated rare reward
                // injected directly into their player_missions as 'ready_to_redeem'.
                // Each mentee's reward is class-appropriate (Weapon/Armor/Shield)
                // using get_reward_item() from reward_tables.php.
                //
                // Verified against newserv source (HTTPServer.cc, StaticGameData.cc):
                //   - /y/clients endpoint returns 'CharClass' as a STRING (e.g. 'HUmar')
                //     via name_for_char_class() — never a numeric ID.
                //   - 'Account' is a nested JSON object with 'AccountID' inside it.
                //   - 'LobbyID' is the game/lobby instance ID for party matching.
                //   - 'Name' is the decoded character name string.
                //   - 'Level' is 1-indexed (raw level + 1).
                // =====================================================================
                } elseif ($m['goal_type'] === 'HARDCORE_MENTOR') {
                    $mentees = [];
                    $my_level = $join_level_of($client); // levels as they were when each joined the game
                    $my_lobby = $client['LobbyID'] ?? -1;
                    
                    // Scan all connected clients for party members in the same lobby
                    // who are at least 10 levels below the mentor
                    if ($my_lobby !== -1) {
                        foreach ($clients as $other_client) {
                            if (($other_client['LobbyID'] ?? -2) === $my_lobby && ($other_client['Account']['AccountID'] ?? -1) !== ($client['Account']['AccountID'] ?? -1)) {
                                if ($join_level_of($other_client) <= ($my_level - 10)) {
                                    $mentees[] = $other_client;
                                }
                            }
                        }
                    }
                    // Require at least 3 mentees to qualify as a Hardcore Mentor bounty
                    if (count($mentees) >= 3) {
                        $completed = true;
                        if (!function_exists('get_reward_item')) {
                            require_once 'reward_tables.php';
                        }
                        // Fallback class_map for numeric IDs. In practice, newserv always
                        // returns CharClass as a string (verified in StaticGameData.cc:294).
                        // NOTE: Newserv's internal class ID ordering differs from standard PSO client
                        // ordering (e.g. newserv ID 3=RAmar, not HUcaseal), but this map is only
                        // used as a fallback when the API returns null/0, defaulting to HUmar.
                        $class_map = [0 => 'HUmar', 1 => 'HUnewearl', 2 => 'HUcast', 3 => 'RAmar', 4 => 'RAcast', 5 => 'RAcaseal', 6 => 'FOmarl', 7 => 'FOnewm', 8 => 'FOnewearl', 9 => 'HUcaseal', 10 => 'FOmar', 11 => 'RAmarl'];
                        foreach ($mentees as $mentee) {
                            $m_acc = $mentee['Account']['AccountID'] ?? 0;   // Nested: Account.AccountID (Account.cc:263)
                            $m_char = $mentee['Name'] ?? 'Unknown';          // Decoded character name (HTTPServer.cc:180)
                            // Resolve class: newserv /y/clients uses 'CharClass' (always a string),
                            // but we also check 'Class' for /y/summary compatibility.
                            $m_class_raw = $mentee['CharClass'] ?? $mentee['Class'] ?? 0;
                            if (is_string($m_class_raw) && !is_numeric($m_class_raw)) {
                                $m_class_str = $m_class_raw; // Already a string like 'HUmar' — use directly
                            } else {
                                $m_class_str = $class_map[(int)$m_class_raw] ?? 'HUmar'; // Numeric fallback
                            }
                            $m_lvl = $mentee['Level'] ?? 1;
                            // Randomly pick a reward category so mentees get varied loot
                            $reward_category = ['Weapon', 'Armor', 'Shield'][array_rand(['Weapon', 'Armor', 'Shield'])];
                            // get_reward_item() returns rare-tier items from the class-specific pool.
                            // Signature: get_reward_item($level, $charClassString, $category)
                            $mentee_reward = get_reward_item($m_lvl, $m_class_str, $reward_category);
                            
                            // Create a new mission record for the mentee's reward
                            $ins = $db->prepare("INSERT INTO missions (title, description, goal_type, goal_target, reward_item_string) VALUES ('Surviving the Hardcore Carry', 'You survived a brutal boss carry from a veteran Hunter!', 'HARDCORE_MENTOR', '0', :ri)");
                            $ins->bindValue(':ri', $mentee_reward, SQLITE3_TEXT);
                            $ins->execute();
                            $new_m_id = $db->lastInsertRowID();
                            
                            // Assign the mission directly as 'ready_to_redeem' — no objective needed
                            $assign = $db->prepare("INSERT INTO player_missions (account_id, character_name, mission_id, status) VALUES (:acc, :cname, :mid, 'ready_to_redeem')");
                            $assign->bindValue(':acc', $m_acc, SQLITE3_INTEGER);
                            $assign->bindValue(':cname', $m_char, SQLITE3_TEXT);
                            $assign->bindValue(':mid', $new_m_id, SQLITE3_INTEGER);
                            $assign->execute();
                            
                            // Notify the mentee in-game via personal mail
                            $m_lang_row = $db->querySingle("SELECT language FROM users WHERE account_id = " . intval($m_acc), true);
                            $m_lang = $m_lang_row['language'] ?? 'en';
                            $srvAddr = get_server_address();
                            if ($m_lang === 'jp') {
                                send_personal_mail($m_acc, "ハンターズギルド", "ハードキャリーをクリアしました！{$srvAddr}でレア報酬を受け取ってください。");
                            } elseif ($m_lang === 'ru') {
                                send_personal_mail($m_acc, "Гильдия Охотников", "Вы выжили в хардкорном сопровождении! Заберите награду на сайте {$srvAddr}.");
                            } else {
                                send_personal_mail($m_acc, "Hunters Guild", "You survived a Hardcore Carry! Check {$srvAddr} to claim your rare reward.");
                            }
                            @touch(__DIR__ . '/../db/.bounty_' . $m_acc);
                        }
                    }
                // =====================================================================
                // DIVERSE_PARTY_BOSS — Team Bounty: Kill a boss with all 3 class types.
                // Requires at least one Hunter (HU*), one Ranger (RA*), and one Force (FO*)
                // present in the same lobby/game during the boss kill.
                //
                // On completion, ALL other party members (excluding the bounty owner)
                // receive a class-appropriate rare reward auto-injected as 'ready_to_redeem'.
                //
                // Class detection uses string prefix matching ('HU', 'RA', 'FO') on the
                // CharClass field. Newserv always returns class names like 'HUmar', 'RAcast',
                // 'FOnewearl' etc. (verified in StaticGameData.cc:294-297).
                // The numeric fallback handles edge cases where the API might return null.
                //
                // NOTE: The numeric ID ranges in the fallback do NOT match newserv's internal
                // class ordering (newserv: 0=HUmar,3=RAmar,6=FOmarl,9=HUcaseal,10=FOmar,11=RAmarl).
                // This is acceptable because the string path is always taken in production.
                // =====================================================================
                } elseif ($m['goal_type'] === 'DIVERSE_PARTY_BOSS') {
                    $party_classes = [];
                    $my_lobby = $client['LobbyID'] ?? -1;
                    // Scan ALL players in this lobby (including self) to check class diversity
                    if ($my_lobby !== -1) {
                        foreach ($clients as $other_client) {
                            if (($other_client['LobbyID'] ?? -2) === $my_lobby) {
                                $c_class = $other_client['CharClass'] ?? $other_client['Class'] ?? 0;
                                // String path (always taken with newserv /y/clients — CharClass is a string)
                                if (is_string($c_class) && !is_numeric($c_class)) {
                                    if (strpos($c_class, 'HU') === 0) $party_classes['HU'] = true;
                                    elseif (strpos($c_class, 'RA') === 0) $party_classes['RA'] = true;
                                    elseif (strpos($c_class, 'FO') === 0) $party_classes['FO'] = true;
                                } else {
                                    // Numeric fallback — uses newserv class IDs (StaticGameData.cc:294)
                                    // HU: 0=HUmar, 1=HUnewearl, 2=HUcast, 9=HUcaseal
                                    // RA: 3=RAmar, 4=RAcast, 5=RAcaseal, 11=RAmarl
                                    // FO: 6=FOmarl, 7=FOnewm, 8=FOnewearl, 10=FOmar
                                    if (in_array($c_class, [0, 1, 2, 9])) $party_classes['HU'] = true;
                                    elseif (in_array($c_class, [3, 4, 5, 11])) $party_classes['RA'] = true;
                                    elseif (in_array($c_class, [6, 7, 8, 10])) $party_classes['FO'] = true;
                                }
                            }
                        }
                    }
                    // All three class archetypes must be present for completion
                    if (isset($party_classes['HU']) && isset($party_classes['RA']) && isset($party_classes['FO'])) {
                        $completed = true;
                        
                        if (!function_exists('get_reward_item')) {
                            require_once 'reward_tables.php';
                        }
                        
                        // Fallback class_map (see HARDCORE_MENTOR comments above for details)
                        $class_map = [0 => 'HUmar', 1 => 'HUnewearl', 2 => 'HUcast', 3 => 'RAmar', 4 => 'RAcast', 5 => 'RAcaseal', 6 => 'FOmarl', 7 => 'FOnewm', 8 => 'FOnewearl', 9 => 'HUcaseal', 10 => 'FOmar', 11 => 'RAmarl'];
                        
                        // Collect all OTHER party members (exclude the bounty owner from rewards)
                        $party_members = [];
                        foreach ($clients as $other_client) {
                            if (($other_client['LobbyID'] ?? -2) === $my_lobby && ($other_client['Account']['AccountID'] ?? -1) !== ($client['Account']['AccountID'] ?? -1)) {
                                $party_members[] = $other_client;
                            }
                        }
                        
                        // Inject a rare reward for each party member
                        foreach ($party_members as $member) {
                            $m_acc = $member['Account']['AccountID'] ?? 0;   // Nested: Account.AccountID
                            $m_char = $member['Name'] ?? 'Unknown';          // Decoded character name
                            // Resolve class string (see HARDCORE_MENTOR comments for full explanation)
                            $m_class_raw = $member['CharClass'] ?? $member['Class'] ?? 0;
                            if (is_string($m_class_raw) && !is_numeric($m_class_raw)) {
                                $m_class_str = $m_class_raw; // String from newserv — use directly
                            } else {
                                $m_class_str = $class_map[(int)$m_class_raw] ?? 'HUmar'; // Numeric fallback
                            }
                            $m_lvl = $member['Level'] ?? 1;
                            $reward_category = ['Weapon', 'Armor', 'Shield'][array_rand(['Weapon', 'Armor', 'Shield'])];
                            $member_reward = get_reward_item($m_lvl, $m_class_str, $reward_category);
                            
                            // Create mission record with the generated reward
                            $ins = $db->prepare("INSERT INTO missions (title, description, goal_type, goal_target, reward_item_string) VALUES ('Diverse Party Bonus', 'You contributed to completing a Team Bounty by fulfilling a diverse class requirement!', 'DIVERSE_PARTY_BOSS', '0', :ri)");
                            $ins->bindValue(':ri', $member_reward, SQLITE3_TEXT);
                            $ins->execute();
                            $new_m_id = $db->lastInsertRowID();
                            
                            // Assign directly as redeemable — no further objective needed
                            $assign = $db->prepare("INSERT INTO player_missions (account_id, character_name, mission_id, status) VALUES (:acc, :cname, :mid, 'ready_to_redeem')");
                            $assign->bindValue(':acc', $m_acc, SQLITE3_INTEGER);
                            $assign->bindValue(':cname', $m_char, SQLITE3_TEXT);
                            $assign->bindValue(':mid', $new_m_id, SQLITE3_INTEGER);
                            $assign->execute();
                            
                            // Notify the party member in-game
                            $m_lang_row = $db->querySingle("SELECT language FROM users WHERE account_id = " . intval($m_acc), true);
                            $m_lang = $m_lang_row['language'] ?? 'en';
                            $srvAddr = get_server_address();
                            if ($m_lang === 'jp') {
                                send_personal_mail($m_acc, "ハンターズギルド", "チームバウンティを達成しました！{$srvAddr}でレア報酬を受け取ってください。");
                            } elseif ($m_lang === 'ru') {
                                send_personal_mail($m_acc, "Гильдия Охотников", "Вы завершили командный заказ! Заберите награду на сайте {$srvAddr}.");
                            } else {
                                send_personal_mail($m_acc, "Hunters Guild", "You completed a Team Bounty! Check {$srvAddr} to claim your rare reward.");
                            }
                            @touch(__DIR__ . '/../db/.bounty_' . $m_acc);
                        }
                    }
                } else {
                    $completed = true;
                }
            }
        } elseif ($m['goal_type'] === 'SPEEDRUN_BOSS') {
            // ── High-frequency tracker for speedrun boss kills ──
            list($speedrun_target_id, $time_limit) = explode('_', $m['goal_target']);
            $speedrun_target_id = (int)$speedrun_target_id;
            $time_limit = (int)$time_limit;

            $tracker_key = $stateKey;
            if (isset($boss_kill_events[$tracker_key])) {
                $episode = get_boss_episode_by_context($m['mission_title'], $m['mission_desc'] ?? '', $speedrun_target_id, parse_boss_target($m['goal_target'])['episode']);
                $valid_preceding = [
                    '11_1' => [1, 2], '12_1' => [3, 4, 5], '12_2' => [5, 6, 7, 8, 9],
                    '13_1' => [6, 7], '13_2' => [10, 11], '14_1' => [8, 9, 10],
                    '14_2' => [1, 2], '15_2' => [3, 4], '9_4' => [5, 6, 7, 8],
                ];
                $comp_key = "{$speedrun_target_id}_{$episode}";

                foreach ($boss_kill_events[$tracker_key] as $kill_evt) {
                    if ($kill_evt['floor'] !== $speedrun_target_id) continue;
                    if (!empty($m['activated_at']) && (int) $kill_evt['time'] < (int) $m['activated_at']) continue;
                    
                    if (isset($kill_evt['episode']) && (int) $kill_evt['episode'] !== (int) $episode) {
                        echo "[CRON] SPEEDRUN_BOSS tracker kill on floor {$speedrun_target_id} rejected: Episode {$kill_evt['episode']} room\n";
                        continue;
                    }
                    // Episode validation via pre_boss_floor
                    $valid = $valid_preceding[$comp_key] ?? null;
                    if ($valid !== null && $kill_evt['pre_boss_floor'] >= 0 && !in_array($kill_evt['pre_boss_floor'], $valid)) {
                        echo "[CRON] SPEEDRUN_BOSS tracker kill on floor {$speedrun_target_id} rejected: wrong episode\n";
                        continue;
                    }
                    
                    // Time check using tracker's precise arena timing (5s resolution)
                    if ($kill_evt['time_in_arena'] <= $time_limit) {
                        $completed = true;
                        echo "[CRON] SPEEDRUN_BOSS completed! {$charName} killed floor {$speedrun_target_id} in {$kill_evt['time_in_arena']}s (limit: {$time_limit}s)\n";
                    } else {
                        echo "[CRON] SPEEDRUN_BOSS too slow: {$kill_evt['time_in_arena']}s > {$time_limit}s limit\n";
                    }
                    break;
                }
            }
        } elseif ($m['goal_type'] === 'SPEEDRUN_FLOOR') {
            // ── High-frequency tracker for floor clear speedruns ──
            list($target_floor, $time_limit) = explode('_', $m['goal_target']);
            $target_floor = (int)$target_floor;
            $time_limit = (int)$time_limit;

            $valid_next = [$target_floor + 1];
            if ($target_floor === 2) { $valid_next = [3, 11, 14]; }
            elseif ($target_floor === 4) { $valid_next = [5, 11, 15]; }
            elseif ($target_floor === 5) { $valid_next = [6, 7, 8, 9, 12]; }
            elseif ($target_floor === 7) { $valid_next = [8, 13]; }
            elseif ($target_floor === 8) { $valid_next = [9, 12]; }
            elseif ($target_floor === 9) { $valid_next = [10, 12]; }
            elseif ($target_floor === 10) { $valid_next = [11, 14]; }
            elseif ($target_floor === 11) { $valid_next = [12, 13]; }

            $tracker_key = $stateKey;
            if (isset($floor_clear_events[$tracker_key])) {
                foreach ($floor_clear_events[$tracker_key] as $fc_evt) {
                    if ($fc_evt['from_floor'] === $target_floor && in_array($fc_evt['to_floor'], $valid_next)) {
                        if ($fc_evt['time_on_floor'] <= $time_limit) {
                            $completed = true;
                            echo "[CRON] SPEEDRUN_FLOOR completed! {$charName} cleared floor {$target_floor} in {$fc_evt['time_on_floor']}s (limit: {$time_limit}s)\n";
                        } else {
                            echo "[CRON] SPEEDRUN_FLOOR too slow: {$fc_evt['time_on_floor']}s > {$time_limit}s limit\n";
                        }
                        break;
                    }
                }
            }
        } elseif ($m['goal_type'] === 'EPISODE_RUN' || $m['goal_type'] === 'RARE_TARGET') {
            // Contracts (api/bounty_contracts.php): full episode runs and rare-creature hunts
            $contract_progress = null;
            $completed = bounty_evaluate_contract($db, $m, $client, $lobby_info, $kills,
                ['key' => $stateKey, 'members' => $lobby_members, 'lobby_rares' => $lobby_rare_kills], $contract_progress);
            if ($completed) {
                // everyone else in the game gets a share, a tier lower, built for them
                $share_lobby = $contract_progress['lobby'] ?? $lobby_id;
                bounty_contract_pay_shares($db, $m, $client, $contract_progress, $lobby_members[$share_lobby] ?? []);
            }
        } elseif (in_array($m['goal_type'], ['AREA_SWEEP', 'BOSS_HUNT', 'ENEMY_HUNT', 'RARE_HUNT', 'CHALLENGE_RUN', 'BATTLE_RULE_WINS', 'PLAY_HOURS'], true)) {
            // Objectives of the Bounty System Rework with progress (kept in player_missions.progress)
            $completed = bounty_evaluate_weekly($db, $m, $client, $lobby_info, $kills, $lobby_info_map);
        }

        if ($saved_boss_events !== null) {
            $boss_kill_events[$stateKey] = $saved_boss_events;
        }

        // 5. Completion Handling
        if ($completed) {
            $completed_any = true;
            $last_completed_type = $m['goal_type'];
            $completed_this_pass[] = $m;
            echo "[CRON] Account $accId completed mission " . $m['mission_id'] . "\n";
            
            $upd = $db->prepare("UPDATE player_missions SET status = 'ready_to_redeem' WHERE id = :id");
            $upd->bindValue(':id', $m['id'], SQLITE3_INTEGER);
            $upd->execute();

            // Touch marker file to notify the web portal of the change (lightweight polling)
            @touch(__DIR__ . '/../db/.bounty_' . $accId);

            $srvAddr = get_server_address();
            if ($user_lang === 'jp') {
                $completion_msg = "ミッション「" . ($m['mission_title'] ?? '') . "」を達成しました！\n報酬は{$srvAddr}で受け取ってください。";
                send_personal_mail($accId, "ハンターズギルド", $completion_msg);
            } elseif ($user_lang === 'ru') {
                $completion_msg = "Вы выполнили миссию: " . ($m['mission_title'] ?? 'Неизвестно') . "!\nЗаберите награду на {$srvAddr}.";
                send_personal_mail($accId, "Гильдия Охотников", $completion_msg);
            } else {
                $completion_msg = "You have completed the mission: " . ($m['mission_title'] ?? 'Unknown') . "!\nRedeem your reward at {$srvAddr}.";
                send_personal_mail($accId, "Hunters Guild", $completion_msg);
            }
        }
    }



    // 7. New bounties
    $bounty_state = [];
    if (bounty_rework_enabled()) {
        // Bounty System Rework (api/bounty_system.php): drops, earning under the caps, Battle and
        // Challenge pools, then at most one activation (one Gemini call) for this player this pass.
        $bounty_state = bounty_tick_for_client($db, $client, [
            'account_id' => $accId,
            'char_name' => $charName,
            'user_lang' => $user_lang,
            'curr_f' => $curr_f,
            'lobby' => $lobby_info,
            'lobby_map' => $lobby_info_map,
            'kills' => $kills,
            'completed' => $completed_this_pass,
            'active_types' => $active_mission_types,
            'recent_titles' => $recent_missions_str,
        ]);
    } else {
        // Original flow: below 3 active bounties, a 10% chance each pass (about one a minute) of a
        // new one, so a new bounty arrives about every 10 minutes on average.
        $random_catch = (rand(1, 100) <= 10);
        if ($active_mission_count < 3 && $random_catch && !empty($GEMINI_API_KEY) && isset($client['Name'])) {
            echo "[CRON] Generating new Gemini quest for " . $client['Name'] . "\n";
            $obj = bounty_pick_objective($client, $curr_f, $active_mission_types);
            $story = bounty_write_story($client, $obj, [
                'db' => $db,
                'account_id' => $accId,
                'user_lang' => $user_lang,
                'recent_titles' => $recent_missions_str,
                'completed_any' => $completed_any,
                'last_completed_type' => $last_completed_type,
            ]);
            if ($story) {
                $reward_item_string = bounty_legacy_reward($obj, $story['quest_giver']);
                $ins = $db->prepare("INSERT INTO missions (title, description, goal_type, goal_target, reward_item_string) VALUES (:t, :d, :gt, :gta, :ri)");
                $ins->bindValue(':t', $story['title'], SQLITE3_TEXT);
                $ins->bindValue(':d', $story['description'], SQLITE3_TEXT);
                $ins->bindValue(':gt', $obj['selected_goal'], SQLITE3_TEXT);
                $ins->bindValue(':gta', $obj['selected_target_id'], SQLITE3_TEXT);
                $ins->bindValue(':ri', $reward_item_string, SQLITE3_TEXT);

                if ($ins->execute()) {
                    $new_mission_id = $db->lastInsertRowID();

                    $assign = $db->prepare("INSERT INTO player_missions (account_id, character_name, mission_id) VALUES (:acc, :cname, :mid)");
                    $assign->bindValue(':acc', $accId, SQLITE3_INTEGER);
                    $assign->bindValue(':cname', $charName, SQLITE3_TEXT);
                    $assign->bindValue(':mid', $new_mission_id, SQLITE3_INTEGER);
                    $assign->execute();
                    echo "[CRON] Successfully assigned new quest ID {$new_mission_id}!\n";

                    $clean_reward = renderRewardString($reward_item_string);
                    $clean_obj = getClearObjective($obj['selected_goal'], $obj['selected_target_id'], $story['title'], $story['description']);

                    if ($user_lang === 'jp') {
                        $mail_msg = "新ミッション: 「" . $story['title'] . "」を受注しました！\n";
                        $mail_msg .= "目標: " . $clean_obj . "\n";
                        $mail_msg .= "報酬: " . $clean_reward . "\n\n";
                        $mail_msg .= "詳細: " . mb_strimwidth($story['description'], 0, 200, "...");
                        send_personal_mail($accId, "ハンターズギルド", $mail_msg);
                    } elseif ($user_lang === 'ru') {
                        $mail_msg = "Новый заказ: " . $story['title'] . "\n";
                        $mail_msg .= "Цель: " . $clean_obj . "\n";
                        $mail_msg .= "Награда: " . $clean_reward . "\n\n";
                        $mail_msg .= "Описание: " . mb_strimwidth($story['description'], 0, 200, "...");
                        send_personal_mail($accId, "Гильдия Охотников", $mail_msg);
                    } else {
                        $mail_msg = "New Bounty: " . $story['title'] . "\n";
                        $mail_msg .= "Objective: " . $clean_obj . "\n";
                        $mail_msg .= "Reward: " . $clean_reward . "\n\n";
                        $mail_msg .= "Description: " . mb_strimwidth($story['description'], 0, 200, "...");
                        send_personal_mail($accId, "Hunters Guild", $mail_msg);
                    }
                }
            }
        }
    }

    $player_states[(string)$stateKey] = [
        'exp' => $current_exp,
        'floor' => $curr_f,
        'items' => $current_item_count,
        'level' => $curr_level,
        'char_name' => $charName,
        'last_boss_arena' => $last_boss_arena,
        'last_boss_arena_time' => $last_boss_arena_time,
        'pre_boss_floor' => $pre_boss_floor,
        'patrol' => $current_patrols,
        'patrol_seen' => $current_patrol_seen,
        'floor_entered_time' => $floor_entered_time,
        'game_lobby' => $client['LobbyID'] ?? null,
        'game_join_level' => $game_join_level[$stateKey] ?? (int) $curr_level,
    ] + $bounty_state;
}

// Batch-write all daily login records in a single transaction.
// This is the highest-frequency write path in the cron; batching it dramatically
// reduces lock contention against concurrent user-facing redemption requests.
if (!empty($pending_logins)) {
    $today = date('Y-m-d');
    $db->exec("BEGIN IMMEDIATE");
    try {
        $streak_stmt = $db->prepare("INSERT OR IGNORE INTO daily_logins (account_id, login_date) VALUES (:aid, :date)");
        foreach ($pending_logins as $loginAccId) {
            $streak_stmt->bindValue(':aid', (int)$loginAccId, SQLITE3_INTEGER);
            $streak_stmt->bindValue(':date', $today, SQLITE3_TEXT);
            $streak_stmt->execute();
            $streak_stmt->reset();
        }
        $db->exec("COMMIT");
    } catch (Exception $e) {
        $db->exec("ROLLBACK");
        echo "[CRON] Daily login batch failed: " . $e->getMessage() . "\n";
    }
}

    // 4. Save state
    file_put_contents($state_cache_file, json_encode($player_states));
    
    sleep(10);
}

echo "[CRON] Run complete.\n";
?>