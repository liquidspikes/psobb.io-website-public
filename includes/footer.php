    <footer>
        <?php if (isset($current_page) && $current_page == 'home'): ?>
        <p><?= __('PSOBB Server Name:') ?> <span id="server-name"><?= __('Loading...') ?></span></p>
        <?php elseif (isset($current_page) && $current_page == 'login'): ?>
        <p><?= __('PSOBB Server Name:') ?> <span id="server-name"><?= __('Loading...') ?></span> | <?= __('Uptime:') ?> <span id="uptime"><?= __('Loading...') ?></span></p>
        <?php else: ?>
        <p><?= __('Stats update every 30 seconds.') ?></p>
        <?php endif; ?>
        <p>&copy; <?= date('Y') ?> <?= htmlspecialchars(get_server_name()) ?> <?= __('private server') ?><br>
        <span style="font-size: 0.8em; opacity: 0.7;">
            <?= sprintf(__('Server %s created by %s'), '<a href="https://github.com/fuzziqersoftware/newserv" target="_blank" style="color: inherit; text-decoration: underline;">newserv</a>', '<a href="http://fuzziqersoftware.com" target="_blank" style="color: inherit; text-decoration: underline;">fuzziqersoftware</a>') ?>
        </span>
        </p>
    </footer>
</body>

</html>
