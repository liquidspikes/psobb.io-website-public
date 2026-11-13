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
 * The Episode I setting for an Episode I field floor (Forest 1 ... Ruins 3).
 */
function bounty_setting_for_ep1_floor($floor)
{
    $floor = (int) $floor;
    if ($floor >= 1 && $floor <= 2) return 'ep1_forest';
    if ($floor >= 3 && $floor <= 5) return 'ep1_caves';
    if ($floor >= 6 && $floor <= 7) return 'ep1_mines';
    if ($floor >= 8 && $floor <= 10) return 'ep1_ruins';
    return 'ep1_pioneer2';
}

/**
 * A setting for an objective with no place of its own (Meseta, levels, materials...):
 * one of the episodes' hubs (Pioneer 2 in 3084-85, the Lab in 3085, Pioneer 2 in 3086).
 */
function bounty_pick_hub_setting()
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
    $ep = array_rand($available);
    return [$ep, $available[$ep]];
}

/**
 * Picks a quest giver from the setting's era-appropriate cast.
 *
 * @return array|null ['id'=>ns_id, ...card] or null if the setting has no cast.
 */
function bounty_pick_giver($setting_id)
{
    $lore = bounty_lore();
    $setting = $lore['settings'][$setting_id] ?? null;
    if (!$setting || empty($setting['cast'])) {
        return null;
    }
    $id = $setting['cast'][array_rand($setting['cast'])];
    return ['id' => $id] + $lore['cast'][$id];
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
        'PATROL' => 'Stay in the named area and keep watch there for a while.',
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
        'MAT_HP' => 'Keep using HP Materials, items that permanently strengthen the body, until the hunter\'s career total reaches that number.',
        'MAT_TP' => 'Keep using TP Materials, items that permanently strengthen technique power, until the hunter\'s career total reaches that number.',
        'MAT_POWER' => 'Keep using Power Materials, items that permanently raise attack strength, until the hunter\'s career total reaches that number.',
        'MAT_DEF' => 'Keep using Def Materials, items that permanently raise defence, until the hunter\'s career total reaches that number.',
        'MAT_MIND' => 'Keep using Mind Materials, items that permanently raise mental strength, until the hunter\'s career total reaches that number.',
        'MAT_EVADE' => 'Keep using Evade Materials, items that permanently sharpen reflexes, until the hunter\'s career total reaches that number.',
        'MAT_LUCK' => 'Keep using Luck Materials, items that permanently improve luck, until the hunter\'s career total reaches that number.',
    ];
    return $m[$goal] ?? '';
}

/**
 * In-world wording for the Episode I field areas, so the story never needs menu labels
 * such as "Cave 2".
 */
function bounty_floor_phrase($floor)
{
    $p = [
        1 => 'the outer Forest', 2 => 'the inner Forest near the Central Dome',
        3 => 'the upper Caves', 4 => 'the middle Caves', 5 => 'the deepest Caves',
        6 => 'the upper Mines', 7 => 'the lower Mines',
        8 => 'the upper Ruins', 9 => 'the middle Ruins', 10 => 'the deepest Ruins',
    ];
    return $p[(int) $floor] ?? '';
}

/**
 * Builds the full lore-grounded Gemini prompt (without the language directive and JSON
 * schema, which bounty_prompt_tail() adds) for one bounty.
 *
 * @param string     $setting_id key of a setting card
 * @param array|null $giver      from bounty_pick_giver(), or null when Hex gives the job
 * @param array      $job        hunter_name, level, class, completed_line, goal, objective,
 *                               difficulty, is_location_goal, quest_giver, hex_twist, recent_titles,
 *                               floor (Ep1 field floor for EXPLORATION/PATROL/SPEEDRUN_FLOOR),
 *                               target (the raw goal_target, e.g. a technique name), and optionally
 *                               extra_lines (more job lines) and location_override (replaces the
 *                               where-to-go line)
 * @return string
 */
function bounty_build_prompt($setting_id, $giver, array $job)
{
    $lore = bounty_lore();
    $setting_name = $lore['settings'][$setting_id]['name'] ?? $setting_id;
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
- Objective: {$job['objective']}" . (bounty_goal_meaning($job['goal'], $job['target'] ?? null) !== '' ? "\n- What it asks of the hunter: " . bounty_goal_meaning($job['goal'], $job['target'] ?? null) : '') . (!empty($job['floor']) && bounty_floor_phrase($job['floor']) !== '' ? "\n- In the story, call the place " . bounty_floor_phrase($job['floor']) . "." : '') . "
- Difficulty: {$job['difficulty']} (the hunter is Level {$job['level']}). For a fight, let the danger match it; for an objective with no fight, let it set how much is at stake for the client." . (empty($job['extra_lines']) ? '' : "\n- " . implode("\n- ", (array) $job['extra_lines'])) . "
- {$location_instruction}
- The client is " . preg_replace_callback('/^(A|An|The) /', fn($m) => strtolower($m[0]), $job['quest_giver']) . ". Tell it from their side, in their voice.{$job['hex_twist']}
- Give the client a believable, specific reason to want exactly this objective done now (a client who never explains themselves can set terms instead). Mention the hunter by name once.
- The objective names its target for the hunter's records only. If the setting card says people do not yet know a creature's name or nature, the client must describe it only as people would at this moment (for example, \"whatever waits at the heart of the ruins\").
- Description: 2 to 4 sentences, under 600 characters. Title: 2 to 6 words, specific and fresh.
- Recently posted titles on this server: [ {$job['recent_titles']} ]. Do not repeat their titles, plots or tropes.
";
    return $prompt;
}

/**
 * The end of every bounty prompt (lore-grounded or generic): the language directive and the
 * JSON schema Gemini must return.
 */
function bounty_prompt_tail($user_lang, $goal, $target_id)
{
    $tail = "";
    if ($user_lang === 'jp') {
        $tail .= "CRITICAL LANGUAGE DIRECTIVE: The player's client is set to Japanese. You MUST respond entirely in natural, fluent Japanese, including the quest title and description.\n";
    }
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
