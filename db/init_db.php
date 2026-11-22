<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../api/db.php';

$db = get_db(true);
run_db_migrations($db);
$db->exec('PRAGMA user_version = ' . (int) PSO_DB_SCHEMA_VERSION . ';');

$dbPath = get_db_path();
$tableCount = 0;
$tRes = $db->query("SELECT count(*) as count FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
if ($tRes && ($row = $tRes->fetchArray(SQLITE3_ASSOC))) {
    $tableCount = (int) $row['count'];
}

if (!isset($_SERVER['SCRIPT_FILENAME']) || realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    echo "Database initialized at {$dbPath} ({$tableCount} tables verified, schema v" . PSO_DB_SCHEMA_VERSION . ")\n";
}
