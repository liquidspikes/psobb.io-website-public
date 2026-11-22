<?php
/**
 * Regression & source-audit tests for SQLITE_BUSY_SNAPSHOT ("database is locked") prevention
 * across bounty/reward redemption and LFG/cron endpoints.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

function assert_wal_true(bool $cond, string $label): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL [$label]\n");
        exit(1);
    }
}

$tmpDb = sys_get_temp_dir() . '/test_wal_snapshot_' . getmypid() . '_' . mt_rand(1000, 9999) . '.sqlite';

try {
    $db1 = new SQLite3($tmpDb);
    $db1->enableExceptions(true);
    $db1->busyTimeout(30000);
    $db1->exec('PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL;');
    $db1->exec('CREATE TABLE player_missions (id INTEGER PRIMARY KEY, status TEXT, updated_at INTEGER);');
    $db1->exec("INSERT INTO player_missions (id, status, updated_at) VALUES (1, 'ready_to_redeem', 0), (2, 'in_progress', 0);");

    $db2 = new SQLite3($tmpDb);
    $db2->enableExceptions(true);
    $db2->busyTimeout(30000);

    // 1. Confirm unfinalized 1-row SELECT triggers SQLITE_BUSY_SNAPSHOT when WAL advances
    $badStmt = $db1->prepare('SELECT id, status FROM player_missions WHERE id = 1');
    $badRes = $badStmt->execute();
    $badRow = $badRes ? $badRes->fetchArray(SQLITE3_ASSOC) : false;
    assert_wal_true(is_array($badRow), 'initial SELECT returned row');

    $db2->exec("UPDATE player_missions SET updated_at = 1 WHERE id = 2");
    $caughtSnapshot = false;
    $t0 = microtime(true);
    try {
        $db1->exec("UPDATE player_missions SET status = 'redeeming' WHERE id = 1");
    } catch (Throwable $e) {
        $caughtSnapshot = stripos($e->getMessage(), 'database is locked') !== false;
    }
    assert_wal_true($caughtSnapshot, 'unfinalized cursor causes SQLITE_BUSY_SNAPSHOT across WAL write');
    assert_wal_true((microtime(true) - $t0) < 2.0, 'SQLITE_BUSY_SNAPSHOT bypasses 30s busyTimeout');
    if ($badRes) {
        $badRes->finalize();
    }
    $badStmt->close();

    // 2. Verify finalized cursor + concurrent writes succeed across 25 iterations
    for ($i = 1; $i <= 25; $i++) {
        $db1->exec("UPDATE player_missions SET status = 'ready_to_redeem' WHERE id = 1");

        $sel = $db1->prepare('SELECT id, status FROM player_missions WHERE id = 1');
        $selRes = $sel->execute();
        $row = $selRes ? $selRes->fetchArray(SQLITE3_ASSOC) : false;
        if ($selRes) {
            $selRes->finalize();
        }
        $sel->close();
        assert_wal_true(is_array($row) && $row['status'] === 'ready_to_redeem', "iteration $i SELECT ready");

        // Concurrent writer commits while connection 1 is doing external HTTP I/O
        $db2->exec("UPDATE player_missions SET updated_at = $i WHERE id = 2");

        $lock = $db1->prepare("UPDATE player_missions SET status = 'redeeming' WHERE id = 1 AND status = 'ready_to_redeem'");
        $lockRes = $lock->execute();
        if ($lockRes) {
            $lockRes->finalize();
        }
        $lock->close();
        assert_wal_true($db1->changes() === 1, "iteration $i redeeming reservation succeeded");

        // Second concurrent commit between reserving and completing
        $db2->exec("UPDATE player_missions SET updated_at = " . ($i + 100) . " WHERE id = 2");

        $db1->exec('BEGIN IMMEDIATE;');
        $done = $db1->prepare("UPDATE player_missions SET status = 'completed' WHERE id = 1");
        $doneRes = $done->execute();
        if ($doneRes) {
            $doneRes->finalize();
        }
        $done->close();
        $db1->exec('COMMIT;');
        assert_wal_true($db1->querySingle('SELECT status FROM player_missions WHERE id = 1') === 'completed', "iteration $i completed");
    }

    // 3. Verify fresh connection recovery fallback when an artificial cursor pins stale snapshot
    $pinnedStmt = $db1->prepare('SELECT id, status FROM player_missions WHERE id = 1');
    $pinnedRes = $pinnedStmt->execute();
    $pinnedRes->fetchArray(SQLITE3_ASSOC);
    $db2->exec('UPDATE player_missions SET updated_at = 999 WHERE id = 2');

    $recovered = false;
    try {
        $db1->exec("UPDATE player_missions SET status = 'fallback_test' WHERE id = 1");
    } catch (Throwable $ignored) {
        $freshDb = new SQLite3($tmpDb);
        $freshDb->enableExceptions(true);
        $freshDb->busyTimeout(30000);
        $freshStmt = $freshDb->prepare("UPDATE player_missions SET status = 'fallback_completed' WHERE id = 1");
        $freshRes = $freshStmt->execute();
        if ($freshRes) {
            $freshRes->finalize();
        }
        $freshStmt->close();
        $freshDb->close();
        $recovered = true;
    }
    assert_wal_true($recovered, 'fresh connection fallback recovered from artificial stale snapshot');
    $pinnedRes->finalize();
    $pinnedStmt->close();
    assert_wal_true($db2->querySingle('SELECT status FROM player_missions WHERE id = 1') === 'fallback_completed', 'fallback write persisted');

    $db1->close();
    $db2->close();
} finally {
    @unlink($tmpDb);
    @unlink($tmpDb . '-wal');
    @unlink($tmpDb . '-shm');
}

// 4. Source audit across all 8 hardened endpoints / cron scripts
$repoRoot = dirname(__DIR__);
$sessionReleaseFiles = [
    'api/redeem_bounty.php',
    'api/redeem_community.php',
    'api/redeem_special_delivery.php',
    'api/claim_tekker_drop.php',
    'api/claim_unlock.php',
    'api/abandon_bounty.php',
];
$allAuditedFiles = array_merge($sessionReleaseFiles, [
    'api/lfg_requests.php',
    'api/cron_missions.php',
]);

foreach ($sessionReleaseFiles as $rel) {
    $src = (string) file_get_contents($repoRoot . '/' . $rel);
    assert_wal_true(strpos($src, 'session_write_close()') !== false, "$rel calls session_write_close()");
}

foreach ($allAuditedFiles as $rel) {
    $src = (string) file_get_contents($repoRoot . '/' . $rel);
    assert_wal_true(preg_match('/while\s*\([^)]*->fetchArray\(/', $src) === 0 || strpos($src, '->finalize()') !== false, "$rel finalizes fetch loops");
    if (preg_match_all('/(\$[A-Za-z0-9_]+)\s*=\s*\$[A-Za-z0-9_]+->(?:execute|query)\s*\(/', $src, $matches)) {
        foreach (array_unique($matches[1]) as $var) {
            assert_wal_true(
                strpos($src, $var . '->finalize()') !== false,
                "$rel finalizes SQLite3Result variable $var"
            );
        }
    }
}

echo "ALL SQLITE WAL SNAPSHOT LOCK TESTS PASSED\n";
