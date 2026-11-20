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

if (!function_exists('decode_psochar_name')) {
    /**
     * Decodes a PSOBB character name from raw .psochar binary data (offset 0x3C8 / 968, 32 bytes),
     * matching NewServ's pstring<TextEncoding::UTF16_ALWAYS_MARKED, 0x10>::decode():
     *   - Scans 2 bytes at a time up to the first UTF-16LE NUL code unit (\x00\x00).
     *   - Converts UTF-16LE -> UTF-8.
     *   - Strips any 2-byte "\t<lang>" prefix (E/J/B/T/K/etc.) while preserving "\tC" color codes.
     */
    function decode_psochar_name(string $charData, bool $trim = true): string {
        if (strlen($charData) < 0x3C8 + 0x20) {
            return '';
        }
        $raw = substr($charData, 0x3C8, 0x20);
        $byteLen = 0x20;
        for ($z = 0; $z < 0x20; $z += 2) {
            if ($raw[$z] === "\x00" && $raw[$z + 1] === "\x00") {
                $byteLen = $z;
                break;
            }
        }
        if ($byteLen === 0) {
            return '';
        }
        $slice = substr($raw, 0, $byteLen);
        $utf8 = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($slice, 'UTF-8', 'UTF-16LE')
            : @iconv('UTF-16LE', 'UTF-8//IGNORE', $slice);
        if (!is_string($utf8) || $utf8 === '') {
            return '';
        }
        if (strlen($utf8) >= 2 && $utf8[0] === "\t" && $utf8[1] !== 'C' && ord($utf8[1]) < 0x80) {
            $utf8 = substr($utf8, 2);
        }
        return $trim ? trim($utf8, " \r\n\0\x0B") : $utf8;
    }
}

/**
 * Retrieves a singleton-like SQLite3 database connection.
 * Automatically runs required schema migrations (e.g., adding missing columns or tables) 
 * to ensure the database is up-to-date with the current application version.
 *
 * @throws Exception If the database file cannot be opened or created.
 * @return SQLite3 The established SQLite3 database connection.
 */
function get_db(bool $reset = false)
{
    static $dbInstance = null;
    static $cachedPath = null;

    $path = !empty($_ENV['DB_PATH']) ? $_ENV['DB_PATH'] : (getenv('DB_PATH') ?: (__DIR__ . '/../db/website.db'));
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

            CREATE TABLE IF NOT EXISTS daily_rewards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                claim_date TEXT NOT NULL,
                item_string TEXT NOT NULL,
                claimed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(account_id, claim_date)
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
                hint_attribute TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1
            );

            CREATE TABLE IF NOT EXISTS tekker_player_state (
                user_id TEXT NOT NULL,
                drop_id TEXT NOT NULL,
                attempts_used INTEGER NOT NULL,
                max_attempts INTEGER NOT NULL,
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
        ");

        // --- Auto-migration for 'users' table ---
        // Ensure discord_id column exists
        $result = $db->query("PRAGMA table_info(users)");
        $hasDiscordId = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'discord_id') {
                $hasDiscordId = true;
                break;
            }
        }
        $result->finalize();
        if (!$hasDiscordId) {
            $db->exec("ALTER TABLE users ADD COLUMN discord_id TEXT");
            $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_discord ON users(discord_id) WHERE discord_id IS NOT NULL");
        }

        // Ensure language column exists
        $hasLanguage = false;
        $result = $db->query("PRAGMA table_info(users)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'language') {
                $hasLanguage = true;
                break;
            }
        }
        $result->finalize();
        if (!$hasLanguage) {
            $db->exec("ALTER TABLE users ADD COLUMN language TEXT DEFAULT 'en'");
        }

        // Ensure display_name column exists (leaderboard alias)
        $hasDisplayName = false;
        $result = $db->query("PRAGMA table_info(users)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'display_name') {
                $hasDisplayName = true;
                break;
            }
        }
        $result->finalize();
        if (!$hasDisplayName) {
            $db->exec("ALTER TABLE users ADD COLUMN display_name TEXT");
        }

        // Ensure receive_system_mail column exists (QoL notification setting)
        $hasReceiveSystemMail = false;
        $result = $db->query("PRAGMA table_info(users)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'receive_system_mail') {
                $hasReceiveSystemMail = true;
                break;
            }
        }
        $result->finalize();
        if (!$hasReceiveSystemMail) {
            $db->exec("ALTER TABLE users ADD COLUMN receive_system_mail INTEGER DEFAULT 1");
        }

        // Ensure receive_discord_streak_msg column exists (QoL Discord DM streak alert setting)
        $hasReceiveDiscordStreakMsg = false;
        $result = $db->query("PRAGMA table_info(users)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'receive_discord_streak_msg') {
                $hasReceiveDiscordStreakMsg = true;
                break;
            }
        }
        $result->finalize();
        if (!$hasReceiveDiscordStreakMsg) {
            $db->exec("ALTER TABLE users ADD COLUMN receive_discord_streak_msg INTEGER DEFAULT 1");
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

        // Enable FK enforcement only after all schema migrations are done
        $db->exec("PRAGMA foreign_keys = ON;");

        $dbInstance = $db;
        return $dbInstance;

    } catch (Exception $e) {
        throw $e;
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

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'api-key: ' . $BREVO_API_KEY,
            'content-type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
