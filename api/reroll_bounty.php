<?php
/**
 * PSOBB API: Reroll Bounty (Bounty System Rework, point 14)
 *
 * A bounty whose objective needs a particular party (mentor carries, Hardcore carries,
 * diverse-party bosses) can be rerolled once for free. The bounty keeps its tier, cadence and
 * reward; the cron gives it a new objective and story the next time the character is online
 * (status 'rerolling' until then). If the new objective needs a party again, it has to be
 * completed or abandoned: there is no second reroll.
 */
error_reporting(0);
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';
require_once 'bounty_system.php';
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
$pmId = (int) ($input['player_mission_id'] ?? 0);
$account_id = (int) ($_SESSION['user']['account_id'] ?? 0);
if (!$pmId || !$account_id) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid parameters"]);
    exit;
}

try {
    $db = get_db();
    $stmt = $db->prepare("SELECT pm.id, pm.rerolled, m.goal_type, m.cadence
                          FROM player_missions pm JOIN missions m ON pm.mission_id = m.id
                          WHERE pm.id = :id AND pm.account_id = :acc AND pm.status = 'in_progress'");
    $stmt->bindValue(':id', $pmId, SQLITE3_INTEGER);
    $stmt->bindValue(':acc', $account_id, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        http_response_code(400);
        echo json_encode(["error" => "Bounty not found, not yours, or not active."]);
        exit;
    }
    if (empty($row['cadence'])) {
        http_response_code(400);
        echo json_encode(["error" => "Only daily and weekly bounties can be rerolled."]);
        exit;
    }
    if (!in_array($row['goal_type'], bounty_config()['reroll_goal_types'], true)) {
        http_response_code(400);
        echo json_encode(["error" => "Only bounties that need a particular party can be rerolled."]);
        exit;
    }
    if ((int) $row['rerolled'] !== 0) {
        http_response_code(400);
        echo json_encode(["error" => "This bounty has already been rerolled. Complete it or abandon it."]);
        exit;
    }
    $upd = $db->prepare("UPDATE player_missions SET status = 'rerolling', rerolled = 1 WHERE id = :id AND status = 'in_progress' AND rerolled = 0");
    $upd->bindValue(':id', $pmId, SQLITE3_INTEGER);
    $upd->execute();
    if ($db->changes() === 0) {
        http_response_code(409);
        echo json_encode(["error" => "This bounty changed in the meantime. Refresh the page."]);
        exit;
    }
    echo json_encode(["success" => true, "message" => "Rerolling: a new objective arrives the next time this character is online. The tier and reward stay the same."]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error while rerolling."]);
}
