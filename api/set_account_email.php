<?php
/**
 * PSOBB API: Set / Update Account Recovery Email
 * 
 * Allows an authenticated user (especially legacy game accounts with placeholder emails)
 * to link a real email address for password recovery via /forgot_password.
 */
error_reporting(0);
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';

if (ob_get_length()) ob_clean();
start_secure_session();
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? $_POST['csrf_token'] ?? '');

// 1. Verify Authentication
if (empty($_SESSION['user']) || empty($_SESSION['user']['account_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not logged in"]);
    exit;
}

$accountId = (int)$_SESSION['user']['account_id'];
$username = strtolower(trim($_SESSION['user']['username'] ?? ''));

// 2. Parse & Validate Input
$rawEmail = (string)($input['email'] ?? '');

// Strict CRLF Injection Prevention: reject any carriage returns or line feeds
if (preg_match('/[\r\n]/', $rawEmail)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Email address cannot contain carriage returns or newlines."]);
    exit;
}

$email = strtolower(trim($rawEmail));

if (empty($email)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Email address cannot be empty."]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100 || !preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $email)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid email address format."]);
    exit;
}

if (preg_match('/_legacy@psobb\.io$/i', $email)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Cannot use legacy placeholder as recovery email."]);
    exit;
}

try {
    $db = get_db();

    // 3. Uniqueness Check: Ensure email is not already taken by another account
    $stmt = $db->prepare("SELECT id FROM users WHERE email = :e COLLATE NOCASE AND account_id != :aid");
    $stmt->bindValue(':e', $email, SQLITE3_TEXT);
    $stmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($existing) {
        http_response_code(409);
        echo json_encode(["success" => false, "error" => "This email address is already linked to another account."]);
        exit;
    }

    // 4. Update or Insert User Record
    $stmt = $db->prepare("SELECT id, language FROM users WHERE account_id = :aid OR username = :u");
    $stmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $userRow = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($userRow) {
        $upd = $db->prepare("UPDATE users SET email = :e WHERE id = :id");
        $upd->bindValue(':e', $email, SQLITE3_TEXT);
        $upd->bindValue(':id', (int)$userRow['id'], SQLITE3_INTEGER);
        $upd->execute();
    } else {
        $ins = $db->prepare("INSERT INTO users (username, email, account_id, receive_system_mail, receive_discord_streak_msg) VALUES (:u, :e, :aid, 1, 1)");
        $ins->bindValue(':u', $username, SQLITE3_TEXT);
        $ins->bindValue(':e', $email, SQLITE3_TEXT);
        $ins->bindValue(':aid', $accountId, SQLITE3_INTEGER);
        $ins->execute();
    }

    // 5. Update Active Session State
    $_SESSION['user']['email'] = $email;
    $_SESSION['user']['has_email'] = true;
    $_SESSION['user']['is_legacy_email'] = false;

    // 6. Send Confirmation Email to the newly linked address
    $lang_pref = $userRow['language'] ?? ($_COOKIE['psobb_lang'] ?? 'en');
    if ($lang_pref === 'jp') {
        $subject = "リカバリー用メールアドレス設定完了 - PSOBB.IO";
        $msg = "$username さん、\n\nPSOBB.IOアカウント ($username) にリカバリー用メールアドレスが正常に設定されました。\n今後はパスワードを忘れた場合でも、以下のURLからパスワード再設定が可能です：\nhttps://psobb.io/forgot_password.php\n\n心当たりがない場合は、直ちに管理者にご連絡ください。\n\n良い狩りを！\nPSOBB.IO チーム";
    } else {
        $subject = "Recovery Email Linked - PSOBB.IO";
        $msg = "Hello $username,\n\nYour recovery email address has been successfully set for your PSOBB.IO account ($username).\nYou can now use this email address to recover your password at:\nhttps://psobb.io/forgot_password.php\n\nIf you did not make this change, please contact an administrator immediately.\n\nHappy Hunting,\nPSOBB.IO Team";
    }
    @send_email($email, $subject, $msg);

    echo json_encode([
        "success" => true,
        "email" => $email,
        "message" => "Recovery email successfully linked! You can now use it to reset your password."
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
}
?>
