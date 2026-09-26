<?php
/**
 * Shared Admin Navigation Bar & Language Switcher
 * 
 * Included across admin pages to provide unified navigation and fast language toggling.
 */
$adminCurrentPage = $admin_active_tab ?? ($current_page ?? 'dashboard');
$currentUri = $_SERVER['REQUEST_URI'] ?? '/admin/dashboard.php';
$activeLang = $PSO_LANG ?? 'en';
?>
<style>
.admin-subnav-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin: 1.5rem 0 2rem 0;
    padding: 8px 12px;
    background: rgba(0, 15, 30, 0.75);
    border: 1px solid rgba(0, 255, 255, 0.2);
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
}
.admin-subnav-links {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}
.admin-subnav-links a {
    padding: 7px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--pso-text, #ccc);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid transparent;
}
.admin-subnav-links a:hover {
    background: rgba(0, 255, 255, 0.1);
    color: #fff;
    border-color: rgba(0, 255, 255, 0.3);
}
.admin-subnav-links a.active {
    background: var(--pso-blue, #00ffff);
    color: #000 !important;
    font-weight: 700;
    border-color: var(--pso-blue, #00ffff);
    box-shadow: 0 0 10px rgba(0, 255, 255, 0.35);
}
.admin-subnav-links a.active i {
    color: #000 !important;
}
.admin-subnav-lang {
    display: flex;
    align-items: center;
    gap: 4px;
    background: rgba(0, 0, 0, 0.6);
    border: 1px solid rgba(0, 255, 255, 0.25);
    padding: 3px 6px;
    border-radius: 20px;
}
.admin-subnav-lang-label {
    font-size: 0.75rem;
    color: #aaa;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0 4px 0 2px;
}
.admin-lang-btn {
    padding: 3px 8px;
    font-size: 0.75rem;
    font-weight: 700;
    text-decoration: none;
    border-radius: 12px;
    color: #aaa;
    transition: all 0.2s;
}
.admin-lang-btn:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.12);
}
.admin-lang-btn.active {
    background: var(--pso-blue, #00ffff);
    color: #000 !important;
    box-shadow: 0 0 8px rgba(0, 255, 255, 0.5);
}
</style>

<div class="admin-subnav-bar">
    <div class="admin-subnav-links">
        <a href="dashboard.php" class="<?= $adminCurrentPage === 'dashboard' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt"></i> <?= __('Dashboard') ?>
        </a>
        <a href="site_settings.php" class="<?= $adminCurrentPage === 'site_settings' ? 'active' : '' ?>">
            <i class="fas fa-sliders-h" style="color:#00ffff;"></i> <?= __('Site Settings') ?>
        </a>
        <a href="theme_manager.php" class="<?= $adminCurrentPage === 'theme_manager' ? 'active' : '' ?>">
            <i class="fas fa-palette" style="color:var(--pso-blue);"></i> <?= __('Theme Manager') ?>
        </a>
        <a href="telemetry.php" class="<?= $adminCurrentPage === 'telemetry' ? 'active' : '' ?>">
            <i class="fas fa-chart-line" style="color:#00ffcc;"></i> <?= __('Telemetry') ?>
        </a>
        <a href="mission_manager.php" class="<?= $adminCurrentPage === 'mission_manager' ? 'active' : '' ?>">
            <i class="fas fa-crosshairs" style="color:#00C851;"></i> <?= __('Mission Manager') ?>
        </a>
        <a href="special_deliveries.php" class="<?= $adminCurrentPage === 'special_deliveries' ? 'active' : '' ?>">
            <i class="fas fa-gift" style="color:#fb923c;"></i> <?= __('Special Deliveries') ?>
        </a>
        <a href="bot_tokens.php" class="<?= $adminCurrentPage === 'bot_tokens' ? 'active' : '' ?>">
            <i class="fas fa-robot" style="color:#a78bfa;"></i> <?= __('Bot Tokens') ?>
        </a>
        <a href="mods.php" class="<?= $adminCurrentPage === 'mods' ? 'active' : '' ?>">
            <i class="fas fa-cube" style="color:#38bdf8;"></i> <?= __('Manage Mods') ?>
        </a>
    </div>

    <div class="admin-subnav-lang" title="<?= __('Admin Interface Language') ?>">
        <div class="admin-subnav-lang-label">
            <i class="fas fa-globe" style="color:var(--pso-blue);"></i>
            <span><?= __('Lang') ?>:</span>
        </div>
        <a href="/api/set_lang.php?lang=en&redirect=<?= urlencode($currentUri) ?>" class="admin-lang-btn <?= $activeLang === 'en' ? 'active' : '' ?>" title="English">EN</a>
        <a href="/api/set_lang.php?lang=jp&redirect=<?= urlencode($currentUri) ?>" class="admin-lang-btn <?= $activeLang === 'jp' ? 'active' : '' ?>" title="日本語">JP</a>
        <a href="/api/set_lang.php?lang=ru&redirect=<?= urlencode($currentUri) ?>" class="admin-lang-btn <?= $activeLang === 'ru' ? 'active' : '' ?>" title="Русский">RU</a>
    </div>
</div>
