<?php
$page_title = 'Agent Decryption - PSOBB Private Server';
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

    /* Dual Strix Halo Compute Cluster Panel */
    .cluster-panel {
        padding: 24px;
        border: 1px solid rgba(0, 237, 255, 0.3);
        background: linear-gradient(145deg, rgba(10, 15, 38, 0.96), rgba(20, 15, 50, 0.9));
    }

    /* Centerpiece PCIe DMA Laser Data Highway */
    .dma-highway-container {
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

    .dma-highway-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }

    .dma-status-badge {
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

    .dma-pulse-indicator {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--accent-primary);
        box-shadow: 0 0 10px var(--accent-primary);
        animation: smoothPulse 1.5s infinite;
    }

    .dma-rate-readout {
        display: flex;
        align-items: baseline;
        gap: 8px;
        font-family: 'JetBrains Mono', monospace;
    }
    .dma-rate-val {
        font-size: 1.3rem;
        font-weight: bold;
        color: var(--accent-success);
        text-shadow: 0 0 10px rgba(35, 209, 96, 0.4);
    }
    .dma-rate-sub {
        font-size: 0.75rem;
        color: #94a3b8;
        letter-spacing: 1px;
    }

    /* Bus Visualizer */
    .dma-bus-visualizer {
        display: flex;
        align-items: center;
        gap: 15px;
        position: relative;
        padding: 10px 0;
    }

    .node-endpoint {
        padding: 10px 16px;
        background: rgba(10, 18, 42, 0.9);
        border: 1px solid rgba(0, 237, 255, 0.3);
        border-radius: 6px;
        min-width: 140px;
        text-align: center;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
    }
    .node-endpoint.left { border-color: rgba(0, 237, 255, 0.4); }
    .node-endpoint.right { border-color: rgba(94, 105, 255, 0.4); }

    .endpoint-name {
        font-family: 'JetBrains Mono', monospace;
        font-weight: bold;
        font-size: 0.95rem;
        color: #fff;
    }
    .node-endpoint.left .endpoint-name { color: #00EDFF; text-shadow: 0 0 8px rgba(0, 237, 255, 0.5); }
    .node-endpoint.right .endpoint-name { color: #5E69FF; text-shadow: 0 0 8px rgba(94, 105, 255, 0.5); }

    .endpoint-role {
        font-size: 0.7rem;
        color: #94a3b8;
        letter-spacing: 1px;
        margin-top: 4px;
        text-transform: uppercase;
    }

    /* Laser Channel */
    .dma-laser-channel {
        flex: 1;
        height: 48px;
        background: rgba(2, 6, 18, 0.8);
        border: 1px solid rgba(0, 237, 255, 0.15);
        border-radius: 24px;
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: center;
        box-shadow: inset 0 0 15px rgba(0, 237, 255, 0.1);
    }

    .laser-track {
        height: 2px;
        position: relative;
        background: linear-gradient(90deg, rgba(0, 237, 255, 0.1), rgba(0, 237, 255, 0.3), rgba(0, 237, 255, 0.1));
    }

    .laser-packet {
        position: absolute;
        top: -3px;
        height: 8px;
        border-radius: 4px;
        box-shadow: 0 0 10px currentColor;
    }

    .track-forward .packet-1 {
        width: 35px;
        background: #00EDFF;
        color: #00EDFF;
        animation: packetTravelForward 2.4s cubic-bezier(0.4, 0, 0.2, 1) infinite;
    }
    .track-forward .packet-2 {
        width: 25px;
        background: #23D160;
        color: #23D160;
        animation: packetTravelForward 2.4s cubic-bezier(0.4, 0, 0.2, 1) infinite 0.8s;
    }
    .track-forward .packet-3 {
        width: 30px;
        background: #00EDFF;
        color: #00EDFF;
        animation: packetTravelForward 2.4s cubic-bezier(0.4, 0, 0.2, 1) infinite 1.6s;
    }

    .track-reverse .packet-rev-1 {
        width: 30px;
        background: #5E69FF;
        color: #5E69FF;
        animation: packetTravelReverse 2.2s cubic-bezier(0.4, 0, 0.2, 1) infinite 0.4s;
    }
    .track-reverse .packet-rev-2 {
        width: 22px;
        background: #9d4edd;
        color: #9d4edd;
        animation: packetTravelReverse 2.2s cubic-bezier(0.4, 0, 0.2, 1) infinite 1.5s;
    }

    @keyframes packetTravelForward {
        0% { left: -5%; opacity: 0; }
        15% { opacity: 1; }
        85% { opacity: 1; }
        100% { left: 105%; opacity: 0; }
    }

    @keyframes packetTravelReverse {
        0% { right: -5%; opacity: 0; }
        15% { opacity: 1; }
        85% { opacity: 1; }
        100% { right: 105%; opacity: 0; }
    }

    .laser-center-badge {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: rgba(6, 12, 30, 0.95);
        border: 1px solid rgba(0, 237, 255, 0.3);
        padding: 3px 12px;
        border-radius: 12px;
        font-family: 'Share Tech Mono', monospace;
        font-size: 0.75rem;
        color: #00EDFF;
        letter-spacing: 1px;
        display: flex;
        align-items: center;
        gap: 6px;
        z-index: 2;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.6);
    }

    .dma-footer-specs {
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
    .dma-footer-specs span strong { color: #fff; }

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

    .stream-cluster-strip {
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
    .cluster-mini-item { display: flex; align-items: center; gap: 6px; }
    .cluster-mini-item .c-lbl { color: #64748b; }
    .cluster-mini-item .c-val { color: #00EDFF; font-weight: bold; }
    .cluster-mini-divider { color: rgba(255, 255, 255, 0.15); }

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

        .dma-bus-visualizer {
            flex-direction: column;
            gap: 10px;
        }
        .dma-laser-channel {
            width: 100%;
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
    .fill-active { width: 65%; background: linear-gradient(90deg, #00EDFF, #5E69FF); }
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
        <h1><div class="pulse-ring"></div> Agent Decryption Matrix</h1>
        <p style="color: #94a3b8; font-family: 'Exo 2', sans-serif; font-size: 1.1rem; max-width: 850px; margin-top: 10px; line-height: 1.6;">
            <strong>[PIONEER 2 LAB TRANSMISSION]</strong><br>
            Attention Hunters. Our autonomous analytics network is deployed to reverse-engineer the foundational architecture of the Pioneer project's archives, actively decompiling the <strong>Phantasy Star Online Blue Burst Client / Tethealla 125.13 binary</strong> into clean, idiomatic C++ using Microsoft Visual C++ Toolkit 2003 (<span style="color: #00EDFF;">CL.EXE</span>). What you are witnessing below is a live feed from the central AI cluster as it maps unknown structures, isolates legacy networking protocols, and stabilizes the combat data grid.
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
                <span style="color: #5E69FF; font-size: 0.8rem; letter-spacing: 2px; text-transform: uppercase; font-weight: bold; display: block;">AGENT STATUS</span>
                <span id="m-status" style="font-family: 'JetBrains Mono', monospace; font-size: 1.2rem; font-weight: bold; color: #23D160; text-shadow: 0 0 10px rgba(35, 209, 96, 0.4);">Initializing...</span>
            </div>
            <div style="text-align: right;">
                <span style="color: #5E69FF; font-size: 0.8rem; letter-spacing: 2px; text-transform: uppercase; font-weight: bold; display: block;">AI ENGINE</span>
                <span id="m-model" style="font-family: 'JetBrains Mono', monospace; font-size: 1.2rem; font-weight: bold; color: #00EDFF; text-shadow: 0 0 10px rgba(0, 237, 255, 0.4);">Detecting...</span>
            </div>
            <div style="text-align: right;">
                <span style="color: #5E69FF; font-size: 0.8rem; letter-spacing: 2px; text-transform: uppercase; font-weight: bold; display: block;">EST. TIME REMAINING</span>
                <span id="m-eta" style="font-family: 'JetBrains Mono', monospace; font-size: 1.2rem; font-weight: bold; color: var(--accent-success); text-shadow: 0 0 10px rgba(35, 209, 96, 0.4);">Calculating...</span>
            </div>
        </div>

        
        <!-- Executive Project Overview & Current Position Hero -->
        <div class="glass-panel exec-hero-card tech-corners">
            <div class="hero-top-row">
                <div class="hero-badge-group">
                    <span class="pso-status-pill"><span class="pill-dot"></span> LIVE DECOMPILATION CLUSTER</span>
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
                    <h2>PSOBB Client Reverse-Engineering Project</h2>
                    <p class="hero-description-text">
                        We are reverse-engineering the complete <strong>Phantasy Star Online Blue Burst (v125.13)</strong> client binary (<code>psobb.exe</code>) into clean, matching C++ source code. Achieving a bit-exact decompilation unlocks a fully native, standalone modern client for <strong>64-bit Windows, Linux, and Steam Deck</strong> with high framerates, widescreen UI scaling, and modern controller mapping.
                    </p>
                    <div class="hero-key-takeaways">
                        <div class="takeaway-card success">
                            <span class="takeaway-lbl">CURRENT PROGRESS</span>
                            <span class="takeaway-val text-glow-green" id="takeaway-pct">95.6%</span>
                            <span class="takeaway-sub" id="takeaway-solved-sub">18,790 of 19,660 Functions Solved</span>
                        </div>
                        <div class="takeaway-card highlight">
                            <span class="takeaway-lbl">WORK REMAINING</span>
                            <span class="takeaway-val text-glow-cyan" id="takeaway-remaining">870 fns</span>
                            <span class="takeaway-sub">Final Unknown Stub Functions</span>
                        </div>
                        <div class="takeaway-card">
                            <span class="takeaway-lbl">ACTIVE FRONTIER</span>
                            <span class="takeaway-val" style="color: #a5b4fc; font-size: 1.15rem;">Phase 2: Types</span>
                            <span class="takeaway-sub">Trade Windows & Entity Structs</span>
                        </div>
                    </div>
                </div>

                <div class="hero-progress-ring-card">
                    <div class="circular-progress" id="progress-circle" style="--percentage: 95.6;">
                        <div class="progress-value">
                            <span id="progress-text">95.6%</span>
                            <span class="progress-label">TOTAL SOLVED</span>
                        </div>
                    </div>
                    <div class="hero-progress-breakdown">
                        <div class="breakdown-row">
                            <span style="color: #94a3b8;">Solved Functions:</span>
                            <span id="hero-solved-fns" style="color: #23D160; font-weight: bold;">18,790</span>
                        </div>
                        <div class="breakdown-row">
                            <span style="color: #94a3b8;">Remaining Functions:</span>
                            <span id="hero-remaining-fns" style="color: #00EDFF; font-weight: bold;">870</span>
                        </div>
                        <div class="breakdown-row">
                            <span style="color: #94a3b8;">Total Mod DB:</span>
                            <span id="hero-total-mods" style="color: #a5b4fc; font-weight: bold;">22,232</span>
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
                        <span class="roadmap-stage-tag badge-done"><i class="fas fa-check"></i> 95.6% Solved</span>
                    </div>
                    <h3 class="roadmap-card-title">Binary Disassembly &amp; Function Mapping</h3>
                    <p class="roadmap-card-desc">Disassembling the entire <code>psobb.exe</code> PE binary into readable assembly, generating control flow graphs, and assigning human semantic names.</p>
                    <div class="roadmap-card-tasks">
                        <div class="task-item done">
                            <i class="fas fa-check-circle"></i>
                            <span>18,790 functions identified and decompiled into C++</span>
                        </div>
                        <div class="task-item done">
                            <i class="fas fa-check-circle"></i>
                            <span>Core game loops, math matrices, and renderer mapped</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-hourglass-half"></i>
                            <span>Final 870 stub functions remaining to identify</span>
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
                        <span class="roadmap-stage-tag badge-active"><i class="fas fa-bolt"></i> Active Frontier</span>
                    </div>
                    <h3 class="roadmap-card-title">Type Reconstruction &amp; Memory Structs</h3>
                    <p class="roadmap-card-desc">Replacing raw memory offsets (<code>DAT_...</code>, <code>PTR_...</code>) with true typed C++ structs for player entities, inventory items, and packet handlers.</p>
                    <div class="roadmap-card-tasks">
                        <div class="task-item done">
                            <i class="fas fa-check-circle"></i>
                            <span>22,232 structs, types, and variables committed</span>
                        </div>
                        <div class="task-item active-now">
                            <i class="fas fa-arrow-right"></i>
                            <span>Current Focus: Trade Window state &amp; Packet 0x60 / 0xA6</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-hourglass-half"></i>
                            <span>63 vTables and entity component layouts to finalize</span>
                        </div>
                    </div>
                    <div class="roadmap-mini-meter">
                        <div class="roadmap-mini-fill fill-active" id="meter-phase-2"></div>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="roadmap-card stage-next">
                    <div class="roadmap-card-head">
                        <span class="roadmap-stage-num">PHASE 03</span>
                        <span class="roadmap-stage-tag badge-next"><i class="fas fa-microchip"></i> In Testbench</span>
                    </div>
                    <h3 class="roadmap-card-title">MSVC 2003 Matching Compilation</h3>
                    <p class="roadmap-card-desc">Recompiling the extracted C++ source using the exact compiler used in 2004 (Microsoft Visual C++ Toolkit 2003 <code>cl.exe</code>) to match original binary assembly.</p>
                    <div class="roadmap-card-tasks">
                        <div class="task-item done">
                            <i class="fas fa-check-circle"></i>
                            <span>MSVC 7.1 build environment configured &amp; automated</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-hourglass-half"></i>
                            <span>Resolve header forward declarations and cyclical deps</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-hourglass-half"></i>
                            <span>Achieve 1:1 bit-exact binary matching against original</span>
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
                    <p class="roadmap-card-desc">Untangling the monolithic client into clean, decoupled C++ subsystems with modern interfaces for networking, rendering, audio, and UI.</p>
                    <div class="roadmap-card-tasks">
                        <div class="task-item remaining">
                            <i class="fas fa-circle-notch"></i>
                            <span><code>psobb-net</code>: Isolate NewServ client network protocol</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-circle-notch"></i>
                            <span><code>psobb-render</code>: Abstract D3D8 into modern D3D11/Vulkan</span>
                        </div>
                        <div class="task-item remaining">
                            <i class="fas fa-circle-notch"></i>
                            <span><code>psobb-audio</code>: Integrate OpenAL-Soft / DSOAL 3D sound</span>
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
                        <span>16,243 Functions • MSVC 7.1</span>
                        <span id="text-pct" style="color: #00EDFF; font-weight: bold;">95.4%</span>
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
            <!-- Metric 1: Functions -->
            <div class="metric-master-card">
                <div class="metric-master-head">
                    <span class="metric-master-title"><i class="fas fa-code"></i> Functions Solved</span>
                    <span class="roadmap-stage-tag badge-done">95.6%</span>
                </div>
                <div class="metric-master-val text-glow-green" id="s-solved-fns-display">18,790</div>
                <div class="metric-details-list">
                    <div class="metric-detail-row">
                        <span>Total Scope:</span>
                        <strong id="s-total-fns-display">19,660</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Remaining Unknowns:</span>
                        <strong id="s-remaining-fns-display" style="color: #00EDFF;">870</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Unknown Thunks:</span>
                        <strong id="s-unknown-thunks-display">15</strong>
                    </div>
                </div>
            </div>

            <!-- Metric 2: Types & Structures -->
            <div class="metric-master-card">
                <div class="metric-master-head">
                    <span class="metric-master-title"><i class="fas fa-database"></i> Committed Types</span>
                    <span class="roadmap-stage-tag badge-active">Active</span>
                </div>
                <div class="metric-master-val text-glow-cyan" id="s-mods-display">22,232</div>
                <div class="metric-details-list">
                    <div class="metric-detail-row">
                        <span>Remaining vTables:</span>
                        <strong id="s-unknown-vtables-display">63</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Remaining Pointers:</span>
                        <strong id="s-unknown-ptr-display">4,538</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Strings Mapped:</span>
                        <strong id="s-unknown-strings-display">12,115</strong>
                    </div>
                </div>
            </div>

            <!-- Metric 3: AI Swarm Telemetry -->
            <div class="metric-master-card">
                <div class="metric-master-head">
                    <span class="metric-master-title"><i class="fas fa-brain"></i> Autonomous Swarm</span>
                    <span class="roadmap-stage-tag badge-active">Live</span>
                </div>
                <div class="metric-master-val text-glow-purple" id="s-tps-display">560.4 t/s</div>
                <div class="metric-details-list">
                    <div class="metric-detail-row">
                        <span>Active Model:</span>
                        <strong id="s-model-display">Qwen 3.8 Flash Distributed</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Tokens Processed:</span>
                        <strong id="s-tokens-display">383,700,597</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Swarm Loops:</span>
                        <strong id="s-batch-display">14,274</strong>
                    </div>
                </div>
            </div>

            <!-- Metric 4: Cluster Interconnect -->
            <div class="metric-master-card">
                <div class="metric-master-head">
                    <span class="metric-master-title"><i class="fas fa-network-wired"></i> Compute Cluster</span>
                    <span class="roadmap-stage-tag badge-done">80G Link</span>
                </div>
                <div class="metric-master-val" style="color: #fff;" id="s-dma-display">368.4 MB/s</div>
                <div class="metric-details-list">
                    <div class="metric-detail-row">
                        <span>Hardware:</span>
                        <strong>Dual AMD Strix Halo</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>Unified Memory:</span>
                        <strong style="color: #23D160;">256 GB LPDDR5X</strong>
                    </div>
                    <div class="metric-detail-row">
                        <span>RPC Latency:</span>
                        <strong style="color: #00EDFF;">0.11 ms</strong>
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
                        <span class="label">Tokens Burned</span>
                        <span class="value" id="s-tokens" style="color: #5E69FF;">383,700,597</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Tokens/sec</span>
                        <span class="value" id="s-tps" style="color: #23D160;">560.4</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">Total DB Mods</span>
                        <span class="value" id="s-mods">22,232</span>
                    </div>
                    <div class="glass-panel stat-card">
                        <span class="label">AI Loops</span>
                        <span class="value" id="s-batch" style="color: #5E69FF;">14,274</span>
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
                        <span class="value" id="s-extracted-files">0</span>
                    </div>
                </div>
            </div>
        </details>


        <!-- Dual Strix Halo Compute Cluster Panel -->
        <div class="glass-panel cluster-panel tech-corners">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; border-bottom: 1px solid rgba(0, 237, 255, 0.15); padding-bottom: 16px; margin-bottom: 20px;">
                <div>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                        <h2 style="font-size: 1.4rem; margin: 0; color: #fff; letter-spacing: 2px;">DUAL AMD STRIX HALO COMPUTE CLUSTER</h2>
                        <span style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; background: rgba(35, 209, 96, 0.15); border: 1px solid rgba(35, 209, 96, 0.4); color: var(--accent-success); text-transform: uppercase;">
                            <span style="width: 8px; height: 8px; border-radius: 50%; background: var(--accent-success); box-shadow: 0 0 8px var(--accent-success); animation: smoothPulse 2s infinite;"></span>
                            2 Nodes Online
                        </span>
                    </div>
                    <p style="color: #94a3b8; font-size: 0.95rem; margin: 0;">256 GB Unified LPDDR5X Memory • 80 Gbps Dual USB4 PCIe DMA • Distributed <code>rpc-tensor</code> Split</p>
                </div>
                <div style="display: flex; gap: 20px; text-align: right;">
                    <div>
                        <span style="color: #5E69FF; font-size: 0.75rem; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; display: block;">INFERENCE SPEED</span>
                        <span id="cluster-tps" style="font-family: 'JetBrains Mono', monospace; font-size: 1.3rem; font-weight: bold; color: var(--accent-success); text-shadow: 0 0 10px rgba(35, 209, 96, 0.4);">25.9 t/s</span>
                    </div>
                    <div>
                        <span style="color: #5E69FF; font-size: 0.75rem; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; display: block;">TIME TO FIRST TOKEN</span>
                        <span id="cluster-ttft" style="font-family: 'JetBrains Mono', monospace; font-size: 1.3rem; font-weight: bold; color: #00EDFF; text-shadow: 0 0 10px rgba(0, 237, 255, 0.4);">4.96s</span>
                    </div>
                </div>
            </div>

            <!-- Cluster Specs Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px;">
                <div style="background: rgba(6, 11, 30, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 14px;">
                    <span style="color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 4px;">ACTIVE MODEL</span>
                    <span style="font-family: 'JetBrains Mono', monospace; font-size: 1rem; color: #fff; font-weight: bold;">Qwen 3.8 Flash Next</span>
                    <span style="color: #5E69FF; font-size: 0.8rem; display: block; margin-top: 2px;">176B Parameters (Q4_K_M)</span>
                </div>
                <div style="background: rgba(6, 11, 30, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 14px;">
                    <span style="color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 4px;">CONTEXT SCOPE</span>
                    <span style="font-family: 'JetBrains Mono', monospace; font-size: 1rem; color: #fff; font-weight: bold;">262,144 Tokens</span>
                    <span style="color: var(--accent-success); font-size: 0.8rem; display: block; margin-top: 2px;">Full Binary Scope</span>
                </div>
                <div style="background: rgba(6, 11, 30, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 14px;">
                    <span style="color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 4px;">DMA INTERCONNECT</span>
                    <span style="font-family: 'JetBrains Mono', monospace; font-size: 1rem; color: #fff; font-weight: bold;">80 Gbps Dual 40G USB4</span>
                    <span id="cluster-dma-rate" style="color: #00EDFF; font-size: 0.8rem; display: block; margin-top: 2px;">PCIe DMA Stream Active</span>
                </div>
                <div style="background: rgba(6, 11, 30, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 14px;">
                    <span style="color: #94a3b8; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 4px;">AGGREGATE MEMORY</span>
                    <span id="cluster-mem-text" style="font-family: 'JetBrains Mono', monospace; font-size: 1rem; color: #fff; font-weight: bold;">107.4 / 256 GB</span>
                    <div style="width: 100%; height: 6px; background: rgba(255, 255, 255, 0.1); border-radius: 3px; margin-top: 6px; overflow: hidden;">
                        <div id="cluster-mem-bar" style="width: 42%; height: 100%; background: linear-gradient(90deg, #5E69FF, #00EDFF); border-radius: 3px;"></div>
                    </div>
                </div>
            </div>

            <!-- Centerpiece Animated PCIe DMA Laser Data Highway -->
            <div class="dma-highway-container">
                <div class="dma-highway-header">
                    <div class="dma-status-badge">
                        <span class="dma-pulse-indicator"></span>
                        <span>80 Gbps DUAL USB4 PCIe DMA BUS ACTIVE</span>
                    </div>
                    <div class="dma-rate-readout">
                        <span class="dma-rate-val" id="dma-bus-speed">368.4 MB/s</span>
                        <span class="dma-rate-sub">RPC-TENSOR PIPELINE</span>
                    </div>
                </div>

                <div class="dma-bus-visualizer">
                    <div class="node-endpoint left">
                        <div class="endpoint-name">bosgame1</div>
                        <div class="endpoint-role">MASTER (LAYERS 0-47)</div>
                    </div>

                    <div class="dma-laser-channel">
                        <div class="laser-track track-forward">
                            <div class="laser-packet packet-1"></div>
                            <div class="laser-packet packet-2"></div>
                            <div class="laser-packet packet-3"></div>
                        </div>
                        <div class="laser-center-badge">
                            <i class="fas fa-bolt"></i> <span>/dev/tbstream0 ⇄ /dev/tbstream1</span>
                        </div>
                        <div class="laser-track track-reverse">
                            <div class="laser-packet packet-rev-1"></div>
                            <div class="laser-packet packet-rev-2"></div>
                        </div>
                    </div>

                    <div class="node-endpoint right">
                        <div class="endpoint-name">bosgame2</div>
                        <div class="endpoint-role">WORKER (LAYERS 48-95)</div>
                    </div>
                </div>

                <div class="dma-footer-specs">
                    <span><i class="fas fa-wave-square"></i> RPC Latency: <strong>0.11 ms</strong></span>
                    <span><i class="fas fa-memory"></i> Unified LPDDR5X: <strong>256 GB</strong></span>
                    <span><i class="fas fa-network-wired"></i> Interconnect: <strong>80 Gbps Full Duplex</strong></span>
                    <span><i class="fas fa-microchip"></i> Partition: <strong>48 / 48 Layer Split</strong></span>
                </div>
            </div>

            <!-- Nodes Sub-Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 15px;">
                <!-- Node 1 -->
                <div style="background: rgba(6, 11, 30, 0.8); border: 1px solid rgba(0, 237, 255, 0.2); border-radius: 6px; padding: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.06); padding-bottom: 8px;">
                        <span style="font-family: 'JetBrains Mono', monospace; font-weight: bold; color: #00EDFF; font-size: 1rem;">NODE 1: bosgame1</span>
                        <span style="font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; background: rgba(0, 237, 255, 0.15); color: #00EDFF; font-weight: bold;">MASTER LEADER</span>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.9rem;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Compute:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;">AMD Ryzen AI Max+ 395 (32T)</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Graphics:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;">AMD Radeon 8060S (49.6 GB VRAM)</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Unified Memory:</span>
                            <span id="n1-mem" style="color: var(--accent-success); font-family: 'JetBrains Mono', monospace;">53.7 / 128 GB</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Thermals / Power:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;"><span id="n1-temp">53°C</span> • <span id="n1-watts">29.3W</span></span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">CPU / GPU Load:</span>
                            <span style="color: #00EDFF; font-family: 'JetBrains Mono', monospace;"><span id="n1-cpu-load">0.5%</span> CPU • <span id="n1-gpu-busy">0%</span> GPU</span>
                        </div>
                    </div>
                </div>

                <!-- Node 2 -->
                <div style="background: rgba(6, 11, 30, 0.8); border: 1px solid rgba(94, 105, 255, 0.2); border-radius: 6px; padding: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.06); padding-bottom: 8px;">
                        <span style="font-family: 'JetBrains Mono', monospace; font-weight: bold; color: #5E69FF; font-size: 1rem;">NODE 2: bosgame2</span>
                        <span style="font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; background: rgba(94, 105, 255, 0.15); color: #5E69FF; font-weight: bold;">RPC WORKER</span>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.9rem;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Compute:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;">AMD Ryzen AI Max+ 395 (32T)</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Graphics:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;">AMD Radeon 8060S (49.6 GB VRAM)</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Unified Memory:</span>
                            <span id="n2-mem" style="color: var(--accent-success); font-family: 'JetBrains Mono', monospace;">53.7 / 128 GB</span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">Thermals / Power:</span>
                            <span style="color: #fff; font-family: 'JetBrains Mono', monospace;"><span id="n2-temp">53°C</span> • <span id="n2-watts">29.3W</span></span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #94a3b8;">CPU / GPU Load:</span>
                            <span style="color: #5E69FF; font-family: 'JetBrains Mono', monospace;"><span id="n2-cpu-load">3.8%</span> CPU • <span id="n2-gpu-busy">0%</span> GPU</span>
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
                    <div class="stream-cluster-strip">
                        <div class="cluster-mini-item">
                            <span class="c-lbl">CLUSTER TPS:</span>
                            <span class="c-val" id="stream-hud-tps">--</span>
                        </div>
                        <div class="cluster-mini-divider">•</div>
                        <div class="cluster-mini-item">
                            <span class="c-lbl">TTFT:</span>
                            <span class="c-val" id="stream-hud-ttft">--</span>
                        </div>
                        <div class="cluster-mini-divider">•</div>
                        <div class="cluster-mini-item">
                            <span class="c-lbl">USB4 DMA:</span>
                            <span class="c-val" id="stream-hud-dma" style="color: #23D160;">80G LINK</span>
                        </div>
                        <div class="cluster-mini-divider">•</div>
                        <div class="cluster-mini-item">
                            <span class="c-lbl">STRIX HALO VRAM:</span>
                            <span class="c-val" id="stream-hud-mem">--</span>
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
