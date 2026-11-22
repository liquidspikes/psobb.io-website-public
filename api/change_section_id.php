<?php
/**
 * PSOBB API: Change Section ID
 * 
 * Allows a user to change the Section ID of one of their characters.
 * Deducts 50,000 Meseta from the character's bank via the NewServ API.
 * The character must be offline to perform this action.
 */
require_once 'config.php';

if (ob_get_length()) ob_clean();

start_secure_session();
header('Content-Type: application/json');
verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');

// 1. Verify Login
if (empty($_SESSION['user']) || empty($_SESSION['user']['account_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$character_name = $input['character_name'] ?? '';
$new_section_id = $input['new_section_id'] ?? '';

if (empty($character_name) || empty($new_section_id)) {
    http_response_code(400);
    echo json_encode(["error" => "Missing character name or new Section ID"]);
    exit;
}

// Allowed Section IDs
$allowed_section_ids = PSO_SECTION_IDS;

if (!in_array($new_section_id, $allowed_section_ids)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid Section ID selected"]);
    exit;
}

$targetAccountId = $_SESSION['user']['account_id'];

// 2. Verify online character & lobby state (cannot change Section ID while inside a game)
$foundClient = newserv_require_online_character((int) $targetAccountId, $character_name, [
    'offline_error'           => 'Game server is currently offline',
    'not_online_http_code'    => 404,
    'not_online_error'        => 'Character not found or not currently online. You must be logged in-game to change your Section ID.',
    'check_loading'           => false,
    'forbid_in_game'          => true,
    'in_game_forbidden_error' => 'You cannot change your Section ID while actively in a game. Please return to the lobby block to change it!',
]);

// 3. Verify Level <= 50
$level = $foundClient['Level'] ?? 0;
if ($level > 50) {
    http_response_code(403);
    echo json_encode(["error" => "Only characters level 50 and below can change their Section ID."]);
    exit;
}

// 4. Send the Commands to the Server
// Use account ID (hex) to identify the client — the `on` command's parser
// uses skip_non_whitespace which doesn't handle quoted strings, so names
// with spaces or quotes would break. Account ID is always unambiguous.
$account_id_hex = dechex($targetAccountId);

// Execute the change
$change_cmd = 'on ' . $account_id_hex . ' cc $edit secid ' . strtolower($new_section_id);
$res1 = newserv_shell_exec($change_cmd);

if ($res1 === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to connect to game server shell API.']);
    exit;
}

// Force a save to cleanly write it to the .psochar
$save_cmd = 'on ' . $account_id_hex . ' cc $save';
newserv_shell_exec($save_cmd);

echo json_encode(["success" => true, "message" => "Section ID changed successfully! Check your Guild Card or Lobby Info (\$li) in-game."]);
?>
