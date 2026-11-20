<?php
/**
 * CLI self-test for the lore-grounded bounty generator.
 *
 *   php api/test_bounty_lore.php            run the checks
 *   php api/test_bounty_lore.php --prompt   also print one full sample prompt per setting
 *
 * Checks: boss target parsing, episode resolution (explicit and legacy keywords),
 * objective rendering, and that the lore files in api/lore/ load, cover every setting the
 * generator can choose, and build a prompt for each with a valid quest giver.
 * Needs no game server, database or Gemini key.
 */
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/bounty_lore.php';
// functions.php pulls in the shared helpers; it needs nothing from the environment to load.
require_once __DIR__ . '/functions.php';

$fails = 0;
$passes = 0;
function check($cond, $label)
{
    global $fails, $passes;
    if ($cond) {
        $passes++;
    } else {
        $fails++;
        echo "FAIL: $label\n";
    }
}

// --- 1. Boss targets ---------------------------------------------------------
check(parse_boss_target('13') === ['floor' => 13, 'episode' => null, 'time_limit' => null], "parse '13'");
check(parse_boss_target('13@2') === ['floor' => 13, 'episode' => 2, 'time_limit' => null], "parse '13@2'");
check(parse_boss_target('14@1_600') === ['floor' => 14, 'episode' => 1, 'time_limit' => 600], "parse '14@1_600'");
check(parse_boss_target('11_180') === ['floor' => 11, 'episode' => null, 'time_limit' => 180], "parse legacy speedrun '11_180'");
check(parse_boss_target('ANY_DRAGON')['floor'] === 0, "parse 'ANY_DRAGON' has no floor");
check(make_boss_target(12, 2) === '12@2' && make_boss_target(9, 4, 900) === '9@4_900', 'make_boss_target');
check((int) '13@2' === 13, "legacy (int) cast of '13@2' still gives the floor");
list($sr_floor, $sr_time) = explode('_', '14@2_600');
check((int) $sr_floor === 14 && (int) $sr_time === 600, "legacy explode('_') of a speedrun target still works");

// --- 2. Episode resolution ---------------------------------------------------
check(get_boss_episode_by_context('Anything', 'mentions Barba Ray', 14, 1) === 1, 'explicit episode wins over keywords');
check(get_boss_episode_by_context('Silent Guardians', 'A tale of betrayal in the grey depths of the ruins.', 14) === 1, "'betrayal' no longer reads as Barba Ray");
check(get_boss_episode_by_context('Raft of Sorrow', 'Barba Ray waits in the VR Temple.', 14) === 2, 'Barba Ray still detected');
check(get_boss_episode_by_context('Illegal cargo', 'Smugglers in the regal caves.', 12) === 1, "'illegal'/'regal' no longer read as Gal Da Val");
check(get_boss_episode_by_context('Wings over Gal Da Val', 'The Gal Gryphon nests above the CCA.', 12) === 2, 'Gal Gryphon still detected');
check(get_boss_episode_by_context('Overflow', 'Coolant overflow in the Mines.', 13) === 1, "'overflow' no longer reads as Olga Flow");
check(get_boss_episode_by_context('Deep water', 'Something stirs in the Seabed.', 13) === 2, 'Seabed still detected');
check(get_boss_episode_by_context('深海', 'オルガ・フロウを倒せ。海底へ。', 13) === 2, 'Japanese keyword still detected');
check(get_boss_episode_by_context('x', 'y', 15) === 2 && get_boss_episode_by_context('x', 'y', 9) === 4, 'fixed-episode floors');

// --- 3. Objective rendering --------------------------------------------------
check(strpos(getClearObjective('BOSS_ARENA', '13@2', 'Mines trouble', 'Vol Opt'), 'Olga Flow') !== false, "'13@2' renders as Olga Flow despite Ep1 words");
check(strpos(getClearObjective('BOSS_ARENA', '14', 'Betrayal', 'grey ruins'), 'Dark Falz') !== false, "legacy '14' with 'betrayal' renders as Dark Falz");
check(strpos(getClearObjective('SPEEDRUN_BOSS', '12@2_630', '', ''), 'Gal Gryphon') !== false, "speedrun '12@2_630' renders as Gal Gryphon");
check(strpos(getClearObjective('MENTOR_BOSS', '14@2', '', ''), 'Barba Ray') !== false, "mentor '14@2' renders as Barba Ray");
check(strpos(getClearObjective('BOSS_ARENA', '11', 'The silver dragon', ''), 'Sil Dragon') === false, "'silver' no longer reads as Sil Dragon");
check(strpos(getClearObjective('BOSS_ARENA', '11', 'Hunt the Sil Dragon', ''), 'Sil Dragon') !== false, 'Sil Dragon still detected');

// --- 4. Lore files -----------------------------------------------------------
$lore = bounty_lore();
check(is_array($lore), 'lore files load');
if (is_array($lore)) {
    $needed = ['ep1_pioneer2', 'ep2_lab', 'ep4_pioneer2'];
    foreach (bounty_boss_table() as $b) {
        $needed[] = $b[4];
    }
    foreach (range(1, 10) as $f) {
        $needed[] = bounty_setting_for_ep1_floor($f);
    }
    foreach (array_unique($needed) as $sid) {
        check(isset($lore['settings'][$sid]), "setting '$sid' exists");
    }
    foreach ($lore['settings'] as $sid => $s) {
        check(!empty($s['cast']), "setting '$sid' has at least one valid cast member");
        foreach (['name', 'era', 'summary', 'known_facts', 'hidden_truths', 'job_hooks'] as $field) {
            check(!empty($s[$field]), "setting '$sid' has '$field'");
        }
        foreach ($s['cast'] as $cid) {
            check(!empty($lore['cast'][$cid]['name']), "cast '$cid' (in $sid) has a name");
        }
        $giver = bounty_pick_giver($sid);
        $prompt = bounty_lore_prompt($sid, $giver);
        check(strlen($prompt) > 500 && strlen($prompt) < 20000, "prompt for '$sid' has a sane size (" . strlen($prompt) . " bytes)");
        $full = bounty_build_prompt($sid, $giver, ['hunter_name' => 'Tester', 'level' => 40, 'class' => 'HUmar', 'completed_line' => '', 'goal' => 'LEVEL', 'objective' => 'Level 41', 'difficulty' => 'Very Hard', 'is_location_goal' => false, 'quest_giver' => $giver['name'] ?? 'x', 'hex_twist' => '', 'recent_titles' => 'None'])
            . bounty_prompt_tail('en', 'LEVEL', '41');
        check(strpos($full, 'Tester') !== false && strpos($full, '"goal_target": "41"') !== false, "full prompt for '$sid' carries the hunter and the schema");
        if (in_array('--prompt', $argv ?? [], true)) {
            echo "\n================ $sid / " . ($giver['name'] ?? '?') . " ================\n$prompt\n";
        }
    }
    foreach ([1, 20, 35, 50, 200] as $lvl) {
        for ($i = 0; $i < 50; $i++) {
            $b = bounty_pick_boss($lvl, 12);
            check($b[3] <= $lvl && $b[0] !== 12, "boss pick at level $lvl respects level gate and current floor");
        }
    }
}

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
