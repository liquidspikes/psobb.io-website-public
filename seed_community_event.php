<?php
/**
 * Seeder to launch the next PSOBB Community Event
 * Run via CLI on your host: php seed_community_event.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';

$db = get_db();

$title = "Operation: Digital Blasphemy";
$description = "Corrupted Pioneer 1 control units and VR combat simulations are escalating across three fronts. Principal Tyrell's office and the Lab request Hunter strikes against Vol Opt in the Mines (+1 pt), Gol Dragon in the VR Spaceship (+2 pts), or Shambertin beneath the Subterranean Desert (+3 pts), with difficulty bonuses (+1 Hard, +2 VHard, +3 Ult), for 3 level-fit rare drops!";
$goal_type = "BOSS_ARENA";
$goal_target = "DIGITAL_BLASPHEMY"; // Custom multi-boss target tracked dynamically
$target_amount = 75000; // Scaled to span the entire month!
$reward_item_string = "3x Random Rare Drops"; // Intercepted dynamically at claim time
$top_3_reward_item_string = "Heart of Chao | Cell of MAG 502 | Amitie's Memo"; // Exclusively Cosmetic Mags

// Deactivate any currently active events to prevent overlaps
$db->exec("UPDATE community_events SET status = 'inactive' WHERE status = 'active'");

// Insert the new community event
$stmt = $db->prepare("INSERT INTO community_events (title, description, goal_type, goal_target, target_amount, reward_item_string, top_3_reward_item_string, status) 
                      VALUES (:t, :d, :gt, :gta, :amt, :ri, :top3, 'active')");
$stmt->bindValue(':t', $title, SQLITE3_TEXT);
$stmt->bindValue(':d', $description, SQLITE3_TEXT);
$stmt->bindValue(':gt', $goal_type, SQLITE3_TEXT);
$stmt->bindValue(':gta', $goal_target, SQLITE3_TEXT);
$stmt->bindValue(':amt', $target_amount, SQLITE3_INTEGER);
$stmt->bindValue(':ri', $reward_item_string, SQLITE3_TEXT);
$stmt->bindValue(':top3', $top_3_reward_item_string, SQLITE3_TEXT);

if ($stmt->execute()) {
    echo "\n[SUCCESS] Operation: Digital Blasphemy launched successfully!\n";
    echo "Event: " . $title . "\n";
    echo "Goal Target: " . $goal_target . " (Vol Opt, Gol Dragon, Shambertin)\n";
    echo "Target Progress Points: " . $target_amount . "\n";
    echo "Main Reward: " . $reward_item_string . " (dynamic level-fit drops)\n";
    echo "Top 3 Mags Choice: " . $top_3_reward_item_string . "\n\n";
} else {
    echo "\n[ERROR] Failed to insert community event.\n\n";
}
?>
