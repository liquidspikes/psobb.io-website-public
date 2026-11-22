<?php
/**
 * PSOBB API: Switch Character
 * 
 * Executed via NewServ shell command to instantly switch the player's active 
 * online character to another slot (1-4) without disconnecting.
 * Requires the player to be online and in a lobby block, not actively inside a game room.
 */
require_once __DIR__ . '/config.php';

if (ob_get_length()) ob_clean();

start_secure_session();
header('Content-Type: application/json');
verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');

// 1. Verify user is logged in
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['account_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in', 'success' => false]);
    exit;
}

$accountId = (int)$_SESSION['user']['account_id'];

// 2. Validate slot index input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['slot'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid slot index', 'success' => false]);
    exit;
}

$slot = clamp((int)$input['slot'], 0, 3);
$targetSlotNum = $slot + 1; // 1-based character slot for newserv command

// 3-4. Verify player is online in lobby block (not inside an active game)
newserv_require_online_character((int) $accountId, null, [
    'include_success_false'   => true,
    'offline_http_code'       => 502,
    'offline_error'           => 'Failed to connect to the game server. Please try again later.',
    'not_online_http_code'    => 400,
    'not_online_error'        => 'You must be logged into a character in-game to switch characters instantly.',
    'check_loading'           => false,
    'forbid_in_game'          => true,
    'in_game_forbidden_error' => 'You cannot switch characters while actively inside a game. Please return to the lobby first!',
]);

// 5. Execute the switch character command
$accountIdHex = dechex($accountId);
$switchCmd = 'on ' . $accountIdHex . ' cc $switchchar ' . $targetSlotNum;
$result = newserv_shell_exec($switchCmd);

if ($result === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to trigger character switch on the game server.', 'success' => false]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => "Character swap command sent! Swapping instantly to Slot {$targetSlotNum}..."
]);
?>
