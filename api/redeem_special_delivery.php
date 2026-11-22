<?php
/**
 * PSOBB API: Redeem Special Item Delivery
 *
 * GET  — returns count of pending deliveries for the logged-in player.
 * POST — claims a specific delivery (player must be in-game for item to drop).
 */
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

if (ob_get_length()) ob_clean();
start_secure_session();
header('Content-Type: application/json');

if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$accountId = (int)$_SESSION['user']['account_id'];
$db = get_db();

// ----------------------------------------------------------------
// GET — return count + list of pending deliveries for this player
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare("
        SELECT id, item_name, admin_note, created_at
        FROM special_deliveries
        WHERE recipient_id = :uid AND status = 'pending'
        ORDER BY created_at ASC
    ");
    $stmt->bindValue(':uid', $accountId, SQLITE3_INTEGER);
    $res = $stmt->execute();

    $items = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $items[] = [
            'id'         => (int)$row['id'],
            'item_name'  => $row['item_name'],
            'admin_note' => $row['admin_note'],
            'created_at' => $row['created_at'],
        ];
    }

    echo json_encode(['count' => count($items), 'items' => $items]);
    exit;
}

// ----------------------------------------------------------------
// POST — claim a specific delivery
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($input['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Delivery ID required']);
        exit;
    }

    // Fetch and lock the delivery row
    $stmt = $db->prepare("
        SELECT id, item_name, item_string
        FROM special_deliveries
        WHERE id = :id AND recipient_id = :uid AND status = 'pending'
        LIMIT 1
    ");
    $stmt->bindValue(':id',  $id,        SQLITE3_INTEGER);
    $stmt->bindValue(':uid', $accountId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $delivery = $res->fetchArray(SQLITE3_ASSOC);

    if (!$delivery) {
        http_response_code(404);
        echo json_encode(['error' => 'Delivery not found or already claimed']);
        exit;
    }

    // Verify online, spawned, and actively inside a game before reserving
    $onlineCharacter = newserv_require_online_character($accountId, null, [
        'require_in_game'      => true,
        'include_offline_flag' => true,
        'offline_http_code'    => 503,
    ]);

    // Atomic reservation: set status = 'redeeming' to lock out concurrent requests
    $lock = $db->prepare("UPDATE special_deliveries SET status = 'redeeming' WHERE id = :id AND recipient_id = :uid AND status = 'pending'");
    $lock->bindValue(':id',  $id,        SQLITE3_INTEGER);
    $lock->bindValue(':uid', $accountId, SQLITE3_INTEGER);
    $lock->execute();
    if ($db->changes() === 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Delivery is already being redeemed or has already been claimed.']);
        exit;
    }

    // Attempt to deliver via newserv (revert cleanly + preserve partial remainder on failure)
    $revertPending = function (?string $remaining = null) use ($db, $id): void {
        if ($remaining !== null && $remaining !== '') {
            $rev = $db->prepare("UPDATE special_deliveries SET status = 'pending', item_string = :rem WHERE id = :id AND status = 'redeeming'");
            $rev->bindValue(':rem', $remaining, SQLITE3_TEXT);
        } else {
            $rev = $db->prepare("UPDATE special_deliveries SET status = 'pending' WHERE id = :id AND status = 'redeeming'");
        }
        $rev->bindValue(':id', $id, SQLITE3_INTEGER);
        $rev->execute();
    };

    try {
        $result = parse_and_drop_items($accountId, $delivery['item_string'], $onlineCharacter['Name'] ?? null);
    } catch (Throwable $e) {
        $revertPending();
        http_response_code(500);
        echo json_encode(['error' => 'Unexpected error while dropping delivery. Please try again.']);
        exit;
    }

    if (!$result['success']) {
        $revertPending($result['remaining'] ?? null);

        $errMsg = $result['error'] ?? 'Unknown error';

        // Detect offline / not found specifically so the UI can give a helpful message
        $offline = (stripos($errMsg, 'not found') !== false ||
                    stripos($errMsg, 'offline') !== false ||
                    stripos($errMsg, 'connect') !== false);

        http_response_code($offline ? 503 : 500);
        echo json_encode([
            'error'   => $errMsg,
            'offline' => $offline,
        ]);
        exit;
    }

    // Mark as redeemed
    $upd = $db->prepare("
        UPDATE special_deliveries
        SET status = 'redeemed', redeemed_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $upd->bindValue(':id', $id, SQLITE3_INTEGER);
    $upd->execute();

    echo json_encode([
        'success'   => true,
        'item_name' => $delivery['item_name'],
        'message'   => "Your item has been delivered! Check your floor.",
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
