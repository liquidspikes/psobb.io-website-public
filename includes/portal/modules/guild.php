<?php
/**
 * PSOBB Portal Module: Hunters Guild & Milestones
 */
?>
                <!-- Guild status & milestones alerts -->
                <div id="unlocks-status" class="alert-box" style="display: none; margin-bottom: 2rem;"></div>

                <div class="dashboard-grid-guild">
                    <!-- Left side: Milestones and Streaks -->
                    <div>
                        <!-- Daily Streak panel -->
                        <div id="streak-section" style="margin-bottom: 2rem;">
                            <h3 style="margin-top:0; color:#ffaa00; font-family:'Share Tech Mono', monospace;"><i
                                    class="fas fa-fire animate-pulse"
                                    style="color:#ffaa00; margin-right:8px;"></i><?= __('Daily Login Streak') ?></h3>
                            <div class="streak-container"
                                style="background: rgba(0, 10, 20, 0.4); border-color: rgba(255, 170, 0, 0.3);">
                                <div class="streak-info">
                                    <span id="streak-count" class="streak-number">0</span>
                                    <span class="streak-label"><?= __('consecutive days') ?></span>
                                </div>
                                <div class="streak-bar-wrapper">
                                    <div class="streak-bar">
                                        <div id="streak-fill" class="streak-fill" style="width: 0%;"></div>
                                    </div>
                                    <div class="streak-nodes">
                                        <div class="streak-node" data-day="7" data-milestone="7">
                                            <div class="streak-node-dot"></div>
                                            <div class="streak-node-label"><?= __('7 Days') ?></div>
                                            <div class="streak-node-reward"><?= __('Random Mat') ?></div>
                                        </div>
                                        <div class="streak-node" data-day="30" data-milestone="30">
                                            <div class="streak-node-dot"></div>
                                            <div class="streak-node-label"><?= __('30 Days') ?></div>
                                            <div class="streak-node-reward"><?= __('Random Mat') ?></div>
                                        </div>
                                        <div class="streak-node" data-day="90" data-milestone="90">
                                            <div class="streak-node-dot"></div>
                                            <div class="streak-node-label"><?= __('90 Days') ?></div>
                                            <div class="streak-node-reward"><?= __('Random Mat') ?></div>
                                        </div>
                                        <div class="streak-node" data-day="180" data-milestone="180">
                                            <div class="streak-node-dot"></div>
                                            <div class="streak-node-label"><?= __('180 Days') ?></div>
                                            <div class="streak-node-reward"><?= __('Random Mat') ?></div>
                                        </div>
                                        <div class="streak-node" data-day="270" data-milestone="270">
                                            <div class="streak-node-dot"></div>
                                            <div class="streak-node-label"><?= __('270 Days') ?></div>
                                            <div class="streak-node-reward"><?= __('Random Mat') ?></div>
                                        </div>
                                        <div class="streak-node" data-day="365" data-milestone="365">
                                            <div class="streak-node-dot"></div>
                                            <div class="streak-node-label"><?= __('365 Days') ?></div>
                                            <div class="streak-node-reward"><?= __('Yahoo! Mag') ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div id="streak-claims" class="streak-calendar" style="margin-top:1.5rem;"></div>
                            </div>
                        </div>

                        <!-- Level Milestones Board -->
                        <div>
                            <h3 style="color:#00ffff; font-family:'Share Tech Mono', monospace;"><i class="fas fa-gift"
                                    style="color:#00ffff; margin-right:8px;"></i><?= __('Level Milestone Crates') ?>
                            </h3>
                            <div id="character-info" class="server-status-widget"
                                style="display: none; margin-bottom: 1.5rem; padding:15px; border-color:rgba(0,255,255,0.2);">
                                <p style="margin:0; font-size:0.9rem; color:rgba(255,255,255,0.7);">
                                    <?= __('Active Character detected:') ?> <strong id="char-name"
                                        style="color:#fff;">--</strong> (<span id="char-class"
                                        style="color:#00ffff;">--</span>) <?= __('Lv.') ?> <strong id="char-level"
                                        style="color:#ffaa00;">--</strong></p>
                            </div>
                            <div id="milestones-container" class="milestones-grid" style="margin-top: 1rem;">
                                <p id="loading-text" style="color:#aaa; font-family:'Share Tech Mono', monospace;">
                                    <?= __('Synchronizing character rewards...') ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Right side: Daily Claim, Active Bounties, and Completed Rewards -->
                    <div>
                        <!-- Daily Item roll -->
                        <div id="daily-reward-section" style="margin-bottom: 2rem;">
                            <h3 style="margin-top:0; color:#00ffc8; font-family:'Share Tech Mono', monospace;"><i
                                    class="fas fa-dice"
                                    style="color:#00ffc8; margin-right:8px;"></i><?= __('Daily Reward Box') ?></h3>
                            <div class="streak-container"
                                style="background: rgba(0, 10, 20, 0.4); border-color: rgba(0, 255, 200, 0.3);">
                                <p style="color: rgba(255,255,255,0.85); margin-bottom: 0.5rem; font-size:0.85rem;">
                                    <?= __('Claim a free random item every day just for playing!') ?></p>
                                <button id="daily-claim-btn" class="dl-btn"
                                    style="width: 100%; padding: 0.8rem; font-size: 1rem; font-weight: bold; font-family: 'Share Tech Mono', monospace; border: 2px solid #00ff88; background: rgba(0,255,136,0.15); color: #00ff88; cursor: pointer; border-radius: 6px; letter-spacing: 1px; text-shadow: 0 0 8px rgba(0,255,136,0.3);">
                                    🎲 <?= __('Claim Daily Reward') ?>
                                </button>
                                <div id="daily-result"
                                    style="margin-top: 1rem; display: none; text-align: center; color: #00ff88; font-family: 'Share Tech Mono', monospace;">
                                </div>
                            </div>
                        </div>

                        <!-- Community Event Status -->
                        <div id="community-event-section" style="margin-bottom: 2rem; display:none;">
                            <h3 style="color:#ffaa00; font-family:'Share Tech Mono', monospace; margin-top:0;"><i
                                    class="fas fa-globe animate-pulse"
                                    style="color:#ffaa00; margin-right:8px;"></i><?= __('Active Community Event') ?>
                            </h3>
                            <div id="community-event-cards"></div>
                        </div>

                        <!-- Claimable Bounties -->
                        <div id="claimable-bounties-section" style="margin-bottom: 2rem; display:none;">
                            <h3 style="color:#00ff88; font-family:'Share Tech Mono', monospace; margin-top:0;"><i
                                    class="fas fa-trophy"
                                    style="color:#00ff88; margin-right:8px;"></i><?= __('Bounties Ready to Claim') ?>
                            </h3>
                            <div id="claimable-bounties-list"></div>
                        </div>

                        <!-- Active Bounties (in progress) -->
                        <div id="active-bounties-section" style="margin-bottom: 2rem; display:none;">
                            <h3 style="color:#00ffff; font-family:'Share Tech Mono', monospace; margin-top:0;"><i
                                    class="fas fa-crosshairs animate-pulse"
                                    style="color:#00ffff; margin-right:8px;"></i><?= __('Active Bounties') ?></h3>
                            <div id="active-bounties-list"></div>
                        </div>

                        <!-- Bounty board link -->
                        <div
                            style="border: 1px solid rgba(0, 255, 255, 0.2); background: rgba(0, 10, 20, 0.4); padding: 1.5rem; border-radius: 8px; margin-bottom:2rem;">
                            <p
                                style="font-size:0.85rem; color:rgba(255,255,255,0.7); margin-bottom:15px; margin-top:0;">
                                <?= __('Accept unique custom personal bounties from the Hunters Guild Bounty Board to earn rare weapon packages, shield upgrades, and Meseta cash payouts!') ?>
                            </p>

                            <a href="missions.php" class="dl-btn"
                                style="display:block; text-align:center; text-decoration:none; border-color: #00ffff; background: rgba(0, 255, 255, 0.15); color: #00ffff; font-weight: bold; font-family: 'Share Tech Mono', monospace; font-size:0.9rem; padding:10px;">
                                <i class="fas fa-bullseye"></i> <?= __('Open Hunters Guild Board') ?>
                            </a>
                        </div>
                    </div>
                </div>
