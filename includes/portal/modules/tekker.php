<?php
/**
 * PSOBB Portal Module: Tekker Store Mainframe
 */
?>
                <style>
                    /* Cyberpunk Tekker Store Style Sheet */
                    .tekker-mainframe {
                        position: relative;
                        border: 1px solid rgba(0, 255, 255, 0.3) !important;
                        background: radial-gradient(circle at 50% 50%, rgba(0, 20, 40, 0.8), rgba(0, 5, 10, 0.95)) !important;
                        border-radius: 12px;
                        padding: 2rem !important;
                        box-shadow: 0 0 25px rgba(0, 255, 255, 0.15), inset 0 0 15px rgba(0, 255, 255, 0.05);
                        overflow: hidden;
                    }

                    /* Corner decors */
                    .tekker-mainframe::before, .tekker-mainframe::after {
                        content: '';
                        position: absolute;
                        width: 15px;
                        height: 15px;
                        border-color: #00ffff;
                        border-style: solid;
                        pointer-events: none;
                    }
                    .tekker-mainframe::before {
                        top: 10px;
                        left: 10px;
                        border-width: 2px 0 0 2px;
                    }
                    .tekker-mainframe::after {
                        bottom: 10px;
                        right: 10px;
                        border-width: 0 2px 2px 0;
                    }

                    /* Scanning Line Effect */
                    .tekker-scanner-line {
                        position: absolute;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 4px;
                        background: linear-gradient(90deg, transparent, rgba(0, 255, 255, 0.5), rgba(0, 255, 255, 0.8), rgba(0, 255, 255, 0.5), transparent);
                        box-shadow: 0 0 10px rgba(0, 255, 255, 0.8);
                        opacity: 0.7;
                        z-index: 10;
                        pointer-events: none;
                        animation: tekker-scan 4s linear infinite;
                    }

                    @keyframes tekker-scan {
                        0% { top: -2%; }
                        50% { top: 102%; }
                        100% { top: -2%; }
                    }

                    /* Neon Glow Headers */
                    .tekker-neon-glow {
                        font-family: 'Share Tech Mono', monospace;
                        font-weight: bold;
                        color: #00ffff !important;
                        text-shadow: 0 0 8px rgba(0, 255, 255, 0.8), 0 0 20px rgba(0, 255, 255, 0.3) !important;
                        border-bottom: 2px solid rgba(0, 255, 255, 0.4) !important;
                        padding-bottom: 12px;
                        margin-bottom: 15px;
                        letter-spacing: 2px;
                    }

                    /* Token Cards */
                    .tekker-card-premium {
                        background: rgba(0, 15, 30, 0.6) !important;
                        border: 1px solid rgba(0, 255, 255, 0.2) !important;
                        border-radius: 8px;
                        padding: 1.25rem;
                        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
                        position: relative;
                        cursor: pointer;
                        overflow: hidden;
                    }

                    .tekker-card-premium::before {
                        content: '';
                        position: absolute;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: linear-gradient(135deg, rgba(0, 255, 255, 0.05) 0%, transparent 100%);
                        opacity: 0;
                        transition: opacity 0.3s ease;
                        pointer-events: none;
                    }

                    .tekker-card-premium:hover {
                        transform: translateY(-3px) scale(1.01);
                        border-color: rgba(0, 255, 255, 0.6) !important;
                        box-shadow: 0 5px 15px rgba(0, 255, 255, 0.15);
                    }

                    .tekker-card-premium:hover::before {
                        opacity: 1;
                    }

                    .tekker-card-premium.active-selected {
                        background: rgba(0, 255, 255, 0.08) !important;
                        border-color: #00ffff !important;
                        box-shadow: 0 0 20px rgba(0, 255, 255, 0.25), inset 0 0 10px rgba(0, 255, 255, 0.1) !important;
                    }

                    /* Stat badges */
                    .tekker-stat-badge {
                        display: inline-flex;
                        align-items: center;
                        padding: 3px 8px;
                        border-radius: 4px;
                        font-size: 0.75rem;
                        font-weight: bold;
                        font-family: 'Share Tech Mono', monospace;
                        margin: 2px 4px;
                        background: rgba(255, 255, 255, 0.03);
                        border: 1px solid rgba(255, 255, 255, 0.08);
                        color: #555;
                        transition: all 0.2s ease;
                    }

                    .tekker-stat-badge.has-val {
                        background: rgba(255, 170, 0, 0.1);
                        border-color: rgba(255, 170, 0, 0.4);
                        color: #ffaa00;
                        text-shadow: 0 0 4px rgba(255, 170, 0, 0.4);
                    }

                    .tekker-stat-badge.has-hit {
                        background: rgba(0, 255, 200, 0.15);
                        border-color: rgba(0, 255, 200, 0.5);
                        color: #00ffc8;
                        text-shadow: 0 0 5px rgba(0, 255, 200, 0.6);
                        box-shadow: 0 0 8px rgba(0, 255, 200, 0.1);
                    }

                    /* Custom Checkbox Design */
                    .tekker-checkbox-container {
                        display: flex;
                        align-items: center;
                        position: relative;
                        cursor: pointer;
                        user-select: none;
                        margin: 0;
                    }

                    .tekker-checkbox-container input {
                        position: absolute;
                        opacity: 0;
                        cursor: pointer;
                        height: 0;
                        width: 0;
                    }

                    .tekker-checkmark {
                        height: 22px;
                        width: 22px;
                        background-color: rgba(0, 0, 0, 0.5);
                        border: 1px solid rgba(0, 255, 255, 0.4);
                        border-radius: 4px;
                        position: relative;
                        transition: all 0.2s ease;
                        flex-shrink: 0;
                    }

                    .tekker-checkbox-container:hover input ~ .tekker-checkmark {
                        border-color: #00ffff;
                        box-shadow: 0 0 8px rgba(0, 255, 255, 0.4);
                    }

                    .tekker-checkbox-container input:checked ~ .tekker-checkmark {
                        background-color: rgba(0, 255, 255, 0.2);
                        border-color: #00ffff;
                        box-shadow: 0 0 12px rgba(0, 255, 255, 0.6);
                    }

                    .tekker-checkmark:after {
                        content: "";
                        position: absolute;
                        display: none;
                        left: 7px;
                        top: 3px;
                        width: 5px;
                        height: 10px;
                        border: solid #00ffff;
                        border-width: 0 2px 2px 0;
                        transform: rotate(45deg);
                    }

                    .tekker-checkbox-container input:checked ~ .tekker-checkmark:after {
                        display: block;
                    }

                    /* Control Settings Card (Redemption Panel) */
                    .tekker-control-panel {
                        background: linear-gradient(135deg, rgba(0, 255, 255, 0.05), rgba(0, 0, 0, 0.6)) !important;
                        border: 1px solid rgba(0, 255, 255, 0.4) !important;
                        border-radius: 10px;
                        padding: 1.5rem !important;
                        margin-bottom: 2rem !important;
                        box-shadow: 0 0 20px rgba(0, 255, 255, 0.1), inset 0 0 10px rgba(0, 255, 255, 0.05);
                        backdrop-filter: blur(5px);
                        transition: all 0.3s ease;
                    }

                    /* Custom Dropdown Styling */
                    .tekker-select {
                        width: 100%;
                        padding: 10px !important;
                        background: rgba(0, 10, 20, 0.9) !important;
                        border: 1px solid rgba(0, 255, 255, 0.3) !important;
                        color: #fff !important;
                        border-radius: 6px !important;
                        font-family: 'Share Tech Mono', monospace !important;
                        font-size: 0.9rem !important;
                        outline: none;
                        cursor: pointer;
                        transition: all 0.2s ease;
                    }

                    .tekker-select:focus {
                        border-color: #00ffff !important;
                        box-shadow: 0 0 10px rgba(0, 255, 255, 0.3);
                    }

                    .tekker-select option {
                        background: #020813 !important;
                        color: #fff !important;
                    }

                    /* Premium Redeem Button */
                    .tekker-btn-redeem {
                        border: 2px solid #00ffff !important;
                        background: rgba(0, 255, 255, 0.15) !important;
                        color: #00ffff !important;
                        font-family: 'Share Tech Mono', monospace !important;
                        font-weight: bold !important;
                        padding: 12px 30px !important;
                        font-size: 1rem !important;
                        letter-spacing: 1px;
                        text-shadow: 0 0 5px rgba(0, 255, 255, 0.5);
                        box-shadow: 0 0 15px rgba(0, 255, 255, 0.2);
                        cursor: pointer;
                        transition: all 0.3s ease !important;
                        border-radius: 6px;
                    }

                    .tekker-btn-redeem:hover:not(:disabled) {
                        background: rgba(0, 255, 255, 0.3) !important;
                        box-shadow: 0 0 25px rgba(0, 255, 255, 0.5) !important;
                        transform: scale(1.03);
                    }

                    .tekker-btn-redeem:disabled {
                        border-color: rgba(0, 255, 255, 0.15) !important;
                        background: rgba(255, 255, 255, 0.02) !important;
                        color: rgba(255, 255, 255, 0.2) !important;
                        text-shadow: none !important;
                        box-shadow: none !important;
                        cursor: not-allowed;
                    }

                    /* Unlinked Overlay Style */
                    .tekker-overlay-unlinked {
                        position: absolute;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(0, 5, 10, 0.85) !important;
                        backdrop-filter: blur(8px) !important;
                        z-index: 100;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        padding: 2rem;
                        box-sizing: border-box;
                    }

                    .tekker-overlay-content {
                        max-width: 500px;
                        width: 100%;
                        text-align: center;
                        background: rgba(15, 0, 0, 0.75) !important;
                        border: 2px solid #ff4444 !important;
                        border-radius: 12px;
                        padding: 2.5rem !important;
                        box-shadow: 0 0 30px rgba(255, 68, 68, 0.25), inset 0 0 15px rgba(255, 68, 68, 0.1) !important;
                        position: relative;
                        animation: overlay-slide-in 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
                    }

                    .tekker-overlay-content::before {
                        content: '';
                        position: absolute;
                        top: 8px;
                        left: 8px;
                        width: 12px;
                        height: 12px;
                        border-top: 2px solid #ff4444;
                        border-left: 2px solid #ff4444;
                    }

                    .tekker-overlay-content::after {
                        content: '';
                        position: absolute;
                        bottom: 8px;
                        right: 8px;
                        width: 12px;
                        height: 12px;
                        border-bottom: 2px solid #ff4444;
                        border-right: 2px solid #ff4444;
                    }

                    @keyframes overlay-slide-in {
                        from { opacity: 0; transform: scale(0.95); }
                        to { opacity: 1; transform: scale(1); }
                    }

                    .tekker-overlay-btn {
                        border: 2px solid #5865F2 !important;
                        background: rgba(88, 101, 242, 0.15) !important;
                        color: #fff !important;
                        font-family: 'Share Tech Mono', monospace !important;
                        font-weight: bold !important;
                        padding: 12px 25px !important;
                        font-size: 0.95rem !important;
                        letter-spacing: 1px;
                        text-shadow: 0 0 5px rgba(88, 101, 242, 0.5);
                        box-shadow: 0 0 15px rgba(88, 101, 242, 0.2);
                        cursor: pointer;
                        transition: all 0.3s ease !important;
                        border-radius: 6px;
                        margin-top: 1.5rem;
                    }

                    .tekker-overlay-btn:hover {
                        background: #5865F2 !important;
                        box-shadow: 0 0 25px rgba(88, 101, 242, 0.6) !important;
                        transform: scale(1.03);
                    }
                </style>

                <div class="tekker-mainframe">
                    <div class="tekker-scanner-line"></div>
                    
                    <h3 class="tekker-neon-glow">
                        <i class="fas fa-gavel animate-pulse" style="margin-right:8px;"></i><?= __('Discord Tekker Store') ?>
                    </h3>
                    <p style="font-size:0.85rem; color:rgba(255,255,255,0.7); margin-bottom:15px;">
                        <?= __('Redeem your earned Discord Tekker Challenge tokens for rare untekked weapons. Select exactly 3 weapons you want; the server will randomly pick one, inject the token\'s stats, and drop it at your feet in-game!') ?>
                    </p>

                    <!-- Alert message container -->
                    <div id="tekker-status-alert" class="alert-box" style="display: none; margin-bottom: 1rem;"></div>

                    <!-- Token list loader -->
                    <div id="tekker-loader" style="text-align: center; padding: 2rem;">
                        <i class="fas fa-spinner fa-spin fa-2x" style="color: #00ffff;"></i>
                        <p style="margin-top: 10px; font-family: 'Share Tech Mono', monospace; color: #aaa;"><?= __('RETRIEVING TOKENS...') ?></p>
                    </div>

                    <!-- Unlinked Discord State Overlay -->
                    <div id="tekker-unlinked-state" class="tekker-overlay-unlinked" style="display: none;">
                        <div class="tekker-overlay-content">
                            <i class="fab fa-discord fa-4x" style="color: #5865F2; margin-bottom: 1.5rem; filter: drop-shadow(0 0 10px rgba(88,101,242,0.4));"></i>
                            <h4 style="color: #ff4444; margin-top: 0; font-family:'Share Tech Mono', monospace; font-size:1.4rem; letter-spacing:1px; text-shadow:0 0 10px rgba(255,68,68,0.3);"><?= __('Discord Account Not Linked') ?></h4>
                            <p style="font-size: 0.95rem; color: rgba(255,255,255,0.85); margin-bottom: 1.5rem; font-family:'Share Tech Mono', monospace; line-height:1.5;">
                                <?= __('This tab is currently unavailable because your account is not linked to Discord. Link your account to view and claim Tekker Challenge reward tokens.') ?>
                            </p>
                            <button onclick="switchDashboardTab('tab-settings')" class="tekker-overlay-btn">
                                <i class="fab fa-discord"></i> <?= __('Go to Settings & Link Discord') ?>
                            </button>
                        </div>
                    </div>

                    <!-- Linked but empty tokens state -->
                    <div id="tekker-empty-state" style="display: none; text-align: center; padding: 2rem; border: 1px dashed rgba(0, 255, 255, 0.2); background: rgba(0, 255, 255, 0.02); border-radius: 8px;">
                        <i class="fas fa-ticket-alt fa-3x" style="color: rgba(0, 255, 255, 0.4); margin-bottom: 1rem;"></i>
                        <h4 style="color: #aaa; margin-top: 0; font-family:'Share Tech Mono', monospace;"><?= __('No Unclaimed Tokens') ?></h4>
                        <p style="font-size: 0.9rem; color: rgba(255,255,255,0.6); margin-bottom: 0;">
                            <?= __('You have no unclaimed reward tokens. Play the Tekker Challenge minigame on our Discord server to earn some!') ?>
                        </p>
                    </div>

                    <!-- Weapon Selection & Combined Telemetry Card (Visible when at least 1 token checked) -->
                    <div id="tekker-redemption-card" class="tekker-control-panel" style="display: none;">
                        <h4 style="margin-top:0; margin-bottom:12px;">
                            <i class="fas fa-cubes" style="margin-right:6px;"></i><?= __('Redemption Settings') ?>
                        </h4>
                        
                        <div style="font-family:'Share Tech Mono', monospace; font-size:0.9rem; margin-bottom: 15px; border-bottom: 1px dashed rgba(0,255,255,0.2); padding-bottom: 8px; line-height: 1.6;">
                            <div><?= __('Tokens Selected:') ?> <span id="tekker-selected-count" style="color:#00ffff; font-weight:bold;">0</span> / 3</div>
                            <div><?= __('Unlocked Rarity Tier:') ?> <span id="tekker-unlocked-tier" style="color:#00ffc8; font-weight:bold;">-</span></div>
                            <div style="display:flex; align-items:center; flex-wrap:wrap; margin-top:4px;"><?= __('Combined Telemetry Stats:') ?> <span id="tekker-combined-stats" style="margin-left: 6px;">-</span></div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-bottom: 15px;">
                            <div>
                                <label style="font-size:0.75rem; color:#aaa; display:block; margin-bottom:4px;"><?= __('Weapon Choice 1') ?></label>
                                <select id="tekker-weapon-1" class="tekker-select"></select>
                            </div>
                            <div>
                                <label style="font-size:0.75rem; color:#aaa; display:block; margin-bottom:4px;"><?= __('Weapon Choice 2') ?></label>
                                <select id="tekker-weapon-2" class="tekker-select"></select>
                            </div>
                            <div>
                                <label style="font-size:0.75rem; color:#aaa; display:block; margin-bottom:4px;"><?= __('Weapon Choice 3') ?></label>
                                <select id="tekker-weapon-3" class="tekker-select"></select>
                            </div>
                        </div>

                        <div style="display:flex; justify-content:flex-end;">
                            <button id="tekker-claim-btn" onclick="submitTekkerClaim()" class="tekker-btn-redeem">
                                <i class="fas fa-gift"></i> <?= __('Claim Dynamic Drop') ?>
                            </button>
                        </div>
                    </div>

                    <!-- Tokens Container -->
                    <div id="tekker-tokens-container" style="display: none; display: flex; flex-direction: column; gap: 1.5rem;">
                        <!-- Tokens will be injected here via JS -->
                    </div>
                </div>
