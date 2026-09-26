<?php
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/functions.php';
start_secure_session();

if (empty($_SESSION['user']) || empty($_SESSION['user']['is_admin'])) {
    header("Location: ../login.php");
    exit;
}

$page_title = __('Site & Server Settings - Admin');
$current_page = 'site_settings';
include '../includes/header.php';

$cfg = get_site_config();

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
.field-input {
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
.field-input:focus {
    outline: none;
    border-color: var(--pso-blue);
    box-shadow: 0 0 8px rgba(0, 255, 255, 0.3);
}
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

    <!-- Admin Subnav -->
    <div class="admin-subnav">
        <a href="site_settings.php" class="active"><i class="fas fa-sliders-h"></i> <?= __('Site Settings') ?></a>
        <a href="theme_manager.php"><i class="fas fa-palette"></i> <?= __('Theme Manager') ?></a>
        <a href="telemetry.php"><i class="fas fa-chart-line"></i> <?= __('Telemetry') ?></a>
        <a href="mission_manager.php"><i class="fas fa-crosshairs"></i> <?= __('Mission Manager') ?></a>
        <a href="special_deliveries.php"><i class="fas fa-gift"></i> <?= __('Special Deliveries') ?></a>
        <a href="bot_tokens.php"><i class="fas fa-robot"></i> <?= __('Bot Tokens') ?></a>
    </div>

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

    <form id="site-settings-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="settings-grid">
            <!-- Left Column: Identity & Gameplay Rates -->
            <div style="display:flex; flex-direction:column; gap:1.5rem;">
                <!-- Server Identity Card -->
                <div class="settings-card">
                    <h3><i class="fas fa-id-card"></i> 1. <?= __('Server Identity & Branding') ?></h3>
                    
                    <div class="field-group">
                        <label>
                            <?= __('Server Name') ?>
                            <small><?= __('Displayed in header logo, page titles, footer, and emails (e.g., PSOBB.IO, Pioneer 2 Remastered)') ?></small>
                        </label>
                        <input type="text" class="field-input" name="server_name" value="<?= htmlspecialchars($cfg['server_name']) ?>" required>
                    </div>

                    <div class="field-group">
                        <label>
                            <?= __('Server Host Address') ?>
                            <small><?= __('Address players enter in their client options / patch (e.g., psobb.io, 127.0.0.1)') ?></small>
                        </label>
                        <input type="text" class="field-input" name="server_address" value="<?= htmlspecialchars($cfg['server_address']) ?>" required>
                    </div>

                    <div class="field-group">
                        <label>
                            <?= __('Homepage Subtitle / Tagline') ?>
                            <small><?= __('Welcoming statement displayed under the main logo banner on the homepage') ?></small>
                        </label>
                        <input type="text" class="field-input" name="server_tagline" value="<?= htmlspecialchars($cfg['server_tagline']) ?>">
                    </div>
                </div>

                <!-- Rates Card -->
                <div class="settings-card">
                    <h3><i class="fas fa-chart-bar"></i> 2. <?= __('Game Rates & Multipliers') ?></h3>
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

                <!-- Community Links -->
                <div class="settings-card">
                    <h3><i class="fab fa-discord"></i> 3. <?= __('Community Links') ?></h3>
                    <div class="field-group">
                        <label>
                            <?= __('Discord Server Invite URL') ?>
                            <small><?= __('The invite URL used on "Join Discord" buttons across the site') ?></small>
                        </label>
                        <input type="url" class="field-input" name="discord_server" value="<?= htmlspecialchars($cfg['discord_server']) ?>" placeholder="https://discord.gg/...">
                    </div>
                </div>
            </div>

            <!-- Right Column: Modular Feature Toggles & Client Downloads -->
            <div style="display:flex; flex-direction:column; gap:1.5rem;">
                <!-- Modular Feature Toggles -->
                <div class="settings-card">
                    <h3><i class="fas fa-cubes"></i> 4. <?= __('Modular Feature Toggles (Bolt-On Modules)') ?></h3>
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

                <!-- Client Downloads Card -->
                <div class="settings-card">
                    <h3><i class="fas fa-download"></i> 5. <?= __('Client Download Mirrors') ?></h3>
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
                </div>
            </div>
        </div>

        <div style="background: rgba(0, 15, 30, 0.85); border: 1px solid var(--pso-blue); border-radius: 8px; padding: 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <strong style="color: #fff; display: block; font-size: 1.05rem;"><?= __('Apply Configuration') ?></strong>
                <span style="color: #aaa; font-size: 0.85rem;"><?= __('Settings persist immediately to config/site.json and update site-wide.') ?></span>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="dl-btn" style="background: var(--pso-blue); color: #000; font-weight: bold; padding: 10px 24px; font-size: 1rem; border-color: var(--pso-blue);">
                    <i class="fas fa-save"></i> <?= __('Save Site Settings') ?>
                </button>
            </div>
        </div>
    </form>
</main>

<script>
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
