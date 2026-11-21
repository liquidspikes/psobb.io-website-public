<?php
/**
 * PSOBB API: Client-Side JS Exception & Unhandled Promise Rejection Collector
 *
 * Receives uncaught frontend errors from js/main.js and writes sanitized records
 * into logs/player_exceptions.jsonl with extension noise filtering and rate limiting.
 */
require_once __DIR__ . '/config.php';
start_secure_session();

if (ob_get_length()) {
    ob_clean();
}
header('Content-Type: application/json');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = (string)@file_get_contents('php://input', false, null, 0, 4096);
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$message = trim((string)($payload['message'] ?? ''));
$kind = ($payload['kind'] ?? '') === 'client_promise_rejection'
    ? 'client_promise_rejection'
    : 'client_js_error';
$page = trim((string)($payload['page'] ?? ''));
$source = trim((string)($payload['source'] ?? ''));
$line = isset($payload['line']) && is_numeric($payload['line']) ? (int)$payload['line'] : 0;
$col = isset($payload['col']) && is_numeric($payload['col']) ? (int)$payload['col'] : 0;
$stack = trim((string)($payload['stack'] ?? ''));

if ($message === '') {
    echo json_encode(['success' => true, 'ignored' => 'empty']);
    exit;
}

// Ignore third-party extension/wallet injections, generic cross-origin Script error noise, and benign ResizeObserver warnings
if (psobb_is_ignored_client_error($message, $source, $stack)) {
    echo json_encode(['success' => true, 'ignored' => 'noise']);
    exit;
}

// Sliding 60-second rate limit (max 30 globally, max 8 per session)
$logDir = dirname(psobb_get_exception_log_path());
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
    @chmod($logDir, 0775);
}
$rlPath = $logDir . '/.client_err_ratelimit.json';
$now = time();
$sid = session_id();
$sessKey = $sid !== '' ? hash('sha256', $sid) : 'anon';
$throttled = false;

$rlFp = @fopen($rlPath, 'c+');
if ($rlFp !== false) {
    @chmod($rlPath, 0664);
    if (@flock($rlFp, LOCK_EX)) {
        $rawRl = stream_get_contents($rlFp);
        $state = is_string($rawRl) && $rawRl !== '' ? json_decode($rawRl, true) : null;
        if (!is_array($state)) {
            $state = ['global' => [], 'sessions' => []];
        }

        $cutoff = $now - 60;
        $global = array_values(array_filter(
            (array)($state['global'] ?? []),
            static fn($t): bool => is_numeric($t) && (int)$t > $cutoff
        ));

        $sessions = [];
        foreach ((array)($state['sessions'] ?? []) as $k => $times) {
            if (!is_array($times)) {
                continue;
            }
            $recent = array_values(array_filter(
                $times,
                static fn($t): bool => is_numeric($t) && (int)$t > $cutoff
            ));
            if (!empty($recent)) {
                $sessions[$k] = $recent;
            }
        }

        $sessRecent = $sessions[$sessKey] ?? [];
        if (count($global) >= 30 || count($sessRecent) >= 8) {
            $throttled = true;
        } else {
            $global[] = $now;
            $sessRecent[] = $now;
            $sessions[$sessKey] = $sessRecent;
            $newJson = json_encode(['global' => $global, 'sessions' => $sessions]);
            if (is_string($newJson)) {
                ftruncate($rlFp, 0);
                rewind($rlFp);
                fwrite($rlFp, $newJson);
                fflush($rlFp);
            }
        }
        @flock($rlFp, LOCK_UN);
    }
    fclose($rlFp);
}

if ($throttled) {
    echo json_encode(['success' => true, 'throttled' => true]);
    exit;
}

$extra = array_filter([
    'page'   => $page !== '' ? substr($page, 0, 200) : null,
    'source' => $source !== '' ? substr($source, 0, 240) : null,
    'line'   => $line > 0 ? $line : null,
    'col'    => $col > 0 ? $col : null,
    'stack'  => $stack !== '' ? substr($stack, 0, 400) : null,
], static fn($v): bool => $v !== null);

log_player_exception($message, $extra, $kind, 0);

echo json_encode(['success' => true]);
