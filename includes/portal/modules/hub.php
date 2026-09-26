<?php
/**
 * PSOBB Portal Module: Hub & Overview
 */
?>
                <!-- Legacy Account Email Alert Banner -->
                <div id="legacy-email-banner" style="display:none; margin-bottom: 1.5rem; background: linear-gradient(135deg, rgba(255, 170, 0, 0.15) 0%, rgba(255, 68, 68, 0.08) 100%); border: 1px solid rgba(255, 170, 0, 0.5); border-radius: 8px; padding: 1rem 1.25rem; box-shadow: 0 0 15px rgba(255, 170, 0, 0.1);" class="animate-fade-in">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap: 15px; flex-wrap: wrap;">
                        <div style="display:flex; align-items:center; gap: 12px;">
                            <i class="fas fa-exclamation-triangle" style="color: #ffaa00; font-size: 1.5rem;"></i>
                            <div>
                                <strong style="color: #ffaa00; display:block; font-family:'Share Tech Mono', monospace; font-size: 1rem;"><?= __('Account Security: No Recovery Email Linked') ?></strong>
                                <span style="font-size: 0.85rem; color: #ddd;"><?= __('Your game account does not have a recovery email. Link an email to enable password recovery if you ever forget your password.') ?></span>
                            </div>
                        </div>
                        <button type="button" onclick="switchDashboardTab('tab-settings'); focusEmailInput();" class="dl-btn" style="padding: 6px 16px; border-color: #ffaa00; background: rgba(255,170,0,0.2); color: #ffaa00; font-size: 0.85rem; white-space: nowrap;"><i class="fas fa-link"></i> <?= __('Link Email Now') ?></button>
                    </div>
                </div>

                <div class="dashboard-content-grid"
                    style="display: grid; grid-template-columns: minmax(320px, 1fr) 1.5fr; gap: 1.5rem;">
                    <!-- Left side: Hunter's License Card & PWA card -->
                    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                        <div class="hunters-license-card animate-float">
                            <div class="hl-header">
                                <div class="hl-chip"></div>
                                <div class="hl-title"><?= __('HUNTER\'S LICENSE') ?></div>
                            </div>
                            <div class="hl-body">
                                <div class="hl-row">
                                    <span class="hl-label"><?= __('NAME') ?></span>
                                    <span class="hl-value" id="dash-username">--</span>
                                </div>
                                <div class="hl-row"
                                    style="border-top: 1px dashed rgba(0, 255, 255, 0.3); padding-top: 10px;">
                                    <span class="hl-label"><?= __('GUILD CARD ID') ?></span>
                                    <span class="hl-value" id="dash-account-id"
                                        style="font-family: monospace; letter-spacing: 2px;">--</span>
                                </div>
                                <div class="hl-row">
                                    <span class="hl-label"><?= __('TEAM') ?></span>
                                    <span class="hl-value" id="dash-team">--</span>
                                </div>
                                <div class="hl-row"
                                    style="border-top: 1px dashed rgba(0, 255, 255, 0.3); padding-top: 10px;">
                                    <span class="hl-label"><?= __('TIME PLAYED') ?></span>
                                    <span class="hl-value" id="dash-playtime">--</span>
                                </div>
                            </div>
                            <div class="hl-footer">
                                <span class="hl-status"><?= __('STATUS: ACTIVE') ?></span>
                                <img src="img/favicon.svg" class="hl-logo-sm" alt="logo">
                            </div>
                        </div>

                        <!-- Special Delivery Widget (hidden until pending deliveries exist) -->
                        <div id="special-delivery-widget" style="display:none;"
                             class="animate-fade-in">
                            <div style="background: linear-gradient(135deg, rgba(251,146,60,.12) 0%, rgba(249,115,22,.06) 100%);
                                        border: 1px solid rgba(251,146,60,.4);
                                        border-radius: 12px; padding: 1.25rem;
                                        box-shadow: 0 0 20px rgba(251,146,60,.08);">
                                <h3 style="color:#fdba74; font-family:'Share Tech Mono',monospace;
                                           margin:0 0 .75rem; font-size:.95rem;
                                           display:flex; align-items:center; gap:.5rem;
                                           border-bottom:1px solid rgba(251,146,60,.2); padding-bottom:.6rem;">
                                    <i class="fas fa-gift" style="animation: pulse 2s infinite;"></i>
                                    <?= __('Special Delivery') ?>
                                </h3>
                                <div id="special-delivery-list"></div>
                            </div>
                        </div>

                        <!-- PWA Installation Card -->
                        <div id="pwa-install-card" class="pwa-install-card" style="display: none;">
                            <h3
                                style="color:#00ffff; font-family:'Share Tech Mono',monospace; margin-top:0; margin-bottom:10px;">
                                <i class="fas fa-mobile-alt animate-pulse"></i> <?= __('Companion App Available') ?>
                            </h3>
                            <p style="font-size:0.85rem; color:rgba(255,255,255,0.7); margin-bottom:15px;">
                                <?= __('Install the PSOBB.io Companion App directly on your mobile screen or desktop for instant access!') ?>
                            </p>
                            <div id="pwa-install-android" style="display:none;">
                                <button onclick="installPortalApp()" class="dl-btn pwa-install-btn"><i
                                        class="fas fa-download"></i>
                                    <?= __('Install PSOBB.io Companion App') ?></button>
                            </div>
                            <div id="pwa-install-ios" style="display:none;">
                                <p style="font-size:0.8rem; color:#ffaa00; margin:0; line-height:1.6;">
                                    <i class="fas fa-arrow-up"></i>
                                    <?= __('Tap the <strong>Share</strong> button (box with arrow) in Safari, then tap <strong>"Add to Home Screen"</strong>.') ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Right side: Server stats and LFG Quick Warp -->
                    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                        <div
                            style="border: 1px solid rgba(0, 255, 255, 0.2); background: rgba(0, 255, 255, 0.05); padding: 1.5rem; border-radius: 8px;">
                            <h3
                                style="color:#00ffff; font-family:'Share Tech Mono',monospace; margin-top:0; border-bottom:1px solid rgba(0,255,255,0.2); padding-bottom:8px; margin-bottom:12px;">
                                <i class="fas fa-satellite-dish animate-pulse"></i> <?= __('Server Telemetry') ?></h3>
                            <div class="server-telemetry-stats"
                                style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div
                                    style="background:rgba(0,0,0,0.4); padding:10px; border-radius:4px; border:1px solid rgba(255,255,255,0.05);">
                                    <span
                                        style="font-size:0.75rem; color:#aaa; display:block;"><?= __('EXP MULTIPLIER') ?></span>
                                    <span id="rate-exp"
                                        style="font-size:1.5rem; font-family:'Share Tech Mono',monospace; color:#ffaa00; font-weight:bold;">1.0x</span>
                                </div>
                                <div
                                    style="background:rgba(0,0,0,0.4); padding:10px; border-radius:4px; border:1px solid rgba(255,255,255,0.05);">
                                    <span
                                        style="font-size:0.75rem; color:#aaa; display:block;"><?= __('DROP MULTIPLIER') ?></span>
                                    <span id="rate-drop"
                                        style="font-size:1.5rem; font-family:'Share Tech Mono',monospace; color:#00ffc8; font-weight:bold;">1.0x</span>
                                </div>
                                <div
                                    style="background:rgba(0,0,0,0.4); padding:10px; border-radius:4px; border:1px solid rgba(255,255,255,0.05);">
                                    <span
                                        style="font-size:0.75rem; color:#aaa; display:block;"><?= __('PLAYERS ONLINE') ?></span>
                                    <span id="client-count"
                                        style="font-size:1.5rem; font-family:'Share Tech Mono',monospace; color:#fff; font-weight:bold;">0</span>
                                </div>
                                <div
                                    style="background:rgba(0,0,0,0.4); padding:10px; border-radius:4px; border:1px solid rgba(255,255,255,0.05);">
                                    <span
                                        style="font-size:0.75rem; color:#aaa; display:block;"><?= __('ACTIVE ROOMS') ?></span>
                                    <span id="game-count"
                                        style="font-size:1.5rem; font-family:'Share Tech Mono',monospace; color:#fff; font-weight:bold;">0</span>
                                </div>
                            </div>
                        </div>

                        <!-- LFG Quick Warp / Group Card -->
                        <div
                            style="border: 1px solid rgba(255, 170, 0, 0.2); background: rgba(255, 170, 0, 0.05); padding: 1.5rem; border-radius: 8px;">
                            <h3
                                style="color:#ffaa00; font-family:'Share Tech Mono',monospace; margin-top:0; border-bottom:1px solid rgba(255,170,0,0.2); padding-bottom:8px; margin-bottom:12px;">
                                <i class="fas fa-users"></i> <?= __('LFG Terminal & Group Warp') ?></h3>
                            <p style="font-size:0.85rem; color:rgba(255,255,255,0.7); margin-bottom:15px;">
                                <?= __('Coordinate with other players and join active party rooms in-game instantly with Direct Warp capabilities.') ?>
                            </p>
                            <div style="display:flex; gap:10px;">
                                <a href="lfg.php" class="dl-btn"
                                    style="flex:1; text-align:center; text-decoration:none; border-color: #ffaa00; background: rgba(255, 170, 0, 0.15); color: #ffaa00; font-weight: bold; font-family: 'Share Tech Mono', monospace; font-size:0.9rem; padding:10px;">
                                    <i class="fas fa-satellite"></i> <?= __('Open LFG Terminal') ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
