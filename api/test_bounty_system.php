<?php
/**
 * CLI self-test for the Bounty System Rework (api/bounty_system.php).
 *
 *   php api/test_bounty_system.php
 *
 * Runs against a throwaway SQLite database in the system temp folder, answers Gemini prompts
 * with a stub, and points in-game mail at a closed port, so it needs no game server, Gemini key
 * or live data. Checks earning caps, the waiting queue, activation order, drops (slots, the
 * 10-held limit, tier rules for party/solo rooms), Battle/Challenge pools, weekly objectives and
 * their progress, expiry at the reset, the one free reroll, the reward roll on claim, and contracts
 * (drops, slots, objectives, runs, hunts, reward pools and party shares).
 */
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

$tmp_db = sys_get_temp_dir() . '/bounty_rework_test_' . getmypid() . '.db';
putenv("DB_PATH=$tmp_db");
require_once __DIR__ . '/config.php';
$_ENV['DB_PATH'] = $tmp_db;
$NEWSERV_API_URL = 'http://127.0.0.1:9'; // mail goes nowhere
$GEMINI_API_KEY = 'test'; // never sent: the stub below answers
$GLOBALS['BOUNTY_IGNORE_LOCAL_CONFIG'] = true;
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/bounty_system.php';
require_once __DIR__ . '/enemy_kills.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

$n_prompts = 0;
$GLOBALS['BOUNTY_GEMINI_STUB'] = function ($prompt) use (&$n_prompts) {
    $n_prompts++;
    return ['title' => "Test Job $n_prompts", 'description' => 'A stub story.'];
};

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

$db = get_db(true);
$db->exec("INSERT INTO users (username, email, account_id, language) VALUES ('t1', 't1@x', 9001, 'en')");

function client($name, $level = 60, array $extra = [])
{
    return $extra + [
        'Account' => ['AccountID' => 9001], 'Name' => $name, 'EXP' => 50000, 'Level' => $level,
        'CharClass' => 'HUmar', 'LocationFloor' => 0, 'LobbyID' => 1, 'Meseta' => 10000,
        'PlayTimeSeconds' => 36000, 'InventoryItems' => [], 'TechniqueLevels' => [],
        'BattlePlaceCounts' => [0, 0, 0, 0], 'ChallengeTimesEp1Online' => [], 'ChallengeTimesEp2Online' => [],
    ];
}
function no_kills()
{
    return ['enemy' => 0, 'rare' => 0, 'rare_name' => '', 'has_counters' => false, 'boss_events' => [], 'state' => []];
}
function tick($db, $client, $lobby = null, $kills = null, $completed = [])
{
    ob_start();
    bounty_tick_for_client($db, $client, [
        'account_id' => (int) ($client['Account']['AccountID'] ?? 9001), 'char_name' => $client['Name'], 'user_lang' => 'en', 'curr_f' => (int) $client['LocationFloor'],
        'lobby' => $lobby, 'lobby_map' => [], 'kills' => $kills ?? no_kills(), 'completed' => $completed,
        'active_types' => [], 'recent_titles' => 'None',
    ]);
    ob_end_clean();
}
function acc_rows($db, $acc, $where = '1')
{
    $s = $db->prepare("SELECT pm.*, m.tier, m.cadence, m.pool, m.source, m.goal_type, m.goal_target, m.reward_item_string, m.reward_spec, m.title
                       FROM player_missions pm JOIN missions m ON pm.mission_id = m.id
                       WHERE pm.account_id = :a AND $where ORDER BY pm.id");
    $s->bindValue(':a', (int) $acc, SQLITE3_INTEGER);
    $r = $s->execute();
    $out = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $out[] = $row;
    }
    return $out;
}
function rows($db, $char, $where = '1')
{
    $s = $db->prepare("SELECT pm.*, m.tier, m.cadence, m.pool, m.source, m.goal_type, m.goal_target, m.reward_item_string, m.reward_spec, m.title
                       FROM player_missions pm JOIN missions m ON pm.mission_id = m.id
                       WHERE pm.character_name = :c AND $where ORDER BY pm.id");
    $s->bindValue(':c', $char, SQLITE3_TEXT);
    $r = $s->execute();
    $out = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $out[] = $row;
    }
    return $out;
}
$defaults = bounty_default_config();
check($defaults['enabled'] === false, 'rework is dark-launched (enabled: false) by default');
check(($defaults['legacy_grace_days'] ?? 0) === 30, 'legacy in_progress grace window defaults to 30 days');
$loadedCfg = bounty_config();
check(is_bool(bounty_rework_enabled()), 'bounty_rework_enabled returns a boolean');
$chChance = (int) ($loadedCfg['earn']['challenge_chance'] ?? -1);
$nChChance = (int) ($loadedCfg['earn']['normal_challenge_chance'] ?? -1);
check($chChance >= 0 && $chChance <= 100, 'challenge_chance is within 0..100');
check($nChChance >= 0 && $nChChance <= 100, 'normal_challenge_chance is within 0..100');
check((int) ($loadedCfg['earn']['normal_battle_chance'] ?? 0) === 10, 'normal_battle_chance defaults to 10');
$modelCands = bounty_gemini_model_candidates('gemma-4-26b-a4b-it');
check($modelCands[0] === 'gemma-4-26b-a4b-it' && in_array('gemini-2.5-flash-lite', $modelCands, true) && in_array('gemini-2.5-flash', $modelCands, true), 'Gemma/Gemini fallback chain keeps gemma-4-26b-a4b-it first and includes 2.5-flash-lite and 2.5-flash');
$thoughtJson = json_encode(['candidates' => [['content' => ['parts' => [
    ['thought' => true, 'text' => 'internal reasoning notes'],
    ['text' => json_encode(['title' => 'Forest Patrol', 'description' => 'Clear Boomas from Forest 1.'])],
]]]]]);
$parsedThought = bounty_parse_gemini_response_json($thoughtJson);
check(($parsedThought['title'] ?? '') === 'Forest Patrol' && ($parsedThought['description'] ?? '') === 'Clear Boomas from Forest 1.', 'response parser skips thought parts and parses JSON output part');
$gemmaPayload = json_decode(bounty_build_gemini_payload('test', 'gemma-4-26b-a4b-it'), true);
check(($gemmaPayload['generationConfig']['thinkingConfig']['thinkingLevel'] ?? '') === 'MINIMAL', 'gemma-4 payload sets thinkingLevel to MINIMAL');
$flashPayload = json_decode(bounty_build_gemini_payload('test', 'gemini-2.5-flash'), true);
check(!isset($flashPayload['generationConfig']['thinkingConfig']), 'gemini-2.5 payload omits thinkingConfig');
$fallbackStory = bounty_fallback_story(['selected_target_friendly' => 'Defeat Dark Falz'], 'Irene', 'ep1_ruins', 1, 'Ultimate');
check(!empty($fallbackStory['title']) && !empty($fallbackStory['description']), 'fallback story generator returns title and description');
$quiet = ['enabled' => true, 'earn' => ['login_daily_chance' => 0, 'login_weekly_chance' => 0, 'battle_chance' => 0, 'challenge_chance' => 0, 'normal_battle_chance' => 0, 'normal_challenge_chance' => 0]];

// ---------------------------------------------------------------------------
// 1. Free earning: 3 + 3 active, caps of 5 dailies and 10 weeklies per reset, the rest waits
// ---------------------------------------------------------------------------
bounty_config(['enabled' => true, 'earn' => ['login_daily_chance' => 100, 'login_weekly_chance' => 100]]);
$a = client('Aster');
for ($i = 0; $i < 30; $i++) {
    tick($db, $a);
}
check(bounty_earned_count($db, 9001, 'Aster', 'standard', 'daily') === 5, 'daily free earns stop at 5 per day');
check(bounty_earned_count($db, 9001, 'Aster', 'standard', 'weekly') === 10, 'weekly free earns stop at 10 per week');
check(bounty_active_count($db, 9001, 'Aster', 'standard', 'daily') === 3, '3 daily bounties active');
check(bounty_active_count($db, 9001, 'Aster', 'standard', 'weekly') === 3, '3 weekly bounties active');
check(bounty_queued_count($db, 9001, 'Aster', 'standard', 'daily') === 2, '2 dailies wait in Up next');
check(bounty_queued_count($db, 9001, 'Aster', 'standard', 'weekly') === 7, '7 weeklies wait in Up next');
$tiers = array_unique(array_column(rows($db, 'Aster'), 'tier'));
check(!array_intersect($tiers, ['ultra', 'mythic']), 'free bounties are Common, Uncommon or Rare only');
foreach (rows($db, 'Aster', "pm.status = 'in_progress'") as $r) {
    check($r['goal_type'] !== 'PENDING' && $r['title'] !== '' && $r['activated_at'], "active bounty #{$r['id']} has an objective and story");
    if ($r['cadence'] === 'weekly') {
        list(, $diff) = bounty_parse_weekly_target($r['goal_target']);
        check($r['goal_type'] === 'LEVEL' || $diff !== 'Ultimate', "weekly #{$r['id']} ({$r['tier']}) does not need Ultimate");
    }
}
foreach (rows($db, 'Aster', "pm.status = 'queued'") as $r) {
    check($r['goal_type'] === 'PENDING' && $r['title'] === '', "waiting bounty #{$r['id']} has no objective yet");
}

// Completing a daily frees a slot: the oldest waiting daily starts next pass
bounty_config($quiet);
$active = rows($db, 'Aster', "pm.status = 'in_progress' AND m.cadence = 'daily'");
$queued_before = rows($db, 'Aster', "pm.status = 'queued' AND m.cadence = 'daily'");
$db->exec("UPDATE player_missions SET status = 'ready_to_redeem' WHERE id = " . (int) $active[0]['id']);
tick($db, $a);
$now_active = array_column(rows($db, 'Aster', "pm.status = 'in_progress' AND m.cadence = 'daily'"), 'id');
check(in_array($queued_before[0]['id'], $now_active, true) && count($now_active) === 3, 'oldest waiting daily fills the freed slot');
check(bounty_earned_count($db, 9001, 'Aster', 'standard', 'daily') === 5, 'completing at the cap earns nothing more');

// ---------------------------------------------------------------------------
// 2. Completion earns another (under the cap)
// ---------------------------------------------------------------------------
$b = client('Briar');
ob_start();
bounty_try_free_earn($db, 9001, 'Briar', $b, 'daily', 'login');
ob_end_clean();
tick($db, $b);
$r = rows($db, 'Briar', "pm.status = 'in_progress'")[0];
$db->exec("UPDATE player_missions SET status = 'ready_to_redeem' WHERE id = " . (int) $r['id']);
tick($db, $b, null, null, [['pool' => 'standard', 'cadence' => 'daily']]);
check(bounty_earned_count($db, 9001, 'Briar', 'standard', 'daily') === 2, 'finishing a daily earns another');
check(bounty_active_count($db, 9001, 'Briar', 'standard', 'daily') === 1, 'and it starts straight away');

// ---------------------------------------------------------------------------
// 3. Drops: rare-enemy kills in a party; 5 active, 10 held, then nothing
// ---------------------------------------------------------------------------
bounty_config(['drops' => ['rare_enemy_chance' => ['Ultimate' => 100], 'boss_chance' => ['Ultimate' => 100]], 'contracts' => ['drop_share' => 0]]);
$party = ['is_game' => true, 'episode' => 1, 'difficulty' => 'Ultimate', 'mode' => 'Normal', 'players' => 3];
$c = client('Cobalt', 150, ['LocationFloor' => 4, 'SectionID' => 'Redria']);
$k = no_kills();
$k['rare'] = 14;
$k['rare_name'] = 'HILDEBLUE';
tick($db, $c, $party, $k);
$drops = rows($db, 'Cobalt', "m.pool = 'drop'");
check(count($drops) === 10, 'drops stop at 10 held (got ' . count($drops) . ')');
check(!in_array('mythic', array_column($drops, 'tier'), true), 'no Mythic Rare in a party');
foreach ($drops as $d) {
    if (in_array($d['tier'], ['ultra', 'mythic'], true)) {
        check($d['cadence'] === 'weekly', 'Ultra/Mythic drops are weeklies');
    }
    $r = rows($db, 'Cobalt', 'pm.id = ' . (int) $d['id'])[0];
    $spec = json_decode((string) $r['reward_spec'], true);
    if ($r['status'] === 'queued') {
        check($spec === null && $r['reward_item_string'] === '', 'a waiting drop has no reward yet');
    } else {
        check(is_array($spec) && (int) ($spec['version'] ?? 0) === 2 && empty($spec['rolled']), 'an active drop has its reward, stats not rolled yet');
    }
    $meta = json_decode((string) $db->querySingle("SELECT meta FROM missions WHERE id = " . (int) $r['mission_id']), true);
    check(($meta['area'] ?? null) === 'ep1_caves' && (int) ($meta['episode'] ?? 0) === 1, 'a drop remembers the area it dropped in (Cave 2 = ep1_caves)');
}
for ($i = 0; $i < 8; $i++) {
    tick($db, $c, $party);
}
check(bounty_active_count($db, 9001, 'Cobalt', 'drop') === 5, '5 drops active');
check(bounty_queued_count($db, 9001, 'Cobalt', 'drop') === 5, '5 drops waiting');
tick($db, $c, $party, $k);
check(count(rows($db, 'Cobalt', "m.pool = 'drop'")) === 10, 'still 10 held after more kills');
check(bounty_active_count($db, 9001, 'Cobalt', 'standard', 'daily') === 0, 'drops do not use the 3 + 3 slots');

$battle = ['is_game' => true, 'episode' => 1, 'difficulty' => 'Ultimate', 'mode' => 'Battle', 'players' => 4];
$d = client('Dune', 150);
tick($db, $d, $battle, $k);
check(count(rows($db, 'Dune', "m.pool = 'drop'")) === 0, 'nothing drops in Battle Mode');
bounty_config(['drops' => $defaults['drops']]);

// Tier rules (points 8-10), by sampling
$count = function ($lobby, $n = 20000) {
    $t = array_fill_keys(BOUNTY_TIERS, 0);
    for ($i = 0; $i < $n; $i++) {
        $t[bounty_roll_drop_tier($lobby)]++;
    }
    return $t;
};
$solo = $count(['is_game' => true, 'difficulty' => 'Ultimate', 'mode' => 'Solo', 'players' => 1]);
$alone = $count(['is_game' => true, 'difficulty' => 'Ultimate', 'mode' => 'Normal', 'players' => 1]);
$duo = $count(['is_game' => true, 'difficulty' => 'Ultimate', 'mode' => 'Normal', 'players' => 2]);
check($solo['ultra'] === 0 && $alone['ultra'] === 0, 'no Ultra Rare when playing alone');
check($duo['mythic'] === 0 && $duo['ultra'] > 0, 'Ultra Rare but no Mythic Rare with 2+ players');
check($solo['mythic'] > 0 && $alone['mythic'] > $solo['mythic'] * 1.2, 'Mythic weight x1.5 alone in a Multi-mode room (solo ' . $solo['mythic'] . ', alone ' . $alone['mythic'] . ')');
check(bounty_roll_drop_tier(['is_game' => true, 'difficulty' => 'Ultimate', 'mode' => 'Challenge', 'players' => 1]) === null, 'no drop tier in Challenge Mode');

// ---------------------------------------------------------------------------
// 4. Battle (3 dailies per account) and Challenge (3 weeklies per account)
// ---------------------------------------------------------------------------
bounty_config(['earn' => ['battle_chance' => 100, 'challenge_chance' => 100]]);
// newserv shows a stage never cleared as 65535
$e = client('Ember', 80, ['ChallengeTimesEp1Online' => [900, 3000, 65535, 65535, 65535, 65535, 65535, 65535, 65535], 'ChallengeTimesEp2Online' => array_fill(0, 5, 65535)]);
for ($i = 0; $i < 8; $i++) {
    tick($db, $e, $battle);
}
check(bounty_earned_count($db, 9001, 'Ember', 'battle', 'daily') === 3, 'Battle Mode earns 3 dailies');
check(bounty_active_count($db, 9001, 'Ember', 'battle', 'daily') === 3, 'all 3 Battle bounties active');
foreach (acc_rows($db, 9001, "m.pool = 'battle'") as $r) {
    check($r['character_name'] === '', "Battle bounty #{$r['id']} is stored account-wide (empty character_name)");
    list($q, $w) = bounty_parse_battle_target($r['goal_target']);
    check($r['goal_type'] === 'BATTLE_RULE_WINS' && $q >= 501 && $q <= 508 && $w >= 1 && $w <= 5, "Battle bounty names a rule set and 1-5 wins ({$r['goal_target']})");
    check(strpos(getClearObjective($r['goal_type'], $r['goal_target']), 'Battle ' . ($q - 500) . ':') !== false, 'Battle objective names the rule set');
}
$chal = ['is_game' => true, 'episode' => 1, 'difficulty' => 'Normal', 'mode' => 'Challenge', 'players' => 2];
for ($i = 0; $i < 8; $i++) {
    tick($db, $e, $chal);
}
check(bounty_earned_count($db, 9001, 'Ember', 'challenge', 'weekly') === 3, 'Challenge Mode earns 3 weeklies');
foreach (acc_rows($db, 9001, "m.pool = 'challenge'") as $r) {
    check($r['character_name'] === '', "Challenge bounty #{$r['id']} is stored account-wide (empty character_name)");
    $ct = bounty_parse_challenge_target($r['goal_target']);
    check($r['goal_type'] === 'CHALLENGE_RUN' && $r['cadence'] === 'weekly', "Challenge bounty is a weekly stage objective ({$r['goal_target']})");
    if ($r['status'] === 'in_progress' && !($ct['first'] === 1 && $ct['last'] === bounty_challenge_stage_total($ct['episode']))) {
        check($ct['first'] <= (($ct['episode'] === 1) ? 3 : 1), 'Challenge stage starts within reach (highest cleared + 1)');
    }
}
// Second character on the same account shares Battle & Challenge caps and board summary
$eAlt = client('EmberAlt', 1);
tick($db, $eAlt, $battle);
tick($db, $eAlt, $chal);
check(bounty_earned_count($db, 9001, 'EmberAlt', 'battle', 'daily') === 3 && bounty_active_count($db, 9001, 'EmberAlt', 'battle', 'daily') === 3, 'second character shares Battle daily cap and slots');
check(bounty_earned_count($db, 9001, 'EmberAlt', 'challenge', 'weekly') === 3 && bounty_active_count($db, 9001, 'EmberAlt', 'challenge', 'weekly') === 3, 'second character shares Challenge weekly cap and slots');
$accSum = bounty_account_board_summary($db, 9001);
$charSum = bounty_board_summary($db, 9001);
check($accSum['has_any'] && $accSum['battle'] === [3, 3, 0] && $accSum['challenge'] === [3, 3, 0] && !isset($charSum['']), 'account board summary tracks Battle/Challenge and excludes empty character key from per-character summary');

// Normal/Solo play also earns Battle (daily) and Challenge (weekly) bounties within pool caps (fresh accounts)
$db->exec("INSERT INTO users (username, email, account_id, language) VALUES ('t11', 't11@x', 9011, 'en'), ('t12', 't12@x', 9012, 'en')");
bounty_config(['earn' => ['battle_chance' => 0, 'challenge_chance' => 0, 'normal_battle_chance' => 100, 'normal_challenge_chance' => 100]]);
$fern = client('Fern', 80, ['Account' => ['AccountID' => 9011], 'ChallengeTimesEp1Online' => [900, 65535, 65535, 65535, 65535, 65535, 65535, 65535, 65535], 'ChallengeTimesEp2Online' => array_fill(0, 5, 65535)]);
tick($db, $fern, null);
check(bounty_earned_count($db, 9011, 'Fern', 'battle', 'daily') === 0 && bounty_earned_count($db, 9011, 'Fern', 'challenge', 'weekly') === 0, 'lobby idling does not roll Battle/Challenge normal-play bounties');
$normal_room = ['is_game' => true, 'episode' => 1, 'difficulty' => 'Ultimate', 'mode' => 'Normal', 'players' => 2];
for ($i = 0; $i < 6; $i++) {
    tick($db, $fern, $normal_room);
}
check(bounty_earned_count($db, 9011, 'Fern', 'battle', 'daily') === 3, 'Normal play earns up to 3 Battle dailies per account');
check(bounty_earned_count($db, 9011, 'Fern', 'challenge', 'weekly') === 3, 'Normal play earns up to 3 Challenge weeklies per account');
$flint = client('Flint', 80, ['Account' => ['AccountID' => 9012]]);
ob_start();
bounty_try_mode_earn($db, 9012, 'Flint', $flint, 'battle');
ob_end_clean();
tick($db, $flint, null, null, [['pool' => 'battle', 'cadence' => 'daily']]);
check(bounty_earned_count($db, 9012, 'Flint', 'battle', 'daily') === 2, 'completing a Battle bounty earns another within cap');
bounty_config($quiet);

// ---------------------------------------------------------------------------
// 5. Weekly objectives and their progress
// ---------------------------------------------------------------------------
function weekly_row($db, $char, $goal, $target)
{
    ob_start();
    $id = bounty_earn($db, 9001, $char, client($char), 'standard', 'weekly', 'rare', 'login');
    ob_end_clean();
    $mid = $db->querySingle("SELECT mission_id FROM player_missions WHERE id = $id");
    $db->exec("UPDATE missions SET goal_type = '$goal', goal_target = '$target', title = 'W' WHERE id = $mid");
    $db->exec("UPDATE player_missions SET status = 'in_progress', activated_at = " . (time() - 5) . " WHERE id = $id");
    return $id;
}
function wrow($db, $id)
{
    return $db->query("SELECT pm.id, pm.progress, pm.activated_at, m.goal_type, m.goal_target FROM player_missions pm JOIN missions m ON pm.mission_id = m.id WHERE pm.id = $id")->fetchArray(SQLITE3_ASSOC);
}
$hard1 = ['is_game' => true, 'episode' => 1, 'difficulty' => 'Hard', 'mode' => 'Normal', 'players' => 2];
$norm1 = ['is_game' => true, 'episode' => 1, 'difficulty' => 'Normal', 'mode' => 'Normal', 'players' => 2];
$id = weekly_row($db, 'Fennec', 'AREA_SWEEP', '1@Hard');
$done = false;
foreach ([1, 2, 3, 3, 4, 5, 6, 7, 8, 9] as $f) {
    $done = bounty_evaluate_weekly($db, wrow($db, $id), client('Fennec', 60, ['LocationFloor' => $f]), $hard1, no_kills(), []);
}
check(!$done && bounty_progress_text('AREA_SWEEP', '1@Hard', wrow($db, $id)['progress']) === '9 / 10 areas', 'area sweep counts each area once');
bounty_evaluate_weekly($db, wrow($db, $id), client('Fennec', 60, ['LocationFloor' => 10]), $norm1, no_kills(), []);
check(bounty_progress_text('AREA_SWEEP', '1@Hard', wrow($db, $id)['progress']) === '9 / 10 areas', 'an area on a lower difficulty does not count');
$done = bounty_evaluate_weekly($db, wrow($db, $id), client('Fennec', 60, ['LocationFloor' => 10]), $hard1, no_kills(), []);
check($done, 'area sweep completes after every Episode 1 area');

$id = weekly_row($db, 'Fennec', 'ENEMY_HUNT', '100@Hard');
$k = no_kills();
$k['enemy'] = 60;
check(!bounty_evaluate_weekly($db, wrow($db, $id), client('Fennec'), $hard1, $k, []), 'enemy hunt: 60 of 100');
check(!bounty_evaluate_weekly($db, wrow($db, $id), client('Fennec'), $battle, $k, []), 'Battle Mode kills do not count');
check(bounty_evaluate_weekly($db, wrow($db, $id), client('Fennec'), $hard1, $k, []), 'enemy hunt completes at 120 of 100');

$id = weekly_row($db, 'Fennec', 'BOSS_HUNT', '2@Hard');
$k = no_kills();
$k['boss_events'] = [['time' => time() - 3600, 'lobby_id' => 1], ['time' => time(), 'lobby_id' => 1]];
check(!bounty_evaluate_weekly($db, wrow($db, $id), client('Fennec'), $hard1, $k, [1 => $hard1]), 'a boss killed before the bounty started does not count');
check(bounty_progress_text('BOSS_HUNT', '2@Hard', wrow($db, $id)['progress']) === '1 / 2 bosses', 'boss hunt progress 1 / 2');

check(bounty_weekly_difficulty(150, 'rare') === 'Very Hard' && bounty_weekly_difficulty(150, 'mythic') === 'Ultimate', 'only Mythic weeklies ask for Ultimate');

// Kill counters from the newserv patch
$s = bounty_kill_deltas(['EnemyKillCount' => 40, 'RareEnemyKillCount' => 1], [], [])['state'];
$k = bounty_kill_deltas(['EnemyKillCount' => 55, 'RareEnemyKillCount' => 2], $s, []);
check($k['enemy'] === 15 && $k['rare'] === 1, 'kill deltas between passes');
$k = bounty_kill_deltas(['EnemyKillCount' => 7, 'RareEnemyKillCount' => 0], $k['state'], []);
check($k['enemy'] === 7, 'a reconnect (counter restarted) counts the new total');
$k = bounty_kill_deltas(['Name' => 'x'], [], [['time' => time() - 10]]);
check($k['boss_events'] === [] && !$k['has_counters'], 'first sight: earlier boss kills are not new');

// Kills by anyone in the game count for everyone in it
$id = weekly_row($db, 'Fennec', 'ENEMY_HUNT', '100@Hard');
$k = no_kills();
$k['enemy'] = 5; // the player's own
$k['party'] = ['enemy' => 70, 'rare' => 1]; // the whole game, the player's own included
check(!bounty_evaluate_weekly($db, wrow($db, $id), client('Fennec'), $hard1, $k, []) && bounty_progress_text('ENEMY_HUNT', '100@Hard', wrow($db, $id)['progress']) === '70 / 100 enemies',
      'an enemy hunt counts every kill in the game (70), not only the player\'s own (5)');
$id = weekly_row($db, 'Fennec', 'RARE_HUNT', '1@Hard');
check(bounty_evaluate_weekly($db, wrow($db, $id), client('Fennec'), $hard1, $k, []), 'a teammate\'s rare kill finishes a rare hunt');
$id = weekly_row($db, 'Fennec', 'BOSS_HUNT', '3@Hard');
$t = time();
$k = no_kills();
$k['boss_events'] = [['time' => $t, 'lobby_id' => 7, 'episode' => 1, 'floor' => 11]];
$k['party_boss_events'] = [['time' => $t, 'lobby_id' => 7, 'episode' => 1, 'floor' => 11], ['time' => $t + 4, 'lobby_id' => 7, 'episode' => 1, 'floor' => 11]];
bounty_evaluate_weekly($db, wrow($db, $id), client('Fennec'), $hard1, $k, [7 => $hard1]);
check(bounty_progress_text('BOSS_HUNT', '3@Hard', wrow($db, $id)['progress']) === '1 / 3 bosses', 'one boss fight counts once, however many players the tracker saw kill it');
$k = no_kills();
$k['party_boss_events'] = [['time' => $t + 20, 'lobby_id' => 7, 'episode' => 1, 'floor' => 11], ['time' => $t + 25, 'lobby_id' => 7, 'episode' => 1, 'floor' => 12]];
bounty_evaluate_weekly($db, wrow($db, $id), client('Fennec', 60, ['LocationFloor' => 0]), $hard1, $k, [7 => $hard1]);
check(bounty_progress_text('BOSS_HUNT', '3@Hard', wrow($db, $id)['progress']) === '2 / 3 bosses',
      'a teammate\'s boss kill counts for a player who wasn\'t in the arena; a late report of the same fight does not');

// newserv's KillCounts: each player's own kills by episode, difficulty, mode and enemy
$kc = function ($n_booma, $n_falz, $n_blue = 0) {
    return ['KillCounts' => array_values(array_filter([
        ['Episode' => 'Episode 1', 'Difficulty' => 'Ultimate', 'Mode' => 'Normal', 'Enemy' => 'BOOMA', 'Name' => 'Bartle', 'Rare' => false, 'Boss' => false, 'Count' => $n_booma],
        ['Episode' => 'Episode 1', 'Difficulty' => 'Ultimate', 'Mode' => 'Normal', 'Enemy' => 'DARK_FALZ_3', 'Name' => 'Dark Falz', 'Rare' => false, 'Boss' => true, 'Count' => $n_falz],
        $n_blue ? ['Episode' => 'Episode 1', 'Difficulty' => 'Ultimate', 'Mode' => 'Normal', 'Enemy' => 'HILDEBLUE', 'Name' => 'Hildetorr', 'Rare' => true, 'Boss' => false, 'Count' => $n_blue] : null,
    ]))];
};
$d0 = enemy_kill_deltas($kc(10, 0), []);
check($d0['has'] && $d0['entries'] === [], 'kill counts: first sight records nothing');
$d1 = enemy_kill_deltas($kc(25, 1, 1), ['kill_counts' => $d0['state']]);
$by = [];
foreach ($d1['entries'] as $e) $by[$e['enemy']] = $e;
check(($by['BOOMA']['n'] ?? 0) === 15 && ($by['DARK_FALZ_3']['n'] ?? 0) === 1 && !empty($by['DARK_FALZ_3']['boss']) && !empty($by['HILDEBLUE']['rare'])
      && $by['BOOMA']['episode'] === 1 && $by['BOOMA']['difficulty'] === 'Ultimate', 'kill counts: new kills per enemy, with episode, difficulty, boss and rare');
$d2 = enemy_kill_deltas($kc(3, 0), ['kill_counts' => $d1['state']]);
check($d2['reset'] && count($d2['entries']) === 1 && $d2['entries'][0]['n'] === 3, 'kill counts: a reconnect counts the new readings');
$sum = [];
enemy_kills_add_to_summary($sum, $d1['entries']);
enemy_kills_add_to_summary($sum, [['episode' => 1, 'difficulty' => 'Ultimate', 'mode' => 'Normal', 'enemy' => 'BOOMA', 'name' => 'Bartle', 'rare' => false, 'boss' => false, 'n' => 4]]);
check($sum['enemy'] === 21 && $sum['rare'] === 1 && $sum['boss'] === 1 && $sum['by_name']['BOOMA'] === 19 && $sum['rare_names'] === ['HILDEBLUE' => 1], 'a game\'s summary adds up its players\' kills');
$sum2 = [];
enemy_kills_add_to_summary($sum2, null, 12, 1, 'NAR_LILY');
check($sum2['enemy'] === 12 && $sum2['rare_names'] === ['NAR_LILY' => 1] && !$sum2['typed'], 'without KillCounts, a game\'s summary uses the totals');
enemy_kills_record($db, 9001, 'Kestrel', $d1['entries']);
enemy_kills_record($db, 9001, 'Kestrel', [['episode' => 1, 'difficulty' => 'Normal', 'mode' => 'Normal', 'enemy' => 'DARK_FALZ_2', 'name' => 'Dark Falz', 'rare' => false, 'boss' => true, 'n' => 2],
                                          ['episode' => 1, 'difficulty' => 'Normal', 'mode' => 'Challenge', 'enemy' => 'BOOMA', 'name' => 'Booma', 'rare' => false, 'boss' => false, 'n' => 7],
                                          ['episode' => 1, 'difficulty' => 'Ultimate', 'mode' => 'Normal', 'enemy' => 'BOOMA', 'name' => 'Bartle', 'rare' => false, 'boss' => false, 'n' => 5]]);
$kv = enemy_kills_for_character($db, 9001, 'Kestrel');
$rows = [];
foreach ($kv['episodes']['1'] as $row) $rows[$row['enemy']] = $row;
check(count($kv['episodes']['1']) === 3 && $kv['episodes']['1'][0]['enemy'] === 'DARK_FALZ_3', 'kill counts: one row per enemy, bosses first');
check($rows['DARK_FALZ_3']['counts']['Normal'] === 2 && $rows['DARK_FALZ_3']['counts']['Ultimate'] === 1 && $rows['DARK_FALZ_3']['name'] === 'Dark Falz',
      'Dark Falz\'s final form on Normal and above is one row');
check($rows['BOOMA']['counts']['Ultimate'] === 20 && $rows['BOOMA']['challenge'] === 7 && $rows['BOOMA']['total'] === 27 && $rows['BOOMA']['name'] === 'Booma' && $rows['BOOMA']['ultimate_name'] === 'Bartle',
      'kill counts add up per difficulty, Challenge Mode apart, with the Ultimate name');
check($kv['totals'] === ['kills' => 31, 'bosses' => 3, 'rares' => 1], 'kill count totals (' . json_encode($kv['totals']) . ')');
check(enemy_kills_for_character($db, 9001, 'Nobody')['episodes'] === [], 'no kills for an unknown character');

// ---------------------------------------------------------------------------
// 6. Reroll: keeps tier and reward, new objective, once
// ---------------------------------------------------------------------------
$g = client('Gale', 70);
ob_start();
$id = bounty_earn($db, 9001, 'Gale', $g, 'standard', 'daily', 'uncommon', 'login');
ob_end_clean();
tick($db, $g);
$before = rows($db, 'Gale', "pm.id = $id")[0];
$db->exec("UPDATE missions SET goal_type = 'MENTOR_BOSS', goal_target = '11@1' WHERE id = " . (int) $before['mission_id']);
$db->exec("UPDATE player_missions SET status = 'rerolling', rerolled = 1 WHERE id = $id");
check(bounty_active_count($db, 9001, 'Gale', 'standard', 'daily') === 1, 'a rerolling bounty keeps its slot');
tick($db, $g);
$after = rows($db, 'Gale', "pm.id = $id")[0];
check($after['status'] === 'in_progress' && (int) $after['rerolled'] === 1, 'reroll comes back active and marked rerolled');
check($after['tier'] === $before['tier'] && $after['reward_item_string'] === $before['reward_item_string'], 'reroll keeps tier and reward');
check($after['title'] !== $before['title'], 'reroll writes a new story');
check(!in_array($after['goal_type'], bounty_config()['reroll_goal_types'], true), 'a reroll does not hand back another team objective');

// ---------------------------------------------------------------------------
// 7. Expiry at the reset
// ---------------------------------------------------------------------------
$ready = (int) $db->querySingle("SELECT COUNT(*) FROM player_missions WHERE status = 'ready_to_redeem'");
$day_end = bounty_period_bounds('daily')[1];
$n = bounty_expire_sweep($db, $day_end);
$left_daily = (int) $db->querySingle("SELECT COUNT(*) FROM player_missions pm JOIN missions m ON pm.mission_id = m.id WHERE m.cadence = 'daily' AND pm.status IN ('queued', 'in_progress', 'rerolling')");
check($n > 0 && $left_daily === 0, 'unfinished dailies expire at the daily reset');
$week_end = bounty_period_bounds('weekly')[1];
if ($week_end > $day_end) {
    check((int) $db->querySingle("SELECT COUNT(*) FROM player_missions pm JOIN missions m ON pm.mission_id = m.id WHERE m.cadence = 'weekly' AND pm.status IN ('queued', 'in_progress')") > 0, 'weeklies survive the daily reset');
}
bounty_expire_sweep($db, $week_end);
check((int) $db->querySingle("SELECT COUNT(*) FROM player_missions WHERE status IN ('queued', 'in_progress', 'rerolling')") === 0, 'everything unfinished expires at the weekly reset');
check((int) $db->querySingle("SELECT COUNT(*) FROM player_missions WHERE status = 'ready_to_redeem'") === $ready, 'finished, unclaimed bounties never expire');
check(bounty_period_key('daily', $day_end) !== bounty_period_key('daily', $day_end - 1), 'a new day starts a new earning period');

// ---------------------------------------------------------------------------
// 8. Rewards from the drop data: tiers, areas, the 10x rule, stat limits, Photon Drops
// ---------------------------------------------------------------------------
$items = bounty_item_data();
check(count($items) > 1000, 'item data loaded (' . count($items) . ' items)');
$rc = bounty_reward_cfg();
$sections = ['Viridia', 'Greennill', 'Skyly', 'Bluefull', 'Purplenum', 'Pinkal', 'Redria', 'Oran', 'Yellowboze', 'Whitill'];
$settings = array_keys(bounty_area_data()['settings']);
$spheres_max = 0;
for ($i = 0; $i < 600; $i++) {
    $tier = BOUNTY_TIERS[$i % 5];
    $sec = $sections[$i % 10];
    $cls = BOUNTY_CHAR_CLASSES[$i % 12];
    $lv = 1 + ($i * 37) % 200;
    $setting = $settings[$i % count($settings)];
    $area = bounty_reward_area($setting, null);
    $hunter = ['Level' => $lv, 'CharClass' => $cls, 'SectionID' => $sec];
    $spec = bounty_build_reward_spec_v2($tier, $hunter, $area);
    $shown = bounty_reward_display($spec);
    $rolled = bounty_roll_reward_string($spec);
    check(preg_match('/(^|, )\d+ Meseta$/', $rolled) === 1, "$tier reward ends with Meseta");
    if ($tier === 'common') {
        check($shown === $rolled, 'common rewards have nothing hidden');
    }
    // Photon Drops: a currency, at most one Photon Sphere, and Spheres only from Ultra Rare and Mythic Rare
    check(substr_count($rolled, BOUNTY_PHOTON_SPHERE) <= 1, 'at most one Photon Sphere');
    if (in_array($tier, ['common', 'uncommon', 'rare'], true)) {
        check(strpos($rolled, BOUNTY_PHOTON_SPHERE) === false, "$tier bounties never pay a Photon Sphere");
    }
    $spheres_max = max($spheres_max, substr_count($rolled, BOUNTY_PHOTON_SPHERE));
    if (preg_match('/' . BOUNTY_PHOTON_DROP . ' x(\d+)/', $rolled, $mm)) {
        check((int) $mm[1] <= 99, 'Photon Drops fit one stack');
    }
    $tokens_seen = 0;
    foreach ($spec['items'] as $it) {
        $code = strtoupper(substr(explode(' ', $it['base'])[0], 0, 6));
        $d = $items[$code] ?? null;
        if (!empty($it['token'])) {
            // Exchange tokens: Uncommon to Mythic Rare, at most one, from the token list
            $tokens_seen++;
            $tc = $rc['exchange_tokens'];
            check($tier !== 'common', 'no exchange tokens on Common bounties');
            check(in_array($code, array_merge(['031209', '031003', '031004', '031002', '031200', '031201', '031202'], $tc['enemy_parts']), true), "token $code is an exchange token");
            preg_match('/ x(\d+)$/', $it['base'], $qm);
            $q = (int) ($qm[1] ?? 1);
            list($qlo, $qhi) = $tc['kinds'][$it['token']]['qty'][$tier];
            check($q >= $qlo && $q <= $qhi, "token quantity $q for $tier {$it['token']}");
            if ($it['token'] === 'enemy_part' && $area['drop_settings']) {
                $here = array_filter($tc['enemy_parts'], function ($p) use ($items, $area) { return (bool) array_intersect($area['drop_settings'], $items[$p]['drop_settings']); });
                if ($here) {
                    check(in_array($code, $here, true), "enemy part $code comes from $setting");
                }
            }
            if ($it['token'] === 'weapons_badge') {
                $metal = ['uncommon' => ['031200'], 'rare' => ['031200', '031201'], 'ultra' => ['031201', '031202'], 'mythic' => ['031202']][$tier];
                check(in_array($code, $metal, true), "$tier badge metal ($code)");
            }
            continue;
        }
        if (in_array($tier, ['rare', 'ultra', 'mythic'], true) && $d) {
            if ($tier === 'mythic' && strpos((string) $spec['pool'], 'exchange item') === 0) {
                check(bounty_is_mythic_exchange_item($code, $d, $rc), "Mythic exchange reward $code is allowed");
                continue;
            }
            check(!in_array($d['obtained'], ['challenge_prize', 'not_obtainable', 'seasonal_drop_only', 'exchange_only', 'combination_only'], true), "$tier reward $code is allowed ({$d['obtained']})");
            check(!bounty_is_challenge_reserved($d, $rc), "$tier reward $code is not reserved for Challenge full runs");
            $r = (float) $d['drop_rate_by_section'][bounty_section_index($sec)];
            if ($tier === 'rare') {
                $avg = bounty_item_average_rate($d);
                check($d['obtained'] === 'rare_drop' && $avg >= $rc['rare_min_rate'] && $avg < $rc['rare_max_rate'] && $r < $rc['own_section_common_rate'], "Rare reward $code is in the Rare band server-wide and not common for $sec");
                check(in_array($cls, $d['usable_by'], true), "Rare reward $code is usable by $cls");
            } elseif ($tier === 'ultra') {
                check($d['obtained'] === 'rare_drop' && bounty_item_average_rate($d) < $rc['rare_min_rate'] && $r < $rc['own_section_common_rate'], "Ultra reward $code is rarer than the Rare band server-wide and not common for $sec");
            } else {
                check($d['obtained'] === 'quest_reward_only', "Mythic reward $code is a quest reward that never drops ({$d['obtained']})");
            }
            if (in_array($tier, ['rare', 'ultra'], true) && strpos((string) $spec['pool'], 'area') === 0 && $area['drop_settings']) {
                check((bool) array_intersect($area['drop_settings'], $d['drop_settings']), "$tier reward $code drops in $setting");
            }
        }
    }
    check($tokens_seen <= 1, 'at most one exchange token');
    if ($tier === 'mythic') {
        check($tokens_seen === 1, 'Mythic Rare always pays an exchange token');
    }
    foreach (explode(', ', $rolled) as $part) {
        if (preg_match('/ (\d+)\/(\d+)\/(\d+)\/(\d+)(?:\/(\d+))?$/', $part, $mm)) {
            $attrs = array_map('intval', array_slice($mm, 1, 4));
            $hit = (int) ($mm[5] ?? 0);
            check(count(array_filter($attrs)) <= 2, "at most 2 attributes ($part)");
            check(max($attrs) <= 90 && $hit <= 50, "attributes at most 90, hit at most 50 ($part)");
            foreach (array_merge($attrs, [$hit]) as $v) {
                check($v % 5 === 0, "stat $v is a multiple of 5");
            }
            // The first attribute matches the area's enemies: for a one-class area it must be that class
            $one = array_keys(array_filter($area['attributes'], function ($x) { return $x >= 0.999; }));
            if ($one) {
                check($attrs[array_search($one[0], BOUNTY_ATTRS, true)] > 0, "weapon from $setting has the {$one[0]} attribute ($part)");
            }
        }
        if (preg_match('/ \+(\d+)$/', $part, $mm) && strpos($part, 'Meseta') === false && strpos($part, 'def') === false && strpos($part, 'evp') === false) {
            check((int) $mm[1] <= 4, 'armor slots at most 4');
        }
    }
}
check($spheres_max === 1, 'Photon Spheres are paid sometimes');

// Photon Drops and Spheres roll on their own: neither is given every time
$pc = $rc['photon'];
foreach (BOUNTY_TIERS as $t) {
    $nd = $ns = 0;
    for ($i = 0; $i < 4000; $i++) {
        $ph = bounty_photon_reward($t, 200);
        $nd += $ph['drops'] > 0 ? 1 : 0;
        $ns += $ph['spheres'];
        check($ph['spheres'] <= 1 && $ph['drops'] <= 99, 'at most one Sphere and one stack of Drops');
    }
    check(abs($nd / 40 - $pc['drop_chance'][$t]) < 4, "$t pays Photon Drops about {$pc['drop_chance'][$t]}% of the time (" . ($nd / 40) . '%)');
    if (in_array($t, ['common', 'uncommon', 'rare'], true)) {
        check($pc['sphere_chance'][$t] == 0 && $ns === 0, "$t never pays a Photon Sphere ($ns in 4000)");
    } else {
        check($pc['sphere_chance'][$t] > 0 && abs($ns / 40 - $pc['sphere_chance'][$t]) < max(1.0, $pc['sphere_chance'][$t] / 3), "$t pays a Photon Sphere about {$pc['sphere_chance'][$t]}% of the time (" . ($ns / 40) . '%)');
    }
}

// Rewards come from every Section ID's table, including items the hunter's own never drops
$redria = array_search('redria', BOUNTY_SECTION_IDS, true);
$pool = bounty_reward_pool('rare', ['section' => $redria, 'class' => array_search('HUcast', BOUNTY_CHAR_CLASSES, true), 'level' => 152,
    'area' => bounty_reward_area('ep1_ruins', 1)]);
check(isset($pool['items']['009400']) && $pool['items']['009400'] == 0, 'a Redria hunter can win Morning Glory, which Redria never finds');
foreach (range(0, 9) as $sec) {
    $foreign = 0;
    foreach (['rare', 'ultra'] as $tier) {
        foreach ([60, 120, 200] as $lv) {
            foreach (['ep1_ruins', 'ep2_seabed', 'ep4_desert'] as $st) {
                $p = bounty_reward_pool($tier, ['section' => $sec, 'class' => 0, 'level' => $lv, 'area' => bounty_reward_area($st, null)]);
                $foreign += count(array_filter($p['items'], function ($r) { return $r == 0; }));
            }
        }
    }
    check($foreign > 0, BOUNTY_SECTION_IDS[$sec] . " pools hold items only other Section IDs find ($foreign)");
}

// The 10x rule, for every Section ID at a few levels and areas
$m10 = (float) $rc['drop_rate_multiplier'];
$worst = INF;
foreach (['rare', 'ultra', 'mythic'] as $tier) {
    foreach (range(0, 9) as $sec) {
        foreach ([20, 80, 150, 200] as $lv) {
            foreach (['ep1_forest', 'ep1_ruins', 'ep2_seabed', 'ep4_desert', 'ep2_lab'] as $setting) {
                $pool = bounty_reward_pool($tier, ['section' => $sec, 'class' => $sec % 12, 'level' => $lv, 'area' => bounty_reward_area($setting, null)]);
                $ch = bounty_pool_chances($pool['items']);
                check(abs(array_sum($ch) - 1.0) < 1e-9 && count($ch) >= 1, "$tier pool has chances summing to 1");
                foreach ($ch as $code => $p) {
                    $r = $pool['items'][$code];
                    if ($r > 0) {
                        $worst = min($worst, $p / $r);
                    }
                    check($p + 1e-12 >= $m10 * $r, "10x rule: $tier $code from a bounty ($p) vs a kill ($r)");
                }
            }
        }
    }
}
check($worst >= 10, 'every item is at least 10x as likely from a bounty as from a kill (worst ' . round($worst, 1) . 'x)');

// Exchange items stay with their exchanges: Mythic Rare pays one only rarely, never the Item Ticket
// shop's or Dr. Osto's (their tokens are paid instead), never tokens or combination results
$ex = bounty_mythic_exchange_items();
check(count($ex) > 50, 'Mythic exchange pool has items (' . count($ex) . ')');
foreach ($ex as $code) {
    $d = $items[$code];
    check(!array_filter($d['exchanges'], function ($e) { return strpos($e, 'Item Present') === 0; }), "$code is not an Item Ticket shop item");
    check(!array_intersect($d['quest_rewards'], ["Dr. Osto's Research", 'Unsealed Door']), "$code is not a Dr. Osto trade");
    check(empty($d['combinations']), "$code is not a combination result");
}
foreach (['031209', '031003', '031200', '031000', '031001', '00CA00', '001800', '008E01', '006D01'] as $code) {
    check(!in_array($code, $ex, true), "$code is not in the Mythic exchange pool");
}
$hunter = ['Level' => 150, 'CharClass' => 'RAcast', 'SectionID' => 'Oran'];
$nex = 0;
$tok = array_fill_keys(BOUNTY_TIERS, 0);
for ($i = 0; $i < 4000; $i++) {
    $sp = bounty_build_reward_spec_v2('mythic', $hunter, bounty_reward_area('ep1_mines', 1));
    if (strpos((string) $sp['pool'], 'exchange item') === 0) {
        $nex++;
    }
}
check($nex >= 40 && $nex <= 240, "Mythic Rare pays an exchange item about 3% of the time ($nex / 4000)");
foreach (['common', 'uncommon', 'rare', 'ultra'] as $t) {
    for ($i = 0; $i < 2000; $i++) {
        $sp = bounty_build_reward_spec_v2($t, $hunter, bounty_reward_area('ep1_mines', 1));
        foreach ($sp['items'] as $it) {
            if (!empty($it['token'])) {
                $tok[$t]++;
            }
        }
    }
}
check($tok['common'] === 0, 'Common bounties pay no tokens');
foreach (['uncommon' => 20, 'rare' => 35, 'ultra' => 60] as $t => $pct) {
    check(abs($tok[$t] / 2000 * 100 - $pct) < 5, "$t pays a token about $pct% of the time ({$tok[$t]} / 2000)");
}

// Challenge Mode: paces, objectives, the full-run reserve
check(bounty_challenge_pace_seconds(1, 9, 9, 'S') === 70 * 60, 'Ep1 Stage 9 S pace is 70 min');
check(bounty_challenge_pace_seconds(1, 9, 9, 'A') === 130 * 60, 'Ep1 Stage 9 A pace is 130 min (x13/7)');
check(bounty_challenge_pace_seconds(1, 1, 9, 'S') === 7 * 3600, 'Ep1 full run S pace is the 7 h S-rank limit');
check(bounty_challenge_pace_seconds(2, 1, 5, 'S') === 5 * 3600, 'Ep2 full run S pace is the 5 h S-rank limit');
check(bounty_challenge_pace_seconds(2, 4, 4, 'B') === 0, 'B pace has no limit');
$fresh = client('Fresh', 50, ['ChallengeTimesEp1Online' => array_fill(0, 9, 65535), 'ChallengeTimesEp2Online' => array_fill(0, 5, 65535)]);
for ($i = 0; $i < 300; $i++) {
    $tier = BOUNTY_TIERS[$i % 5];
    $o = bounty_pick_challenge_objective($fresh, $tier);
    $ct = bounty_parse_challenge_target($o['target']);
    $n = $ct['last'] - $ct['first'] + 1;
    if ($tier === 'mythic') {
        check($o['full'] && $ct['first'] === 1 && $ct['last'] === bounty_challenge_stage_total($ct['episode']) && $ct['pace'] === 'S', 'Mythic Challenge bounty is a full run at S-rank pace');
    } elseif ($tier === 'ultra') {
        check($n >= 2 && $n <= 4 && $ct['first'] === 1, "Ultra Challenge bounty spans 2-4 stages from the first a new character can play ({$o['target']})");
    } else {
        check($n === 1 && $ct['first'] === 1, "a new character's single-stage bounty is Stage 1 ({$o['target']})");
        check($ct['pace'] === ['common' => 'B', 'uncommon' => 'A', 'rare' => 'S'][$tier], "$tier Challenge pace");
    }
}
for ($i = 0; $i < 40; $i++) {
    $ep = 1 + $i % 2;
    $spec = bounty_build_reward_spec_v2('mythic', ['Level' => 1 + $i * 5, 'CharClass' => 'FOmarl', 'SectionID' => $sections[$i % 10]],
        bounty_challenge_reward_area($ep, 1, bounty_challenge_stage_total($ep)), ['challenge_reserve' => true, 'scale' => 2.0]);
    $code = $spec['items'][0]['base'];
    check(bounty_is_challenge_reserved($items[$code], $rc), "full-run reward $code is one of the rarest drops");
    check(in_array("Ep$ep", $items[$code]['drop_episodes'], true), "full-run reward $code drops in Episode $ep");
    $rolled = bounty_roll_reward_string($spec);
    $wantSpheres = ($ep === 1) ? 2 : 1;
    check(($spec['photon']['spheres'] ?? 0) === $wantSpheres, "Episode $ep full Challenge run guarantees $wantSpheres Photon Sphere(s)");
    $wantSphereToken = ($wantSpheres > 1) ? BOUNTY_PHOTON_SPHERE . " x$wantSpheres" : BOUNTY_PHOTON_SPHERE;
    check(strpos($rolled, $wantSphereToken) !== false && ($ep === 1 || strpos($rolled, BOUNTY_PHOTON_SPHERE . ' x') === false), "full Challenge run formats Episode $ep Photon Sphere count cleanly");
    if (preg_match('/ (\d+)\/(\d+)\/(\d+)\/(\d+)\/(\d+)/', $rolled, $mm)) {
        $attrs = array_values(array_filter(array_map('intval', array_slice($mm, 1, 4))));
        // Unsealable weapons (003300/00AB00) cap at 2 total attributes including Hit (slot 3 stores kills).
        $wantAttrs = in_array($code, ['003300', '00AB00'], true) ? 1 : 2;
        check(count($attrs) === $wantAttrs && min($attrs) >= 60 && max($attrs) <= 90 && (int) $mm[5] >= 30 && (int) $mm[5] <= 50, "full-run weapon has high stats ($rolled)");
    }
}

// Challenge clears: newserv counters, or record times without them
list($cl, $st) = bounty_challenge_clear_deltas(['ChallengeClearCount' => 3, 'LastChallengeClear' => ['QuestNumber' => 88104, 'Seconds' => 2500]], []);
check($cl === [] && $st['bounty_challenge_clears'] === 3, 'first sight of the clear counter counts nothing');
list($cl) = bounty_challenge_clear_deltas(['ChallengeClearCount' => 4, 'LastChallengeClear' => ['QuestNumber' => 88104, 'Seconds' => 2500]], $st);
check($cl === [[1, 4, 2500]], 'a clear counted by newserv (Ep1 Stage 4, 2500 s)');
list($cl) = bounty_challenge_clear_deltas(['ChallengeClearCount' => 1, 'LastChallengeClear' => ['QuestNumber' => 88202, 'Seconds' => 3100]], $st);
check($cl === [[2, 2, 3100]], 'a clear after reconnecting (Ep2 Stage 2)');
list($cl, $st2) = bounty_challenge_clear_deltas(['ChallengeTimesEp1Online' => [700, 65535, 65535], 'ChallengeTimesEp2Online' => [65535, 65535]], []);
list($cl) = bounty_challenge_clear_deltas(['ChallengeTimesEp1Online' => [650, 2800, 65535], 'ChallengeTimesEp2Online' => [65535, 65535]], $st2);
check($cl === [[1, 1, 650], [1, 2, 2800]], 'without the counters, changed record times count as clears');

// newserv shows a stage never cleared as 65535 (its decoding of an empty record), not 0
check(!challenge_time_cleared(65535) && !challenge_time_cleared(0) && challenge_time_cleared(2400), '65535 and 0 mean not cleared');
$real = ['ChallengeTimesEp1Online' => [700, 2800, 65535, 65535, 65535, 65535, 65535, 65535, 65535], 'ChallengeTimesEp2Online' => array_fill(0, 5, 65535)];
check(challenge_stages_cleared($real) === 2, 'two of the 14 stages cleared (' . challenge_stages_cleared($real) . ')');
check(bounty_challenge_highest_cleared($real, 1) === 2 && bounty_challenge_highest_cleared($real, 2) === 0, 'highest stage cleared skips the 65535s');
list($cl, $st3) = bounty_challenge_clear_deltas($real, []);
$real2 = $real;
$real2['ChallengeTimesEp1Online'][2] = 2500;
list($cl) = bounty_challenge_clear_deltas($real2, $st3);
check($cl === [[1, 3, 2500]], 'a first clear of Stage 3 (65535 to 2500) counts');
// The original "clear N Challenge stages" objective: one more than the stages cleared so far
$picked = null;
for ($i = 0; $i < 2000 && !$picked; $i++) {
    $o = bounty_pick_objective(client('Stager', 60, $real), 0, []);
    if ($o['selected_goal'] === 'CHALLENGE_STAGES') {
        $picked = $o;
    }
}
check($picked && (int) $picked['selected_target_id'] === 3, 'the stage-count objective asks for 3 with 2 cleared (' . ($picked['selected_target_id'] ?? 'not offered') . ')');
check(challenge_stages_cleared($real) < 3 && challenge_stages_cleared($real2) >= 3, 'and a new stage clear completes it');
check(strpos(getClearObjective('CHALLENGE_STAGES', '3'), 'until 3 of the 14 are cleared') !== false, 'the board says it is a total of the 14 stages');
$all = ['ChallengeTimesEp1Online' => array_fill(0, 9, 2000), 'ChallengeTimesEp2Online' => array_fill(0, 5, 3000)];
$offered = false;
for ($i = 0; $i < 2000 && !$offered; $i++) {
    $offered = bounty_pick_objective(client('Stager', 60, $all), 0, [])['selected_goal'] === 'CHALLENGE_STAGES';
}
check(!$offered, 'not offered once all 14 stages are cleared');

$cid = weekly_row($db, 'Gale', 'CHALLENGE_RUN', '1:3-4:' . bounty_challenge_pace_seconds(1, 3, 4, 'A') . ':A');
$kk = no_kills();
$kk['challenge_clears'] = [[1, 3, 4000]];
check(!bounty_evaluate_weekly($db, wrow($db, $cid), client('Gale'), null, $kk, []), 'one of two stages cleared: not done');
check(bounty_progress_text('CHALLENGE_RUN', wrow($db, $cid)['goal_target'], wrow($db, $cid)['progress']) === '1 / 2 stages, 1 h 07 min so far', 'Challenge progress text');
$kk['challenge_clears'] = [[2, 4, 100], [1, 4, 6000]];
check(!bounty_evaluate_weekly($db, wrow($db, $cid), client('Gale'), null, $kk, []), 'combined 10000 s is over the A pace: not done');
$kk['challenge_clears'] = [[1, 4, 4500]];
check(bounty_evaluate_weekly($db, wrow($db, $cid), client('Gale'), null, $kk, []), 'a faster replay brings the combined time under the A pace: done');

$bid = weekly_row($db, 'Gale', 'BATTLE_RULE_WINS', '503:2');
list($bw, $bs) = bounty_battle_win_deltas(['BattlePlaceCounts' => [5, 0, 0, 0]], [], ['is_game' => true, 'mode' => 'Battle', 'quest_number' => 503]);
check($bw['wins'] === 0 && $bs['bounty_battle_quest'] === 503, 'first sight of battle records counts nothing');
list($bw, $bs) = bounty_battle_win_deltas(['BattlePlaceCounts' => [6, 0, 0, 0]], $bs, null);
check($bw === ['quest' => 503, 'wins' => 1], 'a win reported after leaving is credited to the last rule set played');
$kk = no_kills();
$kk['battle_wins'] = $bw;
check(!bounty_evaluate_weekly($db, wrow($db, $bid), client('Gale'), null, $kk, []), 'one of two Battle 3 wins');
$kk['battle_wins'] = ['quest' => 501, 'wins' => 1];
check(!bounty_evaluate_weekly($db, wrow($db, $bid), client('Gale'), null, $kk, []), 'a Battle 1 win does not count for Battle 3');
$kk['battle_wins'] = ['quest' => 503, 'wins' => 1];
check(bounty_evaluate_weekly($db, wrow($db, $bid), client('Gale'), null, $kk, []), 'two Battle 3 wins: done');

// Account-wide Battle/Challenge reward mechanics:
// 100% Photon Drops, level-independence at Lv 1 vs Lv 200, monotonic time/pace scaling, speed bonus
$lowHunter = ['Level' => 1, 'CharClass' => 'HUmar', 'SectionID' => 'Skyly'];
$maxHunter = ['Level' => 200, 'CharClass' => 'HUmar', 'SectionID' => 'Skyly'];
foreach (BOUNTY_TIERS as $t) {
    $poolLow = bounty_reward_pool($t, ['section' => bounty_section_index('Skyly'), 'class' => 0, 'level' => 1, 'area' => bounty_reward_area('ep1_forest', 1), 'ignore_level' => true])['items'];
    $poolHigh = bounty_reward_pool($t, ['section' => bounty_section_index('Skyly'), 'class' => 0, 'level' => 200, 'area' => bounty_reward_area('ep1_forest', 1), 'ignore_level' => true])['items'];
    if (in_array($t, ['rare', 'ultra', 'mythic'], true)) {
        check(array_keys($poolLow) === array_keys($poolHigh), "level-independent $t pool matches at Lv 1 and Lv 200");
    }
    $bspec = bounty_build_reward_spec_v2($t, $lowHunter, bounty_reward_area('ep1_pioneer2', 1), ['ignore_level' => true, 'mode_pool' => 'battle', 'battle_wins' => 1]);
    $cspec = bounty_build_reward_spec_v2($t, $lowHunter, bounty_challenge_reward_area(1, 6, 6), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_minutes' => 50, 'challenge_pace' => 'S']);
    check((int) $bspec['level'] === 200 && (int) $cspec['level'] === 200, "$t mode specs lock level to 200 for full claim stat caps");
    check(($bspec['photon']['drops'] ?? 0) >= 1 && ($cspec['photon']['drops'] ?? 0) >= 1, "$t Battle and Challenge always include Photon Drops");
}
$missingPd = 0;
for ($i = 0; $i < 200; $i++) {
    $t = BOUNTY_TIERS[$i % 5];
    $h = ['Level' => 1 + ($i * 19) % 200, 'CharClass' => BOUNTY_CHAR_CLASSES[$i % 12], 'SectionID' => 'Viridia'];
    $bs = bounty_build_reward_spec_v2($t, $h, bounty_reward_area('ep1_pioneer2', 1), ['ignore_level' => true, 'mode_pool' => 'battle']);
    $cs = bounty_build_reward_spec_v2($t, $h, bounty_challenge_reward_area(1, 1, 1), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_minutes' => 13, 'challenge_pace' => 'B']);
    if (($bs['photon']['drops'] ?? 0) < 1 || ($cs['photon']['drops'] ?? 0) < 1) {
        $missingPd++;
    }
}
check($missingPd === 0, 'Battle and Challenge rewards pay >= 1 Photon Drop in 200/200 specs');

$c1B = bounty_build_reward_spec_v2('common', $lowHunter, bounty_challenge_reward_area(1, 1, 1), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_minutes' => 13, 'challenge_pace' => 'B']);
$c6B = bounty_build_reward_spec_v2('common', $lowHunter, bounty_challenge_reward_area(1, 6, 6), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_minutes' => 50, 'challenge_pace' => 'B']);
$c4Ep2B = bounty_build_reward_spec_v2('common', $lowHunter, bounty_challenge_reward_area(2, 4, 4), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_minutes' => 85, 'challenge_pace' => 'B']);
check($c1B['photon']['drops'] < $c6B['photon']['drops'] && $c6B['photon']['drops'] < $c4Ep2B['photon']['drops'], 'Challenge Photon Drops increase with stage length (Ep1 C1 < Ep1 C6 < Ep2 C4)');
check($c1B['meseta'] < $c6B['meseta'] && $c6B['meseta'] < $c4Ep2B['meseta'], 'Challenge Meseta increases with stage length (Ep1 C1 < Ep1 C6 < Ep2 C4)');

$uEp1Short = bounty_build_reward_spec_v2('ultra', $lowHunter, bounty_challenge_reward_area(1, 1, 2), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_minutes' => 60, 'challenge_pace' => 'S']);
$uEp2Long = bounty_build_reward_spec_v2('ultra', $lowHunter, bounty_challenge_reward_area(2, 1, 3), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_minutes' => 165, 'challenge_pace' => 'S']);
check($uEp1Short['photon']['drops'] < $uEp2Long['photon']['drops'] && $uEp1Short['meseta'] < $uEp2Long['meseta'], 'longer Ultra Challenge course strictly out-rewards shorter Ultra course');

$mEp2Full = bounty_build_reward_spec_v2('mythic', $lowHunter, bounty_challenge_reward_area(2, 1, 5), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_reserve' => true, 'challenge_minutes' => 300, 'challenge_pace' => 'S']);
$mEp1Full = bounty_build_reward_spec_v2('mythic', $lowHunter, bounty_challenge_reward_area(1, 1, 9), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_reserve' => true, 'challenge_minutes' => 420, 'challenge_pace' => 'S']);
check($mEp2Full['photon']['drops'] < $mEp1Full['photon']['drops'] && $mEp2Full['meseta'] < $mEp1Full['meseta'], 'Ep1 7h full run strictly out-rewards Ep2 5h full run');
check(($mEp2Full['photon']['spheres'] ?? 0) === 1 && ($mEp1Full['photon']['spheres'] ?? 0) === 2 && $mEp2Full['photon']['value'] < $mEp1Full['photon']['value'], 'full Challenge runs guarantee 1 Photon Sphere for Ep2 and 2 for Ep1');
$dispEp1 = html_entity_decode(strip_tags(renderRewardString(bounty_reward_display($mEp1Full))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$dispEp2 = html_entity_decode(strip_tags(renderRewardString(bounty_reward_display($mEp2Full))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
check(strpos($dispEp1, 'Photon Sphere x2') !== false && strpos($dispEp2, 'Photon Sphere') !== false && strpos($dispEp2, 'Photon Sphere x') === false, 'full Challenge display renders Photon Sphere x2 for Ep1 and single Photon Sphere for Ep2');

$pB = bounty_build_reward_spec_v2('rare', $lowHunter, bounty_challenge_reward_area(1, 6, 6), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_minutes' => 50, 'challenge_pace' => 'B']);
$pA = bounty_build_reward_spec_v2('rare', $lowHunter, bounty_challenge_reward_area(1, 6, 6), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_minutes' => 50, 'challenge_pace' => 'A']);
$pS = bounty_build_reward_spec_v2('rare', $lowHunter, bounty_challenge_reward_area(1, 6, 6), ['ignore_level' => true, 'mode_pool' => 'challenge', 'challenge_minutes' => 50, 'challenge_pace' => 'S']);
check($pB['photon']['drops'] < $pA['photon']['drops'] && $pA['photon']['drops'] < $pS['photon']['drops'], 'Challenge Photon Drops rise strictly with pace (B < A < S)');
check($pB['meseta'] < $pA['meseta'] && $pA['meseta'] < $pS['meseta'], 'Challenge Meseta rises strictly with pace (B < A < S)');

$prevBtPd = 0;
$prevBtMes = 0;
foreach (BOUNTY_TIERS as $btTier) {
    $bs = bounty_build_reward_spec_v2($btTier, $lowHunter, bounty_reward_area('ep1_pioneer2', 1), ['ignore_level' => true, 'mode_pool' => 'battle']);
    check($bs['photon']['drops'] > $prevBtPd && $bs['meseta'] > $prevBtMes, "Battle $btTier rewards strictly exceed lower win tier ({$bs['photon']['drops']} PDs, {$bs['meseta']} Meseta)");
    $prevBtPd = $bs['photon']['drops'];
    $prevBtMes = $bs['meseta'];
}

$sPaceC6 = bounty_challenge_pace_seconds(1, 6, 6, 'S');
$fastId = weekly_row($db, 'Gale', 'CHALLENGE_RUN', '1:6-6:' . bounty_challenge_pace_seconds(1, 6, 6, 'A') . ':A');
$fastMid = (int) $db->querySingle("SELECT mission_id FROM player_missions WHERE id = $fastId");
$baseSpec = [
    'version' => 2, 'tier' => 'rare', 'level' => 200,
    'items' => [['base' => '000500', 'cat' => 'Weapon', 'profile' => 'rare', 'attributes' => ['native' => 1, 'abeast' => 0, 'machine' => 0, 'dark' => 0]]],
    'photon' => ['spheres' => 0, 'drops' => 10, 'value' => 10], 'meseta' => 15000,
];
$db->exec("UPDATE missions SET reward_spec = '" . $db->escapeString(json_encode($baseSpec)) . "' WHERE id = $fastMid");
$fastKills = no_kills();
$fastKills['challenge_clears'] = [[1, 6, (int) round($sPaceC6 * 0.8)]];
check(bounty_evaluate_weekly($db, wrow($db, $fastId), client('Gale'), null, $fastKills, []), 'fast Challenge clear completes bounty');
$sf = bounty_apply_challenge_speed_bonus($db, $fastId, $fastMid, '1:6-6:' . bounty_challenge_pace_seconds(1, 6, 6, 'A') . ':A', 'rare');
check($sf !== null && $sf >= 1.20 && $sf <= 1.35, 'S-pace completion applies speed_factor bonus (' . var_export($sf, true) . ')');
$savedSpec = json_decode((string) $db->querySingle("SELECT reward_spec FROM missions WHERE id = $fastMid"), true);
check(!empty($savedSpec['speed_factor']) && (float) $savedSpec['speed_factor'] === (float) $sf, 'speed_factor persisted into missions.reward_spec');

// Bounties from before this update (no spec version) still roll, within the same limits
$legacy = ['tier' => 'mythic', 'difficulty' => 'Ultimate', 'items' => [['base' => '000500', 'cat' => 'Weapon', 'profile' => 'mythic']], 'meseta' => 1000];
for ($i = 0; $i < 200; $i++) {
    if (preg_match('/ (\d+)\/(\d+)\/(\d+)\/(\d+)(?:\/(\d+))?/', bounty_roll_reward_string($legacy), $mm)) {
        $attrs = array_map('intval', array_slice($mm, 1, 4));
        check(count(array_filter($attrs)) <= 2 && max($attrs) <= 90 && (int) ($mm[5] ?? 0) <= 50, 'legacy rolls keep the limits');
    }
}

// Dailies never ask to hold Meseta or use materials; play time counts from the start
$excluded = array_keys(array_filter(bounty_config()['excluded_goal_types']));
check(in_array('MESETA', $excluded, true) && count(preg_grep('/^MAT_/', $excluded)) === 7, 'Meseta and all seven material objectives are excluded');
$fresh = client('Pell', 60, ['Meseta' => 5000, 'PlayTimeSeconds' => 435600, 'NumHPMaterialsUsed' => 0, 'NumTPMaterialsUsed' => 0, 'NumPowerMaterialsUsed' => 0,
    'NumDefMaterialsUsed' => 0, 'NumMindMaterialsUsed' => 0, 'NumEvadeMaterialsUsed' => 0, 'NumLuckMaterialsUsed' => 0]);
$daily_row = ['id' => 0, 'mission_id' => 0, 'status' => 'queued', 'tier' => 'common', 'cadence' => 'daily', 'pool' => 'standard', 'meta' => null];
$seen = [];
for ($i = 0; $i < 800; $i++) {
    $o = bounty_pick_rework_objective($fresh, $daily_row, 0, []);
    $seen[$o['selected_goal']] = $o['selected_target_id'];
}
check(!array_intersect(array_keys($seen), array_merge($excluded, ['PLAYTIME'])), 'rework dailies offer no Meseta, material or total-play-time objective (' . implode(', ', array_keys($seen)) . ')');
check(($seen['PLAY_HOURS'] ?? null) === '3600:435600', 'play time is 1 hour counted from the play time at the start (' . ($seen['PLAY_HOURS'] ?? 'not offered') . ')');
check(html_entity_decode(strip_tags(getClearObjective('PLAY_HOURS', '3600:435600'))) === 'Play for 1 hour (counted from when the bounty starts)', 'the board says "Play for 1 hour"');
bounty_config(['excluded_goal_types' => ['MAT_EVADE' => false]]);
$again = false;
for ($i = 0; $i < 800 && !$again; $i++) {
    $again = bounty_pick_rework_objective($fresh, $daily_row, 0, [])['selected_goal'] === 'MAT_EVADE';
}
check($again, 'an excluded objective comes back when config sets it to false');
bounty_config(['excluded_goal_types' => ['MAT_EVADE' => true]]);
$pid = weekly_row($db, 'Pell', 'PLAY_HOURS', '3600:435600');
check(!bounty_evaluate_weekly($db, wrow($db, $pid), client('Pell', 60, ['PlayTimeSeconds' => 435600 + 1830]), null, no_kills(), []), 'half an hour of play is not done');
check(bounty_progress_text('PLAY_HOURS', '3600:435600', wrow($db, $pid)['progress']) === '30 min / 1 h played', 'the board shows the time played (' . bounty_progress_text('PLAY_HOURS', '3600:435600', wrow($db, $pid)['progress']) . ')');
check(!bounty_evaluate_weekly($db, wrow($db, $pid), client('Pell', 60, ['PlayTimeSeconds' => 435600 + 3599]), null, no_kills(), []), 'a second short of an hour is not done');
check(bounty_evaluate_weekly($db, wrow($db, $pid), client('Pell', 60, ['PlayTimeSeconds' => 435600 + 3600]), null, no_kills(), []), 'an hour of play since the start: done');
check(!bounty_evaluate_weekly($db, wrow($db, weekly_row($db, 'Pell', 'PLAY_HOURS', '3600:500000')), client('Pell', 60, ['PlayTimeSeconds' => 500000 + 600]), null, no_kills(), []), 'play time before the bounty started does not count');

// "Reach level N" only when the EXP still needed fits in a day's or a week's play
$lt = bounty_level_data()['exp_for_level'];
check(count($lt) === 200 && $lt[0] === 0 && $lt[1] === 50 && $lt[199] === 83227800, 'EXP table from newserv: 200 levels, 83,227,800 EXP at level 200');
$pk = bounty_level_data()['exp_per_kill'];
check($pk['Normal'] < $pk['Hard'] && $pk['Hard'] < $pk['Very Hard'] && $pk['Very Hard'] < $pk['Ultimate'], 'average EXP per enemy rises with difficulty');
$at = function ($lv, $extra = 0) use ($lt) {
    return client('Lex', $lv, ['EXP' => $lt[$lv - 1] + $extra]);
};
check(bounty_realistic_levels($at(30), 'daily') === 2 && bounty_realistic_levels($at(30), 'weekly') === 5, 'a level-30 hunter can gain 2 levels in a day and 5 in a week');
check(bounty_realistic_levels($at(150), 'daily') === 0, 'a level-150 hunter is not asked to level up in a day');
check(bounty_realistic_levels($at(190), 'weekly') === 0 && bounty_realistic_levels($at(199), 'weekly') === 0, 'nor a level-190 or 199 hunter in a week');
check(bounty_realistic_levels(client('Lex', 200, ['EXP' => $lt[199]]), 'weekly') === 0, 'nothing at level 200');
check(bounty_realistic_levels($at(150, $lt[150] - $lt[149] - 1000), 'daily') === 1, 'a hunter 1,000 EXP short of the next level can reach it in a day');
$budget = function ($lv, $cad) use ($pk) {
    $c = bounty_config()['level'];
    return $c['hours'][$cad] * $c['kills_per_hour'] * $pk[bounty_level_difficulty($lv)] * $c['exp_multiplier'];
};
foreach (['daily' => [60, 100, 112, 118, 130, 160], 'weekly' => [60, 120, 150, 166, 172, 190]] as $cad => $lvs) {
    foreach ($lvs as $lv) {
        $row = ['id' => 0, 'mission_id' => 0, 'status' => 'queued', 'tier' => 'uncommon', 'cadence' => $cad, 'pool' => 'standard', 'meta' => null];
        $offered = false;
        for ($i = 0; $i < 400; $i++) {
            $o = bounty_pick_rework_objective($at($lv), $row, 0, []);
            if ($o['selected_goal'] === 'LEVEL') {
                $offered = true;
                $need = $lt[(int) $o['selected_target_id'] - 1] - $lt[$lv - 1];
                check($need <= $budget($lv, $cad) && (int) $o['selected_target_id'] > $lv, "$cad level target from $lv to {$o['selected_target_id']} fits the budget ($need EXP)");
            }
        }
        $fits = $lt[$lv] - $lt[$lv - 1] <= $budget($lv, $cad);
        check($offered === $fits, "$cad level objective at level $lv is " . ($fits ? 'offered' : 'not offered') . ($offered ? ' (offered)' : ' (not offered)'));
    }
}

// The effort of a daily fits its tier: errands for Common, a boss or a timed area for Uncommon,
// a boss under time or with a team for Rare; Uncommon and Rare count only on a fitting difficulty
$want = ['common' => ['EXPLORATION', 'PATROL', 'ITEM', 'TECHNIQUE', 'PLAY_HOURS'], 'uncommon' => ['BOSS_ARENA', 'SPEEDRUN_FLOOR', 'LEVEL'],
         'rare' => ['SPEEDRUN_BOSS', 'MENTOR_BOSS', 'HARDCORE_MENTOR', 'DIVERSE_PARTY_BOSS']];
foreach ([25, 60, 122] as $lv) {
    $hunter = $at($lv);
    $hunter['CharClass'] = 'FOmarl';
    foreach ($want as $tier => $types) {
        $row = ['id' => 0, 'mission_id' => 0, 'status' => 'queued', 'tier' => $tier, 'cadence' => 'daily', 'pool' => 'standard', 'meta' => null];
        $got = [];
        $mins = [];
        for ($i = 0; $i < 300; $i++) {
            $o = bounty_pick_rework_objective($hunter, $row, 0, []);
            $got[$o['selected_goal']] = true;
            $mins[$o['min_difficulty'] ?? 'any'] = true;
            if ($o['min_difficulty']) {
                check($o['difficulty'] === $o['min_difficulty'], "a $tier daily's story difficulty is its minimum");
            }
        }
        $extra = array_diff(array_keys($got), $types);
        check(!$extra, "level $lv $tier dailies use only their tier's objectives (" . implode(', ', array_keys($got)) . ')');
        $expect = bounty_daily_min_difficulty($lv, $tier);
        check(array_keys($mins) === [$expect ?? 'any'] || ($tier === 'common' && array_keys($mins) === ['any'])
            || ($tier === 'uncommon' && !array_diff(array_keys($mins), [$expect ?? 'any', 'any'])), "level $lv $tier daily minimum difficulty is " . ($expect ?? 'any') . ' (' . implode(', ', array_keys($mins)) . ')');
    }
}
check(bounty_daily_min_difficulty(122, 'common') === null, 'Common dailies count on any difficulty');
check(bounty_daily_min_difficulty(122, 'rare') === 'Very Hard' && bounty_daily_min_difficulty(122, 'uncommon') === 'Hard', 'level 122: Rare on Very Hard, Uncommon on Hard');
check(bounty_daily_min_difficulty(45, 'rare') === 'Very Hard' && bounty_daily_min_difficulty(45, 'uncommon') === 'Hard', 'level 45: Rare on Very Hard, Uncommon on Hard');
check(bounty_daily_min_difficulty(25, 'rare') === 'Hard' && bounty_daily_min_difficulty(25, 'uncommon') === null, 'level 25: Rare on Hard, Uncommon anywhere');
check(bounty_daily_min_difficulty(10, 'rare') === null, 'level 10: no requirement (only Normal is open)');
$t = html_entity_decode(strip_tags(getClearObjective('BOSS_ARENA', '11', 'Hunt the Sil Dragon', '', 'Very Hard')), ENT_QUOTES);
check(substr($t, -strlen('(on Very Hard or higher)')) === '(on Very Hard or higher)', "the board adds the difficulty ($t)");
check(strpos(getClearObjective('BOSS_ARENA', '11', 'Hunt the Sil Dragon', '', 'Ultimate'), '(on Ultimate)') !== false, 'and says "on Ultimate" for Ultimate');
check(strpos(getClearObjective('ITEM', '000100', '', '', 'Hard'), 'or higher') === false, 'not on objectives that are not in an area');
check(bounty_meta_min_difficulty('{"min_difficulty":"Hard","x":1}') === 'Hard' && bounty_meta_min_difficulty(null) === null && bounty_meta_min_difficulty('{"min_difficulty":"Normal"}') === null, 'the minimum is read back from missions.meta');
// Activating a Rare daily stores its minimum in missions.meta
ob_start();
$rid = bounty_earn($db, 9001, 'Rook', $at(100) + ['Name' => 'Rook'], 'standard', 'daily', 'rare', 'login');
ob_end_clean();
$rook = $at(100);
$rook['Name'] = 'Rook';
tick($db, $rook);
$rm = $db->querySingle("SELECT m.meta FROM player_missions pm JOIN missions m ON pm.mission_id = m.id WHERE pm.id = $rid");
check(bounty_meta_min_difficulty($rm) === 'Very Hard', "an activated Rare daily keeps its minimum in meta ($rm)");

// Battle dailies never carry a minimum difficulty (their objective has no area)
$brow = ['id' => 0, 'mission_id' => 0, 'status' => 'queued', 'tier' => 'rare', 'cadence' => 'daily', 'pool' => 'battle', 'meta' => null];
$bmins = [];
for ($i = 0; $i < 100; $i++) {
    $bmins[bounty_pick_rework_objective($at(100), $brow, 0, [])['min_difficulty'] ?? 'none'] = true;
}
check(array_keys($bmins) === ['none'], 'Battle dailies have no minimum difficulty (' . implode(', ', array_keys($bmins)) . ')');

// Ultra Rare and Mythic Rare weeklies are hunts, never an area visit or a few levels
foreach (['ultra', 'mythic'] as $tier) {
    $row = ['id' => 0, 'mission_id' => 0, 'status' => 'queued', 'tier' => $tier, 'cadence' => 'weekly', 'pool' => 'drop', 'meta' => null];
    $got = [];
    for ($i = 0; $i < 300; $i++) {
        $o = bounty_pick_rework_objective($at(30) + ['EnemyKillCount' => 100, 'RareEnemyKillCount' => 0], $row, 0, []);
        $got[$o['selected_goal']] = true;
        if ($o['selected_goal'] === 'ENEMY_HUNT') {
            check((int) $o['selected_target_id'] >= ($tier === 'mythic' ? 900 : 600), "$tier enemy hunts ask for at least an hour or two of kills ({$o['selected_target_id']})");
        }
        if ($o['selected_goal'] === 'RARE_HUNT' && $tier === 'mythic') {
            check((int) $o['selected_target_id'] >= 2, "mythic rare hunts ask for at least 2 rare enemies ({$o['selected_target_id']})");
        }
    }
    check(!array_diff(array_keys($got), ['BOSS_HUNT', 'ENEMY_HUNT', 'RARE_HUNT']) && isset($got['RARE_HUNT']), "$tier weeklies are boss, enemy and rare hunts (" . implode(', ', array_keys($got)) . ')');
}

// ---------------------------------------------------------------------------
// 9. Contracts: a share of Ultra/Mythic drops, 3 held, never expire; runs, hunts, party shares
// ---------------------------------------------------------------------------
$db->exec("INSERT INTO users (username, email, account_id, language) VALUES ('t2', 't2@x', 9002, 'en'), ('t3', 't3@x', 9003, 'en'), ('t4', 't4@x', 9004, 'en')");
bounty_config(['drops' => ['rare_enemy_chance' => ['Ultimate' => 100], 'boss_chance' => ['Ultimate' => 100], 'tier_weights' => ['Ultimate' => [0, 0, 0, 0, 100]]], 'contracts' => ['drop_share' => 100]]);
$solo4 = ['is_game' => true, 'episode' => 4, 'difficulty' => 'Ultimate', 'mode' => 'Solo', 'players' => 1];
$ct = client('Corvid', 150, ['LocationFloor' => 6, 'SectionID' => 'Pinkal', 'CharClass' => 'FOmarl', 'RareEnemyKillCount' => 0]);
$got = [];
ob_start();
for ($i = 0; $i < 400 && count($got) < 6; $i++) {
    $d = bounty_try_drop($db, 9001, 'Corvid', $ct, $solo4, 'boss', 'a boss', 9);
    if ($d) $got[] = $d;
}
ob_end_clean();
$contracts = rows($db, 'Corvid', "m.pool = 'contract'");
check(count($contracts) === 3, 'contracts stop at 3 held (got ' . count($contracts) . ')');
foreach ($contracts as $r) {
    check(in_array($r['tier'], ['ultra', 'mythic'], true) && $r['cadence'] === 'contract', "contract #{$r['id']} is Ultra or Mythic Rare");
    check($r['expires_at'] === null && $r['period_key'] === null, "contract #{$r['id']} never expires and belongs to no reset");
}
$after3 = array_slice($got, 3);
check($after3 && !array_filter($after3, function ($d) { return $d['pool'] === 'contract'; }), 'with 3 contracts held, a contract roll stays a normal drop');
foreach ($after3 as $d) {
    if (in_array($d['tier'], ['ultra', 'mythic'], true)) check($d['cadence'] === 'weekly', 'such a drop is a normal weekly');
}
// The drop slots full: contracts still drop; contract slots full too: nothing
$db->exec("UPDATE player_missions SET status = 'abandoned' WHERE id = " . (int) $contracts[0]['id']);
$db->exec("UPDATE player_missions SET status = 'queued' WHERE id IN (SELECT pm.id FROM player_missions pm JOIN missions m ON pm.mission_id = m.id WHERE pm.character_name = 'Corvid' AND m.pool = 'drop')");
for ($i = 0; bounty_count($db, 9001, 'Corvid', "m.pool = 'drop' AND pm.status = 'queued'") < 10 && $i < 50; $i++) {
    ob_start(); bounty_earn($db, 9001, 'Corvid', $ct, 'drop', 'daily', 'common', 'boss'); ob_end_clean();
}
$n_drop = count(rows($db, 'Corvid', "m.pool = 'drop'"));
ob_start();
$d = null;
for ($i = 0; $i < 400 && !$d; $i++) {
    $d = bounty_try_drop($db, 9001, 'Corvid', $ct, $solo4, 'boss', 'a boss', 9);
}
$more = bounty_try_drop($db, 9001, 'Corvid', $ct, $solo4, 'boss', 'a boss', 9);
ob_end_clean();
check($d && $d['pool'] === 'contract' && count(rows($db, 'Corvid', "m.pool = 'drop'")) === $n_drop, 'with 10 drops held, a contract can still drop');
check($more === null, 'with 10 drops and 3 contracts held, nothing drops');
// The share of Ultra/Mythic drops that are contracts, by sampling (25%)
bounty_config(['contracts' => ['drop_share' => $defaults['contracts']['drop_share']]]);
$pools = ['drop' => 0, 'contract' => 0];
ob_start();
for ($i = 0; $i < 400; $i++) {
    $db->exec("UPDATE player_missions SET status = 'abandoned' WHERE character_name = 'Corvid'");
    $d = bounty_try_drop($db, 9001, 'Corvid', $ct, $solo4, 'boss', 'a boss', 9);
    $pools[$d['pool']]++;
}
ob_end_clean();
check($pools['contract'] >= 60 && $pools['contract'] <= 140, 'about a quarter of Mythic Rare drops are contracts (' . $pools['contract'] . ' of 400)');
bounty_config(['drops' => $defaults['drops']]);
$db->exec("UPDATE player_missions SET status = 'abandoned' WHERE character_name = 'Corvid'");

// Activation: contracts start at once (own slots), objective tied to the episode they dropped in
$ids = array_column(rows($db, 'Corvid', "m.pool = 'contract' AND pm.status = 'abandoned'"), 'id');
$e4 = [];
for ($i = 0; $i < 3; $i++) {
    ob_start(); $e4[] = bounty_earn($db, 9001, 'Corvid', $ct, 'contract', 'contract', $i ? 'mythic' : 'ultra', 'boss', ['episode' => 4, 'area' => 'ep4_desert']); ob_end_clean();
}
for ($i = 0; $i < 4; $i++) {
    tick($db, $ct, $solo4);
}
$act = rows($db, 'Corvid', "m.pool = 'contract' AND pm.status = 'in_progress'");
check(count($act) === 3, '3 contracts active at once (no waiting)');
foreach ($act as $r) {
    check(in_array($r['goal_type'], ['EPISODE_RUN', 'RARE_TARGET'], true), "contract #{$r['id']} is a run or a hunt ({$r['goal_type']})");
    $ep = ($r['goal_type'] === 'EPISODE_RUN') ? bounty_parse_run_target($r['goal_target'])['episode'] : bounty_parse_hunt_target($r['goal_target'])['episode'];
    check($ep === 4, "contract #{$r['id']} is set in Episode 4, where it dropped");
    $spec = json_decode($r['reward_spec'], true);
    check(is_array($spec) && count($spec['items']) >= 1 && $r['expires_at'] === null, "contract #{$r['id']} has its reward and no expiry");
    $code = strtoupper(substr($spec['items'][0]['base'], 0, 6));
    $it = bounty_item_data()[$code] ?? [];
    check(in_array('Ep4', $it['drop_episodes'] ?? [], true), "contract #{$r['id']}'s item drops in Episode 4 ({$it['name']})");
}
bounty_expire_sweep($db, time() + 86400 * 400);
check(count(rows($db, 'Corvid', "m.pool = 'contract' AND pm.status = 'in_progress'")) === 3, 'contracts survive every reset');
$sum = bounty_board_summary($db, 9001)['Corvid'] ?? null;
check($sum && $sum['contract'][0] === 3, 'the slot panel counts 3 contracts held');
check(bounty_kind_label('contract', 'contract') === 'Contract' && bounty_kind_label('share', 'share') === 'Contract share', 'kind labels');

// Objectives by tier: Ultra = mixed-party run or Ultra hunt; Mythic = carry run, timed run or Mythic hunt
$seen = ['ultra' => [], 'mythic' => []];
$hunts = ['ultra' => [], 'mythic' => []];
foreach ([1, 2, 4] as $ep) {
    foreach (['ultra', 'mythic'] as $tier) {
        for ($i = 0; $i < 60; $i++) {
            $c = bounty_pick_contract_objective($ct, ['meta' => json_encode(['episode' => $ep])], $tier);
            $key = $c['goal'] === 'EPISODE_RUN' ? 'run:' . bounty_parse_run_target($c['target'])['variant'] : 'hunt';
            $seen[$tier][$key] = true;
            if ($c['goal'] === 'RARE_TARGET') {
                $h = bounty_parse_hunt_target($c['target']);
                $hunts[$tier][$h['target']] = $h['count'];
                check(isset(bounty_rare_enemy_data()[$h['target']]['episodes']['Ep' . $ep]), "{$h['target']} is met in Episode $ep");
            }
            check(($c['difficulty'] === 'Ultimate') === ($tier === 'mythic'), "a level 150 $tier contract is on " . ($tier === 'mythic' ? 'Ultimate' : 'Very Hard'));
            if ($c['goal'] === 'EPISODE_RUN') {
                // Told from the episode's deepest area, which knows every area the run crosses
                check($c['setting_id'] === ['1' => 'ep1_ruins', '2' => 'ep2_seabed', '4' => 'ep4_desert'][(string) $ep], "an Episode $ep run is told from its deepest area ({$c['setting_id']})");
                check(strpos($c['location_override'], 'Dark Falz') === false && strpos($c['location_override'], 'Vol Opt') === false, 'a run names no guardian its card keeps hidden');
            }
        }
    }
}
ksort($seen['ultra']); ksort($seen['mythic']);
check(array_keys($seen['ultra']) === ['hunt', 'run:diverse'], 'Ultra Rare contracts: mixed-party runs and hunts (' . implode(', ', array_keys($seen['ultra'])) . ')');
check(array_keys($seen['mythic']) === ['hunt', 'run:carry', 'run:timed'], 'Mythic Rare contracts: carry runs, timed runs and hunts (' . implode(', ', array_keys($seen['mythic'])) . ')');
check(($hunts['mythic']['KONDRIEU'] ?? 0) === 3 && ($hunts['mythic']['HILDEBLUE'] ?? 0) === 1 && ($hunts['ultra']['DEL_RAPPY'] ?? 0) === 3 && ($hunts['ultra']['NAR_LILY'] ?? 0) === 1,
      'hunt counts by effort: 3 Kondrieus, 1 Hildeblue, 3 Del Rappies, 1 Nar Lily (' . json_encode($hunts) . ')');
check(!array_intersect_key($hunts['ultra'], array_flip(['KONDRIEU', 'HILDEBLUE', 'PAZUZU', 'DORPHON_ECLAIR', 'AL_RAPPY'])), 'Ultra hunts never ask for a Mythic creature');
$nopatch = $ct;
unset($nopatch['RareEnemyKillCount']);
$any_hunt = false;
for ($i = 0; $i < 60; $i++) {
    $any_hunt = $any_hunt || bounty_pick_contract_objective($nopatch, ['meta' => json_encode(['episode' => 4])], 'mythic')['goal'] === 'RARE_TARGET';
}
check(!$any_hunt, 'no hunts without the newserv kill patch');

// Reward pools: a Mythic run pays from the episode's items rarer than 1/75,000 (Solferino among Ep4's);
// a hunt from the creature's own rare drops
$rc = bounty_reward_cfg();
$ctx4 = ['section' => bounty_section_index('Pinkal'), 'class' => null, 'level' => 200, 'area' => bounty_contract_reward_area(4), 'max_area' => 1];
$run4 = bounty_reward_pool('contract_run', $ctx4)['items'];
$sol = array_search('SOLFERINO', array_map(function ($it) { return $it['name']; }, bounty_item_data()), true);
check($sol && isset($run4[$sol]), 'Solferino (an Episode 4 drop no bounty could pay) is in the Episode 4 Mythic run pool');
$bad = 0;
foreach ($run4 as $code => $r) {
    $it = bounty_item_data()[$code];
    if (!in_array('Ep4', $it['drop_episodes'], true) || bounty_item_average_rate($it) >= $rc['challenge_reserve_max_rate']) $bad++;
}
check(count($run4) >= 10 && $bad === 0, 'the Episode 4 Mythic run pool: ' . count($run4) . ' Episode 4 items, all rarer than 1/75,000');
$kitems = bounty_rare_enemy_data()['KONDRIEU']['episodes']['Ep4']['items'];
$kpool = bounty_reward_pool('creature', $ctx4 + ['creature_items' => $kitems])['items'];
check($kpool && !array_diff(array_keys($kpool), $kitems), 'a Kondrieu hunt pays only Kondrieu drops (' . count($kpool) . ')');
foreach ($kpool as $code => $r) {
    $it = bounty_item_data()[$code];
    check(empty($it['common_drop']) && bounty_item_average_rate($it) < $rc['rare_max_rate'], "{$it['name']} is a rare Kondrieu drop");
}

// Runs: every area and boss in one game; carry and mixed-party checks at each boss; timed runs
function contract_row($db, $acc, $char, $tier, $goal, $target)
{
    ob_start();
    $id = bounty_earn($db, $acc, $char, ['Name' => $char], 'contract', 'contract', $tier, 'boss', ['episode' => 4]);
    ob_end_clean();
    $mid = (int) $db->querySingle("SELECT mission_id FROM player_missions WHERE id = $id");
    $db->exec("UPDATE missions SET goal_type = '$goal', goal_target = '$target', title = 'Test contract' WHERE id = $mid");
    $db->exec("UPDATE player_missions SET status = 'in_progress', activated_at = " . (time() - 100) . " WHERE id = $id");
    return ['id' => $id, 'mission_id' => $mid, 'goal_type' => $goal, 'goal_target' => $target, 'tier' => $tier, 'progress' => '{}',
            'activated_at' => time() - 100, 'mission_title' => 'Test contract', 'meta' => json_encode(['episode' => 4])];
}
function member($acc, $name, $class, $join, $lobby = 77)
{
    return ['key' => "{$acc}_$name", 'acc' => $acc, 'name' => $name, 'class' => $class, 'join_level' => $join,
            'client' => client($name, $join, ['Account' => ['AccountID' => $acc], 'CharClass' => $class, 'LobbyID' => $lobby, 'SectionID' => 'Oran'])];
}
$run_lobby = ['is_game' => true, 'episode' => 4, 'difficulty' => 'Ultimate', 'mode' => 'Normal', 'players' => 4];
$walk = function ($db, &$m, $holder, $members, $floors, $boss_times, $lobby_info, $start = null) {
    $done = false;
    $p = null;
    foreach ($floors as $f) {
        $holder['LocationFloor'] = $f;
        $k = no_kills();
        $done = bounty_evaluate_contract($db, $m, $holder, $lobby_info, $k, ['key' => '9001_' . $holder['Name'], 'members' => [77 => $members], 'lobby_rares' => []], $p);
        $m['progress'] = json_encode($p);
        if ($start !== null && !empty($p['started']) && $p['started'] > $start) {
            $p['started'] = $start; // pretend the run began then
            $m['progress'] = json_encode($p);
        }
    }
    foreach ($boss_times as $t) {
        $k = no_kills();
        $k['boss_events'] = [['floor' => 9, 'time' => $t, 'entry_time' => $t - 60, 'lobby_id' => 77, 'episode' => 4]];
        $done = bounty_evaluate_contract($db, $m, $holder, $lobby_info, $k, ['key' => '9001_' . $holder['Name'], 'members' => [77 => $members], 'lobby_rares' => []], $p);
        $m['progress'] = json_encode($p);
    }
    return [$done, $p];
};
$holder = client('Hale', 120, ['LobbyID' => 77, 'CharClass' => 'HUcast']);
$carried = [member(9001, 'Hale', 'HUcast', 120), member(9002, 'Kit', 'RAmar', 100), member(9003, 'Lux', 'FOnewm', 105), member(9004, 'Moss', 'HUmar', 110)];
$m = contract_row($db, 9001, 'Hale', 'mythic', 'EPISODE_RUN', '4:carry:0@Ultimate');
list($done, $p) = $walk($db, $m, $holder, $carried, range(1, 7), [time()], $run_lobby);
check(!$done && count($p['floors']) === 7, 'a run is not done with an area left (7/8 areas)');
list($done, $p) = $walk($db, $m, $holder, $carried, [8], [], $run_lobby);
check($done, 'a carry run is done: every area, the boss, 3 hunters 10+ levels below at the boss');
check(strpos(bounty_progress_text('EPISODE_RUN', $m['goal_target'], json_encode($p)), '8 / 8 areas, 1 / 1 boss') !== false, 'run progress text');
$before = (int) $db->querySingle("SELECT COUNT(*) FROM player_missions WHERE status = 'ready_to_redeem'");
ob_start();
$paid = bounty_contract_pay_shares($db, $m, $holder, $p, $carried);
ob_end_clean();
check($paid === 3, 'the 3 other players each get a share (got ' . $paid . ')');
$shares = [];
$r = $db->query("SELECT pm.account_id, pm.character_name, pm.status, pm.expires_at, m.tier, m.pool, m.cadence, m.goal_type, m.reward_spec FROM player_missions pm JOIN missions m ON pm.mission_id = m.id WHERE m.pool = 'share'");
while ($x = $r->fetchArray(SQLITE3_ASSOC)) $shares[] = $x;
check(count($shares) === 3 && !in_array(9001, array_column($shares, 'account_id')), 'no share for the holder');
foreach ($shares as $x) {
    $spec = json_decode($x['reward_spec'], true);
    check($x['status'] === 'ready_to_redeem' && $x['tier'] === 'ultra' && $x['goal_type'] === 'CONTRACT_SHARE' && $x['expires_at'] === null, "{$x['character_name']}'s share is an Ultra Rare reward, ready to claim");
    check((int) $spec['level'] === ['Kit' => 100, 'Lux' => 105, 'Moss' => 110][$x['character_name']], "{$x['character_name']}'s share is built for their level");
}
// Not enough carried: a boss with only 2 far below fails the game
$two = [member(9001, 'Hale', 'HUcast', 120), member(9002, 'Kit', 'RAmar', 100), member(9003, 'Lux', 'FOnewm', 112), member(9004, 'Moss', 'HUmar', 105)];
$m = contract_row($db, 9001, 'Hale', 'mythic', 'EPISODE_RUN', '4:carry:0@Ultimate');
list($done, $p) = $walk($db, $m, $holder, $two, range(1, 8), [time()], $run_lobby);
check(!$done && !empty($p['missed']), 'a boss with only 2 players 10+ levels below fails the carry for that game');
check(strpos(bounty_progress_text('EPISODE_RUN', $m['goal_target'], json_encode($p)), 'start a new game') !== false, 'progress says to start a new game');
// A new game starts the run over
$holder2 = $holder;
$holder2['LobbyID'] = 78;
$holder2['LocationFloor'] = 1;
bounty_evaluate_contract($db, $m, $holder2, $run_lobby, no_kills(), ['key' => '9001_Hale', 'members' => [], 'lobby_rares' => []], $p);
check(($p['lobby'] ?? null) === 78 && $p['floors'] === [1] && empty($p['missed']) && !$p['bosses'], 'joining a new game starts the run over');
// An easier game doesn't count
$m = contract_row($db, 9001, 'Hale', 'mythic', 'EPISODE_RUN', '4:carry:0@Ultimate');
list($done, $p) = $walk($db, $m, $holder, $carried, range(1, 8), [time()], ['difficulty' => 'Very Hard'] + $run_lobby);
check(!$done && empty($p['floors']), 'a Very Hard game does not count for an Ultimate run');
// Another episode's boss events don't count
$m = contract_row($db, 9001, 'Hale', 'mythic', 'EPISODE_RUN', '4:carry:0@Ultimate');
list($done, $p) = $walk($db, $m, $holder, $carried, range(1, 8), [], $run_lobby);
$k = no_kills();
$k['boss_events'] = [['floor' => 9, 'time' => time(), 'lobby_id' => 77, 'episode' => 1]];
$done = bounty_evaluate_contract($db, $m, $holder + ['LocationFloor' => 8], $run_lobby, $k, ['key' => '9001_Hale', 'members' => [77 => $carried], 'lobby_rares' => []], $p);
check(!$done && !$p['bosses'], 'a floor-9 kill in an Episode 1 room is not the Episode 4 boss');
// Mixed party
$mixed = [member(9001, 'Hale', 'HUcast', 120), member(9002, 'Kit', 'RAmar', 118), member(9003, 'Lux', 'FOnewm', 121)];
$no_force = [member(9001, 'Hale', 'HUcast', 120), member(9002, 'Kit', 'RAmar', 118), member(9004, 'Moss', 'HUmar', 119)];
$m = contract_row($db, 9001, 'Hale', 'ultra', 'EPISODE_RUN', '4:diverse:0@Very Hard');
list($done) = $walk($db, $m, $holder, $mixed, range(1, 8), [time()], $run_lobby);
check($done, 'a mixed-party run is done with a Hunter, a Ranger and a Force at the boss');
$m = contract_row($db, 9001, 'Hale', 'ultra', 'EPISODE_RUN', '4:diverse:0@Very Hard');
list($done, $p) = $walk($db, $m, $holder, $no_force, range(1, 8), [time()], $run_lobby);
check(!$done && !empty($p['missed']), 'no Force at the boss: the mixed-party run fails for that game');
// Timed
$m = contract_row($db, 9001, 'Hale', 'mythic', 'EPISODE_RUN', '4:timed:90@Ultimate');
list($done) = $walk($db, $m, $holder, $carried, range(1, 8), [time()], $run_lobby, time() - 80 * 60);
check($done, 'a timed run done in 80 minutes (limit 90) counts');
$m = contract_row($db, 9001, 'Hale', 'mythic', 'EPISODE_RUN', '4:timed:90@Ultimate');
list($done, $p) = $walk($db, $m, $holder, $carried, range(1, 8), [time()], $run_lobby, time() - 95 * 60);
check(!$done && !empty($p['late']), 'a timed run over 90 minutes does not');
// A boss a teammate killed counts for the run even if the holder wasn't in the arena
$m = contract_row($db, 9001, 'Hale', 'ultra', 'EPISODE_RUN', '4:diverse:0@Very Hard');
list($done, $p) = $walk($db, $m, $holder, $mixed, range(1, 8), [], $run_lobby);
$done = bounty_evaluate_contract($db, $m, $holder + ['LocationFloor' => 8], $run_lobby, no_kills(),
    ['key' => '9001_Hale', 'members' => [77 => $mixed], 'lobby_rares' => [], 'lobby_boss_events' => [77 => [['floor' => 9, 'time' => time(), 'lobby_id' => 77, 'episode' => 4, 'by' => '9002_Kit']]]], $p);
check($done && $p['bosses'] === [9], 'a teammate\'s boss kill in the run\'s game counts for the run');
// A share needs half the run's bosses (Episode 1: 2 of 4)
$m = contract_row($db, 9001, 'Hale', 'ultra', 'EPISODE_RUN', '1:diverse:0@Very Hard');
$late_joiner = member(9004, 'Moss', 'HUmar', 119);
$pp = ['seen' => ['9002_Kit' => [11, 12, 13, 14], '9003_Lux' => [13, 14], '9004_Moss' => [14]]];
ob_start();
$paid = bounty_contract_pay_shares($db, $m, $holder, $pp, [member(9001, 'Hale', 'HUcast', 120), member(9002, 'Kit', 'RAmar', 118), member(9003, 'Lux', 'FOnewm', 121), $late_joiner]);
ob_end_clean();
check($paid === 2, 'a share needs at least half the run\'s bosses (2 of 4): Kit and Lux, not Moss');
check($db->querySingle("SELECT m.tier FROM player_missions pm JOIN missions m ON pm.mission_id = m.id WHERE m.pool = 'share' ORDER BY pm.id DESC LIMIT 1") === 'rare', 'an Ultra Rare contract\'s shares are Rare');

// Hunts: a kill by anyone in the game counts; other games, other episodes and easier games don't
$m = contract_row($db, 9001, 'Hale', 'mythic', 'RARE_TARGET', 'KONDRIEU:3:4@Ultimate');
$hunt = function ($db, &$m, $lobby_info, $rares, $lobby_id = 77) {
    $p = null;
    $h = client('Hale', 120, ['LobbyID' => $lobby_id]);
    $done = bounty_evaluate_contract($db, $m, $h, $lobby_info, no_kills(), ['key' => '9001_Hale', 'members' => [], 'lobby_rares' => $rares], $p);
    $m['progress'] = json_encode($p);
    return [$done, $p];
};
$hunt($db, $m, $run_lobby, [77 => ['KONDRIEU' => 1]]);
$hunt($db, $m, $run_lobby, [77 => ['KONDRIEU_SPINNER' => 1, 'DEL_RAPPY_DESERT' => 2]]);
$hunt($db, $m, $run_lobby, [78 => ['KONDRIEU' => 1]]);
list($done, $p) = $hunt($db, $m, ['difficulty' => 'Very Hard'] + $run_lobby, [77 => ['KONDRIEU' => 1]]);
check(!$done && (int) $p['count'] === 2, 'Kondrieu kills in your game count (its spinner too); other games, other creatures and easier games do not');
check(bounty_progress_text('RARE_TARGET', $m['goal_target'], json_encode($p)) === '2 / 3 Kondrieus', 'hunt progress text');
list($done) = $hunt($db, $m, $run_lobby, [77 => ['KONDRIEU' => 1]]);
check($done, 'the third Kondrieu finishes the hunt');
$m = contract_row($db, 9001, 'Hale', 'ultra', 'RARE_TARGET', 'DEL_RAPPY:3:4@Very Hard');
$hunt($db, $m, $run_lobby, [77 => ['DEL_RAPPY_CRATER' => 2]]);
list($done, $p) = $hunt($db, $m, ['episode' => 2] + $run_lobby, [77 => ['DEL_RAPPY_DESERT' => 1]]);
check(!$done && (int) $p['count'] === 2, 'Del Rappies of the Crater count; a kill in another episode\'s game does not');

// Wording
foreach (['BOSS_HUNT' => 'Defeat 5 bosses on Very Hard or higher', 'ENEMY_HUNT' => 'Defeat 480 enemies on Very Hard or higher', 'RARE_HUNT' => 'Defeat 1 rare enemy on Very Hard or higher'] as $g => $want) {
    $t = bounty_weekly_objective_text($g, ($g === 'BOSS_HUNT' ? '5' : ($g === 'ENEMY_HUNT' ? '480' : '1')) . '@Very Hard');
    check($t === "$want (a kill by anyone in your game counts)", "weekly $g says the whole game's kills count ($t)");
}
check(bounty_weekly_objective_text('AREA_SWEEP', '2@Hard') === 'Visit every area of Episode 2 on Hard or higher', 'an area sweep has no kill note');
check(bounty_episode_setting(1) === 'ep1_ruins' && bounty_episode_setting(2) === 'ep2_seabed' && bounty_episode_setting(4) === 'ep4_desert', 'whole-episode jobs use the deepest area\'s card');
$sweeps = [];
for ($i = 0; $i < 400 && count($sweeps) < 3; $i++) {
    $o = bounty_pick_rework_objective($ct, ['id' => 0, 'mission_id' => 0, 'status' => 'queued', 'tier' => 'common', 'cadence' => 'weekly', 'pool' => 'standard', 'meta' => null], 0, []);
    if ($o['selected_goal'] === 'AREA_SWEEP') {
        $sweeps[$o['mission_episode']] = $o['setting_id'];
    }
}
ksort($sweeps);
check($sweeps === [1 => 'ep1_ruins', 2 => 'ep2_seabed', 4 => 'ep4_desert'], 'an area sweep is told from its episode\'s deepest area (' . json_encode($sweeps) . ')');
check(bounty_contract_objective_text('RARE_TARGET', 'KONDRIEU:3:4@Ultimate') === 'Defeat 3 Kondrieus in Episode 4 on Ultimate (a kill by anyone in your game counts)', 'hunt wording');
check(bounty_contract_objective_text('RARE_TARGET', 'AL_RAPPY:1:1@Ultimate') === 'Defeat an Al Rappy in Episode 1 on Ultimate (a kill by anyone in your game counts)', 'hunt wording (an)');
check(strpos(bounty_contract_objective_text('EPISODE_RUN', '1:timed:150@Very Hard'), 'Full Episode 1 run in one game on Very Hard or higher: every area and all 4 bosses, within 2 h 30 min') === 0, 'timed run wording');
check(strpos(strip_tags(getClearObjective('EPISODE_RUN', '2:diverse:0@Hard')), '[Contract] Full Episode 2 run') === 0, 'getClearObjective tags contracts');
$db->exec("UPDATE player_missions SET status = 'abandoned' WHERE character_name IN ('Corvid', 'Hale')");

// The board names items by newserv's name where item_map.json's last name for a code is wrong
$names = ['031200' => 'Weapons Bronze Badge', '031201' => 'Weapons Silver Badge', '031202' => 'Weapons Gold Badge', '01024B' => 'Assist Barrier',
          '008A00' => 'Sange', '010223' => 'Regene Gear Adv.', '031209' => 'Item Ticket', '031003' => 'Secret Ticket', '031004' => 'Photon Ticket',
          '031002' => 'Photon Crystal', '030D00' => "Sorcerer's Right Arm", '030900' => 'Scape Doll', '001001' => 'Agito (1975)'];
foreach ($names as $code => $want) {
    $got = html_entity_decode(renderRewardString($code), ENT_QUOTES);
    check(strcasecmp($got, $want) === 0, "the board names $code \"$want\" (got \"$got\")");
}

// Boss arenas are read per episode: floor 9 is Ruins 2 / the Seaside outside Episode 4, 11 the lower Seabed in Episode 2
check(bounty_boss_arena_floors(1) === [11, 12, 13, 14] && bounty_boss_arena_floors(2) === [12, 13, 14, 15] && bounty_boss_arena_floors(4) === [9], 'boss arena floors per episode');
check(!in_array(9, bounty_boss_arena_floors(1)) && !in_array(9, bounty_boss_arena_floors(2)) && !in_array(11, bounty_boss_arena_floors(2)), 'Ruins 2, the Seaside and the lower Seabed are not boss arenas');
check(!array_intersect(bounty_boss_arena_floors(1), bounty_episode_floors(1)) && !array_intersect(bounty_boss_arena_floors(2), bounty_episode_floors(2)) && !array_intersect(bounty_boss_arena_floors(4), bounty_episode_floors(4)), 'no field floor is also a boss arena in its episode');

// Multi-episode daily floor objectives (EXPLORATION, PATROL, SPEEDRUN_FLOOR across Ep1/Ep2/Ep4)
$ftLegacy = parse_floor_target('7');
$ftLegacySr = parse_floor_target('7_900');
$ftEp2 = parse_floor_target('5@2');
$ftEp4Sr = parse_floor_target('7@4_1200');
check($ftLegacy['episode'] === 1 && $ftLegacy['floor'] === 7 && $ftLegacySr['time_limit'] === 900, 'parse_floor_target preserves bare Ep1 legacy targets');
check($ftEp2['episode'] === 2 && $ftEp2['floor'] === 5 && $ftEp4Sr['episode'] === 4 && $ftEp4Sr['floor'] === 7 && $ftEp4Sr['time_limit'] === 1200, 'parse_floor_target parses Ep2/Ep4 qualified floor targets');
check(strpos(strip_tags(getClearObjective('EXPLORATION', '5@2')), '[Ep 2] Explore the Central Control Area') === 0, 'getClearObjective renders Ep2 EXPLORATION');
check(strpos(strip_tags(getClearObjective('PATROL', '6@4')), '[Ep 4] Survive and patrol the Subterranean Desert 1 for 10 minutes') === 0, 'getClearObjective renders Ep4 PATROL');
check(strpos(strip_tags(getClearObjective('SPEEDRUN_FLOOR', '11@2_900')), '[Ep 2] Clear Seabed Lower in under 15 minutes') === 0, 'getClearObjective renders Ep2 SPEEDRUN_FLOOR');
check(in_array(12, pso_speedrun_valid_next_floors(2, 5), true) && in_array(13, pso_speedrun_valid_next_floors(2, 11), true), 'Ep2 speedrun transitions map CCA->Gal Gryphon and Seabed Lower->Olga Flow');
check(in_array(5, pso_speedrun_valid_next_floors(4, 1), true) && in_array(9, pso_speedrun_valid_next_floors(4, 8), true), 'Ep4 speedrun transitions map Crater rim->Interior and Desert 3->Saint-Milion');
$epSeenFloor = ['EXPLORATION' => [], 'SPEEDRUN_FLOOR' => []];
$settingSeenFloor = [];
for ($i = 0; $i < 240; $i++) {
    foreach (['EXPLORATION' => ['only' => ['EXPLORATION']], 'SPEEDRUN_FLOOR' => ['only' => ['SPEEDRUN_FLOOR']]] as $fg => $fopts) {
        $picked = bounty_pick_objective($at(60), 0, [], $fopts);
        $epSeenFloor[$fg][$picked['mission_episode']] = true;
        $settingSeenFloor[$picked['setting_id']] = true;
    }
}
check(isset($epSeenFloor['EXPLORATION'][1], $epSeenFloor['EXPLORATION'][2], $epSeenFloor['EXPLORATION'][4]), 'daily EXPLORATION picks across Episodes 1, 2, and 4');
check(isset($epSeenFloor['SPEEDRUN_FLOOR'][1], $epSeenFloor['SPEEDRUN_FLOOR'][2], $epSeenFloor['SPEEDRUN_FLOOR'][4]), 'daily SPEEDRUN_FLOOR picks across Episodes 1, 2, and 4');
check(isset($settingSeenFloor['ep2_seabed'], $settingSeenFloor['ep4_desert']), 'daily floor objectives bind Ep2 and Ep4 lore/reward settings');

// ---------------------------------------------------------------------------
// Pre-rework 30-day legacy grace + unsealable weapon attribute limits
// ---------------------------------------------------------------------------
$db->exec("INSERT INTO missions (title, description, goal_type, goal_target, reward_item_string) VALUES
    ('Legacy Active Job', 'Pre-rework active bounty', 'BOSS_ARENA', '11@1', '1000 Meseta'),
    ('Legacy Ready Job', 'Pre-rework claimable bounty', 'BOSS_ARENA', '11@1', '1000 Meseta')");
$legacyReadyMid = $db->lastInsertRowID();
$legacyActiveMid = $legacyReadyMid - 1;
$db->exec("INSERT INTO player_missions (account_id, character_name, mission_id, status) VALUES
    (9001, 'LegacyOne', {$legacyActiveMid}, 'in_progress'),
    (9001, 'LegacyOne', {$legacyReadyMid}, 'ready_to_redeem')");
$legacyReadyPm = $db->lastInsertRowID();
$legacyActivePm = $legacyReadyPm - 1;

$t0 = 1800000000;
bounty_expire_sweep($db, $t0);
$activeRow = $db->querySingle("SELECT status, expires_at FROM player_missions WHERE id = {$legacyActivePm}", true);
$readyRow = $db->querySingle("SELECT status, expires_at FROM player_missions WHERE id = {$legacyReadyPm}", true);
check($activeRow['status'] === 'in_progress' && (int) $activeRow['expires_at'] === $t0 + 30 * 86400, 'first sweep stamps 30-day grace onto pre-rework in_progress bounties');
check($readyRow['status'] === 'ready_to_redeem' && $readyRow['expires_at'] === null, 'pre-rework ready_to_redeem bounties stay NULL (never expire)');
check(bounty_active_count($db, 9001, 'LegacyOne', 'standard', 'daily') === 0 && bounty_active_count($db, 9001, 'LegacyOne', 'standard', 'weekly') === 0, 'legacy bounties consume zero rework daily/weekly slots');

bounty_expire_sweep($db, $t0 + 86400);
$activeAfterDay = $db->querySingle("SELECT status, expires_at FROM player_missions WHERE id = {$legacyActivePm}", true);
check($activeAfterDay['status'] === 'in_progress' && (int) $activeAfterDay['expires_at'] === $t0 + 30 * 86400, 'subsequent sweeps do not extend legacy expiry deadline');

bounty_expire_sweep($db, $t0 + 30 * 86400 + 1);
$activeExpired = $db->querySingle("SELECT status FROM player_missions WHERE id = {$legacyActivePm}");
$readyStillOk = $db->querySingle("SELECT status, expires_at FROM player_missions WHERE id = {$legacyReadyPm}", true);
check($activeExpired === 'expired', 'pre-rework in_progress expires after 30 days');
check($readyStillOk['status'] === 'ready_to_redeem' && $readyStillOk['expires_at'] === null, 'pre-rework ready_to_redeem stays claimable after grace ends');

// Unsealable weapons (003300 Sealed J-Sword, 00AB00 Lame d'Argent): max 2 total attrs including Hit
bounty_config(['reward_engine' => ['stat_rolls' => ['mythic' => ['attrs' => [2, 2], 'attr_cap' => 90, 'hit_chance' => 100, 'hit_cap' => 50, 'slots' => [3, 4], 'bonus' => 1.0]]]]);
$unsealableViolations = 0;
foreach (['003300', '00AB00'] as $uCode) {
    $spec = [
        'version' => 2,
        'tier' => 'mythic',
        'level' => 200,
        'items' => [[
            'base' => $uCode,
            'cat' => 'Weapon',
            'profile' => 'mythic',
            'attributes' => ['Native' => 1, 'A.Beast' => 1, 'Machine' => 1, 'Dark' => 1],
            'episode_attributes' => ['Native' => 1, 'A.Beast' => 1, 'Machine' => 1, 'Dark' => 1],
        ]],
        'photon' => [],
        'meseta' => 1000,
    ];
    for ($i = 0; $i < 40; $i++) {
        $rolled = bounty_roll_reward_string($spec);
        if (preg_match('/ (\d+)\/(\d+)\/(\d+)\/(\d+)(?:\/(\d+))?/', $rolled, $mm)) {
            $nonHitCount = count(array_filter(array_map('intval', array_slice($mm, 1, 4))));
            $hasHit = ((int) ($mm[5] ?? 0)) > 0 ? 1 : 0;
            if ($nonHitCount + $hasHit > 2) {
                $unsealableViolations++;
            }
        }
    }
}
check($unsealableViolations === 0, 'unsealable weapons (003300/00AB00) never exceed 2 total attributes including Hit');

@unlink($tmp_db);
foreach ([9001, 9002, 9003, 9004, 9011, 9012] as $acc) {
    @unlink(__DIR__ . '/../db/.bounty_' . $acc); // board refresh markers for the test accounts
}
@unlink("$tmp_db-wal");
@unlink("$tmp_db-shm");
echo "\n$passes passed, $fails failed ($n_prompts stories requested from the stub)\n";
exit($fails ? 1 : 0);
