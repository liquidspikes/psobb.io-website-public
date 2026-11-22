<?php
/**
 * PSOBB API: Web-to-Game Chat Message
 * 
 * Secure endpoint that sends chat messages typed from the dashboard Web Portal
 * straight to the player's active in-game lobby/room, operating as a vital QoL
 * keyboard bypass for Steam Deck and mobile sessions.
 */
require_once 'config.php';
require_once 'db.php';
start_secure_session();

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');
verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');

// 1. Verify User Login
if (empty($_SESSION['user']) || empty($_SESSION['user']['account_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not logged in"]);
    exit;
}

if (!can_access_portal_module('chat')) {
    http_response_code(403);
    echo json_encode(["success" => false, "error" => "Ragol chat portal module is currently unavailable."]);
    exit;
}

$accountId = (int)$_SESSION['user']['account_id'];

// 2. Validate Inputs
$input = json_decode(file_get_contents('php://input'), true);
$characterName = trim($input['character_name'] ?? '');
$messageText = trim($input['message'] ?? '');

if (empty($characterName) || empty($messageText)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Character name and message text are required."]);
    exit;
}

// Enforce message length restriction for in-game chat buffer
if (mb_strlen($messageText) > 64) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Message exceeds the in-game limit of 64 characters."]);
    exit;
}

// 3. Verify character is online via NewServ
$foundClient = newserv_require_online_character((int) $accountId, $characterName, [
    'include_success_false' => true,
    'offline_http_code'     => 502,
    'offline_error'         => 'Failed to reach the game server to verify player status.',
    'not_online_http_code'  => 403,
    'not_online_error'      => 'The character ' . htmlspecialchars($characterName) . ' is not currently online. Please log in-game first!',
    'check_loading'         => false,
]);

// 4. Send command to NewServ shell-exec
// newserv: on <hex_account_id> c <message>
$accountIdHex = dechex($accountId);
$chatCmd = 'on ' . $accountIdHex . ' c ' . $messageText;

$execRes = newserv_shell_exec($chatCmd);

if ($execRes === false) {
    http_response_code(502);
    echo json_encode(["success" => false, "error" => "Failed to transmit message through the game server API."]);
    exit;
}

echo json_encode([
    "success" => true,
    "message" => "Message broadcasted inside your game lobby successfully!"
]);
?>
