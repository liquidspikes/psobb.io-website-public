<?php
/**
 * PSOBB: Save Site Settings Endpoint
 * 
 * Allows administrators to update global server settings, feature toggles,
 * rates, client download links, and community URLs.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
start_secure_session();

header('Content-Type: application/json');

if (empty($_SESSION['user']['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Administrator privileges required.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

$token = $data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verify_csrf_token($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token.']);
    exit;
}

$currentConfig = get_site_config();

$cleanString = function($val, $default = '', $max = 250) {
    if ($val === null) return $default;
    $s = strip_tags(trim((string)$val));
    return strlen($s) > $max ? substr($s, 0, $max) : $s;
};

$serverName    = $cleanString($data['server_name'] ?? null, $currentConfig['server_name'] ?? 'PSOBB.IO', 60);
$serverAddress = $cleanString($data['server_address'] ?? null, $currentConfig['server_address'] ?? 'psobb.io', 120);
$serverTagline = $cleanString($data['server_tagline'] ?? null, $currentConfig['server_tagline'] ?? '', 250);
$heroLogoUrl   = $cleanString($data['hero_logo_url'] ?? null, $currentConfig['hero_logo_url'] ?? '/img/header_logo.png', 250);
$expRate       = $cleanString($data['exp_rate'] ?? null, '1x', 15);
$dropRate      = $cleanString($data['drop_rate'] ?? null, '1x', 15);
$mesetaRate    = $cleanString($data['meseta_rate'] ?? null, '1x', 15);
$discordServer = $cleanString($data['discord_server'] ?? null, 'https://discord.gg/28s84HJXha', 200);

$clientWin = $cleanString($data['client_windows_url'] ?? null, '/downloads/PSOBBIO-Setup_1.25.13b.exe', 250);
$clientMac = $cleanString($data['client_mac_url'] ?? null, '/downloads/PSOBBIO_125.13.dmg', 250);
$clientRaw = $cleanString($data['client_raw_url'] ?? null, '/downloads/PSOBBIO-Linux_1.25.13.zip', 250);

$enableRegistration = !empty($data['enable_registration']);
$enableBounties     = !empty($data['enable_bounties']);
$enableLfg          = !empty($data['enable_lfg']);
$enableMods         = !empty($data['enable_mods']);
$enableQuestEditor  = !empty($data['enable_quest_editor']);
$enableDiscordOAuth = !empty($data['enable_discord_oauth']);

$defaultLanguage = $data['default_language'] ?? ($currentConfig['default_language'] ?? 'auto');
if (!in_array($defaultLanguage, ['auto', 'en', 'jp', 'ru'])) {
    $defaultLanguage = 'auto';
}

$newConfig = [
    'server_name'          => $serverName ?: 'PSOBB.IO',
    'server_address'       => $serverAddress ?: 'psobb.io',
    'server_tagline'       => $serverTagline,
    'default_language'     => $defaultLanguage,
    'hero_logo_url'        => $heroLogoUrl ?: '/img/header_logo.png',
    'exp_rate'             => $expRate ?: '1x',
    'drop_rate'            => $dropRate ?: '1x',
    'meseta_rate'          => $mesetaRate ?: '1x',
    'discord_server'       => $discordServer ?: 'https://discord.gg/28s84HJXha',
    'enable_registration'  => $enableRegistration,
    'enable_bounties'      => $enableBounties,
    'enable_lfg'           => $enableLfg,
    'enable_mods'          => $enableMods,
    'enable_quest_editor'  => $enableQuestEditor,
    'enable_discord_oauth' => $enableDiscordOAuth,
    'client_windows_url'   => $clientWin,
    'client_mac_url'       => $clientMac,
    'client_raw_url'       => $clientRaw,
    'updated_at'           => date('c')
];

$cfgPath = __DIR__ . '/../config/site.json';
$dir = dirname($cfgPath);
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
$saved = @file_put_contents($cfgPath, json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Handle About page / Command Deck configuration
if (isset($data['about']) && is_array($data['about'])) {
    $aboutInput = $data['about'];
    $cleanedAbout = [
        'hero_title'         => $cleanString($aboutInput['hero_title'] ?? null, 'About ' . $serverName, 120),
        'hero_subtitle'      => $cleanString($aboutInput['hero_subtitle'] ?? null, '', 1000),
        'command_deck_title' => $cleanString($aboutInput['command_deck_title'] ?? null, $serverName . ' Command Deck', 120),
        'show_features'      => !empty($aboutInput['show_features']),
        'show_tech_specs'    => !empty($aboutInput['show_tech_specs']),
        'crew'               => []
    ];

    if (!empty($aboutInput['crew']) && is_array($aboutInput['crew'])) {
        foreach ($aboutInput['crew'] as $member) {
            if (!is_array($member)) continue;
            $name = $cleanString($member['name'] ?? null, '', 80);
            if ($name === '') continue; // skip blank rows
            $cleanedAbout['crew'][] = [
                'id'        => $cleanString($member['id'] ?? null, 'crew_' . substr(md5(uniqid()), 0, 8), 32),
                'name'      => $name,
                'role'      => $cleanString($member['role'] ?? null, '', 100),
                'specialty' => $cleanString($member['specialty'] ?? null, '', 100),
                'icon'      => $cleanString($member['icon'] ?? null, 'fas fa-user-astronaut', 60),
                'theme'     => $cleanString($member['theme'] ?? null, 'admin-card', 40),
                'bio'       => $cleanString($member['bio'] ?? null, '', 1500),
            ];
        }
    }

    $aboutPath = __DIR__ . '/../config/about.json';
    @file_put_contents($aboutPath, json_encode($cleanedAbout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

if ($saved) {
    // Also sync discord_server into theme.json if present
    $themePath = __DIR__ . '/../config/theme.json';
    if (file_exists($themePath)) {
        $themeConfig = json_decode(@file_get_contents($themePath), true);
        if (is_array($themeConfig)) {
            $themeConfig['discord_server'] = $newConfig['discord_server'];
            $themeConfig['discord_invite_url'] = $newConfig['discord_server'];
            @file_put_contents($themePath, json_encode($themeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Site and server settings saved successfully!',
        'config' => $newConfig
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to write site configuration to disk.']);
}
