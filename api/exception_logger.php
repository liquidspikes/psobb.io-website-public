<?php
/**
 * PSOBB Centralized Player-Visible Exception & Error Logger
 *
 * Captures uncaught PHP exceptions, PHP fatal errors, player-facing JSON/HTTP error
 * responses, handled exceptions, and frontend JS error reports into a rotated JSONL log
 * (`logs/player_exceptions.jsonl`) with strict PII and secret scrubbing.
 */

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

if (defined('PSOBB_EXCEPTION_LOGGER_INITIALIZED')) {
    return;
}
define('PSOBB_EXCEPTION_LOGGER_INITIALIZED', true);

if (!function_exists('psobb_get_exception_log_path')) {
    /**
     * Resolve the destination JSONL file path for player exception logs.
     */
    function psobb_get_exception_log_path(): string {
        $envPath = $_ENV['PLAYER_EXCEPTIONS_LOG_PATH'] ?? getenv('PLAYER_EXCEPTIONS_LOG_PATH');
        if (is_string($envPath) && trim($envPath) !== '') {
            return trim($envPath);
        }
        return __DIR__ . '/../logs/player_exceptions.jsonl';
    }
}

if (!function_exists('psobb_rel_path')) {
    /**
     * Convert an internal filesystem path into a repository-relative path without home/user prefixes.
     */
    function psobb_rel_path(string $path): string {
        if ($path === '') {
            return '';
        }
        $norm = str_replace('\\', '/', $path);
        $root = realpath(__DIR__ . '/..');
        if (is_string($root) && $root !== '') {
            $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
            if (strpos($norm, $rootNorm) === 0) {
                return substr($norm, strlen($rootNorm));
            }
        }
        $norm = preg_replace('~^/home/[^/]+/~', '', $norm) ?? $norm;
        return ltrim($norm, '/');
    }
}

if (!function_exists('psobb_sanitize_exception_text')) {
    /**
     * Scrub secrets, tokens, emails, IPs, and host home paths from log text before persistence.
     */
    function psobb_sanitize_exception_text(string $text, int $maxLen = 600): string {
        if ($text === '') {
            return '';
        }
        $root = realpath(__DIR__ . '/..');
        if (is_string($root) && $root !== '') {
            $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
            $text = str_replace([$rootNorm, rtrim($rootNorm, '/')], ['', ''], $text);
        }
        $text = preg_replace('~/home/[^/\s:]+/~', '', $text) ?? $text;
        $text = preg_replace(
            '/(password|passwd|secret|token|csrf_token|authorization|api_key|code)\s*([=:]\s*["\']?)([^"\'&\s,}]+)/i',
            '$1$2<REDACTED>',
            $text
        ) ?? $text;
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer <REDACTED>', $text) ?? $text;
        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '<EMAIL>', $text) ?? $text;
        $text = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '<IP>', $text) ?? $text;
        $text = preg_replace('/\b[a-f0-9]{32,}\b/i', '<HEX_TOKEN>', $text) ?? $text;
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        $text = preg_replace('/\s{2,}/', ' ', trim($text)) ?? trim($text);

        if ($maxLen > 0 && strlen($text) > $maxLen) {
            return substr($text, 0, max(1, $maxLen - 3)) . '...';
        }
        return $text;
    }
}

if (!function_exists('psobb_sanitize_extra')) {
    /**
     * Recursively sanitize structured metadata arrays attached to exception records.
     */
    function psobb_sanitize_extra($value, int $depth = 0) {
        if ($depth > 3) {
            return '[nested]';
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return psobb_sanitize_exception_text($value, 400);
        }
        if (is_array($value)) {
            $out = [];
            $count = 0;
            foreach ($value as $k => $v) {
                if ($count >= 16) {
                    break;
                }
                $keyStr = is_string($k) ? psobb_sanitize_exception_text($k, 64) : $k;
                if (is_string($keyStr) && preg_match('/^(?:password|passwd|secret|token|csrf|email|ip|cookie|auth)/i', $keyStr)) {
                    $out[$keyStr] = '<REDACTED>';
                } else {
                    $out[$keyStr] = psobb_sanitize_extra($v, $depth + 1);
                }
                $count++;
            }
            return $out;
        }
        return psobb_sanitize_exception_text((string)$value, 200);
    }
}

if (!function_exists('psobb_extract_safe_context')) {
    /**
     * Extract non-PII player identity (account_id/username) and whitelisted scalar action parameters.
     */
    function psobb_extract_safe_context(): array {
        $ctx = [
            'account_id' => null,
            'username'   => null,
            'params'     => [],
        ];

        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            if (isset($_SESSION['user']['account_id']) && is_numeric($_SESSION['user']['account_id'])) {
                $ctx['account_id'] = (int)$_SESSION['user']['account_id'];
            }
            if (!empty($_SESSION['user']['username']) && is_string($_SESSION['user']['username'])) {
                $ctx['username'] = strtolower(trim($_SESSION['user']['username']));
            }
        }

        $allowedKeys = [
            'slot', 'action', 'op', 'category', 'level',
            'mission_id', 'player_mission_id', 'mod_id',
            'section_id', 'episode', 'difficulty',
        ];
        $params = [];

        foreach ($allowedKeys as $key) {
            if (isset($_GET[$key]) && is_scalar($_GET[$key])) {
                $params[$key] = substr(psobb_sanitize_exception_text((string)$_GET[$key], 64), 0, 64);
            } elseif (isset($_POST[$key]) && is_scalar($_POST[$key])) {
                $params[$key] = substr(psobb_sanitize_exception_text((string)$_POST[$key], 64), 0, 64);
            }
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0 && $contentLength <= 4096 && stripos($contentType, 'application/json') !== false) {
            $rawInput = @file_get_contents('php://input');
            if (is_string($rawInput) && $rawInput !== '') {
                $decoded = json_decode($rawInput, true);
                if (is_array($decoded)) {
                    foreach ($allowedKeys as $key) {
                        if (!isset($params[$key]) && isset($decoded[$key]) && is_scalar($decoded[$key])) {
                            $params[$key] = substr(psobb_sanitize_exception_text((string)$decoded[$key], 64), 0, 64);
                        }
                    }
                }
            }
        }

        $ctx['params'] = $params;
        return $ctx;
    }
}

if (!function_exists('psobb_is_cli_eval_exception')) {
    /**
     * Identify ad-hoc CLI one-liner failures (e.g. `php -r`) while preserving real cron script errors.
     */
    function psobb_is_cli_eval_exception(string $file = '', string $message = '', string $sapi = PHP_SAPI): bool {
        if ($sapi !== 'cli') {
            return false;
        }
        $normFile = strtolower(trim($file));
        if ($normFile === 'command line code' || $normFile === 'standard input code') {
            return true;
        }
        return stripos($message, 'Command line code') !== false || stripos($message, 'Standard input code') !== false;
    }
}

if (!function_exists('psobb_is_ignored_api_error')) {
    /**
     * Skip routine non-error API lookup responses (such as Discord bot polling an unlinked member).
     */
    function psobb_is_ignored_api_error(string $endpoint, string $message, int $status = 200): bool {
        return $endpoint === 'api/bot_api.php'
            && $status < 500
            && strtolower(trim($message)) === 'not linked';
    }
}

if (!function_exists('psobb_is_ignored_client_error')) {
    /**
     * Identify third-party browser/extension injection errors and benign resize noise.
     */
    function psobb_is_ignored_client_error(string $message, string $source = '', string $stack = ''): bool {
        if (
            preg_match('/^(?:chrome|moz|safari|edge)-extension:\/\//i', $source) ||
            preg_match('/(?:chrome|moz|safari|edge)-extension:\/\//i', $stack)
        ) {
            return true;
        }
        if (stripos($message, 'ResizeObserver loop') !== false) {
            return true;
        }
        if (($message === 'Script error.' || $message === 'Script error') && $source === '' && $stack === '') {
            return true;
        }
        if (preg_match('/\b(?:window\.ethereum|ethereum\.selectedAddress|__firefox__|__crWeb|webkit\.messageHandlers)\b/i', $message . ' ' . $stack)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('psobb_is_ignored_exception_record')) {
    /**
     * Determine whether a persisted exception log entry is expected noise for dashboard filtering.
     */
    function psobb_is_ignored_exception_record(array $rec): bool {
        $kind = (string)($rec['kind'] ?? '');
        $endpoint = (string)($rec['endpoint'] ?? '');
        $message = (string)($rec['message'] ?? '');
        $status = (int)($rec['http_status'] ?? 0);
        $sapi = (string)($rec['sapi'] ?? '');

        if (psobb_is_cli_eval_exception((string)($rec['file'] ?? ''), $message, $sapi)) {
            return true;
        }
        if ($kind === 'api_error_response' && psobb_is_ignored_api_error($endpoint, $message, $status)) {
            return true;
        }
        if ($kind === 'client_js_error' || $kind === 'client_promise_rejection') {
            $extra = is_array($rec['extra'] ?? null) ? $rec['extra'] : [];
            if (psobb_is_ignored_client_error($message, (string)($extra['source'] ?? ''), (string)($extra['stack'] ?? ''))) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('psobb_write_exception_record')) {
    /**
     * Append a single sanitized JSONL exception record with locked size-capped log rotation (10 MB x 3).
     */
    function psobb_write_exception_record(array $record): void {
        try {
            $logPath = psobb_get_exception_log_path();
            $dir = dirname($logPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
                @chmod($dir, 0775);
            }
            $isRoot = function_exists('posix_geteuid') && @posix_geteuid() === 0;
            $lockPath = $dir . '/.player_exceptions.lock';
            $lockFp = @fopen($lockPath, 'c+');
            if ($lockFp !== false) {
                @chmod($lockPath, 0664);
                if ($isRoot) {
                    @chown($lockPath, 'www-data');
                    @chgrp($lockPath, 'www-data');
                }
                @flock($lockFp, LOCK_EX);
            }

            clearstatcache(true, $logPath);
            if (file_exists($logPath) && @filesize($logPath) >= 10 * 1024 * 1024) {
                $rot2 = $logPath . '.2';
                $rot1 = $logPath . '.1';
                if (file_exists($rot2)) {
                    @unlink($rot2);
                }
                if (file_exists($rot1)) {
                    @rename($rot1, $rot2);
                }
                @rename($logPath, $rot1);
            }

            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($line) && $line !== '') {
                $fp = @fopen($logPath, 'ab');
                if ($fp !== false) {
                    @fwrite($fp, $line . "\n");
                    @fclose($fp);
                    @chmod($logPath, 0664);
                    if ($isRoot) {
                        @chown($logPath, 'www-data');
                        @chgrp($logPath, 'www-data');
                    }
                }
            }

            if ($lockFp !== false) {
                @flock($lockFp, LOCK_UN);
                @fclose($lockFp);
            }
        } catch (Throwable $ignored) {
            // Never cascade logger failures back onto player responses
        }
    }
}

if (!function_exists('log_player_exception')) {
    /**
     * Record a player-visible exception, error response, or handled failure into player_exceptions.jsonl.
     *
     * @param Throwable|string $err
     */
    function log_player_exception($err, array $extra = [], string $kind = 'handled_exception', ?int $httpStatus = null): void {
        try {
            $status = $httpStatus ?? (PHP_SAPI === 'cli' ? 0 : (int)(http_response_code() ?: 200));
            if ($kind === 'php_fatal') {
                $severity = 'critical';
            } elseif ($kind === 'uncaught_exception' || $status >= 500) {
                $severity = 'error';
            } else {
                $severity = 'warning';
            }

            $endpoint = psobb_rel_path((string)($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['SCRIPT_NAME'] ?? '')));
            $uriRaw = (string)($_SERVER['REQUEST_URI'] ?? '');
            $ctx = psobb_extract_safe_context();

            $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $record = [
                'ts'          => $dt->format('Y-m-d\TH:i:s.v\Z'),
                'kind'        => $kind,
                'severity'    => $severity,
                'sapi'        => PHP_SAPI,
                'http_status' => $status,
                'method'      => $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : 'GET'),
                'endpoint'    => $endpoint,
                'uri'         => psobb_sanitize_exception_text($uriRaw, 240),
                'account_id'  => $ctx['account_id'],
                'username'    => $ctx['username'],
            ];

            if (!empty($ctx['params'])) {
                $record['params'] = $ctx['params'];
            }

            if ($err instanceof Throwable) {
                $record['message'] = psobb_sanitize_exception_text($err->getMessage(), 600);
                $record['exception_class'] = get_class($err);
                $record['file'] = psobb_rel_path($err->getFile());
                $record['line'] = $err->getLine();

                $traceFrames = [];
                foreach (array_slice($err->getTrace(), 0, 6) as $frame) {
                    $fFile = isset($frame['file']) ? psobb_rel_path((string)$frame['file']) : '[internal]';
                    $fLine = isset($frame['line']) ? (int)$frame['line'] : 0;
                    $fCall = '';
                    if (!empty($frame['class'])) {
                        $fCall .= $frame['class'] . ($frame['type'] ?? '::');
                    }
                    if (!empty($frame['function'])) {
                        $fCall .= $frame['function'] . '()';
                    }
                    $traceFrames[] = trim($fFile . ($fLine > 0 ? ':' . $fLine : '') . ($fCall !== '' ? ' ' . $fCall : ''));
                }
                if (!empty($traceFrames)) {
                    $record['trace'] = $traceFrames;
                }
            } else {
                $record['message'] = psobb_sanitize_exception_text((string)$err, 600);
            }

            if (!empty($extra)) {
                $sanExtra = psobb_sanitize_extra($extra);
                if (!empty($sanExtra)) {
                    $record['extra'] = $sanExtra;
                }
            }

            psobb_write_exception_record($record);
        } catch (Throwable $ignored) {
            // Protect caller flow
        }
    }
}

$GLOBALS['__psobb_logged_fatal_or_uncaught'] = false;
$GLOBALS['__psobb_ob_captured'] = '';

// Hook A: Global uncaught exception handler (chains existing handlers like api/bot_api.php)
$__psobb_prev_ex_handler = set_exception_handler(static function (Throwable $e) use (&$__psobb_prev_ex_handler): void {
    $GLOBALS['__psobb_logged_fatal_or_uncaught'] = true;
    if (!psobb_is_cli_eval_exception($e->getFile(), $e->getMessage())) {
        log_player_exception($e, [], 'uncaught_exception', 500);
    }

    if (is_callable($__psobb_prev_ex_handler)) {
        $__psobb_prev_ex_handler($e);
        return;
    }

    if (PHP_SAPI !== 'cli') {
        $endpoint = psobb_rel_path((string)($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['SCRIPT_NAME'] ?? '')));
        if (!headers_sent()) {
            http_response_code(500);
            if (strpos($endpoint, 'api/') === 0) {
                header('Content-Type: application/json');
            }
        }
        if (strpos($endpoint, 'api/') === 0) {
            echo json_encode(['success' => false, 'error' => 'An unexpected server error occurred.']);
        } else {
            echo '<p style="color:#ff5555;font-family:sans-serif;padding:16px;">An unexpected error occurred. Please try again later.</p>';
        }
    } else {
        fwrite(STDERR, 'Uncaught ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    }
});

// Hook B: Non-intrusive output tap for non-CLI HTTP requests (preserves ob_clean() and binary downloads)
if (PHP_SAPI !== 'cli') {
    ob_start(static function (string $buf, int $phase): string {
        if (($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
            $GLOBALS['__psobb_ob_captured'] = '';
            return '';
        }
        if (strlen($GLOBALS['__psobb_ob_captured']) < 8192) {
            $GLOBALS['__psobb_ob_captured'] .= substr($buf, 0, 8192 - strlen($GLOBALS['__psobb_ob_captured']));
        }
        return $buf;
    }, 16384);
}

// Hook C: Fatal error capture + shutdown response inspection for JSON/HTTP errors
register_shutdown_function(static function (): void {
    $err = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (is_array($err) && in_array($err['type'] ?? 0, $fatalTypes, true)) {
        if (
            empty($GLOBALS['__psobb_logged_fatal_or_uncaught']) &&
            !psobb_is_cli_eval_exception((string)($err['file'] ?? ''), (string)($err['message'] ?? ''))
        ) {
            $GLOBALS['__psobb_logged_fatal_or_uncaught'] = true;
            log_player_exception(
                (string)($err['message'] ?? 'PHP fatal error'),
                [
                    'file'     => psobb_rel_path((string)($err['file'] ?? '')),
                    'line'     => (int)($err['line'] ?? 0),
                    'php_type' => (int)($err['type'] ?? 0),
                ],
                'php_fatal',
                500
            );
        }
        return;
    }

    if (PHP_SAPI === 'cli' || !empty($GLOBALS['__psobb_logged_fatal_or_uncaught'])) {
        return;
    }

    $endpoint = psobb_rel_path((string)($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($endpoint === 'api/log_client_error.php') {
        return;
    }

    $captured = (string)($GLOBALS['__psobb_ob_captured'] ?? '');
    $pending = (ob_get_level() > 0) ? (string)ob_get_contents() : '';
    $rawBody = $captured . substr($pending, 0, max(0, 8192 - strlen($captured)));

    $status = (int)(http_response_code() ?: 200);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    $machineEndpoints = [
        'api/bot_api.php',
        'api/bot_tekker_db.php',
    ];
    $isIngestEndpoint = (strpos($endpoint, 'api/') === 0 && substr($endpoint, -11) === '_ingest.php');
    if (($status === 401 || $status === 403) && ($isIngestEndpoint || in_array($endpoint, $machineEndpoints, true))) {
        return;
    }

    $trimmed = ltrim($rawBody);
    if ($trimmed === '') {
        return;
    }

    // Case 1: JSON error payload
    if ($trimmed[0] === '{') {
        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            $errMsg = null;
            if (isset($json['error']) && is_string($json['error']) && trim($json['error']) !== '') {
                $errMsg = trim($json['error']);
                if (
                    isset($json['message']) &&
                    is_string($json['message']) &&
                    trim($json['message']) !== '' &&
                    trim($json['message']) !== $errMsg
                ) {
                    $errMsg .= ': ' . trim($json['message']);
                }
            } elseif (
                ((($json['status'] ?? '') === 'error') || (($json['success'] ?? null) === false)) &&
                isset($json['message']) &&
                is_string($json['message']) &&
                trim($json['message']) !== ''
            ) {
                $errMsg = trim($json['message']);
            }

            if ($errMsg !== null) {
                $hasSessionAcc = (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user']['account_id']));
                $lowerErr = strtolower(trim($errMsg));
                if ($method === 'GET' && $status === 401 && !$hasSessionAcc && in_array($lowerErr, ['not logged in', 'not logged in.', 'unauthorized'], true)) {
                    return;
                }
                if (psobb_is_ignored_api_error($endpoint, $errMsg, $status)) {
                    return;
                }
                log_player_exception($errMsg, [], 'api_error_response', $status);
            }
        }
        return;
    }

    // Case 2: Plain-text die(...) / non-HTML HTTP error responses
    $hasRedirect = false;
    $isBinary = false;
    foreach (headers_list() as $hdr) {
        if (stripos($hdr, 'Location:') === 0) {
            $hasRedirect = true;
            break;
        }
        if (preg_match('/^Content-Type:\s*(application\/octet-stream|application\/zip|image\/)/i', $hdr)) {
            $isBinary = true;
        }
    }
    if ($hasRedirect || $isBinary) {
        return;
    }

    if ($trimmed[0] !== '<' && ($status >= 400 || strpos($endpoint, 'api/discord_') === 0)) {
        $cleanMsg = substr(trim(strip_tags($trimmed)), 0, 400);
        if ($cleanMsg !== '') {
            log_player_exception($cleanMsg, [], 'http_error_response', $status >= 400 ? $status : 400);
        }
    }
});
