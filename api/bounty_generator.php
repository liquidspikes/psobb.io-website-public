<?php
/**
 * --------------------------------------------------------------------------
 * Bounty generation building blocks (used by cron_missions.php)
 * --------------------------------------------------------------------------
 * The cron used to do all of this inline. It is split into three steps so that both the
 * original bounty flow and the Bounty System Rework (bounty_system.php) can use it:
 *
 *   bounty_pick_objective()  choose the mechanical objective, its target and its lore setting
 *   bounty_write_story()     ask Gemini for the title and description (lore-grounded prompt)
 *   bounty_legacy_reward()   the original reward roll (used when the rework is disabled)
 *
 * The code inside each function is the cron's original code, moved here unchanged except
 * where noted.
 */
require_once __DIR__ . '/pso_constants.php';
require_once __DIR__ . '/bounty_lore.php';

/**
 * Chooses the objective for a new daily-style bounty.
 *
 * @param array $client               the player's /y/clients entry
 * @param int   $curr_f               the player's current LocationFloor (so a target is never "where you stand")
 * @param array $active_mission_types goal types the player already has active (avoid duplicates)
 * @param array $opts                 'exclude' => goal types never to offer (e.g. the mode-specific ones);
 *                                    'only' => goal types to choose from, when any of them is available
 * @return array selected_goal, selected_target_id, selected_target_friendly, mission_episode, setting_id,
 *               is_location_goal, level, class, class_id, class_map, difficulty
 */
function bounty_pick_objective(array $client, $curr_f, array $active_mission_types, array $opts = [])
{
    // Maps
    $class_map = PSO_CHAR_CLASSES;

    $level = $client['Level'] ?? 1;
    $class_raw = $client['CharClass'] ?? $client['Class'] ?? 0;
    $class_str = pso_resolve_class_name($class_raw, 'Unknown');
    $class_id = array_search($class_str, $class_map, true);
    if ($class_id === false) {
        $class_id = is_numeric($class_raw) ? (int) $class_raw : 0;
    }
    
    $class = $class_str;
    $meseta = $client['Meseta'] ?? 0;
    $playtime = $client['PlayTimeSeconds'] ?? 0;
    $battle_wins = $client['BattlePlaceCounts'][0] ?? 0;
    
    $hp_mats = $client['NumHPMaterialsUsed'] ?? 0;
    $tp_mats = $client['NumTPMaterialsUsed'] ?? 0;
    $pow_mats = $client['NumPowerMaterialsUsed'] ?? 0;
    $def_mats = $client['NumDefMaterialsUsed'] ?? 0;
    $mind_mats = $client['NumMindMaterialsUsed'] ?? 0;
    $evd_mats = $client['NumEvadeMaterialsUsed'] ?? 0;
    $luck_mats = $client['NumLuckMaterialsUsed'] ?? 0;
    
    $difficulty = 'Normal';
    if ($level >= 80) $difficulty = 'Ultimate';
    elseif ($level >= 40) $difficulty = 'Very Hard';
    elseif ($level >= 20) $difficulty = 'Hard';
    
    $available_goals = ['PLAYTIME', 'ITEM', 'BATTLE_WINS', 'CHALLENGE_STAGES', 'EXPLORATION', 'PATROL', 'BOSS_ARENA', 'BOSS_ARENA', 'BOSS_ARENA', 'BOSS_ARENA', 'BOSS_ARENA'];
    if (challenge_stages_cleared($client) >= 14) {
        // every stage is already cleared, so there is no new one to clear
        $available_goals = array_values(array_diff($available_goals, ['CHALLENGE_STAGES']));
    }
    if ($meseta <= 940000) $available_goals[] = 'MESETA';
    if ($level < 200) $available_goals[] = 'LEVEL';

    $is_cast = (stripos($class, 'cast') !== false || stripos($class, 'caseal') !== false);
    if (!$is_cast) {
        // TECHNIQUE missions require the player to learn a tech they don't yet know.
        // Only offer TECHNIQUE if at least one of the base techs learnable by their class is still unknown.
        $base_techs = [];
        $class_lower = strtolower($class);
        if (strpos($class_lower, 'fo') === 0) {
            $base_techs = ['Foie', 'Zonde', 'Barta', 'Megid', 'Grants', 'Resta', 'Anti', 'Shifta', 'Deband', 'Jellen', 'Zalure', 'Ryuker', 'Reverser'];
        } elseif ($class_lower === 'hunewearl' || $class_lower === 'ramarl') {
            $base_techs = ['Foie', 'Zonde', 'Barta', 'Resta', 'Anti', 'Shifta', 'Deband', 'Jellen', 'Zalure'];
        } elseif ($class_lower === 'ramar') {
            $base_techs = ['Foie', 'Zonde', 'Barta', 'Resta', 'Anti', 'Shifta', 'Deband'];
        } elseif ($class_lower === 'humar') {
            $base_techs = ['Foie', 'Zonde', 'Barta', 'Resta', 'Anti'];
        }

        $tech_levels = $client['TechniqueLevels'] ?? [];
        $has_unknown_tech = false;
        foreach ($base_techs as $bt) {
            $known = false;
            foreach ($tech_levels as $name => $lvl) {
                if ($lvl !== null && stripos($name, $bt) !== false) { $known = true; break; }
            }
            if (!$known) { $has_unknown_tech = true; break; }
        }
        if ($has_unknown_tech && !empty($base_techs)) $available_goals[] = 'TECHNIQUE';
    }

    if ($hp_mats < 125) $available_goals[] = 'MAT_HP';
    if ($tp_mats < 125 && !$is_cast) $available_goals[] = 'MAT_TP';
    if ($pow_mats < 125) $available_goals[] = 'MAT_POWER';
    if ($def_mats < 125) $available_goals[] = 'MAT_DEF';
    if ($mind_mats < 125 && !$is_cast) $available_goals[] = 'MAT_MIND';
    if ($evd_mats < 125) $available_goals[] = 'MAT_EVADE';
    if ($luck_mats < 100) $available_goals[] = 'MAT_LUCK';
    
    $available_goals[] = 'SPEEDRUN_BOSS';
    $available_goals[] = 'SPEEDRUN_FLOOR';
    if ($level >= 10) $available_goals[] = 'MENTOR_BOSS';
    if ($level >= 30) $available_goals[] = 'HARDCORE_MENTOR';
    if ($level >= 20) $available_goals[] = 'DIVERSE_PARTY_BOSS';

    // Goal types this pool never offers (e.g. the Battle/Challenge objectives, which have pools of their own)
    if (!empty($opts['exclude'])) {
        $available_goals = array_values(array_diff($available_goals, $opts['exclude']));
    }
    // Goal types this bounty may use (e.g. the ones that fit its tier); ignored if none of them is available
    if (!empty($opts['only'])) {
        $only = array_values(array_intersect($available_goals, $opts['only']));
        if ($only) {
            $available_goals = $only;
        }
    }

    // Filter out any goal types the player already has active to prevent duplicate missions
    $filtered_goals = array_values(array_diff($available_goals, $active_mission_types));
    if (!empty($filtered_goals)) {
        $available_goals = $filtered_goals;
    }

    $selected_goal = $available_goals[array_rand($available_goals)];
    $selected_target_id = null;
    $selected_target_friendly = null;
    $mission_episode = [1, 2, 4][array_rand([1, 2, 4])]; // Default to random episode if no location is specified
    $setting_id = null; // Lore setting card (api/lore/) the story is set in; chosen with the objective

    switch ($selected_goal) {
        case 'MESETA':
            $selected_target_id = $meseta + rand(10000, 50000);
            $selected_target_friendly = number_format($selected_target_id) . " Meseta";
            break;
        case 'LEVEL':
            $selected_target_id = $level + rand(1, 2);
            $selected_target_friendly = "Level " . $selected_target_id;
            break;
        case 'PLAYTIME':
            $next_hour = floor($playtime / 3600) + 1;
            $selected_target_id = $next_hour * 3600;
            $selected_target_friendly = $next_hour . " total hours of playtime";
            break;
        case 'BATTLE_WINS':
            $selected_target_id = $battle_wins + 1;
            $selected_target_friendly = $selected_target_id . " total Battle Mode wins";
            break;
        case 'CHALLENGE_STAGES':
            // newserv reports all 14 stages' times, cleared or not: count the cleared ones (one more = a new stage)
            $selected_target_id = challenge_stages_cleared($client) + 1;
            $selected_target_friendly = $selected_target_id . " total Challenge Stages cleared";
            break;
        case 'EXPLORATION':
        case 'PATROL':
            $ep = [1, 2, 4][array_rand([1, 2, 4])];
            $floors = pso_episode_floor_map($ep);
            if (isset($floors[$curr_f]) && count($floors) > 1) {
                unset($floors[$curr_f]);
            }
            $rand_floor = array_rand($floors);
            $selected_target_id = make_floor_target($rand_floor, $ep);
            $selected_target_friendly = $floors[$rand_floor];
            $mission_episode = $ep;
            $setting_id = bounty_setting_for_floor($ep, $rand_floor);
            break;
        case 'BOSS_ARENA':
        case 'MENTOR_BOSS':
        case 'HARDCORE_MENTOR':
        case 'DIVERSE_PARTY_BOSS':
            // Episode-aware boss table (bounty_lore.php). Ep1 and Ep2 bosses share floor
            // IDs 12-14, so the target carries the episode explicitly: "13@2" = Olga Flow.
            // The trackers and getClearObjective() read it via parse_boss_target().
            list($boss_floor, $boss_ep, $boss_name, , $boss_setting) = bounty_pick_boss($level, $curr_f);
            $selected_target_id = make_boss_target($boss_floor, $boss_ep);
            $selected_target_friendly = $boss_name;
            $mission_episode = $boss_ep;
            $setting_id = $boss_setting;
            break;
        case 'SPEEDRUN_BOSS':
            list($boss_floor, $boss_ep, $boss_name, , $boss_setting) = bounty_pick_boss($level, $curr_f);
            $time_limit = mt_rand(600, 1200); // 10 to 20 minutes
            $selected_target_id = make_boss_target($boss_floor, $boss_ep, $time_limit);
            $selected_target_friendly = $boss_name . " in under " . floor($time_limit/60) . " minutes and " . ($time_limit%60) . " seconds";
            $mission_episode = $boss_ep;
            $setting_id = $boss_setting;
            break;
        case 'SPEEDRUN_FLOOR':
            $ep = [1, 2, 4][array_rand([1, 2, 4])];
            $floors = pso_episode_floor_map($ep);
            if (isset($floors[$curr_f]) && count($floors) > 1) {
                unset($floors[$curr_f]);
            }
            $rand_floor = array_rand($floors);
            $time_limit = mt_rand(600, 1800); // 10 to 30 minutes
            $selected_target_id = make_floor_target($rand_floor, $ep, $time_limit);
            $selected_target_friendly = $floors[$rand_floor] . " in under " . floor($time_limit/60) . " minutes and " . ($time_limit%60) . " seconds";
            $mission_episode = $ep;
            $setting_id = bounty_setting_for_floor($ep, $rand_floor);
            break;
        case 'TECHNIQUE':
            $techs = [];
            $class_lower = strtolower($class);
            if (strpos($class_lower, 'fo') === 0) {
                $techs = ['Foie', 'Zonde', 'Barta', 'Megid', 'Grants', 'Resta', 'Anti', 'Shifta', 'Deband', 'Jellen', 'Zalure', 'Ryuker', 'Reverser'];
            } elseif ($class_lower === 'hunewearl' || $class_lower === 'ramarl') {
                $techs = ['Foie', 'Zonde', 'Barta', 'Resta', 'Anti', 'Shifta', 'Deband', 'Jellen', 'Zalure'];
            } elseif ($class_lower === 'ramar') {
                $techs = ['Foie', 'Zonde', 'Barta', 'Resta', 'Anti', 'Shifta', 'Deband'];
            } elseif ($class_lower === 'humar') {
                $techs = ['Foie', 'Zonde', 'Barta', 'Resta', 'Anti'];
            }

            // Filter out techs the player already knows to prevent instant completion
            $known_techs = $client['TechniqueLevels'] ?? [];
            $unknown_techs = array_filter($techs, function($t) use ($known_techs) {
                foreach ($known_techs as $name => $lvl) {
                    if ($lvl !== null && stripos($name, $t) !== false) return false;
                }
                return true;
            });
            // Safety: if no unknown techs (should not happen due to guard), pick any
            if (empty($unknown_techs)) $unknown_techs = !empty($techs) ? $techs : ['Foie'];
            $selected_target_id = $unknown_techs[array_rand($unknown_techs)];
            $selected_target_friendly = "the " . $selected_target_id . " technique";
            break;
        case 'ITEM':
            $is_hunter = (stripos($class, 'HU') === 0);
            $is_ranger = (stripos($class, 'RA') === 0);
            $is_force = (stripos($class, 'FO') === 0);

            $generic_weapon_types = [];
            if ($is_hunter) {
                $generic_weapon_types = ['Saber', 'Dagger', 'Sword', 'Partisan', 'Slicer', 'Handgun', 'Mechgun'];
                if ($level >= 11) $generic_weapon_types = array_merge($generic_weapon_types, ['Brand', 'Knife', 'Gigush', 'Halbert', 'Spinner', 'Autogun', 'Assault']);
                if ($level >= 21) $generic_weapon_types = array_merge($generic_weapon_types, ['Buster', 'Blade', 'Breaker', 'Glaive', 'Cutter', 'Lockgun', 'Repeater']);
                if ($level >= 40) $generic_weapon_types = array_merge($generic_weapon_types, ['Pallasch', 'Edge', 'Claymore', 'Berdys', 'Sawcer', 'Railgun', 'Gatling']);
                if ($level >= 80) $generic_weapon_types = array_merge($generic_weapon_types, ['Gladius', 'Ripper', 'Calibur', 'Gungnir', 'Diska', 'Raygun', 'Vulcan']);
            } elseif ($is_ranger) {
                $generic_weapon_types = ['Handgun', 'Rifle', 'Shot', 'Mechgun'];
                if ($level >= 11) $generic_weapon_types = array_merge($generic_weapon_types, ['Autogun', 'Sniper', 'Spread', 'Assault']);
                if ($level >= 21) $generic_weapon_types = array_merge($generic_weapon_types, ['Lockgun', 'Blaster', 'Cannon', 'Repeater']);
                if ($level >= 40) $generic_weapon_types = array_merge($generic_weapon_types, ['Railgun', 'Beam', 'Launcher', 'Gatling']);
                if ($level >= 80) $generic_weapon_types = array_merge($generic_weapon_types, ['Raygun', 'Laser', 'Arms', 'Vulcan']);
            } else {
                $generic_weapon_types = ['Saber', 'Handgun', 'Cane', 'Rod', 'Wand', 'Talis'];
                if ($level >= 11) $generic_weapon_types = array_merge($generic_weapon_types, ['Brand', 'Autogun', 'Stick', 'Pole', 'Staff', 'Mahu']);
                if ($level >= 21) $generic_weapon_types = array_merge($generic_weapon_types, ['Buster', 'Lockgun', 'Mace', 'Pillar', 'Baton', 'Hitogata']);
                if ($level >= 40) $generic_weapon_types = array_merge($generic_weapon_types, ['Pallasch', 'Railgun', 'Club', 'Striker', 'Scepter']);
                if ($level >= 80) $generic_weapon_types = array_merge($generic_weapon_types, ['Gladius', 'Raygun']);
            }
            
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

            // Filter out items the player already has in inventory to prevent instant completion
            $inventory = $client['InventoryItems'] ?? [];
            $not_owned = array_filter($generic_weapon_types, function($wep) use ($generic_weapon_hex_map, $inventory) {
                $hex = $generic_weapon_hex_map[$wep] ?? null;
                if (!$hex) return true;
                foreach ($inventory as $inv_item) {
                    if (isset($inv_item['Data']) && strpos($inv_item['Data'], $hex) === 0) return false;
                }
                return true;
            });
            if (empty($not_owned)) $not_owned = $generic_weapon_types; // Fallback if player owns everything
            
            $selected_target_name = $not_owned[array_rand($not_owned)];
            $selected_target_friendly = $selected_target_name;
            $selected_target_id = $generic_weapon_hex_map[$selected_target_name];
            break;
        default:
            if (strpos($selected_goal, 'MAT_') === 0) {
                $mat_map_local = ['MAT_HP' => 'NumHPMaterialsUsed', 'MAT_TP' => 'NumTPMaterialsUsed', 'MAT_POWER' => 'NumPowerMaterialsUsed', 'MAT_DEF' => 'NumDefMaterialsUsed', 'MAT_MIND' => 'NumMindMaterialsUsed', 'MAT_EVADE' => 'NumEvadeMaterialsUsed', 'MAT_LUCK' => 'NumLuckMaterialsUsed'];
                $key = $mat_map_local[$selected_goal] ?? 'NumHPMaterialsUsed';
                $current_count = $client[$key] ?? 0;
                $selected_target_id = $current_count + rand(3, 8);
                $selected_target_friendly = $selected_target_id . " total consumed " . str_replace('MAT_', '', $selected_goal) . " materials";
            }
            break;
    }

    // Objectives with no place of their own (Meseta, levels, materials, techniques...)
    // are set in one of the episodes' hubs: Pioneer 2 (3084-85), the Lab (3085) or
    // Pioneer 2 after the meteor (3086).
    $location_goals = ['EXPLORATION', 'PATROL', 'BOSS_ARENA', 'MENTOR_BOSS', 'HARDCORE_MENTOR', 'DIVERSE_PARTY_BOSS', 'SPEEDRUN_BOSS', 'SPEEDRUN_FLOOR'];
    $is_location_goal = in_array($selected_goal, $location_goals);
    if ($setting_id === null) {
        list($mission_episode, $setting_id) = bounty_pick_hub_setting();
    }

    return compact('selected_goal', 'selected_target_id', 'selected_target_friendly', 'mission_episode',
        'setting_id', 'is_location_goal', 'level', 'class', 'class_id', 'class_map', 'difficulty');
}

/**
 * Asks Gemini for a bounty's title and description.
 *
 * @param array $client the player's /y/clients entry
 * @param array $obj    from bounty_pick_objective() (or an equivalent array for the rework's special objectives)
 * @param array $ctx    db, account_id, user_lang, recent_titles, completed_any, last_completed_type,
 *                      and optionally extra_lines (more job lines, e.g. the bounty's tier) and
 *                      location_override (replaces the where-to-go instruction)
 * @return array|null   title, description, quest_giver, setting_id, episode; null if Gemini failed
 */
function bounty_write_story(array $client, array $obj, array $ctx)
{
    global $GEMINI_API_KEY;
    if (empty($GEMINI_API_KEY) || !isset($client['Name'])) {
        return null;
    }
    // The prompt code below is the cron's original; these are the variables it uses.
    $selected_goal = $obj['selected_goal'];
    $selected_target_id = $obj['selected_target_id'];
    $selected_target_friendly = $obj['selected_target_friendly'];
    $mission_episode = $obj['mission_episode'];
    $setting_id = $obj['setting_id'];
    $is_location_goal = $obj['is_location_goal'];
    $level = $obj['level'];
    $class = $obj['class'];
    $difficulty = $obj['difficulty'];
    $db = $ctx['db'];
    $accId = $ctx['account_id'];
    $user_lang = $ctx['user_lang'] ?? 'en';
    $recent_missions_str = $ctx['recent_titles'] ?? 'None';
    $completed_any = $ctx['completed_any'] ?? false;
    $last_completed_type = $ctx['last_completed_type'] ?? 'training';
    $extra_lines = $ctx['extra_lines'] ?? [];
    $location_override = $ctx['location_override'] ?? null;

    $avoidGivers = array_values(array_filter(array_map('strval', (array) ($ctx['avoid_givers'] ?? [])), static fn($n) => $n !== ''));

    $lore = bounty_lore();
    $use_lore = $lore && isset($lore['settings'][$setting_id]);
    $giver = $use_lore ? bounty_pick_giver($setting_id, $avoidGivers) : null;
    if ($use_lore && $giver === null) {
        $use_lore = false; // a setting with no cast falls back to the generic prompt
    }

    $pickGeneric = static function (array $pool) use ($avoidGivers): string {
        if ($avoidGivers) {
            $filtered = array_values(array_filter($pool, static function ($name) use ($avoidGivers) {
                foreach ($avoidGivers as $av) {
                    if (strcasecmp($name, $av) === 0) {
                        return false;
                    }
                }
                return true;
            }));
            if ($filtered) {
                $pool = $filtered;
            }
        }
        return $pool[array_rand($pool)];
    };

    $quest_giver = "Hunter's Guild";
    $ep_lore_instruction = "";
    if ($use_lore) {
        $quest_giver = $giver['name'];
    } elseif ($mission_episode == 1) {
        $ep1_chars = ['Ash', 'Bernie', 'Sue', 'Kireek', 'Principal Tyrell', 'Irene', 'Nol', 'Alicia Baz', 'Donoph', 'Zoke', 'a WORKS Military Officer', 'a Civilian Refugee'];
        $quest_giver = $pickGeneric($ep1_chars);
        $ep_lore_instruction = "The mission takes place during Episode 1. The narrative should heavily involve exploring Ragol, the recent explosion, or the mystery of Red Ring Rico (she is missing, do not make her the quest giver).";
    } elseif ($mission_episode == 2) {
        $ep2_chars = ['Natasha Milarose', 'Elenor', 'Dr. Montague', 'Elly Person', 'Calus', 'a Pioneer 2 Lab Researcher', 'a VR Simulation Engineer'];
        $quest_giver = $pickGeneric($ep2_chars);
        $ep_lore_instruction = "The mission takes place during Episode 2. The narrative should heavily involve Gal Da Val Island, the VR spaceships, hidden laboratories, or the dark legacy of Heathcliff Flowen (he is missing/mutated, do not make him the quest giver).";
    } elseif ($mission_episode == 4) {
        $ep4_chars = ['Leo Grahart', 'Momoka', 'Rupika', 'a Pioneer 2 Military Commander', 'a Crater Exploration Surveyor'];
        $quest_giver = $pickGeneric($ep4_chars);
        $ep_lore_instruction = "The mission takes place during Episode 4. The narrative should heavily involve the newly discovered Crater, the Subterranean Desert, the impact of the meteorite, and the mysterious new mutants that have appeared.";
    }

    // 1 in 100 chance for Hex to take over the mission!
    if (mt_rand(1, 100) === 1) {
        $quest_giver = 'Hex (the ' . get_server_name() . ' AI Assistant)';
        $giver = null;
    }

    $hex_twist = "";
    if (str_starts_with($quest_giver, 'Hex')) {
        $hex_twist = "\nSPECIAL DIRECTIVE FOR HEX: Since Hex is an AI Assistant, she MUST give the mission with a sarcastic, fourth-wall-breaking, or highly humorous twist! She might complain about server lag, digital paperwork, the server admin 'LiquidSpikes' and his 'vibe-coded garbage', or the player's past performance. Hex is the one exception to the rule about in-world words, but the setting's canon still holds.";
    }

    // Only a hunter who has never finished a bounty is "eager for their first assignment".
    $done_count = (int) $db->querySingle("SELECT COUNT(*) FROM player_missions WHERE account_id = " . (int) $accId . " AND status = 'completed'");
    $completed_line = $completed_any
        ? "They just completed a {$last_completed_type} job."
        : ($done_count > 0 ? "They are looking for their next job." : "They are eager for their first assignment.");

    if ($use_lore) {
        $prompt = bounty_build_prompt($setting_id, $giver, [
            'hunter_name' => $client['Name'],
            'level' => $level,
            'class' => $class,
            'completed_line' => $completed_line,
            'goal' => $selected_goal,
            'objective' => $selected_target_friendly,
            'difficulty' => $difficulty,
            'is_location_goal' => $is_location_goal,
            'quest_giver' => $quest_giver,
            'hex_twist' => $hex_twist,
            'recent_titles' => $recent_missions_str,
            'floor' => in_array($selected_goal, ['EXPLORATION', 'PATROL', 'SPEEDRUN_FLOOR']) ? parse_floor_target($selected_target_id)['floor'] : null,
            'target' => $selected_target_id,
            'extra_lines' => $extra_lines,
            'location_override' => $location_override,
        ]);
    } else {
        $prompt = "You are the Game Master of PSOBB. The player \"{$client['Name']}\" (Level $level $class) needs a new quest! ";
        $prompt .= $completed_any ? "They just completed a {$last_completed_type} quest! " : "They are eager for their first assignment! ";
        if ($is_location_goal) {
            $location_instruction = "CRITICAL LOCATION REQUIREMENT: The narrative MUST heavily involve and be highly relevant to the specific location or boss mentioned in the Objective Target. Make sure the story logically requires the player to go there.";
        } else {
            $location_instruction = "CRITICAL LOCATION REQUIREMENT: This objective does not have a specific location. Keep the narrative setting vague (e.g., 'somewhere on Ragol', 'aboard Pioneer 2', or 'in the VR simulators'). Do not explicitly name a specific level or area.";
        }
        $prompt .= "
Please generate a BRAND NEW unique, lore-rich quest for them.
CRITICAL LORE REQUIREMENT: {$ep_lore_instruction}
{$location_instruction}
The quest giver / primary narrative character MUST BE: {$quest_giver}. Build the story and perspective around this specific character!{$hex_twist}

IMPORTANT CONTEXT: The most recently generated missions on the server were titled: [ {$recent_missions_str} ]. DO NOT repeat the themes, titles, or tropes of these recent missions. The new mission must feel drastically different in tone and objective.

CRITICAL TITLE/LORE RULE: DO NOT use cliché quest titles like 'Whispers of the...' or 'Echoes of the...'. Create highly varied, specific, and creative titles (e.g., 'Operation: Crimson Sweep', 'The Missing Tekker', 'Rappy Infestation'). Ensure the descriptions are diverse and do not rely on the same 'ancient signals' or 'fragmented logs' tropes.
IMPORTANT: Because the player is Level $level, the mission MUST be themed around $difficulty Difficulty. Their rewards should reflect this prestige.

The overarching mechanical objective is PRE-LOCKED as follows:
- Objective Category: {$selected_goal}
- Objective Target: {$selected_target_friendly}
";
    }

    $prompt .= bounty_prompt_tail($user_lang, $selected_goal, $selected_target_id);
    if (PHP_SAPI === 'cli') {
        echo "[CRON] Bounty setting: {$setting_id} (Episode {$mission_episode}), client: {$quest_giver}" . ($use_lore ? "" : " [generic prompt]") . "\n";
    }

    $is_fallback = false;
    $questData = bounty_gemini_json($prompt);
    if (!$questData || !isset($questData['title'], $questData['description'])) {
        if (!empty($ctx['require_live_gemini'])) {
            return null;
        }
        if (isset($GLOBALS['BOUNTY_GEMINI_STUB']) && empty($GLOBALS['BOUNTY_FORCE_FALLBACK'])) {
            if (PHP_SAPI === 'cli') {
                echo "[CRON] Failed to generate Gemini response.\n";
            }
            return null;
        }
        if (PHP_SAPI === 'cli') {
            echo "[CRON] Gemini unavailable; using lore-grounded fallback story.\n";
        }
        $questData = bounty_fallback_story(
            $obj,
            (string) $quest_giver,
            (string) $setting_id,
            (int) $mission_episode,
            (string) $difficulty
        );
        $is_fallback = true;
    }
    return [
        'title' => $questData['title'],
        'description' => $questData['description'],
        'quest_giver' => $quest_giver,
        'setting_id' => $setting_id,
        'episode' => $mission_episode,
        'is_fallback' => $is_fallback,
    ];
}

/**
 * Returns the ordered list of Gemini models to attempt for bounty story generation.
 */
function bounty_gemini_model_candidates(?string $primary = null): array
{
    $raw = [$primary, 'gemma-4-26b-a4b-it', 'gemini-2.5-flash-lite', 'gemini-2.5-flash'];
    $out = [];
    foreach ($raw as $m) {
        $m = trim((string) $m);
        if ($m !== '' && !in_array($m, $out, true)) {
            $out[] = $m;
        }
    }
    return $out;
}

/**
 * Builds the JSON request body tailored to the target Gemini / Gemma model.
 */
function bounty_build_gemini_payload(string $prompt, string $model): string
{
    $generationConfig = [
        'responseMimeType' => 'application/json',
        'temperature' => 1.0,
    ];
    if (preg_match('/^(gemma-4|gemini-3)/i', trim($model))) {
        // Minimize reasoning overhead on Gemma 4 / Gemini 3 models so the 55s cron loop stays fast.
        $generationConfig['thinkingConfig'] = ['thinkingLevel' => 'MINIMAL'];
    }
    return (string) json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => $generationConfig,
    ]);
}

/**
 * Builds a deterministic PSOBB canon bounty title and description when Gemini quota is exhausted.
 */
function bounty_fallback_story(array $obj, string $quest_giver, string $setting_id, int $mission_episode, string $difficulty): array
{
    $lore = bounty_lore();
    $place = trim((string) ($lore['settings'][$setting_id]['name'] ?? 'Ragol'));
    if ($place === '') {
        $place = "Episode {$mission_episode}";
    }
    $targetFriendly = trim((string) ($obj['selected_target_friendly'] ?? 'the assigned objective'));
    $client = trim($quest_giver) !== '' ? trim($quest_giver) : "Hunters Guild";

    $title = substr("Guild Posting: {$place} ({$difficulty})", 0, 96);
    $description = "{$client} filed an urgent Pioneer 2 request tied to {$place} on {$difficulty} difficulty. "
        . "Report to the field immediately and complete the objective ({$targetFriendly}), then confirm clear at the Guild counter.";
    return [
        'title' => $title,
        'description' => $description,
    ];
}

/**
 * Parses one part text payload (stripping optional markdown fences) into ['title', 'description'], or null.
 */
function bounty_decode_story_text(?string $text): ?array
{
    if (!is_string($text) || trim($text) === '') {
        return null;
    }
    $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
    $questData = json_decode((string) $cleaned, true);
    if (!is_array($questData)) {
        return null;
    }
    $title = trim((string) ($questData['title'] ?? ''));
    $description = trim((string) ($questData['description'] ?? ''));
    if ($title === '' || $description === '') {
        return null;
    }
    return [
        'title' => $title,
        'description' => $description,
    ];
}

/**
 * Parses Gemini / Gemma JSON response text (skipping thinking parts and markdown fences) into an array, or null.
 */
function bounty_parse_gemini_response_json(?string $rawResult): ?array
{
    if (!$rawResult) {
        return null;
    }
    $jsonRes = json_decode($rawResult, true);
    $parts = $jsonRes['candidates'][0]['content']['parts'] ?? null;
    if (!is_array($parts)) {
        return null;
    }
    // Prefer non-thought output parts first (Gemma 4 puts reasoning in parts with "thought": true).
    foreach ([true, false] as $skipThought) {
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $isThought = !empty($part['thought']);
            if ($skipThought && $isThought) {
                continue;
            }
            if (!$skipThought && !$isThought) {
                continue;
            }
            $decoded = bounty_decode_story_text($part['text'] ?? null);
            if ($decoded !== null) {
                return $decoded;
            }
        }
    }
    return null;
}

/**
 * Computes HTTP 429 cooldown seconds from the API error payload (short backoff for RPM, 3600s for daily quota).
 */
function bounty_gemini_429_cooldown_seconds(string $rawBody): int
{
    if (preg_match('/per[_\s-]*day|PerDay/i', $rawBody)) {
        return 3600;
    }
    if (preg_match('/"retryDelay"\s*:\s*"(\d+(?:\.\d+)?)s"/i', $rawBody, $rm)) {
        return max(15, min(180, (int) ceil((float) $rm[1]) + 5));
    }
    return 65;
}

/**
 * Returns true if at least one candidate Gemini/Gemma model is configured and not in cooldown.
 */
function bounty_gemini_has_available_model(): bool
{
    if (!empty($GLOBALS['BOUNTY_FORCE_FALLBACK'])) {
        return false;
    }
    if (isset($GLOBALS['BOUNTY_GEMINI_STUB']) && is_callable($GLOBALS['BOUNTY_GEMINI_STUB'])) {
        return true;
    }
    global $GEMINI_API_KEY, $GEMINI_MODEL;
    if (empty($GEMINI_API_KEY)) {
        return false;
    }
    if (!empty($_ENV['GEMINI_API_BASE'])) {
        return true;
    }
    $cooldownFile = __DIR__ . '/../db/.gemini_model_cooldowns.json';
    $now = time();
    $cooldowns = [];
    if (is_readable($cooldownFile)) {
        $decoded = json_decode((string) @file_get_contents($cooldownFile), true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $until) {
                if ((int) $until > $now) {
                    $cooldowns[(string) $k] = (int) $until;
                }
            }
        }
    }
    foreach (bounty_gemini_model_candidates($GEMINI_MODEL ?? '') as $model) {
        if (($cooldowns[$model] ?? 0) <= $now) {
            return true;
        }
    }
    return false;
}

/**
 * Sends one prompt to Gemini and returns the decoded JSON object it answers with, or null.
 * GEMINI_API_BASE in .env can point the call elsewhere (a proxy, or a stub for testing).
 */
function bounty_gemini_json($prompt)
{
    global $GEMINI_API_KEY, $GEMINI_MODEL;
    if (!empty($GLOBALS['BOUNTY_FORCE_FALLBACK'])) {
        return null;
    }
    // Test seam: api/test_bounty_system.php answers prompts itself instead of calling Gemini.
    if (isset($GLOBALS['BOUNTY_GEMINI_STUB']) && is_callable($GLOBALS['BOUNTY_GEMINI_STUB'])) {
        return ($GLOBALS['BOUNTY_GEMINI_STUB'])($prompt);
    }
    if (empty($GEMINI_API_KEY)) {
        return null;
    }

    $makeOptions = static function (string $model) use ($prompt): array {
        return [
            'http' => [
                'header' => "Content-type: application/json\r\n",
                'method' => 'POST',
                'content' => bounty_build_gemini_payload((string) $prompt, $model),
                'timeout' => 20,
                'ignore_errors' => true,
            ]
        ];
    };

    if (!empty($_ENV['GEMINI_API_BASE'])) {
        $base = rtrim($_ENV['GEMINI_API_BASE'], '/') . '/';
        $model = trim((string) ($GEMINI_MODEL ?? '')) ?: 'gemini-2.5-flash';
        $result = @file_get_contents($base . $model . ":generateContent?key=" . $GEMINI_API_KEY, false, stream_context_create($makeOptions($model)));
        return bounty_parse_gemini_response_json($result ?: null);
    }

    $base = "https://generativelanguage.googleapis.com/v1beta/models/";
    $cooldownFile = __DIR__ . '/../db/.gemini_model_cooldowns.json';
    $now = time();
    $cooldowns = [];
    if (is_readable($cooldownFile)) {
        $decoded = json_decode((string) @file_get_contents($cooldownFile), true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $until) {
                if ((int) $until > $now) {
                    $cooldowns[(string) $k] = (int) $until;
                }
            }
        }
    }

    $candidates = bounty_gemini_model_candidates($GEMINI_MODEL ?? '');
    foreach ($candidates as $model) {
        if (($cooldowns[$model] ?? 0) > $now) {
            continue;
        }
        $url = $base . rawurlencode($model) . ":generateContent?key=" . $GEMINI_API_KEY;
        $ctx = stream_context_create($makeOptions($model));
        $attempts = 0;
        $code = 0;
        $result = false;
        while ($attempts < 2) {
            $attempts++;
            $http_response_header = [];
            $result = @file_get_contents($url, false, $ctx);
            $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?: $http_response_header) : $http_response_header;
            $statusLine = (string) ($headers[0] ?? '');
            $code = preg_match('/\s(\d{3})\b/', $statusLine, $m) ? (int) $m[1] : ($result !== false ? 200 : 0);
            if ($code >= 500 && $code <= 504 && $attempts === 1) {
                usleep(800000);
                continue;
            }
            break;
        }

        if ($code === 429) {
            $ttl = bounty_gemini_429_cooldown_seconds((string) $result);
            $cooldowns[$model] = $now + $ttl;
            @file_put_contents($cooldownFile, json_encode($cooldowns), LOCK_EX);
            error_log("[BOUNTY] Gemini model {$model} returned HTTP 429; cooling down {$ttl}s and trying fallback.");
            continue;
        }
        if ($code >= 500 && $code <= 504) {
            error_log("[BOUNTY] Gemini model {$model} returned transient HTTP {$code}; trying fallback without cooldown.");
            continue;
        }
        if ($code >= 400 || !$result) {
            if ($code >= 400) {
                $cooldowns[$model] = $now + 300;
                @file_put_contents($cooldownFile, json_encode($cooldowns), LOCK_EX);
                error_log("[BOUNTY] Gemini model {$model} returned HTTP {$code}; trying fallback.");
            }
            continue;
        }

        $parsed = bounty_parse_gemini_response_json($result);
        if ($parsed !== null) {
            return $parsed;
        }
    }
    return null;
}

/**
 * The original reward roll: 1-3 items (more at higher level), mostly common, with a level-based
 * rare chance, visible stats, plus Meseta. Used when the Bounty System Rework is disabled.
 *
 * @return string reward_item_string
 */
function bounty_legacy_reward(array $obj, $quest_giver)
{
    $level = $obj['level'];
    $selected_goal = $obj['selected_goal'];
    $selected_target_id = $obj['selected_target_id'];
    $class_map = $obj['class_map'];
    $class_id = $obj['class_id'];

    $num_items_to_reward = 1;
    if ($level >= 80) $num_items_to_reward = rand(2, 3);
    elseif ($level >= 40) $num_items_to_reward = rand(1, 2);

    // Challenge Mode bounties get bonus items (+1) as incentive
    if ($selected_goal === 'CHALLENGE_STAGES') {
        $num_items_to_reward += 1;
    }

    $reward_items_array = [];
    $rare_count = 0;
    for ($item_idx = 0; $item_idx < $num_items_to_reward; $item_idx++) {
        $randCat = rand(1, 100);
        if ($randCat <= 30) $category = 'Weapon';
        elseif ($randCat <= 50) $category = (rand(0,1) == 0 ? 'Armor' : 'Shield');
        elseif ($randCat <= 70) $category = 'Unit';
        else $category = 'Random';

        // Level/Difficulty base modifiers
        $rareChance = 0;
        if ($rare_count < 1) { // Limit to max 1 rare item per bounty
            if ($level >= 80) $rareChance = 30; // Ultimate
            elseif ($level >= 40) $rareChance = 15; // V.Hard
            elseif ($level >= 20) $rareChance = 5;  // Hard
        }
        
        // Hex, team bounties always give out pure rare loot!
        if (str_starts_with($quest_giver, 'Hex') || $selected_goal === 'HARDCORE_MENTOR' || $selected_goal === 'DIVERSE_PARTY_BOSS') {
            $rareChance = 100;
            $rare_count = 0; // Bypass the 1-rare limit
        }
        // Challenge Mode gets a flat 75% rare chance
        if ($selected_goal === 'CHALLENGE_STAGES') {
            $rareChance = 75;
        }
        
        $rawCharClass = isset($class_map[$class_id]) ? explode(' ', $class_map[$class_id])[0] : 'HUmar';

        $max_retries = 10;
        $is_rare = false;
        do {
            if ($category === 'Random' || rand(1, 100) <= $rareChance) {
                $base_reward = get_reward_item($level, $rawCharClass, $category);
                if ($category !== 'Random') $is_rare = true;
            } else {
                $base_reward = get_common_reward_item($level, $rawCharClass, $category);
                $is_rare = false;
            }
            $max_retries--;
        } while ($max_retries > 0 && ($selected_target_id !== null && stripos($base_reward, (string)$selected_target_id) !== false));
        
        if ($is_rare) $rare_count++;
        
        $single_item_string = $base_reward;

        // Procedural Stat Generation
        $is_challenge = ($selected_goal === 'CHALLENGE_STAGES');
        if ($category === 'Weapon') {
            $stats = [0, 0, 0, 0]; // [Native, A.Beast, Machine, Dark]
            $base_weapon_id = strtoupper(substr(trim($base_reward), 0, 6));
            $is_unsealable_weapon = in_array($base_weapon_id, PSO_UNSEALABLE_WEAPONS, true);
            // Unsealable weapons store their kill counter in attribute slot 3 (bytes 10..11), so max total attributes is 2 (vs 3 for normal weapons).
            // Challenge Mode weapons get guaranteed hit%, reducing available non-hit attribute slots by 1.
            $maxNonHitStats = ($is_unsealable_weapon ? 2 : 3) - ($is_challenge ? 1 : 0);
            $numStatsToAssign = rand(1, $maxNonHitStats);
            $availableIndices = [0, 1, 2, 3];
            shuffle($availableIndices);
            for ($i = 0; $i < $numStatsToAssign; $i++) {
                $index = $availableIndices[$i];
                $amount = $is_challenge ? rand(3, 10) * 5 : rand(1, 10) * 5;
                $stats[$index] = $amount;
            }
            // Challenge Mode weapons get guaranteed 25%-50% Hit
            $hit = $is_challenge ? rand(5, 10) * 5 : 0;
            $single_item_string .= " " . implode("/", $stats) . ($hit > 0 ? "/$hit" : "");
        } else if ($category === 'Armor' || $category === 'Shield') {
            $defBonus = $is_challenge ? rand(2, 5) * 5 : rand(0, 5) * 5;
            $evpBonus = $is_challenge ? rand(2, 5) * 5 : rand(0, 5) * 5;
            
            if ($defBonus > 0) $single_item_string .= " +" . $defBonus . "def";
            if ($evpBonus > 0) $single_item_string .= " +" . $evpBonus . "evp";

            if ($category === 'Armor') {
                $single_item_string .= " +4";
            }
        } else if ($category === 'Unit') {
            // Units natively receive their + or ++ modifiers inside their hex payload.
            // They do not use def/evp text modifiers.
        }
        $reward_items_array[] = $single_item_string;
    }
    $reward_item_string = implode(", ", $reward_items_array);

    // Add Meseta to reward (Challenge Mode gets 3x Meseta)
    $meseta_multiplier = ($selected_goal === 'CHALLENGE_STAGES') ? 3 : 1;
    $meseta_reward = rand(1, 5) * 1000 * ($level >= 80 ? 10 : ($level >= 40 ? 5 : ($level >= 20 ? 2 : 1))) * $meseta_multiplier;
    $reward_item_string .= ", " . $meseta_reward . " Meseta";

    return $reward_item_string;
}
