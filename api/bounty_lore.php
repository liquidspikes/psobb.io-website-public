<?php
/**
 * --------------------------------------------------------------------------
 * Lore grounding for the AI bounty generator (cron_missions.php)
 * --------------------------------------------------------------------------
 * The generator picks the mechanical objective in PHP and asks Gemini only for a
 * title and a short story. This file gives Gemini the world to write it in:
 *
 *   api/lore/world.json   canon summary and writing rules shared by every bounty
 *   api/lore/ep1.json     Episode I settings (Pioneer 2, Forest, Caves, Mines, Ruins)
 *   api/lore/ep2.json     Episode II settings (Lab, VR Temple, VR Spaceship, Gal Da Val, Seabed)
 *   api/lore/ep4.json     Episode IV settings (Pioneer 2 in 3086, Crater, Subterranean Desert)
 *
 * Each setting card describes one area AT ITS MOMENT in the story (what people know then,
 * open rumours, later truths that must not be stated, creatures, places, factions, kinds of
 * job that fit) and lists the quest givers who could plausibly post a job there then. The
 * cards were written from "The Ragol Chronicles", a timeline retelling of the game scripts.
 * See api/lore/README.md for the format.
 *
 * Everything here degrades gracefully: if the lore files are missing or invalid,
 * bounty_lore() returns null and the cron falls back to its old generic prompt.
 */

/**
 * Reads one JSON file; returns the decoded array or null.
 */
function bounty_lore_read_json($path)
{
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/**
 * Loads and merges the lore files once per process.
 *
 * Cast ids are namespaced by episode ("ep1:bernie"), because the same person can be a
 * different quest giver in different years (Sue in 3084 is not Sue in 3086).
 *
 * @return array|null ['world'=>..., 'settings'=>[id=>card], 'cast'=>[ns_id=>card],
 *                     'never_givers'=>[...], 'examples'=>[...]] or null if unusable.
 */
function bounty_lore()
{
    static $lore = false;
    if ($lore !== false) {
        return $lore;
    }
    $dir = __DIR__ . '/lore';
    $world = bounty_lore_read_json("$dir/world.json");
    if (!$world) {
        return $lore = null;
    }
    $lore = ['world' => $world, 'settings' => [], 'cast' => [], 'never_givers' => [], 'examples' => []];
    foreach ([1, 2, 4] as $ep) {
        $data = bounty_lore_read_json("$dir/ep{$ep}.json");
        if (!$data) {
            echo "[LORE] api/lore/ep{$ep}.json missing or invalid; Episode {$ep} bounties use the generic prompt.\n";
            continue;
        }
        foreach (($data['cast'] ?? []) as $id => $card) {
            $lore['cast']["ep{$ep}:{$id}"] = $card;
        }
        foreach (($data['settings'] ?? []) as $id => $card) {
            $card['episode'] = $ep;
            $card['cast'] = array_values(array_filter(array_map(
                function ($cid) use ($ep) { return "ep{$ep}:{$cid}"; },
                $card['cast'] ?? []
            ), function ($nsid) use ($lore) { return isset($lore['cast'][$nsid]); }));
            $lore['settings'][$id] = $card;
        }
        foreach (($data['never_givers'] ?? []) as $ng) {
            $ng['episode'] = $ep;
            $lore['never_givers'][] = $ng;
        }
        foreach (($data['examples'] ?? []) as $ex) {
            $ex['episode'] = $ep;
            $lore['examples'][] = $ex;
        }
    }
    if (empty($lore['settings'])) {
        $lore = null;
    }
    return $lore;
}

/**
 * Bosses the generator may target: [floor, episode, name, minimum level, setting id].
 * Floors are newserv LocationFloor values; Ep1 and Ep2 share floors 12-14, which is why
 * boss targets are stored with an explicit episode ("13@2", see make_boss_target()).
 */
function bounty_boss_table()
{
    return [
        [11, 1, 'Dragon', 1, 'ep1_forest'],
        [12, 1, 'De Rol Le', 10, 'ep1_caves'],
        [13, 1, 'Vol Opt', 20, 'ep1_mines'],
        [14, 1, 'Dark Falz', 30, 'ep1_ruins'],
        [14, 2, 'Barba Ray', 15, 'ep2_vr_temple'],
        [15, 2, 'Gol Dragon', 30, 'ep2_vr_spaceship'],
        [12, 2, 'Gal Gryphon', 35, 'ep2_gal_da_val'],
        [13, 2, 'Olga Flow', 40, 'ep2_seabed'],
        [9, 4, 'Saint-Milion', 50, 'ep4_desert'],
    ];
}

/**
 * Picks a boss suited to the player's level, avoiding the arena floor they are standing in
 * (so the bounty cannot complete instantly).
 *
 * @return array [floor, episode, name, min_level, setting_id]
 */
function bounty_pick_boss($level, $current_floor)
{
    $choices = array_values(array_filter(bounty_boss_table(), function ($b) use ($level, $current_floor) {
        return $level >= $b[3] && $b[0] !== (int) $current_floor;
    }));
    if (empty($choices)) {
        return bounty_boss_table()[0];
    }
    return $choices[array_rand($choices)];
}

/**
 * The setting ID for a field floor in Episode 1, 2, or 4.
 */
function bounty_setting_for_floor($episode, $floor)
{
    $ep = (int) $episode;
    $floor = (int) $floor;
    if ($ep === 2) {
        if ($floor >= 1 && $floor <= 2) return 'ep2_vr_temple';
        if ($floor >= 3 && $floor <= 4) return 'ep2_vr_spaceship';
        if ($floor >= 5 && $floor <= 9) return 'ep2_gal_da_val';
        if ($floor >= 10 && $floor <= 11) return 'ep2_seabed';
        return 'ep2_lab';
    }
    if ($ep === 4) {
        if ($floor >= 1 && $floor <= 5) return 'ep4_crater';
        if ($floor >= 6 && $floor <= 8) return 'ep4_desert';
        return 'ep4_pioneer2';
    }
    if ($floor >= 1 && $floor <= 2) return 'ep1_forest';
    if ($floor >= 3 && $floor <= 5) return 'ep1_caves';
    if ($floor >= 6 && $floor <= 7) return 'ep1_mines';
    if ($floor >= 8 && $floor <= 10) return 'ep1_ruins';
    return 'ep1_pioneer2';
}

/**
 * The Episode I setting for an Episode I field floor (Forest 1 ... Ruins 3).
 */
function bounty_setting_for_ep1_floor($floor)
{
    return bounty_setting_for_floor(1, $floor);
}

/**
 * A setting for an objective with no place of its own (Meseta, levels, materials...):
 * one of the episodes' hubs (Pioneer 2 in 3084-85, the Lab in 3085, Pioneer 2 in 3086).
 *
 * @param int|null $seed When set, selects deterministically instead of randomly.
 */
function bounty_pick_hub_setting($seed = null, array $activeSettings = [])
{
    $lore = bounty_lore();
    $hubs = $lore['world']['hubs'] ?? ['1' => 'ep1_pioneer2', '2' => 'ep2_lab', '4' => 'ep4_pioneer2'];
    $available = [];
    foreach ($hubs as $ep => $sid) {
        if ($lore && isset($lore['settings'][$sid])) {
            $available[(int) $ep] = $sid;
        }
    }
    if (empty($available)) {
        return [1, 'ep1_pioneer2'];
    }
    if ($seed !== null) {
        ksort($available);
        $eps = array_keys($available);
        $ep = $eps[abs((int) $seed) % count($eps)];
        return [$ep, $available[$ep]];
    }
    if (!empty($activeSettings)) {
        $counts = [];
        foreach ($available as $ep => $sid) {
            $c = 0;
            foreach ($activeSettings as $used) {
                if ((string) $used === (string) $sid) {
                    $c++;
                }
            }
            $counts[$ep] = $c;
        }
        $minCount = min($counts);
        $leastUsed = array_keys(array_filter($counts, static fn($c) => $c === $minCount));
        $ep = $leastUsed[array_rand($leastUsed)];
        return [$ep, $available[$ep]];
    }
    $ep = array_rand($available);
    return [$ep, $available[$ep]];
}

/**
 * The setting for a job that crosses a whole episode (a visit to every area, a full run): the card
 * of the episode's deepest area. Its moment comes after every area before it was found, so its
 * story can name all of them. The hub cards are set earlier: Episode 4's is the first days after the
 * meteor, before anyone knew of the Subterranean Desert, which a full run ends in.
 */
function bounty_episode_setting($episode)
{
    $lore = bounty_lore();
    $best = null;
    $deepest = -1;
    foreach ($lore['settings'] ?? [] as $sid => $s) {
        if ((int) ($s['episode'] ?? 0) !== (int) $episode) {
            continue;
        }
        $top = max(array_map('intval', $s['floors'] ?? [0]) ?: [0]);
        if ($top > $deepest) {
            $deepest = $top;
            $best = $sid;
        }
    }
    return $best ?? ($lore['world']['hubs'][(string) $episode] ?? ['1' => 'ep1_pioneer2', '2' => 'ep2_lab', '4' => 'ep4_pioneer2'][(string) $episode] ?? 'ep1_pioneer2');
}

/**
 * Picks a quest giver from the setting's era-appropriate cast.
 *
 * @param string   $setting_id
 * @param int|null $seed When set, picks deterministically so recurring messages keep one voice.
 * @return array|null ['id'=>ns_id, ...card] or null if the setting has no cast.
 */
function bounty_pick_giver_seeded($setting_id, $seed = null)
{
    $lore = bounty_lore();
    $setting = $lore['settings'][$setting_id] ?? null;
    if (!$setting || empty($setting['cast'])) {
        return null;
    }
    $castIds = array_values($setting['cast']);
    if ($seed !== null) {
        $id = $castIds[abs((int) $seed) % count($castIds)];
    } else {
        $id = $castIds[array_rand($castIds)];
    }
    return ['id' => $id] + $lore['cast'][$id];
}

/**
 * Picks a quest giver from the setting's era-appropriate cast, avoiding recently used names when possible.
 *
 * @return array|null ['id'=>ns_id, ...card] or null if the setting has no cast.
 */
function bounty_pick_giver($setting_id, array $avoidNames = [])
{
    if (!empty($avoidNames)) {
        $lore = bounty_lore();
        $setting = $lore['settings'][$setting_id] ?? null;
        if ($setting && !empty($setting['cast'])) {
            $fresh = [];
            foreach ($setting['cast'] as $id) {
                $name = (string) ($lore['cast'][$id]['name'] ?? '');
                $used = false;
                foreach ($avoidNames as $av) {
                    if ($name !== '' && strcasecmp($name, (string) $av) === 0) {
                        $used = true;
                        break;
                    }
                }
                if (!$used && isset($lore['cast'][$id])) {
                    $fresh[] = $id;
                }
            }
            if ($fresh) {
                $pick = $fresh[array_rand($fresh)];
                return ['id' => $pick] + $lore['cast'][$pick];
            }
        }
    }
    return bounty_pick_giver_seeded($setting_id, null);
}

/**
 * Maps any bounty or Community Event goal_type + goal_target to the appropriate lore setting card.
 *
 * Handles multi-boss Community Event targets (EP1_BOSS_RUSH, DIGITAL_BLASPHEMY, etc.),
 * episode-qualified single bosses ("12@2"), Ep1 field floors (including speedrun "7_300"),
 * and non-location hub objectives. Safe to call even if api/lore/ files are absent.
 *
 * @return array ['episode'=>int, 'setting_id'=>string, 'is_location_goal'=>bool, 'floor'=>int|null, 'location_override'=>string|null]
 */
function bounty_lore_setting_for_objective($goal_type, $goal_target = '', $title = '', $desc = '', $seed = null)
{
    $goal_type = strtoupper(trim((string) $goal_type));
    $goal_target = trim((string) $goal_target);
    $lore = bounty_lore();

    $multiBoss = [
        'EP1_BOSS_RUSH' => [
            'episode' => 1,
            'setting_id' => bounty_episode_setting(1),
            'is_location_goal' => true,
            'floor' => null,
            'location_override' => 'This fleet-wide operation spans all four surveyed regions of Ragol in Episode I: Forest, Caves, Mines, and the Ruins beneath them.',
        ],
        'EP2_BOSS_RUSH' => [
            'episode' => 2,
            'setting_id' => bounty_episode_setting(2),
            'is_location_goal' => true,
            'floor' => null,
            'location_override' => 'This fleet-wide operation spans the Pioneer 2 Laboratory VR simulators, Gal Da Val Island, and the Seabed facility beneath it.',
        ],
        'DIGITAL_BLASPHEMY' => [
            'episode' => 4,
            'setting_id' => ($lore && isset($lore['settings']['ep4_desert'])) ? 'ep4_desert' : bounty_episode_setting(4),
            'is_location_goal' => true,
            'floor' => null,
            'location_override' => 'This fleet-wide operation targets Vol Opt in the Mines, Gol Dragon inside the Lab VR Spaceship simulator, and Shambertin in the Subterranean Desert.',
        ],
        'DRACONIC_DOMINION' => [
            'episode' => 4,
            'setting_id' => ($lore && isset($lore['settings']['ep4_desert'])) ? 'ep4_desert' : bounty_episode_setting(4),
            'is_location_goal' => true,
            'floor' => null,
            'location_override' => 'This fleet-wide operation targets the Dragon beneath the Central Dome, Gol Dragon in the Lab VR Spaceship simulator, and Shambertin in the Subterranean Desert.',
        ],
        'CATACLYSMIC_CORE' => [
            'episode' => 4,
            'setting_id' => ($lore && isset($lore['settings']['ep4_desert'])) ? 'ep4_desert' : bounty_episode_setting(4),
            'is_location_goal' => true,
            'floor' => null,
            'location_override' => 'This fleet-wide operation strikes the deepest threats uncovered in the Ruins, the Seabed depths, and the Subterranean Desert.',
        ],
        'ALL_BOSSES' => [
            'episode' => 4,
            'setting_id' => ($lore && isset($lore['settings']['ep4_desert'])) ? 'ep4_desert' : bounty_episode_setting(4),
            'is_location_goal' => true,
            'floor' => null,
            'location_override' => 'This Guild-wide general mobilisation calls on Hunter teams to hunt major threats across every surveyed region of Ragol and the Lab simulators.',
        ],
        'ANY_DRAGON' => [
            'episode' => 2,
            'setting_id' => ($lore && isset($lore['settings']['ep2_vr_spaceship'])) ? 'ep2_vr_spaceship' : 'ep1_forest',
            'is_location_goal' => true,
            'floor' => null,
            'location_override' => 'Hunters may strike either the Dragon beneath the Central Dome on Ragol or the Gol Dragon inside the Lab\'s VR Spaceship simulator.',
        ],
    ];

    if ($goal_type === 'ENEMY_KILL') {
        if ($goal_target === 'RARE_RAPPIES') {
            return [
                'episode' => 1,
                'setting_id' => ($lore && isset($lore['settings']['ep1_forest'])) ? 'ep1_forest' : bounty_episode_setting(1),
                'is_location_goal' => true,
                'floor' => null,
                'location_override' => 'Unusual seasonal flocks of Rare Rappies—Al Rappy and Pal Rappy in Ragol\'s Forest, Love Rappy and seasonal variants in the Pioneer 2 Lab VR Temple, and Del Rappy across the Crater and Subterranean Desert—are being tracked across all episodes.',
            ];
        }
        return [
            'episode' => 1,
            'setting_id' => ($lore && isset($lore['settings']['ep1_forest'])) ? 'ep1_forest' : bounty_episode_setting(1),
            'is_location_goal' => true,
            'floor' => null,
            'location_override' => 'Hunters are tracking high-value rare enemies and seasonal anomalies across every surveyed region of Ragol and the Lab simulators.',
        ];
    }

    $bossGoals = ['BOSS_ARENA', 'SPEEDRUN_BOSS', 'MENTOR_BOSS', 'HARDCORE_MENTOR', 'DIVERSE_PARTY_BOSS'];
    if (in_array($goal_type, $bossGoals, true)) {
        if (isset($multiBoss[$goal_target])) {
            return $multiBoss[$goal_target];
        }

        if (function_exists('parse_boss_target')) {
            $parsed = parse_boss_target($goal_target);
            $floor = (int) $parsed['floor'];
            $explicitEp = $parsed['episode'];
        } else {
            $floor = (int) $goal_target;
            $explicitEp = preg_match('/@([124])/', $goal_target, $m) ? (int) $m[1] : null;
        }

        // Map legacy synthetic Community Event IDs (16-19) when no explicit episode is present.
        $legacyMap = [16 => [15, 2], 17 => [14, 2], 18 => [13, 2], 19 => [9, 4]];
        if ($explicitEp === null && isset($legacyMap[$floor])) {
            list($floor, $explicitEp) = $legacyMap[$floor];
        }

        if ($explicitEp === null) {
            if (function_exists('get_boss_episode_by_context')) {
                $ep = get_boss_episode_by_context($title, $desc, $floor, null);
            } else {
                $ep = ($floor === 15) ? 2 : (($floor === 9) ? 4 : 1);
            }
        } else {
            $ep = (int) $explicitEp;
        }

        foreach (bounty_boss_table() as $b) {
            if ($b[0] === $floor && $b[1] === $ep) {
                return [
                    'episode' => $ep,
                    'setting_id' => $b[4],
                    'is_location_goal' => true,
                    'floor' => null,
                    'location_override' => null,
                ];
            }
        }
        return [
            'episode' => $ep,
            'setting_id' => bounty_episode_setting($ep),
            'is_location_goal' => true,
            'floor' => null,
            'location_override' => null,
        ];
    }

    if (in_array($goal_type, ['EXPLORATION', 'PATROL', 'SPEEDRUN_FLOOR'], true)) {
        $ft = parse_floor_target($goal_target);
        $ep = $ft['episode'];
        $floor = $ft['floor'];
        $validFloors = pso_episode_floor_map($ep);
        return [
            'episode' => $ep,
            'setting_id' => bounty_setting_for_floor($ep, $floor),
            'is_location_goal' => true,
            'floor' => isset($validFloors[$floor]) ? $floor : null,
            'location_override' => null,
        ];
    }

    // Hub / non-location objective: honor explicit Episode cues in existing text when present.
    $contextText = mb_strtolower($title . ' ' . $desc);
    $hubs = $lore['world']['hubs'] ?? ['1' => 'ep1_pioneer2', '2' => 'ep2_lab', '4' => 'ep4_pioneer2'];
    if ($contextText !== '' && trim($contextText) !== '') {
        if (preg_match('/\b(ep\s*4|episode\s*4|crater|desert|meteor|shambertin|saint-milion)\b/i', $contextText)) {
            $sid = $hubs['4'] ?? 'ep4_pioneer2';
            if (!$lore || isset($lore['settings'][$sid])) {
                return ['episode' => 4, 'setting_id' => $sid, 'is_location_goal' => false, 'floor' => null, 'location_override' => null];
            }
        }
        if (preg_match('/\b(ep\s*2|episode\s*2|lab|vr\s*temple|vr\s*spaceship|gal\s*da\s*val|seabed|olga\s*flow|gal\s*gryphon|gol\s*dragon|barba\s*ray)\b/i', $contextText)) {
            $sid = $hubs['2'] ?? 'ep2_lab';
            if (!$lore || isset($lore['settings'][$sid])) {
                return ['episode' => 2, 'setting_id' => $sid, 'is_location_goal' => false, 'floor' => null, 'location_override' => null];
            }
        }
    }

    list($ep, $sid) = bounty_pick_hub_setting($seed);
    return [
        'episode' => (int) $ep,
        'setting_id' => $sid,
        'is_location_goal' => false,
        'floor' => null,
        'location_override' => null,
    ];
}

/**
 * Formats a list as "- item" lines, skipping empty values.
 */
function bounty_lore_lines($items, $limit = 12)
{
    $out = [];
    foreach (array_slice(array_values((array) $items), 0, $limit) as $item) {
        $item = trim((string) $item);
        if ($item !== '') {
            $out[] = '- ' . $item;
        }
    }
    return implode("\n", $out);
}

/**
 * Builds the lore part of the Gemini prompt for one bounty.
 *
 * @param string     $setting_id  key of a setting card
 * @param array|null $giver       from bounty_pick_giver(), or null when Hex gives the job
 * @return string
 */
function bounty_lore_prompt($setting_id, $giver)
{
    $lore = bounty_lore();
    $world = $lore['world'];
    $s = $lore['settings'][$setting_id];
    $ep = (int) $s['episode'];

    $p = "THE WORLD (canon; never contradict it):\n" . bounty_lore_lines($world['summary'] ?? [], 20) . "\n\n";
    $p .= "WRITING RULES:\n" . bounty_lore_lines($world['rules'] ?? [], 20) . "\n\n";

    $p .= "THE SETTING: " . ($s['name'] ?? $setting_id) . " (Episode {$ep}). Moment: " . ($s['era'] ?? '') . "\n";
    $p .= trim((string) ($s['summary'] ?? '')) . "\n";
    if (!empty($s['boss']['name'])) {
        $p .= "Boss of this area: " . $s['boss']['name']
            . (!empty($s['boss']['ultimate_name']) ? " (in Ultimate: " . $s['boss']['ultimate_name'] . ")" : '') . "\n";
    }
    $sections = [
        'known_facts' => 'What people know at this moment',
        'open_mysteries' => 'Open questions and rumours (a job may hint at them, never answer them)',
        'hidden_truths' => 'Hidden truths (NEVER state or hint at these; only never contradict them)',
        'creatures' => 'Creatures here',
        'landmarks' => 'Places here',
        'factions' => 'Groups active here',
        'job_hooks' => 'Kinds of job that fit here',
        'avoid' => 'Avoid',
    ];
    foreach ($sections as $key => $label) {
        if (!empty($s[$key])) {
            $p .= "{$label}:\n" . bounty_lore_lines($s[$key], 14) . "\n";
        }
    }

    $never = array_values(array_filter($lore['never_givers'], function ($ng) use ($ep) {
        return (int) ($ng['episode'] ?? 0) === $ep;
    }));
    if ($never) {
        $p .= "Never a client in this episode:\n" . bounty_lore_lines(array_map(function ($ng) {
            return ($ng['name'] ?? '') . ' (' . ($ng['why'] ?? '') . ')';
        }, $never), 12) . "\n";
    }

    if ($giver) {
        $p .= "\nTHE CLIENT: " . ($giver['name'] ?? 'a Guild client') . ". " . ($giver['role'] ?? '') . "\n";
        if (!empty($giver['voice'])) $p .= "Voice: " . $giver['voice'] . "\n";
        if (!empty($giver['available'])) $p .= "Where and when: " . $giver['available'] . "\n";
        if (!empty($giver['ties'])) $p .= "Ties:\n" . bounty_lore_lines($giver['ties'], 6) . "\n";
        if (!empty($giver['never'])) $p .= "This client must never:\n" . bounty_lore_lines($giver['never'], 6) . "\n";
    }

    // Two or three real jobs from the same setting (or episode) as a style reference.
    $examples = array_values(array_filter($lore['examples'], function ($ex) use ($setting_id) {
        return ($ex['setting'] ?? '') === $setting_id;
    }));
    if (count($examples) < 2) {
        $examples = array_merge($examples, array_values(array_filter($lore['examples'], function ($ex) use ($ep, $setting_id) {
            return (int) ($ex['episode'] ?? 0) === $ep && ($ex['setting'] ?? '') !== $setting_id;
        })));
    }
    shuffle($examples);
    $examples = array_slice($examples, 0, 3);
    if ($examples) {
        $p .= "\nREAL JOBS FROM THIS TIME (style reference only; do not copy them or reuse their plots):\n"
            . bounty_lore_lines(array_map(function ($ex) {
                return ($ex['client'] ?? 'A client') . ': ' . ($ex['brief'] ?? '');
            }, $examples), 3) . "\n";
    }
    return $p;
}

/**
 * What each objective type asks of the hunter, in words the story can use. The objective
 * label alone ("MENTOR_BOSS", "12 total consumed LUCK materials") does not tell Gemini what
 * the hunter must actually do, or how to say it in-world.
 */
function bounty_goal_meaning($goal, $target = null)
{
    if ($goal === 'TECHNIQUE' && $target !== null) {
        $techs = [
            'Foie' => 'a fire attack', 'Zonde' => 'a lightning attack', 'Barta' => 'an ice attack',
            'Megid' => 'a dark attack that can kill outright', 'Grants' => 'a light attack',
            'Resta' => 'heals the party', 'Anti' => 'cures poison and paralysis',
            'Shifta' => 'raises the party\'s attack', 'Deband' => 'raises the party\'s defence',
            'Jellen' => 'lowers enemies\' attack', 'Zalure' => 'lowers enemies\' defence',
            'Ryuker' => 'opens a way back to Pioneer 2', 'Reverser' => 'revives a fallen ally',
        ];
        foreach ($techs as $name => $what) {
            if (stripos((string) $target, $name) !== false) {
                return "Learn the {$name} technique ({$what}) from a technique disk.";
            }
        }
    }
    $m = [
        'BOSS_ARENA' => 'Defeat the named creature in its lair.',
        'MENTOR_BOSS' => 'Defeat the named creature with a less experienced hunter (at least 5 levels lower) in the party, so the client has a reason to want a newcomer brought along and taught.',
        'HARDCORE_MENTOR' => 'Defeat the named creature while leading three or more much less experienced hunters (at least 10 levels lower), so the client has a reason to want a group of newcomers brought through it.',
        'DIVERSE_PARTY_BOSS' => 'Defeat the named creature with a party that includes at least one Hunter, one Ranger and one Force, so the client has a reason to want all three kinds of fighter there.',
        'SPEEDRUN_BOSS' => 'Defeat the named creature within the time limit, counted from entering its lair, so the client has a reason to need it done fast.',
        'SPEEDRUN_FLOOR' => 'Cross the named area within the time limit, so the client has a reason to need speed.',
        'EXPLORATION' => 'Reach the named area.',
        'PATROL' => 'Stay in the named area and keep watch there for ten minutes.',
        'MESETA' => 'Hold at least that much Meseta at once (saved up, not spent).',
        'LEVEL' => 'Grow stronger through field experience until reaching that level.',
        'PLAYTIME' => 'Put in that much total time on duty as a Hunter.',
        'PLAY_HOURS' => 'Put in that much time on duty as a Hunter, counted from when the job is taken.',
        'ITEM' => 'Find a weapon of that type on Ragol and carry it back.',
        'TECHNIQUE' => 'Learn that technique (Forces and some Hunters and Rangers learn techniques from technique disks).',
        'BATTLE_WINS' => 'Win first place in that many sanctioned battle-simulation bouts between hunters.',
        'CHALLENGE_STAGES' => 'Clear that many of the challenge trials, set courses where a team starts with basic gear.',
        'CHALLENGE_RUN' => 'Clear the named challenge trial course or courses, set courses where a team starts over with basic issued gear, within the time given if there is one.',
        'BATTLE_RULE_WINS' => 'Win first place that many times in sanctioned battle-simulation bouts between hunters, fought under the named rules.',
        'LEVEL_UP' => 'Earn field levels across participating hunters (every level gained by hunters contributes toward the shared total).',
        'MAT_CONSUME' => 'Use stat Materials across participating hunters (HP, TP, Power, Def, Mind, Evade, or Luck Materials all contribute).',
        'MAT_HP' => 'Keep using HP Materials, items that permanently strengthen the body, until the hunter\'s career total reaches that number.',
        'MAT_TP' => 'Keep using TP Materials, items that permanently strengthen technique power, until the hunter\'s career total reaches that number.',
        'MAT_POWER' => 'Keep using Power Materials, items that permanently raise attack strength, until the hunter\'s career total reaches that number.',
        'MAT_DEF' => 'Keep using Def Materials, items that permanently raise defence, until the hunter\'s career total reaches that number.',
        'MAT_MIND' => 'Keep using Mind Materials, items that permanently raise mental strength, until the hunter\'s career total reaches that number.',
        'MAT_EVADE' => 'Keep using Evade Materials, items that permanently sharpen reflexes, until the hunter\'s career total reaches that number.',
        'MAT_LUCK' => 'Keep using Luck Materials, items that permanently improve luck, until the hunter\'s career total reaches that number.',
        'AREA_SWEEP' => 'Visit every area of that episode on the required difficulty over the course of the week.',
        'BOSS_HUNT' => 'Defeat bosses across Ragol on the required difficulty over the course of the week.',
        'ENEMY_HUNT' => 'Clear out that many hostile creatures across Ragol on the required difficulty over the course of the week.',
        'RARE_HUNT' => 'Track down and defeat rare creatures on Ragol on the required difficulty over the course of the week.',
        'RARE_TARGET' => 'Track down and defeat the named rare creature where it appears on the required difficulty.',
        'EPISODE_RUN' => 'Fight through every area and defeat every guardian of that episode in a single expedition under the contract\'s conditions.',
    ];
    return $m[$goal] ?? '';
}

/**
 * In-world wording for field areas across Episodes 1, 2, and 4, so the story never needs menu
 * labels such as "Cave 2" or "Seabed Lower".
 */
function bounty_floor_phrase($floor, $episode = 1)
{
    $phrases = [
        1 => [
            1 => 'the outer Forest', 2 => 'the inner Forest near the Central Dome',
            3 => 'the upper Caves', 4 => 'the middle Caves', 5 => 'the deepest Caves',
            6 => 'the upper Mines', 7 => 'the lower Mines',
            8 => 'the upper Ruins', 9 => 'the middle Ruins', 10 => 'the deepest Ruins',
        ],
        2 => [
            1 => 'the outer halls of the VR Temple', 2 => 'the inner sanctum of the VR Temple',
            3 => 'the forward decks of the VR Spaceship', 4 => 'the deeper decks of the VR Spaceship',
            5 => 'the Central Control Area on Gal Da Val Island',
            6 => 'the northern jungle of Gal Da Val Island', 7 => 'the eastern jungle of Gal Da Val Island',
            8 => 'the high mountain pass on Gal Da Val Island', 9 => 'the seaside cliffs of Gal Da Val Island',
            10 => 'the upper levels of the Seabed facility', 11 => 'the abyssal lower decks of the Seabed',
        ],
        4 => [
            1 => 'the eastern Crater rim', 2 => 'the western Crater rim',
            3 => 'the southern Crater rim', 4 => 'the northern Crater rim',
            5 => 'the Crater Interior impact zone',
            6 => 'the outer Subterranean Desert', 7 => 'the middle Subterranean Desert caverns',
            8 => 'the deepest ruins of the Subterranean Desert',
        ],
    ];
    return $phrases[(int) $episode][(int) $floor] ?? '';
}

function bounty_episode_from_setting_id($lore, $setting_id): int
{
    if (!empty($lore['settings'][$setting_id]['episode'])) {
        return (int) $lore['settings'][$setting_id]['episode'];
    }
    if (strpos((string) $setting_id, 'ep2_') === 0) return 2;
    if (strpos((string) $setting_id, 'ep4_') === 0) return 4;
    return 1;
}

/**
 * Strips legacy code-name prefixes ("Operation:", "Protocol:", "Project:", "Directive:",
 * "Contract:") from a comma-separated recent-titles list before embedding it in a prompt, so
 * older database rows do not seed few-shot prefix mimicry on new Guild postings.
 */
function bounty_clean_recent_titles($recent_titles)
{
    $raw = trim((string) $recent_titles);
    if ($raw === '' || strcasecmp($raw, 'None') === 0) {
        return 'None';
    }
    $parts = preg_split('/\s*,\s*/', $raw);
    $cleaned = [];
    $seen = [];
    foreach ($parts as $part) {
        $t = trim(preg_replace('/^(?:Operation|Protocol|Project|Directive|Contract)\s*:\s*/iu', '', (string) $part));
        if ($t === '' || strcasecmp($t, 'None') === 0 || stripos($t, 'Guild Posting:') === 0) {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $cleaned[] = $t;
    }
    return $cleaned ? implode(', ', $cleaned) : 'None';
}

/**
 * Builds the full lore-grounded Gemini prompt (without the language directive and JSON
 * schema, which bounty_prompt_tail() adds) for one bounty.
 *
 * @param string     $setting_id key of a setting card
 * @param array|null $giver      from bounty_pick_giver(), or null when Hex gives the job
 * @param array      $job        hunter_name, level, class, completed_line, goal, objective,
 *                               difficulty, is_location_goal, quest_giver, hex_twist, recent_titles,
 *                               floor (field floor ID for EXPLORATION/PATROL/SPEEDRUN_FLOOR),
 *                               target (the raw goal_target, e.g. a technique name), and optionally
 *                               extra_lines (more job lines) and location_override (replaces the
 *                               where-to-go line)
 * @return string
 */
function bounty_build_prompt($setting_id, $giver, array $job)
{
    $lore = bounty_lore();
    $setting_name = $lore['settings'][$setting_id]['name'] ?? $setting_id;
    $epForFloor = bounty_episode_from_setting_id($lore, $setting_id);
    $floorPhrase = !empty($job['floor']) ? bounty_floor_phrase($job['floor'], $epForFloor) : '';
    $clean_recent = bounty_clean_recent_titles($job['recent_titles'] ?? 'None');
    if (!empty($job['location_override'])) {
        $location_instruction = $job['location_override'];
    } elseif (!empty($job['is_location_goal'])) {
        $location_instruction = "The job takes place in {$setting_name}, and the story must give the hunter a clear reason to go there for this objective.";
    } else {
        $location_instruction = "This objective has no fixed place: set the job aboard Pioneer 2 (the setting's home is {$setting_name}) or wherever this client would be at this moment, and do not send the hunter to any particular area of Ragol.";
    }

    $prompt = "You are the Hunter's Guild aboard Pioneer 2, writing one new job for the bounty board of a Phantasy Star Online: Blue Burst server. "
        . "The hunter \"{$job['hunter_name']}\" (Level {$job['level']} {$job['class']}) needs a new job. {$job['completed_line']}\n\n";
    $prompt .= bounty_lore_prompt($setting_id, $giver);
    $prompt .= "
THE JOB (the server has fixed the mechanics; do not change them):
- Objective category: {$job['goal']}
- Objective: {$job['objective']}" . (bounty_goal_meaning($job['goal'], $job['target'] ?? null) !== '' ? "\n- What it asks of the hunter: " . bounty_goal_meaning($job['goal'], $job['target'] ?? null) : '') . ($floorPhrase !== '' ? "\n- In the story, call the place " . $floorPhrase . "." : '') . "
- Difficulty: {$job['difficulty']} (the hunter is Level {$job['level']}). For a fight, let the danger match it; for an objective with no fight, let it set how much is at stake for the client." . (empty($job['extra_lines']) ? '' : "\n- " . implode("\n- ", (array) $job['extra_lines'])) . "
- {$location_instruction}
- The client is " . preg_replace_callback('/^(A|An|The) /', fn($m) => strtolower($m[0]), $job['quest_giver']) . ". Tell it from their side, in their voice.{$job['hex_twist']}
- Give the client a believable, specific reason to want exactly this objective done now (a client who never explains themselves can set terms instead). Weave the hunter's name in naturally once (do not always put it in the opening clause, and never open with \"Listen up\", \"Listen here\", \"Listen closely\", \"Listen to me\" or \"Attention\").
- The objective names its target for the hunter's records only. If the setting card says people do not yet know a creature's name or nature, the client must describe it only as people would at this moment (for example, \"whatever waits at the heart of the ruins\").
- Description: 2 to 4 sentences, under 600 characters, opening straight in the client's situation, complaint, observation or terms with varied sentence structure — grounded in concrete personal stakes (a debt, private wager, salvage dispute, lab/medical shortage, missing partner, or field rumour). Title: 2 to 6 words, specific, grounded in PSO's Hunter's Guild style (like 'Missing Partner', 'A Debt in the Caves', 'Samples from Below', 'Quiet Watch'), NEVER prefixed with 'Operation:', 'Protocol:', 'Project:', 'Directive:' or 'Contract:', and NEVER generic training/bureaucracy clichés like 'Testing ...', 'Combat Readiness ...', 'Readiness Check', '... Assessment', or '... Evaluation'.
- Recently posted titles on this server: [ {$clean_recent} ]. Do not repeat their words, sentence shapes, plots or tropes.
";
    return $prompt;
}

/**
 * Language directive shared by JSON-returning lore prompts.
 */
function bounty_lang_directive($user_lang)
{
    if ($user_lang === 'jp') {
        return "CRITICAL LANGUAGE DIRECTIVE: The player's client is set to Japanese. You MUST respond entirely in natural, fluent Japanese, including the title and description.\n";
    }
    if ($user_lang === 'ru') {
        return "CRITICAL LANGUAGE DIRECTIVE: The user's interface is set to Russian. You MUST respond entirely in natural, fluent Russian, including the title and description.\n";
    }
    return "";
}

/**
 * Resolves the where-to-go instruction and location phrase shared by admin/event builders.
 */
function bounty_resolve_location_instruction($setting_id, array $spec, &$setting_name)
{
    $lore = bounty_lore();
    $setting_name = ($lore && isset($lore['settings'][$setting_id]['name'])) ? $lore['settings'][$setting_id]['name'] : $setting_id;
    if (!empty($spec['location_override'])) {
        return $spec['location_override'];
    }
    if (!empty($spec['is_location_goal'])) {
        return "The operation takes place in {$setting_name}, and the story must give hunters a clear reason to go there for this objective.";
    }
    return "This objective has no fixed place: set it aboard Pioneer 2 (the setting's home is {$setting_name}) or wherever this client would be at this moment, and do not send hunters to any particular area of Ragol.";
}

/**
 * Builds a lore-grounded Gemini prompt for creating a reusable Daily Bounty Template
 * inside admin/mission_manager.php. Falls back cleanly if api/lore/ files are missing.
 *
 * @param string     $setting_id
 * @param array|null $giver
 * @param array      $spec ['goal', 'objective', 'difficulty', 'is_location_goal', 'floor', 'target', 'reward', 'recent_titles', 'location_override']
 * @param string     $user_lang
 * @return string
 */
function bounty_build_admin_mission_prompt($setting_id, $giver, array $spec, $user_lang = 'en')
{
    $lore = bounty_lore();
    $setting_name = $setting_id;
    $location_instruction = bounty_resolve_location_instruction($setting_id, $spec, $setting_name);
    $goal = (string) ($spec['goal'] ?? 'BOSS_ARENA');
    $objective = (string) ($spec['objective'] ?? '');
    $target = $spec['target'] ?? '';
    $difficulty = (string) ($spec['difficulty'] ?? 'Standard');
    $reward = trim((string) ($spec['reward'] ?? ''));
    $recent = bounty_clean_recent_titles($spec['recent_titles'] ?? 'None');
    $meaning = bounty_goal_meaning($goal, $target);
    $epForFloor = bounty_episode_from_setting_id($lore, $setting_id);
    $floorPhrase = !empty($spec['floor']) ? bounty_floor_phrase($spec['floor'], $epForFloor) : '';
    $clientName = $giver['name'] ?? "a Hunter's Guild client";

    if ($lore && isset($lore['settings'][$setting_id])) {
        $prompt = "You are the Hunter's Guild aboard Pioneer 2, drafting one reusable Hunter's Guild bounty board posting for a Phantasy Star Online: Blue Burst server.\n\n";
        $prompt .= bounty_lore_prompt($setting_id, $giver);
    } else {
        $prompt = "You are the Hunter's Guild aboard Pioneer 2, drafting a lore-accurate bounty board posting for Phantasy Star Online: Blue Burst.\n\n";
    }

    $prompt .= "\nTHE POSTING (mechanics are fixed; do not alter the objective):\n"
        . "- Objective category: {$goal}\n"
        . "- Objective: {$objective}\n"
        . ($meaning !== '' ? "- What it asks of a hunter: {$meaning}\n" : '')
        . ($floorPhrase !== '' ? "- In the story, call the place {$floorPhrase}.\n" : '')
        . "- Target tier / tone: {$difficulty}.\n"
        . ($reward !== '' ? "- Guild payout noted on the slip: {$reward}.\n" : '')
        . "- {$location_instruction}\n"
        . "- Client perspective: Write from {$clientName}'s side, in their voice. Since this is a reusable board template, do NOT invent or name a specific hunter — address whoever accepts the contract naturally as \"you\" or \"a hunter\".\n"
        . "- Give {$clientName} a specific, believable reason in this setting to post this exact objective now.\n"
        . "- If the setting card says people do not yet know a creature's true name or nature, describe it only as people would at this moment.\n"
        . "- Title: 2 to 6 words, specific and grounded in PSO's Hunter's Guild style (never prefixed with 'Operation:', 'Protocol:', 'Project:', 'Directive:' or 'Contract:'). Description: 2 to 4 sentences, under 600 characters, opening straight in the client's situation without 'Listen up/here/closely' hooks.\n"
        . "- Existing bounty titles to avoid repeating: [ {$recent} ].\n\n";

    $prompt .= bounty_lang_directive($user_lang);
    $prompt .= "CRITICAL RULE: Return ONLY valid JSON properly formatted with double quotes matching this schema:\n"
        . "{\n"
        . "  \"title\": \"A specific bounty title\",\n"
        . "  \"description\": \"The reusable Hunter's Guild posting written in the client's voice\"\n"
        . "}";
    return $prompt;
}

/**
 * Builds a lore-grounded Gemini prompt for authoring a fleet-wide Global Community Event
 * inside admin/mission_manager.php. Falls back cleanly if api/lore/ files are missing.
 *
 * @param string     $setting_id
 * @param array|null $giver
 * @param array      $spec ['goal', 'objective', 'target_amount', 'target', 'reward', 'recent_titles', 'is_location_goal', 'floor', 'location_override']
 * @param string     $user_lang
 * @return string
 */
function bounty_build_community_event_prompt($setting_id, $giver, array $spec, $user_lang = 'en')
{
    $lore = bounty_lore();
    $setting_name = $setting_id;
    $location_instruction = bounty_resolve_location_instruction($setting_id, $spec, $setting_name);
    $goal = (string) ($spec['goal'] ?? 'BOSS_ARENA');
    $objective = (string) ($spec['objective'] ?? '');
    $target = $spec['target'] ?? '';
    $amount = (int) ($spec['target_amount'] ?? 0);
    $reward = trim((string) ($spec['reward'] ?? ''));
    $recent = trim((string) ($spec['recent_titles'] ?? 'None'));
    $meaning = bounty_goal_meaning($goal, $target);
    $epForFloor = bounty_episode_from_setting_id($lore, $setting_id);
    $floorPhrase = !empty($spec['floor']) ? bounty_floor_phrase($spec['floor'], $epForFloor) : '';
    $clientName = $giver['name'] ?? "the Hunter's Guild command desk";

    if ($lore && isset($lore['settings'][$setting_id])) {
        $prompt = "You are Pioneer 2 Fleet Command and the Hunter's Guild, issuing a major fleet-wide cooperative Global Community Event for a Phantasy Star Online: Blue Burst server.\n\n";
        $prompt .= bounty_lore_prompt($setting_id, $giver);
    } else {
        $prompt = "You are the Hunter's Guild aboard Pioneer 2, issuing a major cooperative Global Community Event for Phantasy Star Online: Blue Burst.\n\n";
    }

    $scaleLine = $amount > 0
        ? ($goal === 'PLAYTIME'
            ? number_format($amount) . " collective hours across all participating hunters"
            : number_format($amount) . " collective points/units across all participating hunters")
        : "Fleet-wide cooperative server milestone";

    $prompt .= "\nTHE OPERATION (mechanics are fixed; do not change them):\n"
        . "- Event goal category: {$goal}\n"
        . "- Shared objective: {$objective}\n"
        . ($meaning !== '' ? "- How hunters contribute: {$meaning}\n" : '')
        . "- Collective fleet target: {$scaleLine}\n"
        . ($floorPhrase !== '' ? "- In the story, call the place {$floorPhrase}.\n" : '')
        . ($reward !== '' ? "- Completion reward for contributors: {$reward}.\n" : '')
        . "- {$location_instruction}\n"
        . "- Speaker & framing: Present this as a major cooperative mobilization led by {$clientName}, speaking in their voice to all hunters across Pioneer 2.\n"
        . "- Give an urgent, canon-faithful reason why scores of hunters must coordinate together right now. Obey all canon constraints and hidden-truth spoiler guards.\n"
        . "- Title: 2 to 6 words (evocative fleet-wide event title — vary the structure rather than defaulting to 'Operation:', and avoid clichés like 'Whispers of...' or 'Echoes of...'). Description: 2 to 4 sentences, under 600 characters, explaining both the crisis and how hunters rally together.\n"
        . "- Previous community event titles to avoid repeating: [ {$recent} ].\n\n";

    $prompt .= bounty_lang_directive($user_lang);
    $prompt .= "CRITICAL RULE: Return ONLY valid JSON properly formatted with double quotes matching this schema:\n"
        . "{\n"
        . "  \"title\": \"An evocative event title\",\n"
        . "  \"description\": \"The lore-grounded Guild briefing calling all hunters to cooperate\"\n"
        . "}";
    return $prompt;
}

/**
 * Builds the lore-grounded bilingual (EN + JP) broadcast prompt for cron_community.php milestones.
 * Uses seeded setting/speaker selection on $ce['id'] so all 5 broadcasts (0%, 20%, 50%, 80%, 100%)
 * speak consistently in the same character's voice.
 *
 * @return array ['prompt' => string, 'speaker' => string]
 */
function bounty_build_community_broadcast_prompt(array $ce, $milestone_type, $pct_str, $srv_addr)
{
    $eventId = (int) ($ce['id'] ?? 0);
    $title = trim((string) ($ce['title'] ?? 'Global Operation'));
    $desc = trim((string) ($ce['description'] ?? ''));
    $goalType = trim((string) ($ce['goal_type'] ?? 'BOSS_ARENA'));
    $goalTarget = trim((string) ($ce['goal_target'] ?? ''));
    $lore = bounty_lore();

    $resolved = bounty_lore_setting_for_objective($goalType, $goalTarget, $title, $desc, $eventId);
    $sid = $resolved['setting_id'];
    $giver = ($lore && isset($lore['settings'][$sid])) ? bounty_pick_giver_seeded($sid, $eventId) : null;
    $speaker = $giver['name'] ?? ($lore ? 'Hunters Guild' : 'Mission Control');

    if ($milestone_type === 'start') {
        $milestoneLine = "The Global Community Event has just launched (0%)! Broadcast a dramatic rallying call ordering all available hunters to deploy and contribute.";
    } elseif ($milestone_type === '100') {
        $milestoneLine = "Hunters have reached 100% and completed the operation! Broadcast the victory and tell every participant to claim their reward at {$srv_addr}.";
    } else {
        $milestoneLine = "Hunters just passed {$pct_str} of the shared operation goal! Broadcast a crisp, motivating tactical status update in character.";
    }

    if ($lore && isset($lore['settings'][$sid])) {
        $world = $lore['world'];
        $s = $lore['settings'][$sid];
        $ep = (int) ($s['episode'] ?? $resolved['episode']);
        $prompt = "You are {$speaker}, broadcasting an urgent Pioneer 2 Hunter's Guild bulletin across an active Phantasy Star Online: Blue Burst server.\n\n";
        $prompt .= "WRITING RULES:\n" . bounty_lore_lines($world['rules'] ?? [], 12) . "\n\n";
        $prompt .= "THE SETTING: " . ($s['name'] ?? $sid) . " (Episode {$ep}). Moment: " . ($s['era'] ?? '') . "\n"
            . trim((string) ($s['summary'] ?? '')) . "\n";
        if (!empty($s['hidden_truths'])) {
            $prompt .= "Hidden truths (NEVER state or hint at these):\n" . bounty_lore_lines($s['hidden_truths'], 8) . "\n";
        }
        if (!empty($s['avoid'])) {
            $prompt .= "Avoid:\n" . bounty_lore_lines($s['avoid'], 8) . "\n";
        }
        if ($giver) {
            $prompt .= "\nYOUR VOICE ({$speaker}): " . ($giver['role'] ?? '') . "\n";
            if (!empty($giver['voice'])) $prompt .= "Tone & diction: " . $giver['voice'] . "\n";
            if (!empty($giver['never'])) $prompt .= "You must never:\n" . bounty_lore_lines($giver['never'], 4) . "\n";
        }
        if (!empty($resolved['location_override'])) {
            $prompt .= "\nScope note: " . $resolved['location_override'] . "\n";
        }
    } else {
        $prompt = "You are '{$speaker}', broadcasting an official PSOBB server bulletin.\n";
    }

    $prompt .= "\nACTIVE COMMUNITY EVENT:\n"
        . "- Title: {$title}\n"
        . "- Goal type: {$goalType}" . ($goalTarget !== '' ? " (Target: {$goalTarget})" : '') . "\n"
        . ($desc !== '' ? "- Briefing: {$desc}\n" : '')
        . "\nMILESTONE DIRECTIVE:\n- {$milestoneLine}\n\n"
        . "CRITICAL FORMAT & LENGTH DIRECTIVES:\n"
        . "- This is a global in-game Simple Mail broadcast: write plain text ONLY (no markdown asterisks, no bullet points, no JSON, and do NOT start with '[{$speaker}]' — the server prepends that automatically).\n"
        . "- Stay strictly in character, keep spoilers locked out, and keep the ENTIRE message compact (around 150-300 characters total).\n"
        . "- BILINGUAL OUTPUT REQUIRED: Write 1 crisp English sentence (under 25 words), followed by ' / ', followed by its natural Japanese translation (1 crisp sentence).";

    return [
        'prompt' => $prompt,
        'speaker' => $speaker,
    ];
}

/**
 * The end of every bounty prompt (lore-grounded or generic): the language directive and the
 * JSON schema Gemini must return.
 */
function bounty_prompt_tail($user_lang, $goal, $target_id)
{
    $tail = bounty_lang_directive($user_lang);
    $tail .= "
CRITICAL RULE: Return ONLY valid JSON properly formatted with double quotes strictly matching this exact schema block, do not include any other markdown:
{
  \"title\": \"A specific title\",
  \"description\": \"The job, told by the client, naming the hunter once\",
  \"goal_type\": \"{$goal}\",
  \"goal_target\": \"{$target_id}\"
}";
    return $tail;
}
