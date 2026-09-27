<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$rawEmail = (string)($input['email'] ?? '');

if (preg_match('/[\r\n]/', $rawEmail)) {
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

$email = strtolower(trim($rawEmail));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

try {
    $db = get_db();

    // Check if email exists (Case Insensitive)
    $stmt = $db->prepare("SELECT username, email, language FROM users WHERE email = :e COLLATE NOCASE");
    $stmt->bindValue(':e', $email);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);

    if (!$row) {
        // Return success to prevent email enumeration
        echo json_encode(['success' => true, 'message' => 'If this email exists, a reset link has been sent.']);
        exit;
    }

    $username = strtolower($row['username']);
    $token = bin2hex(random_bytes(32));
    $expires_at = time() + 3600; // 1 hour

    // Store Token with all schema columns supported
    $stmt = $db->prepare("INSERT INTO password_resets (token, username, email, expires_at) VALUES (:t, :u, :e, :exp)");
    $stmt->bindValue(':t', $token);
    $stmt->bindValue(':u', $username);
    $stmt->bindValue(':e', $email);
    $stmt->bindValue(':exp', $expires_at);
    $stmt->execute();

    // Construct Link
    $srvName = get_server_name();
    $srvAddr = get_server_address();
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https" : "http";
    $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $srvAddr;
    $link = "$protocol://$host/reset_password.php?token=$token";

    $lang_pref = $row['language'] ?? 'en';
    if ($lang_pref === 'jp') {
        $subject = "パスワード再設定リクエスト - {$srvName}";
        $message = "$username さん、\n\n{$srvName}アカウントのパスワード再設定リクエストを受け付けました。\n\n以下のリンクをクリックして新しいパスワードを設定してください：\n$link\n\nこのリンクは1時間有効です。\n\n心当たりがない場合は、このメールを無視してください。";
    } elseif ($lang_pref === 'ru') {
        $subject = "Запрос на сброс пароля - {$srvName}";
        $message = "Здравствуйте, $username,\n\nМы получили запрос на сброс пароля для вашей учетной записи {$srvName}.\n\nПерейдите по ссылке ниже, чтобы подтвердить ваш email и установить новый пароль:\n$link\n\nЭта ссылка действительна в течение 1 часа.\n\nЕсли вы не отправляли этот запрос, просто проигнорируйте это письмо.";
    } else {
        $subject = "Password Reset Request - {$srvName}";
        $message = "Hello $username,\n\nWe received a request to reset your password for your {$srvName} account.\n\nClick the link below to verify your email and set a new password:\n$link\n\nThis link will expire in 1 hour.\n\nIf you did not request this, please ignore this email.";
    }

    if (send_email($email, $subject, $message)) {
        echo json_encode(['success' => true, 'message' => 'Reset link sent to your email. Check your spam folder.']);
    } else {
        echo json_encode(['error' => 'Failed to send email. Server configuration issue.']);
    }
} catch (Throwable $e) {
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>
