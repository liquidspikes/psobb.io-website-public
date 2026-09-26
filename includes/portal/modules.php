<?php
/**
 * PSOBB Player Portal Modular Architecture
 * 
 * Provides a flexible, extensible registry and rendering engine for Player Portal features.
 * Features can be toggled by administrators to:
 *   - 'everyone'   : Available to all authenticated players.
 *   - 'admin_only'  : Visible only to administrators (useful for testing/staging).
 *   - 'disabled'    : Turned off completely.
 * 
 * Developers can easily register custom modules by calling register_portal_module()
 * or simply dropping a PHP file into `includes/portal/custom_modules/`.
 */

require_once __DIR__ . '/../../api/config.php';
require_once __DIR__ . '/../../api/lang.php';

global $PORTAL_MODULES;
$PORTAL_MODULES = [];

/**
 * Register a module in the Player Portal.
 *
 * @param string $key Unique identifier for the module (e.g. 'tekker', 'my_custom_tool')
 * @param array $config Configuration array:
 *   - 'id' (string): DOM pane element ID (e.g. 'tab-tekker')
 *   - 'name' (string): Display name shown in tab button and admin settings
 *   - 'icon' (string): FontAwesome icon class (e.g. 'fas fa-gavel')
 *   - 'description' (string): Short description for admin settings UI
 *   - 'file' (string|null): Absolute path to the view template file
 *   - 'render_callback' (callable|null): Alternative callback function to render output
 *   - 'order' (int): Sort order in tabs bar (lower appears first)
 *   - 'default' (string): Default visibility ('everyone', 'admin_only', 'disabled')
 */
function register_portal_module(string $key, array $config): void {
    global $PORTAL_MODULES;

    $defaults = [
        'id'              => 'tab-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $key),
        'name'            => ucfirst($key),
        'icon'            => 'fas fa-cubes',
        'description'     => '',
        'file'            => null,
        'render_callback' => null,
        'order'           => 100,
        'default'         => 'everyone',
    ];

    $PORTAL_MODULES[$key] = array_merge($defaults, $config);
}

/**
 * Register built-in standard portal modules.
 */
function init_default_portal_modules(): void {
    register_portal_module('hub', [
        'id'          => 'tab-hub',
        'name'        => __('Hub'),
        'icon'        => 'fas fa-home',
        'description' => __('Hunter license summary, server telemetry, and companion app card.'),
        'file'        => __DIR__ . '/modules/hub.php',
        'order'       => 10,
        'default'     => 'everyone',
    ]);

    register_portal_module('characters', [
        'id'          => 'tab-banks',
        'name'        => __('Character'),
        'icon'        => 'fas fa-user-astronaut',
        'description' => __('Character slots, 3D gear inspect, combat stats, and backpack inventory.'),
        'file'        => __DIR__ . '/modules/characters.php',
        'order'       => 20,
        'default'     => 'everyone',
    ]);

    register_portal_module('bank', [
        'id'          => 'tab-bank',
        'name'        => __('Bank'),
        'icon'        => 'fas fa-vault',
        'description' => __('Vault storage viewer and in-game character/shared bank swapping.'),
        'file'        => __DIR__ . '/modules/bank.php',
        'order'       => 30,
        'default'     => 'everyone',
    ]);

    register_portal_module('guild', [
        'id'          => 'tab-guild',
        'name'        => __('Hunters Guild'),
        'icon'        => 'fas fa-crosshairs',
        'description' => __('Daily login streak, level milestones, item box rolls, and personal bounties.'),
        'file'        => __DIR__ . '/modules/guild.php',
        'order'       => 40,
        'default'     => 'everyone',
    ]);

    register_portal_module('tekker', [
        'id'          => 'tab-tekker',
        'name'        => __('Tekker Store'),
        'icon'        => 'fas fa-gavel',
        'description' => __('Event tokens inventory and custom rare weapon crafting mainframe.'),
        'file'        => __DIR__ . '/modules/tekker.php',
        'order'       => 50,
        'default'     => 'everyone',
    ]);

    register_portal_module('lfg', [
        'id'          => 'tab-lfg',
        'name'        => __('LFG'),
        'icon'        => 'fas fa-satellite',
        'description' => __('Looking for Group party board and direct game warp telemetry.'),
        'file'        => __DIR__ . '/modules/lfg.php',
        'order'       => 60,
        'default'     => 'everyone',
    ]);

    register_portal_module('chat', [
        'id'          => 'tab-chat',
        'name'        => __('Ragol Chat'),
        'icon'        => 'fas fa-terminal',
        'description' => __('Web-to-game chat console for sending messages to in-game characters.'),
        'file'        => __DIR__ . '/modules/chat.php',
        'order'       => 70,
        'default'     => 'everyone',
    ]);

    register_portal_module('settings', [
        'id'          => 'tab-settings',
        'name'        => __('Settings'),
        'icon'        => 'fas fa-cog',
        'description' => __('Account preferences, email linking, display alias, and password management.'),
        'file'        => __DIR__ . '/modules/settings.php',
        'order'       => 80,
        'default'     => 'everyone',
    ]);

    // Automatically auto-load third-party custom modules from custom_modules/*.php
    $customDir = __DIR__ . '/custom_modules';
    if (is_dir($customDir)) {
        $files = glob($customDir . '/*.php');
        if (!empty($files)) {
            foreach ($files as $customFile) {
                if (basename($customFile) === 'example_tab.php.sample') continue;
                include_once $customFile;
            }
        }
    }
}

// Initialize registry immediately on load
init_default_portal_modules();

/**
 * Retrieve all registered portal module definitions.
 *
 * @return array
 */
function get_portal_module_definitions(): array {
    global $PORTAL_MODULES;
    $modules = $PORTAL_MODULES;
    uasort($modules, function ($a, $b) {
        return ($a['order'] ?? 100) <=> ($b['order'] ?? 100);
    });
    return $modules;
}

/**
 * Retrieve the active, visible portal modules for the current or specified user.
 *
 * @param array|null $user
 * @return array
 */
function get_active_portal_modules(?array $user = null): array {
    if ($user === null && isset($_SESSION['user'])) {
        $user = $_SESSION['user'];
    }

    $allModules = get_portal_module_definitions();
    $active = [];

    foreach ($allModules as $key => $mod) {
        $visibility = get_portal_module_visibility($key);

        if ($visibility === 'disabled') {
            continue;
        }

        if ($visibility === 'admin_only') {
            if (empty($user) || empty($user['is_admin'])) {
                continue;
            }
            $mod['is_admin_only'] = true;
        } else {
            $mod['is_admin_only'] = false;
        }

        $active[$key] = $mod;
    }

    return $active;
}

/**
 * Render the dashboard tabs navigation buttons.
 *
 * @param array $activeModules Array of active modules from get_active_portal_modules()
 * @param string|null $activeTabId Specific tab ID to set as active, or null for first
 */
function render_portal_tabs(array $activeModules, ?string $activeTabId = null): void {
    if (empty($activeModules)) {
        return;
    }

    if ($activeTabId === null) {
        $first = reset($activeModules);
        $activeTabId = $first['id'];
    }

    echo '<div class="dashboard-tabs">';
    foreach ($activeModules as $key => $mod) {
        $isActive = ($mod['id'] === $activeTabId);
        $activeClass = $isActive ? ' active' : '';
        $id = htmlspecialchars($mod['id']);
        $icon = htmlspecialchars($mod['icon']);
        $name = htmlspecialchars($mod['name']);
        $adminBadge = !empty($mod['is_admin_only'])
            ? ' <span class="portal-admin-badge" title="' . htmlspecialchars(__('Admin Only Feature')) . '"><i class="fas fa-shield-alt"></i> ' . htmlspecialchars(__('ADMIN')) . '</span>'
            : '';

        echo "<button type=\"button\" class=\"dl-btn tab-btn{$activeClass}\" onclick=\"switchDashboardTab('{$id}')\" data-tab=\"{$id}\">";
        echo "<i class=\"{$icon}\"></i> {$name}{$adminBadge}";
        echo "</button>\n";
    }
    echo '</div>';
}

/**
 * Render the dashboard panes for all active modules.
 *
 * @param array $activeModules Array of active modules from get_active_portal_modules()
 * @param string|null $activeTabId Specific tab ID to set as active, or null for first
 * @param array $context Additional variables passed to module views (e.g. existing_slots)
 */
function render_portal_panes(array $activeModules, ?string $activeTabId = null, array $context = []): void {
    if (empty($activeModules)) {
        echo '<div class="card-glass" style="text-align:center; padding:2.5rem; margin-top:1.5rem; border:1px solid rgba(255,170,0,0.3);">';
        echo '<i class="fas fa-tools" style="font-size:2.5rem; color:#ffaa00; margin-bottom:1rem; display:block;"></i>';
        echo '<h3 style="color:#ffaa00; font-family:\'Share Tech Mono\', monospace; margin:0 0 0.5rem;">' . htmlspecialchars(__('Portal Features Unavailable')) . '</h3>';
        echo '<p style="color:#bbb; font-size:0.95rem; margin:0;">' . htmlspecialchars(__('All player portal modules are currently disabled or undergoing server maintenance. Please check back later.')) . '</p>';
        echo '</div>';
        return;
    }

    if ($activeTabId === null) {
        $first = reset($activeModules);
        $activeTabId = $first['id'];
    }

    // Extract context variables into local scope for module views
    if (!empty($context)) {
        extract($context);
    }

    foreach ($activeModules as $key => $mod) {
        $isActive = ($mod['id'] === $activeTabId);
        $activeClass = $isActive ? ' active' : '';
        $id = htmlspecialchars($mod['id']);
        $modKey = htmlspecialchars($key);

        echo "\n<!-- Tab Pane: {$modKey} ({$id}) -->\n";
        echo "<div id=\"{$id}\" class=\"dashboard-tab-pane{$activeClass}\" data-module=\"{$modKey}\">\n";

        if (!empty($mod['file']) && file_exists($mod['file'])) {
            include $mod['file'];
        } elseif (!empty($mod['render_callback']) && is_callable($mod['render_callback'])) {
            call_user_func($mod['render_callback'], $context);
        }

        echo "\n</div>\n";
    }
}

/**
 * Render shared modals used across the player portal.
 *
 * @param array $context
 */
function render_portal_modals(array $context = []): void {
    $modalsFile = __DIR__ . '/modules/modals.php';
    if (file_exists($modalsFile)) {
        if (!empty($context)) {
            extract($context);
        }
        include $modalsFile;
    }
}
