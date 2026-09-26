<?php
/**
 * PSOBB Portal Module: Characters & Equipment
 */
$existing_slots = $existing_slots ?? [0];
?>
                <!-- Character Slots selector -->
                <div class="character-slots-bar">
                    <?php foreach ($existing_slots as $idx => $slot): ?>
                        <button class="dl-btn slot-btn<?= $idx === 0 ? ' active' : '' ?>"
                            onclick="switchCharSlot(<?= $slot ?>)"
                            data-slot="<?= $slot ?>"><?= sprintf(__('Character %d'), $slot + 1) ?></button>
                    <?php endforeach; ?>
                </div>

                <div id="viewer-loader" style="text-align: center; padding: 2rem; display: none;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #00ffff;"></i>
                    <p style="margin-top: 10px; font-family: 'Share Tech Mono', monospace; color: #aaa;"><?= __('SYNCHRONIZING TELEMETRY...') ?></p>
                </div>

                <div id="viewer-content-pane">
                    <!-- ===== HERO: Character Identity Card ===== -->
                    <div class="char-hero-card">
                        <div class="char-hero-left">
                            <div class="char-avatar-frame">
                                <img id="char-profile-avatar-fallback" src="" alt="avatar">
                                <div id="char-profile-secid" class="char-secid-badge"></div>
                                <div id="char-profile-online" class="char-online-indicator"></div>
                            </div>
                        </div>
                        <div class="char-hero-right">
                            <h2 id="char-profile-name" class="char-hero-name">Hunter</h2>
                            <div class="char-hero-meta">
                                <span id="char-profile-class" class="char-class-badge">--</span>
                                <span class="char-level-badge">Lv.<span id="char-profile-level">--</span></span>
                                <span class="char-playtime-badge"><i class="fas fa-clock"></i> <span
                                        id="char-profile-playtime">--</span></span>
                            </div>
                            <div class="char-hero-meseta">
                                <i class="fas fa-coins" style="color:#ffaa00;"></i> <span id="char-meseta-val">0</span>
                                <?= __('Meseta') ?>
                            </div>
                        </div>
                    </div>

                    <!-- ===== PAPER DOLL: Equipment + Stats Side-by-Side ===== -->
                    <div class="char-equip-stats-grid">
                        <!-- Left: Paper Doll Equipment -->
                        <div class="paper-doll-panel">
                            <h3 class="panel-header"><i class="fas fa-shield-halved"></i> <?= __('Equipped Gear') ?>
                            </h3>
                            <div class="paper-doll-layout">
                                <div class="pd-row pd-row-top">
                                    <div class="pd-slot" data-slot="mag">
                                        <div class="pd-slot-label"><?= __('MAG') ?></div>
                                        <div class="pd-slot-box" id="pd-slot-mag"></div>
                                    </div>
                                </div>
                                <div class="pd-row pd-row-mid">
                                    <div class="pd-slot" data-slot="weapon">
                                        <div class="pd-slot-label"><?= __('WEAPON') ?></div>
                                        <div class="pd-slot-box" id="pd-slot-weapon"></div>
                                    </div>
                                    <div class="pd-slot pd-slot-center" data-slot="armor">
                                        <div class="pd-slot-label"><?= __('ARMOR') ?></div>
                                        <div class="pd-slot-box pd-armor" id="pd-slot-armor"></div>
                                    </div>
                                    <div class="pd-slot" data-slot="shield">
                                        <div class="pd-slot-label"><?= __('SHIELD') ?></div>
                                        <div class="pd-slot-box" id="pd-slot-shield"></div>
                                    </div>
                                </div>
                                <div class="pd-row pd-row-bot">
                                    <div class="pd-slot" data-slot="unit1">
                                        <div class="pd-slot-label"><?= __('UNIT 1') ?></div>
                                        <div class="pd-slot-box" id="pd-slot-unit1"></div>
                                    </div>
                                    <div class="pd-slot" data-slot="unit2">
                                        <div class="pd-slot-label"><?= __('UNIT 2') ?></div>
                                        <div class="pd-slot-box" id="pd-slot-unit2"></div>
                                    </div>
                                    <div class="pd-slot" data-slot="unit3">
                                        <div class="pd-slot-label"><?= __('UNIT 3') ?></div>
                                        <div class="pd-slot-box" id="pd-slot-unit3"></div>
                                    </div>
                                    <div class="pd-slot" data-slot="unit4">
                                        <div class="pd-slot-label"><?= __('UNIT 4') ?></div>
                                        <div class="pd-slot-box" id="pd-slot-unit4"></div>
                                    </div>
                                </div>
                            </div>
                            <!-- Equipped item names list -->
                            <div id="equipped-item-names" class="equipped-names-list"></div>
                            <!-- MAG Stats Card -->
                            <div id="mag-stats-card" class="mag-stats-card" style="display:none;"></div>
                        </div>

                        <!-- Right: Stats & Materials -->
                        <div class="char-stats-panel">
                            <h3 class="panel-header"><i class="fas fa-chart-bar"></i> <?= __('Combat Stats') ?></h3>
                            <div class="stat-bars-container">
                                <div class="stat-bar-row"><span class="stat-label">ATP</span>
                                    <div class="stat-bar">
                                        <div class="stat-fill stat-atp" id="bar-atp"></div>
                                    </div><span class="stat-value" id="stat-val-atp">--</span>
                                </div>
                                <div class="stat-bar-row"><span class="stat-label">DFP</span>
                                    <div class="stat-bar">
                                        <div class="stat-fill stat-dfp" id="bar-dfp"></div>
                                    </div><span class="stat-value" id="stat-val-dfp">--</span>
                                </div>
                                <div class="stat-bar-row"><span class="stat-label">MST</span>
                                    <div class="stat-bar">
                                        <div class="stat-fill stat-mst" id="bar-mst"></div>
                                    </div><span class="stat-value" id="stat-val-mst">--</span>
                                </div>
                                <div class="stat-bar-row"><span class="stat-label">ATA</span>
                                    <div class="stat-bar">
                                        <div class="stat-fill stat-ata" id="bar-ata"></div>
                                    </div><span class="stat-value" id="stat-val-ata">--</span>
                                </div>
                                <div class="stat-bar-row"><span class="stat-label">EVP</span>
                                    <div class="stat-bar">
                                        <div class="stat-fill stat-evp" id="bar-evp"></div>
                                    </div><span class="stat-value" id="stat-val-evp">--</span>
                                </div>
                                <div class="stat-bar-row"><span class="stat-label">LCK</span>
                                    <div class="stat-bar">
                                        <div class="stat-fill stat-lck" id="bar-lck"></div>
                                    </div><span class="stat-value" id="stat-val-lck">--</span>
                                </div>
                                <div class="stat-bar-row"><span class="stat-label">HP</span>
                                    <div class="stat-bar">
                                        <div class="stat-fill stat-hp" id="bar-hp"></div>
                                    </div><span class="stat-value" id="stat-val-hp">--</span>
                                </div>
                            </div>

                            <!-- Material Gauges (compact) -->
                            <h3 class="panel-header" style="margin-top:1.5rem;"><i class="fas fa-gem"></i>
                                <?= __('Materials Used') ?></h3>
                            <div class="mat-compact-grid">
                                <div class="mat-compact-item">
                                    <div class="mat-icon hp-icon"></div><span class="mat-name"><?= __('HP') ?></span><span
                                        class="mat-val" id="mat-val-hp">0</span><span class="mat-max">/125</span>
                                </div>
                                <div class="mat-compact-item">
                                    <div class="mat-icon tp-icon"></div><span class="mat-name"><?= __('TP') ?></span><span
                                        class="mat-val" id="mat-val-tp">0</span><span class="mat-max">/125</span>
                                </div>
                                <div class="mat-compact-item">
                                    <div class="mat-icon pow-icon"></div><span class="mat-name"><?= __('Power') ?></span><span
                                        class="mat-val" id="mat-val-power">0</span>
                                </div>
                                <div class="mat-compact-item">
                                    <div class="mat-icon mind-icon"></div><span class="mat-name"><?= __('Mind') ?></span><span
                                        class="mat-val" id="mat-val-mind">0</span>
                                </div>
                                <div class="mat-compact-item">
                                    <div class="mat-icon evd-icon"></div><span class="mat-name"><?= __('Evade') ?></span><span
                                        class="mat-val" id="mat-val-evade">0</span>
                                </div>
                                <div class="mat-compact-item">
                                    <div class="mat-icon def-icon"></div><span class="mat-name"><?= __('Def') ?></span><span
                                        class="mat-val" id="mat-val-def">0</span>
                                </div>
                                <div class="mat-compact-item">
                                    <div class="mat-icon lck-icon"></div><span class="mat-name"><?= __('Luck') ?></span><span
                                        class="mat-val" id="mat-val-luck">0</span><span class="mat-max">/45</span>
                                </div>
                            </div>

                            <!-- Material Recalibration (right after Materials Used) -->
                            <details class="danger-details">
                                <summary><i class="fas fa-exclamation-triangle" style="color:#ff4444;"></i>
                                    <?= __('Material Recalibration') ?></summary>
                                <div class="mat-reset-box" style="margin-top:0.5rem;">
                                    <p><?= __('Wipe all consumed materials on this slot and recalculate display stats safely. Requires you to be offline or in a lobby block.') ?>
                                    </p>
                                    <button onclick="triggerMaterialReset()" class="dl-btn mat-reset-btn"
                                        style="width:100%; font-family:'Share Tech Mono',monospace; font-weight:bold;"><i
                                            class="fas fa-trash-restore"></i> <?= __('WIPE ALL MATERIALS') ?></button>
                                    <div id="reset-mat-message"
                                        style="margin-top:10px; font-weight:bold; display:none; font-size:0.85rem;">
                                    </div>
                                </div>
                            </details>

                            <!-- Section ID change -->
                            <div id="section-id-change-container" style="margin-top:1rem;"></div>
                        </div>
                    </div>

                    <!-- ===== BACKPACK INVENTORY (30 slots) ===== -->
                    <div class="inventory-section">
                        <div class="item-grid-title">
                            <span>🎒 <?= __('Backpack Inventory') ?></span>
                            <span style="font-size:0.8rem; color:#aaa; font-family:'Share Tech Mono',monospace;"
                                id="viewer-backpack-count">0 / 30</span>
                        </div>
                        <div class="backpack-grid-box" id="viewer-backpack-grid"></div>
                    </div>
                </div>
