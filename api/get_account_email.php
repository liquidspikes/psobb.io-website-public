<?php
/**
 * PSOBB API: Get Account Recovery Email
 * 
 * Returns the recovery email address for the currently authenticated user,
 * indicating whether the account is a legacy account without a real email,
 * and if there is an active pending email confirmation request.
 */
error_reporting(0);
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';

if (ob_get_length()) ob_clean();
start_secure_session();
header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['user']['account_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not logged in"]);
    exit;
}

$accountId = (int)$_SESSION['user']['account_id'];
$username = strtolower(trim($_SESSION['user']['username'] ?? ''));

try {
    $db = get_db();
    $stmt = $db->prepare("SELECT email FROM users WHERE account_id = :aid OR username = :u");
    $stmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
    $stmt->bindValue(':u', $username, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;

    $rawEmail = $row ? trim($row['email'] ?? '') : '';
    $isLegacy = empty($rawEmail) || (bool)preg_match('/_legacy@psobb\.io$/i', $rawEmail);
    $displayEmail = $isLegacy ? '' : $rawEmail;
    $legacyEmail = $isLegacy ? ($rawEmail ?: ($username . '_legacy@psobb.io')) : '';

    // Check for pending email confirmation
    $db->exec("CREATE TABLE IF NOT EXISTS email_confirmations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        account_id INTEGER NOT NULL,
        username TEXT NOT NULL,
        new_email TEXT NOT NULL,
        token TEXT UNIQUE NOT NULL,
        confirmed_at INTEGER DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at INTEGER NOT NULL
    )");

    $pendStmt = $db->prepare("SELECT new_email, expires_at FROM email_confirmations WHERE account_id = :aid AND confirmed_at IS NULL AND expires_at > :now ORDER BY id DESC LIMIT 1");
    $pendStmt->bindValue(':aid', $accountId, SQLITE3_INTEGER);
    $pendStmt->bindValue(':now', time(), SQLITE3_INTEGER);
    $pendRes = $pendStmt->execute();
    $pendRow = $pendRes ? $pendRes->fetchArray(SQLITE3_ASSOC) : null;
    $pendingEmail = $pendRow ? trim($pendRow['new_email'] ?? '') : '';

    echo json_encode([
        "success" => true,
        "email" => $displayEmail,
        "legacy_email" => $legacyEmail,
        "has_email" => !$isLegacy,
        "is_legacy" => $isLegacy,
        "pending_email" => $pendingEmail,
        "is_pending" => !empty($pendingEmail)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
}
?>
