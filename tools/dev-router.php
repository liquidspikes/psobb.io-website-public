<?php
declare(strict_types=1);
/**
 * Router for PHP built-in server (apache .htaccess rewrites are ignored by `php -S`).
 *
 * Usage from website root:
 *   php -S 127.0.0.1:8080 -t . tools/dev-router.php
 */
$uriPath = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
if (str_contains($uriPath, '..')) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

if ($uriPath !== '/') {
    $full = realpath($docRoot . $uriPath);
    if ($full !== false && strncmp($full, $docRoot, strlen($docRoot)) === 0 && is_file($full)) {
        return false;
    }
}

$route = '/' . trim($uriPath, '/');
if ($route === '/') {
    require $docRoot . '/index.php';
    return true;
}

$script = $docRoot . $route . '.php';
if (is_file($script)) {
    $_SERVER['SCRIPT_NAME'] = $route . '.php';
    $_SERVER['PHP_SELF'] = $route . '.php';
    require $script;
    return true;
}

http_response_code(404);
echo 'Not found';
return true;
