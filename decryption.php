<?php
$page_title = 'Client Decompilation Matrix - PSOBB Private Server';
$current_page = 'decryption';
include 'includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;600;700;800&family=Rajdhani:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
    /* Scoped Dashboard Styling */
    .decryption-dashboard {
        --panel-bg: linear-gradient(145deg, rgba(10, 15, 32, 0.92), rgba(15, 22, 48, 0.85));
        --panel-border: rgba(0, 237, 255, 0.2);
        --accent-primary: #00EDFF;
        --accent-secondary: #5E69FF;
        --accent-success: #23D160;
        --accent-alert: #FF3366;
        --accent-warning: #FFB020;
        
        font-family: 'Rajdhani', sans-serif;
        color: #f8fafc;
        max-width: 1440px;
        margin: 0 auto;
        padding: 30px 20px 60px 20px;
        display: flex;
        flex-direction: column;
        gap: 24px;
        position: relative;
    }

    /* Sci-Fi Glass Panels with Tech Corner Brackets */
    .decryption-dashboard .glass-panel {
        background: var(--panel-bg);
        backdrop-filter: blur(24px);
        -webkit-backdrop-filter: blur(24px);
        border: 1px solid var(--panel-border);
        border-radius: 8px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.08);
        position: relative;
    }

    .decryption-dashboard .tech-corners::before,
    .decryption-dashboard .tech-corners::after {
        content: '';
        position: absolute;
        width: 12px;
        height: 12px;
        pointer-events: none;
        z-index: 5;
    }
    .decryption-dashboard .tech-corners::before {
        top: -1px;
        left: -1px;
        border-top: 2px solid var(--accent-primary);
        border-left: 2px solid var(--accent-primary);
    }
    .decryption-dashboard .tech-corners::after {
        bottom: -1px;
        right: -1px;
        border-bottom: 2px solid var(--accent-primary);
        border-right: 2px solid var(--accent-primary);
    }

    /* Pioneer 2 Lab HUD Top Bar */
    .hud-top-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        padding: 12px 20px;
        background: linear-gradient(90deg, rgba(0, 237, 255, 0.1), rgba(94, 105, 255, 0.08), rgba(0, 0, 0, 0.4));
        border: 1px solid rgba(0, 237, 255, 0.3);
        border-radius: 6px;
        font-family: 'Share Tech Mono', monospace;
    }

    .hud-pioneer-badge {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.85rem;
        letter-spacing: 2px;
        color: var(--accent-primary);
        text-transform: uppercase;
        font-weight: bold;
    }

    .hud-glow-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background-color: var(--accent-success);
        box-shadow: 0 0 10px var(--accent-success);
        animation: smoothPulse 2s infinite;
    }

    .hud-top-actions {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .hud-beat-clock {
        display: flex;
        align-items: center;
        gap: 8px;
        background: rgba(0, 0, 0, 0.4);
        padding: 4px 12px;
        border-radius: 4px;
        border: 1px solid rgba(0, 237, 255, 0.25);
    }

    .hud-beat-label {
        font-size: 0.75rem;
        color: #94a3b8;
        letter-spacing: 1px;
    }

    .hud-beat-val {
        font-size: 1.1rem;
        font-weight: bold;
        color: #23D160;
        text-shadow: 0 0 10px rgba(35, 209, 96, 0.5);
    }

    .hud-sfx-btn {
        background: rgba(94, 105, 255, 0.15);
        border: 1px solid rgba(94, 105, 255, 0.4);
        color: #fff;
        padding: 5px 14px;
        border-radius: 4px;
        cursor: pointer;
        font-family: 'Rajdhani', sans-serif;
        font-weight: 700;
        font-size: 0.9rem;
        letter-spacing: 1px;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
    }
    .hud-sfx-btn:hover {
        background: var(--accent-secondary);
        color: #fff;
        box-shadow: 0 0 15px rgba(94, 105, 255, 0.5);
    }
    .hud-sfx-btn.active {
        background: rgba(0, 237, 255, 0.2);
        border-color: var(--accent-primary);
        color: var(--accent-primary);
        box-shadow: 0 0 10px rgba(0, 237, 255, 0.3);
    }

    /* Live Cipher / Assembly Stream Marquee */
    .cipher-stream-wrapper {
        display: flex;
        align-items: center;
        background: rgba(4, 8, 22, 0.9);
        border: 1px solid rgba(0, 237, 255, 0.2);
        border-radius: 6px;
        overflow: hidden;
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.85rem;
    }

    .cipher-stream-label {
        background: rgba(0, 237, 255, 0.15);
        color: var(--accent-primary);
        font-weight: bold;
        padding: 6px 14px;
        white-space: nowrap;
        letter-spacing: 1.5px;
        border-right: 1px solid rgba(0, 237, 255, 0.3);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .cipher-stream-marquee {
        flex: 1;
        overflow: hidden;
        white-space: nowrap;
        position: relative;
        padding: 6px 0;
    }

    .cipher-stream-track {
        display: inline-block;
        white-space: nowrap;
        animation: marqueeScroll 45s linear infinite;
        color: #94a3b8;
    }
    .cipher-stream-track span {
        margin: 0 25px;
    }
    .cipher-stream-track .hex-addr { color: #00EDFF; }
    .cipher-stream-track .hex-bytes { color: #5E69FF; }
    .cipher-stream-track .hex-asm { color: #23D160; }

    @keyframes marqueeScroll {
        0% { transform: translateX(0); }
        100% { transform: translateX(-50%); }
    }

    /* Main Progress Highlight Card */
    .decryption-dashboard .highlight-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 40px;
        background: linear-gradient(135deg, rgba(94, 105, 255, 0.22), rgba(0, 237, 255, 0.1));
        border: 1px solid rgba(0, 237, 255, 0.35);
        position: relative;
        overflow: hidden;
    }
    
    .decryption-dashboard .highlight-card::after {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 50%;
        height: 100%;
        background: linear-gradient(to right, transparent, rgba(255,255,255,0.12), transparent);
        transform: skewX(-20deg);
        animation: shine 8s infinite;
    }
    
    @keyframes shine {
        0% { left: -100%; }
        20% { left: 200%; }
        100% { left: 200%; }
    }

    .decryption-dashboard h2 {
        font-family: 'Exo 2', sans-serif;
        font-size: 1.8rem;
        color: #fff;
        text-transform: uppercase;
        letter-spacing: 2px;
        margin-bottom: 10px;
    }

    .decryption-dashboard .circular-progress {
        position: relative;
        width: clamp(180px, 20vw, 250px);
        height: clamp(180px, 20vw, 250px);
        border-radius: 50%;
        background: conic-gradient(var(--accent-primary) calc(var(--percentage) * 1%), rgba(255,255,255,0.05) 0);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 0 35px rgba(0, 237, 255, 0.25), inset 0 0 20px rgba(0, 0, 0, 0.6);
        transition: background 0.5s ease;
    }

    .decryption-dashboard .circular-progress::before {
        content: "";
        position: absolute;
        width: 88%;
        height: 88%;
        background-color: #060B1E;
        border-radius: 50%;
        box-shadow: inset 0 0 25px rgba(0, 237, 255, 0.25);
    }

    .decryption-dashboard .progress-value {
        position: relative;
        font-family: 'Exo 2', sans-serif;
        font-size: clamp(2.5rem, 4vw, 3.5rem);
        font-weight: 700;
        color: #fff;
        text-shadow: 0 0 18px var(--accent-primary);
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .decryption-dashboard .progress-label {
        font-size: clamp(0.9rem, 1.5vw, 1.2rem);
        color: var(--accent-secondary);
        text-transform: uppercase;
        letter-spacing: 2px;
        margin-top: 5px;
    }

    /* Stats Grid */
    .decryption-dashboard .stats-block {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
    }

    .decryption-dashboard .stat-card {
        padding: 24px;
        text-align: center;
        display: flex;
        flex-direction: column;
        gap: 15px;
        transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
        border: 1px solid rgba(0, 237, 255, 0.1);
        background: var(--panel-bg);
    }
    
    .decryption-dashboard .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0, 237, 255, 0.2), inset 0 0 20px rgba(0, 237, 255, 0.08);
        border-color: rgba(0, 237, 255, 0.4);
    }

    .decryption-dashboard .stat-card .label {
        color: #94a3b8;
        font-size: 1rem;
        text-transform: uppercase;
        letter-spacing: 2px;
    }

    .decryption-dashboard .stat-card .value {
        font-size: 3rem;
        font-family: 'JetBrains Mono', monospace;
        color: var(--accent-success);
        text-shadow: 0 0 10px rgba(35, 209, 96, 0.3);
    }

    .decryption-dashboard .stat-card.span-2 {
        grid-column: span 2;
    }

    /* PE Memory Matrix Sector Scanner */
    .memory-matrix-panel {
        padding: 24px;
    }

    .memory-matrix-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 20px;
        border-bottom: 1px solid rgba(0, 237, 255, 0.15);
        padding-bottom: 16px;
    }

    .matrix-legend {
        display: flex;
        gap: 15px;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    .matrix-legend .legend-item {
        display: flex;
        align-items: center;
        gap: 6px;
        color: #94a3b8;
    }
    .matrix-legend .legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 2px;
    }
    .matrix-legend .legend-dot.solved { background: #23D160; box-shadow: 0 0 8px #23D160; }
    .matrix-legend .legend-dot.scanning { background: #00EDFF; box-shadow: 0 0 8px #00EDFF; animation: smoothPulse 1.5s infinite; }
    .matrix-legend .legend-dot.pending { background: rgba(94, 105, 255, 0.3); border: 1px solid rgba(94, 105, 255, 0.6); }

    .memory-sections-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 16px;
    }

    .mem-section-card {
        background: rgba(6, 11, 28, 0.8);
        border: 1px solid rgba(0, 237, 255, 0.15);
        border-radius: 6px;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        position: relative;
        overflow: hidden;
    }

    .mem-section-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--accent-primary);
    }

    .mem-section-head {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
    }
    .mem-section-title {
        font-family: 'Exo 2', sans-serif;
        font-size: 1.05rem;
        font-weight: 700;
        color: #fff;
    }
    .mem-section-range {
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.75rem;
        color: #64748b;
    }

    .mem-blocks-row {
        display: grid;
        grid-template-columns: repeat(16, 1fr);
        gap: 3px;
        position: relative;
        padding: 4px 0;
    }

    .mem-block {
        aspect-ratio: 1;
        background: rgba(94, 105, 255, 0.2);
        border-radius: 2px;
        border: 1px solid rgba(94, 105, 255, 0.3);
        transition: all 0.2s ease;
        position: relative;
    }
    .mem-block.solved {
        background: rgba(35, 209, 96, 0.6);
        border-color: #23D160;
        box-shadow: 0 0 6px rgba(35, 209, 96, 0.4);
    }
    .mem-block.active {
        background: #00EDFF;
        border-color: #fff;
        box-shadow: 0 0 12px #00EDFF;
        animation: blockScan 1.2s infinite alternate;
    }
    @keyframes blockScan {
        0% { transform: scale(1); opacity: 0.8; }
        100% { transform: scale(1.15); opacity: 1; filter: brightness(1.4); }
    }
    .mem-block:hover {
        transform: scale(1.3);
        z-index: 10;
        filter: brightness(1.5);
    }

    .mem-section-foot {
        display: flex;
        justify-content: space-between;
        font-size: 0.85rem;
        color: #94a3b8;
        font-family: 'Share Tech Mono', monospace;
    }

    /* MSVC 7.1 Autonomous Matching Testbench Panel */
    .workbench-panel {
        padding: 24px;
        border: 1px solid rgba(0, 237, 255, 0.3);
        background: linear-gradient(145deg, rgba(10, 15, 38, 0.96), rgba(20, 15, 50, 0.9));
    }

    /* Autonomous Decompilation & Verification Pipeline */
    .decomp-pipeline-container {
        margin: 20px 0;
        background: rgba(4, 7, 20, 0.85);
        border: 1px solid rgba(0, 237, 255, 0.25);
        border-radius: 8px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 16px;
        box-shadow: inset 0 0 30px rgba(0, 237, 255, 0.05);
    }

    .decomp-pipeline-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }

    .pipeline-status-badge {
        display: flex;
        align-items: center;
        gap: 8px;
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.85rem;
        color: var(--accent-primary);
        letter-spacing: 1.5px;
        text-transform: uppercase;
        font-weight: bold;
    }

    .pipeline-pulse-indicator {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--accent-primary);
        box-shadow: 0 0 10px var(--accent-primary);
        animation: smoothPulse 1.5s infinite;
    }

    .pipeline-rate-readout {
        display: flex;
        align-items: baseline;
        gap: 8px;
        font-family: 'JetBrains Mono', monospace;
    }
    .pipeline-rate-val {
        font-size: 1.3rem;
        font-weight: bold;
        color: var(--accent-success);
        text-shadow: 0 0 10px rgba(35, 209, 96, 0.4);
    }
    .pipeline-rate-sub {
        font-size: 0.75rem;
        color: #00EDFF;
        letter-spacing: 1px;
    }

    /* Pipeline Flow Stage Nodes */
    .decomp-pipeline-flow {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        position: relative;
        padding: 10px 0;
        overflow-x: auto;
    }

    .pipeline-stage-node {
        flex: 1;
        min-width: 140px;
        padding: 14px 10px;
        background: rgba(10, 18, 42, 0.9);
        border: 1px solid rgba(0, 237, 255, 0.25);
        border-radius: 6px;
        text-align: center;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
        position: relative;
        transition: all 0.3s ease;
    }
    .pipeline-stage-node:hover {
        border-color: rgba(0, 237, 255, 0.6);
        box-shadow: 0 4px 20px rgba(0, 237, 255, 0.2);
    }
    .pipeline-stage-node.active-stage {
        border-color: #00EDFF;
        box-shadow: 0 0 15px rgba(0, 237, 255, 0.35);
        background: rgba(14, 26, 60, 0.95);
    }
    .pipeline-stage-node.success-stage {
        border-color: rgba(35, 209, 96, 0.5);
    }

    .stage-step-num {
        position: absolute;
        top: 6px;
        left: 8px;
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.65rem;
        color: #64748b;
        font-weight: bold;
    }

    .stage-node-icon {
        font-size: 1.25rem;
        color: #00EDFF;
        margin-bottom: 6px;
    }
    .pipeline-stage-node.success-stage .stage-node-icon {
        color: #23D160;
    }

    .stage-node-title {
        font-family: 'JetBrains Mono', monospace;
        font-weight: bold;
        font-size: 0.8rem;
        color: #fff;
        letter-spacing: 0.5px;
    }

    .stage-node-desc {
        font-size: 0.68rem;
        color: #94a3b8;
        letter-spacing: 0.5px;
        margin-top: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pipeline-flow-connector {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 4px;
        color: rgba(0, 237, 255, 0.4);
        font-size: 0.9rem;
        flex-shrink: 0;
    }

    .pipeline-footer-specs {
        display: flex;
        justify-content: space-around;
        flex-wrap: wrap;
        gap: 15px;
        font-size: 0.85rem;
        color: #94a3b8;
        font-family: 'Share Tech Mono', monospace;
        border-top: 1px solid rgba(255, 255, 255, 0.06);
        padding-top: 12px;
    }
    .pipeline-footer-specs span strong { color: #fff; }

    /* Live Belt Function Inventory & Matching Explorer */
    .function-inventory-panel {
        padding: 24px;
        margin-bottom: 24px;
        background: linear-gradient(145deg, rgba(8, 14, 32, 0.95), rgba(16, 24, 48, 0.9));
        border: 1px solid rgba(0, 237, 255, 0.25);
    }
    .inventory-header-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 16px;
        border-bottom: 1px solid rgba(0, 237, 255, 0.15);
        padding-bottom: 16px;
        margin-bottom: 20px;
    }
    .inventory-controls {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: center;
        margin-bottom: 16px;
    }
    .inventory-search-wrap {
        position: relative;
        flex: 1;
        min-width: 260px;
    }
    .inventory-search-wrap i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
        font-size: 0.9rem;
    }
    .inventory-search-input {
        width: 100%;
        background: rgba(6, 11, 30, 0.8);
        border: 1px solid rgba(0, 237, 255, 0.25);
        border-radius: 4px;
        padding: 8px 12px 8px 34px;
        color: #f8fafc;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.85rem;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .inventory-search-input:focus {
        border-color: #00EDFF;
        box-shadow: 0 0 10px rgba(0, 237, 255, 0.25);
    }
    .inventory-filter-group {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }
    .inventory-filter-btn {
        background: rgba(15, 23, 42, 0.7);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #94a3b8;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 0.78rem;
        font-family: 'JetBrains Mono', monospace;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .inventory-filter-btn:hover {
        border-color: rgba(0, 237, 255, 0.4);
        color: #fff;
    }
    .inventory-filter-btn.active {
        background: rgba(0, 237, 255, 0.15);
        border-color: #00EDFF;
        color: #00EDFF;
        font-weight: bold;
    }
    .inventory-table-container {
        overflow-x: auto;
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 6px;
        background: rgba(4, 7, 20, 0.6);
        max-height: 520px;
        position: relative;
    }
    .inventory-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
        text-align: left;
    }
    .inventory-table th {
        background: rgba(10, 18, 42, 0.95);
        color: #94a3b8;
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.75rem;
        letter-spacing: 1px;
        text-transform: uppercase;
        padding: 10px 14px;
        border-bottom: 1px solid rgba(0, 237, 255, 0.2);
        position: sticky;
        top: 0;
        z-index: 2;
        white-space: nowrap;
    }
    .inventory-table td {
        padding: 10px 14px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        vertical-align: middle;
    }
    .inventory-table tr:hover td {
        background: rgba(0, 237, 255, 0.04);
    }
    .inv-addr {
        font-family: 'JetBrains Mono', monospace;
        color: #00EDFF;
        font-weight: 600;
        letter-spacing: 0.5px;
        white-space: nowrap;
    }
    .inv-name {
        font-family: 'JetBrains Mono', monospace;
        color: #f8fafc;
        font-weight: 500;
    }
    .inv-badge-c {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 0.7rem;
        font-weight: bold;
        background: rgba(35, 209, 96, 0.15);
        color: #23D160;
        border: 1px solid rgba(35, 209, 96, 0.3);
    }
    .inv-badge-cpp {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 0.7rem;
        font-weight: bold;
        background: rgba(0, 237, 255, 0.15);
        color: #00EDFF;
        border: 1px solid rgba(0, 237, 255, 0.3);
    }
    .inv-badge-match {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.72rem;
        font-weight: 600;
        background: rgba(35, 209, 96, 0.15);
        color: #23D160;
        border: 1px solid rgba(35, 209, 96, 0.4);
        white-space: nowrap;
    }
    .inv-inspect-btn {
        background: rgba(94, 105, 255, 0.15);
        border: 1px solid rgba(94, 105, 255, 0.4);
        color: #a5b4fc;
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 0.75rem;
        font-family: 'JetBrains Mono', monospace;
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
    }
    .inv-inspect-btn:hover {
        background: rgba(94, 105, 255, 0.3);
        color: #fff;
        border-color: #5E69FF;
    }
    .inventory-pagination-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 14px;
        font-size: 0.85rem;
        color: #94a3b8;
    }
    .pagination-btn-group {
        display: flex;
        gap: 6px;
        align-items: center;
    }
    .page-nav-btn {
        background: rgba(15, 23, 42, 0.8);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #94a3b8;
        padding: 5px 12px;
        border-radius: 4px;
        font-size: 0.8rem;
        font-family: 'JetBrains Mono', monospace;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .page-nav-btn:hover:not(:disabled) {
        border-color: #00EDFF;
        color: #fff;
    }
    .page-nav-btn:disabled {
        opacity: 0.35;
        cursor: not-allowed;
    }

    /* Function Detail Modal */
    .fn-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(3, 7, 18, 0.85);
        backdrop-filter: blur(8px);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .fn-modal-card {
        background: linear-gradient(145deg, #0a0f28, #121938);
        border: 1px solid rgba(0, 237, 255, 0.4);
        border-radius: 8px;
        max-width: 720px;
        width: 100%;
        box-shadow: 0 0 40px rgba(0, 237, 255, 0.2);
        overflow: hidden;
        animation: modalFadeIn 0.25s ease-out;
    }
    @keyframes modalFadeIn {
        from { opacity: 0; transform: scale(0.96); }
        to { opacity: 1; transform: scale(1); }
    }
    .fn-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        background: rgba(0, 237, 255, 0.08);
        border-bottom: 1px solid rgba(0, 237, 255, 0.2);
    }
    .fn-modal-body {
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 16px;
        max-height: 75vh;
        overflow-y: auto;
    }
    .fn-modal-close {
        background: transparent;
        border: none;
        color: #94a3b8;
        font-size: 1.2rem;
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 4px;
        transition: color 0.2s;
    }
    .fn-modal-close:hover {
        color: #fff;
    }
    .fn-meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 10px;
    }
    .fn-meta-box {
        background: rgba(6, 11, 30, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 4px;
        padding: 10px;
    }
    .fn-meta-box .lbl {
        font-size: 0.72rem;
        color: #94a3b8;
        text-transform: uppercase;
        display: block;
        margin-bottom: 4px;
    }
    .fn-meta-box .val {
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.9rem;
        font-weight: 600;
        color: #f8fafc;
    }
    .fn-code-box {
        background: #040714;
        border: 1px solid rgba(0, 237, 255, 0.2);
        border-radius: 4px;
        padding: 12px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.82rem;
        color: #a5b4fc;
        overflow-x: auto;
        white-space: pre-wrap;
    }
    .fn-desc-box {
        background: rgba(15, 23, 42, 0.5);
        border-left: 3px solid #00EDFF;
        border-radius: 0 4px 4px 0;
        padding: 12px 16px;
        font-size: 0.9rem;
        color: #cbd5e1;
        line-height: 1.5;
    }

    /* Synaptic Neural Frequency Equalizer in Live Cognitive Stream */
    .console-header-bar {
        padding: 16px 24px;
        border-bottom: 1px solid rgba(94, 105, 255, 0.3);
        background: rgba(94, 105, 255, 0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .console-header-left {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .synaptic-eq {
        display: flex;
        align-items: flex-end;
        gap: 3px;
        height: 22px;
        padding: 0 6px;
    }

    .eq-bar {
        width: 4px;
        height: 4px;
        background: var(--accent-primary);
        border-radius: 1px;
        box-shadow: 0 0 6px var(--accent-primary);
        animation: eqPulse 1.4s ease-in-out infinite alternate;
    }

    .synaptic-eq.thinking .eq-bar {
        animation-duration: 0.4s !important;
        background: #23D160;
        box-shadow: 0 0 8px #23D160;
    }

    .eq-bar:nth-child(1) { animation-delay: 0.1s; }
    .eq-bar:nth-child(2) { animation-delay: 0.3s; }
    .eq-bar:nth-child(3) { animation-delay: 0.5s; }
    .eq-bar:nth-child(4) { animation-delay: 0.2s; }
    .eq-bar:nth-child(5) { animation-delay: 0.4s; }
    .eq-bar:nth-child(6) { animation-delay: 0.6s; }
    .eq-bar:nth-child(7) { animation-delay: 0.15s; }
    .eq-bar:nth-child(8) { animation-delay: 0.35s; }
    .eq-bar:nth-child(9) { animation-delay: 0.55s; }
    .eq-bar:nth-child(10) { animation-delay: 0.25s; }
    .eq-bar:nth-child(11) { animation-delay: 0.45s; }
    .eq-bar:nth-child(12) { animation-delay: 0.65s; }
    .eq-bar:nth-child(13) { animation-delay: 0.18s; }
    .eq-bar:nth-child(14) { animation-delay: 0.38s; }
    .eq-bar:nth-child(15) { animation-delay: 0.58s; }
    .eq-bar:nth-child(16) { animation-delay: 0.28s; }
    .eq-bar:nth-child(17) { animation-delay: 0.48s; }
    .eq-bar:nth-child(18) { animation-delay: 0.68s; }
    .eq-bar:nth-child(19) { animation-delay: 0.32s; }
    .eq-bar:nth-child(20) { animation-delay: 0.52s; }

    @keyframes eqPulse {
        0% { height: 4px; opacity: 0.4; }
        50% { height: 16px; opacity: 0.8; }
        100% { height: 22px; opacity: 1; }
    }

    .hud-fs-btn {
        background: transparent;
        border: 1px solid var(--accent-primary);
        color: var(--accent-primary);
        padding: 6px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-family: 'Rajdhani', sans-serif;
        font-size: 1rem;
        font-weight: 700;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
    }
    .hud-fs-btn:hover {
        background: var(--accent-primary);
        color: #000;
        box-shadow: 0 0 15px rgba(0, 237, 255, 0.4);
    }

    /* Terminal & Fullscreen Live Stream Theater Styles */
    .decryption-dashboard .console-wrapper {
        background: #040816;
        border: 1px solid var(--accent-secondary);
        border-radius: 8px;
        transition: all 0.3s ease;
        position: relative;
    }

    .decryption-dashboard .console-wrapper.fullscreen {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        z-index: 99999 !important;
        border-radius: 0 !important;
        background: #030611 !important;
        backdrop-filter: blur(16px);
        display: flex !important;
        flex-direction: column !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
        border: none !important;
    }

    /* Live Stream Theater Top HUD Bar */
    .stream-theater-header {
        background: linear-gradient(180deg, rgba(8, 14, 38, 0.98) 0%, rgba(4, 8, 22, 0.95) 100%);
        border-bottom: 1px solid rgba(0, 237, 255, 0.25);
        padding: 10px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        flex-shrink: 0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.6);
        z-index: 10;
        flex-wrap: wrap;
    }

    .stream-theater-left {
        display: flex;
        align-items: center;
        gap: 15px;
        flex-shrink: 0;
    }

    .stream-badge-live {
        display: flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 51, 102, 0.15);
        border: 1px solid rgba(255, 51, 102, 0.5);
        padding: 4px 12px;
        border-radius: 20px;
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.8rem;
        color: #FF3366;
        letter-spacing: 1.5px;
        font-weight: bold;
        box-shadow: 0 0 10px rgba(255, 51, 102, 0.3);
    }

    .stream-live-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #FF3366;
        animation: streamBlink 1.2s infinite;
    }

    @keyframes streamBlink {
        0%, 100% { opacity: 1; transform: scale(1); box-shadow: 0 0 8px #FF3366; }
        50% { opacity: 0.3; transform: scale(0.8); }
    }

    .stream-target-hud {
        display: flex;
        align-items: center;
        gap: 8px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.85rem;
        background: rgba(0, 0, 0, 0.4);
        border: 1px solid rgba(0, 237, 255, 0.2);
        padding: 4px 12px;
        border-radius: 4px;
    }
    .stream-hud-label { color: #94a3b8; font-size: 0.75rem; letter-spacing: 1px; }
    .stream-target-addr { color: #00EDFF; font-weight: bold; text-shadow: 0 0 8px rgba(0, 237, 255, 0.5); }
    .stream-target-tag {
        background: rgba(94, 105, 255, 0.25);
        color: #5E69FF;
        border: 1px solid rgba(94, 105, 255, 0.4);
        padding: 1px 6px;
        border-radius: 3px;
        font-size: 0.7rem;
        font-weight: bold;
    }

    .stream-theater-center {
        display: flex;
        align-items: center;
        justify-content: center;
        flex: 1;
        min-width: 0;
    }

    .stream-decomp-strip {
        display: flex;
        align-items: center;
        gap: 12px;
        background: rgba(0, 0, 0, 0.5);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 6px;
        padding: 4px 14px;
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.8rem;
    }
    .decomp-mini-item { display: flex; align-items: center; gap: 6px; }
    .decomp-mini-item .c-lbl { color: #64748b; }
    .decomp-mini-item .c-val { color: #00EDFF; font-weight: bold; }
    .decomp-mini-divider { color: rgba(255, 255, 255, 0.15); }

    .stream-theater-right {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }

    .hud-stream-ctrl-btn {
        background: rgba(0, 0, 0, 0.4);
        border: 1px solid rgba(0, 237, 255, 0.3);
        color: #94a3b8;
        padding: 5px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.8rem;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .hud-stream-ctrl-btn.active {
        color: #23D160;
        border-color: #23D160;
        background: rgba(35, 209, 96, 0.1);
        box-shadow: 0 0 8px rgba(35, 209, 96, 0.2);
    }

    /* Live Stream Theater Split Stage */
    .stream-theater-stage {
        display: flex;
        flex: 1;
        min-height: 0;
        width: 100%;
        overflow: hidden;
        background: #040816;
    }

    .console-wrapper:not(.fullscreen) .stream-theater-stage {
        height: 520px;
    }

    .console-wrapper.view-grid-active .stream-theater-stage {
        display: none !important;
    }
    .console-wrapper.view-grid-active .swarm-grid {
        display: grid !important;
    }
    .console-wrapper:not(.view-grid-active) .swarm-grid {
        display: none !important;
    }

    .stream-stage-col {
        display: flex;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
        position: relative;
    }

    .stream-stage-left {
        flex: 56;
        border-right: 1px solid rgba(0, 237, 255, 0.15);
        background: rgba(3, 7, 20, 0.85);
    }

    .stream-stage-right {
        flex: 44;
        background: rgba(4, 10, 26, 0.92);
    }

    .stream-col-header {
        padding: 12px 18px;
        background: rgba(8, 16, 40, 0.85);
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-shrink: 0;
        gap: 10px;
    }

    .col-title-group {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .col-title {
        margin: 0;
        font-family: 'Exo 2', sans-serif;
        font-size: 1rem;
        font-weight: 700;
        letter-spacing: 0.5px;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .col-subtitle {
        font-size: 0.75rem;
        color: #64748b;
        font-family: 'JetBrains Mono', monospace;
    }

    .stream-agent-tabs {
        display: flex;
        gap: 4px;
        background: rgba(0, 0, 0, 0.4);
        padding: 3px;
        border-radius: 4px;
        border: 1px solid rgba(255, 255, 255, 0.06);
    }
    .agent-tab {
        background: transparent;
        border: none;
        color: #94a3b8;
        padding: 3px 8px;
        font-size: 0.7rem;
        font-family: 'Share Tech Mono', monospace;
        border-radius: 3px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .agent-tab:hover { color: #00EDFF; }
    .agent-tab.active {
        background: var(--accent-primary);
        color: #000;
        font-weight: bold;
    }

    .stream-stats-chip {
        display: flex;
        align-items: center;
        gap: 6px;
        background: rgba(35, 209, 96, 0.1);
        border: 1px solid rgba(35, 209, 96, 0.3);
        padding: 3px 10px;
        border-radius: 12px;
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.75rem;
    }
    .stream-stats-chip .chip-label { color: #94a3b8; }
    .stream-stats-chip .chip-val { color: #23D160; font-weight: bold; }

    .stream-feed-body {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        scroll-behavior: smooth;
    }

    /* Custom Stream Scrollbars */
    .stream-feed-body::-webkit-scrollbar { width: 6px; }
    .stream-feed-body::-webkit-scrollbar-track { background: #02050E; }
    .stream-feed-body::-webkit-scrollbar-thumb { background: rgba(0, 237, 255, 0.2); border-radius: 3px; }
    .stream-feed-body::-webkit-scrollbar-thumb:hover { background: var(--accent-primary); }

    /* Left Pane Cards: Cognitive & Decomp */
    .stream-card-thought {
        background: rgba(0, 237, 255, 0.04);
        border-left: 3px solid #00EDFF;
        border-radius: 4px;
        padding: 12px 14px;
        font-family: 'JetBrains Mono', monospace;
        animation: streamFadeIn 0.25s ease-out;
    }
    .stream-card-thought-header {
        display: flex;
        justify-content: space-between;
        font-size: 0.75rem;
        color: #00EDFF;
        margin-bottom: 6px;
        opacity: 0.8;
    }
    .stream-card-thought-content {
        font-size: 0.88rem;
        line-height: 1.5;
        color: #cbd5e1;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .stream-card-tool {
        background: rgba(94, 105, 255, 0.08);
        border-left: 3px solid #5E69FF;
        border-radius: 4px;
        padding: 10px 14px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.85rem;
        animation: streamFadeIn 0.25s ease-out;
    }
    .stream-card-tool-header {
        display: flex;
        justify-content: space-between;
        font-size: 0.75rem;
        color: #5E69FF;
        margin-bottom: 4px;
    }
    .stream-card-tool-args {
        color: #e2e8f0;
        background: rgba(0, 0, 0, 0.3);
        padding: 6px 10px;
        border-radius: 3px;
        margin-top: 4px;
        font-size: 0.82rem;
        word-break: break-all;
    }

    .stream-card-system {
        background: rgba(35, 209, 96, 0.05);
        border-left: 3px solid #23D160;
        border-radius: 4px;
        padding: 10px 14px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.85rem;
        color: #23D160;
        animation: streamFadeIn 0.25s ease-out;
    }

    /* Right Pane Cards: Renaming & Describing */
    .stream-rename-card {
        background: rgba(8, 14, 34, 0.88);
        border: 1px solid rgba(0, 237, 255, 0.2);
        border-left: 4px solid var(--accent-primary);
        border-radius: 6px;
        padding: 14px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        animation: streamSlideInRight 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
        transition: border-color 0.2s, transform 0.2s;
    }
    .stream-rename-card:hover {
        border-color: var(--accent-primary);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 237, 255, 0.15);
    }
    .stream-rename-card.action-proto { border-left-color: #5E69FF; }
    .stream-rename-card.action-type { border-left-color: #23D160; }
    .stream-rename-card.action-struct { border-left-color: #F5A623; }

    .rename-card-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
    }
    .rename-badge {
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.72rem;
        font-weight: bold;
        padding: 2px 8px;
        border-radius: 3px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: rgba(0, 237, 255, 0.15);
        color: #00EDFF;
        border: 1px solid rgba(0, 237, 255, 0.3);
    }
    .rename-badge.action-proto { background: rgba(94, 105, 255, 0.18); color: #5E69FF; border-color: rgba(94, 105, 255, 0.4); }
    .rename-badge.action-type { background: rgba(35, 209, 96, 0.15); color: #23D160; border-color: rgba(35, 209, 96, 0.35); }
    .rename-badge.action-struct { background: rgba(245, 166, 35, 0.15); color: #F5A623; border-color: rgba(245, 166, 35, 0.35); }

    .rename-meta-right {
        display: flex;
        align-items: center;
        gap: 8px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.75rem;
    }
    .rename-addr {
        color: #00EDFF;
        font-weight: bold;
        background: rgba(0, 237, 255, 0.08);
        padding: 1px 6px;
        border-radius: 3px;
    }
    .rename-agent-tag {
        color: #94a3b8;
        background: rgba(255, 255, 255, 0.05);
        padding: 1px 5px;
        border-radius: 3px;
    }
    .rename-time { color: #64748b; }

    .rename-transformation {
        display: flex;
        align-items: center;
        gap: 10px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.95rem;
        background: rgba(0, 0, 0, 0.3);
        padding: 8px 12px;
        border-radius: 4px;
        border: 1px solid rgba(255, 255, 255, 0.05);
    }
    .old-sym {
        color: #94a3b8;
        text-decoration: line-through;
        opacity: 0.7;
    }
    .rename-arrow {
        color: #23D160;
        font-size: 0.9rem;
    }
    .new-sym {
        color: #00EDFF;
        font-weight: bold;
        text-shadow: 0 0 10px rgba(0, 237, 255, 0.4);
    }

    .rename-proto-block {
        background: #02050E;
        border: 1px solid rgba(94, 105, 255, 0.25);
        border-radius: 4px;
        padding: 8px 12px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.85rem;
        color: #cbd5e1;
        overflow-x: auto;
    }
    .rename-proto-block code {
        color: #5E69FF;
        font-family: 'JetBrains Mono', monospace;
    }

    .rename-desc-box {
        background: rgba(35, 209, 96, 0.06);
        border: 1px solid rgba(35, 209, 96, 0.2);
        border-radius: 4px;
        padding: 10px 12px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .desc-box-label {
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.72rem;
        color: #23D160;
        letter-spacing: 1px;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .desc-box-text {
        font-family: 'Exo 2', sans-serif;
        font-size: 0.9rem;
        line-height: 1.5;
        color: #e2e8f0;
    }

    @keyframes streamFadeIn {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @keyframes streamSlideInRight {
        from { opacity: 0; transform: translateX(20px); }
        to { opacity: 1; transform: translateX(0); }
    }

    /* Top Action Button for Live Stream */
    .hud-stream-btn {
        background: linear-gradient(135deg, rgba(0, 237, 255, 0.15) 0%, rgba(94, 105, 255, 0.2) 100%);
        border: 1px solid #00EDFF;
        color: #00EDFF;
        padding: 5px 14px;
        border-radius: 4px;
        cursor: pointer;
        font-family: 'Rajdhani', sans-serif;
        font-size: 0.95rem;
        font-weight: 700;
        letter-spacing: 1px;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
        box-shadow: 0 0 12px rgba(0, 237, 255, 0.25);
    }
    .hud-stream-btn:hover {
        background: #00EDFF;
        color: #000;
        box-shadow: 0 0 20px rgba(0, 237, 255, 0.6);
        transform: scale(1.02);
    }
    .stream-live-pulse {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #FF3366;
        animation: streamBlink 1.2s infinite;
    }

    /* Standard Swarm Grid Styles */
    .decryption-dashboard .swarm-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 20px;
        padding: 24px;
        max-height: 500px;
        overflow-y: auto;
    }

    .decryption-dashboard .agent-terminal {
        background: rgba(0, 0, 0, 0.45);
        border: 1px solid rgba(0, 237, 255, 0.15);
        border-radius: 6px;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        position: relative;
    }

    .decryption-dashboard .agent-terminal-header {
        background: rgba(94, 105, 255, 0.18);
        padding: 10px 15px;
        font-family: 'Exo 2', sans-serif;
        font-weight: 700;
        font-size: 0.95rem;
        color: var(--accent-primary);
        border-bottom: 1px solid rgba(0, 237, 255, 0.15);
        display: flex;
        justify-content: space-between;
    }

    .decryption-dashboard .terminal-feed {
        padding: 15px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 12px;
        font-family: 'JetBrains Mono', monospace;
        scroll-behavior: smooth;
        height: 300px;
    }

    .decryption-dashboard .console-wrapper.fullscreen .terminal-feed {
        height: 500px;
    }

    /* Terminal Scrollbar */
    .decryption-dashboard .swarm-grid::-webkit-scrollbar,
    .decryption-dashboard .terminal-feed::-webkit-scrollbar { width: 6px; }
    .decryption-dashboard .swarm-grid::-webkit-scrollbar-track,
    .decryption-dashboard .terminal-feed::-webkit-scrollbar-track { background: #060B1E; }
    .decryption-dashboard .swarm-grid::-webkit-scrollbar-thumb,
    .decryption-dashboard .terminal-feed::-webkit-scrollbar-thumb { background: var(--accent-secondary); border-radius: 3px; }
    .decryption-dashboard .swarm-grid::-webkit-scrollbar-thumb:hover,
    .decryption-dashboard .terminal-feed::-webkit-scrollbar-thumb:hover { background: var(--accent-primary); }

    .decryption-dashboard .log-entry {
        font-size: 0.9rem;
        line-height: 1.6;
        animation: slideUp 0.3s ease;
    }
    
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .decryption-dashboard .log-entry.system { color: var(--accent-secondary); }
    .decryption-dashboard .log-entry.tool { 
        color: var(--accent-primary); 
        background: rgba(0, 237, 255, 0.05); 
        padding: 12px; 
        border-radius: 4px; 
        border-left: 4px solid var(--accent-primary);
    }
    .decryption-dashboard .log-entry.thought { 
        color: #00EDFF; 
        opacity: 0.85;
        font-style: italic;
        border-left: 2px solid #00EDFF;
        padding-left: 12px;
        background: linear-gradient(90deg, rgba(0, 237, 255, 0.05) 0%, transparent 100%);
        padding-top: 8px;
        padding-bottom: 8px;
    }

    .decryption-dashboard .log-time {
        color: #475569;
        font-size: 0.85rem;
        margin-right: 12px;
    }

    /* Recent Impacts */
    .decryption-dashboard .recent-impacts {
        display: flex;
        flex-direction: column;
        gap: 15px;
        padding: 24px;
        max-height: 480px;
        overflow-y: auto;
    }

    .decryption-dashboard .impact-row {
        display: flex;
        justify-content: space-between;
        padding: 16px;
        background: rgba(0, 0, 0, 0.3);
        border-left: 4px solid var(--accent-secondary);
        border-radius: 4px;
        transition: transform 0.2s ease, border-left-color 0.2s ease, background 0.2s ease;
    }

    .decryption-dashboard .impact-row > div:first-child {
        flex: 1;
        min-width: 0;
        overflow-wrap: break-word;
        word-wrap: break-word;
        word-break: break-word;
        margin-right: 15px;
    }

    .decryption-dashboard .impact-row:hover {
        transform: translateX(5px);
        border-left-color: var(--accent-primary);
        background: rgba(0, 237, 255, 0.05);
    }

    @keyframes smoothPulse {
        0% { box-shadow: 0 0 0 0 rgba(35, 209, 96, 0.7); }
        70% { box-shadow: 0 0 0 10px rgba(35, 209, 96, 0); }
        100% { box-shadow: 0 0 0 0 rgba(35, 209, 96, 0); }
    }

    .pulse-ring {
        display: inline-block;
        width: 12px;
        height: 12px;
        background-color: var(--accent-success);
        border-radius: 50%;
        margin-right: 15px;
        animation: smoothPulse 2s infinite;
    }

    /* Tablet Responsiveness */
    @media (max-width: 1024px) {
        .decryption-dashboard .stats-block {
            grid-template-columns: repeat(2, 1fr) !important;
        }
        .decryption-dashboard .highlight-card {
            padding: 30px;
        }
        .decryption-dashboard .stat-card.empty-card {
            display: none;
        }
    }

    /* Mobile Responsiveness */
    @media (max-width: 768px) {
        .decryption-dashboard {
            padding: 15px 10px;
        }

        .hud-top-bar {
            flex-direction: column;
            align-items: stretch;
            text-align: center;
        }
        .hud-top-actions {
            justify-content: center;
        }

        .decomp-pipeline-flow {
            flex-direction: column;
            gap: 10px;
        }
        .pipeline-flow-connector i {
            transform: rotate(90deg);
        }

        .decryption-dashboard .highlight-card {
            flex-direction: column;
            gap: 25px;
            text-align: center;
            padding: 25px 15px;
        }

        .decryption-dashboard .stats-block {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 12px;
        }

        .decryption-dashboard .stat-card {
            padding: 15px;
            gap: 8px;
        }

        .decryption-dashboard .stat-card.span-2 {
            grid-column: span 2 !important;
        }

        .decryption-dashboard .stat-card .label {
            font-size: 0.85rem;
        }

        .decryption-dashboard .stat-card .value {
            font-size: 1.8rem;
        }

        .agent-status-header {
            flex-direction: column;
            align-items: stretch !important;
            gap: 12px !important;
            margin-bottom: 25px !important;
            background: rgba(0,0,0,0.2);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.05);
        }

        .agent-status-header > div {
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-align: left !important;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        
        .agent-status-header > div:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .agent-status-header > div > span:first-child {
            margin-bottom: 0;
        }

        .decryption-dashboard .impact-row {
            flex-direction: column;
            gap: 10px;
        }

        .decryption-dashboard .impact-row > div:last-child {
            text-align: left !important;
            opacity: 0.7;
        }
        
        .decryption-dashboard h2 {
            font-size: 1.4rem;
            text-align: center;
        }
        
        .main-header h1 {
            font-size: 1.6rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .main-header p {
            text-align: center;
            font-size: 0.95rem !important;
            padding: 0 10px;
        }
        
        /* Vertical Pipeline Tracker for Mobile */
        .pipeline-container {
            flex-direction: column !important;
            gap: 25px !important;
            align-items: flex-start !important;
            padding-left: 20px;
            padding-top: 10px;
            padding-bottom: 10px;
        }
        
        .pipeline-line {
            width: 2px !important;
            height: calc(100% - 40px) !important;
            top: 20px !important;
            left: 39px !important;
            bottom: auto !important;
            right: auto !important;
            background: linear-gradient(to bottom, rgba(0, 237, 255, 0.4), rgba(0, 237, 255, 0.05)) !important;
        }
        
        .pipeline-step {
            width: 100% !important;
            flex-direction: row !important;
            justify-content: flex-start !important;
            align-items: center !important;
            gap: 20px !important;
            position: relative;
            z-index: 2;
        }
        
        .pipeline-step span {
            font-size: 1.1rem !important;
            text-align: left !important;
            font-weight: 600;
        }
        
        .decryption-dashboard .swarm-grid {
            grid-template-columns: 1fr;
            padding: 15px;
        }
    }

    @media (max-width: 480px) {
        .decryption-dashboard .stats-block {
            grid-template-columns: 1fr !important;
        }
        .decryption-dashboard .stat-card.span-2 {
            grid-column: span 1 !important;
        }
    }


    /* Executive Summary Hero Card */
    .exec-hero-card {
        background: linear-gradient(135deg, rgba(10, 18, 42, 0.96), rgba(15, 25, 58, 0.92));
        border: 1px solid rgba(0, 237, 255, 0.35);
        border-radius: 8px;
        padding: 28px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.1);
        display: flex;
        flex-direction: column;
        gap: 20px;
        position: relative;
    }
    .hero-top-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        border-bottom: 1px solid rgba(0, 237, 255, 0.15);
        padding-bottom: 14px;
    }
    .hero-badge-group {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .pso-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        background: rgba(35, 209, 96, 0.15);
        border: 1px solid rgba(35, 209, 96, 0.4);
        color: var(--accent-success);
    }
    .pso-status-pill .pill-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--accent-success);
        box-shadow: 0 0 8px var(--accent-success);
        animation: smoothPulse 2s infinite;
    }
    .pso-version-pill {
        display: inline-flex;
        align-items: center;
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 1px;
        background: rgba(0, 237, 255, 0.12);
        border: 1px solid rgba(0, 237, 255, 0.3);
        color: #00EDFF;
    }
    .pso-toolchain-pill {
        display: inline-flex;
        align-items: center;
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 1px;
        background: rgba(94, 105, 255, 0.12);
        border: 1px solid rgba(94, 105, 255, 0.3);
        color: #a5b4fc;
    }
    .hero-updated-time {
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.85rem;
        color: #94a3b8;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .hero-main-layout {
        display: grid;
        grid-template-columns: 1fr 340px;
        gap: 30px;
        align-items: center;
    }
    @media (max-width: 960px) {
        .hero-main-layout {
            grid-template-columns: 1fr;
        }
    }
    .hero-headline-block h2 {
        font-family: 'Exo 2', sans-serif;
        font-size: 1.85rem;
        font-weight: 800;
        color: #fff;
        margin: 0 0 12px 0;
        letter-spacing: 0.5px;
        text-shadow: 0 0 20px rgba(0, 237, 255, 0.25);
    }
    .hero-description-text {
        color: #cbd5e1;
        font-size: 1.05rem;
        line-height: 1.6;
        margin: 0 0 22px 0;
    }
    .hero-key-takeaways {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 12px;
    }
    .takeaway-card {
        background: rgba(6, 11, 30, 0.7);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 6px;
        padding: 12px 16px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .takeaway-card.highlight {
        border-color: rgba(0, 237, 255, 0.4);
        background: linear-gradient(145deg, rgba(0, 237, 255, 0.08), rgba(6, 11, 30, 0.8));
    }
    .takeaway-card.success {
        border-color: rgba(35, 209, 96, 0.4);
        background: linear-gradient(145deg, rgba(35, 209, 96, 0.08), rgba(6, 11, 30, 0.8));
    }
    .takeaway-lbl {
        font-size: 0.75rem;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: #94a3b8;
        font-family: 'Share Tech Mono', monospace;
    }
    .takeaway-val {
        font-family: 'Rajdhani', sans-serif;
        font-size: 1.4rem;
        font-weight: 700;
        color: #fff;
    }
    .takeaway-sub {
        font-size: 0.8rem;
        color: #64748b;
    }

    /* Hero Progress Ring Block */
    .hero-progress-ring-card {
        background: rgba(4, 8, 24, 0.8);
        border: 1px solid rgba(0, 237, 255, 0.25);
        border-radius: 8px;
        padding: 22px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 15px;
        box-shadow: inset 0 0 20px rgba(0, 0, 0, 0.5);
    }
    .hero-progress-ring-card .circular-progress {
        width: 140px;
        height: 140px;
    }
    .hero-progress-breakdown {
        width: 100%;
        display: flex;
        flex-direction: column;
        gap: 8px;
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.85rem;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        padding-top: 12px;
    }
    .breakdown-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* Master Roadmap & Remaining Steps Board */
    .roadmap-section {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    .roadmap-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 15px;
        border-bottom: 1px solid rgba(0, 237, 255, 0.2);
        padding-bottom: 16px;
    }
    .roadmap-title-group h2 {
        font-family: 'Exo 2', sans-serif;
        font-size: 1.5rem;
        color: #fff;
        margin: 0 0 6px 0;
        letter-spacing: 1px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .roadmap-title-group p {
        color: #94a3b8;
        font-size: 0.95rem;
        margin: 0;
    }
    .roadmap-legend-strip {
        display: flex;
        align-items: center;
        gap: 16px;
        font-size: 0.8rem;
        font-family: 'Share Tech Mono', monospace;
        flex-wrap: wrap;
    }
    .roadmap-legend-strip span {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .leg-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
    }
    .leg-dot.completed { background: #23D160; box-shadow: 0 0 6px #23D160; }
    .leg-dot.active { background: #00EDFF; box-shadow: 0 0 8px #00EDFF; animation: smoothPulse 2s infinite; }
    .leg-dot.upcoming { background: #5E69FF; }
    .leg-dot.target { background: #FFB020; }

    /* Roadmap 5-Stage Step Cards */
    .roadmap-steps-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 16px;
    }
    .roadmap-card {
        background: rgba(8, 14, 34, 0.85);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 6px;
        padding: 18px;
        display: flex;
        flex-direction: column;
        gap: 14px;
        position: relative;
        transition: transform 0.2s ease, border-color 0.2s ease;
    }
    .roadmap-card:hover {
        transform: translateY(-2px);
    }
    .roadmap-card.stage-done {
        border-color: rgba(35, 209, 96, 0.4);
        background: linear-gradient(180deg, rgba(35, 209, 96, 0.06), rgba(8, 14, 34, 0.9));
    }
    .roadmap-card.stage-active {
        border-color: rgba(0, 237, 255, 0.6);
        background: linear-gradient(180deg, rgba(0, 237, 255, 0.12), rgba(8, 14, 34, 0.95));
        box-shadow: 0 0 25px rgba(0, 237, 255, 0.15), inset 0 0 15px rgba(0, 237, 255, 0.05);
    }
    .roadmap-card.stage-next {
        border-color: rgba(94, 105, 255, 0.35);
    }
    .roadmap-card.stage-future {
        border-color: rgba(255, 255, 255, 0.08);
        opacity: 0.85;
    }

    .roadmap-card-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .roadmap-stage-num {
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.8rem;
        letter-spacing: 2px;
        font-weight: 700;
        color: #94a3b8;
    }
    .roadmap-stage-tag {
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
        padding: 2px 8px;
        border-radius: 4px;
    }
    .roadmap-stage-tag.badge-done {
        background: rgba(35, 209, 96, 0.2);
        color: #23D160;
        border: 1px solid rgba(35, 209, 96, 0.4);
    }
    .roadmap-stage-tag.badge-active {
        background: rgba(0, 237, 255, 0.2);
        color: #00EDFF;
        border: 1px solid rgba(0, 237, 255, 0.5);
        animation: smoothPulse 2s infinite;
    }
    .roadmap-stage-tag.badge-next {
        background: rgba(94, 105, 255, 0.2);
        color: #a5b4fc;
        border: 1px solid rgba(94, 105, 255, 0.4);
    }
    .roadmap-stage-tag.badge-future {
        background: rgba(255, 255, 255, 0.05);
        color: #94a3b8;
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .roadmap-card-title {
        font-family: 'Exo 2', sans-serif;
        font-size: 1.15rem;
        font-weight: 700;
        color: #fff;
        margin: 0;
        line-height: 1.3;
    }
    .roadmap-card-desc {
        color: #94a3b8;
        font-size: 0.9rem;
        line-height: 1.5;
        margin: 0;
    }
    .roadmap-card-tasks {
        background: rgba(0, 0, 0, 0.35);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 4px;
        padding: 10px 12px;
        display: flex;
        flex-direction: column;
        gap: 6px;
        font-size: 0.85rem;
    }
    .task-item {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        line-height: 1.4;
    }
    .task-item.done {
        color: #e2e8f0;
    }
    .task-item.done i {
        color: #23D160;
        margin-top: 2px;
    }
    .task-item.remaining {
        color: #94a3b8;
    }
    .task-item.remaining i {
        color: #FFB020;
        margin-top: 2px;
    }
    .task-item.active-now {
        color: #00EDFF;
        font-weight: 600;
    }
    .task-item.active-now i {
        color: #00EDFF;
        margin-top: 2px;
        animation: smoothPulse 1.5s infinite;
    }
    .roadmap-mini-meter {
        width: 100%;
        height: 6px;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 3px;
        overflow: hidden;
        margin-top: auto;
    }
    .roadmap-mini-fill {
        height: 100%;
        border-radius: 3px;
    }
    .fill-done { width: 95.6%; background: linear-gradient(90deg, #23D160, #00EDFF); }
    .fill-active { width: 10.4%; background: linear-gradient(90deg, #00EDFF, #5E69FF); }
    .fill-next { width: 25%; background: linear-gradient(90deg, #5E69FF, #a5b4fc); }
    .fill-future { width: 0%; background: #64748b; }

    /* Streamlined Clean Metrics Panels */
    .clean-metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 16px;
    }
    .metric-master-card {
        background: rgba(10, 16, 38, 0.85);
        border: 1px solid rgba(0, 237, 255, 0.18);
        border-radius: 8px;
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 14px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35);
    }
    .metric-master-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        padding-bottom: 10px;
    }
    .metric-master-title {
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.85rem;
        letter-spacing: 1.5px;
        color: #94a3b8;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .metric-master-title i {
        color: var(--accent-primary);
    }
    .metric-master-val {
        font-family: 'Rajdhani', sans-serif;
        font-size: 2.2rem;
        font-weight: 700;
        color: #fff;
        line-height: 1;
    }
    .metric-master-val.text-glow-green {
        color: #23D160;
        text-shadow: 0 0 15px rgba(35, 209, 96, 0.5);
    }
    .metric-master-val.text-glow-cyan {
        color: #00EDFF;
        text-shadow: 0 0 15px rgba(0, 237, 255, 0.5);
    }
    .metric-master-val.text-glow-purple {
        color: #a5b4fc;
        text-shadow: 0 0 15px rgba(94, 105, 255, 0.5);
    }
    .metric-details-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        font-size: 0.9rem;
    }
    .metric-detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: #94a3b8;
    }
    .metric-detail-row strong {
        color: #f8fafc;
        font-family: 'JetBrains Mono', monospace;
    }

    /* Collapsible Technical Forensics Drawer */
    .forensics-drawer {
        background: rgba(6, 11, 28, 0.7);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 6px;
        overflow: hidden;
    }
    .forensics-drawer summary {
        padding: 14px 20px;
        cursor: pointer;
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.95rem;
        color: #94a3b8;
        display: flex;
        align-items: center;
        gap: 10px;
        user-select: none;
        transition: color 0.2s, background 0.2s;
    }
    .forensics-drawer summary:hover {
        color: #00EDFF;
        background: rgba(0, 237, 255, 0.05);
    }
    .forensics-drawer-content {
        padding: 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.06);
    }

</style>

<div class="pso-spinner-svg">
    <canvas id="star-canvas-stats"></canvas>
    <svg class="hex2"><!-- hex SVG --></svg>
</div>

<main class="container" style="margin-top: 90px;">
    <div class="main-header" style="margin-bottom: 1.5rem;">
        <h1><div class="pulse-ring"></div> Client Decompilation Matrix</h1>
        <p style="color: #94a3b8; font-family: 'Exo 2', sans-serif; font-size: 1.1rem; max-width: 850px; margin-top: 10px; line-height: 1.6;">
            <strong>[PIONEER 2 LAB TRANSMISSION]</strong><br>
            Attention Hunters. Our autonomous analytics network is deployed to reverse-engineer the foundational architecture of the Pioneer project's archives, actively decompiling the <strong>Phantasy Star Online Blue Burst Client / Tethealla 125.13 binary</strong> into clean, idiomatic C++ using Microsoft Visual C++ Toolkit 2003 (<span style="color: #00EDFF;">CL.EXE</span>). What you are witnessing below is a live telemetry feed from the decompilation testbench as it compiles clean C++ candidates against Microsoft Visual C++ 2003, verifies COFF relocations, and gates byte-level match integrity.
        </p>
    </div>

    <div class="decryption-dashboard">
        
        <!-- Pioneer 2 Lab HUD Top Bar -->
        <div class="hud-top-bar tech-corners">
            <div class="hud-pioneer-badge">
                <span class="hud-glow-dot"></span>
                <span class="hud-pioneer-text">PIONEER 2 LAB // VER 125.13 // CLASSIFIED S-RANK REVERSE ENGINEERING</span>
            </div>
            <div class="hud-top-actions">
                <button id="btn-open-stream" class="hud-stream-btn" onclick="openLiveStreamTheater()" title="Launch Fullscreen Live Stream Theater">
                    <span class="stream-live-pulse"></span>
                    <i class="fas fa-satellite-dish"></i>
                    <span>LIVE STREAM THEATER</span>
                </button>
                <div class="hud-beat-clock" title="Swatch Internet Time (Biel Mean Time UTC+1)">
                    <span class="hud-beat-label">BEAT TIME</span>
                    <span id="pso-beat-time" class="hud-beat-val">@000 .beats</span>
                </div>
                <button id="sfx-toggle-btn" class="hud-sfx-btn" onclick="toggleAudioSFX()" title="Toggle Retro Sci-Fi Terminal Audio Feedback">
                    <i class="fas fa-volume-mute" id="sfx-icon"></i>
                    <span id="sfx-text">SFX: OFF</span>
                </button>
            </div>
        </div>

        <!-- Live Assembly / Opcode Stream Marquee -->
        <div class="cipher-stream-wrapper">
            <div class="cipher-stream-label"><i class="fas fa-microchip"></i> RE-TRACE STREAM</div>
            <div class="cipher-stream-marquee">
                <div class="cipher-stream-track" id="cipher-stream-track">
                    <span><strong class="hex-addr">0x00824880</strong> <span class="hex-bytes">8B 44 24 04</span> <span class="hex-asm">MOV EAX, [ESP+4]</span></span>
                    <span><strong class="hex-addr">0x00824884</strong> <span class="hex-bytes">A3 F8 65 A1 00</span> <span class="hex-asm">MOV [g_PreviousMainMenuType_00a165f8], EAX</span></span>
                    <span><strong class="hex-addr">0x00824889</strong> <span class="hex-bytes">E8 1A 2B BE FF</span> <span class="hex-asm">CALL ResetTargetIndexBuffers_004073a8</span></span>
                    <span><strong class="hex-addr">0x00824CBC</strong> <span class="hex-bytes">80 79 05 02</span> <span class="hex-asm">CMP BYTE PTR [ECX+0x5], 2</span></span>
                    <span><strong class="hex-addr">0x00824D68</strong> <span class="hex-bytes">E8 93 1E 00 00</span> <span class="hex-asm">CALL AcceptTradeRequest_00824d68</span></span>
                    <span><strong class="hex-addr">0x00825268</strong> <span class="hex-bytes">8B 50 10</span> <span class="hex-asm">MOV EDX, [EAX+0x10] ; HandleTradeWindowItemUpdate</span></span>
                    <span><strong class="hex-addr">0x008252A4</strong> <span class="hex-bytes">83 EC 14</span> <span class="hex-asm">SUB ESP, 0x14 ; UpdateTradeItemState</span></span>
                    <span><strong class="hex-addr">0x008259E8</strong> <span class="hex-bytes">80 78 05 00</span> <span class="hex-asm">CMP BYTE PTR [EAX+0x5], 0 ; Packet0x60 Sub0xA6</span></span>
                    <span><strong class="hex-addr">0x00826E50</strong> <span class="hex-bytes">C7 05 FC A8 AC 00 04 00 00 00</span> <span class="hex-asm">MOV [g_TradeWindowOtherPlayerSlot], 4</span></span>
                    <span><strong class="hex-addr">0x00824880</strong> <span class="hex-bytes">8B 44 24 04</span> <span class="hex-asm">MOV EAX, [ESP+4]</span></span>
                    <span><strong class="hex-addr">0x00824884</strong> <span class="hex-bytes">A3 F8 65 A1 00</span> <span class="hex-asm">MOV [g_PreviousMainMenuType_00a165f8], EAX</span></span>
                </div>
            </div>
        </div>

        <!-- Live Status Subheader -->
        <div class="agent-status-header" style="display: flex; justify-content: flex-end; gap: 40px; margin-bottom: -10px; flex-wrap: wrap;">
            <div style="text-align: right;">
                <span style="color: #5E69FF; font-size: 0.8rem; letter-spacing: 2px; text-transform: uppercase; font-weight: bold; display: block;">BANKED MATCHES</span>
                <span id="m-status" style="font-family: 'JetBrains Mono', monospace; font-size: 1.2rem; font-weight: bold; color: #23D160; text-shadow: 0 0 10px rgba(35, 209, 96, 0.4);">220 / 2,729 (8.06%)</span>
            </div>
            <div style="text-align: right;">
                <span style="color: #5E69FF; font-size: 0.8rem; letter-spacing: 2px; text-transform: uppercase; font-weight: bold; display: block;">IN-FLIGHT REACH</span>
                <span id="m-model" style="font-family: 'JetBrains Mono', monospace; font-size: 1.2rem; font-weight: bold; color: #00EDFF; text-shadow: 0 0 10px rgba(0, 237, 255, 0.4);">481 Functions (17.6%)</span>
            </div>
            <div style="text-align: right;">
                <span style="color: #5E69FF; font-size: 0.8rem; letter-spacing: 2px; text-transform: uppercase; font-weight: bold; display: block;">BUILD INTEGRITY</span>
                <span id="m-eta" style="font-family: 'JetBrains Mono', monospace; font-size: 1.2rem; font-weight: bold; color: var(--accent-success); text-shadow: 0 0 10px rgba(35, 209, 96, 0.4);">MSVC 7.1 (0 Drift)</span>
            </div>
        </div>

        
        <!-- Executive Project Overview & Current Position Hero -->
        <div class="glass-panel exec-hero-card tech-corners">
            <div class="hero-top-row">
                <div class="hero-badge-group">
                    <span class="pso-status-pill"><span class="pill-dot"></span> LIVE DECOMPILATION PIPELINE</span>
                    <span class="pso-version-pill">TARGET: PSOBB v125.13 (PC)</span>
                    <span class="pso-toolchain-pill">TOOLCHAIN: MSVC 2003 (CL.EXE)</span>
                </div>
                <div class="hero-updated-time">
                    <i class="fas fa-sync-alt fa-spin" style="color: var(--accent-primary);"></i>
                    <span id="hero-live-indicator">LIVE TELEMETRY ACTIVE</span>
                </div>
            </div>

            <div class="hero-main-layout">
                <div class="hero-headline-block">
                    <h2>PSOBB Client Decompilation Project</h2>
                    <p class="hero-description-text">
                        We are reverse-engineering the complete <strong>Phantasy Star Online Blue Burst (v125.13)</strong> client binary (<code>psobb.exe</code>) into clean, matching C++ source code. Achieving a byte-identical decompilation unlocks a standalone modern client for <strong>64-bit Windows, Linux, and Steam Deck</strong> with uncapped framerates, 4K UI scaling, and modern controller mapping.
                    </p>
                    <div class="hero-key-takeaways">
                        <div class="takeaway-card success">
                            <span class="takeaway-lbl">BYTE-EXACT BANKED</span>
                            <span class="takeaway-val text-glow-green" id="takeaway-pct">8.1%</span>
                            <span class="takeaway-sub" id="takeaway-solved-sub">220 of 2,729 Live Belt Functions</span>
                        </div>
                        <div class="takeaway-card highlight">
                            <span class="takeaway-lbl">PROMOTABLE NEAR</span>
                            <span class="takeaway-val text-glow-cyan" id="takeaway-promotable">+266 fns</span>
                            <span class="takeaway-sub" id="takeaway-promotable-sub">17.6% In-Flight Reach</span>
                        </div>
                        <div class="takeaway-card">
                            <span class="takeaway-lbl">GHIDRA RECONNAISSANCE</span>
                            <span class="takeaway-val" id="takeaway-ghidra" style="color: #a5b4fc; font-size: 1.4rem;">95.6%</span>
                            <span class="takeaway-sub">18,790 / 19,660 Symbols Mapped</span>
                        </div>
                        <div class="takeaway-card">
                            <span class="takeaway-lbl">TARGET COMPILER</span>
                            <span class="takeaway-val" style="color: #FFB020; font-size: 1.15rem;">MSVC 7.1 /MT /O2</span>
                            <span class="takeaway-sub">Pinned cl.exe 13.10.3077</span>
                        </div>
                    </div>
                </div>

                <div class="hero-progress-ring-card">
                    <div class="circular-progress" id="progress-circle" style="--percentage: 7.9;">
                        <div class="progress-value">
                            <span id="progress-text">8.1%</span>
                            <span class="progress-label">BYTE-MATCHED</span>
                        </div>
                    </div>
                    <div class="hero-progress-breakdown">
                        <div class="breakdown-row">
                            <span style="color: #94a3b8;">Banked Exact:</span>
                            <span id="hero-banked-fns" style="color: #23D160; font-weight: bold;">220 / 2,729 (8.1%)</span>
                        </div>
                        <div class="breakdown-row">
                            <span style="color: #94a3b8;">Promotable Near:</span>
                            <span id="hero-promotable-fns" style="color: #00EDFF; font-weight: bold;">266 Candidates (9.7%)</span>
                        </div>
                        <div class="breakdown-row">
                            <span style="color: #94a3b8;">Belt Remaining:</span>
                            <span id="hero-remaining-belt-fns" style="color: #FFB020; font-weight: bold;">2,509 Functions</span>
                        </div>
                        <div class="breakdown-row" style="border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 6px; margin-top: 4px;">
                            <span style="color: #94a3b8;">Ghidra Symbols:</span>
                            <span id="hero-ghidra-fns" style="color: #a5b4fc; font-weight: bold;">18,790 / 19,660 (95.6%)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Master Project Roadmap: Where We Are At & What Steps Are Remaining -->
        <div class="glass-panel roadmap-section tech-corners" style="padding: 24px;">
            <div class="roadmap-header">
                <div class="roadmap-title-group">
                    <h2><i class="fas fa-map-signs" style="color: var(--accent-primary);"></i> Project Roadmap: Where We Are At &amp; What Steps Are Remaining</h2>
                    <p>Transparent progress tracking through each phase required to produce a clean, compilable, and modernized PSOBB client.</p>
                </div>
                <div class="roadmap-legend-strip">
                    <span><span class="leg-dot completed"></span> Complete</span>
                    <span><span class="leg-dot active"></span> Active Frontier</span>
                    <span><span class="leg-dot upcoming"></span> Next Up</span>
                    <span><span class="leg-dot target"></span> Final Goal</span>
                </div>
            </div>

            <div class="roadmap-steps-grid">
                <!-- Step 1 -->
                <div class="roadmap-card stage-done">
                    <div class="roadmap-card-head">
                        <span class="roadmap-stage-num">PHASE 01</span>
                        <span class="roadmap-stage-tag badge-done"><i class="fas fa-check"></i> 95.6% Mapped</span>
                    </div>
                    <h3 class="roadmap-card-title">Binary Disassembly &amp; Symbol Reconnaissance</h3>
                    <p class="roadmap-card-desc">Ghidra control flow recovery, symbol identification, and preliminary decompiler passes across all 19,660 routines in <code>psobb.exe</code>.</p>
                    <div class="roadmap-card-tasks">
                        <div class="task-item done">
                            <i class="fas fa-check-circle"></i>
                            <span>18,790 functions identified and semantically labeled in Ghidra</span>
                        </div>
                        <div class="task-item done">
                            <i class="fas fa-check-circle"></i>
                            <span>Core game loops, math matrices, packet handlers, and renderer mapped</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-hourglass-half"></i>
                            <span>870 unknown stub/helper routines remaining to categorize</span>
                        </div>
                    </div>
                    <div class="roadmap-mini-meter">
                        <div class="roadmap-mini-fill fill-done" id="meter-phase-1"></div>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="roadmap-card stage-active">
                    <div class="roadmap-card-head">
                        <span class="roadmap-stage-num">PHASE 02</span>
                        <span class="roadmap-stage-tag badge-active"><i class="fas fa-bolt"></i> Active Frontier (8.1%)</span>
                    </div>
                    <h3 class="roadmap-card-title">Byte-Exact C/C++ Matching (MSVC 2003)</h3>
                    <p class="roadmap-card-desc">Authoring clean, compilable C/C++ code that yields bit-identical machine code when compiled with the original compiler (MSVC 7.1 <code>cl.exe 13.10.3077 /MT /O2</code>).</p>
                    <div class="roadmap-card-tasks">
                        <div class="task-item done">
                            <i class="fas fa-check-circle"></i>
                            <span>220 live belt functions banked and reloc-verified in <code>src/</code></span>
                        </div>
                        <div class="task-item active-now">
                            <i class="fas fa-arrow-right"></i>
                            <span>266 promotable near-matches in <code>drafts/near/</code> (0 mismatches, clean relocs)</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-hourglass-half"></i>
                            <span>2,509 live belt reachable functions remaining to decompile &amp; bank</span>
                        </div>
                    </div>
                    <div class="roadmap-mini-meter">
                        <div class="roadmap-mini-fill fill-active" id="meter-phase-2" style="width: 17.6%;"></div>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="roadmap-card stage-next">
                    <div class="roadmap-card-head">
                        <span class="roadmap-stage-num">PHASE 03</span>
                        <span class="roadmap-stage-tag badge-next"><i class="fas fa-microchip"></i> In Testbench</span>
                    </div>
                    <h3 class="roadmap-card-title">Whole-Image Link &amp; Relocation Resolution</h3>
                    <p class="roadmap-card-desc">Incrementally linking banked translation units with the original binary through <code>tools/build_all.py</code>, ensuring SEH handlers and 16,500 relocation slots match.</p>
                    <div class="roadmap-card-tasks">
                        <div class="task-item done">
                            <i class="fas fa-check-circle"></i>
                            <span>Automated rebuild harness compiling banked translation units</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-hourglass-half"></i>
                            <span>Synthetic stub linker combining banked objects with original PE chunks</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-hourglass-half"></i>
                            <span>Achieve 1:1 bit-exact whole-image link across all 11 PE sections</span>
                        </div>
                    </div>
                    <div class="roadmap-mini-meter">
                        <div class="roadmap-mini-fill fill-next" id="meter-phase-3"></div>
                    </div>
                </div>

                <!-- Step 4 -->
                <div class="roadmap-card stage-future">
                    <div class="roadmap-card-head">
                        <span class="roadmap-stage-num">PHASE 04</span>
                        <span class="roadmap-stage-tag badge-future"><i class="fas fa-cubes"></i> Next Up</span>
                    </div>
                    <h3 class="roadmap-card-title">Modular Subsystem Decoupling</h3>
                    <p class="roadmap-card-desc">Untangling monolithic client code into clean, modular C++ libraries with modern, decoupled interfaces for network, audio, renderer, and UI.</p>
                    <div class="roadmap-card-tasks">
                        <div class="task-item remaining">
                            <i class="fas fa-circle-notch"></i>
                            <span><code>psobb-net</code>: Isolate NewServ/Tethealla client network protocol</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-circle-notch"></i>
                            <span><code>psobb-render</code>: Abstract legacy D3D8 into modern D3D11/Vulkan</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-circle-notch"></i>
                            <span><code>psobb-audio</code>: Integrate OpenAL-Soft / DSOAL 3D spatial audio</span>
                        </div>
                    </div>
                    <div class="roadmap-mini-meter">
                        <div class="roadmap-mini-fill fill-future"></div>
                    </div>
                </div>

                <!-- Step 5 -->
                <div class="roadmap-card stage-future">
                    <div class="roadmap-card-head">
                        <span class="roadmap-stage-num">PHASE 05</span>
                        <span class="roadmap-stage-tag badge-future"><i class="fas fa-trophy"></i> Destination</span>
                    </div>
                    <h3 class="roadmap-card-title">Modern Native Client Release</h3>
                    <p class="roadmap-card-desc">The ultimate goal: A pure native, modern 64-bit client running seamlessly on modern operating systems without emulation or compatibility layers.</p>
                    <div class="roadmap-card-tasks">
                        <div class="task-item remaining">
                            <i class="fas fa-circle-notch"></i>
                            <span>Native 64-bit Windows build (no DDraw/D3D8 legacy bugs)</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-circle-notch"></i>
                            <span>Native Linux &amp; Steam Deck build (no Proton/Wine needed)</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-circle-notch"></i>
                            <span>High-FPS physics decoupling, 4K UI scaling, and controllers</span>
                        </div>
                    </div>
                    <div class="roadmap-mini-meter">
                        <div class="roadmap-mini-fill fill-future"></div>
                    </div>
                </div>
            </div>
        </div>

        

        <!-- PSOBB PE Executable Memory Matrix & Sector Scanner -->
        <div class="glass-panel memory-matrix-panel tech-corners">
            <div class="memory-matrix-header">
                <div>
                    <h2 style="font-size: 1.3rem; margin: 0 0 6px 0;"><i class="fas fa-cubes" style="color: var(--accent-primary); margin-right: 8px;"></i> PSOBB PE Memory Matrix & Sector Scanner</h2>
                    <p style="color: #94a3b8; margin: 0; font-size: 0.95rem;">Live structural status of the <code>psobb.exe</code> image (0x00400000 - 0x00B20000). Visualizing decrypted sections, unresolved symbols, and active AI scanning sweeps.</p>
                </div>
                <div class="matrix-legend">
                    <span class="legend-item"><span class="legend-dot solved"></span> Decompiled</span>
                    <span class="legend-item"><span class="legend-dot scanning"></span> In Scope</span>
                    <span class="legend-item"><span class="legend-dot pending"></span> Pending</span>
                </div>
            </div>

            <div class="memory-sections-grid">
                <!-- Section: .text -->
                <div class="mem-section-card">
                    <div class="mem-section-head">
                        <span class="mem-section-title">.text (Code / Functions)</span>
                        <span class="mem-section-range">0x00401000 - 0x00780000</span>
                    </div>
                    <div class="mem-blocks-row" id="blocks-text">
                        <!-- 16 blocks -->
                    </div>
                    <div class="mem-section-foot">
                        <span>2,729 Live Belt • MSVC 7.1</span>
                        <span id="text-pct" style="color: #00EDFF; font-weight: bold;">8.1% Banked (95.6% Mapped)</span>
                    </div>
                </div>

                <!-- Section: .rdata -->
                <div class="mem-section-card">
                    <div class="mem-section-head">
                        <span class="mem-section-title">.rdata (Read-Only Data)</span>
                        <span class="mem-section-range">0x00780000 - 0x00830000</span>
                    </div>
                    <div class="mem-blocks-row" id="blocks-rdata">
                        <!-- 16 blocks -->
                    </div>
                    <div class="mem-section-foot">
                        <span>Strings, vTables, Consts</span>
                        <span id="rdata-pct" style="color: #23D160; font-weight: bold;">98.1%</span>
                    </div>
                </div>

                <!-- Section: .data -->
                <div class="mem-section-card">
                    <div class="mem-section-head">
                        <span class="mem-section-title">.data (Globals & Packets)</span>
                        <span class="mem-section-range">0x00830000 - 0x009C0000</span>
                    </div>
                    <div class="mem-blocks-row" id="blocks-data">
                        <!-- 16 blocks -->
                    </div>
                    <div class="mem-section-foot">
                        <span>21,672 Variables • Pointers</span>
                        <span id="data-pct" style="color: #5E69FF; font-weight: bold;">96.8%</span>
                    </div>
                </div>

                <!-- Section: .bss -->
                <div class="mem-section-card">
                    <div class="mem-section-head">
                        <span class="mem-section-title">.bss (Uninitialized Memory)</span>
                        <span class="mem-section-range">0x009C0000 - 0x00B20000</span>
                    </div>
                    <div class="mem-blocks-row" id="blocks-bss">
                        <!-- 16 blocks -->
                    </div>
                    <div class="mem-section-foot">
                        <span>Runtime Buffers & Entity Heap</span>
                        <span id="bss-pct" style="color: #23D160; font-weight: bold;">100.0%</span>
                    </div>
                </div>
            </div>
        </div>

        

        <!-- Clean High-Signal Metrics Grid -->
        <div class="clean-metrics-grid">
            <!-- Metric 1: Live Belt Banked C/C++ Source -->
            <div class="metric-master-card">
                <div class="metric-master-head">
                    <span class="metric-master-title"><i class="fas fa-check-double"></i> Live Belt Banked</span>
                    <span class="roadmap-stage-tag badge-active" id="s-banked-pct-tag">8.1%</span>
                </div>
                <div class="metric-master-val text-glow-green" id="s-banked-fns-display">220</div>
                <div class="metric-details-list">
                    <div class="metric-detail-row">
                        <span>Live Belt Scope:</span>
                        <strong id="s-belt-total-display">2,729 fns</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Promotable Near-Matches:</span>
                        <strong id="s-promotable-display" style="color: #00EDFF;">266 (17.6% coverage)</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Belt Remaining to Match:</span>
                        <strong id="s-belt-remaining-display">2,509 fns</strong>
                    </div>
                </div>
            </div>

            <!-- Metric 2: Ghidra Symbol Sweep & Flow -->
            <div class="metric-master-card">
                <div class="metric-master-head">
                    <span class="metric-master-title"><i class="fas fa-search"></i> Symbol Reconnaissance</span>
                    <span class="roadmap-stage-tag badge-done" id="s-ghidra-pct-tag">95.6%</span>
                </div>
                <div class="metric-master-val text-glow-cyan" id="s-ghidra-solved-display">18,790</div>
                <div class="metric-details-list">
                    <div class="metric-detail-row">
                        <span>Total PE Functions:</span>
                        <strong id="s-ghidra-total-display">19,660 routines</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Ghidra Symbol Coverage:</span>
                        <strong style="color: #23D160;">95.57% Mapped</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Unknown Stubs Remaining:</span>
                        <strong id="s-ghidra-remaining-display" style="color: #FFB020;">870 fns</strong>
                    </div>
                </div>
            </div>

            <!-- Metric 3: Code Footprint & Byte Volume -->
            <div class="metric-master-card">
                <div class="metric-master-head">
                    <span class="metric-master-title"><i class="fas fa-layer-group"></i> Code Footprint &amp; Volume</span>
                    <span class="roadmap-stage-tag badge-done">Verified</span>
                </div>
                <div class="metric-master-val text-glow-purple" id="s-footprint-display">5,211 B</div>
                <div class="metric-details-list">
                    <div class="metric-detail-row">
                        <span>Live Belt Code Scope:</span>
                        <strong style="color: #00EDFF;">~573 KB (586,764 B)</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Post-Belt CRT / OS:</span>
                        <strong style="color: #94a3b8;">~4,380 KB (Carved)</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Average Function Size:</span>
                        <strong id="s-avg-fn-size" style="color: #23D160;">24.2 Bytes / fn</strong>
                    </div>
                </div>
            </div>

            <!-- Metric 4: Toolchain Match Gate -->
            <div class="metric-master-card">
                <div class="metric-master-head">
                    <span class="metric-master-title"><i class="fas fa-shield-alt"></i> Toolchain Match Gate</span>
                    <span class="roadmap-stage-tag badge-done">Strict Parity</span>
                </div>
                <div class="metric-master-val text-glow-green" id="s-gate-display">0 DRIFT</div>
                <div class="metric-details-list">
                    <div class="metric-detail-row">
                        <span>Target Compiler:</span>
                        <strong>MSVC 7.1 (cl.exe 13.10)</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Compiler Flags:</span>
                        <strong style="color: #23D160;">/MT /O2 (Release)</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Relocation Parity:</span>
                        <strong style="color: #00EDFF;">DIR32 / REL32 (100%)</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Collapsible Technical Ghidra Forensics Drawer -->
        <details class="forensics-drawer">
            <summary>
                <i class="fas fa-microscope" style="color: var(--accent-primary);"></i>
                <span>Technical PE Memory Forensics &amp; Raw Symbol Inventory (Click to Expand)</span>
                <span style="margin-left: auto; font-size: 0.8rem; color: #64748b;">Ghidra Database Telemetry</span>
            </summary>
            <div class="forensics-drawer-content">
                <div class="stats-block" style="margin: 0;">
                    <div class="glass-panel stat-card">
                        <span class="label">Functions Remaining</span>
                        <span class="value" id="s-unknown" style="color: #f8fafc;">870</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Thunks</span>
                        <span class="value" id="s-unknown-thunks" style="color: #f8fafc;">15</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Raw Data (DAT)</span>
                        <span class="value" id="s-unknown-dat" style="color: #f8fafc;">82,913</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Pointers (PTR)</span>
                        <span class="value" id="s-unknown-ptr" style="color: #f8fafc;">4,538</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">vTables</span>
                        <span class="value" id="s-unknown-vtables" style="color: #f8fafc;">63</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Floats</span>
                        <span class="value" id="s-unknown-floats" style="color: #f8fafc;">2,201</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Strings</span>
                        <span class="value" id="s-unknown-strings" style="color: #f8fafc;">12,115</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Cross-Function Edges</span>
                        <span class="value" id="s-call-edges" style="color: #5E69FF;">25,839</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Call / Jump Sites</span>
                        <span class="value" id="s-call-sites" style="color: #00EDFF;">1,475,711</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Relocations (DIR32)</span>
                        <span class="value" id="s-reloc-dir32" style="color: #23D160;">1,296 words</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Relocations (REL32)</span>
                        <span class="value" id="s-reloc-rel32" style="color: #FFB020;">128 spans</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Func-Pointer Words</span>
                        <span class="value" id="s-func-ptr-words" style="color: #f8fafc;">1,661</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Total DB Mods</span>
                        <span class="value" id="s-mods">22,232</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Recompiler Status</span>
                        <span class="value" id="s-recompiler-status" style="color: #00EDFF;">Testbench Active</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Compile Attempts</span>
                        <span class="value" id="s-recompiler-attempts">0</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Compile Errors</span>
                        <span class="value" id="s-compile-errors" style="color: #FF3366;">0</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Extracted Files</span>
                        <span class="value" id="s-extracted-files">220</span>
                    </div>
                </div>
            </div>
        </details>


        <!-- PSOBB MSVC 7.1 Autonomous Matching Testbench & Pipeline -->
        <div class="glass-panel workbench-panel tech-corners">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; border-bottom: 1px solid rgba(0, 237, 255, 0.15); padding-bottom: 16px; margin-bottom: 20px;">
                <div>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                        <h2 style="font-size: 1.4rem; margin: 0; color: #fff; letter-spacing: 2px;">MSVC 7.1 AUTONOMOUS MATCHING TESTBENCH</h2>
                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; background: rgba(35, 209, 96, 0.15); border: 1px solid rgba(35, 209, 96, 0.4); color: var(--accent-success); text-transform: uppercase;">
                            <span style="width: 8px; height: 8px; border-radius: 50%; background: var(--accent-success); box-shadow: 0 0 8px var(--accent-success); animation: smoothPulse 2s infinite;"></span>
                            CL.EXE 13.10.3077 PINNED // GATE PASSING
                        </span>
                    </div>
                    <p style="color: #94a3b8; font-size: 0.95rem; margin: 0;">Automated C++ Byte-Matching Pipeline • COFF Relocation Validation (DIR32 / REL32) • Whole-Image PE Link Gate</p>
                </div>
                <div style="display: flex; gap: 20px; text-align: right;">
                    <div>
                        <span style="color: #5E69FF; font-size: 0.75rem; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; display: block;">TARGET COMPILER</span>
                        <span style="font-family: 'JetBrains Mono', monospace; font-size: 1.25rem; font-weight: bold; color: var(--accent-success); text-shadow: 0 0 10px rgba(35, 209, 96, 0.4);">MSVC 7.1</span>
                    </div>
                    <div>
                        <span style="color: #5E69FF; font-size: 0.75rem; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; display: block;">FLAGS &amp; RUNTIME</span>
                        <span style="font-family: 'JetBrains Mono', monospace; font-size: 1.25rem; font-weight: bold; color: #00EDFF; text-shadow: 0 0 10px rgba(0, 237, 255, 0.4);">/MT /O2</span>
                    </div>
                </div>
            </div>

            <!-- Testbench Specs Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div style="background: rgba(6, 11, 30, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 14px;">
                    <span style="color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 4px;">LIVE BELT SCOPE</span>
                    <span style="font-family: 'JetBrains Mono', monospace; font-size: 1rem; color: #fff; font-weight: bold;">2,729 Functions</span>
                    <span style="color: #5E69FF; font-size: 0.8rem; display: block; margin-top: 2px;">0x00401000 - 0x00482B0C</span>
                </div>
                <div style="background: rgba(6, 11, 30, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 14px;">
                    <span style="color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 4px;">VERIFICATION STANDARD</span>
                    <span style="font-family: 'JetBrains Mono', monospace; font-size: 1rem; color: #fff; font-weight: bold;">1:1 Byte-Exact Match</span>
                    <span style="color: var(--accent-success); font-size: 0.8rem; display: block; margin-top: 2px;">COFF .obj Disassembly Parity</span>
                </div>
                <div style="background: rgba(6, 11, 30, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 14px;">
                    <span style="color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 4px;">RELOCATION PARITY</span>
                    <span style="font-family: 'JetBrains Mono', monospace; font-size: 1rem; color: #fff; font-weight: bold;">DIR32 &amp; REL32</span>
                    <span style="color: #00EDFF; font-size: 0.8rem; display: block; margin-top: 2px;">Data Pointers &amp; Call Displacements</span>
                </div>
                <div style="background: rgba(6, 11, 30, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 14px;">
                    <span style="color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 4px;">IN-FLIGHT COVERAGE</span>
                    <span id="workbench-banked-text" style="font-family: 'JetBrains Mono', monospace; font-size: 1rem; color: #fff; font-weight: bold;">481 / 2,729 (17.6%)</span>
                    <div style="width: 100%; height: 6px; background: rgba(255, 255, 255, 0.1); border-radius: 3px; margin-top: 6px; overflow: hidden;">
                        <div style="width: 17.6%; height: 100%; background: linear-gradient(90deg, #23D160, #00EDFF); border-radius: 3px;"></div>
                    </div>
                </div>
            </div>

            <!-- Centerpiece Function Matching Pipeline Visualizer -->
            <div class="decomp-pipeline-container">
                <div class="decomp-pipeline-header">
                    <div class="pipeline-status-badge">
                        <span class="pipeline-pulse-indicator"></span>
                        <span>CONTINUOUS FUNCTION MATCHING PIPELINE</span>
                    </div>
                    <div class="pipeline-rate-readout">
                        <span class="pipeline-rate-val">220 BANKED</span>
                        <span class="pipeline-rate-sub" id="workbench-near-text">+266 NEAR DRAFTS</span>
                    </div>
                </div>

                <div class="decomp-pipeline-flow">
                    <div class="pipeline-stage-node">
                        <div class="stage-step-num">01</div>
                        <div class="stage-node-icon"><i class="fas fa-search"></i></div>
                        <div class="stage-node-title">GHIDRA RECON</div>
                        <div class="stage-node-desc">PE VA Disassembly &amp; Reachability</div>
                    </div>

                    <div class="pipeline-flow-connector">
                        <i class="fas fa-chevron-right flow-arrow"></i>
                    </div>

                    <div class="pipeline-stage-node">
                        <div class="stage-step-num">02</div>
                        <div class="stage-node-icon"><i class="fas fa-code"></i></div>
                        <div class="stage-node-title">CLEAN C++ SOURCE</div>
                        <div class="stage-node-desc">Idiomatic Types &amp; SEH Blocks</div>
                    </div>

                    <div class="pipeline-flow-connector">
                        <i class="fas fa-chevron-right flow-arrow"></i>
                    </div>

                    <div class="pipeline-stage-node active-stage">
                        <div class="stage-step-num">03</div>
                        <div class="stage-node-icon"><i class="fas fa-cogs"></i></div>
                        <div class="stage-node-title">MSVC 7.1 COMPILER</div>
                        <div class="stage-node-desc">cl.exe 13.10.3077 /MT /O2</div>
                    </div>

                    <div class="pipeline-flow-connector">
                        <i class="fas fa-chevron-right flow-arrow"></i>
                    </div>

                    <div class="pipeline-stage-node">
                        <div class="stage-step-num">04</div>
                        <div class="stage-node-icon"><i class="fas fa-microchip"></i></div>
                        <div class="stage-node-title">COFF VERIFICATION</div>
                        <div class="stage-node-desc">DIR32 / REL32 Parity Engine</div>
                    </div>

                    <div class="pipeline-flow-connector">
                        <i class="fas fa-chevron-right flow-arrow"></i>
                    </div>

                    <div class="pipeline-stage-node success-stage">
                        <div class="stage-step-num">05</div>
                        <div class="stage-node-icon"><i class="fas fa-check-double"></i></div>
                        <div class="stage-node-title">IMAGE BUILD GATE</div>
                        <div class="stage-node-desc">Banked to src/ (0 Byte Drift)</div>
                    </div>
                </div>

                <div class="pipeline-footer-specs">
                    <span><i class="fas fa-file-code"></i> Target PE: <strong>PsoBB.exe (v125.13)</strong></span>
                    <span><i class="fas fa-layer-group"></i> Section: <strong>.text (sec0, raw 0x400)</strong></span>
                    <span><i class="fas fa-link"></i> Relocations: <strong>DIR32 &amp; REL32 Validated</strong></span>
                    <span><i class="fas fa-shield-alt"></i> Gate Policy: <strong>Zero-Tolerance Byte Drift</strong></span>
                </div>
            </div>

            <!-- Deep Dive Technical Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 15px;">
                <!-- Toolchain Spec Card -->
                <div style="background: rgba(6, 11, 30, 0.8); border: 1px solid rgba(0, 237, 255, 0.2); border-radius: 6px; padding: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.06); padding-bottom: 8px;">
                        <span style="font-family: 'JetBrains Mono', monospace; font-weight: bold; color: #00EDFF; font-size: 1rem;"><i class="fas fa-toolbox" style="margin-right: 6px;"></i> PINNED MSVC 7.1 TOOLCHAIN</span>
                        <span style="font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; background: rgba(0, 237, 255, 0.15); color: #00EDFF; font-weight: bold;">VERIFIED</span>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.9rem;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Compiler Binary:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;">CL.EXE (v13.10.3077)</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Codegen Passes:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;">C1.DLL / C1XX.DLL / C2.DLL</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Runtime Library:</span>
                            <span style="color: var(--accent-success); font-family: 'JetBrains Mono', monospace;">/MT (Static Multithreaded)</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Optimizations:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;">/O2 (Fast Code, Inlining, FPO)</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Target Architecture:</span>
                            <span style="color: #00EDFF; font-family: 'JetBrains Mono', monospace;">x86 IA-32 / Win32 PE</span>
                        </div>
                    </div>
                </div>

                <!-- Verification Spec Card -->
                <div style="background: rgba(6, 11, 30, 0.8); border: 1px solid rgba(94, 105, 255, 0.2); border-radius: 6px; padding: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.06); padding-bottom: 8px;">
                        <span style="font-family: 'JetBrains Mono', monospace; font-weight: bold; color: #5E69FF; font-size: 1rem;"><i class="fas fa-check-circle" style="margin-right: 6px;"></i> PE COFF VERIFICATION CRITERIA</span>
                        <span style="font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; background: rgba(94, 105, 255, 0.15); color: #5E69FF; font-weight: bold;">STRICT GATE</span>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.9rem;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Data Relocations:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;">DIR32 (0x06) Absolute VA</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Call Displacements:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;">REL32 (0x14) PC-Relative</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Floating Point:</span>
                            <span style="color: var(--accent-success); font-family: 'JetBrains Mono', monospace;">x87 FPU Precision Parity</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Exception Model:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;">Win32 FS:[0] SEH Handlers</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Match Tolerance:</span>
                            <span style="color: #5E69FF; font-family: 'JetBrains Mono', monospace;">0 Byte Drift on Banked Units</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Belt Function Inventory & Matching Explorer -->
        <div class="glass-panel tech-corners function-inventory-panel" id="function-inventory-panel">
            <div class="inventory-header-row">
                <div>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                        <h2 style="font-size: 1.35rem; margin: 0; color: #fff; letter-spacing: 1.5px;">
                            <i class="fas fa-database" style="color: #00EDFF; margin-right: 8px;"></i>LIVE BELT FUNCTION INVENTORY
                        </h2>
                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; background: rgba(35, 209, 96, 0.15); border: 1px solid rgba(35, 209, 96, 0.4); color: var(--accent-success); text-transform: uppercase;">
                            <span style="width: 8px; height: 8px; border-radius: 50%; background: var(--accent-success); box-shadow: 0 0 8px var(--accent-success);"></span>
                            <span id="inv-count-pill">220 BANKED MATCHES</span>
                        </span>
                    </div>
                    <p style="color: #94a3b8; font-size: 0.9rem; margin: 0;">
                        Interactive registry of 1:1 byte-matched C / C++ functions in the game belt (<code>0x00401000..0x00482B0C</code>). Filter by address, symbol name, or reverse-engineering description.
                    </p>
                </div>
                <div style="display: flex; gap: 15px; text-align: right;">
                    <div>
                        <span style="color: #5E69FF; font-size: 0.72rem; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; display: block;">TOTAL CODE BYTES</span>
                        <span style="font-family: 'JetBrains Mono', monospace; font-size: 1.15rem; font-weight: bold; color: #23D160; text-shadow: 0 0 10px rgba(35, 209, 96, 0.4);">5,211 B</span>
                    </div>
                    <div>
                        <span style="color: #5E69FF; font-size: 0.72rem; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; display: block;">AVG FUNCTION</span>
                        <span style="font-family: 'JetBrains Mono', monospace; font-size: 1.15rem; font-weight: bold; color: #00EDFF; text-shadow: 0 0 10px rgba(0, 237, 255, 0.4);">24.2 Bytes</span>
                    </div>
                </div>
            </div>

            <!-- Search and Filter Bar -->
            <div class="inventory-controls">
                <div class="inventory-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" id="inv-search-input" class="inventory-search-input" placeholder="Search address (0x0040...), symbol, or RE description..." oninput="handleInventorySearch()">
                </div>
                <div class="inventory-filter-group">
                    <button class="inventory-filter-btn active" data-filter="all" onclick="setInventoryFilter('all')">ALL (220)</button>
                    <button class="inventory-filter-btn" data-filter="c" onclick="setInventoryFilter('c')">C SOURCE (194)</button>
                    <button class="inventory-filter-btn" data-filter="cpp" onclick="setInventoryFilter('cpp')">C++ SOURCE (26)</button>
                </div>
                <div style="margin-left: auto; font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; color: #94a3b8;">
                    <span id="inv-filtered-count">Showing 220 functions</span>
                </div>
            </div>

            <!-- Table Container -->
            <div class="inventory-table-container">
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>VIRTUAL ADDR</th>
                            <th>GHIDRA SYMBOL / FUNCTION</th>
                            <th>SOURCE FILE</th>
                            <th>SIZE</th>
                            <th>FLAGS</th>
                            <th>MATCH STATUS</th>
                            <th style="text-align: right;">INSPECT</th>
                        </tr>
                    </thead>
                    <tbody id="inventory-tbody">
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 30px; color: #94a3b8; font-family: 'JetBrains Mono', monospace;">
                                <i class="fas fa-circle-notch fa-spin" style="margin-right: 8px; color: #00EDFF;"></i> Loading live function inventory...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div class="inventory-pagination-row">
                <div id="inv-page-info">Showing 1 to 15 of 220 functions</div>
                <div class="pagination-btn-group">
                    <button id="inv-prev-btn" class="page-nav-btn" onclick="prevInventoryPage()"><i class="fas fa-chevron-left"></i> Prev</button>
                    <span id="inv-page-display" style="font-family: 'JetBrains Mono', monospace; padding: 0 8px; color: #f8fafc;">Page 1 / 15</span>
                    <button id="inv-next-btn" class="page-nav-btn" onclick="nextInventoryPage()">Next <i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>

        <!-- Function Inspection Modal -->
        <div id="fn-modal" class="fn-modal-overlay" style="display: none;" onclick="closeFnModal(event)">
            <div class="fn-modal-card" onclick="event.stopPropagation()">
                <div class="fn-modal-header">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-family: 'JetBrains Mono', monospace; font-weight: bold; color: #00EDFF; font-size: 1.1rem;" id="fn-modal-addr">0x00401000</span>
                        <span class="inv-badge-match" id="fn-modal-status">100% Byte-Matched</span>
                    </div>
                    <button class="fn-modal-close" onclick="closeFnModal()"><i class="fas fa-times"></i></button>
                </div>
                <div class="fn-modal-body">
                    <div>
                        <span style="color: #94a3b8; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 2px;">RECONSTRUCTED SYMBOL</span>
                        <h3 style="margin: 0; color: #fff; font-family: 'JetBrains Mono', monospace; word-break: break-all;" id="fn-modal-name">InitFloatConstants_00a38230</h3>
                    </div>

                    <div class="fn-meta-grid">
                        <div class="fn-meta-box">
                            <span class="lbl">SOURCE FILE</span>
                            <span class="val" id="fn-modal-file" style="color: #23D160;">FUN_00401000.c</span>
                        </div>
                        <div class="fn-meta-box">
                            <span class="lbl">SIZE (BYTES)</span>
                            <span class="val" id="fn-modal-size" style="color: #00EDFF;">32 Bytes (0x20)</span>
                        </div>
                        <div class="fn-meta-box">
                            <span class="lbl">COMPILER FLAGS</span>
                            <span class="val" id="fn-modal-flags" style="color: #FFB020;">/MT /O2</span>
                        </div>
                        <div class="fn-meta-box">
                            <span class="lbl">VERDICT / PROVENANCE</span>
                            <span class="val" id="fn-modal-verdict" style="color: #a5b4fc;">reject-tethealla</span>
                        </div>
                    </div>

                    <div>
                        <span style="color: #94a3b8; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 6px;">PROTOTYPE</span>
                        <pre class="fn-code-box" id="fn-modal-proto">void FUN_00401000(void)</pre>
                    </div>

                    <div>
                        <span style="color: #94a3b8; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 6px;">REVERSE ENGINEERING NOTES</span>
                        <div class="fn-desc-box" id="fn-modal-desc">
                            Writes the IEEE-754 trio 1.0f (0x3f800000), 8.0f (0x41000000), 1.5f (0x3fc00000) into G_00a38230 / G_00a38234 / G_00a38238.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Impacts -->
        <div class="glass-panel tech-corners">
            <h2 style="padding: 24px; border-bottom: 1px solid rgba(0, 237, 255, 0.15); margin: 0;">Recent Significant Impacts</h2>
            <div class="recent-impacts" id="impact-feed">
                <div style="color: #94a3b8; text-align: center; padding: 20px; font-style: italic;">Awaiting database activity...</div>
            </div>
        </div>

        <!-- MSVC 2003 Build Output -->
        <div class="glass-panel tech-corners" style="margin-bottom: 24px;">
            <h2 style="padding: 24px; border-bottom: 1px solid rgba(255, 51, 102, 0.3); margin: 0; background: rgba(255, 51, 102, 0.05);">MSVC 2003 Build Output</h2>
            <div style="padding: 24px; overflow-y: auto; max-height: 400px; font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; color: #cbd5e1; background: #060B1E; margin: 15px; border-radius: 4px; border: 1px solid rgba(255, 51, 102, 0.2);">
                <pre id="msvc-feed" style="margin: 0; white-space: pre-wrap; word-wrap: break-word;">Awaiting compilation attempt...</pre>
            </div>
        </div>

        <!-- Live Cognitive Stream & Fullscreen Live Stream Theater -->
        <div class="glass-panel console-wrapper tech-corners" id="console-wrapper">
            <!-- Fullscreen Stream Theater Top HUD Bar -->
            <div class="stream-theater-header" id="stream-theater-header">
                <div class="stream-theater-left">
                    <div class="stream-badge-live">
                        <span class="stream-live-dot"></span>
                        <span class="stream-live-text" id="stream-live-status-text">LIVE DECOMP STREAM</span>
                    </div>
                    <div class="stream-target-hud" id="stream-target-hud">
                        <span class="stream-hud-label"><i class="fas fa-crosshairs"></i> TARGET:</span>
                        <span class="stream-target-addr" id="stream-target-addr">Scanning Binary Space...</span>
                        <span class="stream-target-tag" id="stream-target-tag">DISCOVERY</span>
                    </div>
                </div>

                <div class="stream-theater-center">
                    <div class="stream-decomp-strip">
                        <div class="decomp-mini-item">
                            <span class="c-lbl">BANKED:</span>
                            <span class="c-val" id="stream-hud-banked" style="color: #23D160;">220 / 2,729</span>
                        </div>
                        <div class="decomp-mini-divider">•</div>
                        <div class="decomp-mini-item">
                            <span class="c-lbl">RATIO:</span>
                            <span class="c-val" id="stream-hud-ratio">8.06%</span>
                        </div>
                        <div class="decomp-mini-divider">•</div>
                        <div class="decomp-mini-item">
                            <span class="c-lbl">NEAR DRAFTS:</span>
                            <span class="c-val" id="stream-hud-near" style="color: #00EDFF;">+266 fns</span>
                        </div>
                        <div class="decomp-mini-divider">•</div>
                        <div class="decomp-mini-item">
                            <span class="c-lbl">BUILD GATE:</span>
                            <span class="c-val" id="stream-hud-gate" style="color: #23D160;">PASSING</span>
                        </div>
                        <div class="decomp-mini-divider">•</div>
                        <div class="decomp-mini-item">
                            <span class="c-lbl">TOOLCHAIN:</span>
                            <span class="c-val" style="color: #FFB020;">MSVC 7.1</span>
                        </div>
                    </div>
                </div>

                <div class="stream-theater-right">
                    <div class="synaptic-eq" id="synaptic-eq" title="Live Synaptic Neural Frequency Equalizer">
                        <span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span>
                        <span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span>
                        <span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span>
                        <span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span>
                        <span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span><span class="eq-bar"></span>
                    </div>
                    <button id="stream-view-toggle-btn" onclick="toggleStreamViewMode()" class="hud-stream-ctrl-btn" title="Toggle between Theater Split Stage and Swarm Terminal Grid">
                        <i class="fas fa-th-large"></i> <span id="stream-view-toggle-text">Grid View</span>
                    </button>
                    <button id="stream-scroll-btn" onclick="toggleStreamAutoScroll()" class="hud-stream-ctrl-btn active" title="Toggle Auto-Scroll">
                        <i class="fas fa-arrow-down"></i> <span id="stream-scroll-text">Auto-Scroll: ON</span>
                    </button>
                    <button id="fs-toggle-btn" onclick="toggleConsoleFS()" class="hud-fs-btn">
                        <i class="fas fa-expand"></i> <span>Fullscreen Theater</span>
                    </button>
                </div>
            </div>

            <!-- Standard Swarm Terminal Grid -->
            <div class="swarm-grid" id="swarm-grid">
                <!-- Agent terminals injected here -->
            </div>

            <!-- Fullscreen Live Stream Theater Two-Pane Stage -->
            <div class="stream-theater-stage" id="stream-theater-stage">
                <!-- Left Column: WHAT THE DECOMPILER IS DOING -->
                <div class="stream-stage-col stream-stage-left">
                    <div class="stream-col-header">
                        <div class="col-title-group">
                            <h3 class="col-title"><i class="fas fa-brain" style="color: #00EDFF;"></i> WHAT THE DECOMPILER IS DOING</h3>
                            <span class="col-subtitle">Live Cognitive Chain-of-Thought • Ghidra Pseudocode • Forensics Dispatch</span>
                        </div>
                        <div class="stream-agent-tabs" id="stream-agent-tabs">
                            <button class="agent-tab active" onclick="filterStreamAgent('all')">ALL</button>
                            <button class="agent-tab" onclick="filterStreamAgent('master')">COMMANDER</button>
                            <button class="agent-tab" onclick="filterStreamAgent('1')">AGENT 1</button>
                            <button class="agent-tab" onclick="filterStreamAgent('2')">AGENT 2</button>
                        </div>
                    </div>
                    <div class="stream-feed-body" id="stream-cognitive-feed">
                        <div style="color: #64748b; text-align: center; padding: 40px; font-family: 'JetBrains Mono', monospace;">
                            <i class="fas fa-circle-notch fa-spin" style="margin-right: 8px; color: var(--accent-primary);"></i> Initializing Neural Cognitive Telemetry Feed...
                        </div>
                    </div>
                </div>

                <!-- Right Column: WHAT IT IS RENAMING & DESCRIBING -->
                <div class="stream-stage-col stream-stage-right">
                    <div class="stream-col-header">
                        <div class="col-title-group">
                            <h3 class="col-title"><i class="fas fa-file-code" style="color: #23D160;"></i> WHAT IT IS RENAMING &amp; DESCRIBING</h3>
                            <span class="col-subtitle">Committed Function/Data Renames • Method 1 Typed Prototypes • Engine Roles</span>
                        </div>
                        <div class="stream-stats-chip">
                            <span class="chip-label">COMMITTED:</span>
                            <span class="chip-val" id="stream-total-mods-badge">0</span>
                        </div>
                    </div>
                    <div class="stream-feed-body" id="stream-renames-feed">
                        <div style="color: #64748b; text-align: center; padding: 40px; font-family: 'JetBrains Mono', monospace;">
                            <i class="fas fa-circle-notch fa-spin" style="margin-right: 8px; color: var(--accent-success);"></i> Synchronizing Renames &amp; Architectural Descriptions...
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</main>

<script>
function toggleConsoleFS() {
    const wrapper = document.getElementById('console-wrapper');
    const btn = document.getElementById('fs-toggle-btn');
    wrapper.classList.toggle('fullscreen');
    if (wrapper.classList.contains('fullscreen')) {
        btn.innerHTML = '<i class="fas fa-compress"></i> <span>Exit Fullscreen (ESC)</span>';
        btn.style.borderColor = '#FF3366';
        btn.style.color = '#FF3366';
        if (typeof psoAudio !== 'undefined') psoAudio.playClick();
    } else {
        btn.innerHTML = '<i class="fas fa-expand"></i> <span>Fullscreen Theater</span>';
        btn.style.borderColor = 'var(--accent-primary)';
        btn.style.color = 'var(--accent-primary)';
        if (typeof psoAudio !== 'undefined') psoAudio.playClick();
    }
}

function openLiveStreamTheater() {
    const wrapper = document.getElementById('console-wrapper');
    if (!wrapper.classList.contains('fullscreen')) {
        toggleConsoleFS();
    }
    wrapper.scrollIntoView({ behavior: 'smooth' });
}

function toggleStreamViewMode() {
    const wrapper = document.getElementById('console-wrapper');
    const btn = document.getElementById('stream-view-toggle-text');
    wrapper.classList.toggle('view-grid-active');
    if (wrapper.classList.contains('view-grid-active')) {
        btn.textContent = 'Theater View';
    } else {
        btn.textContent = 'Grid View';
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === "Escape") {
        const wrapper = document.getElementById('console-wrapper');
        if (wrapper && wrapper.classList.contains('fullscreen')) {
            toggleConsoleFS();
        }
    }
});
</script>

<script src="/js/decryption.js?v=<?php echo time(); ?>"></script>

<?php include 'includes/footer.php'; ?>
