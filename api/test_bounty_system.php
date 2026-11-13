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
// 4. Battle (3 dailies) and Challenge (3 weeklies)
// ---------------------------------------------------------------------------
bounty_config(['earn' => ['battle_chance' => 100, 'challenge_chance' => 100]]);
// newserv shows a stage never cleared as 65535
$e = client('Ember', 80, ['ChallengeTimesEp1Online' => [900, 3000, 65535, 65535, 65535, 65535, 65535, 65535, 65535], 'ChallengeTimesEp2Online' => array_fill(0, 5, 65535)]);
for ($i = 0; $i < 8; $i++) {
    tick($db, $e, $battle);
}
check(bounty_earned_count($db, 9001, 'Ember', 'battle', 'daily') === 3, 'Battle Mode earns 3 dailies');
check(bounty_active_count($db, 9001, 'Ember', 'battle', 'daily') === 3, 'all 3 Battle bounties active');
foreach (rows($db, 'Ember', "m.pool = 'battle'") as $r) {
    list($q, $w) = bounty_parse_battle_target($r['goal_target']);
    check($r['goal_type'] === 'BATTLE_RULE_WINS' && $q >= 501 && $q <= 508 && $w >= 1 && $w <= 3, "Battle bounty names a rule set and wins ({$r['goal_target']})");
    check(strpos(getClearObjective($r['goal_type'], $r['goal_target']), 'Battle ' . ($q - 500) . ':') !== false, 'Battle objective names the rule set');
}
$chal = ['is_game' => true, 'episode' => 1, 'difficulty' => 'Normal', 'mode' => 'Challenge', 'players' => 2];
for ($i = 0; $i < 8; $i++) {
    tick($db, $e, $chal);
}
check(bounty_earned_count($db, 9001, 'Ember', 'challenge', 'weekly') === 3, 'Challenge Mode earns 3 weeklies');
foreach (rows($db, 'Ember', "m.pool = 'challenge'") as $r) {
    $ct = bounty_parse_challenge_target($r['goal_target']);
    check($r['goal_type'] === 'CHALLENGE_RUN' && $r['cadence'] === 'weekly', "Challenge bounty is a weekly stage objective ({$r['goal_target']})");
    if ($r['status'] === 'in_progress' && !($ct['first'] === 1 && $ct['last'] === bounty_challenge_stage_total($ct['episode']))) {
        check($ct['first'] <= (($ct['episode'] === 1) ? 3 : 1), 'Challenge stage starts within reach (highest cleared + 1)');
    }
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
    if (preg_match('/ (\d+)\/(\d+)\/(\d+)\/(\d+)\/(\d+)/', $rolled, $mm)) {
        $attrs = array_values(array_filter(array_map('intval', array_slice($mm, 1, 4))));
        check(count($attrs) === 2 && min($attrs) >= 60 && max($attrs) <= 90 && (int) $mm[5] >= 30 && (int) $mm[5] <= 50, "full-run weapon has high stats ($rolled)");
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

// The board names items by newserv's name where item_map.json's last name for a code is wrong
$names = ['031200' => 'Weapons Bronze Badge', '031201' => 'Weapons Silver Badge', '031202' => 'Weapons Gold Badge', '01024B' => 'Assist Barrier',
          '008A00' => 'Sange', '010223' => 'Regene Gear Adv.', '031209' => 'Item Ticket', '031003' => 'Secret Ticket', '031004' => 'Photon Ticket',
          '031002' => 'Photon Crystal', '030D00' => "Sorcerer's Right Arm", '030900' => 'Scape Doll', '001001' => 'Agito (1975)'];
foreach ($names as $code => $want) {
    $got = html_entity_decode(renderRewardString($code), ENT_QUOTES);
    check(strcasecmp($got, $want) === 0, "the board names $code \"$want\" (got \"$got\")");
}

@unlink($tmp_db);
@unlink(__DIR__ . '/../db/.bounty_9001'); // activation marker for the test account
@unlink("$tmp_db-wal");
@unlink("$tmp_db-shm");
echo "\n$passes passed, $fails failed ($n_prompts stories requested from the stub)\n";
exit($fails ? 1 : 0);
