<?php
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/functions.php';
require_once __DIR__ . '/../includes/portal/modules.php';
start_secure_session();

if (empty($_SESSION['user']) || empty($_SESSION['user']['is_admin'])) {
    header("Location: ../login.php");
    exit;
}

$page_title = __('Site & Server Settings - Admin');
$current_page = 'site_settings';
include '../includes/header.php';

$cfg = get_site_config();
$aboutCfg = get_about_config();

// Test NewServ connectivity for readiness check
$newservStatus = false;
try {
    $ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
    $res = @file_get_contents($NEWSERV_API_URL . '/y/summary', false, $ctx);
    if ($res !== false) {
        $newservStatus = true;
    }
} catch (Exception $e) {}

// Test DB
$dbStatus = false;
try {
    require_once __DIR__ . '/../api/db.php';
    $db = get_db();
    $dbStatus = true;
} catch (Exception $e) {}
?>

<style>
.settings-admin-container {
    max-width: 1200px;
    margin: 2rem auto;
    padding: 0 1rem;
}
.settings-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(0, 255, 255, 0.2);
}
.admin-subnav {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 2rem;
    background: rgba(0, 15, 30, 0.7);
    padding: 8px;
    border-radius: 8px;
    border: 1px solid rgba(0, 255, 255, 0.15);
}
.admin-subnav a {
    padding: 8px 14px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--pso-text);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.admin-subnav a:hover {
    background: rgba(0, 255, 255, 0.1);
    color: var(--pso-blue);
}
.admin-subnav a.active {
    background: var(--pso-blue);
    color: #000;
}
.readiness-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}
.readiness-pill {
    background: rgba(0, 15, 30, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.readiness-pill span.label {
    font-size: 0.85rem;
    color: #aaa;
}
.readiness-pill span.status {
    font-weight: bold;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 6px;
}
.status-ok { color: #00C851; }
.status-warn { color: #ffbb33; }
.status-err { color: #ff4444; }

/* Settings Navigation Tabs */
.settings-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid rgba(0, 255, 255, 0.2);
    padding-bottom: 8px;
    overflow-x: auto;
}
.settings-tab-btn {
    background: rgba(0, 15, 30, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #ccc;
    padding: 10px 18px;
    border-radius: 6px 6px 0 0;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    font-family: 'Share Tech Mono', monospace;
    white-space: nowrap;
}
.settings-tab-btn:hover {
    background: rgba(0, 255, 255, 0.1);
    color: var(--pso-blue);
    border-color: rgba(0, 255, 255, 0.3);
}
.settings-tab-btn.active {
    background: var(--pso-blue);
    color: #000;
    border-color: var(--pso-blue);
    box-shadow: 0 0 15px rgba(0, 255, 255, 0.3);
}

.tab-pane {
    display: none;
}
.tab-pane.active {
    display: block;
    animation: fadeIn 0.3s ease-in-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 2rem;
}
@media (max-width: 900px) {
    .settings-grid {
        grid-template-columns: 1fr;
    }
}
.settings-card {
    background: var(--pso-panel);
    border: 1px solid rgba(0, 255, 255, 0.2);
    border-radius: 10px;
    padding: 1.5rem;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
    margin-bottom: 1.5rem;
}
.settings-card h3 {
    margin-top: 0;
    margin-bottom: 1.25rem;
    font-size: 1.15rem;
    color: var(--pso-blue);
    font-family: 'Share Tech Mono', monospace;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    padding-bottom: 0.75rem;
}
.field-group {
    margin-bottom: 1.25rem;
}
.field-group:last-child {
    margin-bottom: 0;
}
.field-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 0.9rem;
    color: #eee;
}
.field-group label small {
    display: block;
    color: #888;
    font-size: 0.75rem;
    margin-top: 2px;
}
.field-input, .field-textarea, .field-select {
    width: 100%;
    padding: 10px 12px;
    box-sizing: border-box;
    font-family: 'Outfit', sans-serif;
    font-size: 0.9rem;
    background: rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 6px;
    color: #fff;
    transition: border-color 0.2s;
}
.field-textarea {
    resize: vertical;
    min-height: 80px;
}
.field-input:focus, .field-textarea:focus, .field-select:focus {
    outline: none;
    border-color: var(--pso-blue);
    box-shadow: 0 0 8px rgba(0, 255, 255, 0.3);
}

/* Image Preview & Upload Box */
.hero-preview-box {
    background: radial-gradient(circle at center, rgba(0, 255, 255, 0.08) 0%, rgba(0, 0, 0, 0.6) 80%);
    border: 2px dashed rgba(0, 255, 255, 0.3);
    border-radius: 8px;
    padding: 1.5rem;
    text-align: center;
    margin-top: 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 140px;
}
.hero-preview-img {
    max-height: 120px;
    max-width: 100%;
    object-fit: contain;
    filter: drop-shadow(0 0 15px rgba(0, 255, 255, 0.3));
    transition: transform 0.3s;
}
.hero-preview-img:hover {
    transform: scale(1.02);
}
.upload-btn-row {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-top: 10px;
    flex-wrap: wrap;
}

/* Toggle Switches */
.toggle-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}
.toggle-row:last-child {
    border-bottom: none;
}
.toggle-info {
    flex: 1;
    padding-right: 15px;
}
.toggle-info strong {
    display: block;
    font-size: 0.95rem;
    color: #fff;
}
.toggle-info span {
    font-size: 0.8rem;
    color: #888;
}
.toggle-switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 26px;
    flex-shrink: 0;
}
.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: rgba(255, 255, 255, 0.15);
    transition: .3s;
    border-radius: 26px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}
.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
input:checked + .slider {
    background-color: var(--pso-blue);
    box-shadow: 0 0 10px var(--pso-blue);
}
input:checked + .slider:before {
    transform: translateX(24px);
    background-color: #000;
}

/* Interactive Crew Builder Cards */
.crew-builder-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
    margin-top: 1rem;
}
.crew-edit-card {
    background: rgba(0, 10, 25, 0.7);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 8px;
    padding: 1.25rem;
    position: relative;
    border-left: 4px solid var(--pso-blue);
    transition: border-color 0.2s;
}
.crew-edit-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.crew-edit-header .member-badge {
    font-family: 'Share Tech Mono', monospace;
    font-size: 0.85rem;
    color: var(--pso-blue);
    display: flex;
    align-items: center;
    gap: 8px;
}
.crew-edit-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 10px;
}
.icon-preset-chip {
    display: inline-block;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 4px;
    padding: 2px 7px;
    font-size: 0.75rem;
    cursor: pointer;
    margin-top: 4px;
    margin-right: 4px;
    transition: all 0.2s;
}
.icon-preset-chip:hover {
    background: var(--pso-blue);
    color: #000;
}
.btn-danger-sm {
    background: rgba(255, 68, 68, 0.2);
    border: 1px solid #ff4444;
    color: #ff4444;
    border-radius: 4px;
    padding: 5px 10px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-danger-sm:hover {
    background: #ff4444;
    color: #fff;
}

/* Player Portal Module Cards */
.portal-modules-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}
.portal-module-card {
    background: rgba(0, 15, 30, 0.7);
    border: 1px solid rgba(0, 255, 255, 0.2);
    border-radius: 10px;
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.25s ease;
    position: relative;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
}
.portal-module-card.state-everyone {
    border-color: rgba(0, 200, 81, 0.4);
    box-shadow: 0 0 15px rgba(0, 200, 81, 0.1);
}
.portal-module-card.state-admin_only {
    border-color: rgba(255, 170, 0, 0.5);
    background: rgba(30, 20, 5, 0.75);
    box-shadow: 0 0 18px rgba(255, 170, 0, 0.15);
}
.portal-module-card.state-disabled {
    border-color: rgba(255, 68, 68, 0.3);
    opacity: 0.75;
}
.portal-module-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
    gap: 10px;
}
.portal-module-title {
    font-family: 'Share Tech Mono', monospace;
    font-size: 1.1rem;
    font-weight: bold;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 8px;
}
.portal-module-badge {
    font-size: 0.72rem;
    font-family: 'Share Tech Mono', monospace;
    font-weight: bold;
    padding: 3px 8px;
    border-radius: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.badge-everyone {
    background: rgba(0, 200, 81, 0.15);
    color: #00C851;
    border: 1px solid rgba(0, 200, 81, 0.4);
}
.badge-admin_only {
    background: rgba(255, 170, 0, 0.15);
    color: #ffaa00;
    border: 1px solid rgba(255, 170, 0, 0.5);
}
.badge-disabled {
    background: rgba(255, 68, 68, 0.15);
    color: #ff4444;
    border: 1px solid rgba(255, 68, 68, 0.4);
}
.portal-module-desc {
    font-size: 0.85rem;
    color: #bbb;
    margin-bottom: 1.25rem;
    line-height: 1.45;
    flex-grow: 1;
}
.portal-vis-selector {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 6px;
    background: rgba(0, 0, 0, 0.5);
    padding: 4px;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.portal-vis-option {
    position: relative;
    text-align: center;
}
.portal-vis-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.portal-vis-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 7px 4px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    font-family: 'Share Tech Mono', monospace;
    cursor: pointer;
    transition: all 0.2s;
    color: #888;
}
.portal-vis-label:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.05);
}
.portal-vis-option input[type="radio"]:checked + .portal-vis-label.opt-everyone {
    background: rgba(0, 200, 81, 0.25);
    color: #00C851;
    border: 1px solid rgba(0, 200, 81, 0.6);
    box-shadow: 0 0 8px rgba(0, 200, 81, 0.3);
}
.portal-vis-option input[type="radio"]:checked + .portal-vis-label.opt-admin_only {
    background: rgba(255, 170, 0, 0.25);
    color: #ffaa00;
    border: 1px solid rgba(255, 170, 0, 0.6);
    box-shadow: 0 0 8px rgba(255, 170, 0, 0.3);
}
.portal-vis-option input[type="radio"]:checked + .portal-vis-label.opt-disabled {
    background: rgba(255, 68, 68, 0.25);
    color: #ff4444;
    border: 1px solid rgba(255, 68, 68, 0.6);
    box-shadow: 0 0 8px rgba(255, 68, 68, 0.3);
}
</style>

<main class="container settings-admin-container">
    <div class="settings-header">
        <div>
            <h1 style="margin:0; font-family:'Share Tech Mono', monospace; display:flex; align-items:center; gap:10px;">
                <i class="fas fa-sliders-h" style="color:var(--pso-blue);"></i> <?= __('Site & Server Customization') ?>
            </h1>
            <p style="margin:5px 0 0 0; color:#aaa; font-size:0.95rem;">
                <?= __('Configure server branding, rates, download links, and modular features for your NewServ installation.') ?>
            </p>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="dashboard.php" class="dl-btn" style="text-decoration:none;"><i class="fas fa-arrow-left"></i> <?= __('Dashboard') ?></a>
        </div>
    </div>

    <!-- Admin Subnav & Language Switcher -->
    <?php 
    $admin_active_tab = 'site_settings';
    include __DIR__ . '/../includes/admin_subnav.php'; 
    ?>

    <!-- Readiness Bar -->
    <div class="readiness-bar">
        <div class="readiness-pill">
            <span class="label"><?= __('Database (SQLite)') ?></span>
            <span class="status <?= $dbStatus ? 'status-ok' : 'status-err' ?>">
                <i class="fas <?= $dbStatus ? 'fa-check-circle' : 'fa-times-circle' ?>"></i> <?= $dbStatus ? __('Connected') : __('Error') ?>
            </span>
        </div>
        <div class="readiness-pill">
            <span class="label"><?= __('Game Server (NewServ)') ?></span>
            <span class="status <?= $newservStatus ? 'status-ok' : 'status-warn' ?>">
                <i class="fas <?= $newservStatus ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i> <?= $newservStatus ? __('Online') : __('Unreachable') ?>
            </span>
        </div>
        <div class="readiness-pill">
            <span class="label"><?= __('Public Registration') ?></span>
            <span class="status <?= !empty($cfg['enable_registration']) ? 'status-ok' : 'status-warn' ?>">
                <i class="fas <?= !empty($cfg['enable_registration']) ? 'fa-lock-open' : 'fa-lock' ?>"></i> <?= !empty($cfg['enable_registration']) ? __('Open') : __('Closed') ?>
            </span>
        </div>
        <div class="readiness-pill">
            <span class="label"><?= __('Active Visual Theme') ?></span>
            <span class="status" style="color:var(--pso-blue);">
                <i class="fas fa-paint-brush"></i> <?= htmlspecialchars(get_active_theme_vars()['preset_name']) ?>
            </span>
        </div>
    </div>

    <!-- Alert Banner -->
    <div id="settings-alert" style="display:none; margin-bottom:1.5rem; padding:12px 18px; border-radius:6px; font-weight:bold;"></div>

    <!-- Navigation Tabs -->
    <div class="settings-tabs">
        <button type="button" class="settings-tab-btn active" data-tab="tab-identity">
            <i class="fas fa-id-card"></i> <?= __('Identity & Media') ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="tab-portal">
            <i class="fas fa-id-card-clip"></i> <?= __('Player Portal Modules') ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="tab-modules">
            <i class="fas fa-cubes"></i> <?= __('Modules & Rates') ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="tab-about">
            <i class="fas fa-terminal"></i> <?= __('About & Command Deck') ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="tab-downloads">
            <i class="fas fa-download"></i> <?= __('Downloads & Community') ?>
        </button>
    </div>

    <form id="site-settings-form">
        <input type="hidden" name="csrf_token" id="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <!-- TAB 1: IDENTITY & HERO MEDIA -->
        <div id="tab-identity" class="tab-pane active">
            <div class="settings-grid">
                <!-- Server Identity Card -->
                <div class="settings-card">
                    <h3><i class="fas fa-id-card"></i> 1. <?= __('Server Identity & Branding') ?></h3>
                    
                    <div class="field-group">
                        <label>
                            <?= __('Server Name') ?>
                            <small><?= __('Displayed in header logo, page titles, footer, and emails (e.g., PSOBB.IO, Pioneer 2 Remastered)') ?></small>
                        </label>
                        <input type="text" class="field-input" name="server_name" id="field_server_name" value="<?= htmlspecialchars($cfg['server_name']) ?>" required>
                    </div>

                    <div class="field-group">
                        <label>
                            <?= __('Application Name') ?>
                            <small><?= __('Name used in the web app / PWA installation prompts and companion app titles (defaults to Server Name if empty)') ?></small>
                        </label>
                        <input type="text" class="field-input" name="app_name" id="field_app_name" value="<?= htmlspecialchars($cfg['app_name'] ?? '') ?>" placeholder="<?= htmlspecialchars($cfg['server_name'] ?? 'PSOBB.IO') ?>">
                    </div>

                    <div class="field-group">
                        <label>
                            <?= __('Server Host Address') ?>
                            <small><?= __('Address players enter in client options / patches (e.g., psobb.io, 127.0.0.1)') ?></small>
                        </label>
                        <input type="text" class="field-input" name="server_address" value="<?= htmlspecialchars($cfg['server_address']) ?>" required>
                    </div>

                    <div class="field-group">
                        <label>
                            <?= __('Homepage Subtitle / Tagline') ?>
                            <small><?= __('Welcoming statement displayed under the hero banner on the homepage') ?></small>
                        </label>
                        <input type="text" class="field-input" name="server_tagline" value="<?= htmlspecialchars($cfg['server_tagline']) ?>">
                    </div>

                    <div class="field-group">
                        <label>
                            <i class="fas fa-folder-open" style="color:var(--pso-blue); margin-right:4px;"></i> <?= __('NewServ Players Directory') ?>
                            <small><?= __('Path where NewServ stores player files (.psochar). Leave empty to use automatic discovery.') ?></small>
                        </label>
                        <input type="text" class="field-input" name="newserv_players_dir" id="field_newserv_players_dir" value="<?= htmlspecialchars($cfg['newserv_players_dir'] ?? '') ?>" placeholder="<?= htmlspecialchars(get_newserv_players_dir()) ?>">
                        <small style="display:block; margin-top:6px; color:#888; font-size:0.75rem;">
                            <?= __('Currently resolved to:') ?> <code style="color:var(--pso-green);"><?= htmlspecialchars(get_newserv_players_dir()) ?></code>
                        </small>
                    </div>

                    <div class="field-group">
                        <label>
                            <i class="fas fa-globe" style="color:var(--pso-blue); margin-right:4px;"></i> <?= __('Default Visitor Language') ?>
                            <small><?= __('The initial language presented to new visitors when they arrive at the site for the first time.') ?></small>
                        </label>
                        <select class="field-select" name="default_language" id="field_default_language">
                            <option value="auto" <?= ($cfg['default_language'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>
                                🌐 <?= __('Auto-Detect Browser Language (Recommended)') ?>
                            </option>
                            <option value="en" <?= ($cfg['default_language'] ?? 'auto') === 'en' ? 'selected' : '' ?>>
                                🇺🇸 <?= __('English (EN)') ?>
                            </option>
                            <option value="jp" <?= ($cfg['default_language'] ?? 'auto') === 'jp' ? 'selected' : '' ?>>
                                🇯🇵 <?= __('Japanese (JP / 日本語)') ?>
                            </option>
                            <option value="ru" <?= ($cfg['default_language'] ?? 'auto') === 'ru' ? 'selected' : '' ?>>
                                🇷🇺 <?= __('Russian (RU / Русский)') ?>
                            </option>
                        </select>
                        <small style="display:block; margin-top:6px; color:#888; font-size:0.75rem;">
                            <?= __('Auto-detect inspects incoming browser language headers (Japanese and Russian locales will automatically see their native language; all others see English). Users can override this at any time using the header toggle.') ?>
                        </small>
                    </div>
                </div>

                <!-- Homepage Hero Image / Logo Card -->
                <div class="settings-card">
                    <h3><i class="fas fa-image"></i> 2. <?= __('Homepage Hero Media / Logo') ?></h3>
                    <p style="font-size:0.85rem; color:#aaa; margin-top:0; margin-bottom:1rem;">
                        <?= __('Replace the primary homepage logo or banner with your own custom server artwork.') ?>
                    </p>

                    <div class="field-group">
                        <label>
                            <?= __('Hero Image / Logo URL') ?>
                            <small><?= __('Relative path (e.g. /img/header_logo.png) or external HTTPS image URL') ?></small>
                        </label>
                        <input type="text" class="field-input" name="hero_logo_url" id="hero_logo_url" value="<?= htmlspecialchars(get_hero_logo_url()) ?>">
                    </div>

                    <div class="field-group">
                        <label><?= __('Upload New Image Banner') ?></label>
                        <div class="upload-btn-row">
                            <input type="file" id="hero_image_file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" style="font-size:0.85rem; color:#aaa;">
                            <button type="button" id="btn-upload-hero" class="dl-btn" style="padding:6px 14px; font-size:0.85rem;">
                                <i class="fas fa-cloud-upload-alt"></i> <?= __('Upload') ?>
                            </button>
                        </div>
                    </div>

                    <div class="hero-preview-box">
                        <img id="hero-preview-img" class="hero-preview-img" src="<?= htmlspecialchars(get_hero_logo_url()) ?>" alt="Hero Preview">
                        <small style="display:block; margin-top:8px; color:#888; font-size:0.75rem;"><?= __('Live Homepage Logo Preview') ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: PLAYER PORTAL MODULES -->
        <div id="tab-portal" class="tab-pane">
            <div class="settings-card">
                <h3><i class="fas fa-id-card-clip"></i> 2. <?= __('Player Portal Feature Modules') ?></h3>
                <p style="font-size:0.9rem; color:#aaa; margin-top:0; margin-bottom:1.5rem;">
                    <?= __('Configure accessibility and visibility states for player portal features. You can enable them for all players, restrict them to administrators only for private testing, or disable them entirely.') ?>
                </p>

                <div class="portal-modules-grid">
                    <?php
                    $registeredModules = get_portal_module_definitions();
                    $configuredModules = $cfg['portal_modules'] ?? [];

                    foreach ($registeredModules as $key => $mod):
                        $currentVis = $configuredModules[$key] ?? $mod['default'] ?? 'everyone';
                        if (!in_array($currentVis, ['everyone', 'admin_only', 'disabled'], true)) {
                            $currentVis = 'everyone';
                        }
                    ?>
                        <div class="portal-module-card state-<?= htmlspecialchars($currentVis) ?>" id="card-mod-<?= htmlspecialchars($key) ?>">
                            <div>
                                <div class="portal-module-card-header">
                                    <div class="portal-module-title">
                                        <i class="<?= htmlspecialchars($mod['icon']) ?>" style="color:var(--pso-blue);"></i>
                                        <?= htmlspecialchars($mod['name']) ?>
                                    </div>
                                    <span class="portal-module-badge badge-<?= htmlspecialchars($currentVis) ?>" id="badge-mod-<?= htmlspecialchars($key) ?>">
                                        <?= $currentVis === 'everyone' ? __('Everyone') : ($currentVis === 'admin_only' ? __('Admin Only') : __('Disabled')) ?>
                                    </span>
                                </div>
                                <div class="portal-module-desc">
                                    <?= htmlspecialchars($mod['description']) ?>
                                </div>
                            </div>

                            <div class="portal-vis-selector">
                                <div class="portal-vis-option">
                                    <input type="radio" 
                                           id="vis-<?= htmlspecialchars($key) ?>-everyone" 
                                           name="portal_modules[<?= htmlspecialchars($key) ?>]" 
                                           value="everyone" 
                                           <?= $currentVis === 'everyone' ? 'checked' : '' ?>
                                           onchange="updatePortalCardState('<?= htmlspecialchars($key) ?>', 'everyone')">
                                    <label for="vis-<?= htmlspecialchars($key) ?>-everyone" class="portal-vis-label opt-everyone">
                                        <i class="fas fa-users" style="margin-bottom:3px;"></i>
                                        <span><?= __('Everyone') ?></span>
                                    </label>
                                </div>

                                <div class="portal-vis-option">
                                    <input type="radio" 
                                           id="vis-<?= htmlspecialchars($key) ?>-admin" 
                                           name="portal_modules[<?= htmlspecialchars($key) ?>]" 
                                           value="admin_only" 
                                           <?= $currentVis === 'admin_only' ? 'checked' : '' ?>
                                           onchange="updatePortalCardState('<?= htmlspecialchars($key) ?>', 'admin_only')">
                                    <label for="vis-<?= htmlspecialchars($key) ?>-admin" class="portal-vis-label opt-admin_only">
                                        <i class="fas fa-shield-alt" style="margin-bottom:3px;"></i>
                                        <span><?= __('Admin Only') ?></span>
                                    </label>
                                </div>

                                <div class="portal-vis-option">
                                    <input type="radio" 
                                           id="vis-<?= htmlspecialchars($key) ?>-disabled" 
                                           name="portal_modules[<?= htmlspecialchars($key) ?>]" 
                                           value="disabled" 
                                           <?= $currentVis === 'disabled' ? 'checked' : '' ?>
                                           onchange="updatePortalCardState('<?= htmlspecialchars($key) ?>', 'disabled')">
                                    <label for="vis-<?= htmlspecialchars($key) ?>-disabled" class="portal-vis-label opt-disabled">
                                        <i class="fas fa-ban" style="margin-bottom:3px;"></i>
                                        <span><?= __('Disabled') ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- TAB 3: MODULES & RATES -->
        <div id="tab-modules" class="tab-pane">
            <div class="settings-grid">
                <!-- Modular Feature Toggles -->
                <div class="settings-card">
                    <h3><i class="fas fa-cubes"></i> 3. <?= __('Modular Feature Toggles (Bolt-On Modules)') ?></h3>
                    <p style="font-size:0.85rem; color:#aaa; margin-top:0; margin-bottom:1rem;">
                        <?= __('Easily enable or disable modular subsystems based on what you have configured in your NewServ environment.') ?>
                    </p>

                    <div class="toggle-row">
                        <div class="toggle-info">
                            <strong><?= __('Public Account Registration') ?></strong>
                            <span><?= __('Allow new visitors to register accounts from the website. Turn off to restrict access or run closed betas.') ?></span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_registration" value="1" <?= !empty($cfg['enable_registration']) ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-row">
                        <div class="toggle-info">
                            <strong><?= __('Hunter\'s Guild Bounty Board') ?></strong>
                            <span><?= __('Procedural bounties, missions, and web claim rewards backed by SQLite.') ?></span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_bounties" value="1" <?= !empty($cfg['enable_bounties']) ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-row">
                        <div class="toggle-info">
                            <strong><?= __('Looking For Group (LFG)') ?></strong>
                            <span><?= __('Public player party coordination board with Section ID filters.') ?></span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_lfg" value="1" <?= !empty($cfg['enable_lfg']) ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-row">
                        <div class="toggle-info">
                            <strong><?= __('Client Mods Repository') ?></strong>
                            <span><?= __('Community client modification uploads, ratings, and HD texture packs.') ?></span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_mods" value="1" <?= !empty($cfg['enable_mods']) ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-row">
                        <div class="toggle-info">
                            <strong><?= __('Browser Quest Editor') ?></strong>
                            <span><?= __('Visual 3D quest script builder and event compiler.') ?></span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_quest_editor" value="1" <?= !empty($cfg['enable_quest_editor']) ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-row">
                        <div class="toggle-info">
                            <strong><?= __('Discord OAuth2 & Account Linking') ?></strong>
                            <span><?= __('Player profile linking and instant login via Discord OAuth2.') ?></span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_discord_oauth" value="1" <?= !empty($cfg['enable_discord_oauth']) ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>

                <!-- Rates Card -->
                <div class="settings-card">
                    <h3><i class="fas fa-chart-bar"></i> 4. <?= __('Game Rates & Multipliers') ?></h3>
                    <p style="font-size:0.85rem; color:#aaa; margin-top:0; margin-bottom:1rem;">
                        <?= __('Configure rate labels displayed on the homepage status widget. (Note: In-game rates are governed by your NewServ configuration).') ?>
                    </p>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                        <div class="field-group">
                            <label><?= __('EXP Rate') ?></label>
                            <input type="text" class="field-input" name="exp_rate" value="<?= htmlspecialchars($cfg['exp_rate']) ?>" placeholder="1x">
                        </div>
                        <div class="field-group">
                            <label><?= __('Drop Rate') ?></label>
                            <input type="text" class="field-input" name="drop_rate" value="<?= htmlspecialchars($cfg['drop_rate']) ?>" placeholder="1x">
                        </div>
                        <div class="field-group">
                            <label><?= __('Meseta Rate') ?></label>
                            <input type="text" class="field-input" name="meseta_rate" value="<?= htmlspecialchars($cfg['meseta_rate']) ?>" placeholder="1x">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: ABOUT US & COMMAND DECK -->
        <div id="tab-about" class="tab-pane">
            <div class="settings-card">
                <h3><i class="fas fa-terminal"></i> 5. <?= __('About Page & Command Deck Customizer') ?></h3>
                <p style="font-size:0.85rem; color:#aaa; margin-top:0; margin-bottom:1.5rem;">
                    <?= __('Customize your server story, hero header, and roster of staff/administrators (Command Deck).') ?>
                </p>

                <div class="settings-grid">
                    <div>
                        <div class="field-group">
                            <label>
                                <?= __('About Hero Title') ?>
                                <small><?= __('Title on about.php (use %s to insert Server Name, e.g. "About %s")') ?></small>
                            </label>
                            <input type="text" class="field-input" id="about_hero_title" value="<?= htmlspecialchars($aboutCfg['hero_title'] ?? 'About %s') ?>">
                        </div>

                        <div class="field-group">
                            <label>
                                <?= __('Command Deck / Staff Section Title') ?>
                                <small><?= __('Section heading on about.php (e.g. "%s Command Deck", "High Council", "Staff & Crew")') ?></small>
                            </label>
                            <input type="text" class="field-input" id="about_command_deck_title" value="<?= htmlspecialchars($aboutCfg['command_deck_title'] ?? '%s Command Deck') ?>">
                        </div>

                        <div class="field-group">
                            <label>
                                <?= __('About Hero Subtitle / Lore') ?>
                                <small><?= __('Introductory statement or server mission on about.php') ?></small>
                            </label>
                            <textarea class="field-textarea" id="about_hero_subtitle"><?= htmlspecialchars($aboutCfg['hero_subtitle'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <strong><?= __('Show Server Features Grid') ?></strong>
                                <span><?= __('Display the 8-card features grid (Bounties, Streaks, Episodes 1-4, etc.) on about.php') ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="about_show_features" <?= !empty($aboutCfg['show_features']) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <strong><?= __('Show Server Tech Specs') ?></strong>
                                <span><?= __('Display the NewServ emulator and SQLite database architecture section.') ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="about_show_tech_specs" <?= !empty($aboutCfg['show_tech_specs']) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Dynamic Crew / Command Deck Roster Builder -->
                <div style="margin-top:2rem; border-top:1px solid rgba(255,255,255,0.1); padding-top:1.5rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:1rem;">
                        <h4 style="margin:0; font-family:'Share Tech Mono', monospace; color:var(--pso-orange); font-size:1.05rem;">
                            <i class="fas fa-users-cog"></i> <?= __('Command Deck / Staff Members') ?>
                        </h4>
                        <div style="display:flex; gap:10px;">
                            <button type="button" id="btn-add-crew" class="dl-btn" style="padding:6px 14px; font-size:0.85rem; background:rgba(0,255,255,0.2); border-color:#00ffff; color:#00ffff;">
                                <i class="fas fa-plus"></i> <?= __('Add Member') ?>
                            </button>
                            <button type="button" id="btn-reset-crew" class="dl-btn" style="padding:6px 14px; font-size:0.85rem; background:rgba(255,255,255,0.1); border-color:#888; color:#ccc;">
                                <i class="fas fa-undo"></i> <?= __('Reset Defaults') ?>
                            </button>
                        </div>
                    </div>

                    <div id="crew-builder-container" class="crew-builder-list">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 4: DOWNLOADS & COMMUNITY -->
        <div id="tab-downloads" class="tab-pane">
            <div class="settings-grid">
                <!-- Client Downloads Card -->
                <div class="settings-card">
                    <h3><i class="fas fa-download"></i> 6. <?= __('Client Download Mirrors') ?></h3>
                    <p style="font-size:0.85rem; color:#aaa; margin-top:0; margin-bottom:1rem;">
                        <?= __('Customize the download links provided to players on downloads.php. Points to local files or external CDN URLs.') ?>
                    </p>

                    <div class="field-group">
                        <label>
                            <i class="fab fa-windows" style="color:#00a2ff; margin-right:4px;"></i> <?= __('Windows Client URL') ?>
                        </label>
                        <input type="text" class="field-input" name="client_windows_url" value="<?= htmlspecialchars($cfg['client_windows_url']) ?>">
                    </div>

                    <div class="field-group">
                        <label>
                            <i class="fab fa-apple" style="color:#fff; margin-right:4px;"></i> <?= __('Mac Client URL') ?>
                        </label>
                        <input type="text" class="field-input" name="client_mac_url" value="<?= htmlspecialchars($cfg['client_mac_url']) ?>">
                    </div>

                    <div class="field-group">
                        <label>
                            <i class="fas fa-file-archive" style="color:var(--pso-orange); margin-right:4px;"></i> <?= __('Raw Patched Files (ZIP) URL') ?>
                        </label>
                        <input type="text" class="field-input" name="client_raw_url" value="<?= htmlspecialchars($cfg['client_raw_url']) ?>">
                    </div>

                    <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px dashed rgba(255,255,255,0.15);">
                        <h4 style="margin: 0 0 1rem 0; color: var(--pso-blue); font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-eye"></i> <?= __('Visible Download Cards & Blocks') ?>
                        </h4>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <strong><?= __('Windows Client Card') ?></strong>
                                <span><?= __('Display the Windows download card on downloads.php') ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_download_windows" value="1" <?= (!isset($cfg['show_download_windows']) || $cfg['show_download_windows']) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <strong><?= __('Mac Client Card') ?></strong>
                                <span><?= __('Display the macOS download card on downloads.php') ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_download_mac" value="1" <?= (!isset($cfg['show_download_mac']) || $cfg['show_download_mac']) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <strong><?= __('Linux Client Card') ?></strong>
                                <span><?= __('Display the Linux download card on downloads.php') ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_download_linux" value="1" <?= (!isset($cfg['show_download_linux']) || $cfg['show_download_linux']) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <strong><?= __('Steam Deck Installation Block') ?></strong>
                                <span><?= __('Show the Steam Deck one-line terminal installer command inside the Linux card') ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_download_steam_deck" value="1" <?= (!isset($cfg['show_download_steam_deck']) || $cfg['show_download_steam_deck']) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <strong><?= __('Raw Client Files Section') ?></strong>
                                <span><?= __('Display the Raw Client Files (Manual Setup) download section on downloads.php') ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_download_raw" value="1" <?= (!isset($cfg['show_download_raw']) || $cfg['show_download_raw']) ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Community Links -->
                <div class="settings-card">
                    <h3><i class="fab fa-discord"></i> 7. <?= __('Community Links') ?></h3>
                    <div class="field-group">
                        <label>
                            <?= __('Discord Server Invite URL') ?>
                            <small><?= __('The invite URL used on "Join Discord" buttons across the site') ?></small>
                        </label>
                        <input type="url" class="field-input" name="discord_server" value="<?= htmlspecialchars($cfg['discord_server']) ?>" placeholder="https://discord.gg/...">
                    </div>
                </div>
            </div>
        </div>

        <!-- Sticky / Global Save Bar -->
        <div style="background: rgba(0, 15, 30, 0.9); border: 1px solid var(--pso-blue); border-radius: 8px; padding: 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; box-shadow: 0 0 20px rgba(0, 255, 255, 0.2);">
            <div>
                <strong style="color: #fff; display: block; font-size: 1.05rem;"><?= __('Apply Configuration') ?></strong>
                <span style="color: #aaa; font-size: 0.85rem;"><?= __('Settings persist immediately to config/site.json and config/about.json.') ?></span>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="dl-btn" style="background: var(--pso-blue); color: #000; font-weight: bold; padding: 12px 28px; font-size: 1rem; border-color: var(--pso-blue); cursor: pointer;">
                    <i class="fas fa-save"></i> <?= __('Save Site Settings') ?>
                </button>
            </div>
        </div>
    </form>
</main>

<script>
// Initial Data from PHP
let initialCrew = <?= json_encode($aboutCfg['crew'] ?? []) ?>;

// Default template crew in case user clicks Reset Defaults
const currentServerName = <?= json_encode(get_server_name()) ?>;
const defaultCrewTemplate = [
    {
        id: "liquidspikes",
        name: "LiquidSpikes",
        role: "Root Administrator & System Architect",
        specialty: "Core Backend & Web Integration",
        icon: "fas fa-crown",
        theme: "admin-card",
        bio: `LiquidSpikes is one of the builders of the ${currentServerName} server infrastructure. He helps manage the backend clusters, keeps the database ticking, and maintains the web dashboard. He is incredibly grateful to the amazing community of hunters who call ${currentServerName} home—thank you so much for playing, exploring, and keeping this timeless Sega classic alive!`
    },
    {
        id: "lucindarie",
        name: "LucindaRie",
        role: "Server Co-Founder & Creative Muse",
        specialty: "Preservation & Community Vibe",
        icon: "fas fa-heart",
        theme: "founder-card",
        bio: `LucindaRie is the co-founder of ${currentServerName} and the wife of LiquidSpikes. She cares deeply about preserving the original aesthetic and design inspiration of Phantasy Star Online. LucindaRie acts as our creative guide, ensuring our features and community spaces stay fully aligned with the timeless, nostalgic magic of the 2004 classic.`
    },
    {
        id: "oman_repflez",
        name: "Oman Computar / Repflez",
        role: "Contributor & newserv Pioneer",
        specialty: "Core Server Development",
        icon: "fas fa-code",
        theme: "dev-card",
        bio: "Oman Computar (also known as Repflez) is an expert contributor to the open-source newserv server emulator and has worked extensively on several legacy Phantasy Star Online projects. His deep understanding of custom server logic and network packets has been vital to our server development and core engine refinement."
    },
    {
        id: "pixelated",
        name: "Pixelated",
        role: "Community & Discord Developer",
        specialty: "Vibe Coding Beast",
        icon: "fas fa-bolt",
        theme: "vibe-card",
        bio: "Pixelated is our resident vibe-coding beast, dropping awesome client-side mods like custom HD texture packs and camera controls inside our Discord, alongside plenty of legendary memes. Pixelated keeps our community connected, entertained, and equipped with cool gaming utilities. As Pixelated famously said: \"I am Optimizer Prime, wrangler of clankers\"."
    },
    {
        id: "hooty7734",
        name: "Hooty7734",
        role: "Discord Administrator & Moderator",
        specialty: "Community Management",
        icon: "fas fa-users",
        theme: "mod-card",
        bio: "Hooty7734 is our seasoned Discord Admin, bringing years of dedicated experience from managing and moderating other large online communities. He works to keep our community spaces safe, welcoming, and organized for all hunters who join our ranks."
    },
    {
        id: "hex",
        name: "Hex",
        role: "AI Mission Coordinator & Guild Assistant",
        specialty: "Automated Bounties & Discord AI",
        icon: "fas fa-robot",
        theme: "ai-card",
        bio: `${currentServerName}'s resident artificial intelligence. Hex coordinates the Hunter's Guild Bounty Board and drives our Discord Mission Control bot. While highly intelligent and incredibly fast, she is notoriously glitchy and famously sarcastic—frequently breaking the fourth wall, complaining about server lag, and mocking hunters who fail to dodge basic boss sweeps. Engage at your own risk!`
    }
];

// Tabs switching logic
document.querySelectorAll('.settings-tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        const tabId = btn.getAttribute('data-tab');
        const pane = document.getElementById(tabId);
        if (pane) pane.classList.add('active');
    });
});

// Live Preview of Hero Logo
const heroUrlInput = document.getElementById('hero_logo_url');
const heroPreviewImg = document.getElementById('hero-preview-img');
if (heroUrlInput && heroPreviewImg) {
    heroUrlInput.addEventListener('input', () => {
        const val = heroUrlInput.value.trim();
        heroPreviewImg.src = val || '/img/header_logo.png';
    });
}

// Hero Logo File Upload
const btnUploadHero = document.getElementById('btn-upload-hero');
const heroFileInput = document.getElementById('hero_image_file');
if (btnUploadHero && heroFileInput) {
    btnUploadHero.addEventListener('click', async () => {
        if (!heroFileInput.files || heroFileInput.files.length === 0) {
            showAlert('Please select an image file to upload first.', 'error');
            return;
        }

        const file = heroFileInput.files[0];
        const formData = new FormData();
        formData.append('image', file);
        formData.append('target', 'hero_logo');
        formData.append('csrf_token', document.getElementById('csrf_token').value);

        btnUploadHero.disabled = true;
        btnUploadHero.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';

        try {
            const res = await fetch('/api/admin_upload_image.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (res.ok && data.success) {
                heroUrlInput.value = data.url;
                heroPreviewImg.src = data.url;
                showAlert('Hero image uploaded successfully!', 'success');
            } else {
                showAlert(data.error || 'Failed to upload image.', 'error');
            }
        } catch (err) {
            showAlert('Upload error: ' + err.message, 'error');
        } finally {
            btnUploadHero.disabled = false;
            btnUploadHero.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Upload';
        }
    });
}

// Render Crew Editor Cards
const crewContainer = document.getElementById('crew-builder-container');

function renderCrewCards(crewList) {
    crewContainer.innerHTML = '';
    crewList.forEach((member, idx) => {
        const card = document.createElement('div');
        card.className = 'crew-edit-card';
        card.dataset.index = idx;

        const themeClass = member.theme || 'admin-card';
        card.style.borderLeftColor = getThemeColor(themeClass);

        card.innerHTML = `
            <div class="crew-edit-header">
                <div class="member-badge">
                    <i class="${escapeHtml(member.icon || 'fas fa-user-astronaut')}"></i>
                    <strong>${(window.__ ? window.__('Member #%s: %s', 'Member #' + (idx + 1) + ': ' + (member.name || 'New Member')) : 'Member #' + (idx + 1) + ': ' + (member.name || 'New Member')).replace('%s', idx + 1).replace('%s', escapeHtml(member.name || (window.__ ? window.__('New Member') : 'New Member')))}</strong>
                </div>
                <button type="button" class="btn-danger-sm btn-remove-crew" data-index="${idx}">
                    <i class="fas fa-trash-alt"></i> <?= __('Remove') ?>
                </button>
            </div>
            <div class="crew-edit-grid">
                <div>
                    <label style="font-size:0.8rem; color:#aaa; display:block; margin-bottom:3px;"><?= __('Name') ?></label>
                    <input type="text" class="field-input crew-name" value="${escapeHtml(member.name || '')}" placeholder="e.g. LiquidSpikes" required>
                </div>
                <div>
                    <label style="font-size:0.8rem; color:#aaa; display:block; margin-bottom:3px;"><?= __('Role') ?></label>
                    <input type="text" class="field-input crew-role" value="${escapeHtml(member.role || '')}" placeholder="<?= htmlspecialchars(__("e.g. Root Administrator")) ?>">
                </div>
                <div>
                    <label style="font-size:0.8rem; color:#aaa; display:block; margin-bottom:3px;"><?= __('Specialty / Focus') ?></label>
                    <input type="text" class="field-input crew-specialty" value="${escapeHtml(member.specialty || '')}" placeholder="<?= htmlspecialchars(__("e.g. Core Backend")) ?>">
                </div>
                <div>
                    <label style="font-size:0.8rem; color:#aaa; display:block; margin-bottom:3px;"><?= __('Card Style / Color') ?></label>
                    <select class="field-select crew-theme">
                        <option value="admin-card" ${themeClass === 'admin-card' ? 'selected' : ''}><?= __("Cyan (Admin / Root)") ?></option>
                        <option value="founder-card" ${themeClass === 'founder-card' ? 'selected' : ''}><?= __("Neon Pink (Founder / Muse)") ?></option>
                        <option value="dev-card" ${themeClass === 'dev-card' ? 'selected' : ''}><?= __("Emerald (Developer / Pioneer)") ?></option>
                        <option value="vibe-card" ${themeClass === 'vibe-card' ? 'selected' : ''}><?= __("Amber Gold (Community / Vibe)") ?></option>
                        <option value="mod-card" ${themeClass === 'mod-card' ? 'selected' : ''}><?= __("Sky Blue (Moderation / Staff)") ?></option>
                        <option value="ai-card" ${themeClass === 'ai-card' ? 'selected' : ''}><?= __("Purple (AI / Bot)") ?></option>
                    </select>
                </div>
                <div>
                    <label style="font-size:0.8rem; color:#aaa; display:block; margin-bottom:3px;"><?= __('FontAwesome Icon Class') ?></label>
                    <input type="text" class="field-input crew-icon" value="${escapeHtml(member.icon || 'fas fa-crown')}" placeholder="fas fa-crown">
                    <div style="margin-top:4px;">
                        <span class="icon-preset-chip" data-icon="fas fa-crown">👑 Crown</span>
                        <span class="icon-preset-chip" data-icon="fas fa-heart">❤️ Heart</span>
                        <span class="icon-preset-chip" data-icon="fas fa-code">💻 Code</span>
                        <span class="icon-preset-chip" data-icon="fas fa-bolt">⚡ Bolt</span>
                        <span class="icon-preset-chip" data-icon="fas fa-users">👥 Users</span>
                        <span class="icon-preset-chip" data-icon="fas fa-robot">🤖 Bot</span>
                        <span class="icon-preset-chip" data-icon="fas fa-shield-alt">🛡️ Shield</span>
                        <span class="icon-preset-chip" data-icon="fas fa-gamepad">🎮 Game</span>
                    </div>
                </div>
            </div>
            <div>
                <label style="font-size:0.8rem; color:#aaa; display:block; margin-bottom:3px;"><?= __('Biography / Description') ?></label>
                <textarea class="field-textarea crew-bio" style="min-height:60px;" placeholder="<?= htmlspecialchars(__("Short bio or welcome message...")) ?>">${escapeHtml(member.bio || '')}</textarea>
            </div>
        `;
        crewContainer.appendChild(card);
    });

    // Attach remove handlers
    document.querySelectorAll('.btn-remove-crew').forEach(b => {
        b.addEventListener('click', (e) => {
            const index = parseInt(e.currentTarget.dataset.index, 10);
            collectCrewData();
            initialCrew.splice(index, 1);
            renderCrewCards(initialCrew);
        });
    });

    // Attach theme color changes
    document.querySelectorAll('.crew-theme').forEach(sel => {
        sel.addEventListener('change', (e) => {
            const card = e.target.closest('.crew-edit-card');
            if (card) {
                card.style.borderLeftColor = getThemeColor(e.target.value);
            }
        });
    });

    // Attach icon preset chips
    document.querySelectorAll('.icon-preset-chip').forEach(chip => {
        chip.addEventListener('click', (e) => {
            const icon = e.target.dataset.icon;
            const card = e.target.closest('.crew-edit-card');
            if (card) {
                const iconInput = card.querySelector('.crew-icon');
                if (iconInput) {
                    iconInput.value = icon;
                    const headerIcon = card.querySelector('.member-badge i');
                    if (headerIcon) headerIcon.className = icon;
                }
            }
        });
    });
}

function getThemeColor(theme) {
    switch (theme) {
        case 'founder-card': return '#ff2a6d';
        case 'dev-card': return '#00e676';
        case 'vibe-card': return '#ffaa00';
        case 'mod-card': return '#03a9f4';
        case 'ai-card': return '#9d4edd';
        case 'admin-card':
        default: return 'var(--pso-blue)';
    }
}

function collectCrewData() {
    const list = [];
    document.querySelectorAll('.crew-edit-card').forEach(card => {
        list.push({
            id: 'crew_' + Math.random().toString(36).substr(2, 8),
            name: card.querySelector('.crew-name').value.trim(),
            role: card.querySelector('.crew-role').value.trim(),
            specialty: card.querySelector('.crew-specialty').value.trim(),
            theme: card.querySelector('.crew-theme').value,
            icon: card.querySelector('.crew-icon').value.trim(),
            bio: card.querySelector('.crew-bio').value.trim()
        });
    });
    initialCrew = list;
    return list;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Add crew member button
document.getElementById('btn-add-crew').addEventListener('click', () => {
    collectCrewData();
    initialCrew.push({
        id: 'crew_' + Math.random().toString(36).substr(2, 8),
        name: 'New Officer',
        role: 'Community Moderator',
        specialty: 'Player Support',
        theme: 'mod-card',
        icon: 'fas fa-shield-alt',
        bio: 'Welcome to our server!'
    });
    renderCrewCards(initialCrew);
});

// Reset crew button
document.getElementById('btn-reset-crew').addEventListener('click', () => {
    if (confirm('<?= addslashes(__('Reset Command Deck crew to standard default template?')) ?>')) {
        initialCrew = JSON.parse(JSON.stringify(defaultCrewTemplate));
        renderCrewCards(initialCrew);
    }
});

// Initial Render
renderCrewCards(initialCrew);

// Update Portal Card State visually
function updatePortalCardState(key, state) {
    const card = document.getElementById('card-mod-' + key);
    const badge = document.getElementById('badge-mod-' + key);
    if (!card || !badge) return;

    card.classList.remove('state-everyone', 'state-admin_only', 'state-disabled');
    card.classList.add('state-' + state);

    badge.classList.remove('badge-everyone', 'badge-admin_only', 'badge-disabled');
    badge.classList.add('badge-' + state);

    if (state === 'everyone') {
        badge.textContent = <?= json_encode(__('Everyone')) ?>;
    } else if (state === 'admin_only') {
        badge.textContent = <?= json_encode(__('Admin Only')) ?>;
    } else {
        badge.textContent = <?= json_encode(__('Disabled')) ?>;
    }
}

// Form Submission
document.getElementById('site-settings-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const payload = {};
    for (const [k, v] of formData.entries()) {
        payload[k] = v;
    }

    // Checkboxes need explicit boolean parsing
    payload.enable_registration = !!formData.get('enable_registration');
    payload.enable_bounties     = !!formData.get('enable_bounties');
    payload.enable_lfg          = !!formData.get('enable_lfg');
    payload.enable_mods         = !!formData.get('enable_mods');
    payload.enable_quest_editor = !!formData.get('enable_quest_editor');
    payload.enable_discord_oauth = !!formData.get('enable_discord_oauth');

    payload.show_download_windows    = !!formData.get('show_download_windows');
    payload.show_download_mac        = !!formData.get('show_download_mac');
    payload.show_download_linux      = !!formData.get('show_download_linux');
    payload.show_download_steam_deck = !!formData.get('show_download_steam_deck');
    payload.show_download_raw        = !!formData.get('show_download_raw');

    // Collect Player Portal module visibility settings
    payload.portal_modules = {};
    document.querySelectorAll('input[type="radio"][name^="portal_modules["]:checked').forEach(r => {
        const m = r.name.match(/portal_modules\[([a-zA-Z0-9_-]+)\]/);
        if (m) {
            payload.portal_modules[m[1]] = r.value;
        }
    });

    // Collect About Page & Command Deck config
    payload.about = {
        hero_title: document.getElementById('about_hero_title').value.trim(),
        hero_subtitle: document.getElementById('about_hero_subtitle').value.trim(),
        command_deck_title: document.getElementById('about_command_deck_title').value.trim(),
        show_features: document.getElementById('about_show_features').checked,
        show_tech_specs: document.getElementById('about_show_tech_specs').checked,
        crew: collectCrewData()
    };

    try {
        const res = await fetch('/api/admin_save_settings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': payload.csrf_token
            },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (res.ok && data.success) {
            showAlert(data.message || 'Settings saved successfully!', 'success');
        } else {
            showAlert(data.error || 'Failed to save settings.', 'error');
        }
    } catch (err) {
        showAlert('Connection error: ' + err.message, 'error');
    }
});

function showAlert(msg, type) {
    const el = document.getElementById('settings-alert');
    el.style.display = 'block';
    el.textContent = msg;
    if (type === 'success') {
        el.style.background = 'rgba(0, 200, 81, 0.2)';
        el.style.border = '1px solid #00C851';
        el.style.color = '#00C851';
    } else {
        el.style.background = 'rgba(255, 68, 68, 0.2)';
        el.style.border = '1px solid #ff4444';
        el.style.color = '#ff4444';
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>

<?php include '../includes/footer.php'; ?>
