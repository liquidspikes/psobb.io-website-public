<?php
/**
 * PSOBB API: Get Account Recovery Email
 * 
 * Returns the recovery email address for the currently authenticated user,
 * indicating whether the account is a legacy account without a real email.
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

    echo json_encode([
        "success" => true,
        "email" => $displayEmail,
        "legacy_email" => $legacyEmail,
        "has_email" => !$isLegacy,
        "is_legacy" => $isLegacy
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Database error: " . $e->getMessage()]);
}
?>
