<?php
/**
 * PSOBB: Admin Image Upload Endpoint
 * 
 * Securely handles image uploads for site branding (such as homepage hero logo/banner).
 * Validates admin authentication, CSRF token, file types, and size.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
start_secure_session();

header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['user']['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Administrator privileges required.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token.']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['image']['error'] ?? 'missing';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No valid image file uploaded. Error code: ' . $errCode]);
    exit;
}

$file = $_FILES['image'];

// Max 10MB
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Image file size exceeds maximum limit of 10MB.']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

$allowedMimes = [
    'image/png'     => 'png',
    'image/jpeg'    => 'jpg',
    'image/webp'    => 'webp',
    'image/gif'     => 'gif',
    'image/svg+xml' => 'svg'
];

if (!array_key_exists($mime, $allowedMimes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported image format (' . htmlspecialchars($mime) . '). Please upload PNG, JPG, WEBP, GIF, or SVG.']);
    exit;
}

$ext = $allowedMimes[$mime];
$uploadDir = __DIR__ . '/../img/uploads';
if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create upload directory on server.']);
        exit;
    }
}

$targetType = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['target'] ?? 'hero');
$filename = $targetType . '_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 6) . '.' . $ext;
$targetPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded image.']);
    exit;
}

$publicUrl = '/img/uploads/' . $filename;

// If target is hero_logo, auto-update config/site.json
if ($targetType === 'hero' || $targetType === 'hero_logo') {
    $cfg = get_site_config();
    $cfg['hero_logo_url'] = $publicUrl;
    $cfg['updated_at'] = date('c');
    $cfgPath = __DIR__ . '/../config/site.json';
    @file_put_contents($cfgPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode([
    'success' => true,
    'url'     => $publicUrl,
    'message' => 'Image uploaded and configured successfully!'
]);
