<?php
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/functions.php';
require_once __DIR__ . '/../api/theme.php';
start_secure_session();

if (empty($_SESSION['user']) || empty($_SESSION['user']['is_admin'])) {
    header("Location: ../login.php");
    exit;
}

$page_title = __('Theme Manager - Admin');
$current_page = 'theme_manager';
include '../includes/header.php';

$presets = get_theme_presets();
$config = get_site_theme_config();
$activeThemeInfo = get_active_theme_vars();
$currentPresetId = $config['default_preset'] ?? 'classic';
$allowUserCustomization = !empty($config['allow_user_customization']);
$customOverrides = $config['custom_overrides'] ?? [];
$discordServer = $config['discord_server'] ?? $config['discord_invite_url'] ?? get_discord_server();
?>

<style>
.theme-admin-container {
    max-width: 1200px;
    margin: 2rem auto;
    padding: 0 1rem;
}
.theme-header {
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
.preset-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.25rem;
    margin-bottom: 2.5rem;
}
.preset-card {
    background: rgba(0, 15, 30, 0.7);
    border: 2px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    padding: 1.25rem;
    cursor: pointer;
    transition: all 0.25s ease;
    position: relative;
    overflow: hidden;
}
.preset-card:hover {
    border-color: var(--pso-blue);
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0, 255, 255, 0.15);
}
.preset-card.selected {
    border-color: var(--pso-blue);
    background: rgba(0, 30, 60, 0.85);
    box-shadow: 0 0 20px rgba(0, 255, 255, 0.3);
}
.preset-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: var(--pso-blue);
    color: #000;
    font-size: 0.7rem;
    font-weight: bold;
    padding: 2px 8px;
    border-radius: 12px;
    text-transform: uppercase;
}
.swatch-row {
    display: flex;
    gap: 8px;
    margin: 12px 0;
}
.color-swatch {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 0 5px rgba(0, 0, 0, 0.5);
}
.customizer-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-bottom: 2.5rem;
}
@media (max-width: 850px) {
    .customizer-grid {
        grid-template-columns: 1fr;
    }
}
.customizer-panel {
    background: var(--pso-panel);
    border: 1px solid rgba(0, 255, 255, 0.2);
    border-radius: 10px;
    padding: 1.5rem;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.4);
}
.color-field-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}
.color-field-row label {
    font-weight: 500;
    font-size: 0.95rem;
    display: flex;
    flex-direction: column;
}
.color-field-row label small {
    color: #888;
    font-family: 'Share Tech Mono', monospace;
    font-size: 0.75rem;
}
.color-field-inputs {
    display: flex;
    align-items: center;
    gap: 10px;
}
.color-picker-input {
    width: 44px;
    height: 38px;
    padding: 0;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 6px;
    background: none;
    cursor: pointer;
}
.color-text-input {
    width: 110px;
    padding: 6px 10px;
    font-family: 'Share Tech Mono', monospace;
    font-size: 0.85rem;
    background: rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 4px;
    color: #fff;
}
.preview-box {
    background: rgba(0, 10, 20, 0.7);
    border: 1px solid rgba(0, 255, 255, 0.25);
    border-radius: 8px;
    padding: 1.5rem;
    margin-top: 1rem;
}
</style>

<main class="container theme-admin-container">
    <div class="theme-header">
        <div>
            <h1 style="margin:0; font-family:'Share Tech Mono', monospace; display:flex; align-items:center; gap:10px;">
                <i class="fas fa-palette" style="color:var(--pso-blue);"></i> <?= __('Global Theme Manager') ?>
            </h1>
            <p style="margin:5px 0 0 0; color:#aaa; font-size:0.95rem;">
                <?= __('Configure the site-wide visual identity, color scheme, and aesthetic presets for all players.') ?>
            </p>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="dashboard.php" class="dl-btn" style="text-decoration:none;"><i class="fas fa-arrow-left"></i> <?= __('Dashboard') ?></a>
        </div>
    </div>

    <!-- Admin Subnav -->
    <div class="admin-subnav">
        <a href="site_settings.php"><i class="fas fa-sliders-h"></i> <?= __('Site Settings') ?></a>
        <a href="theme_manager.php" class="active"><i class="fas fa-palette"></i> <?= __('Theme Manager') ?></a>
        <a href="telemetry.php"><i class="fas fa-chart-line"></i> <?= __('Telemetry') ?></a>
        <a href="mission_manager.php"><i class="fas fa-crosshairs"></i> <?= __('Mission Manager') ?></a>
        <a href="special_deliveries.php"><i class="fas fa-gift"></i> <?= __('Special Deliveries') ?></a>
        <a href="bot_tokens.php"><i class="fas fa-robot"></i> <?= __('Bot Tokens') ?></a>
    </div>

    <!-- Banner Alerts -->
    <div id="theme-alert" style="display:none; margin-bottom:1.5rem; padding:12px 18px; border-radius:6px; font-weight:bold;"></div>

    <form id="theme-manager-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="default_preset" id="selected-preset-input" value="<?= htmlspecialchars($currentPresetId) ?>">

        <h2 style="font-size:1.3rem; margin-bottom:1rem; color:var(--pso-blue); font-family:'Share Tech Mono', monospace;">
            <i class="fas fa-swatchbook"></i> 1. <?= __('Select Server Default Preset') ?>
        </h2>

        <div class="preset-grid">
            <?php foreach ($presets as $id => $p): 
                $isSelected = ($currentPresetId === $id);
                $vars = $p['vars'];
            ?>
                <div class="preset-card <?= $isSelected ? 'selected' : '' ?>" onclick="selectPreset('<?= $id ?>')" id="preset-card-<?= $id ?>">
                    <?php if ($isSelected): ?>
                        <span class="preset-badge" id="badge-<?= $id ?>"><?= __('Active Default') ?></span>
                    <?php endif; ?>
                    <h3 style="margin:0 0 6px 0; font-size:1.15rem; color:#fff;"><?= __($p['name']) ?></h3>
                    <p style="margin:0; font-size:0.85rem; color:#aaa; line-height:1.4; min-height:36px;"><?= __($p['desc']) ?></p>
                    
                    <div class="swatch-row">
                        <span class="color-swatch" style="background: <?= $vars['--pso-blue'] ?>;" title="Primary: <?= $vars['--pso-blue'] ?>"></span>
                        <span class="color-swatch" style="background: <?= $vars['--pso-orange'] ?>;" title="Secondary: <?= $vars['--pso-orange'] ?>"></span>
                        <span class="color-swatch" style="background: <?= $vars['--pso-purple'] ?>;" title="Tertiary: <?= $vars['--pso-purple'] ?>"></span>
                        <span class="color-swatch" style="background: <?= $vars['--pso-dark'] ?>;" title="Dark: <?= $vars['--pso-dark'] ?>"></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="customizer-grid">
            <!-- Custom Color Overrides Column -->
            <div class="customizer-panel">
                <h2 style="font-size:1.2rem; margin-top:0; color:var(--pso-blue); font-family:'Share Tech Mono', monospace;">
                    <i class="fas fa-sliders-h"></i> 2. <?= __('Color Customization') ?>
                </h2>
                <p style="font-size:0.85rem; color:#aaa; margin-bottom:1.5rem;">
                    <?= __('Fine-tune custom CSS color tokens for your selected preset. Changes update the preview below immediately.') ?>
                </p>

                <div class="color-field-row">
                    <label>
                        <?= __('Primary Accent / Glow') ?>
                        <small>--pso-blue</small>
                    </label>
                    <div class="color-field-inputs">
                        <input type="color" class="color-picker-input" id="picker-pso-blue" oninput="updateColorField('--pso-blue', this.value)">
                        <input type="text" class="color-text-input" id="text-pso-blue" name="custom_overrides[--pso-blue]" onchange="updateColorField('--pso-blue', this.value)">
                    </div>
                </div>

                <div class="color-field-row">
                    <label>
                        <?= __('Secondary / Amber Accent') ?>
                        <small>--pso-orange</small>
                    </label>
                    <div class="color-field-inputs">
                        <input type="color" class="color-picker-input" id="picker-pso-orange" oninput="updateColorField('--pso-orange', this.value)">
                        <input type="text" class="color-text-input" id="text-pso-orange" name="custom_overrides[--pso-orange]" onchange="updateColorField('--pso-orange', this.value)">
                    </div>
                </div>

                <div class="color-field-row">
                    <label>
                        <?= __('Tertiary / Cosmic Purple') ?>
                        <small>--pso-purple</small>
                    </label>
                    <div class="color-field-inputs">
                        <input type="color" class="color-picker-input" id="picker-pso-purple" oninput="updateColorField('--pso-purple', this.value)">
                        <input type="text" class="color-text-input" id="text-pso-purple" name="custom_overrides[--pso-purple]" onchange="updateColorField('--pso-purple', this.value)">
                    </div>
                </div>

                <div class="color-field-row">
                    <label>
                        <?= __('Dark Background Base') ?>
                        <small>--pso-dark</small>
                    </label>
                    <div class="color-field-inputs">
                        <input type="color" class="color-picker-input" id="picker-pso-dark" oninput="updateColorField('--pso-dark', this.value)">
                        <input type="text" class="color-text-input" id="text-pso-dark" name="custom_overrides[--pso-dark]" onchange="updateColorField('--pso-dark', this.value)">
                    </div>
                </div>

                <div class="color-field-row">
                    <label>
                        <?= __('Glass Panel Background') ?>
                        <small>--pso-panel (RGBA)</small>
                    </label>
                    <div class="color-field-inputs">
                        <input type="text" class="color-text-input" style="width:170px;" id="text-pso-panel" name="custom_overrides[--pso-panel]" onchange="updateRawStyleVar('--pso-panel', this.value)">
                    </div>
                </div>

                <div class="color-field-row" style="border-bottom:none;">
                    <label>
                        <?= __('Radial Background Gradient') ?>
                        <small>--pso-bg-gradient (CSS gradient)</small>
                    </label>
                    <div class="color-field-inputs" style="width: 100%; margin-top: 6px;">
                        <input type="text" class="color-text-input" style="width:100%;" id="text-pso-bg-gradient" name="custom_overrides[--pso-bg-gradient]" onchange="updateRawStyleVar('--pso-bg-gradient', this.value)">
                    </div>
                </div>

                <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px dashed rgba(255,255,255,0.15);">
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="allow_user_customization" value="1" <?= $allowUserCustomization ? 'checked' : '' ?> style="width:18px; height:18px;">
                        <span style="font-size:0.9rem; color:#eee;">
                            <?= __('Allow players to select their own personal theme preference in the navigation bar') ?>
                        </span>
                    </label>
                </div>

                <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px dashed rgba(255,255,255,0.15);">
                    <label style="display:block; margin-bottom: 6px; font-weight: 500; font-size: 0.95rem;">
                        <i class="fab fa-discord" style="color: #5865F2; margin-right: 5px;"></i> <?= __('Discord Server / Invite URL') ?>
                    </label>
                    <input type="url" class="color-text-input" style="width: 100%; box-sizing: border-box;" id="text-discord-server" name="discord_server" value="<?= htmlspecialchars($discordServer) ?>" placeholder="https://discord.gg/...">
                    <small style="color: #888; display: block; margin-top: 4px;"><?= __('Sitewide community invite link for Join Discord buttons (index, about, etc.)') ?></small>
                </div>
            </div>

            <!-- Live Component Preview Column -->
            <div class="customizer-panel">
                <h2 style="font-size:1.2rem; margin-top:0; color:var(--pso-blue); font-family:'Share Tech Mono', monospace;">
                    <i class="fas fa-eye"></i> 3. <?= __('Interactive Live Preview') ?>
                </h2>
                <p style="font-size:0.85rem; color:#aaa; margin-bottom:1rem;">
                    <?= __('Sample components rendered with the active colors currently loaded in your customizer.') ?>
                </p>

                <div class="preview-box">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                        <h3 style="margin:0; font-size:1.1rem; color:var(--pso-blue);">
                            <i class="fas fa-shield-alt"></i> <?= __('Hunter Profile') ?>
                        </h3>
                        <span class="count-badge" style="background:rgba(0,255,255,0.1); border:1px solid var(--pso-blue); color:var(--pso-blue); padding:2px 8px; border-radius:12px; font-size:0.75rem;">
                            Lv. 200
                        </span>
                    </div>

                    <p style="font-size:0.9rem; line-height:1.5; color:var(--pso-text); margin-bottom:1.25rem;">
                        <?= __('Explore Pioneer 2 and the uncharted surface of Ragol. Your weapons and equipment glow brightly with your chosen photonic frequency.') ?>
                    </p>

                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:1rem;">
                        <button type="button" class="dl-btn" style="padding:6px 14px; font-size:0.85rem;">
                            <i class="fas fa-download"></i> <?= __('Primary Action') ?>
                        </button>
                        <button type="button" class="dl-btn warning-btn" style="padding:6px 14px; font-size:0.85rem; border-color:var(--pso-orange); color:var(--pso-orange);">
                            <i class="fas fa-star"></i> <?= __('Secondary Action') ?>
                        </button>
                    </div>

                    <div style="display:flex; gap:8px; flex-wrap:wrap; font-size:0.75rem;">
                        <span style="background:rgba(0,0,0,0.4); padding:3px 8px; border-radius:4px; border-left:2px solid var(--pso-blue);">Section ID: Skyly</span>
                        <span style="background:rgba(0,0,0,0.4); padding:3px 8px; border-radius:4px; border-left:2px solid var(--pso-purple);">Class: HUmar</span>
                        <span style="background:rgba(0,0,0,0.4); padding:3px 8px; border-radius:4px; border-left:2px solid var(--pso-orange);">Status: Online</span>
                    </div>
                </div>

                <div style="margin-top: 1.5rem; display: flex; flex-direction: column; gap: 10px;">
                    <button type="button" onclick="previewCurrentBrowser()" class="dl-btn" style="width: 100%; border-color: var(--pso-purple); color: #fff; background: rgba(157, 78, 221, 0.2);">
                        <i class="fas fa-laptop"></i> <?= __('Preview on This Browser Only (Cookie)') ?>
                    </button>
                    <button type="submit" class="dl-btn" style="width: 100%; font-size: 1rem; padding: 12px; background: var(--pso-blue); color: #000; font-weight: bold; border-color: var(--pso-blue);">
                        <i class="fas fa-save"></i> <?= __('Save as Global Server Default') ?>
                    </button>
                </div>
            </div>
        </div>
    </form>
</main>

<script>
const PRESETS = <?= json_encode($presets) ?>;
let activeVars = <?= json_encode($activeThemeInfo['vars']) ?>;

function selectPreset(presetId) {
    if (!PRESETS[presetId]) return;
    
    document.getElementById('selected-preset-input').value = presetId;
    
    // Update card styling
    document.querySelectorAll('.preset-card').forEach(card => card.classList.remove('selected'));
    const chosenCard = document.getElementById('preset-card-' + presetId);
    if (chosenCard) chosenCard.classList.add('selected');
    
    // Copy preset vars
    activeVars = Object.assign({}, PRESETS[presetId].vars);
    populateInputs(activeVars);
    applyVarsToDom(activeVars);
}

function populateInputs(vars) {
    for (const [k, v] of Object.entries(vars)) {
        if (k === '--pso-blue') {
            document.getElementById('picker-pso-blue').value = hexOnly(v);
            document.getElementById('text-pso-blue').value = v;
        } else if (k === '--pso-orange') {
            document.getElementById('picker-pso-orange').value = hexOnly(v);
            document.getElementById('text-pso-orange').value = v;
        } else if (k === '--pso-purple') {
            document.getElementById('picker-pso-purple').value = hexOnly(v);
            document.getElementById('text-pso-purple').value = v;
        } else if (k === '--pso-dark') {
            document.getElementById('picker-pso-dark').value = hexOnly(v);
            document.getElementById('text-pso-dark').value = v;
        } else if (k === '--pso-panel') {
            document.getElementById('text-pso-panel').value = v;
        } else if (k === '--pso-bg-gradient') {
            document.getElementById('text-pso-bg-gradient').value = v;
        }
    }
}

function hexOnly(colorStr) {
    if (colorStr.startsWith('#') && (colorStr.length === 7 || colorStr.length === 4)) {
        return colorStr;
    }
    return '#00ffff';
}

function updateColorField(varName, val) {
    activeVars[varName] = val;
    const cleanId = varName.replace('--', '');
    const picker = document.getElementById('picker-' + cleanId);
    const text = document.getElementById('text-' + cleanId);
    if (picker) picker.value = hexOnly(val);
    if (text) text.value = val;
    applyVarsToDom(activeVars);
}

function updateRawStyleVar(varName, val) {
    activeVars[varName] = val;
    applyVarsToDom(activeVars);
}

function applyVarsToDom(vars) {
    const root = document.documentElement;
    for (const [k, v] of Object.entries(vars)) {
        root.style.setProperty(k, v);
    }
    if (vars['--pso-bg-gradient']) {
        document.body.style.backgroundImage = vars['--pso-bg-gradient'];
    }
}

async function previewCurrentBrowser() {
    const preset = document.getElementById('selected-preset-input').value;
    try {
        const res = await fetch('/api/set_theme.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ theme: preset })
        });
        const data = await res.json();
        showBanner('Browser preview set to ' + preset + '! Reloading...', 'success');
        setTimeout(() => location.reload(), 1000);
    } catch (e) {
        showBanner('Error applying preview: ' + e.message, 'error');
    }
}

document.getElementById('theme-manager-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const overrides = {};
    for (const [k, v] of Object.entries(activeVars)) {
        overrides[k] = v;
    }
    
    const payload = {
        csrf_token: formData.get('csrf_token'),
        default_preset: formData.get('default_preset'),
        allow_user_customization: formData.get('allow_user_customization') ? true : false,
        discord_server: formData.get('discord_server') || '',
        discord_invite_url: formData.get('discord_server') || '',
        custom_overrides: overrides
    };

    try {
        const res = await fetch('/api/set_theme.php?action=save_global_theme', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': payload.csrf_token
            },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (res.ok && data.success) {
            showBanner(data.message || 'Global theme saved successfully!', 'success');
        } else {
            showBanner(data.error || 'Failed to save theme settings.', 'error');
        }
    } catch (e) {
        showBanner('Connection error: ' + e.message, 'error');
    }
});

function showBanner(msg, type) {
    const b = document.getElementById('theme-alert');
    b.style.display = 'block';
    b.textContent = msg;
    if (type === 'success') {
        b.style.background = 'rgba(0, 200, 81, 0.2)';
        b.style.border = '1px solid #00C851';
        b.style.color = '#00C851';
    } else {
        b.style.background = 'rgba(255, 68, 68, 0.2)';
        b.style.border = '1px solid #ff4444';
        b.style.color = '#ff4444';
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Initial populate
document.addEventListener('DOMContentLoaded', () => {
    populateInputs(activeVars);
});
</script>

<?php include '../includes/footer.php'; ?>
