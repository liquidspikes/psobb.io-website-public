<?php
/**
 * PSOBB Portal: Shared Modals & Overlays
 */
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
                        <?= __('Select your preferred reward category below. The item will be dropped instantly beside your character in-game!') ?>
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
                    <p><?= __('Are you sure you want to delete your account? This action cannot be undone.') ?></p>
                    <p style="margin-bottom: 1.5rem;"><?= __('Please enter your password to confirm:') ?></p>

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
                            <?= __('Account Security: Set Recovery Email') ?>
                        </h3>
                    </div>

                    <p style="font-size:0.9rem; color:#eee; line-height:1.5; margin-bottom:10px;">
                        <?= __('Your account does not have a recovery email linked yet.') ?>
                    </p>
                    <p style="font-size:0.85rem; color:#aaa; line-height:1.5; margin-bottom:1.25rem; background:rgba(255,170,0,0.08); border-left:3px solid #ffaa00; padding:8px 12px; border-radius:0 4px 4px 0;">
                        <i class="fas fa-info-circle" style="color:#ffaa00; margin-right:4px;"></i>
                        <?= __('Link a real email address so you can recover your password on the Forgot Password page if you ever lose or forget it. A confirmation link will be sent to verify your email.') ?>
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
                            style="background: rgba(255,255,255,0.08); border-color: #555; color:#aaa; font-size:0.85rem;"><?= __('Remind Me Later') ?></button>
                        <button type="button" onclick="confirmPromptEmail()" id="btn-confirm-pem" class="dl-btn"
                            style="background: rgba(255, 170, 0, 0.2); border-color: #ffaa00; color: #ffaa00; font-size:0.85rem; font-weight:bold;"><i class="fas fa-paper-plane"></i> <?= __('Send Confirmation Link') ?></button>
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
                        <?= __('PSOBB HUNTER\'S DATABASE & PORTAL GUIDE') ?>
                    </h2>

                    <div
                        style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 1.5rem; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                        <button class="guide-tab-btn active" onclick="switchGuideTab('tab-portal')"
                            data-tab="tab-portal"><?= __('PORTAL MANAGEMENT') ?></button>
                        <button class="guide-tab-btn" onclick="switchGuideTab('tab-lfg')"
                            data-tab="tab-lfg"><?= __('LFG COORDINATION') ?></button>
                        <button class="guide-tab-btn" onclick="switchGuideTab('tab-drops')"
                            data-tab="tab-drops"><?= __('DYNAMIC DROP CHARTS') ?></button>
                        <button class="guide-tab-btn" onclick="switchGuideTab('tab-commands')"
                            data-tab="tab-commands"><?= __('IN-GAME COMMANDS') ?></button>
                    </div>

                    <div id="guide-modal-content" style="flex: 1; overflow-y: auto; padding-right: 10px;">
                        <div id="tab-portal" class="guide-tab-pane">
                            <div class="guide-section-grid">
                                <div class="guide-card-glass">
                                    <h3><i class="fas fa-university"></i> <?= __('Character & Bank Swapping') ?></h3>
                                    <p><strong><?= __('Bank Management:') ?></strong>
                                        <?= __('Swap your inventory bank container on the fly using the pre-selector dropdown. Switch to the Shared Bank or any character bank (Character 1-20).') ?>
                                    </p>
                                    <p style="color: #ffaa00; font-size: 0.85em; margin-top: 5px;"><i
                                            class="fas fa-exclamation-triangle"></i>
                                        <?= __('Note: Your character must be online in-game but NOT currently standing at the bank counter, and not in Battle or Challenge mode, to successfully swap banks.') ?>
                                    </p>
                                    <p style="margin-top: 10px;"><strong><?= __('Section ID Pre-selector:') ?></strong>
                                        <?= __('Change your drop Section ID pre-selector before launching games. Only characters level 50 and below are permitted to modify their Section ID.') ?>
                                    </p>
                                </div>
                                <div class="guide-card-glass">
                                    <h3><i class="fas fa-users-cog"></i> <?= __('Profile & Account Actions') ?></h3>
                                    <p><strong><?= __('Leaderboard Display Name:') ?></strong>
                                        <?= __('Set a customized alias (2-20 characters) in your profile actions. This alias will represent your hunter on the public leaderboards instead of your account ID.') ?>
                                    </p>
                                    <p style="margin-top: 10px;"><strong><?= __('Discord Integration:') ?></strong>
                                        <?= __('Link your Discord account under Integrations to enable secure instant login, community telemetry sync, and guild notification broadcasts.') ?>
                                    </p>
                                    <p style="margin-top: 10px;"><strong><?= __('Level Milestones:') ?></strong>
                                        <?= __('Check the Level Rewards panel to claim exclusive gifts as your characters reach crucial level milestones on the server!') ?>
                                    </p>
                                </div>
                            </div>

                            <div class="guide-card-glass" style="margin-bottom: 0;">
                                <h3><i class="fas fa-crosshairs"></i> <?= __('Hunter\'s Guild Bounty Board & Events') ?>
                                </h3>
                                <p><strong><?= __('Bounty Board & Personal Quests:') ?></strong>
                                    <?= __('Accept custom-tailored personal bounties from the Hunters Guild Bounty Board. Complete target goals in-game to unlock rare items and meseta. Completed bounties will appear in your Guild Claim Center on the website to claim!') ?>
                                </p>
                                <p style="margin-top: 10px;"><strong><?= __('Cooperative Server Events:') ?></strong>
                                    <?= __('Collaborate server-wide during active community events to pool points. Event rewards feature high-end rare drops tailored to your character\'s class and level at the moment of claiming.') ?>
                                </p>
                                <div
                                    style="background: rgba(255, 170, 0, 0.08); border: 1px solid rgba(255, 170, 0, 0.2); padding: 12px; border-radius: 6px; margin-top: 10px;">
                                    <strong style="color: #ffaa00; display: block; margin-bottom: 6px;"><i
                                            class="fas fa-gift"></i> <?= __('Dynamic Reward Scaling Tiers:') ?></strong>
                                    <ul
                                        style="margin: 0; padding-left: 20px; font-size: 0.85em; line-height: 1.5; color: rgba(255,255,255,0.95);">
                                        <li><strong><?= __('Base Tier:') ?></strong>
                                            <?= __('1x Class-Fit Rare Drop + 5,000 Meseta (0+ points).') ?></li>
                                        <li><strong><?= __('Escalation:') ?></strong>
                                            <?= __('+1 Rare Drop & +5,000 Meseta for every 50 contribution points.') ?>
                                        </li>
                                        <li><strong><?= __('Ultimate Cap:') ?></strong>
                                            <?= __('Up to a massive 10x Rare Drops + 50,000 Meseta (at 450+ points).') ?>
                                        </li>
                                        <li><strong><?= __('Top 3 Champions:') ?></strong>
                                            <?= __('The top 3 event contributors receive a grand 100,000 Meseta prize and a prestigious choice of bonus rare items!') ?>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div id="tab-lfg" class="guide-tab-pane" style="display:none;">
                            <div class="guide-section-grid">
                                <div class="guide-card-glass">
                                    <h3><i class="fas fa-satellite-dish"></i> <?= __('LFG Creation & Syncing') ?></h3>
                                    <p><strong><?= __('Live Character Syncing:') ?></strong>
                                        <?= __('The LFG Terminal synchronizes with the server in real-time, displaying your current online status, active character class, level, and active game lobby ID.') ?>
                                    </p>
                                    <p style="margin-top: 10px;"><strong><?= __('Creating LFG Posts:') ?></strong>
                                        <?= __('Define the mission or objectives you are pursuing. Select which class archetypes you seek (Hunters HU, Rangers RA, Forces FO), and link one of your active Bounty Board quests so others know what you are hunting!') ?>
                                    </p>
                                </div>
                                <div class="guide-card-glass">
                                    <h3><i class="fas fa-space-shuttle"></i> <?= __('Teleportation & Group Controls') ?>
                                    </h3>
                                    <p><strong><?= __('Warp Direct Teleportation:') ?></strong>
                                        <?= __('Find a group seeking your character class? If your level meets the room requirement, click the glowing cyan') ?>
                                        <strong style="color: var(--pso-blue);"><i class="fas fa-rocket"></i>
                                            <?= __('Warp Direct') ?></strong>
                                        <?= __('button on the LFG dashboard. The game server will instantly transition your active character directly into their room in-game!') ?>
                                    </p>
                                    <p style="margin-top: 10px;"><strong><?= __('Leaving a Group:') ?></strong>
                                        <?= __('Need to return to public lobbies? Simply click the') ?> <strong
                                            style="color: #ff4444;"><i class="fas fa-sign-out-alt"></i>
                                            <?= __('Leave Group') ?></strong>
                                        <?= __('button to warp your active character back to public Pioneer 2 lobbies gracefully.') ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div id="tab-drops" class="guide-tab-pane" style="display:none;">
                            <div class="guide-section-grid">
                                <div class="guide-card-glass">
                                    <h3><i class="fas fa-search"></i> <?= __('Search, Filter & Sort') ?></h3>
                                    <p><strong><?= __('Live Server Synchronization:') ?></strong>
                                        <?= __('Our drop database fetches rates directly from active server game data files. If multipliers change or drops are updated, rates in the chart adjust instantly and are 100% accurate.') ?>
                                    </p>
                                    <p style="margin-top: 10px;"><strong><?= __('Dynamic Filters:') ?></strong>
                                        <?= __('Filter drops by Episode (EP1, EP2, EP4) and Difficulty (Normal, Hard, Very Hard, Ultimate).') ?>
                                    </p>
                                    <p style="margin-top: 10px;"><strong><?= __('Target Search:') ?></strong>
                                        <?= __('Search by item names (e.g., Heavenly/Battle) or monster names (e.g., Tollaw) to find exact drop rates.') ?>
                                    </p>
                                </div>
                                <div class="guide-card-glass">
                                    <h3><i class="fas fa-shapes"></i> <?= __('Class Compatibility & Section IDs') ?>
                                    </h3>
                                    <p><strong><?= __('Class Specific Filtering:') ?></strong>
                                        <?= __('Toggle class tags (HUmar, FOnewearl, RAcast, etc.) to view only items usable by your class.') ?>
                                    </p>
                                    <p style="margin-top: 10px;"><strong><?= __('Section ID Mechanics:') ?></strong>
                                        <?= __('In PSOBB, rare drops are determined solely by the Section ID of the') ?>
                                        <strong><?= __('Room Creator') ?></strong>
                                        <?= __('(game leader). Coordinate your party\'s Section ID before generating the game to ensure the monsters drop the items you seek!') ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div id="tab-commands" class="guide-tab-pane" style="display:none;">
                            <div class="guide-card-glass" style="margin-bottom: 1.5rem;">
                                <h3><i class="fas fa-terminal"></i> <?= __('General & Utility Commands') ?></h3>
                                <p style="margin-bottom: 10px; font-size: 0.9em; opacity: 0.8;">
                                    <?= __('Type these commands in the in-game chat to retrieve server telemetry, details, and adjustments:') ?>
                                </p>

                                <div class="command-row">
                                    <span class="command-name">$ping</span>
                                    <span class="command-desc"><?= __('Check your latency to the server.') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name">$li</span>
                                    <span
                                        class="command-desc"><?= __('Display current lobby information and active room details.') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name">$si</span>
                                    <span
                                        class="command-desc"><?= __('Get global server telemetry and active player counts.') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name">$where</span>
                                    <span
                                        class="command-desc"><?= __('Print the exact coordinates of all players on your current floor.') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name">$what</span>
                                    <span
                                        class="command-desc"><?= __('Identify the exact specs and attributes of an item on the floor near you.') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name">$arrow [color]</span>
                                    <span
                                        class="command-desc"><?= __('Change lobby arrow indicator (red, blue, green, yellow, purple, cyan, white, black, etc.).') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name">$song [id]</span>
                                    <span
                                        class="command-desc"><?= __('Change the lobby background jukebox song (lobby only).') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name">$announcerares</span>
                                    <span
                                        class="command-desc"><?= __('Toggles global broadcast announcements when you find rare items.') ?></span>
                                </div>
                            </div>

                            <div class="guide-card-glass">
                                <h3><i class="fas fa-user-shield"></i> <?= __('Character Statistics & CAP Checks') ?>
                                </h3>
                                <p style="margin-bottom: 10px; font-size: 0.9em; opacity: 0.8;">
                                    <?= __('Track materials consumed, force save, swap banks, or count rare weapon kills:') ?>
                                </p>

                                <div class="command-row">
                                    <span class="command-name">$bank [index]</span>
                                    <span
                                        class="command-desc"><?= __('Swap inventory bank on the fly! $bank 0 for Shared Bank, $bank 1-127 for Character Banks.') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name">$save</span>
                                    <span
                                        class="command-desc"><?= __('Force save your character state to the server database.') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name">$checkchar</span>
                                    <span
                                        class="command-desc"><?= __('List character slots on your account, indicating which are used or free.') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name">$matcount</span>
                                    <span
                                        class="command-desc"><?= __('Tally all consumed Stat Materials (Power, Mind, HP, TP, Evade, Def, Luck) and progress toward caps.') ?></span>
                                </div>
                                <div class="command-row">
                                    <span class="command-name">$killcount</span>
                                    <span
                                        class="command-desc"><?= __('View exact monster kill progress for equipped sealed rare weapons (e.g. Sealed J-Sword).') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        style="margin-top: 1rem; border-top: 1px solid rgba(0, 255, 255, 0.2); padding-top: 10px; display: flex; justify-content: space-between; align-items: center; font-size: 0.8em; opacity: 0.7; font-family: 'Share Tech Mono', monospace;">
                        <span><?= __('STATUS: ONLINE // DATABASE SECURE // THANK YOU FOR PLAYING!') ?></span>
                        <button type="button" onclick="closePlayerGuideModal()" class="dl-btn"
                            style="padding: 4px 12px; font-size: 0.75rem; border-color: rgba(0, 255, 255, 0.5); font-weight: bold; background: rgba(0,255,255,0.05); color: #00ffff;"><?= __('CLOSE GUIDE') ?></button>
                    </div>
                </div>
            </div>
