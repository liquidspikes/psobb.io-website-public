<?php
require_once 'config.php';
require_once 'db.php';

if (ob_get_length()) ob_clean();
start_secure_session();
header('Content-Type: application/json');
verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');

if (empty($_SESSION['user']) || empty($_SESSION['user']['account_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}
$accountId = $_SESSION['user']['account_id'];

// 1-2. Verify player is online, spawned, and actively in a game room
$onlineClient = newserv_require_online_character((int) $accountId, null, [
    'offline_error'          => 'Game server is offline.',
    'not_online_error'       => 'You must be logged into the game to claim daily rewards.',
    'require_in_game'        => true,
    'in_game_required_error' => 'You must be in a game (not lobby) to claim daily rewards.',
]);

// 3. Check if already claimed today
$db = get_db();
$today = date('Y-m-d');

$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM daily_rewards WHERE account_id = :aid AND claim_date = :date");
$stmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
$stmt->bindValue(':date', $today, SQLITE3_TEXT);
$result = $stmt->execute()->fetchArray();

if ($result['cnt'] > 0) {
    http_response_code(400);
    echo json_encode(["error" => "You have already claimed your daily reward today. Come back tomorrow!"]);
    exit;
}

// 4. Pick a random daily reward
$dailyPool = [
    '040000000000000000000000E8030000' => ['weight' => 100, 'name' => '1000 Meseta'],
    '030500' => ['weight' => 60, 'name' => 'Star Atomizer'],
    '030400' => ['weight' => 80, 'name' => 'Moon Atomizer'],
    '030002' => ['weight' => 100, 'name' => 'Trimate'],
    '030102' => ['weight' => 80, 'name' => 'Trifluid'],
    '030300' => ['weight' => 40, 'name' => 'Sol Atomizer'],
    '030700' => ['weight' => 100, 'name' => 'Telepipe'],
    '030900' => ['weight' => 15, 'name' => 'Scape Doll'],
    '031000, 031000' => ['weight' => 30, 'name' => 'Photon Drop x2'],
    '030600' => ['weight' => 60, 'name' => 'Antidote'],
    '030601' => ['weight' => 60, 'name' => 'Antiparalysis'],
];

// Weighted random selection
$totalWeight = 0;
foreach ($dailyPool as $i) $totalWeight += $i['weight'];
$roll = rand(1, $totalWeight);
$cumulative = 0;
$chosenItemHex = '';
$chosenItemName = '';
foreach ($dailyPool as $hex => $info) {
    $cumulative += $info['weight'];
    if ($roll <= $cumulative) {
        $chosenItemHex = $hex;
        $chosenItemName = $info['name'];
        break;
    }
}

// 5. Atomic Reservation: Insert before drop to block race conditions with concurrent requests
try {
    $stmt = $db->prepare("INSERT INTO daily_rewards (account_id, claim_date, item_string) VALUES (:aid, :date, :item)");
    $stmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
    $stmt->bindValue(':date', $today, SQLITE3_TEXT);
    $stmt->bindValue(':item', $chosenItemHex, SQLITE3_TEXT);
    $stmt->execute();
} catch (Exception $e) {
    http_response_code(409);
    echo json_encode(["error" => "You have already claimed your daily reward today. Come back tomorrow!"]);
    exit;
}

// 6. Drop the item
if (!function_exists('parse_and_drop_items')) {
    require_once 'functions.php';
}

$dropResult = parse_and_drop_items($accountId, $chosenItemHex);

if (!$dropResult['success']) {
    // Revert reservation so player can retry
    $del = $db->prepare("DELETE FROM daily_rewards WHERE account_id = :aid AND claim_date = :date");
    $del->bindValue(':aid', $accountId, SQLITE3_INTEGER);
    $del->bindValue(':date', $today, SQLITE3_TEXT);
    $del->execute();

    http_response_code(400);
    echo json_encode(["error" => $dropResult['error']]);
    exit;
}

$translatedName = __($chosenItemName);

echo json_encode([
    "success" => true,
    "item" => $translatedName,
    "message" => "Daily reward: {$translatedName} dropped in-game!"
]);
