<?php
/**
 * CLI: print the exact lore-grounded prompt the bounty cron would send to Gemini.
 *
 *   php api/preview_bounty_prompt.php --goal=BOSS_ARENA --level=45 --class=RAcast --name=Kira
 *   php api/preview_bounty_prompt.php --goal=MESETA --setting=ep2_lab
 *   php api/preview_bounty_prompt.php --goal=EXPLORATION --floor=7
 *
 * Options: --goal (any goal type; default BOSS_ARENA), --level (default 30), --class
 * (default HUmar), --name (default Hunter), --setting (force a setting card), --floor (for
 * EXPLORATION/PATROL/SPEEDRUN_FLOOR), --giver (cast id like ep1:bernie), --lang (en|jp),
 * --seed (repeatable picks). Paste the output into Gemini to see what a bounty looks like.
 * Needs no game server, database or API key.
 */
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/bounty_lore.php';

$o = getopt('', ['mode::', 'goal::', 'target::', 'level::', 'class::', 'name::', 'setting::', 'floor::', 'giver::', 'lang::', 'milestone::', 'seed::']);
if (isset($o['seed'])) {
    mt_srand((int) $o['seed']);
    srand((int) $o['seed']);
}
$mode = strtolower($o['mode'] ?? 'bounty');
$goal = strtoupper($o['goal'] ?? 'BOSS_ARENA');
$level = (int) ($o['level'] ?? 30);
$class = $o['class'] ?? 'HUmar';
$name = $o['name'] ?? 'Hunter';
$lang = $o['lang'] ?? 'en';

$lore = bounty_lore();
if (!$lore) {
    die("Lore files missing or invalid (api/lore/).\n");
}

$difficulty = 'Normal';
if ($level >= 80) $difficulty = 'Ultimate';
elseif ($level >= 40) $difficulty = 'Very Hard';
elseif ($level >= 20) $difficulty = 'Hard';

$floors = [1 => 'Forest 1', 2 => 'Forest 2', 3 => 'Cave 1', 4 => 'Cave 2', 5 => 'Cave 3', 6 => 'Mine 1', 7 => 'Mine 2', 8 => 'Ruins 1', 9 => 'Ruins 2', 10 => 'Ruins 3'];
$boss_goals = ['BOSS_ARENA', 'MENTOR_BOSS', 'HARDCORE_MENTOR', 'DIVERSE_PARTY_BOSS', 'SPEEDRUN_BOSS'];
$floor_goals = ['EXPLORATION', 'PATROL', 'SPEEDRUN_FLOOR'];
$setting_id = $o['setting'] ?? null;

if (in_array($goal, $boss_goals)) {
    $boss = bounty_pick_boss($level, -1);
    if ($setting_id) {
        foreach (bounty_boss_table() as $b) {
            if ($b[4] === $setting_id) $boss = $b;
        }
    }
    list($bf, $be, $bn, , $bs) = $boss;
    $setting_id = $bs;
    if ($goal === 'SPEEDRUN_BOSS') {
        $tl = mt_rand(600, 1200);
        $target_id = make_boss_target($bf, $be, $tl);
        $friendly = "$bn in under " . floor($tl / 60) . " minutes and " . ($tl % 60) . " seconds";
    } else {
        $target_id = make_boss_target($bf, $be);
        $friendly = $bn;
    }
} elseif (in_array($goal, $floor_goals)) {
    $floor = isset($o['floor']) ? (int) $o['floor'] : array_rand($floors);
    $setting_id = bounty_setting_for_ep1_floor($floor);
    $target_id = (string) $floor;
    $friendly = $floors[$floor] ?? "Floor $floor";
    if ($goal === 'SPEEDRUN_FLOOR') {
        $tl = mt_rand(600, 1800);
        $target_id = $floor . '_' . $tl;
        $friendly .= " in under " . floor($tl / 60) . " minutes and " . ($tl % 60) . " seconds";
    }
} else {
    if (!$setting_id) {
        list(, $setting_id) = bounty_pick_hub_setting();
    }
    $examples = [
        'MESETA' => ['350000', '350,000 Meseta'],
        'LEVEL' => [(string) ($level + 2), 'Level ' . ($level + 2)],
        'PLAYTIME' => ['180000', '50 total hours of playtime'],
        'TECHNIQUE' => ['Zalure', 'the Zalure technique'],
        'ITEM' => ['000203', 'Claymore'],
        'BATTLE_WINS' => ['4', '4 total Battle Mode wins'],
        'CHALLENGE_STAGES' => ['6', '6 total Challenge Stages cleared'],
        'MAT_LUCK' => ['12', '12 total consumed LUCK materials'],
    ];
    list($target_id, $friendly) = $examples[$goal] ?? ['10', '10 total consumed HP materials'];
}

if (!isset($lore['settings'][$setting_id])) {
    die("Unknown setting '$setting_id'. Settings: " . implode(', ', array_keys($lore['settings'])) . "\n");
}

$giver = null;
if (!empty($o['giver']) && isset($lore['cast'][$o['giver']])) {
    $giver = ['id' => $o['giver']] + $lore['cast'][$o['giver']];
} else {
    $giver = bounty_pick_giver($setting_id);
}

if ($mode === 'broadcast') {
    $ms = (string) ($o['milestone'] ?? 'start');
    $pctMap = ['start' => '0%', '20' => '20%', '50' => '50%', '80' => '80%', '100' => '100%'];
    $ceTarget = $o['target'] ?? $target_id;
    $ceFriendly = isset($o['target']) ? html_entity_decode(strip_tags(getClearObjective($goal, $ceTarget)), ENT_QUOTES, 'UTF-8') : $friendly;
    $bc = bounty_build_community_broadcast_prompt([
        'id' => (int) ($o['seed'] ?? 1),
        'title' => 'Sample Global Operation',
        'description' => "Collective objective: $ceFriendly",
        'goal_type' => $goal,
        'goal_target' => $ceTarget,
    ], $ms, $pctMap[$ms] ?? "{$ms}%", 'psobb.io');
    fwrite(STDERR, "mode: broadcast ($ms) | speaker: {$bc['speaker']} | goal: $goal | target: $ceTarget | " . strlen($bc['prompt']) . " bytes\n");
    echo $bc['prompt'], "\n";
} elseif ($mode === 'community-event') {
    $ceTarget = $o['target'] ?? $target_id;
    $ceFriendly = isset($o['target']) ? html_entity_decode(strip_tags(getClearObjective($goal, $ceTarget)), ENT_QUOTES, 'UTF-8') : $friendly;
    $res = bounty_lore_setting_for_objective($goal, $ceTarget);
    $ceSid = $o['setting'] ?? $res['setting_id'];
    $ceGiver = (!empty($o['giver']) && isset($lore['cast'][$o['giver']]))
        ? ['id' => $o['giver']] + $lore['cast'][$o['giver']]
        : bounty_pick_giver($ceSid);
    $prompt = bounty_build_community_event_prompt($ceSid, $ceGiver, [
        'goal' => $goal,
        'objective' => $ceFriendly,
        'target_amount' => 75000,
        'target' => $ceTarget,
        'reward' => '3x Random Rare Drops',
        'recent_titles' => 'None',
        'is_location_goal' => $res['is_location_goal'],
        'floor' => $res['floor'],
        'location_override' => $res['location_override'],
    ], $lang);
    fwrite(STDERR, "mode: community-event | setting: $ceSid | client: " . ($ceGiver['id'] ?? '-') . " | goal: $goal | target: $ceTarget | " . strlen($prompt) . " bytes\n");
    echo $prompt, "\n";
} elseif ($mode === 'admin-mission') {
    $prompt = bounty_build_admin_mission_prompt($setting_id, $giver, [
        'goal' => $goal,
        'objective' => $friendly,
        'difficulty' => $difficulty,
        'is_location_goal' => in_array($goal, array_merge($boss_goals, $floor_goals)),
        'floor' => in_array($goal, $floor_goals) ? (int) $target_id : null,
        'target' => $target_id,
        'reward' => '5 Photon Drop',
        'recent_titles' => 'None',
    ], $lang);
    fwrite(STDERR, "mode: admin-mission | setting: $setting_id | client: " . ($giver['id'] ?? '-') . " | goal: $goal | target: $target_id | " . strlen($prompt) . " bytes\n");
    echo $prompt, "\n";
} else {
    $prompt = bounty_build_prompt($setting_id, $giver, [
        'hunter_name' => $name,
        'level' => $level,
        'class' => $class,
        'completed_line' => 'They are eager for their next assignment.',
        'goal' => $goal,
        'objective' => $friendly,
        'difficulty' => $difficulty,
        'is_location_goal' => in_array($goal, array_merge($boss_goals, $floor_goals)),
        'quest_giver' => $giver['name'] ?? "the Hunter's Guild",
        'hex_twist' => '',
        'recent_titles' => 'None',
        'floor' => in_array($goal, $floor_goals) ? (int) $target_id : null,
        'target' => $target_id,
    ]) . bounty_prompt_tail($lang, $goal, $target_id);

    fwrite(STDERR, "setting: $setting_id | client: " . ($giver['id'] ?? '-') . " | goal: $goal | target: $target_id | " . strlen($prompt) . " bytes\n");
    echo $prompt, "\n";
}
