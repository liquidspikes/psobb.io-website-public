<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
// Strip any CRLF, whitespace, or non-hex characters from token to prevent line-wrapping breakage
$token = preg_replace('/[^a-f0-9]/i', '', trim($input['token'] ?? ''));
$password = trim($input['password'] ?? '');

if (!$token || !$password || strlen($token) !== 64) {
    echo json_encode(['error' => 'Missing token or password']);
    exit;
}

if (strlen($password) > 16 || preg_match('/\s/', $password)) {
    echo json_encode(['error' => 'Password must be max 16 chars and no spaces']);
    exit;
}

try {
    $db = get_db();

    // 1. Verify Token
    $stmt = $db->prepare("SELECT email, username, expires_at FROM password_resets WHERE token = :t");
    $stmt->bindValue(':t', $token);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);

    if (!$row) {
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }

    if (!empty($row['expires_at']) && (int)$row['expires_at'] < time()) {
        $delStmt = $db->prepare("DELETE FROM password_resets WHERE token = :t");
        $delStmt->bindValue(':t', $token);
        $delStmt->execute();
        echo json_encode(['error' => 'Reset token has expired. Please request a new one.']);
        exit;
    }

    $email = $row['email'] ?? '';
    $resetUsername = $row['username'] ?? '';

    // 2. Get Account Info
    $userRow = null;
    if (!empty($email)) {
        $stmt = $db->prepare("SELECT account_id, username, email, language FROM users WHERE email = :e COLLATE NOCASE");
        $stmt->bindValue(':e', $email);
        $res = $stmt->execute();
        $userRow = $res->fetchArray(SQLITE3_ASSOC);
    }
    if (!$userRow && !empty($resetUsername)) {
        $stmt = $db->prepare("SELECT account_id, username, email, language FROM users WHERE username = :u COLLATE NOCASE");
        $stmt->bindValue(':u', $resetUsername);
        $res = $stmt->execute();
        $userRow = $res->fetchArray(SQLITE3_ASSOC);
    }

    if (!$userRow) {
        echo json_encode(['error' => 'User account not found']);
        exit;
    }

    $username = strtolower($userRow['username']);
    $account_id = $userRow['account_id'];
    $hexId = sprintf('%08X', $account_id);
    $email = $userRow['email'] ?: $email;

    // 3. Update Password in Newserv
    // Delete old license (admin force)
    run_shell("delete-license $hexId BB $username");

    // Add new license
    $res = run_shell("add-license $hexId BB $username $password");
    $json = json_decode($res, true);

    // 4. Cleanup and Respond
    if ($json && isset($json['result']) && stripos($json['result'], 'updated') !== false) {
        // Delete used token
        $delStmt = $db->prepare("DELETE FROM password_resets WHERE token = :t");
        $delStmt->bindValue(':t', $token);
        $delStmt->execute();

        // Notification email
        $srvName = get_server_name();
        $lang_pref = $userRow['language'] ?? 'en';
        if ($lang_pref === 'jp') {
            send_email($email, "パスワード再設定完了 - {$srvName}", "{$username} さん、\n\n{$srvName} のパスワードが正常にリセットされました。\n\n心当たりがない場合は、直ちに管理者にご連絡ください。\n\n{$srvName} チーム");
        } elseif ($lang_pref === 'ru') {
            send_email($email, "Пароль успешно сброшен - {$srvName}", "Здравствуйте, {$username},\n\nВаш пароль на сервере {$srvName} был успешно сброшен.\n\nЕсли это были не вы, немедленно свяжитесь с администрацией сервера.\n\nКоманда {$srvName}");
        } else {
            send_email($email, "Password Changed - {$srvName}", "Hello {$username},\n\nYour password has been successfully reset on {$srvName}.\n\nIf this wasn't you, please contact an admin immediately.\n\n{$srvName} Team");
        }
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Server failed to update password. Check admin logs.', 'debug' => $res]);
    }
} catch (Throwable $e) {
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
