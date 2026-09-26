<?php
/**
 * PSOBB Portal Module: Bank Vault & Storage Swapping
 */
$existing_slots = $existing_slots ?? [0];
?>
                <div class="character-slots-bar">
                    <?php foreach ($existing_slots as $idx => $slot): ?>
                        <button class="dl-btn slot-btn<?= $idx === 0 ? ' active' : '' ?>"
                            onclick="switchCharSlot(<?= $slot ?>)"
                            data-slot="<?= $slot ?>"><?= sprintf(__('Character %d'), $slot + 1) ?></button>
                    <?php endforeach; ?>
                </div>
                <div
                    style="border: 1px solid rgba(0, 255, 255, 0.15); background: rgba(0, 10, 20, 0.5); padding: 1.5rem; border-radius: 10px;">
                    <div class="item-grid-title" style="flex-wrap:wrap; gap:10px; margin-bottom:1rem;">
                        <span>🏦 <?= __('Bank Vault') ?></span>
                        <span style="font-size:0.9rem; color:#ffaa00; font-family:'Share Tech Mono', monospace;"
                            id="viewer-bank-meseta">0 Meseta</span>
                    </div>
                    <div style="display:flex; gap:10px; margin-bottom:15px; flex-wrap:wrap;">
                        <select id="viewer-bank-select"
                            style="flex:1; min-width:180px; padding: 10px; background: rgba(0,0,0,0.5); color: #fff; border: 1px solid rgba(0,255,255,0.3); border-radius: 4px; font-family:'Share Tech Mono', monospace; box-sizing: border-box;">
                            <?php foreach ($existing_slots as $slot): ?>
                                <option value="<?= $slot ?>"><?= sprintf(__('Slot %d Character Bank'), $slot + 1) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="-1"><?= __('Shared Bank') ?></option>
                        </select>
                        <button id="viewer-btn-swap-bank" onclick="triggerBankSwap()" class="dl-btn"
                            style="border-color:#00ffff; background:rgba(0,255,255,0.1); color:#00ffff; font-family:'Share Tech Mono', monospace; font-weight:bold; padding:10px 20px;"><i
                                class="fas fa-arrows-rotate"></i> <?= __('Swap Bank in Game') ?></button>
                    </div>
                    <div id="bank-swap-result-msg"
                        style="margin-bottom:12px; font-weight:bold; display:none; font-size:0.85rem;"></div>
                    <div style="margin-bottom:15px;">
                        <input type="text" id="viewer-bank-search" placeholder="<?= __('Search bank items...') ?>"
                            style="width:100%; padding:10px; background:rgba(0,0,0,0.5); border:1px solid rgba(0,255,255,0.3); color:#fff; border-radius:4px; font-size:0.9rem; box-sizing:border-box;">
                    </div>
                    <div class="bank-grid-box" id="viewer-bank-grid"></div>
                </div>
