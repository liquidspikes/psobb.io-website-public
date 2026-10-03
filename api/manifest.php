<?php
/**
 * Dynamic Web App Manifest
 * Emits dynamic PWA manifest adhering to configured app_name and server_name.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$appName = get_app_name();
$serverName = get_server_name();
$tagline = get_server_tagline();

$manifest = [
    'name' => sprintf('%s Companion App', $appName),
    'short_name' => $appName,
    'description' => sprintf('Companion App & Hunters Guild portal for %s.', $serverName),
    'start_url' => '/login.php',
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#0a0c10',
    'theme_color' => '#00ffff',
    'icons' => [
        [
            'src' => '/img/steam_icon.png',
            'sizes' => '184x184',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => '/img/favicon.svg',
            'sizes' => '192x192 512x512',
            'type' => 'image/svg+xml',
            'purpose' => 'any'
        ]
    ]
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
