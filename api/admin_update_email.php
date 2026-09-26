<?php
/**
 * PSOBB API: Admin Update Account Email
 * 
 * Allows a logged-in administrator to modify or assign the recovery email
 * for any account (including legacy accounts).
 */
error_reporting(0);
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';

if (ob_get_length()) ob_clean();
start_secure_session();
header('Content-Type: application/json');

verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');

// 1. Check Admin Permissions
if (empty($_SESSION['user']) || empty($_SESSION['user']['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied']);
    exit;
}

// 2. Parse & Validate Payload
$input = json_decode(file_get_contents('php://input'), true);
$target_account_id = isset($input['account_id']) && is_numeric($input['account_id']) ? (int)$input['account_id'] : null;
$username = strtolower(trim($input['username'] ?? ''));
$new_email = strtolower(trim($input['email'] ?? ''));

if (!$target_account_id && empty($username)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Account ID or Username required.']);
    exit;
}

if (empty($new_email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email address cannot be empty.']);
    exit;
}

if (!filter_var($new_email, FILTER_VALIDATE_EMAIL) || strlen($new_email) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email address format.']);
    exit;
}

if (preg_match('/_legacy@psobb\.io$/i', $new_email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Cannot use legacy placeholder as recovery email.']);
    exit;
}

try {
    $db = get_db();

    // 3. Resolve Account ID or Username if one is missing
    if (!$target_account_id || empty($username)) {
        if ($target_account_id) {
            $stmt = $db->prepare("SELECT username FROM users WHERE account_id = :aid");
            $stmt->bindValue(':aid', $target_account_id, SQLITE3_INTEGER);
            $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($res && !empty($res['username'])) {
                $username = strtolower($res['username']);
            }
        } else if (!empty($username)) {
            $stmt = $db->prepare("SELECT account_id FROM users WHERE username = :u COLLATE NOCASE");
            $stmt->bindValue(':u', $username, SQLITE3_TEXT);
            $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($res && !empty($res['account_id'])) {
                $target_account_id = (int)$res['account_id'];
            }
        }
    }

    // Fallback: If still missing account ID or username, query NewServ accounts API
    if (!$target_account_id || empty($username)) {
        $url = $NEWSERV_API_URL . "/y/accounts";
        $data = @file_get_contents($url);
        if ($data !== false) {
            $accounts = json_decode($data, true);
            if (is_array($accounts)) {
                foreach ($accounts as $acc) {
                    $accId = (int)($acc['AccountID'] ?? 0);
                    $bbUser = '';
                    if (isset($acc['BBLicenses']) && is_array($acc['BBLicenses']) && count($acc['BBLicenses']) > 0) {
                        $bbUser = strtolower(trim($acc['BBLicenses'][0]['UserName'] ?? ''));
                    }
                    if (($target_account_id && $accId === $target_account_id) || (!empty($username) && $bbUser === $username)) {
                        $target_account_id = $accId;
                        if (empty($username)) $username = $bbUser;
                        break;
                    }
                }
            }
        }
    }

    if (!$target_account_id && empty($username)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Target account could not be found.']);
        exit;
    }

    // 4. Ensure Uniqueness: Check if new email is taken by another account
    $stmt = $db->prepare("SELECT id, username FROM users WHERE email = :e COLLATE NOCASE AND (:aid IS NULL OR account_id != :aid) AND (:u = '' OR username != :u COLLATE NOCASE)");
    $stmt->bindValue(':e', $new_email, SQLITE3_TEXT);
    if ($target_account_id) {
        $stmt->bindValue(':aid', $target_account_id, SQLITE3_INTEGER);
    } else {
        $stmt->bindValue(':aid', null, SQLITE3_NULL);
    }
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $duplicate = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($duplicate) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => "Email already belongs to user: {$duplicate['username']}"]);
        exit;
    }

    // 5. Update or Self-Heal Insert in SQLite users table
    $stmt = $db->prepare("SELECT id, language FROM users WHERE (:aid IS NOT NULL AND account_id = :aid) OR (:u != '' AND username = :u COLLATE NOCASE)");
    if ($target_account_id) {
        $stmt->bindValue(':aid', $target_account_id, SQLITE3_INTEGER);
    } else {
        $stmt->bindValue(':aid', null, SQLITE3_NULL);
    }
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $userRow = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($userRow) {
        $upd = $db->prepare("UPDATE users SET email = :e WHERE id = :id");
        $upd->bindValue(':e', $new_email, SQLITE3_TEXT);
        $upd->bindValue(':id', (int)$userRow['id'], SQLITE3_INTEGER);
        $upd->execute();
    } else {
        // Provision row for accounts created in-game that have not yet logged into the website
        $ins = $db->prepare("INSERT INTO users (username, email, account_id, receive_system_mail, receive_discord_streak_msg) VALUES (:u, :e, :aid, 1, 1)");
        $ins->bindValue(':u', $username, SQLITE3_TEXT);
        $ins->bindValue(':e', $new_email, SQLITE3_TEXT);
        $ins->bindValue(':aid', $target_account_id ?: 0, SQLITE3_INTEGER);
        $ins->execute();
    }

    // 6. Notify the user of the admin email update
    $lang_pref = $userRow['language'] ?? 'en';
    if ($lang_pref === 'jp') {
        $subject = "リカバリー用メールアドレス変更のお知らせ - PSOBB.IO";
        $msg = "$username さん、\n\n管理者によりPSOBB.IOアカウント ($username) のリカバリー用メールアドレスが更新されました。\n新しいメールアドレス: $new_email\n\n今後はこのメールアドレスを使用してパスワードの再設定が可能です。\n心当たりがない場合は、直ちに管理者にご連絡ください。\n\n良い狩りを！\nPSOBB.IO チーム";
    } else {
        $subject = "Account Recovery Email Updated - PSOBB.IO";
        $msg = "Hello $username,\n\nAn administrator has updated the recovery email address for your PSOBB.IO account ($username).\nNew recovery email: $new_email\n\nYou can now use this email address to reset your password if needed at:\nhttps://psobb.io/forgot_password.php\n\nIf you did not request this change, please contact an administrator immediately.\n\nHappy Hunting,\nPSOBB.IO Team";
    }
    @send_email($new_email, $subject, $msg);

    echo json_encode([
        'success' => true,
        'message' => "Recovery email updated to {$new_email}.",
        'email' => $new_email,
        'account_id' => $target_account_id,
        'username' => $username
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
