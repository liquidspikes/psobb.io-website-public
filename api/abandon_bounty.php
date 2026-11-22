<?php
/**
 * PSOBB API: Abandon Bounty
 * 
 * Allows a user to abandon an in-progress personal bounty mission.
 * Frees up the mission slot but does not reset any progress made towards it
 * if they choose to accept it again later.
 */
error_reporting(0);
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';
start_secure_session();

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');
verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$pmId = $input['player_mission_id'] ?? 0;
$account_id = $_SESSION['user']['account_id'] ?? 0;
session_write_close();

if (!$pmId || !$account_id) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid parameters"]);
    exit;
}

try {
    $db = get_db();
    
    // Ensure the mission belongs to the current user and is active (or, with the Bounty System
    // Rework, waiting in the queue for a slot)
    $check_stmt = $db->prepare("SELECT id FROM player_missions WHERE id = :id AND account_id = :accId AND status IN ('in_progress', 'queued')");
    $check_stmt->bindValue(':id', $pmId, SQLITE3_INTEGER);
    $check_stmt->bindValue(':accId', $account_id, SQLITE3_INTEGER);
    $check_res = $check_stmt->execute();
    $found = $check_res ? $check_res->fetchArray(SQLITE3_ASSOC) : false;
    if ($check_res) {
        $check_res->finalize();
    }
    $check_stmt->close();

    if (!$found) {
        http_response_code(400);
        echo json_encode(["error" => "Quest not found, not owned by you, or not active."]);
        exit;
    }
    
    // Mark as abandoned
    $upd = $db->prepare("UPDATE player_missions SET status = 'abandoned' WHERE id = :id");
    $upd->bindValue(':id', $pmId, SQLITE3_INTEGER);
    $updRes = $upd->execute();
    if ($updRes) {
        $updRes->finalize();
    }
    $upd->close();
    
    echo json_encode(["success" => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error while abandoning quest."]);
}
?>
