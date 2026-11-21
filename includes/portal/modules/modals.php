<?php
/**
 * PSOBB Portal: Shared Modals & Overlays
 */
global $NEWSERV_COMMAND_PREFIX;
$rawCmdPrefix = ($NEWSERV_COMMAND_PREFIX ?? '') !== '' ? $NEWSERV_COMMAND_PREFIX : '$';
$cmdPrefix = htmlspecialchars($rawCmdPrefix);
?>
            <!-- Level Milestone Crate Claim Modal -->
            <div id="claim-modal" class="modal" style="display: none;">
                <div class="modal-content" style="border-color: #00ffff; box-shadow: 0 0 20px rgba(0, 255, 255, 0.3);">
                    <span class="close-modal"
                        onclick="document.getElementById('claim-modal').style.display='none'">&times;</span>
                    <h2 id="modal-title"
                        style="font-family: 'Share Tech Mono', 'Segoe UI', monospace; color: #00ffff; margin-top:0; border-bottom:1px solid rgba(0,255,255,0.2); padding-bottom:8px;">
                        <?= __('Claim Level') ?> <span id="modal-level"></span> <?= __('Reward') ?></h2>
                    <p
                        style="margin-top: 1rem; margin-bottom: 1.5rem; color: rgba(255, 255, 255, 0.7); font-size:0.9rem;">
                        <?= __('Choose a reward to drop beside your character.') ?>
                    </p>

                    <div class="reward-options">
                        <button class="dl-btn claim-category-btn" data-category="Weapon"
                            style="width: 100%; border-color: #ff4444; background: rgba(255, 68, 68, 0.15); color: #ffaaaa; font-weight:bold; font-family:'Share Tech Mono',monospace; padding:10px;"><?= __('Weapon Package') ?></button>
                        <button class="dl-btn claim-category-btn" data-category="Armor"
                            style="width: 100%; border-color: #33b5e5; background: rgba(51, 181, 229, 0.15); color: #aaddff; font-weight:bold; font-family:'Share Tech Mono',monospace; padding:10px;"><?= __('Armor / Frame (4 Slots)') ?></button>
                        <button class="dl-btn claim-category-btn" data-category="Shield"
                            style="width: 100%; border-color: #33b5e5; background: rgba(51, 181, 229, 0.15); color: #aaddff; font-weight:bold; font-family:'Share Tech Mono',monospace; padding:10px;"><?= __('Shield / Barrier') ?></button>
                        <button class="dl-btn claim-category-btn" data-category="Mag"
                            style="width: 100%; border-color: #00c8c8; background: rgba(0, 200, 200, 0.15); color: #80f0f0; font-weight:bold; font-family:'Share Tech Mono',monospace; padding:10px;"><?= __('Rare custom Mag (1x)') ?></button>
                        <button class="dl-btn claim-category-btn" data-category="Random"
                            style="width: 100%; border-color: #00C851; background: rgba(0, 200, 81, 0.15); color: #aaffaa; font-weight:bold; font-family:'Share Tech Mono',monospace; padding:10px;"><?= __('Utility / Tools (3x Drops)') ?></button>
                    </div>

                    <div id="modal-error" style="color: #ff4444; margin-top: 1rem; display: none; font-weight:bold;">
                    </div>
                </div>
            </div>

            <!-- Drop Animation Overlay -->
            <div id="drop-animation-overlay" class="drop-overlay" style="display: none;">
                <div id="countdown-text" class="countdown-text"></div>
                <div class="thank-you-text" id="thank-you-text"><?= __('THANK YOU FOR PLAYING!') ?></div>
                <div class="drop-item-box" id="drop-item-box">
                    <div class="drop-box-core">
                        <div class="face front"></div>
                        <div class="face back"></div>
                        <div class="face right"></div>
                        <div class="face left"></div>
                        <div class="face top"></div>
                        <div class="face bottom"></div>
                    </div>
                    <div class="drop-box-glow"></div>
                </div>
            </div>

            <!-- Change Password Modal -->
            <div id="change-pass-modal"
                style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; justify-content:center; align-items:center;">
                <div
                    style="background: #1a1a1a; padding: 2rem; border-radius: 8px; border: 1px solid #00ffff; max-width: 400px; width: 90%; box-shadow: 0 0 20px rgba(0, 255, 255, 0.2);">
                    <h3 style="color: #fff; margin-top:0; font-family:'Share Tech Mono',monospace;">
                        <?= __('Change Password') ?></h3>

                    <input type="password" id="cp-old" placeholder="<?= __('Current Password') ?>"
                        style="width: 100%; padding: 10px; margin: 10px 0; background: #000; border: 1px solid #444; color: #fff; border-radius:4px;">
                    <input type="password" id="cp-new" placeholder="<?= __('New Password') ?>"
                        style="width: 100%; padding: 10px; margin: 10px 0; background: #000; border: 1px solid #444; color: #fff; border-radius:4px;">
                    <input type="password" id="cp-confirm" placeholder="<?= __('Confirm New Password') ?>"
                        style="width: 100%; padding: 10px; margin: 10px 0; background: #000; border: 1px solid #444; color: #fff; border-radius:4px;">

                    <div id="cp-error" style="color: #ff4444; display: none; margin-bottom: 1rem; font-weight:bold;">
                    </div>
                    <div id="cp-success" style="color: #00C851; display: none; margin-bottom: 1rem; font-weight:bold;">
                    </div>

                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button onclick="closeChangePassModal()" class="dl-btn"
                            style="background: rgba(255,255,255,0.1); border-color: #555;"><?= __('Cancel') ?></button>
                        <button onclick="confirmChangePass()" id="btn-confirm-cp" class="dl-btn"
                            style="background: rgba(0, 255, 255, 0.15); border-color: #00ffff; color: white;"><?= __('Update') ?></button>
                    </div>
                </div>
            </div>

            <!-- Delete Confirmation Modal -->
            <div id="delete-modal"
                style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; justify-content:center; align-items:center;">
                <div
                    style="background: #1a1a1a; padding: 2rem; border-radius: 8px; border: 1px solid #ff4444; max-width: 400px; width: 90%; text-align: center; box-shadow: 0 0 20px rgba(255,0,0,0.2);">
                    <h3 style="color: #ff4444; margin-top:0; font-family:'Share Tech Mono',monospace;">
                        <?= __('Delete Account') ?></h3>
                    <p style="margin-bottom: 1.25rem;"><?= __('Delete your account permanently? Enter your password to confirm.') ?></p>

                    <input type="password" id="delete-confirm-password" placeholder="<?= __('Password') ?>"
                        style="width: 100%; padding: 10px; margin-bottom: 1rem; background: #000; border: 1px solid #444; color: #fff; border-radius:4px;">
                    <div id="delete-error"
                        style="color: #ff4444; display: none; margin-bottom: 1rem; font-weight:bold;"></div>

                    <div style="display: flex; gap: 10px; justify-content: center;">
                        <button onclick="closeDeleteModal()" class="dl-btn"
                            style="background: rgba(255,255,255,0.1); border-color: #555;"><?= __('Cancel') ?></button>
                        <button onclick="confirmDelete()" id="btn-confirm-delete" class="dl-btn"
                            style="background: rgba(255,0,0,0.1); border-color: #ff4444; color: #ff4444;"><?= __('Confirm Delete') ?></button>
                    </div>
                </div>
            </div>

            <!-- Prompt Link Email on Login Modal -->
            <div id="prompt-email-modal"
                style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:9999; justify-content:center; align-items:center;">
                <div
                    style="background: #181818; padding: 2rem; border-radius: 8px; border: 1px solid #ffaa00; max-width: 460px; width: 92%; box-shadow: 0 0 30px rgba(255, 170, 0, 0.25);">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px; border-bottom:1px solid rgba(255,170,0,0.25); padding-bottom:10px;">
                        <i class="fas fa-shield-alt animate-pulse" style="color: #ffaa00; font-size:1.5rem;"></i>
                        <h3 style="color: #ffaa00; margin:0; font-family:'Share Tech Mono',monospace;">
                            <?= __('Set Recovery Email') ?>
                        </h3>
                    </div>

                    <p style="font-size:0.85rem; color:#ccc; line-height:1.5; margin-bottom:1.1rem;">
                        <?= __('Add an email for password resets. We will send a verification link.') ?>
                    </p>

                    <label for="pem-email-input" style="font-size:0.8rem; color:#ccc; display:block; margin-bottom:5px; font-family:'Share Tech Mono',monospace;">
                        <?= __('Your Email Address') ?>
                    </label>
                    <input type="email" id="pem-email-input" placeholder="<?= __('hunter@example.com') ?>" maxlength="100"
                        style="width: 100%; padding: 10px; background: #000; border: 1px solid #555; color: #fff; border-radius:4px; box-sizing:border-box; font-family:'Share Tech Mono',monospace; font-size:0.95rem;">

                    <div id="pem-error" style="color: #ff4444; display: none; margin-top: 10px; font-size:0.85rem; font-weight:bold;"></div>
                    <div id="pem-success" style="color: #00C851; display: none; margin-top: 10px; font-size:0.85rem; font-weight:bold;"></div>

                    <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1.5rem;">
                        <button type="button" onclick="closePromptEmailModal(true)" class="dl-btn"
                            style="background: rgba(255,255,255,0.08); border-color: #555; color:#aaa; font-size:0.85rem;"><?= __('Later') ?></button>
                        <button type="button" onclick="confirmPromptEmail()" id="btn-confirm-pem" class="dl-btn"
                            style="background: rgba(255, 170, 0, 0.2); border-color: #ffaa00; color: #ffaa00; font-size:0.85rem; font-weight:bold;"><i class="fas fa-paper-plane"></i> <?= __('Send Link') ?></button>
                    </div>
                </div>
            </div>

            <!-- Player Guide Modal -->
            <div id="player-guide-modal">
                <div class="guide-modal-dialog">
                    <button onclick="closePlayerGuideModal()"
                        style="position: absolute; top: 15px; right: 20px; background: transparent; border: none; color: #00ffff; font-size: 1.5rem; cursor: pointer; transition: all 0.2s; z-index: 10;"><i
                            class="fas fa-times"></i></button>

                    <h2
                        style="color: #00ffff; margin-top:0; font-family: 'Share Tech Mono', monospace; display: flex; align-items: center; gap: 10px; border-bottom: 2px solid rgba(0, 255, 255, 0.2); padding-bottom: 10px; margin-bottom: 1rem;">
                        <i class="fas fa-terminal animate-pulse"></i>
                        <?= htmlspecialchars(get_server_name()) ?> — <?= __('GUIDE') ?>
                    </h2>

                    <div
                        style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 1rem; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                        <button class="guide-tab-btn active" onclick="switchGuideTab('tab-portal')"
                            data-tab="tab-portal"><?= __('PORTAL') ?></button>
                        <?php if (is_feature_enabled('lfg')): ?>
                        <button class="guide-tab-btn" onclick="switchGuideTab('tab-lfg')"
                            data-tab="tab-lfg"><?= __('LFG') ?></button>
                        <?php endif; ?>
                        <button class="guide-tab-btn" onclick="switchGuideTab('tab-drops')"
                            data-tab="tab-drops"><?= __('DROPS') ?></button>
                        <button class="guide-tab-btn" onclick="switchGuideTab('tab-commands')"
                            data-tab="tab-commands"><?= __('COMMANDS') ?></button>
                    </div>

                    <div id="guide-modal-content" style="flex: 1; overflow-y: auto; padding-right: 6px;">
                        <div id="tab-portal" class="guide-tab-pane">
                            <div class="guide-section-grid">
                                <div class="guide-card-glass">
                                    <h3><i class="fas fa-university"></i> <?= __('Bank & Section ID') ?></h3>
                                    <ul class="guide-list">
                                        <li><?= __('Switch between Shared and Character banks.') ?></li>
                                        <li><?= __('Must be online (not at bank, Battle, or Challenge).') ?></li>
                                        <li><?= __('Change Section ID up to Lv 50.') ?></li>
                                    </ul>
                                </div>
                                <div class="guide-card-glass">
                                    <h3><i class="fas fa-users-cog"></i> <?= __('Account & Rewards') ?></h3>
                                    <ul class="guide-list">
                                        <li><?= __('Set a 2-20 char leaderboard alias.') ?></li>
                                        <?php if (is_feature_enabled('discord_oauth')): ?>
                                        <li><?= __('Link Discord for login and alerts.') ?></li>
                                        <?php endif; ?>
                                        <li><?= __('Claim gifts at level milestones.') ?></li>
                                        <?php if (is_feature_enabled('bounties')): ?>
                                        <li><?= __('Complete bounties & events in-game; claim here.') ?></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <?php if (is_feature_enabled('lfg')): ?>
                        <div id="tab-lfg" class="guide-tab-pane" style="display:none;">
                            <div class="guide-card-glass">
                                <h3><i class="fas fa-satellite-dish"></i> <?= __('LFG & Direct Warp') ?></h3>
                                <ul class="guide-list">
                                    <li><?= __('Auto-syncs your online character and room.') ?></li>
                                    <li><?= __('Post your goal, needed classes, or active bounty.') ?></li>
                                    <li><?= __('Warp Direct joins an open room.') ?></li>
                                    <li><?= __('Leave Group returns to lobby.') ?></li>
                                </ul>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div id="tab-drops" class="guide-tab-pane" style="display:none;">
                            <div class="guide-card-glass">
                                <h3><i class="fas fa-search"></i> <?= __('Drop Charts') ?></h3>
                                <ul class="guide-list">
                                    <li><?= __('Live rates from server drop tables.') ?></li>
                                    <li><?= __('Filter by Episode, Difficulty, class, item, or enemy.') ?></li>
                                    <li><?= __('Drops follow the Room Creator\'s Section ID.') ?></li>
                                </ul>
                            </div>
                        </div>

                        <div id="tab-commands" class="guide-tab-pane" style="display:none;">
                            <div class="guide-card-glass">
                                <h3><i class="fas fa-terminal"></i> <?= __('In-Game Commands') ?></h3>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>ping</span>
                                    <span class="command-desc"><?= __('Server latency') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>li</span>
                                    <span class="command-desc"><?= __('Lobby info') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>si</span>
                                    <span class="command-desc"><?= __('Server & player count') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>where</span>
                                    <span class="command-desc"><?= __('Floor coordinates') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>what</span>
                                    <span class="command-desc"><?= __('Nearest floor item') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>arrow [color]</span>
                                    <span class="command-desc"><?= __('Lobby arrow color') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>song [id]</span>
                                    <span class="command-desc"><?= __('Lobby music') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>announcerares</span>
                                    <span class="command-desc"><?= __('Toggle rare alerts') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>bank [slot]</span>
                                    <span class="command-desc"><?= htmlspecialchars(sprintf(__('Swap bank (%sbank 0 = Shared)'), $rawCmdPrefix)) ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>save</span>
                                    <span class="command-desc"><?= __('Save character') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>checkchar</span>
                                    <span class="command-desc"><?= __('Character slots') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>matcount</span>
                                    <span class="command-desc"><?= __('Material usage') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name"><?= $cmdPrefix ?>killcount</span>
                                    <span class="command-desc"><?= __('Sealed weapon kills') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        style="margin-top: 0.75rem; border-top: 1px solid rgba(0, 255, 255, 0.2); padding-top: 10px; display: flex; justify-content: flex-end; align-items: center;">
                        <button type="button" onclick="closePlayerGuideModal()" class="dl-btn"
                            style="padding: 5px 14px; font-size: 0.75rem; border-color: rgba(0, 255, 255, 0.5); font-weight: bold; background: rgba(0,255,255,0.05); color: #00ffff;"><?= __('CLOSE') ?></button>
                    </div>
                </div>
            </div>
