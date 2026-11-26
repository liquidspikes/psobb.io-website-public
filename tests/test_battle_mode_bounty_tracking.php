<?php
/**
 * Unit tests for Battle Mode bounty quest-number normalization (88001-88008 <-> 501-508)
 * and per-character-slot win delta tracking.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$tmp_db = sys_get_temp_dir() . '/battle_mode_bounty_test_' . getmypid() . '.db';
register_shutdown_function(static function () use ($tmp_db) {
    @unlink($tmp_db);
    @unlink($tmp_db . '-wal');
    @unlink($tmp_db . '-shm');
});
putenv("DB_PATH=$tmp_db");
require_once dirname(__DIR__) . '/api/config.php';
$_ENV['DB_PATH'] = $tmp_db;
$GLOBALS['BOUNTY_IGNORE_LOCAL_CONFIG'] = true;
require_once dirname(__DIR__) . '/api/db.php';
require_once dirname(__DIR__) . '/api/functions.php';
require_once dirname(__DIR__) . '/api/bounty_system.php';

function assert_bm_eq($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL [$label]: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_bm_true(bool $cond, string $label): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL [$label]: condition was false\n");
        exit(1);
    }
}

$db = get_db(true);

function make_battle_mission(SQLite3 $db, string $target = '506:4'): array
{
    $db->exec("INSERT INTO missions (title, description, goal_type, goal_target, tier, cadence, pool, reward_item_string)
               VALUES ('Battle 6 Test', 'Win Battle 6 matches', 'BATTLE_RULE_WINS', '" . $db->escapeString($target) . "', 'ultra', 'daily', 'battle', '001E00')");
    $mid = (int) $db->lastInsertRowID();
    $db->exec("INSERT INTO player_missions (account_id, character_name, mission_id, status, progress)
               VALUES (279, '', $mid, 'in_progress', '{}')");
    $pmid = (int) $db->lastInsertRowID();
    return [
        'id' => $pmid,
        'goal_type' => 'BATTLE_RULE_WINS',
        'goal_target' => $target,
        'progress' => '{}',
        'activated_at' => 1,
    ];
}

function reload_battle_mission(SQLite3 $db, int $pmid, string $target = '506:4'): array
{
    $prog = $db->querySingle("SELECT progress FROM player_missions WHERE id = $pmid");
    return [
        'id' => $pmid,
        'goal_type' => 'BATTLE_RULE_WINS',
        'goal_target' => $target,
        'progress' => $prog ?: '{}',
        'activated_at' => 1,
    ];
}

// 1. bounty_normalize_battle_quest() coverage
for ($k = 1; $k <= 8; $k++) {
    assert_bm_eq(500 + $k, bounty_normalize_battle_quest(88000 + $k), "normalize 8800{$k} -> 50{$k}");
    assert_bm_eq(500 + $k, bounty_normalize_battle_quest((string) (88000 + $k)), "normalize string '8800{$k}' -> 50{$k}");
    assert_bm_eq(500 + $k, bounty_normalize_battle_quest(500 + $k), "normalize 50{$k} -> 50{$k}");
}
foreach ([null, '', 0, 101, 500, 509, 88000, 88009, 88101] as $invalid) {
    assert_bm_eq(null, bounty_normalize_battle_quest($invalid), 'reject invalid quest ' . var_export($invalid, true));
}
assert_bm_eq([506, 4], bounty_parse_battle_target('506:4'), 'parse 506:4');
assert_bm_eq([506, 4], bounty_parse_battle_target('88006:4'), 'parse 88006:4 normalizes to 506');

// 2. Battle 6 (QuestNumber=88006) win progression & non-matching rule filtering (88005)
$m = make_battle_mission($db, '506:4');
$pmid = $m['id'];
$clientBase = ['Account' => ['AccountID' => 279], 'Name' => 'Alakaboom', 'BBCharacterIndex' => 7];
$battle6Lobby = ['is_game' => true, 'mode' => 'Battle', 'quest_number' => 88006];
$battle5Lobby = ['is_game' => true, 'mode' => 'Battle', 'quest_number' => 88005];

list($bw, $state) = bounty_battle_win_deltas($clientBase + ['BattlePlaceCounts' => [0, 0, 0, 0]], [], $battle6Lobby);
assert_bm_eq(0, $bw['wins'], 'initial sight inside Battle 6 lobby yields 0 delta');
assert_bm_eq(506, $bw['quest'], '88006 normalized to 506 in state');

// Win #1 on return to lobby (Battle 6 -> credits 1/4)
list($bw, $state) = bounty_battle_win_deltas($clientBase + ['BattlePlaceCounts' => [1, 0, 0, 0]], $state, null);
assert_bm_eq(['quest' => 506, 'wins' => 1], $bw, 'Battle 6 return to lobby detects win #1');
$done = bounty_evaluate_weekly($db, reload_battle_mission($db, $pmid), $clientBase, null, ['battle_wins' => $bw], []);
assert_bm_true(!$done, '1/4 Battle 6 wins does not complete 506:4');
assert_bm_eq(['count' => 1], json_decode(reload_battle_mission($db, $pmid)['progress'], true), 'progress updated to 1');

// Non-matching Battle 5 match (QuestNumber=88005) must NOT credit Battle 6 bounty
list($bw, $state) = bounty_battle_win_deltas($clientBase + ['BattlePlaceCounts' => [1, 0, 0, 0]], $state, $battle5Lobby);
list($bw, $state) = bounty_battle_win_deltas($clientBase + ['BattlePlaceCounts' => [2, 0, 0, 0]], $state, null);
assert_bm_eq(['quest' => 505, 'wins' => 1], $bw, 'Battle 5 win normalized to 505');
$done = bounty_evaluate_weekly($db, reload_battle_mission($db, $pmid), $clientBase, null, ['battle_wins' => $bw], []);
assert_bm_true(!$done, 'Battle 5 win does not complete Battle 6');
assert_bm_eq(['count' => 1], json_decode(reload_battle_mission($db, $pmid)['progress'], true), 'Battle 5 win leaves Battle 6 progress at 1');

// Wins #2, #3, #4 in Battle 6 complete 506:4 (including defensive normalization on pre-fix array)
list($bw, $state) = bounty_battle_win_deltas($clientBase + ['BattlePlaceCounts' => [2, 0, 0, 0]], $state, $battle6Lobby);
list($bw, $state) = bounty_battle_win_deltas($clientBase + ['BattlePlaceCounts' => [5, 0, 0, 0]], $state, null);
assert_bm_eq(['quest' => 506, 'wins' => 3], $bw, 'Battle 6 wins 2..4 detected as +3 delta');
$done = bounty_evaluate_weekly($db, reload_battle_mission($db, $pmid), $clientBase, null, ['battle_wins' => ['quest' => 88006, 'wins' => 3]], []);
assert_bm_true($done, '4th win completes 506:4 even if caller passes 88006');
assert_bm_eq(['count' => 4], json_decode(reload_battle_mission($db, $pmid)['progress'], true), 'progress reaches 4');

// 3. Existing legacy .cron_player_state.json entry with bounty_battle_quest=88006 credits immediately
$legacyState = ['bounty_battle_firsts' => 0, 'bounty_battle_quest' => 88006];
list($bw, $newState) = bounty_battle_win_deltas($clientBase + ['BattlePlaceCounts' => [1, 0, 0, 0]], $legacyState, null);
assert_bm_eq(['quest' => 506, 'wins' => 1], $bw, 'legacy cached 88006 state normalizes to 506 and credits win');
assert_bm_eq(506, $newState['bounty_battle_quest'], 'legacy quest updated to normalized 506');

// 4. Multi-slot same-name character isolation (BBCharacterIndex 7 vs BBCharacterIndex 3)
$slot7Client = ['Account' => ['AccountID' => 279], 'Name' => 'Alakaboom', 'BBCharacterIndex' => 7, 'BattlePlaceCounts' => [2, 0, 0, 0]];
$slot3Client = ['Account' => ['AccountID' => 279], 'Name' => 'Alakaboom', 'BBCharacterIndex' => 3, 'BattlePlaceCounts' => [0, 0, 0, 0]];

list($bw, $multiState) = bounty_battle_win_deltas($slot7Client, [], $battle6Lobby);
assert_bm_eq(0, $bw['wins'], 'slot 7 initial sight (2 wins) has 0 delta');

// Switch to same-named slot 3 starting at 0 wins, then winning 1 Battle 6 match
list($bw, $multiState) = bounty_battle_win_deltas($slot3Client, $multiState, $battle6Lobby);
assert_bm_eq(0, $bw['wins'], 'slot 3 initial sight (0 wins) has 0 delta and does not clobber slot 7');
list($bw, $multiState) = bounty_battle_win_deltas(['Account' => ['AccountID' => 279], 'Name' => 'Alakaboom', 'BBCharacterIndex' => 3, 'BattlePlaceCounts' => [1, 0, 0, 0]], $multiState, null);
assert_bm_eq(['quest' => 506, 'wins' => 1], $bw, 'slot 3 wins 0 -> 1 credited cleanly');

// Switch back to slot 7 still at 2 wins -> MUST produce 0 delta (not false +1 or +2!)
list($bw, $multiState) = bounty_battle_win_deltas($slot7Client, $multiState, null);
assert_bm_eq(0, $bw['wins'], 'switching back to slot 7 (still at 2 wins) yields 0 delta');

// Slot 7 subsequently wins a 3rd match -> +1 delta
list($bw, $multiState) = bounty_battle_win_deltas(['Account' => ['AccountID' => 279], 'Name' => 'Alakaboom', 'BBCharacterIndex' => 7, 'BattlePlaceCounts' => [3, 0, 0, 0]], $multiState, $battle6Lobby);
assert_bm_eq(['quest' => 506, 'wins' => 1], $bw, 'slot 7 win 2 -> 3 credited accurately after slot switch');

echo "ALL TESTS PASSED (test_battle_mode_bounty_tracking.php)\n";
