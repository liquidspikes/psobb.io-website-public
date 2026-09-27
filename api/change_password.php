<?php
/**
 * PSOBB API: Change Password
 * 
 * Allows an authenticated user to change their account password.
 * Uses the NewServ shell-exec API to delete and recreate the license.
 * Expects JSON payload with 'username', 'old_password', and 'new_password'.
 */
error_reporting(0);
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';
// Clean output
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');
start_secure_session();
verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');

$input = json_decode(file_get_contents('php://input'), true);
$username = strtolower(trim($input['username'] ?? ''));
$old_password = trim($input['old_password'] ?? '');
$new_password = trim($input['new_password'] ?? '');

if (!$username || !$old_password || !$new_password) {
    http_response_code(400);
    echo json_encode(['error' => 'All fields required']);
    exit;
}
if (strlen($new_password) > 16 || preg_match('/\s/', $new_password)) {
    http_response_code(400);
    echo json_encode(['error' => 'New password invalid (max 16 chars, no spaces)']);
    exit;
}

// 1. Authenticate (Find Account ID)
$url = $NEWSERV_API_URL . "/y/accounts";
$data = @file_get_contents($url);

if ($data === FALSE) {
    http_response_code(500);
    echo json_encode(["error" => "Server offline"]);
    exit;
}

$accounts = json_decode($data, true);
$target_id = null;

if (is_array($accounts)) {
    foreach ($accounts as $account) {
        if (isset($account['BBLicenses']) && is_array($account['BBLicenses'])) {
            foreach ($account['BBLicenses'] as $license) {
                if ((strtolower($license['UserName'] ?? '')) === $username && ($license['Password'] ?? '') === $old_password) {
                    $target_id = $account['AccountID'];
                    break 2;
                }
            }
        }
    }
}

if (!$target_id) {
    http_response_code(401);
    echo json_encode(["error" => "Incorrect old password"]);
    exit;
}

$hexId = is_numeric($target_id) ? sprintf('%08X', $target_id) : $target_id;

// 3. Update Password (Delete old license, add new)
run_shell("delete-license $hexId BB $username");

$res = run_shell("add-license $hexId BB $username $new_password");
$json = json_decode($res, true);

if ($json && isset($json['result']) && stripos($json['result'], 'updated') !== false) {
    // Send Confirmation Email
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT email, language FROM users WHERE username = :u COLLATE NOCASE");
        $stmt->bindValue(':u', $username);
        $dbRes = $stmt->execute();
        $row = $dbRes->fetchArray(SQLITE3_ASSOC);
        
        if ($row && !empty($row['email'])) {
            $lang_pref = $row['language'] ?? 'en';
            $srvName = get_server_name();
            if ($lang_pref === 'jp') {
                send_email($row['email'], "パスワード変更完了 - $srvName", "$username さん、\n\nパスワードが正常に変更されました。\n心当たりがない場合は、直ちに管理者にご連絡ください。\n\n$srvName チーム");
            } elseif ($lang_pref === 'ru') {
                send_email($row['email'], "Пароль изменён - $srvName", "Здравствуйте, $username,\n\nВаш пароль был успешно изменён.\nЕсли это были не вы, немедленно свяжитесь с администрацией сервера.\n\nКоманда $srvName");
            } else {
                send_email($row['email'], "Password Changed - $srvName", "Hello $username,\n\nYour password was successfully changed.\nIf this wasn't you, please contact an admin immediately.\n\nHappy Hunting,\n$srvName Team");
            }
        }
    } catch (Exception $e) {
        // Ignore DB/Mail errors for password change success
    }

    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Password update failed on server.']);
}
?>
