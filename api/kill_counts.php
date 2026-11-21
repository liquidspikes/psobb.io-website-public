<?php
/**
 * PSOBB API: Kill counts
 *
 * A character's lifetime kills of each enemy and boss, by episode and difficulty, for the logged-in
 * account (api/enemy_kills.php; recorded by the mission cron from newserv's KillCounts).
 *
 *   GET /api/kill_counts.php?character=NAME   one character (default: the account's first)
 *
 * Response: success, characters (names with kill counts), character, episodes ({"1": [rows], ...}; each
 * row: enemy, name, ultimate_name, rare, boss, counts {Normal, Hard, Very Hard, Ultimate}, challenge,
 * battle, total), totals {kills, bosses, rares}.
 */
error_reporting(0);
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';
require_once 'enemy_kills.php';
start_secure_session();

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

$account_id = (int) ($_SESSION['user']['account_id'] ?? 0);
if (!$account_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $db = get_db();
    $chars = [];
    $st = $db->prepare("SELECT character_name, SUM(kills) AS n FROM enemy_kill_counts WHERE account_id = :acc GROUP BY character_name ORDER BY character_name");
    $st->bindValue(':acc', $account_id, SQLITE3_INTEGER);
    $r = $st->execute();
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
        $chars[] = $x['character_name'];
    }
    $want = isset($_GET['character']) ? (string) $_GET['character'] : null;
    $character = ($want !== null && $want !== '') ? $want : ($chars[0] ?? null);
    $data = $character !== null ? enemy_kills_for_character($db, $account_id, $character) : ['episodes' => [], 'totals' => ['kills' => 0, 'bosses' => 0, 'rares' => 0]];
    echo json_encode([
        'success' => true,
        'characters' => $chars,
        'character' => $character,
        'episodes' => (object) $data['episodes'],
        'totals' => $data['totals'],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error while reading kill counts.']);
}
