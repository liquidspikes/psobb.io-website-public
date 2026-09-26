<?php
/**
 * PSOBB Global Theming Engine
 * 
 * Centralizes site-wide visual styling and enables dynamic theme swapping,
 * custom color presets, and administrator-level theme management.
 */

if (!defined('PSO_THEME_ENGINE_LOADED')) {
    define('PSO_THEME_ENGINE_LOADED', true);
}

/**
 * Returns available built-in theme presets.
 *
 * @return array
 */
function get_theme_presets() {
    return [
        'classic' => [
            'id' => 'classic',
            'name' => 'Pioneer 2 Classic',
            'desc' => 'Iconic 2004 Sega dreamscape cyan with cosmic purple accents.',
            'primary_color' => '#00ffff',
            'vars' => [
                '--pso-blue' => '#00ffff',
                '--pso-dark' => '#0a0a10',
                '--pso-panel' => 'rgba(0, 20, 40, 0.9)',
                '--pso-text' => '#e0f0ff',
                '--pso-orange' => '#ffaa00',
                '--pso-purple' => '#9d4edd',
                '--pso-bg-gradient' => 'radial-gradient(circle at top center, #1a0b2e 0%, #050a14 60%, #000000 100%)',
            ]
        ],
        'cyberpunk' => [
            'id' => 'cyberpunk',
            'name' => 'Cyberpunk Neon',
            'desc' => 'High-voltage electric cyan, hot magenta, and deep violet nightscape.',
            'primary_color' => '#00ffcc',
            'vars' => [
                '--pso-blue' => '#00ffcc',
                '--pso-dark' => '#0d0221',
                '--pso-panel' => 'rgba(15, 5, 29, 0.92)',
                '--pso-text' => '#f0f3f8',
                '--pso-orange' => '#ff007f',
                '--pso-purple' => '#7928ca',
                '--pso-bg-gradient' => 'radial-gradient(circle at top center, #2d004d 0%, #0d0221 60%, #050010 100%)',
            ]
        ],
        'forest' => [
            'id' => 'forest',
            'name' => 'Ragol Forest',
            'desc' => 'Bioluminescent emerald flora with warm hunter amber accents.',
            'primary_color' => '#00e676',
            'vars' => [
                '--pso-blue' => '#00e676',
                '--pso-dark' => '#06140c',
                '--pso-panel' => 'rgba(5, 25, 15, 0.92)',
                '--pso-text' => '#e8f5e9',
                '--pso-orange' => '#ffb300',
                '--pso-purple' => '#26a69a',
                '--pso-bg-gradient' => 'radial-gradient(circle at top center, #0f381e 0%, #06140c 60%, #020704 100%)',
            ]
        ],
        'darkfalz' => [
            'id' => 'darkfalz',
            'name' => 'Dark Falz Void',
            'desc' => 'Subterranean dimensional rift with crimson glow and abyssal black.',
            'primary_color' => '#ff3366',
            'vars' => [
                '--pso-blue' => '#ff3366',
                '--pso-dark' => '#0f0508',
                '--pso-panel' => 'rgba(25, 8, 14, 0.92)',
                '--pso-text' => '#fce4ec',
                '--pso-orange' => '#ff6d00',
                '--pso-purple' => '#d500f9',
                '--pso-bg-gradient' => 'radial-gradient(circle at top center, #3e0a16 0%, #0f0508 60%, #050102 100%)',
            ]
        ],
        'desert' => [
            'id' => 'desert',
            'name' => 'Episode IV Crater',
            'desc' => 'Subterranean Desert solar amber, sun-baked dunes, and terra cotta.',
            'primary_color' => '#ffaa00',
            'vars' => [
                '--pso-blue' => '#ffaa00',
                '--pso-dark' => '#120c08',
                '--pso-panel' => 'rgba(28, 18, 10, 0.92)',
                '--pso-text' => '#fff8e1',
                '--pso-orange' => '#ff7043',
                '--pso-purple' => '#e040fb',
                '--pso-bg-gradient' => 'radial-gradient(circle at top center, #381e0d 0%, #120c08 60%, #050302 100%)',
            ]
        ],
        'matrix' => [
            'id' => 'matrix',
            'name' => 'Monochrome OLED',
            'desc' => 'High contrast true black with cool ice-blue holographic accents.',
            'primary_color' => '#40c4ff',
            'vars' => [
                '--pso-blue' => '#40c4ff',
                '--pso-dark' => '#000000',
                '--pso-panel' => 'rgba(18, 18, 18, 0.94)',
                '--pso-text' => '#f5f5f5',
                '--pso-orange' => '#ffd600',
                '--pso-purple' => '#82b1ff',
                '--pso-bg-gradient' => 'radial-gradient(circle at top center, #181818 0%, #080808 60%, #000000 100%)',
            ]
        ]
    ];
}

/**
 * Returns the path to the centralized theme configuration file.
 *
 * @return string
 */
function get_theme_config_path() {
    return __DIR__ . '/../config/theme.json';
}

/**
 * Loads the centralized theme configuration from disk.
 *
 * @return array
 */
function get_site_theme_config() {
    $cfgPath = get_theme_config_path();
    $defaults = [
        'default_preset' => 'classic',
        'allow_user_customization' => true,
        'custom_overrides' => []
    ];

    if (!file_exists($cfgPath)) {
        return $defaults;
    }

    $raw = @file_get_contents($cfgPath);
    if (!$raw) {
        return $defaults;
    }

    $json = @json_decode($raw, true);
    if (!is_array($json)) {
        return $defaults;
    }

    return array_merge($defaults, $json);
}

/**
 * Saves updated theme configuration to disk.
 *
 * @param array $config
 * @return bool
 */
function save_site_theme_config($config) {
    $cfgPath = get_theme_config_path();
    $dir = dirname($cfgPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $config['updated_at'] = date('c');
    return (bool)@file_put_contents($cfgPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Resolves the currently active theme variables for the current request.
 * Takes into account:
 * 1. Player cookie preference (if allowed and valid preset)
 * 2. Site-wide default preset
 * 3. Administrator custom overrides
 *
 * @return array
 */
function get_active_theme_vars() {
    $presets = get_theme_presets();
    $config = get_site_theme_config();
    
    // Default to site configured preset
    $presetKey = $config['default_preset'] ?? 'classic';
    if (!isset($presets[$presetKey])) {
        $presetKey = 'classic';
    }

    // Check user cookie if user customization is permitted
    $allowUser = $config['allow_user_customization'] ?? true;
    if ($allowUser && !empty($_COOKIE['psobb_theme'])) {
        $userTheme = trim($_COOKIE['psobb_theme']);
        if (isset($presets[$userTheme])) {
            $presetKey = $userTheme;
        }
    }

    $chosenVars = $presets[$presetKey]['vars'];

    // If using the site default preset, apply any custom administrator overrides
    if ($presetKey === ($config['default_preset'] ?? 'classic') && !empty($config['custom_overrides']) && is_array($config['custom_overrides'])) {
        foreach ($config['custom_overrides'] as $varName => $varVal) {
            if (!empty($varVal) && strpos($varName, '--pso-') === 0) {
                $chosenVars[$varName] = $varVal;
            }
        }
    }

    return [
        'preset_id' => $presetKey,
        'preset_name' => $presets[$presetKey]['name'],
        'vars' => $chosenVars
    ];
}

/**
 * Renders inline CSS defining :root variables and body gradient for the active theme.
 *
 * @return string
 */
function render_theme_css() {
    $theme = get_active_theme_vars();
    $css = "<style id=\"pso-theme-vars\">\n:root {\n";
    foreach ($theme['vars'] as $varName => $varVal) {
        $css .= "    " . htmlspecialchars($varName) . ": " . htmlspecialchars($varVal) . ";\n";
    }
    $css .= "}\n";
    if (isset($theme['vars']['--pso-bg-gradient'])) {
        $css .= "body { background-image: " . htmlspecialchars($theme['vars']['--pso-bg-gradient']) . " !important; }\n";
    }
    $css .= "</style>\n";
    return $css;
}
