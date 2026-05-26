    <footer>
        <nav class="footer-sitemap" aria-label="<?= htmlspecialchars(__('Site map', ENT_QUOTES, 'UTF-8')) ?>">
            <div class="footer-sitemap-col">
                <h4><?= __('Play') ?></h4>
                <ul>
                    <li><a href="/downloads.php"><?= __('Downloads') ?></a></li>
                    <li><a href="/faq.php"><?= __('FAQ') ?></a></li>
                    <li><a href="/register.php" class="footer-signup-link"><?= __('Sign Up') ?></a></li>
                    <li><a href="/login.php" class="footer-login-link" data-guest-label="<?= htmlspecialchars(__('Login', ENT_QUOTES, 'UTF-8')) ?>" data-logged-in-label="<?= htmlspecialchars(__('Dashboard', ENT_QUOTES, 'UTF-8')) ?>"><?= __('Login') ?></a></li>
                </ul>
            </div>
            <div class="footer-sitemap-col">
                <h4><?= __('While Playing') ?></h4>
                <ul>
                    <li><a href="/missions.php"><?= __('Bounty Board') ?></a></li>
                    <li><a href="/lfg.php"><?= __('Looking for Group') ?></a></li>
                    <li><a href="/unlocks.php"><?= __('Claim Rewards') ?></a></li>
                    <li><a href="/rewards.php"><?= __('How Rewards Work') ?></a></li>
                </ul>
            </div>
            <div class="footer-sitemap-col">
                <h4><?= __('Reference') ?></h4>
                <ul>
                    <li><a href="/drops_new.php"><?= __('Drop Charts') ?></a></li>
                </ul>
            </div>
            <div class="footer-sitemap-col">
                <h4><?= __('Community') ?></h4>
                <ul>
                    <li><a href="/about.php"><?= __('About Us') ?></a></li>
                    <li><a href="/stats.php"><?= __('Server Status') ?></a></li>
                    <li><a href="/top_hunters.php"><?= __('Top Hunters') ?></a></li>
                    <li><a href="/legends.php"><?= __('Wall of Legends') ?></a></li>
                    <li><a href="/team.php"><?= __('Team List') ?></a></li>
                    <li><a href="https://discord.gg/28s84HJXha" target="_blank" rel="noopener"><?= __('Discord') ?></a></li>
                </ul>
            </div>
            <div class="footer-sitemap-col">
                <h4><?= __('More') ?></h4>
                <ul>
                    <li><a href="/mods.php"><?= __('Client Mods') ?></a></li>
                    <li><a href="/quest-editor"><?= __('Quest Editor') ?></a></li>
                    <li><a href="/development.php"><?= __('Dev Resources') ?></a></li>
                    <li><a href="/decryption.php"><?= __('Data Decryption') ?></a></li>
                </ul>
            </div>
        </nav>

        <?php if (isset($current_page) && $current_page == 'home'): ?>
        <p>PSOBB Server Name: <span id="server-name">Loading...</span></p>
        <?php elseif (isset($current_page) && $current_page == 'login'): ?>
        <p>PSOBB Server Name: <span id="server-name">Loading...</span> | Uptime: <span id="uptime">Loading...</span></p>
        <?php else: ?>
        <p><?= __('Stats update every 30 seconds.') ?></p>
        <?php endif; ?>
        <p>&copy; 2026 psobb.io private server<br>
        <span style="font-size: 0.8em; opacity: 0.7;">
            Server <a href="https://github.com/fuzziqersoftware/newserv" target="_blank" style="color: inherit; text-decoration: underline;">newserv</a> created by <a href="http://fuzziqersoftware.com" target="_blank" style="color: inherit; text-decoration: underline;">fuzziqersoftware</a>
        </span>
        </p>
    </footer>
</body>

</html>
