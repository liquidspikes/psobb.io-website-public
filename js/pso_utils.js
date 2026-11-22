/**
 * PSOBB Website Shared Frontend & Admin Utilities
 * Loaded synchronously before main.js and page-specific scripts.
 */
(function () {
    'use strict';

    function escapeHtml(val) {
        if (val === null || val === undefined) return '';
        return String(val)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getCSRFToken() {
        if (typeof document === 'undefined') return '';
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '').replace(/[\r\n\t ]/g, '') : '';
    }

    function psoGetArchetype(charClass) {
        if (!charClass) return '';
        const u = String(charClass).toUpperCase();
        if (u.startsWith('HU')) return 'HU';
        if (u.startsWith('RA')) return 'RA';
        if (u.startsWith('FO')) return 'FO';
        return '';
    }

    function psoFormatTimeAgo(dbTimeStr) {
        if (!dbTimeStr) return '';
        const raw = String(dbTimeStr).trim();
        const utcStr = raw.includes('T') ? raw : (raw.replace(' ', 'T') + 'Z');
        const diffMs = Date.now() - new Date(utcStr).getTime();
        if (!Number.isFinite(diffMs)) return '';
        const mins = Math.max(1, Math.floor(diffMs / 60000));
        if (mins < 60) return `${mins}m ago`;
        const hours = Math.floor(mins / 60);
        if (hours < 24) return `${hours}h ago`;
        return `${Math.floor(hours / 24)}d ago`;
    }

    function createFireworks(overlayId = 'drop-animation-overlay') {
        if (typeof document === 'undefined' || typeof window === 'undefined') return;
        const overlay = document.getElementById(overlayId);
        if (!overlay) return;
        const colors = ['#ff4444', '#33b5e5', '#00C851', '#ffaa00', '#aa66cc', '#ffffff'];

        for (let b = 0; b < 6; b++) {
            setTimeout(() => {
                const centerX = window.innerWidth / 2 + (Math.random() - 0.5) * 600;
                const centerY = window.innerHeight / 2 + (Math.random() - 0.5) * 500 - 150;

                for (let i = 0; i < 80; i++) {
                    const particle = document.createElement('div');
                    particle.style.position = 'absolute';
                    particle.style.left = centerX + 'px';
                    particle.style.top = centerY + 'px';
                    particle.style.width = (Math.random() * 8 + 4) + 'px';
                    particle.style.height = particle.style.width;
                    particle.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                    particle.style.borderRadius = '50%';
                    particle.style.pointerEvents = 'none';
                    particle.style.zIndex = '9998';
                    particle.style.boxShadow = `0 0 15px ${particle.style.backgroundColor}, 0 0 30px ${particle.style.backgroundColor}`;

                    overlay.appendChild(particle);

                    const angle = Math.random() * Math.PI * 2;
                    const velocity = 100 + Math.random() * 300;
                    const tx = Math.cos(angle) * velocity;
                    const ty = Math.sin(angle) * velocity;

                    particle.animate([
                        { transform: 'translate(0,0) scale(1)', opacity: 1 },
                        { transform: `translate(${tx}px, ${ty}px) scale(0)`, opacity: 0 }
                    ], {
                        duration: 1000 + Math.random() * 800,
                        easing: 'cubic-bezier(0.25, 1, 0.5, 1)',
                        fill: 'forwards'
                    });

                    setTimeout(() => { if (particle.parentNode) particle.remove(); }, 2000);
                }
            }, b * 300);
        }
    }

    async function psoExecAdminCommand(cmd, outEl, endpointUrl = '/api/admin_exec.php', labels = {}) {
        if (!outEl) return null;
        const promptSuffix = labels.promptSuffix || '';
        const trail = labels.trailingNewline ? '\n' : '';
        const errorPrefix = labels.errorPrefix || 'Error: ';
        const connFailed = labels.connFailed || 'Connection Failed';

        outEl.textContent += `\n> ${cmd}${promptSuffix}`;
        outEl.scrollTop = outEl.scrollHeight;

        let data = null;
        try {
            const res = await fetch(endpointUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCSRFToken() },
                body: JSON.stringify({ command: cmd })
            });
            data = await res.json();

            if (data.result) {
                outEl.textContent += `\n${data.result}${trail}`;
            } else if (data.error) {
                outEl.textContent += `\n${errorPrefix}${data.error}${trail}`;
            }
        } catch (e) {
            outEl.textContent += `\n${connFailed}${labels.includeErrorInCatch ? e : ''}${trail}`;
        }
        outEl.scrollTop = outEl.scrollHeight;
        return data;
    }

    const root = typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : null);
    if (root) {
        root.escapeHtml = escapeHtml;
        root.escHtml = escapeHtml;
        root.getCSRFToken = getCSRFToken;
        root.psoGetArchetype = psoGetArchetype;
        root.psoFormatTimeAgo = psoFormatTimeAgo;
        root.createFireworks = createFireworks;
        root.psoExecAdminCommand = psoExecAdminCommand;
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            escapeHtml,
            escHtml: escapeHtml,
            getCSRFToken,
            psoGetArchetype,
            psoFormatTimeAgo,
            createFireworks,
            psoExecAdminCommand,
        };
    }
})();
