<?php
$dbPath = !empty($_ENV['DB_PATH']) ? $_ENV['DB_PATH'] : (getenv('DB_PATH') ?: (__DIR__ . '/website.db'));
$dir = dirname($dbPath);
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
$db = new SQLite3($dbPath);
$db->enableExceptions(true);
$db->busyTimeout(5000);

// High-concurrency optimizations
$db->exec("PRAGMA journal_mode = WAL;");
$db->exec("PRAGMA synchronous = NORMAL;");
$db->exec("PRAGMA temp_store = MEMORY;");
$db->exec("PRAGMA foreign_keys = ON;");

// Users table
$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    email TEXT UNIQUE NOT NULL,
    account_id INTEGER NOT NULL,
    discord_id TEXT,
    language TEXT DEFAULT 'en',
    display_name TEXT,
    receive_system_mail INTEGER DEFAULT 1,
    receive_discord_streak_msg INTEGER DEFAULT 1,
    is_admin INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Password Resets table
$db->exec("CREATE TABLE IF NOT EXISTS password_resets (
    token TEXT PRIMARY KEY,
    username TEXT,
    email TEXT,
    expires_at INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$pwCols = [];
$pRes = $db->query("PRAGMA table_info(password_resets)");
if ($pRes) {
    while ($col = $pRes->fetchArray(SQLITE3_ASSOC)) {
        $pwCols[] = $col['name'];
    }
    $pRes->finalize();
}
if (!in_array('email', $pwCols)) {
    $db->exec("ALTER TABLE password_resets ADD COLUMN email TEXT");
}
if (!in_array('created_at', $pwCols)) {
    $db->exec("ALTER TABLE password_resets ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
}
if (!in_array('expires_at', $pwCols)) {
    $db->exec("ALTER TABLE password_resets ADD COLUMN expires_at INTEGER");
}

// Email Confirmations table
$db->exec("CREATE TABLE IF NOT EXISTS email_confirmations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    username TEXT NOT NULL,
    new_email TEXT NOT NULL,
    token TEXT UNIQUE NOT NULL,
    confirmed_at INTEGER DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at INTEGER NOT NULL
)");

// Rewards Claimed table
$db->exec("CREATE TABLE IF NOT EXISTS rewards_claimed (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    character_name TEXT NOT NULL,
    character_index INTEGER NOT NULL DEFAULT 0,
    level_milestone INTEGER NOT NULL,
    category TEXT NOT NULL,
    item_string TEXT NOT NULL,
    claimed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(account_id, character_name, character_index, level_milestone)
)");

// Daily Logins table (tracks unique play days per account)
$db->exec("CREATE TABLE IF NOT EXISTS daily_logins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    login_date TEXT NOT NULL,
    UNIQUE(account_id, login_date)
)");

// Streak Claims table (tracks which streak milestones have been claimed per cycle)
$db->exec("CREATE TABLE IF NOT EXISTS streak_claims (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    streak_cycle INTEGER NOT NULL DEFAULT 1,
    milestone INTEGER NOT NULL,
    claimed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(account_id, streak_cycle, milestone)
)");

// Missions table
$db->exec("CREATE TABLE IF NOT EXISTS missions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    goal_type TEXT NOT NULL,
    goal_target TEXT NOT NULL,
    reward_item_string TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$mCols = [];
$mRes = $db->query("PRAGMA table_info(missions)");
if ($mRes) {
    while ($mCol = $mRes->fetchArray(SQLITE3_ASSOC)) {
        $mCols[] = $mCol['name'];
    }
    $mRes->finalize();
}
if (!in_array('created_at', $mCols)) {
    $db->exec("ALTER TABLE missions ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
}

// Player Missions Tracking
$db->exec("CREATE TABLE IF NOT EXISTS player_missions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    mission_id INTEGER NOT NULL,
    character_name TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'in_progress',
    progress INTEGER NOT NULL DEFAULT 0,
    accepted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    UNIQUE(account_id, mission_id)
)");

// Schema migrations for existing databases — add columns if missing
$cols = [];
$colRes = $db->query("PRAGMA table_info(player_missions)");
while ($col = $colRes->fetchArray(SQLITE3_ASSOC)) {
    $cols[] = $col['name'];
}
if (!in_array('character_name', $cols)) {
    $db->exec("ALTER TABLE player_missions ADD COLUMN character_name TEXT NOT NULL DEFAULT ''");
}
if (!in_array('accepted_at', $cols)) {
    $db->exec("ALTER TABLE player_missions ADD COLUMN accepted_at DATETIME DEFAULT CURRENT_TIMESTAMP");
}

// Mods table
$db->exec("CREATE TABLE IF NOT EXISTS mods (
    mod_id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    author TEXT NOT NULL,
    submitted_by TEXT NOT NULL,
    version TEXT NOT NULL,
    description TEXT NOT NULL,
    purpose TEXT NOT NULL,
    category TEXT NOT NULL,
    file_path TEXT NOT NULL,
    image_path TEXT,
    file_size INTEGER NOT NULL,
    status TEXT DEFAULT 'pending',
    published_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Mod Ratings table
$db->exec("CREATE TABLE IF NOT EXISTS mod_ratings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mod_id TEXT NOT NULL,
    account_id INTEGER NOT NULL,
    rating INTEGER NOT NULL CHECK(rating >= 1 AND rating <= 5),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(mod_id, account_id)
)");

// Tekker Challenge tables
$db->exec("CREATE TABLE IF NOT EXISTS tekker_active_drops (
    drop_id TEXT PRIMARY KEY,
    stat_native INTEGER NOT NULL,
    stat_abeast INTEGER NOT NULL,
    stat_machine INTEGER NOT NULL,
    stat_dark INTEGER NOT NULL,
    stat_hit INTEGER NOT NULL,
    hint_attribute TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1
)");

$db->exec("CREATE TABLE IF NOT EXISTS tekker_player_state (
    user_id TEXT NOT NULL,
    drop_id TEXT NOT NULL,
    attempts_used INTEGER NOT NULL,
    max_attempts INTEGER NOT NULL,
    PRIMARY KEY (user_id, drop_id)
)");

$db->exec("CREATE TABLE IF NOT EXISTS tekker_telemetry (
    log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL,
    drop_id TEXT NOT NULL,
    guess_array TEXT NOT NULL,
    result_state TEXT NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS tekker_tokens (
    token_id TEXT PRIMARY KEY,
    owner_id TEXT NOT NULL,
    stat_native INTEGER NOT NULL,
    stat_abeast INTEGER NOT NULL,
    stat_machine INTEGER NOT NULL,
    stat_dark INTEGER NOT NULL,
    stat_hit INTEGER NOT NULL,
    is_claimed INTEGER DEFAULT 0,
    claimed_by TEXT,
    claimed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS tekker_active_users (
    user_id TEXT PRIMARY KEY
)");

$db->exec("CREATE TABLE IF NOT EXISTS tekker_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
)");

// Community Events & Participants
$db->exec("CREATE TABLE IF NOT EXISTS community_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    goal_type TEXT NOT NULL,
    goal_target TEXT NOT NULL,
    target_amount INTEGER NOT NULL,
    current_progress INTEGER DEFAULT 0,
    reward_item_string TEXT NOT NULL,
    top_3_reward_item_string TEXT,
    status TEXT DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME,
    announced_start BOOLEAN DEFAULT 0,
    announced_20 BOOLEAN DEFAULT 0,
    announced_50 BOOLEAN DEFAULT 0,
    announced_80 BOOLEAN DEFAULT 0
)");

$db->exec("CREATE TABLE IF NOT EXISTS community_event_participants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    account_id INTEGER NOT NULL,
    contribution_count INTEGER DEFAULT 0,
    reward_claimed BOOLEAN DEFAULT 0,
    UNIQUE(event_id, account_id),
    FOREIGN KEY(event_id) REFERENCES community_events(id)
)");

// Sessions table
$db->exec("CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    data TEXT,
    last_accessed INTEGER NOT NULL
)");

// LFG Requests table
$db->exec("CREATE TABLE IF NOT EXISTS lfg_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    character_name TEXT NOT NULL,
    class TEXT NOT NULL,
    level INTEGER NOT NULL,
    section_id TEXT NOT NULL,
    game_id INTEGER,
    game_name TEXT,
    bounty_id INTEGER,
    looking_for TEXT,
    description TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Bot Tokens table
$botCols = [];
$bRes = $db->query("PRAGMA table_info(bot_tokens)");
if ($bRes) {
    while ($col = $bRes->fetchArray(SQLITE3_ASSOC)) {
        $botCols[] = $col['name'];
    }
    $bRes->finalize();
}
if (!empty($botCols) && (!in_array('token_hash', $botCols) || in_array('token_id', $botCols))) {
    $db->exec("DROP TABLE IF EXISTS bot_tokens");
    $db->exec("DROP INDEX IF EXISTS idx_bot_tokens_hash");
}

$db->exec("CREATE TABLE IF NOT EXISTS bot_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    created_by INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME,
    expires_at DATETIME,
    revoked INTEGER DEFAULT 0
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_bot_tokens_hash ON bot_tokens(token_hash) WHERE revoked = 0");

// Special Deliveries table
$sdCols = [];
$sdRes = $db->query("PRAGMA table_info(special_deliveries)");
if ($sdRes) {
    while ($col = $sdRes->fetchArray(SQLITE3_ASSOC)) {
        $sdCols[] = $col['name'];
    }
    $sdRes->finalize();
}
if (!empty($sdCols) && !in_array('recipient_id', $sdCols)) {
    $db->exec("DROP TABLE IF EXISTS special_deliveries");
    $db->exec("DROP INDEX IF EXISTS idx_special_deliveries_recipient");
}

$db->exec("CREATE TABLE IF NOT EXISTS special_deliveries (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient_id   INTEGER NOT NULL,
    recipient_name TEXT    NOT NULL,
    item_name      TEXT    NOT NULL,
    item_string    TEXT    NOT NULL,
    admin_note     TEXT,
    created_by     INTEGER NOT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    redeemed_at    DATETIME,
    status         TEXT DEFAULT 'pending'
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_special_deliveries_recipient ON special_deliveries(recipient_id, status)");

// Daily Rewards claim records table
$db->exec("CREATE TABLE IF NOT EXISTS daily_rewards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL,
    claim_date TEXT NOT NULL,
    item_string TEXT NOT NULL,
    claimed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(account_id, claim_date)
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_daily_rewards_account_date ON daily_rewards(account_id, claim_date)");

// Column migrations for users table
$userCols = [];
$uRes = $db->query("PRAGMA table_info(users)");
while ($col = $uRes->fetchArray(SQLITE3_ASSOC)) {
    $userCols[] = $col['name'];
}
if (!in_array('discord_id', $userCols)) {
    $db->exec("ALTER TABLE users ADD COLUMN discord_id TEXT");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_discord ON users(discord_id) WHERE discord_id IS NOT NULL");
}
if (!in_array('language', $userCols)) {
    $db->exec("ALTER TABLE users ADD COLUMN language TEXT DEFAULT 'en'");
}
if (!in_array('display_name', $userCols)) {
    $db->exec("ALTER TABLE users ADD COLUMN display_name TEXT");
}
if (!in_array('receive_system_mail', $userCols)) {
    $db->exec("ALTER TABLE users ADD COLUMN receive_system_mail INTEGER DEFAULT 1");
}
if (!in_array('receive_discord_streak_msg', $userCols)) {
    $db->exec("ALTER TABLE users ADD COLUMN receive_discord_streak_msg INTEGER DEFAULT 1");
}
if (!in_array('is_admin', $userCols)) {
    $db->exec("ALTER TABLE users ADD COLUMN is_admin INTEGER DEFAULT 0");
}

// Count total tables
$tableCount = 0;
$tRes = $db->query("SELECT count(*) as count FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
if ($row = $tRes->fetchArray(SQLITE3_ASSOC)) {
    $tableCount = $row['count'];
}

if (php_sapi_name() === 'cli' && (!isset($_SERVER['SCRIPT_FILENAME']) || realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__))) {
    echo "Database initialized at $dbPath ($tableCount tables verified)\n";
}
?>