<?php
require_once __DIR__ . '/api/config.php';

$accountId = 100; // Replace with an active account ID if you have one online

$items = [
    "? Charge Calibur +5 20/0/0/50",
    "Celestial Armor +4",
    "Divinity Barrier +10def +5evp"
];

foreach ($items as $itemString) {
    $cmd = "on " . $accountId . " cc {$NEWSERV_COMMAND_PREFIX}item " . $itemString;
    $execRes = newserv_shell_exec($cmd);
    echo "Item: $itemString\nResult: $execRes\n\n";
}
?>
