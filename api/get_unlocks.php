<?php
require_once 'config.php';
require_once 'db.php';

if (ob_get_length()) ob_clean();
start_secure_session();
header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['user']['account_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}
$accountId = $_SESSION['user']['account_id'];

// 1. Fetch online clients from newserv
$clients = newserv_fetch_clients();
if ($clients === null) {
    http_response_code(500);
    echo json_encode(["error" => __("Game server is offline, cannot fetch character data.")]);
    exit;
}

$onlineCharacter = newserv_find_online_client($clients, (int) $accountId);

if (!$onlineCharacter) {
    // Return early with a message requiring them to log in
    echo json_encode([
        "is_online" => false,
        "message" => __("Please log into the game with a character to view and claim its rewards!")
    ]);
    exit;
}

$lobbies = newserv_fetch_lobbies();
$inGame = ($lobbies !== null) && newserv_is_client_in_game($onlineCharacter, $lobbies, (int) $accountId);

$level = $onlineCharacter['Level'] ?? 1;
$name = $onlineCharacter['Name'] ?? 'Unknown';
$charClass = $onlineCharacter['CharClass'] ?? 'HUmar';
$charIndex = $onlineCharacter['BBCharacterIndex'] ?? 0;

// 2. Query claimed rewards for this character slot to check for character recreation or renaming
try {
    $db = get_db();
    
    // Self-healing/Migration: only migrate legacy slot-0 claims when this slot has no existing claims
    // and the current character level is at least as high as the legacy slot-0 milestones.
    if ((int)$charIndex > 0) {
        $healStmt = $db->prepare("
            UPDATE rewards_claimed
            SET character_index = :cidx
            WHERE account_id = :aid
              AND character_name = :cname COLLATE NOCASE
              AND character_index = 0
              AND NOT EXISTS (
                  SELECT 1 FROM rewards_claimed existing
                  WHERE existing.account_id = :aid AND existing.character_index = :cidx
              )
              AND :lvl >= (
                  SELECT COALESCE(MAX(level_milestone), 0)
                  FROM rewards_claimed legacy
                  WHERE legacy.account_id = :aid
                    AND legacy.character_index = 0
                    AND legacy.character_name = :cname COLLATE NOCASE
              )
        ");
        $healStmt->bindValue(':cidx', $charIndex, SQLITE3_INTEGER);
        $healStmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
        $healStmt->bindValue(':cname', $name, SQLITE3_TEXT);
        $healStmt->bindValue(':lvl', (int)$level, SQLITE3_INTEGER);
        $healStmt->execute();
    }
    
    // Check all claims for this slot to see if the character was recreated/remade or renamed
    $checkStmt = $db->prepare("SELECT character_name, level_milestone, category FROM rewards_claimed WHERE account_id = :aid AND character_index = :cidx");
    $checkStmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
    $checkStmt->bindValue(':cidx', $charIndex, SQLITE3_INTEGER);
    $checkRes = $checkStmt->execute();
    
    $needsReset = false;
    $needsRename = false;
    $claimedRows = [];
    while ($row = $checkRes->fetchArray(SQLITE3_ASSOC)) {
        $claimedRows[] = $row;
        // Only treat as a slot recreation reset when the slot now holds a genuinely new Lv1-4 character
        if ((int)$row['level_milestone'] > (int)$level && (int)$level < 5) {
            $needsReset = true;
        }
    }
    
    if (!$needsReset && !empty($claimedRows)) {
        // If the name is different, but level did not decrease, it's a rename
        if (strcasecmp($claimedRows[0]['character_name'], $name) !== 0) {
            $needsRename = true;
        }
    }
    
    if ($needsReset) {
        // Character slot was recreated — delete old claims
        $delStmt = $db->prepare("DELETE FROM rewards_claimed WHERE account_id = :aid AND character_index = :cidx");
        $delStmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
        $delStmt->bindValue(':cidx', $charIndex, SQLITE3_INTEGER);
        $delStmt->execute();
        $claimedRows = [];
    } elseif ($needsRename) {
        // Character was renamed — update database records to the new name to preserve them
        $updStmt = $db->prepare("UPDATE rewards_claimed SET character_name = :newName WHERE account_id = :aid AND character_index = :cidx");
        $updStmt->bindValue(':newName', $name, SQLITE3_TEXT);
        $updStmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
        $updStmt->bindValue(':cidx', $charIndex, SQLITE3_INTEGER);
        $updStmt->execute();
        
        foreach ($claimedRows as &$row) {
            $row['character_name'] = $name;
        }
        unset($row);
    }

    $claimed = [];
    foreach ($claimedRows as $row) {
        $claimed[$row['level_milestone']] = $row;
    }

    // 3. Generate milestone list up to current level
    $milestones = [];
    $maxMilestone = floor($level / 5) * 5;

    for ($m = 5; $m <= $maxMilestone; $m += 5) {
        $milestoneData = [
            "level" => $m,
            "claimed" => isset($claimed[$m]),
            "claimed_category" => isset($claimed[$m]) ? $claimed[$m]['category'] : null
        ];
        $milestones[] = $milestoneData;
    }

    echo json_encode([
        "is_online" => true,
        "in_game" => $inGame,
        "character" => [
            "name" => $name,
            "level" => $level,
            "class" => $charClass
        ],
        "milestones" => array_reverse($milestones) // Show highest levels first
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
}
?>
