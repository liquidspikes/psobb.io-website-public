<?php
/**
 * PSOBB Website Database Provider
 * 
 * Manages the SQLite3 database connection and handles automatic schema 
 * initialization/migrations on the fly. Also provides the fallback 
 * Brevo email delivery mechanism.
 */
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/exception_logger.php';
require_once __DIR__ . '/pso_constants.php';

if (!defined('PSO_DB_SCHEMA_VERSION')) {
    define('PSO_DB_SCHEMA_VERSION', 2);
}

/**
 * Retrieves a singleton-like SQLite3 database connection.
 * Runs run_db_migrations() only when PRAGMA user_version is behind PSO_DB_SCHEMA_VERSION.
 *
 * @throws Exception If the database file cannot be opened or created.
 * @return SQLite3 The established SQLite3 database connection.
 */
function get_db(bool $reset = false)
{
    static $dbInstance = null;
    static $cachedPath = null;

    $path = get_db_path();
    if ($dbInstance !== null && !$reset && $cachedPath === $path) {
        return $dbInstance;
    }
    $cachedPath = $path;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    try {
        $db = new SQLite3($path);
        $db->enableExceptions(true);
        // 30s timeout: cron jobs hold write locks for multiple seconds during
        // per-player batch processing; user-facing redemptions must wait them out.
        $db->busyTimeout(30000);

        // High-concurrency optimizations
        $db->exec("PRAGMA journal_mode = WAL;");
        $db->exec("PRAGMA synchronous = NORMAL;");
        $db->exec("PRAGMA temp_store = MEMORY;");
        $db->exec("PRAGMA cache_size = -8000;");  // 8MB page cache

        $currentVer = (int) $db->querySingle('PRAGMA user_version');
        if ($currentVer < PSO_DB_SCHEMA_VERSION) {
            run_db_migrations($db);
            $db->exec('PRAGMA user_version = ' . (int) PSO_DB_SCHEMA_VERSION . ';');
        }

        // Enable FK enforcement after any schema migrations
        $db->exec("PRAGMA foreign_keys = ON;");

        $dbInstance = $db;
        return $dbInstance;
    } catch (Exception $e) {
        throw $e;
    }
}

/**
 * Ensure all tables, columns, indexes, and one-time data fixes exist in SQLite.
 */
function run_db_migrations(SQLite3 $db): void
{
        // Ensure foundational tables exist before running column migrations
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
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
            );

            CREATE TABLE IF NOT EXISTS password_resets (
                token TEXT PRIMARY KEY,
                username TEXT,
                email TEXT,
                expires_at INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS email_confirmations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                username TEXT NOT NULL,
                new_email TEXT NOT NULL,
                token TEXT UNIQUE NOT NULL,
                confirmed_at INTEGER DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS rewards_claimed (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                character_name TEXT NOT NULL,
                character_index INTEGER NOT NULL DEFAULT 0,
                level_milestone INTEGER NOT NULL,
                category TEXT NOT NULL,
                item_string TEXT NOT NULL,
                claimed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(account_id, character_name, character_index, level_milestone)
            );

            CREATE TABLE IF NOT EXISTS streak_claims (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                streak_cycle INTEGER NOT NULL DEFAULT 1,
                milestone INTEGER NOT NULL,
                claimed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(account_id, streak_cycle, milestone)
            );

            CREATE TABLE IF NOT EXISTS tekker_active_drops (
                drop_id TEXT PRIMARY KEY,
                stat_native INTEGER NOT NULL,
                stat_abeast INTEGER NOT NULL,
                stat_machine INTEGER NOT NULL,
                stat_dark INTEGER NOT NULL,
                stat_hit INTEGER NOT NULL,
                hint_attribute TEXT NOT NULL DEFAULT '',
                is_active INTEGER NOT NULL DEFAULT 1,
                base_native INTEGER DEFAULT 0,
                base_abeast INTEGER DEFAULT 0,
                base_machine INTEGER DEFAULT 0,
                base_dark INTEGER DEFAULT 0,
                base_hit INTEGER DEFAULT 0,
                spawn_time DATETIME,
                despawn_time DATETIME,
                guesses_since_shift INTEGER DEFAULT 0,
                second_zero_discovered INTEGER DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS tekker_player_state (
                user_id TEXT NOT NULL,
                drop_id TEXT NOT NULL,
                attempts_used INTEGER NOT NULL,
                max_attempts INTEGER NOT NULL,
                lifetime_attempts INTEGER DEFAULT 0,
                attempts_remaining INTEGER,
                last_guess_at TEXT,
                PRIMARY KEY (user_id, drop_id)
            );

            CREATE TABLE IF NOT EXISTS tekker_telemetry (
                log_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT NOT NULL,
                drop_id TEXT NOT NULL,
                guess_array TEXT NOT NULL,
                result_state TEXT NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS tekker_tokens (
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
            );

            CREATE TABLE IF NOT EXISTS tekker_active_users (
                user_id TEXT PRIMARY KEY
            );

            CREATE TABLE IF NOT EXISTS tekker_settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS tekker_claim_log (
                claim_id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                discord_id TEXT,
                token_ids TEXT NOT NULL,
                token_count INTEGER NOT NULL,
                weapon_hex TEXT NOT NULL,
                weapon_name TEXT NOT NULL,
                stat_native INTEGER DEFAULT 0,
                stat_abeast INTEGER DEFAULT 0,
                stat_machine INTEGER DEFAULT 0,
                stat_dark INTEGER DEFAULT 0,
                stat_hit INTEGER DEFAULT 0,
                claimed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Column migrations for tekker_active_drops & tekker_player_state (existing DBs)
        $tadCols = [];
        $tadRes = $db->query("PRAGMA table_info(tekker_active_drops)");
        if ($tadRes) {
            while ($c = $tadRes->fetchArray(SQLITE3_ASSOC)) {
                $tadCols[] = $c['name'];
            }
            $tadRes->finalize();
        }
        $tadExtra = [
            'base_native' => 'INTEGER DEFAULT 0',
            'base_abeast' => 'INTEGER DEFAULT 0',
            'base_machine' => 'INTEGER DEFAULT 0',
            'base_dark' => 'INTEGER DEFAULT 0',
            'base_hit' => 'INTEGER DEFAULT 0',
            'spawn_time' => 'DATETIME',
            'despawn_time' => 'DATETIME',
            'guesses_since_shift' => 'INTEGER DEFAULT 0',
            'second_zero_discovered' => 'INTEGER DEFAULT 0',
        ];
        foreach ($tadExtra as $colName => $colDef) {
            if (!in_array($colName, $tadCols, true)) {
                @$db->exec("ALTER TABLE tekker_active_drops ADD COLUMN {$colName} {$colDef}");
            }
        }
        $db->exec("UPDATE tekker_active_drops SET is_active = 0 WHERE is_active = 1 AND (drop_id IS NULL OR trim(drop_id) = '')");

        $tpsCols = [];
        $tpsRes = $db->query("PRAGMA table_info(tekker_player_state)");
        if ($tpsRes) {
            while ($c = $tpsRes->fetchArray(SQLITE3_ASSOC)) {
                $tpsCols[] = $c['name'];
            }
            $tpsRes->finalize();
        }
        $tpsExtra = [
            'lifetime_attempts' => 'INTEGER DEFAULT 0',
            'attempts_remaining' => 'INTEGER',
            'last_guess_at' => 'TEXT',
        ];
        foreach ($tpsExtra as $colName => $colDef) {
            if (!in_array($colName, $tpsCols, true)) {
                @$db->exec("ALTER TABLE tekker_player_state ADD COLUMN {$colName} {$colDef}");
            }
        }

        // --- Auto-migration for 'users' table (single PRAGMA pass) ---
        $userCols = [];
        $uRes = $db->query("PRAGMA table_info(users)");
        if ($uRes) {
            while ($row = $uRes->fetchArray(SQLITE3_ASSOC)) {
                $userCols[$row['name']] = true;
            }
            $uRes->finalize();
        }
        if (empty($userCols['discord_id'])) {
            $db->exec("ALTER TABLE users ADD COLUMN discord_id TEXT");
        }
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_discord ON users(discord_id) WHERE discord_id IS NOT NULL");
        if (empty($userCols['language'])) {
            $db->exec("ALTER TABLE users ADD COLUMN language TEXT DEFAULT 'en'");
        }
        if (empty($userCols['display_name'])) {
            $db->exec("ALTER TABLE users ADD COLUMN display_name TEXT");
        }
        if (empty($userCols['receive_system_mail'])) {
            $db->exec("ALTER TABLE users ADD COLUMN receive_system_mail INTEGER DEFAULT 1");
        }
        if (empty($userCols['receive_discord_streak_msg'])) {
            $db->exec("ALTER TABLE users ADD COLUMN receive_discord_streak_msg INTEGER DEFAULT 1");
        }
        if (empty($userCols['is_admin'])) {
            $db->exec("ALTER TABLE users ADD COLUMN is_admin INTEGER DEFAULT 0");
        }

        // Ensure email, created_at, and expires_at exist in password_resets
        $pwCols = [];
        $result = $db->query("PRAGMA table_info(password_resets)");
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $pwCols[] = $row['name'];
            }
            $result->finalize();
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

        // --- Auto-migration for Bounty/Missions & Streaks tables ---
        $db->exec("
            CREATE TABLE IF NOT EXISTS missions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                goal_type TEXT NOT NULL,
                goal_target TEXT NOT NULL,
                reward_item_string TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            
            CREATE TABLE IF NOT EXISTS player_missions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                mission_id INTEGER NOT NULL,
                status TEXT DEFAULT 'in_progress',
                completed_at DATETIME,
                FOREIGN KEY(mission_id) REFERENCES missions(id)
            );
            
            CREATE TABLE IF NOT EXISTS daily_logins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                login_date TEXT NOT NULL,
                UNIQUE(account_id, login_date)
            );
            
            CREATE TABLE IF NOT EXISTS community_events (
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
            );
        ");

        // Ensure top_3_reward_item_string column exists in community_events
        $hasTop3 = false;
        $result = $db->query("PRAGMA table_info(community_events)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'top_3_reward_item_string') {
                $hasTop3 = true;
                break;
            }
        }
        $result->finalize();
        if (!$hasTop3) {
            $db->exec("ALTER TABLE community_events ADD COLUMN top_3_reward_item_string TEXT");
        }
        
        // Ensure character_name exists in player_missions to support per-character bounties
        $hasCharName = false;
        $result = $db->query("PRAGMA table_info(player_missions)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'character_name') {
                $hasCharName = true;
                break;
            }
        }
        $result->finalize();
        if (!$hasCharName) {
            $db->exec("ALTER TABLE player_missions ADD COLUMN character_name TEXT");
        }

        // Ensure created_at exists in missions
        $hasMissionCreatedAt = false;
        $result = $db->query("PRAGMA table_info(missions)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'created_at') {
                $hasMissionCreatedAt = true;
                break;
            }
        }
        $result->finalize();
        if (!$hasMissionCreatedAt) {
            $db->exec("ALTER TABLE missions ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
        }

        // --- Bounty System Rework (api/bounty_system.php) ---
        // missions: tier (common..mythic), cadence (daily/weekly), pool (standard/drop/battle/challenge),
        //   source (login/completion/boss/rare_enemy/battle/challenge), reward_spec (JSON: item bases whose
        //   stats roll on claim), meta (JSON: e.g. the difficulty a drop came from).
        // player_missions: period_key (the reset period it was earned in), expires_at (unix), earned_at,
        //   activated_at, rerolled (0/1), progress (JSON, weekly objectives).
        // Rows from before the rework keep NULLs here and behave exactly as before.
        $bountyCols = [
            'missions' => ['tier' => 'TEXT', 'cadence' => 'TEXT', 'pool' => 'TEXT', 'source' => 'TEXT', 'reward_spec' => 'TEXT', 'meta' => 'TEXT'],
            'player_missions' => ['period_key' => 'TEXT', 'expires_at' => 'INTEGER', 'earned_at' => 'INTEGER', 'activated_at' => 'INTEGER', 'rerolled' => 'INTEGER DEFAULT 0', 'progress' => 'TEXT'],
        ];
        foreach ($bountyCols as $bTable => $bCols) {
            $bHave = [];
            $result = $db->query("PRAGMA table_info($bTable)");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $bHave[] = $row['name'];
            }
            $result->finalize();
            foreach ($bCols as $bName => $bDef) {
                if (!in_array($bName, $bHave, true)) {
                    $db->exec("ALTER TABLE $bTable ADD COLUMN $bName $bDef");
                }
            }
        }
        $db->exec("CREATE INDEX IF NOT EXISTS idx_player_missions_scope ON player_missions(account_id, character_name, status)");

        // --- Enemy kill counts (api/enemy_kills.php): each character's own kills, by episode, difficulty,
        //   game mode and enemy, from newserv's KillCounts in /y/clients. A boss counts once per fight. ---
        $db->exec("
            CREATE TABLE IF NOT EXISTS enemy_kill_counts (
                account_id INTEGER NOT NULL,
                character_name TEXT NOT NULL,
                episode INTEGER NOT NULL,
                difficulty TEXT NOT NULL,
                mode TEXT NOT NULL,
                enemy TEXT NOT NULL,
                name TEXT,
                rare INTEGER DEFAULT 0,
                boss INTEGER DEFAULT 0,
                kills INTEGER NOT NULL DEFAULT 0,
                first_kill_at INTEGER,
                last_kill_at INTEGER,
                PRIMARY KEY (account_id, character_name, episode, difficulty, mode, enemy)
            )
        ");
        
        // --- Auto-migration for Community Event Participants & Game Mods ---
        $db->exec("
            CREATE TABLE IF NOT EXISTS community_event_participants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id INTEGER NOT NULL,
                account_id INTEGER NOT NULL,
                contribution_count INTEGER DEFAULT 0,
                reward_claimed BOOLEAN DEFAULT 0,
                UNIQUE(event_id, account_id),
                FOREIGN KEY(event_id) REFERENCES community_events(id)
            );
            
            CREATE TABLE IF NOT EXISTS mods (
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
            );

            CREATE TABLE IF NOT EXISTS mod_ratings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mod_id TEXT NOT NULL,
                account_id INTEGER NOT NULL,
                rating INTEGER NOT NULL CHECK(rating >= 1 AND rating <= 5),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(mod_id, account_id)
            );
            
            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                data TEXT,
                last_accessed INTEGER NOT NULL
            );
        ");

        // Clean up legacy draft table if it exists to prevent conflicts
        $db->exec("DROP TABLE IF EXISTS game_mods");

        // --- Auto-migration for LFG requests table ---
        $db->exec("
            CREATE TABLE IF NOT EXISTS lfg_requests (
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
            );
        ");

        // Check for bounty_id column
        $hasBountyId = false;
        $result = $db->query("PRAGMA table_info(lfg_requests)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'bounty_id') {
                $hasBountyId = true;
                break;
            }
        }
        $result->finalize();
        if (!$hasBountyId) {
            $db->exec("ALTER TABLE lfg_requests ADD COLUMN bounty_id INTEGER");
        }

        // Check for looking_for column
        $hasLookingFor = false;
        $result = $db->query("PRAGMA table_info(lfg_requests)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'looking_for') {
                $hasLookingFor = true;
                break;
            }
        }
        $result->finalize();
        if (!$hasLookingFor) {
            $db->exec("ALTER TABLE lfg_requests ADD COLUMN looking_for TEXT");
        }

        // --- Bot API Tokens ---
        // Self-healing: if the table was created with the broken FK (REFERENCES users(account_id)),
        // or with an older legacy schema (e.g. token_id column instead of token_hash), drop and recreate it.
        $botCols = [];
        $bRes = $db->query("PRAGMA table_info(bot_tokens)");
        if ($bRes) {
            while ($c = $bRes->fetchArray(SQLITE3_ASSOC)) {
                $botCols[] = $c['name'];
            }
            $bRes->finalize();
        }
        $recreateBotTokens = false;
        if (!empty($botCols)) {
            if (!in_array('token_hash', $botCols) || in_array('token_id', $botCols)) {
                $recreateBotTokens = true;
            } else {
                $badFk = $db->querySingle(
                    "SELECT sql FROM sqlite_master WHERE type='table' AND name='bot_tokens' AND sql LIKE '%REFERENCES users%'"
                );
                if ($badFk) {
                    $recreateBotTokens = true;
                }
            }
        }
        if ($recreateBotTokens) {
            $db->exec("DROP TABLE IF EXISTS bot_tokens");
            $db->exec("DROP INDEX IF EXISTS idx_bot_tokens_hash");
        }
        $db->exec("
            CREATE TABLE IF NOT EXISTS bot_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                created_by INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME,
                expires_at DATETIME,
                revoked INTEGER DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS idx_bot_tokens_hash ON bot_tokens(token_hash) WHERE revoked = 0;
        ");

        // --- Special Item Deliveries ---
        // Self-healing: if the table exists with a legacy schema lacking recipient_id, drop and recreate it.
        $sdCols = [];
        $sdRes = $db->query("PRAGMA table_info(special_deliveries)");
        if ($sdRes) {
            while ($c = $sdRes->fetchArray(SQLITE3_ASSOC)) {
                $sdCols[] = $c['name'];
            }
            $sdRes->finalize();
        }
        if (!empty($sdCols) && !in_array('recipient_id', $sdCols)) {
            $db->exec("DROP TABLE IF EXISTS special_deliveries");
            $db->exec("DROP INDEX IF EXISTS idx_special_deliveries_recipient");
        }
        $db->exec("
            CREATE TABLE IF NOT EXISTS special_deliveries (
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
            );
            CREATE INDEX IF NOT EXISTS idx_special_deliveries_recipient
                ON special_deliveries(recipient_id, status);
        ");

        // --- Daily Rewards Self-Healing Migration ---
        $drCols = [];
        $drRes = $db->query("PRAGMA table_info(daily_rewards)");
        if ($drRes) {
            while ($c = $drRes->fetchArray(SQLITE3_ASSOC)) {
                $drCols[] = $c['name'];
            }
            $drRes->finalize();
        }
        if (!empty($drCols) && !in_array('account_id', $drCols)) {
            $db->exec("DROP TABLE IF EXISTS daily_rewards");
        }
        $db->exec("
            CREATE TABLE IF NOT EXISTS daily_rewards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                claim_date TEXT NOT NULL,
                item_string TEXT NOT NULL,
                claimed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(account_id, claim_date)
            );
            CREATE INDEX IF NOT EXISTS idx_daily_rewards_account_date ON daily_rewards(account_id, claim_date);
        ");

        // --- Idempotent Self-Heal Migration for Invalid/Swapped Item Hex Payloads ---
        $itemHexMigrated = $db->querySingle("SELECT value FROM tekker_settings WHERE key = 'migration_fix_invalid_item_hex_v1'");
        if (!$itemHexMigrated) {
            $hasTable = function (string $tbl) use ($db): bool {
                $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :tbl LIMIT 1");
                $stmt->bindValue(':tbl', $tbl, SQLITE3_TEXT);
                $res = $stmt->execute();
                return $res && $res->fetchArray(SQLITE3_NUM) !== false;
            };
            $db->exec("BEGIN IMMEDIATE TRANSACTION;");
            try {
                if ($hasTable('missions')) {
                    $db->exec("
                        UPDATE missions
                        SET reward_item_string = REPLACE(
                            REPLACE(reward_item_string, '030209001D0000000000000000000000', '03021D00090000000000000000000000'),
                            '030212001D0000000000000000000000', '03021D00120000000000000000000000'
                        )
                        WHERE reward_item_string LIKE '%030209001D0000000000000000000000%'
                           OR reward_item_string LIKE '%030212001D0000000000000000000000%';
                    ");
                }
                if ($hasTable('rewards_claimed')) {
                    $db->exec("
                        UPDATE rewards_claimed
                        SET item_string = REPLACE(
                            REPLACE(item_string, '030209001D0000000000000000000000', '03021D00090000000000000000000000'),
                            '030212001D0000000000000000000000', '03021D00120000000000000000000000'
                        )
                        WHERE item_string LIKE '%030209001D0000000000000000000000%'
                           OR item_string LIKE '%030212001D0000000000000000000000%';
                    ");
                }
                if ($hasTable('special_deliveries')) {
                    $db->exec("
                        UPDATE special_deliveries
                        SET item_string = REPLACE(item_string, '030B070000', '030B060000')
                        WHERE status = 'pending' AND item_string LIKE '%030B070000%';
                    ");
                }
                $db->exec("INSERT OR REPLACE INTO tekker_settings (key, value) VALUES ('migration_fix_invalid_item_hex_v1', '1');");
                $db->exec("COMMIT;");
            } catch (Exception $migEx) {
                @$db->exec("ROLLBACK;");
            }
        }

        // --- Idempotent Self-Heal Migration for Stuck Challenge Mode Bounties (goal_target > 14) ---
        $cmMigrated = $db->querySingle("SELECT value FROM tekker_settings WHERE key = 'migration_fix_challenge_stages_v1'");
        if (!$cmMigrated) {
            $playersDir = function_exists('get_newserv_players_dir') ? get_newserv_players_dir() : '/opt/newserv/system/players/';
            $playersDir = rtrim($playersDir, '/\\') . '/';
            $filesByUser = [];
            if (@is_dir($playersDir)) {
                $entries = @scandir($playersDir);
                if (is_array($entries)) {
                    foreach ($entries as $fname) {
                        if (preg_match('/^player_(.+)_(\d+)\.psochar$/i', $fname, $fm)) {
                            $filesByUser[strtolower($fm[1])][] = $playersDir . $fname;
                        }
                    }
                }
            }

            $countPsocharCleared = function (string $username, string $charName) use ($filesByUser): int {
                $paths = $filesByUser[strtolower($username)] ?? [];
                foreach ($paths as $pPath) {
                    $buf = @file_get_contents($pPath);
                    if ($buf === false || strlen($buf) < (0x2CC0 + 0x3C)) {
                        continue;
                    }
                    $rawName = decode_psochar_name($buf, false);
                    if ($rawName === '' || $rawName !== $charName) {
                        continue;
                    }
                    $vals = unpack('V14', substr($buf, 0x2CC4, 56));
                    if (!is_array($vals)) {
                        return 0;
                    }
                    $cleared = 0;
                    foreach ($vals as $v) {
                        $v = (int)$v & 0xFFFFFFFF;
                        $mask = ($v >> 16) & 0xFFFF;
                        $ones = substr_count(decbin($mask), '1');
                        $dec = ($ones < 4 || $ones > 12) ? 0xFFFF : (($mask ^ $v) & 0xFFFF);
                        if ($dec > 0 && $dec < 0xFFFF) {
                            $cleared++;
                        }
                    }
                    return $cleared;
                }
                return 0;
            };

            $db->exec("BEGIN IMMEDIATE TRANSACTION;");
            try {
                $cmStmt = $db->query("
                    SELECT DISTINCT m.id, m.description, pm.character_name, u.username
                    FROM missions m
                    JOIN player_missions pm ON pm.mission_id = m.id
                    LEFT JOIN users u ON u.account_id = pm.account_id
                    WHERE m.goal_type = 'CHALLENGE_STAGES'
                      AND pm.status = 'in_progress'
                      AND CAST(m.goal_target AS INTEGER) > 14
                ");
                $rowsToFix = [];
                if ($cmStmt) {
                    while ($r = $cmStmt->fetchArray(SQLITE3_ASSOC)) {
                        $rowsToFix[] = $r;
                    }
                    $cmStmt->finalize();
                }

                $updCm = $db->prepare("UPDATE missions SET goal_target = :gt, description = :desc WHERE id = :id");
                foreach ($rowsToFix as $r) {
                    $cleared = $countPsocharCleared((string)($r['username'] ?? ''), (string)($r['character_name'] ?? ''));
                    $newTarget = min(14, $cleared + 1);
                    $desc = preg_replace(['/\b1[56]\b/', '/\b(?:fifteen|sixteen)\b/i'], (string)$newTarget, (string)$r['description']);
                    if ($newTarget === 1 && $desc !== null) {
                        $desc = preg_replace('/\b1(\s+(?:[a-z-]+\s+){0,2}?)stages\b/i', '1$1stage', $desc);
                    }
                    if ($desc === null) {
                        $desc = (string)$r['description'];
                    }
                    $updCm->bindValue(':gt', (string)$newTarget, SQLITE3_TEXT);
                    $updCm->bindValue(':desc', $desc, SQLITE3_TEXT);
                    $updCm->bindValue(':id', (int)$r['id'], SQLITE3_INTEGER);
                    $updCm->execute();
                    $updCm->reset();
                }

                $db->exec("INSERT OR REPLACE INTO tekker_settings (key, value) VALUES ('migration_fix_challenge_stages_v1', '1');");
                $db->exec("COMMIT;");
            } catch (Exception $cmMigEx) {
                @$db->exec("ROLLBACK;");
            }
        }

        // --- Idempotent +24h extension for open daily bounties on moving reset to 11:00 AM ET ---
        $dailyExtMigrated = $db->querySingle("SELECT value FROM tekker_settings WHERE key = 'migration_extend_open_dailies_11am_est_v1'");
        if (!$dailyExtMigrated) {
            $hasTable = function (string $tbl) use ($db): bool {
                $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :tbl LIMIT 1");
                $stmt->bindValue(':tbl', $tbl, SQLITE3_TEXT);
                $res = $stmt->execute();
                return $res && $res->fetchArray(SQLITE3_NUM) !== false;
            };
            $db->exec("BEGIN IMMEDIATE TRANSACTION;");
            try {
                if ($hasTable('player_missions') && $hasTable('missions')) {
                    $db->exec("
                        UPDATE player_missions
                        SET expires_at = expires_at + 86400
                        WHERE status IN ('queued', 'in_progress', 'rerolling')
                          AND expires_at IS NOT NULL
                          AND mission_id IN (SELECT id FROM missions WHERE cadence = 'daily');
                    ");
                }
                $db->exec("INSERT OR REPLACE INTO tekker_settings (key, value) VALUES ('migration_extend_open_dailies_11am_est_v1', '1');");
                $db->exec("COMMIT;");
            } catch (Exception $dailyExtEx) {
                @$db->exec("ROLLBACK;");
            }
        }
}

/**
 * Dispatches an email using either the Brevo HTTP API or the fallback PHP mail() function.
 * Logs all dispatch attempts and their results to 'db/mail.log'.
 *
 * @param string $to The recipient's email address.
 * @param string $subject The subject line of the email.
 * @param string $message The plain-text body of the email.
 * @return bool True if the email was successfully accepted for delivery, false otherwise.
 */
function send_email($to, $subject, $message)
{
    global $BREVO_API_KEY, $SMTP_FROM;
    $fallbackFrom = function_exists('get_server_address') ? ('noreply@' . get_server_address()) : 'noreply@localhost';
    $from = $SMTP_FROM ?: $fallbackFrom;

    // Strip any CRLF characters from headers to prevent email header injection
    $to = preg_replace('/[\r\n]/', '', trim((string)$to));
    $from = preg_replace('/[\r\n]/', '', trim((string)$from));
    $subject = preg_replace('/[\r\n]/', '', trim((string)$subject));

    // Normalize body line endings for standard RFC compliance
    $message = str_replace(["\r\n", "\r"], "\n", (string)$message);
    $message = str_replace("\n", "\r\n", $message);

    // Log intent
    $logEntry = "[" . date('Y-m-d H:i:s') . "] To: $to | Subject: $subject";

    if (!empty($BREVO_API_KEY)) {
        // Use Brevo HTTP API (Bypasses SMTP port blocks)
        $url = 'https://api.brevo.com/v3/smtp/email';
        $data = [
            'sender' => ['email' => $from],
            'to' => [['email' => $to]],
            'subject' => $subject,
            'textContent' => $message
        ];

        $payloadJson = json_encode($data);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'accept: application/json',
                'api-key: ' . $BREVO_API_KEY,
                'content-type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $safeApiKey = str_replace(["\r", "\n"], '', (string)$BREVO_API_KEY);
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "accept: application/json\r\napi-key: " . $safeApiKey . "\r\ncontent-type: application/json\r\n",
                    'content' => $payloadJson,
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);
            $http_response_header = [];
            $response = @file_get_contents($url, false, $ctx);
            $httpCode = 0;
            if (!empty($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $hm)) {
                $httpCode = (int) $hm[1];
            }
            if ($response === false) {
                $response = 'stream request failed';
            }
        }

        $success = ($httpCode >= 200 && $httpCode < 300);
        $logEntry .= " | Via: Brevo API | Success: " . ($success ? 'Yes' : 'No') . " | Resp: $response\n";
    } else {
        // Fallback to local mail() with full UTF-8 MIME compliance (fixes Cyrillic/Japanese mojibake)
        $encoded_subject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
            : $subject;

        $headers = 'From: ' . $from . "\r\n" .
            'Reply-To: ' . $from . "\r\n" .
            'MIME-Version: 1.0' . "\r\n" .
            'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
            'Content-Transfer-Encoding: 8bit' . "\r\n" .
            'X-Mailer: PHP/' . phpversion();

        $sent = @mail($to, $encoded_subject, $message, $headers);
        $logEntry .= " | Via: Internal mail() | Sent: " . ($sent ? 'Yes' : 'No') . "\n";
    }

    @file_put_contents(__DIR__ . '/../db/mail.log', $logEntry, FILE_APPEND);

    return isset($success) ? $success : $sent;
}
