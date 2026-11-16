<?php
/**
 * PSOBB API: Get All My Bounties (Dashboard Portal)
 * 
 * Returns ALL bounties for the logged-in player:
 *  - in_progress: currently working on
 *  - ready_to_redeem: completed, awaiting claim
 * 
 * Used by the dashboard Bounties tab for a mini bounty board.
 */
error_reporting(0);
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';
require_once 'bounty_system.php';
start_secure_session();

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$account_id = $_SESSION['user']['account_id'] ?? 0;

if (!$account_id) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid session account ID"]);
    exit;
}

try {
    $db = get_db();
    
    // Fetch ALL active bounties for this player (in_progress + ready_to_redeem)
    $stmt = $db->prepare("
        SELECT pm.id AS player_mission_id, pm.mission_id, pm.character_name, pm.status,
               pm.accepted_at, pm.completed_at,
               m.title, m.description, m.goal_type, m.goal_target, m.reward_item_string,
               m.tier, m.cadence, m.pool, m.reward_spec, m.meta, pm.expires_at, pm.progress, pm.rerolled
        FROM player_missions pm
        JOIN missions m ON pm.mission_id = m.id
        WHERE pm.account_id = :accId 
          AND pm.status IN ('queued', 'in_progress', 'rerolling', 'ready_to_redeem')
        ORDER BY 
            CASE pm.status 
                WHEN 'ready_to_redeem' THEN 0 
                WHEN 'in_progress' THEN 1 
                WHEN 'rerolling' THEN 1
                WHEN 'queued' THEN 2
            END,
            pm.id DESC
    ");
    $stmt->bindValue(':accId', $account_id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    
    $bounties = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        // Bounty System Rework fields (null/empty on bounties from before the rework)
        $row['tier_label'] = $row['tier'] ? bounty_tier_label($row['tier']) : null;
        $row['kind'] = $row['cadence'] ? bounty_kind_label($row['pool'], $row['cadence']) : null;
        $row['expires_in'] = (!empty($row['expires_at']) && $row['status'] !== 'ready_to_redeem') ? bounty_expiry_text($row['expires_at'])
            : ((($row['pool'] ?? '') === 'contract' && $row['status'] !== 'ready_to_redeem') ? 'Never expires' : null);
        $row['progress_text'] = in_array($row['goal_type'], BOUNTY_PROGRESS_GOALS, true) ? bounty_progress_text($row['goal_type'], $row['goal_target'], $row['progress']) : null;
        $row['stats_hidden'] = $row['reward_spec'] ? bounty_reward_has_hidden_stats(json_decode($row['reward_spec'], true)) : false;
        $row['can_reroll'] = $row['status'] === 'in_progress' && $row['cadence'] && (int) $row['rerolled'] === 0 && in_array($row['goal_type'], bounty_config()['reroll_goal_types'], true);
        $min_diff = bounty_meta_min_difficulty($row['meta'] ?? null);
        unset($row['reward_spec'], $row['progress'], $row['meta']);
        // Add human-readable objective
        $row['objective'] = getClearObjective(
            $row['goal_type'], 
            $row['goal_target'], 
            $row['title'], 
            $row['description'],
            $min_diff
        );
        // Add rendered reward string (decode hex to item names)
        $row['reward_display'] = renderRewardString($row['reward_item_string']);
        // Identify team bounties
        $row['is_team'] = in_array($row['goal_type'], ['HARDCORE_MENTOR', 'DIVERSE_PARTY_BOSS']);
        // Strip internal fields — no raw hex for players
        unset($row['reward_item_string']);
        unset($row['goal_type']);
        unset($row['goal_target']);
        $bounties[] = $row;
    }
    
    // Count stats
    $in_progress = array_filter($bounties, fn($b) => $b['status'] === 'in_progress' || $b['status'] === 'rerolling');
    $claimable = array_filter($bounties, fn($b) => $b['status'] === 'ready_to_redeem');
    $queued = array_filter($bounties, fn($b) => $b['status'] === 'queued');
    
    echo json_encode([
        "success" => true,
        "bounties" => $bounties,
        "stats" => [
            "in_progress" => count($in_progress),
            "claimable" => count($claimable),
            "up_next" => count($queued),
            "total" => count($bounties)
        ],
        "slots" => bounty_rework_enabled() ? bounty_board_summary($db, $account_id) : null
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
}
?>
