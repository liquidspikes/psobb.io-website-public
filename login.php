<?php
/**
 * PSOBB Website: Login & Dashboard
 * 
 * Handles user authentication via the NewServ API (using the same credentials as the game client).
 * If logged in, renders the Player Dashboard which provides access to Account Settings, 
 * Character Management (Bank Swap, Section ID), and Discord Integration links.
 */
require_once __DIR__ . '/api/config.php';
$page_title = __('Login - PSOBB Private Server');
$current_page = 'login';
include 'includes/header.php';
require_once __DIR__ . '/includes/portal/modules.php';

// Compute created character slots dynamically
$existing_slots = [0]; // fallback default to slot 1 (index 0)
if (isset($_SESSION['user']['username'])) {
    $playersDir = get_newserv_players_dir();
    $u = strtolower(trim($_SESSION['user']['username']));
    if (!empty($u)) {
        $found_slots = [];
        for ($slot = 0; $slot < 20; $slot++) {
            $charFilename = "player_{$u}_{$slot}.psochar";
            $charPath = $playersDir . $charFilename;
            if (!file_exists($charPath)) {
                if (is_dir($playersDir)) {
                    $files = scandir($playersDir);
                    foreach ($files as $f) {
                        if (strcasecmp($f, $charFilename) === 0) {
                            $charPath = $playersDir . $f;
                            break;
                        }
                    }
                }
            }
            if (file_exists($charPath)) {
                $found_slots[] = $slot;
            }
        }
        if (!empty($found_slots)) {
            $existing_slots = $found_slots;
        }
    }
}
?>

<main class="container">
    <div class="login-container">
        <h1><?= __('Player Portal') ?></h1>

        <div class="login-container-form">
            <p><?= __('Access your account data, character stats, and bank.') ?></p>
            <div id="login-error"
                style="color: #ff4444; display: none; margin-bottom: 1rem; background: rgba(255, 0, 0, 0.1); padding: 10px; border: 1px solid #ff4444; border-radius: 4px;">
            </div>

            <form class="login-form" method="POST" action="login.php">
                <div class="form-group">
                    <label for="username"><?= __('Username') ?></label>
                    <input type="text" id="username" name="username" placeholder="<?= __('Enter your username') ?>"
                        required>
                </div>

                <div class="form-group">
                    <label for="password"><?= __('Password') ?></label>
                    <input type="password" id="password" name="password" placeholder="<?= __('Enter your password') ?>"
                        required>
                </div>

                <div class="form-group" id="captcha-group" style="display:none;">
                    <label for="captcha"><?= __('Security Check') ?></label>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <img id="captcha-img" src="api/captcha.php" alt="CAPTCHA"
                            style="cursor:pointer; border:1px solid #444; height:40px;" title="<?= htmlspecialchars(__('Click to reload')) ?>"
                            onclick="this.src='api/captcha.php?'+Math.random()">
                        <input type="text" id="captcha" name="captcha" placeholder="<?= __('Enter code') ?>"
                            style="width: 120px;">
                    </div>
                </div>

                <button type="submit" class="dl-btn login-submit"><?= __('Login') ?></button>
            </form>

            <div class="login-help">
                <p><a href="forgot_password" style="color: #aaa; font-size: 0.9em;"><?= __('Forgot Password?') ?></a>
                </p>
                <p style="margin-top: 5px;"><?= __('Don\'t have an account?') ?> <a href="register"
                        style="color: #4CAF50;"><?= __('Create one here') ?></a>.</p>
            </div>
        </div>

        <div id="dashboard" style="display: none;">
            <div
                style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:10px;">
                <h2 style="margin:0; font-family:'Share Tech Mono', monospace;"><i class="fas fa-id-card animate-pulse"
                        style="color:#00ffff; margin-right:8px;"></i><?= __('Welcome,') ?> <span
                        id="dash-username-header" style="color:#00ffff;">Hunter</span></h2>
                <div style="display:flex; gap:10px;">
                    <button id="player-guide-btn" onclick="openPlayerGuideModal()" class="dl-btn"
                        style="border: 1px solid #00ffff; color: #00ffff; background: rgba(0, 255, 255, 0.1); padding: 5px 15px; box-shadow: 0 0 5px rgba(0, 255, 255, 0.2); font-family:'Share Tech Mono',monospace; font-weight:bold; font-size:0.85rem;"><i
                            class="fas fa-terminal"></i> <?= __('Guide & Commands') ?></button>
                    <button onclick="logout()" class="dl-btn"
                        style="border: 1px solid #ff4444; color: #ff4444; background: rgba(255, 68, 68, 0.1); padding: 5px 15px; box-shadow: 0 0 5px rgba(255, 68, 68, 0.2); font-family:'Share Tech Mono',monospace; font-weight:bold; font-size:0.85rem;"><i
                            class="fas fa-sign-out-alt"></i> <?= __('Logout') ?></button>
                </div>
            </div>

            <?php
            $active_portal_modules = get_active_portal_modules($_SESSION['user'] ?? null);
            ?>

            <!-- Dashboard SPA Tabs Navigation -->
            <?php render_portal_tabs($active_portal_modules); ?>

            <!-- Dashboard Modular Panes -->
            <?php render_portal_panes($active_portal_modules, null, ['existing_slots' => $existing_slots]); ?>

            <!-- Shared Portal Modals -->
            <?php render_portal_modals(['existing_slots' => $existing_slots]); ?>
        </div>
</main>

<?php include 'includes/footer.php'; ?>