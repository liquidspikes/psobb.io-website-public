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
 * their progress, expiry at the reset, the one free reroll, and the reward roll on claim.
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
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/bounty_system.php';
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
        'account_id' => 9001, 'char_name' => $client['Name'], 'user_lang' => 'en', 'curr_f' => (int) $client['LocationFloor'],
        'lobby' => $lobby, 'lobby_map' => [], 'kills' => $kills ?? no_kills(), 'completed' => $completed,
        'active_types' => [], 'recent_titles' => 'None',
    ]);
    ob_end_clean();
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
$quiet = ['earn' => ['login_daily_chance' => 0, 'login_weekly_chance' => 0, 'battle_chance' => 0, 'challenge_chance' => 0]];

// ---------------------------------------------------------------------------
// 1. Free earning: 3 + 3 active, caps of 5 dailies and 10 weeklies per reset, the rest waits
// ---------------------------------------------------------------------------
bounty_config(['earn' => ['login_daily_chance' => 100, 'login_weekly_chance' => 100]]);
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
bounty_config(['drops' => ['rare_enemy_chance' => ['Ultimate' => 100], 'boss_chance' => ['Ultimate' => 100]]]);
$party = ['is_game' => true, 'episode' => 1, 'difficulty' => 'Ultimate', 'mode' => 'Normal', 'players' => 3];
$c = client('Cobalt', 150);
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
    $meta = json_decode(rows($db, 'Cobalt', 'pm.id = ' . (int) $d['id'])[0]['reward_spec'], true);
    check(is_array($meta) && empty($meta['rolled']), 'drop reward stats are not rolled yet');
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
// 4. Battle (3 dailies) and Challenge (3 weeklies)
// ---------------------------------------------------------------------------
bounty_config(['earn' => ['battle_chance' => 100, 'challenge_chance' => 100]]);
$e = client('Ember', 80);
for ($i = 0; $i < 8; $i++) {
    tick($db, $e, $battle);
}
check(bounty_earned_count($db, 9001, 'Ember', 'battle', 'daily') === 3, 'Battle Mode earns 3 dailies');
check(bounty_active_count($db, 9001, 'Ember', 'battle', 'daily') === 3, 'all 3 Battle bounties active');
foreach (rows($db, 'Ember', "m.pool = 'battle'") as $r) {
    check($r['goal_type'] === 'BATTLE_WINS', 'Battle bounty asks for Battle wins');
}
$chal = ['is_game' => true, 'episode' => 1, 'difficulty' => 'Normal', 'mode' => 'Challenge', 'players' => 2];
for ($i = 0; $i < 8; $i++) {
    tick($db, $e, $chal);
}
check(bounty_earned_count($db, 9001, 'Ember', 'challenge', 'weekly') === 3, 'Challenge Mode earns 3 weeklies');
foreach (rows($db, 'Ember', "m.pool = 'challenge'") as $r) {
    check($r['goal_type'] === 'CHALLENGE_STAGES' && $r['cadence'] === 'weekly', 'Challenge bounty asks for stages, weekly');
}
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
// 8. Rewards: bases shown up front, stats rolled on claim
// ---------------------------------------------------------------------------
$cfg = bounty_config();
$mythic_bases = array_column($cfg['rewards']['mythic_items'], 0);
$ultra_bases = array_column($cfg['rewards']['ultra_items'], 0);
for ($i = 0; $i < 200; $i++) {
    $tier = BOUNTY_TIERS[$i % 5];
    $diff = ['Normal', 'Hard', 'Very Hard', 'Ultimate'][$i % 4];
    $spec = bounty_build_reward_spec($tier, 1 + ($i * 7) % 200, 'RAmar', $diff);
    $shown = bounty_reward_display($spec);
    $rolled = bounty_roll_reward_string($spec);
    check(preg_match('/(^|, )\d+ Meseta$/', $rolled) === 1, "$tier reward ends with Meseta");
    if ($tier === 'common') {
        check($shown === $rolled, 'common rewards have nothing hidden');
    }
    foreach ($spec['items'] as $it) {
        if (strlen($it['base']) === 32 && in_array($it['cat'], ['Weapon', 'Armor', 'Shield'], true)) {
            $bytes = ($it['cat'] === 'Weapon') ? substr($it['base'], 12, 12) : substr($it['base'], 10, 10);
            check($bytes === str_repeat('0', strlen($bytes)), "board shows no pre-rolled stats on {$it['cat']} {$it['base']}");
        }
    }
    if ($tier === 'mythic') {
        check(in_array($spec['items'][0]['base'], $mythic_bases, true), 'Mythic reward includes a Mythic item');
    }
    if ($tier === 'ultra') {
        check(in_array($spec['items'][0]['base'], $ultra_bases, true), 'Ultra reward includes an Ultra item');
    }
    foreach (explode(', ', $rolled) as $part) {
        if (preg_match('/ (\d+)\/(\d+)\/(\d+)\/(\d+)(?:\/(\d+))?$/', $part, $mm)) {
            $p = $cfg['roll_profiles'][$spec['items'][0]['profile'] ?? 'rare'] ?? null;
            foreach (array_slice($mm, 1) as $v) {
                check((int) $v % 5 === 0 && (int) $v <= 100, "stat $v is a multiple of 5, at most 100");
            }
        }
        if (preg_match('/ \+(\d+)$/', $part, $mm) && strpos($part, 'Meseta') === false) {
            check((int) $mm[1] <= 4, 'armor slots at most 4');
        }
    }
}

@unlink($tmp_db);
@unlink(__DIR__ . '/../db/.bounty_9001'); // activation marker for the test account
@unlink("$tmp_db-wal");
@unlink("$tmp_db-shm");
echo "\n$passes passed, $fails failed ($n_prompts stories requested from the stub)\n";
exit($fails ? 1 : 0);
