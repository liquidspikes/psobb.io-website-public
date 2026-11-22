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

    // --- 5. Community Events, Admin Templates & Broadcasts -------------------
    $objCases = [
        ['BOSS_ARENA', '11', 1, 'ep1_forest'],
        ['BOSS_ARENA', '12@1', 1, 'ep1_caves'],
        ['BOSS_ARENA', '12@2', 2, 'ep2_gal_da_val'],
        ['BOSS_ARENA', '13@1', 1, 'ep1_mines'],
        ['BOSS_ARENA', '13@2', 2, 'ep2_seabed'],
        ['BOSS_ARENA', '14@1', 1, 'ep1_ruins'],
        ['BOSS_ARENA', '14@2', 2, 'ep2_vr_temple'],
        ['BOSS_ARENA', '15', 2, 'ep2_vr_spaceship'],
        ['BOSS_ARENA', '9', 4, 'ep4_desert'],
        ['BOSS_ARENA', '16', 2, 'ep2_vr_spaceship'],
        ['BOSS_ARENA', '17', 2, 'ep2_vr_temple'],
        ['BOSS_ARENA', '18', 2, 'ep2_seabed'],
        ['BOSS_ARENA', '19', 4, 'ep4_desert'],
        ['BOSS_ARENA', 'EP1_BOSS_RUSH', 1, 'ep1_ruins'],
        ['BOSS_ARENA', 'EP2_BOSS_RUSH', 2, 'ep2_seabed'],
        ['BOSS_ARENA', 'DIGITAL_BLASPHEMY', 4, 'ep4_desert'],
        ['BOSS_ARENA', 'DRACONIC_DOMINION', 4, 'ep4_desert'],
        ['BOSS_ARENA', 'CATACLYSMIC_CORE', 4, 'ep4_desert'],
        ['BOSS_ARENA', 'ALL_BOSSES', 4, 'ep4_desert'],
        ['BOSS_ARENA', 'ANY_DRAGON', 2, 'ep2_vr_spaceship'],
        ['EXPLORATION', '5', 1, 'ep1_caves'],
        ['EXPLORATION', '5@1', 1, 'ep1_caves'],
        ['PATROL', '2@2', 2, 'ep2_vr_temple'],
        ['EXPLORATION', '4@2', 2, 'ep2_vr_spaceship'],
        ['PATROL', '7@2', 2, 'ep2_gal_da_val'],
        ['SPEEDRUN_FLOOR', '11@2_900', 2, 'ep2_seabed'],
        ['EXPLORATION', '3@4', 4, 'ep4_crater'],
        ['SPEEDRUN_FLOOR', '7@4_1200', 4, 'ep4_desert'],
        ['SPEEDRUN_FLOOR', '7_300', 1, 'ep1_mines'],
    ];
    foreach ($objCases as [$gt, $gta, $expEp, $expSid]) {
        $res = bounty_lore_setting_for_objective($gt, $gta, 'Operation Example', 'Lore-grounded description', 7);
        check($res['episode'] === $expEp && $res['setting_id'] === $expSid, "objective ($gt, $gta) -> ep$expEp / $expSid (got ep{$res['episode']} / {$res['setting_id']})");
    }

    check(
        bounty_floor_phrase(11, 2) !== '' && bounty_floor_phrase(7, 4) !== '' && bounty_floor_phrase(4, 1) !== '',
        'bounty_floor_phrase resolves in-world area names for Ep1, Ep2, and Ep4'
    );

    $hubA = bounty_lore_setting_for_objective('LEVEL_UP', 'ANY', 'Fleet Mobilisation', 'Train together', 42);
    $hubB = bounty_lore_setting_for_objective('LEVEL_UP', 'ANY', 'Fleet Mobilisation', 'Train together', 42);
    check($hubA['setting_id'] === $hubB['setting_id'] && !$hubA['is_location_goal'], 'seeded hub objective selection is deterministic');

    $ep2Hub = bounty_lore_setting_for_objective('MAT_CONSUME', 'ANY', 'Lab Conditioning', 'Pioneer 2 Laboratory research quota');
    check($ep2Hub['setting_id'] === 'ep2_lab', 'Lab context selects ep2_lab hub setting');

    // Episode-qualified 12@2 must resolve to Ep2 even with Ep1 distraction text
    $bt12 = parse_boss_target('12@2');
    check($bt12['floor'] === 12 && $bt12['episode'] === 2, 'cron_community parse_boss_target handles 12@2');
    check(get_boss_episode_by_context('Caves Anomaly', 'Silent patrol without boss name', $bt12['floor'], $bt12['episode']) === 2, 'explicit 12@2 keeps Episode 2 despite Caves keyword');

    // Seeded giver keeps consistent cast speaker across all milestones of one CE
    $g1 = bounty_pick_giver_seeded('ep2_seabed', 19);
    $g2 = bounty_pick_giver_seeded('ep2_seabed', 19);
    check($g1 !== null && $g1['id'] === $g2['id'], 'bounty_pick_giver_seeded is deterministic for one CE id');

    // Admin Daily Bounty Template prompt
    $adminPrompt = bounty_build_admin_mission_prompt('ep2_gal_da_val', $g1, [
        'goal' => 'BOSS_ARENA',
        'objective' => '[Ep 2] Defeat Gal Gryphon',
        'difficulty' => 'Standard',
        'is_location_goal' => true,
        'floor' => null,
        'target' => '12@2',
        'reward' => '5 Photon Drop',
        'recent_titles' => 'Storm Over CCA',
        'location_override' => null,
    ], 'en');
    check(strpos($adminPrompt, 'Gal Da Val') !== false && strpos($adminPrompt, '"description"') !== false && strpos($adminPrompt, 'do NOT invent or name a specific hunter') !== false, 'admin mission prompt is lore-grounded and reusable');

    // Admin Community Event prompt
    $ceRes = bounty_lore_setting_for_objective('BOSS_ARENA', 'DIGITAL_BLASPHEMY', '', '', 9);
    $ceGiver = bounty_pick_giver_seeded($ceRes['setting_id'], 9);
    $cePrompt = bounty_build_community_event_prompt($ceRes['setting_id'], $ceGiver, [
        'goal' => 'BOSS_ARENA',
        'objective' => 'Defeat Vol Opt, Gol Dragon, or Shambertin',
        'target_amount' => 75000,
        'target' => 'DIGITAL_BLASPHEMY',
        'reward' => '3x Random Rare Drops',
        'recent_titles' => 'Ancient Cataclysm',
        'is_location_goal' => $ceRes['is_location_goal'],
        'floor' => $ceRes['floor'],
        'location_override' => $ceRes['location_override'],
    ], 'en');
    check(strpos($cePrompt, '75,000') !== false && strpos($cePrompt, 'Subterranean Desert') !== false && strpos($cePrompt, 'Hidden truths') !== false, 'community event prompt grounds setting, scale, and spoiler guard');

    // Community milestone broadcasts (0%, 50%, 100%)
    $sampleCE = [
        'id' => 14,
        'title' => 'Operation: Digital Blasphemy',
        'description' => 'Corrupted cybernetic mainframes are threatening all three sectors.',
        'goal_type' => 'BOSS_ARENA',
        'goal_target' => 'DIGITAL_BLASPHEMY',
    ];
    $bcStart = bounty_build_community_broadcast_prompt($sampleCE, 'start', '0%', 'psobb.io');
    $bcMid   = bounty_build_community_broadcast_prompt($sampleCE, '50', '50%', 'psobb.io');
    $bcEnd   = bounty_build_community_broadcast_prompt($sampleCE, '100', '100%', 'psobb.io');
    check($bcStart['speaker'] === $bcMid['speaker'] && $bcMid['speaker'] === $bcEnd['speaker'], 'all milestones for CE #14 share the same cast speaker (' . $bcStart['speaker'] . ')');
    check($bcStart['speaker'] !== 'Mission Control' && strpos($bcStart['prompt'], 'BILINGUAL OUTPUT REQUIRED') !== false, 'broadcast prompt uses lore speaker and bilingual directive');
    check(strpos($bcEnd['prompt'], 'psobb.io') !== false && strpos($bcMid['prompt'], '50%') !== false, 'broadcast prompts include milestone percentage and server claim URL at 100%');

    // --- 6. Anti-repetition & PSO vocabulary guards -------------------------
    check(count($lore['world']['rules'] ?? []) <= 12, 'world.json rules fit within the 12-line broadcast cap');
    check(
        bounty_clean_recent_titles('Operation: Thick Hide, Protocol: Sand Stasis, Project: Echo, Directive: Zero, Contract: Iron, Thick Hide, Clear the Waterway')
            === 'Thick Hide, Sand Stasis, Echo, Zero, Iron, Clear the Waterway',
        'bounty_clean_recent_titles strips code-name prefixes and deduplicates'
    );
    check(bounty_clean_recent_titles('') === 'None' && bounty_clean_recent_titles('Operation: , None') === 'None', 'bounty_clean_recent_titles handles empty inputs');
    check(
        strpos(bounty_goal_meaning('PATROL'), 'ten minutes') !== false
            && bounty_goal_meaning('EPISODE_RUN') !== ''
            && bounty_goal_meaning('AREA_SWEEP') !== '',
        'bounty_goal_meaning gives PATROL an in-world duration and covers rework goals'
    );
    $antiRepPrompt = bounty_build_prompt('ep1_caves', $g1, [
        'hunter_name' => 'Ash',
        'level' => 55,
        'class' => 'HUmar',
        'completed_line' => '',
        'goal' => 'PATROL',
        'objective' => 'Cave 2',
        'difficulty' => 'Very Hard',
        'is_location_goal' => true,
        'quest_giver' => $g1['name'] ?? 'Guild',
        'hex_twist' => '',
        'recent_titles' => 'Operation: Thick Hide, Protocol: Sand Stasis, Quiet Watch',
        'floor' => 4,
        'target' => '4',
    ]);
    check(strpos($antiRepPrompt, 'Operation: Thick Hide') === false, 'bounty_build_prompt strips Operation: prefix from recent_titles');
    check(strpos($antiRepPrompt, '[ Thick Hide, Sand Stasis, Quiet Watch ]') !== false, 'bounty_build_prompt embeds cleaned recent_titles');
    check(
        strpos($antiRepPrompt, 'chronometer') !== false
            && strpos($antiRepPrompt, 'Listen up') !== false
            && strpos($antiRepPrompt, 'ten minutes') !== false,
        'bounty_build_prompt carries anti-jargon, anti-cold-open, and patrol duration rules'
    );
}

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
