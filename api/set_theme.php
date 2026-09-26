<?php
/**
 * PSOBB: Set Theme Endpoint
 * 
 * Handles theme switching for users (via cookie) and global theme updates for admins.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/theme.php';
start_secure_session();

header('Content-Type: application/json');

$presets = get_theme_presets();
$action = $_REQUEST['action'] ?? 'set_user_theme';

if ($action === 'save_global_theme') {
    // Admin only
    if (empty($_SESSION['user']['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized: Administrator privileges required.']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? $_POST;

    // Verify CSRF
    $token = $data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verify_csrf_token($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token.']);
        exit;
    }

    $preset = trim($data['default_preset'] ?? 'classic');
    if (!isset($presets[$preset])) {
        $preset = 'classic';
    }

    $allowUser = !empty($data['allow_user_customization']);
    $customOverrides = [];

    if (!empty($data['custom_overrides']) && is_array($data['custom_overrides'])) {
        $allowedVars = [
            '--pso-blue',
            '--pso-dark',
            '--pso-panel',
            '--pso-text',
            '--pso-orange',
            '--pso-purple',
            '--pso-bg-gradient'
        ];
        foreach ($data['custom_overrides'] as $k => $v) {
            if (in_array($k, $allowedVars) && !empty($v)) {
                // Basic sanitization
                $clean = strip_tags(trim($v));
                if (strlen($clean) <= 250) {
                    $customOverrides[$k] = $clean;
                }
            }
        }
    }

    $discordServer = trim($data['discord_server'] ?? $data['discord_invite_url'] ?? '');
    if (!empty($discordServer)) {
        $cleanDiscord = strip_tags($discordServer);
    } else {
        $cleanDiscord = 'https://discord.gg/28s84HJXha';
    }

    $newConfig = [
        'default_preset' => $preset,
        'allow_user_customization' => $allowUser,
        'discord_server' => $cleanDiscord,
        'discord_invite_url' => $cleanDiscord,
        'custom_overrides' => $customOverrides
    ];

    $saved = save_site_theme_config($newConfig);
    if ($saved) {
        echo json_encode([
            'success' => true,
            'message' => 'Global theme settings saved successfully.',
            'config' => $newConfig
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to write theme configuration to disk.']);
    }
    exit;
}

// User / Guest Theme Selection
$theme = trim($_GET['theme'] ?? $_POST['theme'] ?? '');

if ($theme === 'default') {
    // Clear user cookie to inherit server default
    setcookie('psobb_theme', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
} elseif (isset($presets[$theme])) {
    // 365 day cookie
    setcookie('psobb_theme', $theme, [
        'expires' => time() + (365 * 24 * 60 * 60),
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
} else {
    if (isset($_GET['theme'])) {
        header('Location: /');
        exit;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid theme preset requested.']);
    exit;
}

// If accessed directly via browser GET link, redirect back
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $redirect = $_SERVER['HTTP_REFERER'] ?? '/';
    // Validate redirect is local
    $parsed = parse_url($redirect);
    $path = $parsed['path'] ?? '/';
    if (!empty($parsed['query'])) {
        $path .= '?' . $parsed['query'];
    }
    header("Location: $path");
    exit;
}

echo json_encode([
    'success' => true,
    'theme' => $theme,
    'message' => 'Theme updated successfully.'
]);
