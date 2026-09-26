<?php
/**
 * PSOBB Portal Module: Account Settings & Preferences
 */
?>
                <div class="dashboard-grid-settings">
                    <!-- Profile & Preferences -->
                    <div style="display:flex; flex-direction:column; gap:1.5rem;">
                        <!-- System mail preferences toggle -->
                        <div class="switch-container">
                            <div class="switch-label-block">
                                <h4><?= __('In-Game System Mail') ?></h4>
                                <p><?= __('Receive Simple Mail notifications for bounties directly in-game. Uncheck to suppress.') ?>
                                </p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="system-mail-toggle" onchange="toggleSystemMailPref()">
                                <span class="slider"></span>
                            </label>
                        </div>

                        <!-- Discord streak DM preferences toggle -->
                        <div class="switch-container">
                            <div class="switch-label-block">
                                <h4><?= __('Discord Streak Alerts') ?></h4>
                                <p><?= __('Receive Discord DM alerts when your login streak is about to expire. Uncheck to disable.') ?>
                                </p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="discord-streak-toggle" onchange="toggleDiscordStreakPref()">
                                <span class="slider"></span>
                            </label>
                        </div>

                        <!-- Account Recovery Email -->
                        <div
                            style="padding: 15px; border: 1px solid rgba(0, 255, 255, 0.2); background: rgba(0, 10, 20, 0.4); border-radius: 8px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 6px; flex-wrap:wrap; gap:5px;">
                                <h4 style="margin: 0; color: #00ffff; font-family:'Share Tech Mono',monospace;">
                                    <i class="fas fa-envelope-open-text" style="margin-right: 6px;"></i><?= __('Recovery Email Address') ?>
                                </h4>
                                <span id="email-status-badge" style="font-size:0.75rem; padding: 2px 8px; border-radius: 4px; font-family:'Share Tech Mono',monospace;">--</span>
                            </div>
                            <p style="font-size:0.8rem; color:#aaa; margin:0 0 10px 0;">
                                <?= __('Required for password recovery via the Forgot Password page. If you created your account in-game, link your email here so you can recover your password. A confirmation link will be sent to verify your address.') ?>
                            </p>
                            <div style="display: flex; gap: 8px;">
                                <input type="email" id="account-email-input"
                                    placeholder="<?= __('Enter email address (e.g. hunter@example.com)') ?>" maxlength="100"
                                    style="flex: 1; padding: 8px; background: rgba(0,0,0,0.5); border: 1px solid rgba(0,255,255,0.3); color: #fff; border-radius: 4px; font-family: 'Share Tech Mono', monospace;">
                                <button onclick="saveAccountEmail()" id="btn-save-email" class="dl-btn"
                                    style="padding: 8px 16px; border-color: #00ffff; background: rgba(0,255,255,0.15); color: #00ffff; white-space: nowrap;"><i class="fas fa-paper-plane"></i> <?= __('Send Link') ?></button>
                            </div>
                            <div id="email-message" style="margin-top: 6px; font-size: 0.85em; display: none;"></div>
                        </div>

                        <!-- Display alias name -->
                        <div
                            style="padding: 15px; border: 1px solid rgba(0, 255, 255, 0.2); background: rgba(0, 10, 20, 0.4); border-radius: 8px;">
                            <h4 style="margin-top: 0; color: #00ffff; font-family:'Share Tech Mono',monospace;">
                                <?= __('Leaderboard Display Alias') ?></h4>
                            <p style="font-size:0.8rem; color:#aaa; margin:0 0 10px 0;">
                                <?= __('Set a customized alias (2-20 characters) to represent you on public leaderboards instead of your Guild Card ID.') ?>
                            </p>
                            <div style="display: flex; gap: 8px;">
                                <input type="text" id="display-name-input"
                                    placeholder="<?= __('Enter alias (2-20 chars)') ?>" maxlength="20"
                                    style="flex: 1; padding: 8px; background: rgba(0,0,0,0.5); border: 1px solid rgba(0,255,255,0.3); color: #fff; border-radius: 4px; font-family: 'Share Tech Mono', monospace;">
                                <button onclick="saveDisplayName()" id="btn-save-alias" class="dl-btn"
                                    style="padding: 8px 16px; border-color: #00ffff; background: rgba(0,255,255,0.15); color: #00ffff; white-space: nowrap;"><?= __('Save') ?></button>
                            </div>
                            <div id="alias-message" style="margin-top: 6px; font-size: 0.85em; display: none;"></div>
                        </div>
                    </div>

                    <!-- Integrations & Danger zone -->
                    <div style="display:flex; flex-direction:column; gap:1.5rem;">
                        <div
                            style="padding: 15px; border: 1px solid rgba(0, 255, 255, 0.2); background: rgba(0, 10, 20, 0.4); border-radius: 8px;">
                            <h4 style="margin-top: 0; color: #00ffff; font-family:'Share Tech Mono',monospace;">
                                <?= __('Integrations') ?></h4>
                            <div id="discord-integration-container">
                                <a id="btn-link-discord" href="/api/discord_auth.php" class="dl-btn"
                                    style="width:100%; display:block; text-align:center; text-decoration:none; box-sizing:border-box;"><i
                                        class="fab fa-discord"></i> <?= __('Sign in with Discord') ?></a>
                                <div id="discord-linked-info"
                                    style="display: none; padding: 10px; border: 1px solid #5865F2; background: rgba(88, 101, 242, 0.1); border-radius: 4px; text-align: center; color: #fff;">
                                    <span style="display:block; margin-bottom: 5px;"><?= __('Discord Linked') ?> <i
                                            class="fas fa-check-circle" style="color: #00C851;"></i></span>
                                    <a href="/api/discord_unlink.php"
                                        style="color: #ff4444; font-size: 0.85em; text-decoration: underline;"><?= __('Unlink') ?></a>
                                </div>
                            </div>
                        </div>

                        <div
                            style="padding: 15px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0, 10, 20, 0.4); border-radius: 8px;">
                            <h4 style="margin-top: 0; color: #fff; font-family:'Share Tech Mono',monospace;">
                                <?= __('Account Actions & Security') ?></h4>
                            <button onclick="requestChangePassword()" class="dl-btn"
                                style="width:100%; margin-bottom: 1rem; box-sizing: border-box;"><i
                                    class="fas fa-key"></i> <?= __('Change Password') ?></button>

                            <h4 style="margin-top: 15px; color: #ff4444; font-family:'Share Tech Mono',monospace;">
                                <?= __('Danger Zone') ?></h4>
                            <button onclick="requestDeleteAccount()" class="dl-btn"
                                style="width:100%; border-color:#ff4444; color:#ff4444; background:rgba(255, 68, 68, 0.1); box-sizing: border-box;"><i
                                    class="fas fa-user-slash"></i> <?= __('Delete Account') ?></button>
                        </div>
                    </div>
                </div>
