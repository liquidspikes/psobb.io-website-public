<?php
/**
 * PSOBB API: Get My Bounties & Community Events
 * 
 * Returns all bounties for the logged-in user (in_progress and completed),
 * plus active community event status with user contribution.
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
    
    // All bounties for this player (in_progress + ready_to_redeem but not yet redeemed)
    // Status lifecycle: in_progress -> ready_to_redeem -> completed (redeemed)
    $stmt = $db->prepare("
        SELECT pm.id AS player_mission_id, pm.mission_id, pm.character_name, pm.status,
               pm.accepted_at, pm.completed_at,
               m.title, m.description, m.goal_type, m.goal_target, m.reward_item_string,
               m.tier, m.cadence, m.pool, m.reward_spec, m.meta, pm.expires_at, pm.progress, pm.rerolled
        FROM player_missions pm
        JOIN missions m ON pm.mission_id = m.id
        WHERE pm.account_id = :accId 
          AND pm.status IN ('queued', 'in_progress', 'rerolling', 'ready_to_redeem')
        ORDER BY CASE pm.status WHEN 'ready_to_redeem' THEN 0 WHEN 'queued' THEN 2 ELSE 1 END, pm.accepted_at DESC
    ");
    $stmt->bindValue(':accId', $account_id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    
    $bounties = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $row['character_name'] = bounty_display_character_name($row['character_name'] ?? null, $row['pool'] ?? null);
        // Bounty System Rework fields (null/empty on bounties from before the rework)
        $row['tier_label'] = $row['tier'] ? bounty_tier_label($row['tier']) : null;
        $row['kind'] = $row['cadence'] ? bounty_kind_label($row['pool'], $row['cadence']) : null;
        $row['expires_in'] = (!empty($row['expires_at']) && !in_array($row['status'], ['ready_to_redeem', 'redeeming'], true)) ? bounty_expiry_text($row['expires_at'])
            : ((($row['pool'] ?? '') === 'contract' && !in_array($row['status'], ['ready_to_redeem', 'redeeming'], true)) ? 'Never expires' : null);
        $row['progress_text'] = in_array($row['goal_type'], BOUNTY_PROGRESS_GOALS, true) ? bounty_progress_text($row['goal_type'], $row['goal_target'], $row['progress']) : null;
        $row['stats_hidden'] = $row['reward_spec'] ? bounty_reward_has_hidden_stats(json_decode($row['reward_spec'], true)) : false;
        $row['can_reroll'] = $row['status'] === 'in_progress' && $row['cadence'] && (int) $row['rerolled'] === 0 && in_array($row['goal_type'], bounty_config()['reroll_goal_types'], true);
        // "(on Hard or higher)": the difficulty an Uncommon or Rare daily counts on
        $min_diff = bounty_meta_min_difficulty($row['meta'] ?? null);
        $row['min_difficulty_text'] = ($min_diff && in_array($row['goal_type'], BOUNTY_DAILY_PLACE_GOALS, true))
            ? ($min_diff === 'Ultimate' ? __('(on Ultimate)') : __('(on %s or higher)', __($min_diff)))
            : null;
        unset($row['reward_spec'], $row['progress'], $row['meta']);
        // Always provide canonical localized objective text so portal dashboard matches missions.php (including Ep 1/2/4 areas)
        $row['objective_text'] = !empty($row['goal_type'])
            ? html_entity_decode(strip_tags(getClearObjective($row['goal_type'], $row['goal_target'], $row['title'] ?? '', $row['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : null;
        // Decode reward_item_string hex into readable item name using canonical function
        if (!empty($row['reward_item_string'])) {
            $row['reward_decoded'] = renderRewardString($row['reward_item_string']);
        }
        $bounties[] = $row;
    }
    
    // Active community events with user contribution
    $events = [];
    $ce_res = $db->query("SELECT id, title, description, goal_type, goal_target, target_amount, current_progress, reward_item_string, status, created_at FROM community_events WHERE status = 'active' ORDER BY created_at DESC");
    if ($ce_res) {
        while ($row = $ce_res->fetchArray(SQLITE3_ASSOC)) {
            // Get user's contribution to this event
            $p_stmt = $db->prepare("SELECT contribution_count FROM community_event_participants WHERE event_id = :eid AND account_id = :aid");
            $p_stmt->bindValue(':eid', $row['id'], SQLITE3_INTEGER);
            $p_stmt->bindValue(':aid', $account_id, SQLITE3_INTEGER);
            $p_res = $p_stmt->execute();
            $p_row = $p_res->fetchArray(SQLITE3_ASSOC);
            $row['user_contribution'] = $p_row ? (int)$p_row['contribution_count'] : 0;
            $row['progress_pct'] = $row['target_amount'] > 0 ? round(($row['current_progress'] / $row['target_amount']) * 100, 1) : 0;
            $events[] = $row;
        }
    }
    
    // Claimable community events (completed, user participated, not yet claimed)
    $claimable_events = [];
    $cl_res = $db->prepare("
        SELECT ce.id as event_id, ce.title, ce.reward_item_string, ce.top_3_reward_item_string, 
               cep.contribution_count
        FROM community_events ce
        JOIN community_event_participants cep ON ce.id = cep.event_id
        WHERE ce.status = 'completed' 
          AND cep.account_id = :aid
          AND cep.reward_claimed = 0
    ");
    $cl_res->bindValue(':aid', $account_id, SQLITE3_INTEGER);
    $cl_exec = $cl_res->execute();
    if ($cl_exec) {
        while ($row = $cl_exec->fetchArray(SQLITE3_ASSOC)) {
            if (!empty($row['reward_item_string'])) {
                $row['reward_decoded'] = renderRewardString($row['reward_item_string']);
            }
            $claimable_events[] = $row;
        }
    }
    
    echo json_encode([
        "success" => true,
        "bounties" => $bounties,
        "community_events" => $events,
        "claimable_events" => $claimable_events,
        "slots" => bounty_rework_enabled() ? bounty_board_summary($db, $account_id) : null
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database error: " . $e->getMessage()]);
}
?>
