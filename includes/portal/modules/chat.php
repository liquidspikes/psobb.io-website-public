<?php
/**
 * PSOBB Portal Module: Ragol Chat Console
 */
?>
                <div
                    style="border: 1px solid rgba(0, 255, 255, 0.2); background: rgba(0, 10, 20, 0.5); padding: 1.5rem; border-radius: 8px;">
                    <h3
                        style="color:#00ffff; font-family:'Share Tech Mono', monospace; margin-top:0; border-bottom:1px solid rgba(0,255,255,0.2); padding-bottom:8px; margin-bottom:12px;">
                        <i class="fas fa-terminal animate-pulse"
                            style="color:#00ffff; margin-right:8px;"></i><?= __('Web-to-Game Chat Console') ?></h3>
                    <p style="font-size:0.85rem; color:rgba(255,255,255,0.7); margin-bottom:15px;">
                        <?= __('Send chat messages directly to your active in-game character\'s lobby or game block! Highly recommended QoL upgrade for players on Steam Deck or mobile devices.') ?>
                    </p>

                    <div class="chat-messages-log" id="chat-messages-log" style="margin-bottom:15px;">
                        <div class="chat-message-bubble system">
                            <?= __('SYSTEM: Real-time texting console loaded. Log in-game first to broadcast messages.') ?>
                        </div>
                    </div>

                    <div class="chat-input-row" style="flex-direction:column; gap:10px;">
                        <div style="display:flex; gap:10px; align-items:center;">
                            <label
                                style="font-size:0.85rem; color:#aaa; font-family:'Share Tech Mono', monospace; white-space:nowrap;"><?= __('Message From:') ?></label>
                            <select id="chat-character-select"
                                style="flex:1; padding: 8px; background: rgba(0, 0, 0, 0.5); color: #fff; border: 1px solid rgba(0, 255, 255, 0.3); border-radius: 4px; font-family:'Share Tech Mono', monospace;">
                                <!-- Populated via JS characters -->
                                <option value=""><?= __('Select Character') ?></option>
                            </select>
                        </div>

                        <div style="display:flex; gap:10px;">
                            <input type="text" id="chat-message-input"
                                placeholder="<?= __('Type message to game (max 64 chars)...') ?>" maxlength="64"
                                style="flex:1; padding: 10px; background: rgba(0,0,0,0.8); border: 1px solid rgba(0,255,255,0.3); color:#fff; border-radius:4px; font-size:0.95rem;">
                            <button onclick="sendWebToGameMessage()" id="chat-send-btn" class="dl-btn chat-send-btn"><i
                                    class="fas fa-paper-plane"></i> <?= __('Send') ?></button>
                        </div>

                        <div id="chat-status-message"
                            style="display:none; font-weight:bold; font-size:0.85rem; margin-top:5px;"></div>
                    </div>
                </div>
