<?php
/**
 * PSOBB API: Recalibrate / Reset Stat Materials
 * 
 * Safely resets all consumed materials (HP, TP, Power, Mind, Evade, Def, Luck) 
 * back to 0. 
 * - When the character is online in a Lobby Block: uses NewServ's in-game command ($edit material reset every)
 *   to safely reset and recompute stats in-game, then triggers $save. Prevents resets in active game rooms
 *   or offline (since newserv caches .psochar state in memory and flushes every 60s).
 */
require_once 'config.php';
require_once 'db.php';
start_secure_session();

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');
verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');

// 1. Verify Authentication
if (empty($_SESSION['user']) || empty($_SESSION['user']['account_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not logged in"]);
    exit;
}

$accountId = (int)$_SESSION['user']['account_id'];

// Retrieve BB username from Session user data
$username = '';
if (isset($_SESSION['user']['BBLicenses']) && is_array($_SESSION['user']['BBLicenses']) && count($_SESSION['user']['BBLicenses']) > 0) {
    $username = $_SESSION['user']['BBLicenses'][0]['UserName'] ?? '';
}
if (empty($username)) {
    $username = $_SESSION['user']['LastPlayerName'] ?? $_SESSION['user']['username'] ?? '';
}
$username = strtolower(trim($username));

// Get character slot (0 to 126 for MoreSaveSlots support)
$input = json_decode(file_get_contents('php://input'), true);
$slot = isset($input['slot']) ? max(0, min(126, (int)$input['slot'])) : 0;

if (empty($username)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Missing account username context."]);
    exit;
}

// Check online state via NewServ
$clientsUrl = $NEWSERV_API_URL . '/y/clients';
$clientsResponse = @file_get_contents($clientsUrl);
$isOnline = false;
$onlineCharName = '';
$onlineSlot = null;
$targetIdent = $username;

if ($clientsResponse !== FALSE) {
    $clientsData = json_decode($clientsResponse, true);
    if (is_array($clientsData)) {
        foreach ($clientsData as $c) {
            if (isset($c['Account']['AccountID']) && (int)$c['Account']['AccountID'] === $accountId) {
                $isOnline = true;
                $onlineCharName = $c['Name'] ?? '';
                if (isset($c['BBCharacterIndex']) && is_numeric($c['BBCharacterIndex'])) {
                    $onlineSlot = (int)$c['BBCharacterIndex'];
                }
                if (!empty($c['Account']['BBLicenses'][0]['UserName'])) {
                    $targetIdent = $c['Account']['BBLicenses'][0]['UserName'];
                }
                break;
            }
        }
    }
}

// Path to players folder
$playersDir = get_newserv_players_dir();

$psocharPath = resolve_player_file($playersDir, "player_{$username}_{$slot}.psochar");

if (!file_exists($psocharPath)) {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Character file for Slot " . ($slot + 1) . " does not exist."]);
    exit;
}

$charData = @file_get_contents($psocharPath);
if ($charData === false || strlen($charData) < 0x399C) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Failed to read character file binary data safely."]);
    exit;
}

// Decode UTF16_ALWAYS_MARKED name at offset 852 + 116 = 968 (strips \tE/\tJ/\tB/\tT/\tK, preserves \tC color codes)
$slotCharName = decode_psochar_name($charData, false);

// -------------------------------------------------------------------------
// Case A: Character is Online
// -------------------------------------------------------------------------
if ($isOnline) {
    // 1. Verify the online character matches the selected slot (prefer exact BBCharacterIndex from NewServ)
    $slotMatches = ($onlineSlot !== null)
        ? ($onlineSlot === $slot)
        : (strcasecmp($onlineCharName, $slotCharName) === 0 || strcasecmp(trim($onlineCharName), trim($slotCharName)) === 0);
    if (!$slotMatches) {
        http_response_code(400);
        echo json_encode([
            "success" => false, 
            "error" => "You are currently online in-game as character '{$onlineCharName}'. You cannot reset materials for slot " . ($slot + 1) . " ('{$slotCharName}') while playing on a different character. Please log out first!"
        ]);
        exit;
    }

    // 2. Double check if player is in an active game room vs lobby block
    $lobbiesUrl = $NEWSERV_API_URL . '/y/lobbies';
    $lobbiesResponse = @file_get_contents($lobbiesUrl);
    $inGame = false;
    
    if ($lobbiesResponse !== FALSE) {
        $lobbiesData = json_decode($lobbiesResponse, true);
        if (is_array($lobbiesData)) {
            foreach ($lobbiesData as $lobby) {
                if (!empty($lobby['IsGame']) && $lobby['IsGame'] == true) {
                    if (isset($lobby['Clients']) && is_array($lobby['Clients'])) {
                        foreach ($lobby['Clients'] as $lobbyClient) {
                            if ($lobbyClient !== null && isset($lobbyClient['Account']['AccountID']) && (int)$lobbyClient['Account']['AccountID'] === $accountId) {
                                $inGame = true;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }
    
    if ($inGame) {
        http_response_code(403);
        echo json_encode(["success" => false, "error" => "You cannot reset materials while actively in a game. Please return to the lobby block or log out first!"]);
        exit;
    }
    
    // 2. Perform safe reset in the lobby block using NewServ commands (use BB username to avoid numeric ID ambiguity)
    $cmdPrefix = !empty($NEWSERV_COMMAND_PREFIX) ? $NEWSERV_COMMAND_PREFIX : '$';
    $resetCmd = 'on ' . $targetIdent . ' cc ' . $cmdPrefix . 'edit material reset every';
    $saveCmd = 'on ' . $targetIdent . ' cc ' . $cmdPrefix . 'save';

    $res1 = run_shell_command($resetCmd);
    $res2 = run_shell_command($saveCmd);

    $checkShellOk = static function ($rawRes): bool {
        if ($rawRes === false || $rawRes === '') {
            return false;
        }
        $data = json_decode($rawRes, true);
        if (!is_array($data) || !empty($data['Error'])) {
            return false;
        }
        if (isset($data['result'])) {
            $rLower = strtolower((string)$data['result']);
            if (strpos($rLower, 'error') !== false || strpos($rLower, 'not found') !== false || strpos($rLower, 'failed') !== false) {
                return false;
            }
        }
        return true;
    };

    if (!$checkShellOk($res1) || !$checkShellOk($res2)) {
        http_response_code(502);
        echo json_encode(["success" => false, "error" => "Failed to trigger live material reset on the game server."]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => __("Stat materials successfully reset to 0 in-game! Tip: Unequip your Mag and Units before feeding new Materials so equipped bonuses don't hit your class stat cap early.")
    ]);
    exit;
}

// -------------------------------------------------------------------------
// Case B: Character is Offline
// -------------------------------------------------------------------------
// newserv caches player saves in memory and system/players/*.psochar is root:www-data 0640,
// so direct offline binary writes fail under www-data or are overwritten by in-memory flushes.
http_response_code(400);
echo json_encode([
    "success" => false,
    "error" => "Please log into a Lobby Block in-game on '{$slotCharName}' (Slot " . ($slot + 1) . ") to reset materials safely."
]);
?>
