<?php
$page_title = 'Rewards Guide - PSOBB Private Server';
$current_page = 'rewards';
include 'includes/header.php';
?>

<link rel="stylesheet" href="css/rewards.css?v=<?php echo time(); ?>">

    <main class="container rewards-page">
        <div class="main-header" style="margin-bottom: 1.75rem;">
            <h1><?= __('Rewards & Bounties Guide') ?></h1>
            <p style="color: rgba(224,240,255,0.85); max-width: 52rem;">
                <?= __('How to use site rewards and guild bounties. Most of this is claimed in the browser, so log into the website when you play if you want to grab things easily.') ?>
            </p>
        </div>

        <div class="layout-grid">
            <section class="main-content">

                <div class="guide-section">
                    <h2><?= __('Level Unlocks (dashboard)') ?></h2>
                    <p style="margin-top: 0;">
                        <?= __('Log in at <a href="https://psobb.io/">PSOBB.io</a> → <a href="login.php">dashboard</a> → <strong>Level Unlocks</strong>. There you\'ll find: login streak claims, one free daily random item, and rewards every 5 levels.') ?>
                    </p>

                    <h3><?= __('Login streak') ?></h3>
                    <p><?= __('Log in to the game or the website each day to earn rewards. Claim your reward from the streak panel. Rewards include grinders and stat materials.') ?></p>

                    <h3><?= __('Daily random item') ?></h3>
                    <p><?= __('Claim one random item a day just for playing.') ?></p>

                    <h3><?= __('Every 5 levels') ?></h3>
                    <p><?= __('At 5, 10, 15, etc. you can claim on Level Unlocks. Choose weapon, armor, shield, mag, or a mixed bundle. The drop matches your level and class; armor usually has 4 slots and bonus stats. Each milestone is once per character.') ?></p>
                </div>

                <div class="guide-section">
                    <h2><?= __('Bounty Board') ?></h2>
                    <p style="margin-top: 0;"><?= __('See <a href="missions.php">Bounty Board</a> for your current guild bounty, what to do, and the reward. Complete it in-game, then claim on that page.') ?></p>

                    <h3><?= __('New bounties') ?></h3>
                    <p><?= __('You only have one personal bounty at a time. After you finish or abandon it, another can show up later while you\'re online—guild mail tells you when there\'s a new one. You won\'t get one every session; check the board when you\'re done playing.') ?></p>

                    <h3><?= __('Goals and rewards') ?></h3>
                    <p><?= __('Goals can be things like meseta, levels, bosses, visiting areas, holding items, techs, using materials, battle wins, challenge stages, playtime, and similar. Rewards are usually gear, mats, meseta, or other items appropriate for your level.') ?></p>

                    <h3><?= __('Leaderboard and events') ?></h3>
                    <p><?= __('Same page lists top hunters by bounty completions and active community goals. Use abandon on the board if you don\'t want your current bounty.') ?></p>
                </div>

                <div class="guide-actions">
                    <a href="missions.php" class="dl-btn" style="text-decoration: none;"><?= __('Open Bounty Board') ?></a>
                    <a href="login.php" class="discord-btn" style="text-decoration: none;"><?= __('Open dashboard') ?></a>
                </div>
            </section>

            <aside class="sidebar">
                <div class="sidebar-widget">
                    <h3 style="margin-top: 0; color: var(--pso-orange); border-bottom: 1px solid rgba(255, 170, 0, 0.25); padding-bottom: 0.35rem;">
                        <?= __('Claiming rewards') ?></h3>
                    <p><?= __('You must be logged into the game with a character and in an active lobby—either on Pioneer 2 or on a map—when you claim rewards. The ship lobby will not work. Applies to streak rewards, daily items, level rewards, and bounty claims.') ?></p>
                    <div class="widget-divider"></div>
                    <p style="margin-bottom: 0;"><?= __('Reading missions or the leaderboard on your phone is fine. When you claim, the same requirement applies—logged into the game with a character, active lobby on Pioneer 2 or a map, not the ship lobby.') ?></p>
                </div>
            </aside>
        </div>
    </main>

<?php include 'includes/footer.php'; ?>
