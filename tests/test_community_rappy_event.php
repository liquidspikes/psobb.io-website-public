<?php
/**
 * Unit & integration tests for ENEMY_KILL / RARE_RAPPIES Community Events.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

define('CRON_COMMUNITY_LIB_ONLY', true);
require_once dirname(__DIR__) . '/api/cron_community.php';

function assert_eq($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL [$label]: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_true(bool $cond, string $label): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL [$label]: condition was false\n");
        exit(1);
    }
}

// 1. Unit tests for all 7 Rare Rappy types (including NewServ Rare:false variants) & difficulty bonuses
$all_rare_rappy_entries = [];
foreach (COMMUNITY_RARE_RAPPY_TYPES as $enemy_code) {
    $all_rare_rappy_entries[] = [
        'episode' => 2,
        'difficulty' => 'Normal',
        'mode' => 'Normal',
        'enemy' => $enemy_code,
        'name' => $enemy_code,
        'rare' => false, // Mirror NewServ EnemyType.cc where LOVE_RAPPY / seasonal / DEL_RAPPY report Rare=false
        'boss' => false,
        'n' => 1,
    ];
}
assert_eq(70, community_enemy_kill_points($all_rare_rappy_entries, 'RARE_RAPPIES'), 'all 7 rare rappy types count even with rare=false');
assert_eq(70, community_enemy_kill_points($all_rare_rappy_entries, 'ANY_RARE_ENEMY'), 'ANY_RARE_ENEMY also counts all 7 seasonal rappies');

$common_only = [
    ['enemy' => 'RAG_RAPPY', 'difficulty' => 'Ultimate', 'rare' => false, 'n' => 5],
    ['enemy' => 'SAND_RAPPY_CRATER', 'difficulty' => 'Ultimate', 'rare' => false, 'n' => 3],
    ['enemy' => 'SAND_RAPPY_DESERT', 'difficulty' => 'Ultimate', 'rare' => false, 'n' => 2],
];
assert_eq(0, community_enemy_kill_points($common_only, 'RARE_RAPPIES'), 'common rappies yield 0 points');

assert_eq(10, community_enemy_kill_points([['enemy' => 'AL_RAPPY', 'difficulty' => 'Normal', 'rare' => true, 'n' => 1]], 'RARE_RAPPIES'), 'Normal score is 10');
assert_eq(12, community_enemy_kill_points([['enemy' => 'LOVE_RAPPY', 'difficulty' => 'Hard', 'rare' => false, 'n' => 1]], 'RARE_RAPPIES'), 'Hard score is 12');
assert_eq(15, community_enemy_kill_points([['enemy' => 'SAINT_RAPPY', 'difficulty' => 'Very Hard', 'rare' => false, 'n' => 1]], 'RARE_RAPPIES'), 'Very Hard score is 15');
assert_eq(15, community_enemy_kill_points([['enemy' => 'EGG_RAPPY', 'difficulty' => 'VHard', 'rare' => false, 'n' => 1]], 'RARE_RAPPIES'), 'VHard score is 15');
assert_eq(20, community_enemy_kill_points([['enemy' => 'DEL_RAPPY_DESERT', 'difficulty' => 'Ultimate', 'rare' => false, 'n' => 1]], 'RARE_RAPPIES'), 'Ultimate score is 20');

// 2. Integration tests against in-memory SQLite DB
$db = new SQLite3(':memory:');
$db->enableExceptions(true);
$db->exec("
    CREATE TABLE community_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        goal_type TEXT NOT NULL,
        goal_target TEXT NOT NULL,
        target_amount INTEGER NOT NULL,
        current_progress INTEGER DEFAULT 0,
        reward_item_string TEXT NOT NULL,
        status TEXT DEFAULT 'active',
        top_3_reward_item_string TEXT
    );
    CREATE TABLE community_event_participants (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        account_id INTEGER NOT NULL,
        contribution_count INTEGER DEFAULT 0,
        UNIQUE(event_id, account_id)
    );
");
$db->exec("
    INSERT INTO community_events (id, title, description, goal_type, goal_target, target_amount, current_progress, reward_item_string, status, top_3_reward_item_string)
    VALUES (1, 'Operation: Fowl Play', 'Hunt Rare Rappies!', 'ENEMY_KILL', 'RARE_RAPPIES', 5000, 0, '3x Random Rare Drops', 'active', 'Rappy''s Beak | Kit of Hamburger | Heart of Chao');
");

$events = [$db->querySingle("SELECT * FROM community_events WHERE id = 1", true)];
$lobbies = [
    ['ID' => 42, 'IsGame' => true, 'Episode' => 'Episode 2', 'Difficulty' => 'Ultimate'],
    ['ID' => 77, 'IsGame' => true, 'Episode' => 'Episode 4', 'Difficulty' => 'Very Hard'],
    ['ID' => 1, 'IsGame' => false, 'Episode' => 'Episode 1', 'Difficulty' => 'Normal'],
];
$player_states = [];

// Tick 1: First-seen baseline with pre-existing kills should award 0
$clients_t1 = [
    [
        'Account' => ['AccountID' => 101], 'Name' => 'Alice', 'EXP' => 50000, 'Level' => 120, 'LobbyID' => 42,
        'KillCounts' => [['Episode' => 'Episode 1', 'Difficulty' => 'Ultimate', 'Mode' => 'Normal', 'Enemy' => 'AL_RAPPY', 'Name' => 'Pal Rappy', 'Rare' => true, 'Count' => 3]],
    ],
    ['Account' => ['AccountID' => 102], 'Name' => 'Bob', 'EXP' => 40000, 'Level' => 110, 'LobbyID' => 42, 'KillCounts' => []],
    ['Account' => ['AccountID' => 103], 'Name' => 'Cara', 'EXP' => 30000, 'Level' => 105, 'LobbyID' => 42, 'KillCounts' => []],
    ['Account' => ['AccountID' => 104], 'Name' => 'Dan', 'EXP' => 20000, 'Level' => 100, 'LobbyID' => 42, 'KillCounts' => []],
];
$player_states = cron_community_process_clients($db, $clients_t1, $lobbies, $events, $player_states);
assert_eq(0, (int)$db->querySingle("SELECT current_progress FROM community_events WHERE id = 1"), 'baseline tick adds 0 global progress');
assert_eq(0, (int)$db->querySingle("SELECT COUNT(*) FROM community_event_participants"), 'baseline tick adds 0 participant rows');

// Tick 2: Alice kills 1 AL_RAPPY (Ult) and Bob kills 1 LOVE_RAPPY (Ult, Rare=false) in 4-player co-op room 42
$clients_t2 = $clients_t1;
$clients_t2[0]['KillCounts'][0]['Count'] = 4; // +1 AL_RAPPY Ult (+20)
$clients_t2[1]['KillCounts'] = [
    ['Episode' => 'Episode 2', 'Difficulty' => 'Ultimate', 'Mode' => 'Normal', 'Enemy' => 'LOVE_RAPPY', 'Name' => 'Love Rappy', 'Rare' => false, 'Count' => 1],
];
$player_states = cron_community_process_clients($db, $clients_t2, $lobbies, $events, $player_states);
assert_eq(40, (int)$db->querySingle("SELECT current_progress FROM community_events WHERE id = 1"), 'co-op room 2 kills add 40 global progress once');
foreach ([101, 102, 103, 104] as $aid) {
    assert_eq(40, (int)$db->querySingle("SELECT contribution_count FROM community_event_participants WHERE event_id = 1 AND account_id = $aid"), "party account $aid credited +40");
}

// Tick 3: Same-account multibox deduplication (non-killer FoxA listed BEFORE killer FoxB on acc 105) + bystander Guest 106 in room 77
$clients_t3 = [
    ['Account' => ['AccountID' => 105], 'Name' => 'FoxA', 'EXP' => 1000, 'Level' => 80, 'LobbyID' => 77, 'KillCounts' => []],
    ['Account' => ['AccountID' => 105], 'Name' => 'FoxB', 'EXP' => 2000, 'Level' => 85, 'LobbyID' => 77, 'KillCounts' => []],
    ['Account' => ['AccountID' => 106], 'Name' => 'Guest', 'EXP' => 3000, 'Level' => 90, 'LobbyID' => 77, 'KillCounts' => []],
];
$player_states = cron_community_process_clients($db, $clients_t3, $lobbies, $events, $player_states); // baseline 105/106
$clients_t3[1]['KillCounts'] = [
    ['Episode' => 'Episode 4', 'Difficulty' => 'Very Hard', 'Mode' => 'Normal', 'Enemy' => 'DEL_RAPPY_CRATER', 'Name' => 'Del Rappy (crater)', 'Rare' => false, 'Count' => 1],
];
$player_states = cron_community_process_clients($db, $clients_t3, $lobbies, $events, $player_states);
assert_eq(55, (int)$db->querySingle("SELECT current_progress FROM community_events WHERE id = 1"), 'multibox room adds +15 global once');
assert_eq(15, (int)$db->querySingle("SELECT contribution_count FROM community_event_participants WHERE event_id = 1 AND account_id = 105"), 'multibox account credited once (+15)');
assert_eq(15, (int)$db->querySingle("SELECT contribution_count FROM community_event_participants WHERE event_id = 1 AND account_id = 106"), 'co-op guest credited +15');

// Tick 4: Fast-exit to town lobby 1 (IsGame=false) alongside Idle bystander 108 in lobby 1
$clients_t4 = [
    ['Account' => ['AccountID' => 107], 'Name' => 'Scout', 'EXP' => 500, 'Level' => 20, 'LobbyID' => 1, 'KillCounts' => []],
    ['Account' => ['AccountID' => 108], 'Name' => 'Idle', 'EXP' => 500, 'Level' => 20, 'LobbyID' => 1, 'KillCounts' => []],
];
$player_states = cron_community_process_clients($db, $clients_t4, $lobbies, $events, $player_states); // baseline
$clients_t4[0]['KillCounts'] = [
    ['Episode' => 'Episode 4', 'Difficulty' => 'Normal', 'Mode' => 'Normal', 'Enemy' => 'DEL_RAPPY_DESERT', 'Name' => 'Del Rappy (desert)', 'Rare' => false, 'Count' => 1],
];
$player_states = cron_community_process_clients($db, $clients_t4, $lobbies, $events, $player_states);
assert_eq(65, (int)$db->querySingle("SELECT current_progress FROM community_events WHERE id = 1"), 'fast-exit solo kill adds +10 global');
assert_eq(10, (int)$db->querySingle("SELECT contribution_count FROM community_event_participants WHERE event_id = 1 AND account_id = 107"), 'fast-exit killer credited +10');
assert_eq(0, (int)$db->querySingle("SELECT COUNT(*) FROM community_event_participants WHERE event_id = 1 AND account_id = 108"), 'town lobby bystander receives no credit');

// Tick 5: Loading-screen EXP=0 preserves Alice state; Tick 6 only adds 1 new Ultimate kill (+20 => 60)
$clients_t5 = [
    ['Account' => ['AccountID' => 101], 'Name' => 'Alice', 'EXP' => 0, 'Level' => 120, 'LobbyID' => 42, 'KillCounts' => []],
];
$player_states = cron_community_process_clients($db, $clients_t5, $lobbies, $events, $player_states);
assert_eq(50000, $player_states['101_Alice']['exp'], 'loading screen preserves Alice EXP');
assert_eq(4, $player_states['101_Alice']['kill_counts']['Episode 1|Ultimate|Normal|AL_RAPPY'], 'loading screen preserves Alice kill_counts');

$clients_t6 = [
    [
        'Account' => ['AccountID' => 101], 'Name' => 'Alice', 'EXP' => 52000, 'Level' => 120, 'LobbyID' => 42,
        'KillCounts' => [['Episode' => 'Episode 1', 'Difficulty' => 'Ultimate', 'Mode' => 'Normal', 'Enemy' => 'AL_RAPPY', 'Name' => 'Pal Rappy', 'Rare' => true, 'Count' => 5]],
    ],
];
$player_states = cron_community_process_clients($db, $clients_t6, $lobbies, $events, $player_states);
assert_eq(60, (int)$db->querySingle("SELECT contribution_count FROM community_event_participants WHERE event_id = 1 AND account_id = 101"), 'Alice credited +20 for 1 post-transition kill');
assert_eq(85, (int)$db->querySingle("SELECT current_progress FROM community_events WHERE id = 1"), 'global progress increased by +20 to 85');

// 3. UI & lore helper checks
$objText = getClearObjective('ENEMY_KILL', 'RARE_RAPPIES', 'Operation: Fowl Play', 'Hunt Rare Rappies!');
assert_true(strpos($objText, 'Rare Rappies') !== false && strpos($objText, '+10 Pts Base; +2 Hard, +5 VHard, +10 Ult') !== false, 'getClearObjective formats RARE_RAPPIES');

$loreSetting = bounty_lore_setting_for_objective('ENEMY_KILL', 'RARE_RAPPIES', 'Operation: Fowl Play', 'Hunt Rare Rappies!');
assert_eq(true, $loreSetting['is_location_goal'], 'lore setting marks is_location_goal');
assert_true(strpos((string)$loreSetting['location_override'], 'Rare Rappies') !== false, 'lore setting describes Rare Rappies');

echo "ALL COMMUNITY RAPPY TESTS PASSED\n";
