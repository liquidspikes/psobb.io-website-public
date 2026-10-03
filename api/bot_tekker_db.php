<?php
// =====================================================================
// Discord Tekker Challenge — storage backend
// The bot keeps the game logic and calls this endpoint for all persistence
// (it carries no local database). Bearer-authed, same as bot_api.php.
// Request:  POST JSON { "op": "<operation>", ...params }
// Response: { "success": true, "result": ... } | { "success": false, "error": ... }
// Tables (tekker_*) are auto-created by db.php.
// =====================================================================
ini_set('display_errors', '0');
register_shutdown_function(function () {
    $err = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($err && in_array($err['type'], $fatalTypes, true)) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); }
        echo json_encode(['success' => false, 'error' => 'PHP fatal', 'message' => $err['message'], 'file' => $err['file'], 'line' => $err['line']]);
    }
});
set_exception_handler(function ($e) {
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json'); }
    echo json_encode(['success' => false, 'error' => 'PHP exception', 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
});

require_once 'config.php';
require_once 'db.php';

header('Content-Type: application/json');

// --- Bearer auth (mirrors bot_api.php: legacy secret OR a bcrypt bot_tokens row) ---
$auth = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif (function_exists('getallheaders')) {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $auth = $headers['authorization'] ?? '';
}
$provided = (str_starts_with($auth, 'Bearer ')) ? substr($auth, 7) : $auth;

$authenticated = false;
if (!empty($BOT_API_SECRET) && hash_equals($BOT_API_SECRET, $provided)) {
    $authenticated = true;
}
if (!$authenticated && !empty($provided)) {
    $db = get_db();
    $res = $db->query("SELECT id, token_hash FROM bot_tokens WHERE revoked = 0 AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if (password_verify($provided, $row['token_hash'])) {
            $authenticated = true;
            $upd = $db->prepare("UPDATE bot_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upd->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $upd->execute();
            break;
        }
    }
}
if (!$authenticated) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

// --- Dispatch ---
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$op = $in['op'] ?? '';
$db = get_db();

// Auto-create Tekker Challenge tables if they do not exist.
// This isolates the minigame database schema migrations entirely to this endpoint,
// keeping the core db.php clean and unmodified.
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS tekker_active_drops (
            drop_id TEXT PRIMARY KEY,
            stat_native INTEGER NOT NULL,
            stat_abeast INTEGER NOT NULL,
            stat_machine INTEGER NOT NULL,
            stat_dark INTEGER NOT NULL,
            stat_hit INTEGER NOT NULL,
            hint_attribute TEXT NOT NULL,
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

    // Dynamic column migration in case tekker_active_drops already existed with earlier schema
    $cols = [];
    $colRes = $db->query("PRAGMA table_info(tekker_active_drops)");
    if ($colRes) {
        while ($colRow = $colRes->fetchArray(SQLITE3_ASSOC)) {
            $cols[] = $colRow['name'];
        }
    }
    $extraCols = [
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
    foreach ($extraCols as $colName => $colDef) {
        if (!in_array($colName, $cols, true)) {
            @$db->exec("ALTER TABLE tekker_active_drops ADD COLUMN {$colName} {$colDef}");
        }
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Schema migration error: ' . $e->getMessage()]);
    exit;
}

try {
    $result = null;
    switch ($op) {
        case 'ping':
            $result = ['ok' => true];
            break;

        case 'getActiveDrop': {
            $r = $db->querySingle("SELECT * FROM tekker_active_drops WHERE is_active = 1 LIMIT 1", true);
            $result = $r ? $r : null;
            break;
        }
        case 'createDrop': {
            $db->exec("UPDATE tekker_active_drops SET is_active = 0 WHERE is_active = 1");
            $now = date('Y-m-d H:i:s');
            $spawn = $in['spawn_time'] ?? $now;
            $despawn = $in['despawn_time'] ?? date('Y-m-d H:i:s', strtotime($spawn) + 7200);
            $stmt = $db->prepare("INSERT INTO tekker_active_drops
                (drop_id, stat_native, stat_abeast, stat_machine, stat_dark, stat_hit, hint_attribute, is_active,
                 base_native, base_abeast, base_machine, base_dark, base_hit, spawn_time, despawn_time, guesses_since_shift, second_zero_discovered)
                VALUES (:id,:n,:a,:m,:d,:h,:hint,1, :bn,:ba,:bm,:bd,:bh, :st,:dt, :gss, :szd)");
            $stmt->bindValue(':id', $in['drop_id'], SQLITE3_TEXT);
            $stmt->bindValue(':n', (int)$in['stat_native'], SQLITE3_INTEGER);
            $stmt->bindValue(':a', (int)$in['stat_abeast'], SQLITE3_INTEGER);
            $stmt->bindValue(':m', (int)$in['stat_machine'], SQLITE3_INTEGER);
            $stmt->bindValue(':d', (int)$in['stat_dark'], SQLITE3_INTEGER);
            $stmt->bindValue(':h', (int)$in['stat_hit'], SQLITE3_INTEGER);
            $stmt->bindValue(':hint', $in['hint_attribute'], SQLITE3_TEXT);
            $stmt->bindValue(':bn', (int)($in['base_native'] ?? $in['stat_native']), SQLITE3_INTEGER);
            $stmt->bindValue(':ba', (int)($in['base_abeast'] ?? $in['stat_abeast']), SQLITE3_INTEGER);
            $stmt->bindValue(':bm', (int)($in['base_machine'] ?? $in['stat_machine']), SQLITE3_INTEGER);
            $stmt->bindValue(':bd', (int)($in['base_dark'] ?? $in['stat_dark']), SQLITE3_INTEGER);
            $stmt->bindValue(':bh', (int)($in['base_hit'] ?? $in['stat_hit']), SQLITE3_INTEGER);
            $stmt->bindValue(':st', $spawn, SQLITE3_TEXT);
            $stmt->bindValue(':dt', $despawn, SQLITE3_TEXT);
            $stmt->bindValue(':gss', (int)($in['guesses_since_shift'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':szd', (int)($in['second_zero_discovered'] ?? 0), SQLITE3_INTEGER);
            $stmt->execute();
            $result = ['ok' => true];
            break;
        }
        case 'deactivateDrop': {
            $stmt = $db->prepare("UPDATE tekker_active_drops SET is_active = 0 WHERE drop_id = :id");
            $stmt->bindValue(':id', $in['dropId'], SQLITE3_TEXT);
            $stmt->execute();
            $result = ['ok' => true];
            break;
        }
        case 'shiftActiveDropStats': {
            $dropId = $in['dropId'] ?? '';
            $stmt = $db->prepare("SELECT * FROM tekker_active_drops WHERE drop_id = :id");
            $stmt->bindValue(':id', $dropId, SQLITE3_TEXT);
            $d = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if (!$d) {
                $result = ['ok' => false, 'error' => 'Drop not found'];
                break;
            }
            $variances = [-10, -5, 0, 5, 10];
            $categories = ['Native', 'A.Beast', 'Machine', 'Dark', 'Hit'];
            $slugs = ['Native' => 'native', 'A.Beast' => 'abeast', 'Machine' => 'machine', 'Dark' => 'dark', 'Hit' => 'hit'];
            $out = [];
            foreach ($categories as $cat) {
                $s = $slugs[$cat];
                $base = (int)($d['base_' . $s] ?? 0);
                if ($base > 0) {
                    $v = $variances[array_rand($variances)];
                    $val = max(0, min(90, $base + $v));
                } else {
                    $val = 0;
                }
                $out[$s] = $val;
            }
            $upd = $db->prepare("UPDATE tekker_active_drops SET
                stat_native = :n, stat_abeast = :a, stat_machine = :m, stat_dark = :d, stat_hit = :h,
                guesses_since_shift = 0
                WHERE drop_id = :id");
            $upd->bindValue(':n', $out['native'], SQLITE3_INTEGER);
            $upd->bindValue(':a', $out['abeast'], SQLITE3_INTEGER);
            $upd->bindValue(':m', $out['machine'], SQLITE3_INTEGER);
            $upd->bindValue(':d', $out['dark'], SQLITE3_INTEGER);
            $upd->bindValue(':h', $out['hit'], SQLITE3_INTEGER);
            $upd->bindValue(':id', $dropId, SQLITE3_TEXT);
            $upd->execute();
            $result = [
                'ok' => true,
                'stat_native' => $out['native'],
                'stat_abeast' => $out['abeast'],
                'stat_machine' => $out['machine'],
                'stat_dark' => $out['dark'],
                'stat_hit' => $out['hit'],
            ];
            break;
        }
        case 'incrementDropGuesses': {
            $dropId = $in['dropId'] ?? '';
            $stmt = $db->prepare("SELECT * FROM tekker_active_drops WHERE drop_id = :id");
            $stmt->bindValue(':id', $dropId, SQLITE3_TEXT);
            $d = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if (!$d) {
                $result = ['ok' => true, 'count' => 0, 'shift_triggered' => false];
                break;
            }
            $count = (int)($d['guesses_since_shift'] ?? 0) + 1;
            $shiftTriggered = false;
            if ($count >= 12) {
                $shiftTriggered = true;
                $variances = [-10, -5, 0, 5, 10];
                $slugs = ['native', 'abeast', 'machine', 'dark', 'hit'];
                $out = [];
                foreach ($slugs as $s) {
                    $base = (int)($d['base_' . $s] ?? 0);
                    $out[$s] = ($base > 0) ? max(0, min(90, $base + $variances[array_rand($variances)])) : 0;
                }
                $upd = $db->prepare("UPDATE tekker_active_drops SET
                    stat_native = :n, stat_abeast = :a, stat_machine = :m, stat_dark = :d, stat_hit = :h,
                    guesses_since_shift = 0
                    WHERE drop_id = :id");
                $upd->bindValue(':n', $out['native'], SQLITE3_INTEGER);
                $upd->bindValue(':a', $out['abeast'], SQLITE3_INTEGER);
                $upd->bindValue(':m', $out['machine'], SQLITE3_INTEGER);
                $upd->bindValue(':d', $out['dark'], SQLITE3_INTEGER);
                $upd->bindValue(':h', $out['hit'], SQLITE3_INTEGER);
                $upd->bindValue(':id', $dropId, SQLITE3_TEXT);
                $upd->execute();
            } else {
                $upd = $db->prepare("UPDATE tekker_active_drops SET guesses_since_shift = :c WHERE drop_id = :id");
                $upd->bindValue(':c', $count, SQLITE3_INTEGER);
                $upd->bindValue(':id', $dropId, SQLITE3_TEXT);
                $upd->execute();
            }
            $result = ['ok' => true, 'count' => $count, 'shift_triggered' => $shiftTriggered];
            break;
        }
        case 'discoverSecondZero': {
            $dropId = $in['dropId'] ?? '';
            $stmt = $db->prepare("UPDATE tekker_active_drops SET second_zero_discovered = 1 WHERE drop_id = :id");
            $stmt->bindValue(':id', $dropId, SQLITE3_TEXT);
            $stmt->execute();
            $result = ['ok' => true];
            break;
        }
        case 'pulseDespawnTime': {
            $dropId = $in['dropId'] ?? '';
            $stmt = $db->prepare("SELECT spawn_time, despawn_time FROM tekker_active_drops WHERE drop_id = :id");
            $stmt->bindValue(':id', $dropId, SQLITE3_TEXT);
            $d = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if (!$d) {
                $result = ['ok' => false, 'error' => 'Drop not found'];
                break;
            }
            $spawn = !empty($d['spawn_time']) ? strtotime($d['spawn_time']) : time();
            $despawn = !empty($d['despawn_time']) ? strtotime($d['despawn_time']) : ($spawn + 7200);
            $next = $despawn + 1800; // +30m per guess
            $hardCap = $spawn + 28800; // +8h cap from spawn
            if ($next > $hardCap) $next = $hardCap;
            $newDespawnStr = date('Y-m-d H:i:s', $next);
            $upd = $db->prepare("UPDATE tekker_active_drops SET despawn_time = :dt WHERE drop_id = :id");
            $upd->bindValue(':dt', $newDespawnStr, SQLITE3_TEXT);
            $upd->bindValue(':id', $dropId, SQLITE3_TEXT);
            $upd->execute();
            $result = ['ok' => true, 'despawn_time' => $newDespawnStr];
            break;
        }
        case 'getClaimLog': {
            $limit = isset($in['limit']) ? max(1, min(500, (int)$in['limit'])) : 100;
            $stmt = $db->prepare("SELECT * FROM tekker_tokens WHERE is_claimed = 1 ORDER BY claimed_at DESC LIMIT :l");
            $stmt->bindValue(':l', $limit, SQLITE3_INTEGER);
            $res = $stmt->execute();
            $rows = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }
            $result = $rows;
            break;
        }
        case 'getPlayerState': {
            $stmt = $db->prepare("SELECT * FROM tekker_player_state WHERE user_id = :u AND drop_id = :d");
            $stmt->bindValue(':u', $in['userId'], SQLITE3_TEXT);
            $stmt->bindValue(':d', $in['dropId'], SQLITE3_TEXT);
            $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            $result = $r ? $r : null;
            break;
        }
        case 'upsertPlayerState': {
            $stmt = $db->prepare("INSERT INTO tekker_player_state (user_id, drop_id, attempts_used, max_attempts)
                VALUES (:u,:d,:au,:ma)
                ON CONFLICT(user_id, drop_id) DO UPDATE SET attempts_used = excluded.attempts_used");
            $stmt->bindValue(':u', $in['userId'], SQLITE3_TEXT);
            $stmt->bindValue(':d', $in['dropId'], SQLITE3_TEXT);
            $stmt->bindValue(':au', (int)$in['attemptsUsed'], SQLITE3_INTEGER);
            $stmt->bindValue(':ma', (int)$in['maxAttempts'], SQLITE3_INTEGER);
            $stmt->execute();
            $result = ['ok' => true];
            break;
        }
        case 'addTelemetryLog': {
            $stmt = $db->prepare("INSERT INTO tekker_telemetry (user_id, drop_id, guess_array, result_state)
                VALUES (:u,:d,:g,:r)");
            $stmt->bindValue(':u', $in['userId'], SQLITE3_TEXT);
            $stmt->bindValue(':d', $in['dropId'], SQLITE3_TEXT);
            $stmt->bindValue(':g', json_encode($in['guessArray'] ?? []), SQLITE3_TEXT);
            $stmt->bindValue(':r', $in['resultState'] ?? '', SQLITE3_TEXT);
            $stmt->execute();
            $result = ['ok' => true];
            break;
        }
        case 'addActiveUser': {
            $stmt = $db->prepare("INSERT OR IGNORE INTO tekker_active_users (user_id) VALUES (:u)");
            $stmt->bindValue(':u', $in['userId'], SQLITE3_TEXT);
            $stmt->execute();
            $result = ['ok' => true];
            break;
        }
        case 'getActiveUserCount': {
            $result = (int)$db->querySingle("SELECT COUNT(*) FROM tekker_active_users");
            break;
        }
        case 'clearActiveUsers': {
            $db->exec("DELETE FROM tekker_active_users");
            $result = ['ok' => true];
            break;
        }
        case 'getTriggerThreshold': {
            $v = $db->querySingle("SELECT value FROM tekker_settings WHERE key = 'trigger_threshold'");
            $result = ($v !== false && $v !== null) ? (int)$v : 30;
            break;
        }
        case 'setTriggerThreshold': {
            $stmt = $db->prepare("INSERT INTO tekker_settings (key, value) VALUES ('trigger_threshold', :v)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value");
            $stmt->bindValue(':v', (string)($in['value'] ?? 30), SQLITE3_TEXT);
            $stmt->execute();
            $result = ['ok' => true];
            break;
        }
        case 'createToken': {
            $stmt = $db->prepare("INSERT INTO tekker_tokens
                (token_id, owner_id, stat_native, stat_abeast, stat_machine, stat_dark, stat_hit, is_claimed)
                VALUES (:t,:o,:n,:a,:m,:d,:h,0)");
            $stmt->bindValue(':t', preg_replace('/[\r\n\t ]/', '', trim($in['token_id'] ?? '')), SQLITE3_TEXT);
            $stmt->bindValue(':o', preg_replace('/[\r\n\t ]/', '', trim($in['owner_id'] ?? '')), SQLITE3_TEXT);
            $stmt->bindValue(':n', (int)($in['stat_native'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':a', (int)($in['stat_abeast'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':m', (int)($in['stat_machine'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':d', (int)($in['stat_dark'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':h', (int)($in['stat_hit'] ?? 0), SQLITE3_INTEGER);
            $stmt->execute();
            $result = ['ok' => true];
            break;
        }
        case 'getToken': {
            $cleanT = preg_replace('/[\r\n\t ]/', '', trim($in['tokenId'] ?? ''));
            $stmt = $db->prepare("SELECT * FROM tekker_tokens WHERE trim(token_id, char(13)||char(10)||' '||char(9)) = :t");
            $stmt->bindValue(':t', $cleanT, SQLITE3_TEXT);
            $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($r) {
                $r['token_id'] = preg_replace('/[\r\n\t ]/', '', trim($r['token_id']));
                $r['owner_id'] = preg_replace('/[\r\n\t ]/', '', trim($r['owner_id']));
            }
            $result = $r ? $r : null;
            break;
        }
        case 'getUnclaimedTokens': {
            $cleanO = preg_replace('/[\r\n\t ]/', '', trim($in['ownerId'] ?? ''));
            $stmt = $db->prepare("SELECT * FROM tekker_tokens WHERE trim(owner_id, char(13)||char(10)||' '||char(9)) = :o AND is_claimed = 0 ORDER BY created_at DESC");
            $stmt->bindValue(':o', $cleanO, SQLITE3_TEXT);
            $res = $stmt->execute();
            $rows = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $row['token_id'] = preg_replace('/[\r\n\t ]/', '', trim($row['token_id']));
                $row['owner_id'] = preg_replace('/[\r\n\t ]/', '', trim($row['owner_id']));
                $rows[] = $row;
            }
            $result = $rows;
            break;
        }
        case 'getAllTokens': {
            $res = $db->query("SELECT * FROM tekker_tokens ORDER BY created_at DESC");
            $rows = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $row['token_id'] = preg_replace('/[\r\n\t ]/', '', trim($row['token_id']));
                $row['owner_id'] = preg_replace('/[\r\n\t ]/', '', trim($row['owner_id']));
                $rows[] = $row;
            }
            $result = $rows;
            break;
        }
        case 'transferToken': {
            $cleanT = preg_replace('/[\r\n\t ]/', '', trim($in['tokenId'] ?? ''));
            $cleanO = preg_replace('/[\r\n\t ]/', '', trim($in['newOwnerId'] ?? ''));
            $stmt = $db->prepare("UPDATE tekker_tokens SET owner_id = :o WHERE trim(token_id, char(13)||char(10)||' '||char(9)) = :t");
            $stmt->bindValue(':o', $cleanO, SQLITE3_TEXT);
            $stmt->bindValue(':t', $cleanT, SQLITE3_TEXT);
            $stmt->execute();
            $result = ['ok' => true];
            break;
        }
        case 'markTokenClaimed': {
            $cleanT = preg_replace('/[\r\n\t ]/', '', trim($in['tokenId'] ?? ''));
            $stmt = $db->prepare("UPDATE tekker_tokens SET is_claimed = 1, claimed_by = :c, claimed_at = datetime('now') WHERE trim(token_id, char(13)||char(10)||' '||char(9)) = :t");
            $stmt->bindValue(':c', trim($in['claimerId'] ?? ''), SQLITE3_TEXT);
            $stmt->bindValue(':t', $cleanT, SQLITE3_TEXT);
            $stmt->execute();
            $result = ['ok' => true];
            break;
        }
        case 'deleteToken': {
            $cleanT = preg_replace('/[\r\n\t ]/', '', trim($in['tokenId'] ?? ''));
            $stmt = $db->prepare("DELETE FROM tekker_tokens WHERE trim(token_id, char(13)||char(10)||' '||char(9)) = :t");
            $stmt->bindValue(':t', $cleanT, SQLITE3_TEXT);
            $stmt->execute();
            $result = ['ok' => true, 'deleted' => $db->changes()];
            break;
        }
        case 'setTokenClaimed': {
            $cleanT = preg_replace('/[\r\n\t ]/', '', trim($in['tokenId'] ?? ''));
            if (!empty($in['claimed'])) {
                $stmt = $db->prepare("UPDATE tekker_tokens SET is_claimed = 1, claimed_by = :c, claimed_at = datetime('now') WHERE trim(token_id, char(13)||char(10)||' '||char(9)) = :t");
                $stmt->bindValue(':c', trim($in['claimerId'] ?? ''), SQLITE3_TEXT);
            } else {
                $stmt = $db->prepare("UPDATE tekker_tokens SET is_claimed = 0, claimed_by = NULL, claimed_at = NULL WHERE trim(token_id, char(13)||char(10)||' '||char(9)) = :t");
            }
            $stmt->bindValue(':t', $cleanT, SQLITE3_TEXT);
            $stmt->execute();
            $result = ['ok' => true];
            break;
        }
        default:
            echo json_encode(['success' => false, 'error' => "Unknown tekker_db op: " . $op]);
            exit;
    }
    echo json_encode(['success' => true, 'result' => $result]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
