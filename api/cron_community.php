<?php
/**
 * Dedicated cron daemon for processing Global Server Community Events tracking and AI Milestones.
 * Runs independently of cron_missions to ensure AI API latency does not bottleneck player telemetry.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

$community_lock_fp = fopen(__DIR__ . '/../db/.cron_community.lock', 'c');
if ($community_lock_fp && !flock($community_lock_fp, LOCK_EX | LOCK_NB)) {
    echo "[CRON_COMMUNITY] Another instance is running. Exiting.\n";
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/bounty_lore.php';

$db = get_db();

// Verify NewServ configuration exists
if (empty($NEWSERV_API_URL) || empty($GEMINI_API_KEY)) {
    die("[CRON] NEWSERV_API_URL or GEMINI_API_KEY is not defined in .env\n");
}

$state_cache_file = __DIR__ . '/.cron_community_state.json';
$player_states = [];
if (file_exists($state_cache_file)) {
    $player_states = json_decode(file_get_contents($state_cache_file), true) ?: [];
}

echo "[CRON_COMMUNITY] Starting execution...\n";

// Fetch live game state
$clients = newserv_fetch_clients(10.0);
if ($clients === null) {
    die("[CRON_COMMUNITY] Failed to fetch clients from NewServ API.\n");
}

$lobbies = newserv_fetch_lobbies(10.0) ?: [];
$lobby_episode_map = [];
$lobby_difficulty_map = [];
$lobby_is_game_map = [];
foreach ($lobbies as $l) {
    if (isset($l['ID'])) {
        $lobby_is_game_map[$l['ID']] = !empty($l['IsGame']);
        if (isset($l['Episode'])) {
            $lobby_episode_map[$l['ID']] = $l['Episode'];
        }
        if (isset($l['Difficulty'])) {
            $lobby_difficulty_map[$l['ID']] = $l['Difficulty'];
        }
    }
}

if (empty($clients)) {
    echo "[CRON_COMMUNITY] No clients online. Exiting.\n";
    exit;
}

// Fetch active Community Events
$active_community_events = [];
$ce_res = $db->query("SELECT * FROM community_events WHERE status = 'active'");
if ($ce_res) {
    while ($row = $ce_res->fetchArray(SQLITE3_ASSOC)) {
        $active_community_events[] = $row;
    }
}

if (empty($active_community_events)) {
    echo "[CRON_COMMUNITY] No active community events. Proceeding with debug telemetry only.\n";
}

/**
 * Helper function to trigger lore-grounded Gemini AI for milestones and broadcast to NewServ.
 */
function trigger_ai_milestone($ce, $milestone_type, $pct_str) {
    global $GEMINI_API_KEY, $NEWSERV_API_URL, $db, $GEMINI_MODEL;

    if ($milestone_type === 'start') {
        $db_flag = 'announced_start';
    } elseif ($milestone_type === '100') {
        $db_flag = 'completed_at'; // Special case, standard completion handles it
    } else {
        $db_flag = "announced_{$milestone_type}";
    }

    $srvAddr = get_server_address();
    $bc = bounty_build_community_broadcast_prompt($ce, $milestone_type, $pct_str, $srvAddr);
    $speaker = $bc['speaker'];
    $prompt = $bc['prompt'];

    echo "[CRON_COMMUNITY] Triggering Gemini AI for Milestone: {$pct_str} (Speaker: {$speaker})\n";

    $payload = [
        "contents" => [["parts" => [["text" => $prompt]]]],
        "generationConfig" => ["temperature" => 0.85, "maxOutputTokens" => 220]
    ];
    
    $g_options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($payload),
            'timeout' => 15
        ]
    ];
    $gemini_url = "https://generativelanguage.googleapis.com/v1beta/models/" . $GEMINI_MODEL . ":generateContent?key=" . $GEMINI_API_KEY;
    $api_response = @file_get_contents($gemini_url, false, stream_context_create($g_options));

    $message = "";
    if ($api_response) {
        $data = json_decode($api_response, true);
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $msg = trim($data['candidates'][0]['content']['parts'][0]['text']);
            $msg = str_replace(['**', '*'], '', $msg);
            $msg = trim($msg, " \t\n\r\0\x0B\"'");
            $msg = preg_replace('/\s*\R+\s*/u', ' / ', $msg);
            $msg = preg_replace('/^\[(?:' . preg_quote($speaker, '/') . '|Mission Control)\]\s*/iu', '', $msg);
            $msg = mb_strimwidth(trim($msg), 0, 400, '...', 'UTF-8');
            if ($msg !== '') {
                $message = "[{$speaker}] " . $msg;
            }
        }
    }
    
    // Fallback if AI fails to respond
    if (empty($message)) {
        $title = $ce['title'] ?? 'Global Operation';
        if ($milestone_type === 'start') {
            $message = "[{$speaker}] {$title} is underway! All hunters, report for duty. / 「{$title}」作戦開始！全ハンター出撃せよ！";
        } elseif ($milestone_type === '100') {
            $message = "[{$speaker}] {$title} complete! Claim your reward at {$srvAddr}. / 「{$title}」達成！報酬は{$srvAddr}で受け取れます。";
        } else {
            $message = "[{$speaker}] {$title} reached {$pct_str}! Keep pushing forward! / 「{$title}」目標{$pct_str}達成！この調子で続けよう！";
        }
    }

    // Broadcast
    $exec_payload = json_encode_newserv_cmd("announce-mail " . $message);
    $exec_options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => $exec_payload
        ]
    ];
    @file_get_contents($NEWSERV_API_URL . "/y/shell-exec", false, stream_context_create($exec_options));

    // Flag DB
    if ($db_flag !== 'completed_at') {
        $db->query("UPDATE community_events SET {$db_flag} = 1 WHERE id = " . (int)$ce['id']);
    }
}


// --- 1. Milestone Tracking: START (0%) ---
foreach ($active_community_events as $ce) {
    if (empty($ce['announced_start'])) {
        trigger_ai_milestone($ce, 'start', '0%');
    }
}

// --- 2. Player Delta Accumulation ---
// Wrap all contribution writes in one transaction so the write lock is acquired
// once per tick instead of twice per contributing player.
$db->exec("BEGIN IMMEDIATE");
try {
foreach ($clients as $client) {
    if (!isset($client['Account']['AccountID'])) continue;
    $accId = (string)$client['Account']['AccountID'];
    $curr_char_name = trim((string)($client['Name'] ?? '')) ?: 'Unknown';
    $state_key = $accId . '_' . $curr_char_name;

    $current_exp = $client['EXP'] ?? 0;
    $current_item_count = count($client['InventoryItems'] ?? []);
    $prev_state = $player_states[$state_key] ?? ((($player_states[$accId]['char_name'] ?? null) === $curr_char_name) ? $player_states[$accId] : []);

    $prev_exp = $prev_state['exp'] ?? $current_exp;
    $prev_items = $prev_state['items'] ?? $current_item_count;
    $curr_level = $client['Level'] ?? 1;
    $prev_level = $prev_state['level'] ?? $curr_level;

    $curr_meseta = $client['Meseta'] ?? 0;
    $prev_meseta = $prev_state['meseta'] ?? $curr_meseta;

    $curr_playtime = $client['PlayTimeSeconds'] ?? 0;
    $prev_playtime = $prev_state['playtime'] ?? $curr_playtime;

    $same_char = !isset($prev_state['char_name']) || $prev_state['char_name'] === $curr_char_name;
    $curr_chal = challenge_stages_cleared($client);
    $prev_chal = $same_char ? ($prev_state['chal_cleared'] ?? $curr_chal) : $curr_chal;

    $curr_mats = ($client['NumHPMaterialsUsed'] ?? 0) + ($client['NumTPMaterialsUsed'] ?? 0) + ($client['NumPowerMaterialsUsed'] ?? 0) + ($client['NumDefMaterialsUsed'] ?? 0) + ($client['NumMindMaterialsUsed'] ?? 0) + ($client['NumEvadeMaterialsUsed'] ?? 0) + ($client['NumLuckMaterialsUsed'] ?? 0);
    $prev_mats = $prev_state['mats'] ?? $curr_mats;

    $current_patrols = [];
    $curr_f = (int)($client['LocationFloor'] ?? -1);

    $lobby_id = $client['LobbyID'] ?? null;
    $lobby_episode = ($lobby_id !== null && isset($lobby_episode_map[$lobby_id])) ? $lobby_episode_map[$lobby_id] : null;
    $lobby_difficulty = ($lobby_id !== null && isset($lobby_difficulty_map[$lobby_id])) ? $lobby_difficulty_map[$lobby_id] : 'Normal';
    $is_game = ($lobby_id !== null) ? !empty($lobby_is_game_map[$lobby_id]) : false;
    $ep_num = ($is_game && $lobby_episode !== null && preg_match('/(\d)/', (string)$lobby_episode, $em)) ? (int)$em[1] : null;

    // Protect against Zero-EXP spikes during loading screens where NewServ briefly reports 0 EXP.
    // If we don't skip this tick, $prev_exp gets saved as 0, and the NEXT tick will show a massive delta!
    if ($current_exp < $prev_exp && $current_exp === 0) {
        continue;
    }

    // Delayed EXP Sync Memory (episode- and game-aware so lobbies/non-boss floors never record false arenas)
    $last_boss_arena = $prev_state['last_boss_arena'] ?? null;
    $last_boss_arena_time = $prev_state['last_boss_arena_time'] ?? 0;
    $boss_floors_list = ($is_game && $ep_num !== null) ? bounty_boss_arena_floors($ep_num) : [];
    if ($is_game && !empty($boss_floors_list) && in_array($curr_f, $boss_floors_list, true)) {
        $last_boss_arena = $curr_f;
        $last_boss_arena_time = time();
    }

    // Track the last non-boss dungeon floor the player was on inside an active game.
    $pre_boss_floor = $prev_state['pre_boss_floor'] ?? -1;
    if ($is_game && !in_array($curr_f, $boss_floors_list, true) && $curr_f > 0 && $curr_f !== 15) {
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
    //   9 | Ruins 2         | Seaside         | *Saint-Milion/Shambertin/Kondrieu*
    //  10 | Ruins 3         | Seabed Upper    | Test Map
    //  11 | *Dragon*        | Seabed Lower    | —
    //  12 | *De Rol Le*     | *Gal Gryphon*   | —
    //  13 | *Vol Opt*       | *Olga Flow*     | —
    //  14 | *Dark Falz*     | *Barba Ray*     | —
    //  15 | —               | *Gol Dragon*    | —
    // =====================================================================

    // Episode-verified boss debug telemetry is recorded by the 5s cron_boss_tracker.php daemon.

    // Evaluate progression for each active CE
    foreach ($active_community_events as &$ce) {
        $ce_contribution = 0;
        
        if ($ce['goal_type'] === 'MESETA') {
            $delta = $curr_meseta - $prev_meseta;
            if ($delta > 0) $ce_contribution = $delta;
        } elseif ($ce['goal_type'] === 'LEVEL_UP') {
            $delta = $curr_level - $prev_level;
            if ($delta > 0) $ce_contribution = $delta;
        } elseif ($ce['goal_type'] === 'MAT_CONSUME') {
            $delta = $curr_mats - $prev_mats;
            if ($delta > 0) $ce_contribution = $delta;
        } elseif ($ce['goal_type'] === 'PLAYTIME') {
            $delta = $curr_playtime - $prev_playtime;
            if ($delta > 0) $ce_contribution = $delta;
        } elseif ($ce['goal_type'] === 'CHALLENGE_STAGES') {
            $delta = $curr_chal - $prev_chal;
            if ($delta > 0) $ce_contribution = $delta;
        } elseif ($ce['goal_type'] === 'ITEM') {
            if ($current_item_count > $prev_items) {
                $inventory = $client['InventoryItems'] ?? [];
                $target_val = trim((string)$ce['goal_target']);
                
                // Map of generic weapon types to their Hex IDs for backwards compatibility
                $generic_weapon_hex_map = [
                    'Saber' => '007000', 'Dagger' => '000300', 'Handgun' => '000600', 'Rifle' => '007600', 'Cane' => '007900', 'Rod' => '007A00', 'Wand' => '007B00',
                    'Brand' => '000101', 'Knife' => '000301', 'Autogun' => '000601', 'Sniper' => '000701', 'Stick' => '000A01', 'Baton' => '000C02',
                    'Buster' => '000102', 'Blade' => '007200', 'Lockgun' => '000602', 'Blaster' => '000702', 'Mace' => '000A02', 'Scepter' => '000C03',
                    'Pallasch' => '000103', 'Claymore' => '000203', 'Edge' => '000303', 'Berdys' => '000403', 'Sawcer' => '000503', 'Railgun' => '000603', 'Beam' => '000703', 'Launcher' => '00A600', 'Gatling' => '000803', 'Club' => '000A03', 'Striker' => '000B03',
                    'Gladius' => '000104', 'Calibur' => '000204', 'Ripper' => '000304', 'Gungnir' => '000404', 'Diska' => '000504', 'Raygun' => '000604', 'Laser' => '000704', 'Arms' => '000904', 'Vulcan' => '000804',
                ];

                // Backwards compatibility for existing string-based community events
                if (!ctype_xdigit($target_val)) {
                    // Try to match the string to a hex code. If not found, fall back to null.
                    $target_val = $generic_weapon_hex_map[ucfirst(strtolower($target_val))] ?? null;
                } else {
                    // Remove any modifiers from the hex if present
                    $target_val = explode(' ', $target_val)[0];
                }

                if ($target_val) {
                    foreach ($inventory as $inv_item) {
                        // Check if the item's hex Data starts with our target hex prefix
                        if (isset($inv_item['Data']) && strpos($inv_item['Data'], $target_val) === 0) {
                            $ce_contribution = 1;
                            break;
                        }
                    }
                }
            }
        } elseif ($ce['goal_type'] === 'BOSS_ARENA') {
            $target_floor = $ce['goal_target'];
            $original_target = $target_floor;
            $recent_boss_fight = false;
            
            $prev_f = (int)($prev_state['floor'] ?? -1);
            $curr_f = (int)($client['LocationFloor'] ?? -1);
            $was_fast_kill = false;

            if ($target_floor === 'DIGITAL_BLASPHEMY' || $target_floor === 'EP1_BOSS_RUSH' || $target_floor === 'EP2_BOSS_RUSH' || $target_floor === 'ALL_BOSSES' || $target_floor === 'DRACONIC_DOMINION' || $target_floor === 'CATACLYSMIC_CORE') {
                $boss_type = null; // 'dragon', 'de_rol_le', 'vol_opt', 'dark_falz', 'barba_ray', 'gol_dragon', 'gal_gryphon', 'olga_flow', 'shambertin'
                $boss_episode = null;
                $boss_floor_resolved = null;
                
                // 1. Identify which boss arena was entered or exited
                if ($lobby_episode === 'Episode 1') {
                    if ($curr_f === 11 || $prev_f === 11 || $last_boss_arena === 11) { $boss_type = 'dragon'; $boss_episode = 'Episode 1'; $boss_floor_resolved = 11; }
                    elseif ($curr_f === 12 || $prev_f === 12 || $last_boss_arena === 12) { $boss_type = 'de_rol_le'; $boss_episode = 'Episode 1'; $boss_floor_resolved = 12; }
                    elseif ($curr_f === 13 || $prev_f === 13 || $last_boss_arena === 13) { $boss_type = 'vol_opt'; $boss_episode = 'Episode 1'; $boss_floor_resolved = 13; }
                    elseif ($curr_f === 14 || $prev_f === 14 || $last_boss_arena === 14) { $boss_type = 'dark_falz'; $boss_episode = 'Episode 1'; $boss_floor_resolved = 14; }
                } elseif ($lobby_episode === 'Episode 2') {
                    if ($curr_f === 14 || $prev_f === 14 || $last_boss_arena === 14) { $boss_type = 'barba_ray'; $boss_episode = 'Episode 2'; $boss_floor_resolved = 14; }
                    elseif ($curr_f === 15 || $prev_f === 15 || $last_boss_arena === 15) { $boss_type = 'gol_dragon'; $boss_episode = 'Episode 2'; $boss_floor_resolved = 15; }
                    elseif ($curr_f === 12 || $prev_f === 12 || $last_boss_arena === 12) { $boss_type = 'gal_gryphon'; $boss_episode = 'Episode 2'; $boss_floor_resolved = 12; }
                    elseif ($curr_f === 13 || $prev_f === 13 || $last_boss_arena === 13) { $boss_type = 'olga_flow'; $boss_episode = 'Episode 2'; $boss_floor_resolved = 13; }
                } elseif ($lobby_episode === 'Episode 4') {
                    if ($curr_f === 9 || $prev_f === 9 || $last_boss_arena === 9) { $boss_type = 'shambertin'; $boss_episode = 'Episode 4'; $boss_floor_resolved = 9; }
                }
                
                // Fast-Kill checking
                if ($curr_f >= 0 && $curr_f !== $prev_f) {
                    if ($lobby_episode === 'Episode 1') {
                        if ($prev_f === 2) { $was_fast_kill = true; $boss_type = 'dragon'; $boss_episode = 'Episode 1'; $boss_floor_resolved = 11; }
                        elseif ($prev_f === 5) { $was_fast_kill = true; $boss_type = 'de_rol_le'; $boss_episode = 'Episode 1'; $boss_floor_resolved = 12; }
                        elseif ($prev_f === 7) { $was_fast_kill = true; $boss_type = 'vol_opt'; $boss_episode = 'Episode 1'; $boss_floor_resolved = 13; }
                        elseif ($prev_f === 10) { $was_fast_kill = true; $boss_type = 'dark_falz'; $boss_episode = 'Episode 1'; $boss_floor_resolved = 14; }
                    } elseif ($lobby_episode === 'Episode 2') {
                        if ($prev_f === 2) { $was_fast_kill = true; $boss_type = 'barba_ray'; $boss_episode = 'Episode 2'; $boss_floor_resolved = 14; }
                        elseif ($prev_f === 4) { $was_fast_kill = true; $boss_type = 'gol_dragon'; $boss_episode = 'Episode 2'; $boss_floor_resolved = 15; }
                        elseif ($prev_f === 9) { $was_fast_kill = true; $boss_type = 'gal_gryphon'; $boss_episode = 'Episode 2'; $boss_floor_resolved = 12; }
                        elseif ($prev_f === 11) { $was_fast_kill = true; $boss_type = 'olga_flow'; $boss_episode = 'Episode 2'; $boss_floor_resolved = 13; }
                    } elseif ($lobby_episode === 'Episode 4') {
                        if ($prev_f === 8) { $was_fast_kill = true; $boss_type = 'shambertin'; $boss_episode = 'Episode 4'; $boss_floor_resolved = 9; }
                    }
                }
                
                if (time() - $last_boss_arena_time < 120) {
                    if ($last_boss_arena === 11 && $lobby_episode === 'Episode 1') { $boss_type = 'dragon'; $boss_episode = 'Episode 1'; }
                    elseif ($last_boss_arena === 12 && $lobby_episode === 'Episode 1') { $boss_type = 'de_rol_le'; $boss_episode = 'Episode 1'; }
                    elseif ($last_boss_arena === 13 && $lobby_episode === 'Episode 1') { $boss_type = 'vol_opt'; $boss_episode = 'Episode 1'; }
                    elseif ($last_boss_arena === 14 && $lobby_episode === 'Episode 1') { $boss_type = 'dark_falz'; $boss_episode = 'Episode 1'; }
                    elseif ($last_boss_arena === 14 && $lobby_episode === 'Episode 2') { $boss_type = 'barba_ray'; $boss_episode = 'Episode 2'; }
                    elseif ($last_boss_arena === 15 && $lobby_episode === 'Episode 2') { $boss_type = 'gol_dragon'; $boss_episode = 'Episode 2'; }
                    elseif ($last_boss_arena === 12 && $lobby_episode === 'Episode 2') { $boss_type = 'gal_gryphon'; $boss_episode = 'Episode 2'; }
                    elseif ($last_boss_arena === 13 && $lobby_episode === 'Episode 2') { $boss_type = 'olga_flow'; $boss_episode = 'Episode 2'; }
                    elseif ($last_boss_arena === 9 && $lobby_episode === 'Episode 4') { $boss_type = 'shambertin'; $boss_episode = 'Episode 4'; }
                }
                
                $recent_boss_fight = ($boss_type !== null);
                
                if ($recent_boss_fight) {
                    $exp_gain = ($current_exp - $prev_exp) >= 300;
                    $loot_gain = (($client['Level'] ?? 1) >= 200) && ($current_item_count > $prev_items);
                    
                    if ($exp_gain || $loot_gain) {
                        // Calculate score based on difficulty and boss type
                        $diff_bonus = 0;
                        if ($lobby_difficulty === 'Hard') $diff_bonus = 2;
                        elseif ($lobby_difficulty === 'Very Hard') $diff_bonus = 5;
                        elseif ($lobby_difficulty === 'Ultimate') $diff_bonus = 10;
                        
                        $base_score = 1;
                        $valid_for_event = false;
                        
                        if ($target_floor === 'DIGITAL_BLASPHEMY') {
                            if ($boss_type === 'vol_opt') { $base_score = 5; $valid_for_event = true; }
                            elseif ($boss_type === 'gol_dragon') { $base_score = 10; $valid_for_event = true; }
                            elseif ($boss_type === 'shambertin') { $base_score = 15; $valid_for_event = true; }
                        } elseif ($target_floor === 'EP1_BOSS_RUSH') {
                            if ($boss_episode === 'Episode 1') {
                                $valid_for_event = true;
                                if ($boss_type === 'dragon') $base_score = 1;
                                elseif ($boss_type === 'de_rol_le') $base_score = 2;
                                elseif ($boss_type === 'vol_opt') $base_score = 3;
                                elseif ($boss_type === 'dark_falz') $base_score = 4;
                            }
                        } elseif ($target_floor === 'EP2_BOSS_RUSH') {
                            if ($boss_episode === 'Episode 2') {
                                $valid_for_event = true;
                                if ($boss_type === 'barba_ray') $base_score = 1;
                                elseif ($boss_type === 'gol_dragon') $base_score = 2;
                                elseif ($boss_type === 'gal_gryphon') $base_score = 3;
                                elseif ($boss_type === 'olga_flow') $base_score = 4;
                            }
                        } elseif ($target_floor === 'ALL_BOSSES') {
                            $valid_for_event = true;
                            if (in_array($boss_type, ['dragon', 'barba_ray'])) $base_score = 1;
                            elseif (in_array($boss_type, ['de_rol_le', 'gol_dragon'])) $base_score = 2;
                            elseif (in_array($boss_type, ['vol_opt', 'gal_gryphon'])) $base_score = 3;
                            elseif (in_array($boss_type, ['dark_falz', 'olga_flow', 'shambertin'])) $base_score = 4;
                        } elseif ($target_floor === 'DRACONIC_DOMINION') {
                            if ($boss_type === 'dragon') { $base_score = 1; $valid_for_event = true; }
                            elseif ($boss_type === 'gol_dragon') { $base_score = 2; $valid_for_event = true; }
                            elseif ($boss_type === 'shambertin') { $base_score = 3; $valid_for_event = true; }
                        } elseif ($target_floor === 'CATACLYSMIC_CORE') {
                            if ($boss_type === 'dark_falz') { $base_score = 3; $valid_for_event = true; }
                            elseif ($boss_type === 'olga_flow') { $base_score = 4; $valid_for_event = true; }
                            elseif ($boss_type === 'shambertin') { $base_score = 4; $valid_for_event = true; }
                        }
                        
                        if ($valid_for_event) {
                            $ce_contribution = $base_score + $diff_bonus;
                            echo "[CRON_COMMUNITY] Special Boss Rush kill: Event={$target_floor}, Boss={$boss_type}, Diff={$lobby_difficulty}, Contribution={$ce_contribution}\n";
                        }
                    }
                }
            } elseif ($target_floor === 'ANY_DRAGON') {
                $dragon_floors = [11, 15]; // 11 = Ep1 Dragon (Sil Dragon on Ultimate), 15 = Ep2 Gol Dragon
                $recent_boss_fight = in_array($curr_f, $dragon_floors) || in_array($prev_f, $dragon_floors);
                
                // Fast-Kill Race Condition Fix:
                if (in_array($prev_f, [2, 4]) && $curr_f !== $prev_f && !in_array($curr_f, $dragon_floors)) {
                    $was_fast_kill = true;
                }
                
                if (in_array($last_boss_arena, $dragon_floors) && (time() - $last_boss_arena_time < 120)) {
                    $was_fast_kill = true;
                }
                $recent_boss_fight = $recent_boss_fight || $was_fast_kill;
                
                $exp_gain = ($current_exp - $prev_exp) >= 300;
                $loot_gain = (($client['Level'] ?? 1) >= 200) && ($current_item_count > $prev_items);
                if ($recent_boss_fight && ($exp_gain || $loot_gain)) {
                    $ce_contribution = 1;
                }
            } else {
                $parsed_bt = parse_boss_target($original_target);
                $target_floor = $parsed_bt['floor'];
                $episode = $parsed_bt['episode'];

                // Legacy fallback: map unambiguous synthetic IDs (16-19) to real newserv floors.
                // These IDs don't exist in newserv so they're safe to blindly convert.
                // Floor 15 is NOT mapped here because it could be a real Gol Dragon (Ep2 0x0F).
                // After running repair_floor_ids.php, none of these should ever appear.
                if ($episode === null && $target_floor === 16) {
                    $mapped_floor = 15; // Gol Dragon (old synthetic 16 → real 15)
                    $episode = 2;
                } elseif ($episode === null && $target_floor === 17) {
                    $mapped_floor = 14; // Barba Ray (old synthetic 17 → real 14)
                    $episode = 2;
                } elseif ($episode === null && $target_floor === 18) {
                    $mapped_floor = 13; // Olga Flow (old synthetic 18 → real 13)
                    $episode = 2;
                } elseif ($episode === null && $target_floor === 19) {
                    $mapped_floor = 9;  // Saint-Milion (old synthetic 19 → real 9)
                    $episode = 4;
                } else {
                    $mapped_floor = $target_floor;
                }

                // For real IDs (and floor 15 = Gol Dragon), determine episode from explicit @ep or context
                if ($episode === null) {
                    $episode = get_boss_episode_by_context($ce['title'], $ce['description'] ?? '', $mapped_floor);
                }

                $target_floor = $mapped_floor;
                $comp_key = "{$target_floor}_{$episode}";

                $fast_kill_preceding = [
                    '11_1' => [2],             // Dragon / Sil Dragon from Forest 2
                    '12_1' => [5],             // De Rol Le from Cave 3
                    '12_2' => [5, 6, 7, 8, 9], // Gal Gryphon from CCA/Jungle/Mtn/Seaside
                    '13_1' => [7],             // Vol Opt from Mine 2
                    '13_2' => [11],            // Olga Flow from Seabed Lower
                    '14_1' => [10],            // Dark Falz from Ruins 3
                    '14_2' => [2],             // Barba Ray from Temple Beta
                    '15_2' => [4],             // Gol Dragon from Spaceship Beta
                    '9_4'  => [8],             // Saint-Milion from Crater Interior
                ];

                // Catch players who enter the boss arena, kill the boss, and warp to town all within the 60-second cron window.
                if (isset($fast_kill_preceding[$comp_key]) && in_array($prev_f, $fast_kill_preceding[$comp_key])) {
                    if ($curr_f !== $prev_f && $curr_f !== $target_floor && $curr_f >= 0) {
                        $was_fast_kill = true;
                    }
                }
                
                if ($last_boss_arena === $target_floor && (time() - $last_boss_arena_time < 120)) {
                    $was_fast_kill = true;
                }
                $recent_boss_fight = ($curr_f === $target_floor) || ($prev_f === $target_floor) || $was_fast_kill;

                // Preceding floor validation for community events to prevent cross-episode collision false positives!
                $valid_preceding_floors = [
                    '11_1' => [1, 2],           // Dragon / Sil Dragon: Forest 1-2
                    '12_1' => [3, 4, 5],        // De Rol Le: Cave 1-3
                    '12_2' => [5, 6, 7, 8, 9],  // Gal Gryphon: CCA, Jungle, Mountain, Seaside
                    '13_1' => [6, 7],           // Vol Opt: Mine 1-2
                    '13_2' => [10, 11],         // Olga Flow: Seabed Upper/Lower
                    '14_1' => [8, 9, 10],       // Dark Falz: Ruins 1-3
                    '14_2' => [1, 2],           // Barba Ray: VR Temple Alpha/Beta
                    '15_2' => [3, 4],           // Gol Dragon: VR Ship Alpha/Beta
                    '9_4'  => [5, 6, 7, 8],     // Saint-Milion: Crater Interior / Desert
                ];
                $valid_floors_for_target = $valid_preceding_floors[$comp_key] ?? null;
                if ($recent_boss_fight && $valid_floors_for_target !== null && $pre_boss_floor >= 0) {
                    if (!in_array($pre_boss_floor, $valid_floors_for_target)) {
                        echo "[CRON_COMMUNITY] Boss target {$original_target} (key {$comp_key}) rejected: pre_boss_floor={$pre_boss_floor} not in valid set [" . implode(',', $valid_floors_for_target) . "] — wrong episode\n";
                        $recent_boss_fight = false;
                    }
                }

                // Strict Episode Validation using LobbyEpisode telemetry
                $expected_lobby_episode = "Episode " . $episode;
                if ($recent_boss_fight && $lobby_episode !== null && $lobby_episode !== $expected_lobby_episode) {
                    echo "[CRON_COMMUNITY] Boss target {$original_target} (key {$comp_key}) rejected: player is in {$lobby_episode}, but mission is for {$expected_lobby_episode}\n";
                    $recent_boss_fight = false;
                }
                
                $exp_gain = ($current_exp - $prev_exp) >= 300;
                $loot_gain = (($client['Level'] ?? 1) >= 200) && ($current_item_count > $prev_items);
                if ($recent_boss_fight && ($exp_gain || $loot_gain)) {
                    $ce_contribution = 1;
                }
            }
        } elseif ($ce['goal_type'] === 'PATROL') {
            $ft = parse_floor_target($ce['goal_target']);
            $epMatches = ($ft['episode'] === 1)
                ? ($lobby_id === null || ($is_game && ($ep_num === null || $ep_num === 1)))
                : ($is_game && $ep_num === $ft['episode']);
            if ($epMatches && ($client['LocationFloor'] ?? -1) === $ft['floor']) {
                $patrol_progress = ($prev_state['patrol']['CE_'.$ce['id']] ?? 0) + 1;
                $current_patrols['CE_'.$ce['id']] = $patrol_progress;
                if ($patrol_progress >= 10) {
                    $ce_contribution = 1;
                    $current_patrols['CE_'.$ce['id']] = 0;
                }
            } else {
                $current_patrols['CE_'.$ce['id']] = $prev_state['patrol']['CE_'.$ce['id']] ?? 0;
            }
        }

        if ($ce_contribution > 0) {
            echo "[CRON_COMMUNITY] Account $accId contributed $ce_contribution to CE " . $ce['id'] . "\n";
            $p_ins = $db->prepare("INSERT INTO community_event_participants (event_id, account_id, contribution_count) VALUES (:eid, :aid, :contrib) 
                                   ON CONFLICT(event_id, account_id) DO UPDATE SET contribution_count = contribution_count + :contrib");
            $p_ins->bindValue(':eid', $ce['id'], SQLITE3_INTEGER);
            $p_ins->bindValue(':aid', $accId, SQLITE3_INTEGER);
            $p_ins->bindValue(':contrib', $ce_contribution, SQLITE3_INTEGER);
            $p_ins->execute();

            $db->query("UPDATE community_events SET current_progress = current_progress + " . (int)$ce_contribution . " WHERE id = " . (int)$ce['id']);
            
            // Re-fetch to update local cache within the same script run
            $ce['current_progress'] += $ce_contribution;
        }
    }

    $player_states[$state_key] = [
        'exp' => $current_exp,
        'items' => $current_item_count,
        'floor' => $curr_f,
        'level' => $curr_level,
        'patrol' => $current_patrols,
        'meseta' => $curr_meseta,
        'playtime' => $curr_playtime,
        'chal' => $curr_chal,
        'chal_cleared' => $curr_chal,
        'char_name' => $curr_char_name,
        'mats' => $curr_mats,
        'last_boss_arena' => $last_boss_arena,
        'last_boss_arena_time' => $last_boss_arena_time,
        'pre_boss_floor' => $pre_boss_floor
    ];
}
$db->exec("COMMIT");
} catch (Exception $e) {
    $db->exec("ROLLBACK");
    echo "[CRON_COMMUNITY] DB transaction failed: " . $e->getMessage() . "\n";
}

file_put_contents($state_cache_file, json_encode($player_states));

// --- 3. Milestone Tracking: Progress (20, 50, 80, 100%) ---
foreach ($active_community_events as $ce) {
    if ($ce['status'] !== 'active') continue;

    $target = (int)$ce['target_amount'];
    if ($ce['goal_type'] === 'PLAYTIME') {
        $target = $target * 3600;
    }
    
    if ($target <= 0) continue;

    $progress = (int)$ce['current_progress'];
    $pct = ($progress / $target) * 100;

    // Ordered highest to lowest to prevent skipping
    if ($pct >= 100) {
        $db->query("UPDATE community_events SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = " . (int)$ce['id']);
        trigger_ai_milestone($ce, '100', '100%');
    } elseif ($pct >= 80 && empty($ce['announced_80'])) {
        trigger_ai_milestone($ce, '80', '80%');
    } elseif ($pct >= 50 && empty($ce['announced_50'])) {
        trigger_ai_milestone($ce, '50', '50%');
    } elseif ($pct >= 20 && empty($ce['announced_20'])) {
        trigger_ai_milestone($ce, '20', '20%');
    }
}

echo "[CRON_COMMUNITY] Run complete.\n";
?>
