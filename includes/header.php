<?php
/**
 * PSOBB Website: Global Header Layout
 * 
 * Included on every frontend page. Handles HTML document structure, global CSS/JS
 * imports, and navigation bar rendering. Crucially, it injects the CSRF token into 
 * a meta tag for frontend AJAX scripts to utilize securely.
 */
require_once __DIR__ . '/../api/config.php';
start_secure_session();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'PSOBB Private Server'; ?></title>
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <link rel="icon" type="image/svg+xml" href="/img/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Share+Tech+Mono&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/style.css?v=<?php echo time(); ?>">
    <script src="/js/main.js?v=<?php echo time(); ?>" defer></script>
</head>

<body>
    <div class="scan-lines"></div>
    <header class="animate-fade-in">
        <a href="/" class="logo-text" style="text-decoration:none;">PSOBB.IO</a>
        <div class="menu-toggle" id="mobile-menu">
            <span class="bar"></span>
            <span class="bar"></span>
            <span class="bar"></span>
        </div>
        <nav>
            <ul>
                <li class="dropdown">
                    <a href="javascript:void(0)"
                        class="dropbtn <?php echo in_array($current_page ?? '', ['downloads', 'faq', 'register']) ? 'active' : ''; ?>"><?= __('Play') ?>
                        <i class="fas fa-caret-down"></i></a>
                    <div class="dropdown-content">
                        <a href="/downloads.php"
                            class="<?php echo ($current_page == 'downloads') ? 'active' : ''; ?>"><?= __('Downloads') ?></a>
                        <a href="/faq.php"
                            class="<?php echo ($current_page == 'faq') ? 'active' : ''; ?>"><?= __('FAQ') ?></a>
                        <a href="/register.php" id="nav-signup-link"
                            class="<?php echo ($current_page == 'register') ? 'signup-nav-btn active' : 'signup-nav-btn'; ?>"><?= __('Sign Up') ?></a>
                    </div>
                </li>

                <li class="dropdown">
                    <a href="javascript:void(0)"
                        class="dropbtn <?php echo in_array($current_page ?? '', ['missions', 'lfg', 'unlocks', 'rewards']) ? 'active' : ''; ?>"><?= __('While Playing') ?>
                        <i class="fas fa-caret-down"></i></a>
                    <div class="dropdown-content">
                        <a href="/missions.php" class="<?php echo ($current_page == 'missions') ? 'active' : ''; ?>"
                            style="color: var(--pso-orange);"><?= __('Bounty Board') ?></a>
                        <a href="/lfg.php" class="<?php echo ($current_page == 'lfg') ? 'active' : ''; ?>"
                            style="color: #00ffff;"><?= __('Looking for Group') ?></a>
                        <a href="/unlocks.php"
                            class="<?php echo ($current_page == 'unlocks') ? 'active' : ''; ?>"><?= __('Claim Rewards') ?></a>
                        <a href="/rewards.php"
                            class="<?php echo ($current_page == 'rewards') ? 'active' : ''; ?>"><?= __('How Rewards Work') ?></a>
                    </div>
                </li>

                <li class="dropdown">
                    <a href="javascript:void(0)"
                        class="dropbtn <?php echo in_array($current_page ?? '', ['drops', 'drops_new'], true) ? 'active' : ''; ?>"><?= __('Reference') ?>
                        <i class="fas fa-caret-down"></i></a>
                    <div class="dropdown-content">
                        <a href="/drops_new.php"
                            class="<?php echo ($current_page == 'drops_new') ? 'active' : ''; ?>"><?= __('Drop Charts') ?></a>
                    </div>
                </li>

                <li class="dropdown">
                    <a href="javascript:void(0)"
                        class="dropbtn <?php echo in_array($current_page ?? '', ['about', 'stats', 'top_hunters', 'legends', 'team']) ? 'active' : ''; ?>"><?= __('Community') ?>
                        <i class="fas fa-caret-down"></i></a>
                    <div class="dropdown-content">
                        <a href="/about.php"
                            class="<?php echo ($current_page == 'about') ? 'active' : ''; ?>"><?= __('About Us') ?></a>
                        <a href="/stats.php"
                            class="<?php echo ($current_page == 'stats') ? 'active' : ''; ?>"><?= __('Server Status') ?></a>
                        <span class="dropdown-label"><?= __('Leaderboards') ?></span>
                        <a href="/top_hunters.php"
                            class="<?php echo ($current_page == 'top_hunters') ? 'active' : ''; ?>"><?= __('Top Hunters') ?></a>
                        <a href="/legends.php"
                            class="<?php echo ($current_page == 'legends') ? 'active' : ''; ?>"><?= __('Wall of Legends') ?></a>
                        <a href="/team.php"
                            class="<?php echo ($current_page == 'team') ? 'active' : ''; ?>"><?= __('Team List') ?></a>
                        <a href="https://discord.gg/28s84HJXha" target="_blank" rel="noopener"><?= __('Discord') ?></a>
                    </div>
                </li>

                <li class="dropdown">
                    <a href="javascript:void(0)"
                        class="dropbtn <?php echo in_array($current_page ?? '', ['mods', 'quest-editor', 'decryption', 'development']) ? 'active' : ''; ?>"><?= __('More') ?>
                        <i class="fas fa-caret-down"></i></a>
                    <div class="dropdown-content">
                        <span class="dropdown-label"><?= __('Tools & Mods') ?></span>
                        <a href="/mods.php"
                            class="<?php echo ($current_page == 'mods') ? 'active' : ''; ?>"><?= __('Client Mods') ?></a>
                        <a href="/quest-editor"
                            class="<?php echo ($current_page == 'quest-editor') ? 'active' : ''; ?>"><?= __('Quest Editor') ?></a>
                        <a href="/development.php"
                            class="<?php echo ($current_page == 'development') ? 'active' : ''; ?>"><?= __('Dev Resources') ?></a>
                        <a href="/decryption.php"
                            class="<?php echo ($current_page == 'decryption') ? 'active' : ''; ?>"><?= __('Data Decryption') ?></a>
                    </div>
                </li>

                <li class="dropdown" id="nav-admin-dropdown" style="display: none;">
                    <a href="javascript:void(0)"
                        class="dropbtn <?php echo in_array($current_page ?? '', ['dashboard', 'telemetry', 'mission_manager']) ? 'active' : ''; ?>"
                        style="color: #ff5555;"><?= __('Admin') ?> <i class="fas fa-caret-down"></i></a>
                    <div class="dropdown-content">
                        <a href="/admin/dashboard.php"
                            class="<?php echo ($current_page == 'dashboard') ? 'active' : ''; ?>"><?= __('Dashboard') ?></a>
                        <a href="/admin/telemetry.php"
                            class="<?php echo ($current_page == 'telemetry') ? 'active' : ''; ?>"><?= __('Telemetry') ?></a>
                        <a href="/admin/mission_manager.php"
                            class="<?php echo ($current_page == 'mission_manager') ? 'active' : ''; ?>"><?= __('Mission Manager') ?></a>
                    </div>
                </li>

                <li class="dropdown" id="nav-account-dropdown">
                    <a href="javascript:void(0)"
                        class="dropbtn login-nav-btn <?php echo in_array($current_page ?? '', ['login', 'register', 'unlocks']) ? 'active' : ''; ?>"><?= __('Account') ?>
                        <i class="fas fa-caret-down"></i></a>
                    <div class="dropdown-content" id="nav-account-menu">
                        <div id="nav-account-guest">
                            <a href="/login.php"
                                class="<?php echo ($current_page == 'login') ? 'login-nav-btn active' : 'login-nav-btn'; ?>"><?= __('Login') ?></a>
                            <a href="/register.php"
                                class="<?php echo ($current_page == 'register') ? 'signup-nav-btn active' : 'signup-nav-btn'; ?>"><?= __('Sign Up') ?></a>
                        </div>
                        <div id="nav-account-user" style="display: none;">
                            <a href="/login.php"
                                class="<?php echo ($current_page == 'login') ? 'active' : ''; ?>"><?= __('Dashboard') ?></a>
                            <a href="/unlocks.php"
                                class="<?php echo ($current_page == 'unlocks') ? 'active' : ''; ?>"><?= __('Claim Rewards') ?></a>
                            <a href="/missions.php"
                                class="<?php echo ($current_page == 'missions') ? 'active' : ''; ?>"><?= __('Bounty Board') ?></a>
                            <a href="#" id="nav-logout-link" class="nav-logout-link"><?= __('Logout') ?></a>
                        </div>
                    </div>
                </li>

                <li class="lang-toggle-nav">
                    <?php if (($_COOKIE['psobb_lang'] ?? 'en') === 'jp'): ?>
                        <a href="/api/set_lang.php?lang=en" class="lang-toggle" title="Switch to English"><i
                                class="fas fa-globe-americas"></i> EN</a>
                    <?php else: ?>
                        <a href="/api/set_lang.php?lang=jp" class="lang-toggle" title="日本語に切り替える"><i
                                class="fas fa-globe-asia"></i> JP</a>
                    <?php endif; ?>
                </li>
            </ul>
        </nav>
    </header>
