<?php
/**
 * PSOBB Portal Module: Looking For Group (LFG)
 */
?>
                <div
                    style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h3 style="color:#ffaa00; font-family:'Share Tech Mono',monospace; margin:0;"><i
                                class="fas fa-satellite-dish"></i> <?= __('LFG Coordination Feed') ?></h3>
                        <p style="color:rgba(255,255,255,0.5); font-size:0.75rem; margin:4px 0 0;">
                            <?= __('Live postings from online hunters. Warp directly into active parties.') ?></p>
                    </div>
                    <a href="lfg.php" class="dl-btn"
                        style="text-decoration:none; border-color:#ffaa00; color:#ffaa00; background:rgba(255,170,0,0.1); font-size:0.8rem; padding:8px 16px; white-space:nowrap;">
                        <i class="fas fa-plus-circle"></i> <?= __('Create LFG Post') ?>
                    </a>
                </div>
                <div id="lfg-feed-container" style="display:flex; flex-direction:column; gap:0.75rem;">
                    <div style="text-align:center; color:#888; padding:2rem; font-size:0.9rem;">
                        <i class="fas fa-spinner fa-spin"></i> <?= __('Loading LFG feed...') ?>
                    </div>
                </div>
