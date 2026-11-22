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
session_write_close();
$lockedRedeeming = false;
$droppedAny = false;

$execCommunityClaimUpdate = static function (SQLite3 $conn, int $targetClaimedState, int $targetEventId, int $targetAccId, ?int $expectedState = null): int {
    $sql = "UPDATE community_event_participants SET reward_claimed = :state WHERE event_id = :eid AND account_id = :accId";
    if ($expectedState !== null) {
        $sql .= " AND reward_claimed = :expected";
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':state', $targetClaimedState, SQLITE3_INTEGER);
    $stmt->bindValue(':eid', $targetEventId, SQLITE3_INTEGER);
    $stmt->bindValue(':accId', $targetAccId, SQLITE3_INTEGER);
    if ($expectedState !== null) {
        $stmt->bindValue(':expected', $expectedState, SQLITE3_INTEGER);
    }
    $res = $stmt->execute();
    if ($res) {
        $res->finalize();
    }
    $stmt->close();
    return $conn->changes();
};

$revertClaim = static function () use (&$db, &$event_id, &$accId, &$lockedRedeeming, $execCommunityClaimUpdate): void {
    try {
        if ($db instanceof SQLite3) {
            $execCommunityClaimUpdate($db, 0, (int)$event_id, (int)$accId, 2);
            $lockedRedeeming = false;
            return;
        }
    } catch (Throwable $ignored) {}
    try {
        $freshDb = get_db(true);
        $execCommunityClaimUpdate($freshDb, 0, (int)$event_id, (int)$accId, 2);
        $db = $freshDb;
        $lockedRedeeming = false;
    } catch (Throwable $ignored) {}
};

register_shutdown_function(function () use (&$lockedRedeeming, &$droppedAny, &$event_id, &$accId, $revertClaim) {
    if ($lockedRedeeming && !$droppedAny && !empty($event_id) && !empty($accId)) {
        $revertClaim();
    }
});

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
    if ($res) {
        $res->finalize();
    }
    $stmt->close();

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
    if ($top3_res) {
        while ($row = $top3_res->fetchArray(SQLITE3_ASSOC)) {
            if ($row['account_id'] == $accId) {
                $is_top_3 = true;
                break;
            }
        }
        $top3_res->finalize();
    }
    $top3_stmt->close();

    // 1. Verify online character, spawn status, and active game room
    $onlineCharacter = newserv_require_online_character((int) $accId, null, ['require_in_game' => true]);

    $raw_reward = $event['reward_item_string'];
    $num_drops = 1;
    $contribution = intval($event['contribution_count'] ?? 0);
    
    if (stripos($raw_reward, '3x Random Rare Drops') !== false || stripos($raw_reward, '3_RANDOM_RARE_FIT_FOR_LEVEL') !== false) {
        if (!function_exists('get_reward_item')) {
            require_once 'reward_tables.php';
        }
        $charLevel = intval($onlineCharacter['Level'] ?? 1);
        $charClass = pso_resolve_class_name($onlineCharacter['CharClass'] ?? $onlineCharacter['Class'] ?? 'HUmar');
        
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
    if ($execCommunityClaimUpdate($db, 2, (int)$event_id, (int)$accId, 0) === 0) {
        http_response_code(409);
        echo json_encode(["error" => "Community reward is already being redeemed or has already been claimed."]);
        exit;
    }
    $lockedRedeeming = true;

    if (!function_exists('parse_and_drop_items')) {
        require_once 'functions.php';
    }
    
    $dropResult = parse_and_drop_items($accId, $raw_string);
    $droppedAny = !empty($dropResult['dropped']);
    
    if (!$dropResult['success']) {
        // Revert reservation only if nothing dropped yet so partial/delivered drops cannot duplicate
        if (!$droppedAny) {
            $revertClaim();
        }

        http_response_code(400);
        echo json_encode(["error" => $dropResult['error']]);
        exit;
    }
    
    // Mark claimed (1 = fully redeemed); retry with fresh handle if snapshot/lock issue occurs post-drop
    try {
        $execCommunityClaimUpdate($db, 1, (int)$event_id, (int)$accId);
    } catch (Throwable $e) {
        $freshDb = get_db(true);
        $execCommunityClaimUpdate($freshDb, 1, (int)$event_id, (int)$accId);
        $db = $freshDb;
    }
    $lockedRedeeming = false;
    
    // Construct a rich success message confirming the exact quantity of random rares and Meseta dispensed
    if (stripos($raw_reward, '3x Random Rare Drops') !== false || stripos($raw_reward, '3_RANDOM_RARE_FIT_FOR_LEVEL') !== false) {
        $msg = "Community Reward ($num_drops random rare drops and " . number_format($meseta_reward) . " Meseta) dispensed in-game!";
    } else {
        $msg = "Community Reward dispensed in-game!";
    }
    echo json_encode(["success" => true, "message" => $msg]);

} catch (Throwable $e) {
    if ($lockedRedeeming && !$droppedAny && !empty($event_id) && !empty($accId)) {
        $revertClaim();
    }
    http_response_code(500);
    echo json_encode(["error" => "System error: " . $e->getMessage()]);
}
