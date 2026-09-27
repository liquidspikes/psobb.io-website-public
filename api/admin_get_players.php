<?php
require_once 'config.php';
start_secure_session();
header('Content-Type: application/json');

// Check Admin Session
if (empty($_SESSION['user']) || empty($_SESSION['user']['is_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

// Execute 'show-slots' to get players
$output = run_shell_command_json("show-slots");

if ($output === null) {
    echo json_encode(['error' => 'Failed to retrieve player list']);
    exit;
}

// Parse output
// Example output:
// Slots:
//   0: PlayerName (GC: 12345678, ID: 1, ...)
//   1: AnotherPlayer (GC: ...)
$players = [];
$lines = explode("\n", $output);
foreach ($lines as $line) {
    $line = trim($line);
    if (preg_match('/^\d+:\s+(.*?)\s+\(GC:/', $line, $matches)) {
        $players[] = $matches[1];
    }
}

echo json_encode(['players' => $players]);
?>
