<?php
require_once 'config.php';

if (ob_get_length()) ob_clean();

start_secure_session();
header('Content-Type: application/json');
verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');

if (empty($_SESSION['user']['account_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized. Please log in."]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$event_id = intval($input['event_id'] ?? $_POST['event_id'] ?? 0);
$bonus_choice = $input['bonus_choice'] ?? $_POST['bonus_choice'] ?? null;

if (!$event_id) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid event ID"]);
    exit;
}

$accId = $_SESSION['user']['account_id'];

try {
    require_once 'db.php';
    $db = get_db();

    // Verify event is completed, player participated, and hasn't claimed
    $stmt = $db->prepare("SELECT ce.reward_item_string, ce.top_3_reward_item_string, cep.contribution_count 
                          FROM community_event_participants cep
                          JOIN community_events ce ON cep.event_id = ce.id
                          WHERE cep.event_id = :eid AND cep.account_id = :accId AND ce.status = 'completed' AND cep.reward_claimed = 0");
    $stmt->bindValue(':eid', $event_id, SQLITE3_INTEGER);
    $stmt->bindValue(':accId', $accId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $event = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;

    if (!$event) {
        http_response_code(404);
        echo json_encode(["error" => "Event not found, not completed, or reward already claimed."]);
        exit;
    }

    // Check if this player is in the Top 3
    $top3_stmt = $db->prepare("SELECT account_id FROM community_event_participants WHERE event_id = :eid ORDER BY contribution_count DESC LIMIT 3");
    $top3_stmt->bindValue(':eid', $event_id, SQLITE3_INTEGER);
    $top3_res = $top3_stmt->execute();
    
    $is_top_3 = false;
    while ($row = $top3_res->fetchArray(SQLITE3_ASSOC)) {
        if ($row['account_id'] == $accId) {
            $is_top_3 = true;
            break;
        }
    }

    // 1. Fetch online clients from newserv to verify level
    $url = $NEWSERV_API_URL . "/y/clients";
    $data = @file_get_contents($url);

    if ($data === FALSE) {
        http_response_code(500);
        echo json_encode(["error" => "Game server is offline, cannot verify character."]);
        exit;
    }

    $clients = json_decode($data, true);
    $onlineCharacter = null;

    if (is_array($clients)) {
        foreach ($clients as $c) {
            if (isset($c['Account']) && $c['Account']['AccountID'] == $accId) {
                $onlineCharacter = $c;
                break;
            }
        }
    }

    if (!$onlineCharacter) {
        http_response_code(400);
        echo json_encode(["error" => "Character must be online to claim rewards."]);
        exit;
    }

    // Protect against loading screen transitions
    if (!isset($onlineCharacter['EXP']) || ($onlineCharacter['EXP'] === 0 && ($onlineCharacter['Level'] ?? 1) > 1)) {
        http_response_code(400);
        echo json_encode(["error" => "Your character is currently in a loading screen. Please wait until you are fully spawned to claim."]);
        exit;
    }

    $lobbyId = $onlineCharacter['LobbyID'] ?? null;
    if ($lobbyId === null) {
        http_response_code(400);
        echo json_encode(["error" => "Character must be actively logged into a server lobby or game."]);
        exit;
    }

    // 1.5 Fetch lobbies to ensure the player is in an actual game, not just the lobby
    $lobbiesData = @file_get_contents($NEWSERV_API_URL . "/y/lobbies");
    if ($lobbiesData === FALSE) {
        http_response_code(500);
        echo json_encode(["error" => "Game server lobbies offline, cannot verify game status."]);
        exit;
    }

    $lobbies = json_decode($lobbiesData, true);
    $inGame = false;

    if (is_array($lobbies)) {
        foreach ($lobbies as $l) {
            if (isset($l['ID']) && $l['ID'] === $lobbyId) {
                if (!empty($l['IsGame'])) {
                    $inGame = true;
                }
                break;
            }
        }
    }

    if (!$inGame) {
        http_response_code(400);
        echo json_encode(["error" => "You must be actively inside a game (not in a lobby) to claim a reward."]);
        exit;
    }

    $raw_reward = $event['reward_item_string'];
    $num_drops = 1;
    $contribution = intval($event['contribution_count'] ?? 0);
    
    if (stripos($raw_reward, '3x Random Rare Drops') !== false || stripos($raw_reward, '3_RANDOM_RARE_FIT_FOR_LEVEL') !== false) {
        if (!function_exists('get_reward_item')) {
            require_once 'reward_tables.php';
        }
        $charLevel = intval($onlineCharacter['Level'] ?? 1);
        $charClass = $onlineCharacter['CharClass'] ?? $onlineCharacter['Class'] ?? 'HUmar';
        if (is_numeric($charClass)) {
            $class_map = [0 => 'HUmar', 1 => 'HUnewearl', 2 => 'HUcast', 3 => 'RAmar', 4 => 'RAcast', 5 => 'RAcaseal', 6 => 'FOmarl', 7 => 'FOnewm', 8 => 'FOnewearl', 9 => 'HUcaseal', 10 => 'FOmar', 11 => 'RAmarl'];
            $charClass = $class_map[intval($charClass)] ?? 'HUmar';
        }
        
        $num_drops = 1 + floor($contribution / 50);
        if ($num_drops > 10) {
            $num_drops = 10;
        }
        
        $random_items = [];
        for ($i = 0; $i < $num_drops; $i++) {
            $categories = ['Weapon', 'Armor', 'Shield'];
            $category = $categories[array_rand($categories)];
            $random_items[] = get_reward_item($charLevel, $charClass, $category);
        }
        $raw_string = implode(',', $random_items);
    } else {
        $raw_string = str_ireplace(' and ', ',', $raw_reward);
    }

    if ($is_top_3) {
        $meseta_reward = 100000;
    } else {
        $meseta_reward = 5000 + (floor($contribution / 50) * 5000);
        if ($meseta_reward > 50000) {
            $meseta_reward = 50000;
        }
    }
    if ($is_top_3 && !empty($event['top_3_reward_item_string'])) {
        $choices = array_map('trim', explode('|', $event['top_3_reward_item_string']));
        
        if (count($choices) > 1) {
            // Must have selected a valid choice
            if (!$bonus_choice || !in_array(trim($bonus_choice), $choices)) {
                http_response_code(400);
                echo json_encode(["error" => "Invalid or missing bonus reward selection."]);
                exit;
            }
            $raw_string .= ',' . str_ireplace(' and ', ',', trim($bonus_choice));
        } else {
            // Just one choice, give it automatically
            $raw_string .= ',' . str_ireplace(' and ', ',', $event['top_3_reward_item_string']);
        }
    }

    $raw_string = trim($raw_string, ',');
    
    // Always append the calculated Meseta reward
    $raw_string .= ',' . $meseta_reward . ' Meseta';
    
    // Atomic reservation: set reward_claimed = 2 (in-progress) to lock out concurrent requests
    $lock = $db->prepare("UPDATE community_event_participants SET reward_claimed = 2 WHERE event_id = :eid AND account_id = :accId AND reward_claimed = 0");
    $lock->bindValue(':eid', $event_id, SQLITE3_INTEGER);
    $lock->bindValue(':accId', $accId, SQLITE3_INTEGER);
    $lock->execute();
    if ($db->changes() === 0) {
        http_response_code(409);
        echo json_encode(["error" => "Community reward is already being redeemed or has already been claimed."]);
        exit;
    }

    if (!function_exists('parse_and_drop_items')) {
        require_once 'functions.php';
    }
    
    $dropResult = parse_and_drop_items($accId, $raw_string);
    
    if (!$dropResult['success']) {
        // Revert reservation so player can retry
        $revert = $db->prepare("UPDATE community_event_participants SET reward_claimed = 0 WHERE event_id = :eid AND account_id = :accId");
        $revert->bindValue(':eid', $event_id, SQLITE3_INTEGER);
        $revert->bindValue(':accId', $accId, SQLITE3_INTEGER);
        $revert->execute();

        http_response_code(400);
        echo json_encode(["error" => $dropResult['error']]);
        exit;
    }
    
    // Mark claimed (1 = fully redeemed)
    $upd = $db->prepare("UPDATE community_event_participants SET reward_claimed = 1 WHERE event_id = :eid AND account_id = :accId");
    $upd->bindValue(':eid', $event_id, SQLITE3_INTEGER);
    $upd->bindValue(':accId', $accId, SQLITE3_INTEGER);
    $upd->execute();
    
    // Construct a rich success message confirming the exact quantity of random rares and Meseta dispensed
    if (stripos($raw_reward, '3x Random Rare Drops') !== false || stripos($raw_reward, '3_RANDOM_RARE_FIT_FOR_LEVEL') !== false) {
        $msg = "Community Reward ($num_drops random rare drops and " . number_format($meseta_reward) . " Meseta) dispensed in-game!";
    } else {
        $msg = "Community Reward dispensed in-game!";
    }
    echo json_encode(["success" => true, "message" => $msg]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "System error: " . $e->getMessage()]);
}
