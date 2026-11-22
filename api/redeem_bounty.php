<?php
/**
 * PSOBB API: Redeem Bounty
 * 
 * Redeems an actively completed personal bounty mission for a user.
 * Validates that the character is online, in a game, and then converts 
 * the textual item string into a raw hex payload for the game server.
 */
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
$pm_id = intval($input['player_mission_id'] ?? $_POST['player_mission_id'] ?? 0);

if (!$pm_id) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid mission ID"]);
    exit;
}

$accId = $_SESSION['user']['account_id'];
// Release PHP's session file lock before making newserv HTTP/SQLite calls.
session_write_close();
$lockedRedeeming = false;
$droppedAny = false;
$spec = null;
$execMissionStatusUpdate = static function (SQLite3 $conn, string $sql, int $targetPmId): void {
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':pm_id', $targetPmId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    if ($res) {
        $res->finalize();
    }
    $stmt->close();
};
$revertReady = static function () use (&$db, &$pm_id, &$lockedRedeeming, $execMissionStatusUpdate): void {
    $sql = "UPDATE player_missions SET status = 'ready_to_redeem' WHERE id = :pm_id AND status = 'redeeming'";
    try {
        if ($db instanceof SQLite3) {
            $execMissionStatusUpdate($db, $sql, (int)$pm_id);
            $lockedRedeeming = false;
            return;
        }
    } catch (Throwable $ignored) {}
    try {
        $freshDb = get_db(true);
        $execMissionStatusUpdate($freshDb, $sql, (int)$pm_id);
        $db = $freshDb;
        $lockedRedeeming = false;
    } catch (Throwable $ignored) {}
};
$markCompleted = static function () use (&$db, &$pm_id, &$lockedRedeeming, $execMissionStatusUpdate): void {
    $sql = "UPDATE player_missions SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = :pm_id";
    try {
        $execMissionStatusUpdate($db, $sql, (int)$pm_id);
    } catch (Throwable $e) {
        $freshDb = get_db(true);
        $execMissionStatusUpdate($freshDb, $sql, (int)$pm_id);
        $db = $freshDb;
    }
    $lockedRedeeming = false;
};
register_shutdown_function(function () use (&$lockedRedeeming, &$droppedAny, &$pm_id, $revertReady) {
    if ($lockedRedeeming && !$droppedAny && !empty($pm_id)) {
        $revertReady();
    }
});

try {
    require_once 'db.php';
    $db = get_db();

    // Verify ownership and status
    $stmt = $db->prepare("SELECT pm.id, pm.character_name, m.id AS mission_id, m.reward_item_string, m.reward_spec
                          FROM player_missions pm
                          JOIN missions m ON pm.mission_id = m.id
                          WHERE pm.id = :pm_id AND pm.account_id = :accId AND pm.status = 'ready_to_redeem'");
    $stmt->bindValue(':pm_id', $pm_id, SQLITE3_INTEGER);
    $stmt->bindValue(':accId', $accId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $bounty = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
    if ($res) {
        $res->finalize();
    }
    $stmt->close();

    if (!$bounty) {
        http_response_code(404);
        echo json_encode(["error" => "Bounty not found or not ready to redeem"]);
        exit;
    }

    // 1. Verify online character, spawn status, and active game room
    $onlineCharacter = newserv_require_online_character(
        (int) $accId,
        !empty($bounty['character_name']) ? (string) $bounty['character_name'] : null,
        ['require_in_game' => true]
    );

    // Try to drop the items at their feet!
    // We split compound AI strings like "Saber, Monomate and 300 Meseta" into isolated payloads
    $raw_string = trim(str_ireplace(' and ', ',', $bounty['reward_item_string']), ',');
    
    // Atomic reservation: transition status to 'redeeming' to lock out concurrent requests
    $lock = $db->prepare("UPDATE player_missions SET status = 'redeeming' WHERE id = :pm_id AND status = 'ready_to_redeem'");
    $lock->bindValue(':pm_id', $pm_id, SQLITE3_INTEGER);
    $lockRes = $lock->execute();
    if ($lockRes) {
        $lockRes->finalize();
    }
    $lock->close();
    if ($db->changes() === 0) {
        http_response_code(409);
        echo json_encode(["error" => "Bounty is already being redeemed or has already been completed."]);
        exit;
    }
    $lockedRedeeming = true;

    if (!function_exists('parse_and_drop_items')) {
        require_once 'functions.php';
    }

    // Bounty System Rework (point 19): attributes, hit, slots and DEF/EVP bonuses roll now, once.
    // The roll is saved before the drop, so a failed drop retries with the same item.
    if (!empty($bounty['reward_spec'])) {
        require_once 'bounty_system.php';
        $spec = json_decode($bounty['reward_spec'], true);
        if (is_array($spec) && !empty($spec['items'])) {
            if (empty($spec['rolled'])) {
                $spec['rolled'] = bounty_roll_reward_string($spec);
                $save = $db->prepare("UPDATE missions SET reward_spec = :spec, reward_item_string = :ri WHERE id = :mid");
                $save->bindValue(':spec', json_encode($spec), SQLITE3_TEXT);
                $save->bindValue(':ri', $spec['rolled'], SQLITE3_TEXT);
                $save->bindValue(':mid', (int) $bounty['mission_id'], SQLITE3_INTEGER);
                $saveRes = $save->execute();
                if ($saveRes) {
                    $saveRes->finalize();
                }
                $save->close();
            }
            $raw_string = trim(str_ireplace(' and ', ',', $spec['rolled']), ',');
        }
    }

    $dropResult = parse_and_drop_items($accId, $raw_string, $bounty['character_name']);
    $droppedAny = !empty($dropResult['dropped']);

    if (!$dropResult['success']) {
        $remaining = trim((string)($dropResult['remaining'] ?? ''));

        if ($droppedAny && $remaining === '') {
            // All non-empty segments actually dispensed before trailing edge check
            $markCompleted();

            $reward_text = html_entity_decode(renderRewardString($raw_string), ENT_QUOTES);
            echo json_encode(["success" => true, "message" => "Reward dispensed in-game!", "reward" => $reward_text]);
            exit;
        }

        $canRevert = !$droppedAny;
        if ($droppedAny && $remaining !== '') {
            // Persist only undropped items before allowing retry so delivered items cannot duplicate
            $refStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM player_missions WHERE mission_id = :mid");
            $refStmt->bindValue(':mid', (int)$bounty['mission_id'], SQLITE3_INTEGER);
            $refRes = $refStmt->execute();
            $refRow = $refRes ? $refRes->fetchArray(SQLITE3_ASSOC) : false;
            if ($refRes) {
                $refRes->finalize();
            }
            $refStmt->close();
            $refCount = (int)($refRow['cnt'] ?? 0);
            if ($refCount <= 1) {
                if (is_array($spec)) {
                    $spec['rolled'] = $remaining;
                    $updM = $db->prepare("UPDATE missions SET reward_spec = :spec, reward_item_string = :ri WHERE id = :mid");
                    $updM->bindValue(':spec', json_encode($spec), SQLITE3_TEXT);
                    $updM->bindValue(':ri', $remaining, SQLITE3_TEXT);
                    $updM->bindValue(':mid', (int)$bounty['mission_id'], SQLITE3_INTEGER);
                    $updMRes = $updM->execute();
                } else {
                    $updM = $db->prepare("UPDATE missions SET reward_item_string = :ri WHERE id = :mid");
                    $updM->bindValue(':ri', $remaining, SQLITE3_TEXT);
                    $updM->bindValue(':mid', (int)$bounty['mission_id'], SQLITE3_INTEGER);
                    $updMRes = $updM->execute();
                }
                if ($updMRes) {
                    $updMRes->finalize();
                }
                $updM->close();
                $canRevert = true;
            }
        }

        if ($canRevert) {
            $revertReady();
        }

        http_response_code(400);
        echo json_encode(["error" => $dropResult['error']]);
        exit;
    }

    // Update status to 'completed'
    $markCompleted();

    // The rolled reward in words (e.g. "Custom Frame +20def +20evp +4"), for the claim message
    $reward_text = html_entity_decode(renderRewardString($raw_string), ENT_QUOTES);
    echo json_encode(["success" => true, "message" => "Reward dispensed in-game!", "reward" => $reward_text]);

} catch (Throwable $e) {
    if ($lockedRedeeming && !$droppedAny && !empty($pm_id)) {
        $revertReady();
    }
    http_response_code(500);
    echo json_encode(["error" => "System error: " . $e->getMessage()]);
}
?>
