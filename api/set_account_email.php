<?php
/**
 * PSOBB API: Set / Update Account Recovery Email
 * 
 * Allows an authenticated user to request linking a real recovery email address.
 * Generates a secure confirmation token and sends a confirmation link to the email.
 * The email is only confirmed and linked once the confirmation link is clicked.
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

    // Check if the account already has this exact email confirmed on file
    $currStmt = $db->prepare("SELECT email, language FROM users WHERE account_id = :aid OR username = :u");
    $currStmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
    $currStmt->bindValue(':u', $username, SQLITE3_TEXT);
    $userRow = $currStmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($userRow && strcasecmp(trim($userRow['email'] ?? ''), $email) === 0) {
        echo json_encode(["success" => false, "error" => "This email address is already linked to your account."]);
        exit;
    }

    // 4. Ensure email_confirmations table exists
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

    // 5. Generate Secure Confirmation Token
    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + 86400; // 24 hours

    // Invalidate any older unconfirmed tokens for this account
    $del = $db->prepare("DELETE FROM email_confirmations WHERE account_id = :aid AND confirmed_at IS NULL");
    $del->bindValue(':aid', $accountId, SQLITE3_INTEGER);
    $del->execute();

    // Store new pending confirmation
    $ins = $db->prepare("INSERT INTO email_confirmations (account_id, username, new_email, token, expires_at) VALUES (:aid, :u, :e, :t, :exp)");
    $ins->bindValue(':aid', $accountId, SQLITE3_INTEGER);
    $ins->bindValue(':u', $username, SQLITE3_TEXT);
    $ins->bindValue(':e', $email, SQLITE3_TEXT);
    $ins->bindValue(':t', $token, SQLITE3_TEXT);
    $ins->bindValue(':exp', $expiresAt, SQLITE3_INTEGER);
    $ins->execute();

    // 6. Build Confirmation Link & Send Email
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 0) == 443) ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'psobb.io';
    $confirmLink = "$protocol://$host/confirm_email.php?token=$token";

    $lang_pref = $userRow['language'] ?? ($_COOKIE['psobb_lang'] ?? 'en');
    if ($lang_pref === 'jp') {
        $subject = "リカバリー用メールアドレスの確認 - PSOBB.IO";
        $msg = "$username さん、\n\nPSOBB.IOアカウント ($username) のリカバリー用メールアドレスとして、このアドレス ($email) を登録するリクエストを受け付けました。\n\n以下のリンクをクリックして、メールアドレスの登録を完了してください：\n$confirmLink\n\nこのリンクは24時間有効です。\n\n心当たりがない場合は、このメールを無視してください。メールアドレスは変更されません。\n\n良い狩りを！\nPSOBB.IO チーム";
    } else {
        $subject = "Confirm Your Recovery Email - PSOBB.IO";
        $msg = "Hello $username,\n\nYou requested to link this email address ($email) as the recovery email for your PSOBB.IO account ($username).\n\nPlease click the link below to confirm and activate this email address:\n$confirmLink\n\nThis confirmation link will expire in 24 hours.\n\nIf you did not request this, please ignore this email. Your recovery email will not be changed.\n\nHappy Hunting,\nPSOBB.IO Team";
    }
    @send_email($email, $subject, $msg);

    echo json_encode([
        "success" => true,
        "pending" => true,
        "email" => $email,
        "message" => "Confirmation email sent! Please check your inbox and click the confirmation link to finish linking your email."
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
}
?>
