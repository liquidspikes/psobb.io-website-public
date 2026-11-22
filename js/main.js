// psobb-website/js/main.js
/**
 * PSOBB Website Frontend Logic
 * 
 * Handles all client-side dynamic behavior including:
 * - Session Management (Login, Logout, Dashboard State)
 * - Server Telemetry Fetching (Player counts, Active Games)
 * - DOM manipulation and layout animation (Intersection Observers)
 */

function _t(str) {
    return (typeof window.__ === 'function') ? window.__(str) : str;
}

(function initPlayerErrorLogger() {
    const seenKeys = new Set();
    let sentCount = 0;

    function isIgnoredClientError(msgStr, src, stack) {
        if (src && (/^(?:chrome|moz|safari|edge)-extension:\/\//i.test(src) || src.indexOf('/api/log_client_error.php') !== -1)) {
            return true;
        }
        if (stack && (/(?:chrome|moz|safari|edge)-extension:\/\//i.test(stack) || stack.indexOf('/api/log_client_error.php') !== -1)) {
            return true;
        }
        if (msgStr.indexOf('ResizeObserver loop') !== -1) {
            return true;
        }
        if (/\b(?:window\.ethereum|ethereum\.selectedAddress|__firefox__|__crWeb|webkit\.messageHandlers)\b/i.test(`${msgStr} ${stack}`)) {
            return true;
        }
        return (msgStr === 'Script error.' || msgStr === 'Script error') && !src && !stack;
    }

    function sendReport(kind, message, meta) {
        if (sentCount >= 5) return;
        const msgStr = String(message || '').trim().slice(0, 400);
        if (!msgStr) return;
        const src = String((meta && meta.source) || '').slice(0, 240);
        const stack = String((meta && meta.stack) || '').slice(0, 400);
        if (isIgnoredClientError(msgStr, src, stack)) return;

        const key = `${kind}|${msgStr}|${src}|${(meta && meta.line) || 0}`;
        if (seenKeys.has(key)) return;
        seenKeys.add(key);
        sentCount++;

        const payload = JSON.stringify({
            kind,
            message: msgStr,
            page: (window.location.pathname || '') + (window.location.search || ''),
            source: src,
            line: (meta && meta.line) || 0,
            col: (meta && meta.col) || 0,
            stack
        });

        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon('/api/log_client_error.php', new Blob([payload], { type: 'application/json' }));
                return;
            }
        } catch (_) { }

        fetch('/api/log_client_error.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: payload,
            keepalive: true
        }).catch(() => { });
    }

    window.reportPlayerError = function (message, extra) {
        sendReport('client_js_error', message, extra || {});
    };

    window.addEventListener('error', (event) => {
        if (!event || !event.message) return;
        sendReport('client_js_error', event.message, {
            source: event.filename || '',
            line: event.lineno || 0,
            col: event.colno || 0,
            stack: event.error && event.error.stack ? event.error.stack : ''
        });
    });

    window.addEventListener('unhandledrejection', (event) => {
        const reason = event ? event.reason : null;
        const msg = reason instanceof Error ? reason.message : (typeof reason === 'string' ? reason : 'Unhandled promise rejection');
        sendReport('client_promise_rejection', msg, {
            source: window.location.pathname || '',
            stack: reason && reason.stack ? reason.stack : ''
        });
    });
})();

document.addEventListener('DOMContentLoaded', () => {
    // Detect PWA Standalone Mode
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (isStandalone) {
        document.body.classList.add('pwa-standalone');
        const installCard = document.getElementById('pwa-install-card');
        if (installCard) {
            installCard.style.setProperty('display', 'none', 'important');
        }
    }

    fetchServerStats();

    // Refresh stats every 30 seconds
    setInterval(fetchServerStats, 30000);

    // If we are on the stats page, load detailed stats
    if (document.getElementById('player-list')) {
        fetchDetailedStats();
        setInterval(fetchDetailedStats, 30000);
    }

    // Login Form Handler
    const loginForm = document.querySelector('.login-form');
    if (loginForm) {
        loginForm.onsubmit = handleLogin;
    }

    // Check if logged in (for login page)
    if (document.getElementById('dashboard')) {
        checkLoginStatus();
    }

    // Global login UI updates
    const userStr = sessionStorage.getItem('psobb_user');
    if (userStr) {
        try {
            const userData = JSON.parse(userStr);
            updateHeaderNav(userData);
        } catch (e) { }
    }

    // Initialize Star Stream
    // initStarStream();

    // Scroll Animation Observer
    const observerOptions = {
        threshold: 0.1,
        rootMargin: "0px 0px -50px 0px"
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-fade-in');
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);

    // Observe news items and widgets
    document.querySelectorAll('.news-item, .sidebar-widget, .server-status-widget').forEach(el => {
        el.style.opacity = '0'; // Initial hidden state
        observer.observe(el);
    });

    // Mobile Menu Toggle
    const mobileMenu = document.getElementById('mobile-menu');
    const navUl = document.querySelector('nav ul');
    if (mobileMenu && navUl) {
        mobileMenu.addEventListener('click', () => {
            navUl.classList.toggle('active');
        });
    }

    // Dropdown toggling for mobile and desktop click support
    const dropBtns = document.querySelectorAll('.dropbtn');
    dropBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const parentDropdown = btn.closest('.dropdown');
            if (parentDropdown) {
                // Close other open dropdowns
                document.querySelectorAll('.dropdown.is-open, .dropdown.mobile-open').forEach(d => {
                    if (d !== parentDropdown) {
                        d.classList.remove('is-open');
                        d.classList.remove('mobile-open');
                    }
                });
                parentDropdown.classList.toggle('is-open');
                parentDropdown.classList.toggle('mobile-open');
            }
        });
    });

    // Close dropdowns when clicking anywhere outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown.is-open, .dropdown.mobile-open').forEach(d => {
                d.classList.remove('is-open');
                d.classList.remove('mobile-open');
            });
        }
    });
});

async function handleLogin(e) {
    e.preventDefault();
    const username = e.target.username.value.toLowerCase();
    const password = e.target.password.value;
    // Check if captcha input exists and is visible
    const captchaInput = document.getElementById('captcha');
    const captcha = (captchaInput && captchaInput.value) ? captchaInput.value : '';

    const errorEl = document.getElementById('login-error');
    const submitBtn = e.target.querySelector('button');

    submitBtn.disabled = true;
    submitBtn.textContent = 'Logging in...';
    if (errorEl) errorEl.style.display = 'none';

    try {
        const response = await fetch('/api/login.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password, captcha })
        });

        const data = await response.json();

        if (response.ok) {
            // Login Success
            sessionStorage.setItem('psobb_user', JSON.stringify(data));

            // Only prompt to link email after successful credentials login if user has a legacy placeholder email (<username>_legacy@<domain>) or no valid email and no pending confirmation
            const isLegacy = Boolean(
                (data.is_legacy_email ||
                !data.has_email ||
                !data.email ||
                (typeof data.email === 'string' && data.email.toLowerCase().includes('_legacy@'))) &&
                !data.is_pending_email
            );

            if (isLegacy) {
                sessionStorage.setItem('psobb_prompt_email_on_login', '1');
            } else {
                sessionStorage.removeItem('psobb_prompt_email_on_login');
            }

            window.location.reload();
        } else {
            // Login Failed
            // Check for CAPTCHA requirement
            if (data.captcha_required) {
                const grp = document.getElementById('captcha-group');
                if (grp) {
                    grp.style.display = 'block';
                    // Reload captcha image to be safe
                    const img = document.getElementById('captcha-img');
                    if (img) img.src = 'api/captcha.php?' + Math.random();
                }
            }
            throw new Error(data.error || _t('Login failed'));
        }
    } catch (error) {
        if (errorEl) {
            errorEl.textContent = _t(error.message);
            errorEl.style.display = 'block';
        } else {
            alert(_t(error.message));
        }
        submitBtn.disabled = false;
        submitBtn.textContent = _t('Login');
    }
}

function updateHeaderNav(user) {
    if (!user) return;
    const teamLink = document.getElementById('nav-team-link');
    if (teamLink) teamLink.style.display = ''; // Reset display to show it

    const loginBtn = document.querySelector('.login-nav-btn');
    if (loginBtn) {
        loginBtn.textContent = (typeof window.__ === 'function')
            ? window.__('Dashboard')
            : (loginBtn.getAttribute('data-dashboard-text') || 'Dashboard');
    }

    const signupBtn = document.querySelector('.signup-nav-btn');
    if (signupBtn) signupBtn.style.display = 'none';

    if (user.isAdmin) {
        const adminDropdown = document.getElementById('nav-admin-dropdown');
        if (adminDropdown) adminDropdown.style.display = '';
    }
}

async function checkLoginStatus() {
    const userStr = sessionStorage.getItem('psobb_user');
    if (userStr) {
        try {
            const user = JSON.parse(userStr);
            showDashboard(user);
            return;
        } catch (e) {
            sessionStorage.removeItem('psobb_user');
        }
    }

    // Attempt to automatically restore login status from server session cookie
    try {
        const response = await fetch('/api/account_data.php', { credentials: 'same-origin' });
        if (response.ok) {
            const user = await response.json();
            sessionStorage.setItem('psobb_user', JSON.stringify(user));
            showDashboard(user);
        }
    } catch (e) {
        console.error('Failed to restore session:', e);
    }
}

function showDashboard(user) {
    const loginContainer = document.querySelector('.login-container-form');
    const dashboard = document.getElementById('dashboard');

    updateHeaderNav(user);

    if (loginContainer) loginContainer.style.display = 'none';
    if (dashboard) {
        dashboard.style.display = 'block';
        const mainBox = document.querySelector('.login-container');
        if (mainBox) mainBox.classList.add('dashboard-active');

        // Handle Discord Redirect Flags
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('error') === 'session_expired') {
            alert(_t("Security Error: Your PHP Session was lost during transit. Please log out and explicitly log back in to refresh your Secure Session token."));
            window.history.replaceState({}, document.title, window.location.pathname);
        } else if (urlParams.get('discord_linked') === '1') {
            user.discord_id = 'linked';
            sessionStorage.setItem('psobb_user', JSON.stringify(user));
            window.history.replaceState({}, document.title, window.location.pathname);
        } else if (urlParams.get('discord_unlinked') === '1') {
            user.discord_id = null;
            sessionStorage.setItem('psobb_user', JSON.stringify(user));
            window.history.replaceState({}, document.title, window.location.pathname);
        }

        // Toggle Discord Button UI
        const btnLinkDiscord = document.getElementById('btn-link-discord');
        const discordLinkedInfo = document.getElementById('discord-linked-info');
        if (btnLinkDiscord && discordLinkedInfo) {
            if (user.discord_id) {
                btnLinkDiscord.style.display = 'none';
                discordLinkedInfo.style.display = 'block';
            } else {
                btnLinkDiscord.style.display = 'block';
                discordLinkedInfo.style.display = 'none';
            }
        }

        // Populate dashboard header
        const lastPlayer = (user.BBLicenses && user.BBLicenses.length > 0) ? user.BBLicenses[0].UserName : (user.LastPlayerName || 'Hunter');

        const userHeader = document.getElementById('dash-username-header');
        if (userHeader) userHeader.textContent = lastPlayer;
        const dashUser = document.getElementById('dash-username');
        if (dashUser) dashUser.textContent = lastPlayer;
        const dashAcc = document.getElementById('dash-account-id');
        if (dashAcc) dashAcc.textContent = user.AccountID;

        const dashTeam = document.getElementById('dash-team');
        if (dashTeam) dashTeam.textContent = user.BBTeamID ? _t('Team') + ' #' + user.BBTeamID : _t('None');

        const playtimeEl = document.getElementById('dash-playtime');
        if (playtimeEl) {
            playtimeEl.textContent = user.total_play_time_hours ? `${user.total_play_time_hours} ${_t('hrs')}` : '--';
        }

        // PWA Install check
        const installCard = document.getElementById('pwa-install-card');
        if (installCard && window.deferredPrompt) {
            installCard.style.display = 'block';
        }

        // Initialize recovery email
        loadAccountEmail();

        // Prompt only after the user logs in with credentials and does not have a valid email on file
        if (sessionStorage.getItem('psobb_prompt_email_on_login') === '1') {
            sessionStorage.removeItem('psobb_prompt_email_on_login');
            if (!user.is_pending_email) {
                setTimeout(() => {
                    openPromptEmailModal();
                }, 350);
            }
        }

        // Initialize display name alias
        loadDisplayName();

        // Initialize system mail checkbox preference
        loadSystemMailPref();

        // Initialize Discord streak DM checkbox preference
        loadDiscordStreakPref();

        // Check for special item deliveries (widget is hidden unless count > 0)
        loadSpecialDeliveries();

        // Initialize milestone categories claim triggers inside portal
        initClaimModalCategoryButtons();

        // Initialize Backpack & Bank pre-selector dropdown and search bar listeners
        const bankSelect = document.getElementById('viewer-bank-select');
        if (bankSelect) {
            bankSelect.onchange = (e) => {
                window.activeBankIndex = parseInt(e.target.value);
                renderActiveBank();
            };
        }

        const searchInput = document.getElementById('viewer-bank-search');
        if (searchInput) {
            searchInput.oninput = (e) => {
                filterBankGrid(e.target.value.toLowerCase());
            };
        }

        // Load first available active character slot dynamically
        const firstSlotBtn = document.querySelector('.slot-btn');
        const defaultSlot = firstSlotBtn ? parseInt(firstSlotBtn.getAttribute('data-slot')) : 0;
        window.activeSlot = defaultSlot;
        switchCharSlot(defaultSlot);
    }
}

async function loadDisplayName() {
    const input = document.getElementById('display-name-input');
    if (!input) return;
    try {
        const res = await fetch('/api/get_display_name.php', { credentials: 'same-origin' });
        const data = await res.json();
        if (data.display_name) {
            input.value = data.display_name;
        }
    } catch (e) { /* silent */ }
}

// ---- Special Item Delivery ---------------------------------------------------
async function loadSpecialDeliveries() {
    const widget = document.getElementById('special-delivery-widget');
    const list   = document.getElementById('special-delivery-list');
    if (!widget || !list) return;

    try {
        const res  = await fetch('/api/redeem_special_delivery.php', { credentials: 'same-origin' });
        const data = await res.json();

        if (!data.count || data.count === 0) {
            widget.style.display = 'none';
            return;
        }

        widget.style.display = 'block';
        list.innerHTML = data.items.map(item => `
            <div id="sditem-${item.id}" style="
                background: rgba(0,0,0,.3); border: 1px solid rgba(251,146,60,.2);
                border-radius: 8px; padding: .75rem; margin-bottom: .6rem;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:.5rem; flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:700; color:#fff; font-size:.9rem;">${escHtml(item.item_name)}</div>
                        ${item.admin_note ? `<div style="font-size:.78rem; color:#9ca3af; margin-top:.2rem; font-style:italic;">&ldquo;${escHtml(item.admin_note)}&rdquo;</div>` : ''}
                    </div>
                    <button
                        onclick="claimDelivery(${item.id})"
                        id="sdbtn-${item.id}"
                        style="background:rgba(251,146,60,.2); border:1px solid #fb923c; color:#fdba74;
                               border-radius:6px; padding:.35rem .85rem; font-size:.8rem;
                               font-weight:600; cursor:pointer; white-space:nowrap;
                               transition:all .2s; flex-shrink:0;"
                        onmouseover="this.style.background='rgba(251,146,60,.4)'"
                        onmouseout="this.style.background='rgba(251,146,60,.2)'">
                        <i class="fas fa-hand-holding"></i> Claim
                    </button>
                </div>
                <div id="sdmsg-${item.id}" style="font-size:.78rem; margin-top:.4rem; display:none;"></div>
            </div>
        `).join('');

    } catch (e) { /* silent — widget stays hidden */ }
}

window.claimDelivery = async function(id) {
    const btn = document.getElementById(`sdbtn-${id}`);
    const msg = document.getElementById(`sdmsg-${id}`);
    if (!btn) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + _t('Claiming…');

    try {
        const res  = await fetch('/api/redeem_special_delivery.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.getCSRFToken ? window.getCSRFToken() : '',
            },
            body: JSON.stringify({ id }),
        });
        const data = await res.json();

        if (data.success) {
            // Remove the item card with a fade
            const card = document.getElementById(`sditem-${id}`);
            if (card) { card.style.transition = 'opacity .4s'; card.style.opacity = '0'; setTimeout(() => card.remove(), 400); }
            // Hide widget if nothing left
            setTimeout(() => {
                const list = document.getElementById('special-delivery-list');
                if (list && list.children.length === 0) {
                    const w = document.getElementById('special-delivery-widget');
                    if (w) w.style.display = 'none';
                }
            }, 500);
        } else if (data.offline) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-hand-holding"></i> ' + _t('Claim');
            msg.style.display = 'block';
            msg.style.color = '#fbbf24';
            msg.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + _t('You must be logged into the game to claim this item.');
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-hand-holding"></i> ' + _t('Claim');
            msg.style.display = 'block';
            msg.style.color = '#f87171';
            msg.innerHTML = '<i class="fas fa-times-circle"></i> ' + escHtml(data.error ?? 'Claim failed. Please try again.');
        }
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-hand-holding"></i> ' + _t('Claim');
    }
};

// ---- End Special Item Delivery -----------------------------------------------

window.saveDisplayName = async function () {
    const input = document.getElementById('display-name-input');
    const btn = document.getElementById('btn-save-alias');
    const msgEl = document.getElementById('alias-message');
    const name = input.value.trim();

    if (!name) {
        msgEl.textContent = _t('Please enter a display name.');
        msgEl.style.color = '#ff4444';
        msgEl.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.textContent = _t('Saving...');
    msgEl.style.display = 'none';

    try {
        const response = await fetch('/api/set_display_name.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({ display_name: name })
        });
        const data = await response.json();

        if (response.ok && data.success) {
            msgEl.textContent = '✓ ' + data.message;
            msgEl.style.color = '#00C851';
            msgEl.style.display = 'block';
            btn.textContent = _t('Saved!');
            setTimeout(() => { btn.disabled = false; btn.textContent = _t('Save'); }, 2000);
        } else {
            msgEl.textContent = data.error || _t('Failed to update.');
            msgEl.style.color = '#ff4444';
            msgEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = _t('Save');
        }
    } catch (e) {
        msgEl.textContent = _t('Connection error.');
        msgEl.style.color = '#ff4444';
        msgEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = _t('Save');
    }
};

window.focusEmailInput = function () {
    const input = document.getElementById('account-email-input');
    if (input) {
        input.focus();
        input.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
};

window.loadAccountEmail = async function () {
    const input = document.getElementById('account-email-input');
    const badge = document.getElementById('email-status-badge');
    const banner = document.getElementById('legacy-email-banner');
    const btn = document.getElementById('btn-save-email');
    if (!input && !banner) return;

    const userStr = sessionStorage.getItem('psobb_user');
    let cachedEmail = '';
    let isLegacy = false;
    let pendingEmail = '';
    let isPending = false;
    if (userStr) {
        try {
            const user = JSON.parse(userStr);
            if (user.is_legacy_email || user.has_email === false) {
                isLegacy = true;
            } else if (user.email) {
                cachedEmail = user.email;
            }
            if (user.pending_email) {
                pendingEmail = user.pending_email;
                isPending = Boolean(user.is_pending_email);
            }
        } catch (e) { }
    }

    renderEmailStatus(cachedEmail, isLegacy, pendingEmail, isPending);

    try {
        const res = await fetch('/api/get_account_email.php', { credentials: 'same-origin' });
        const data = await res.json();
        if (data.success) {
            renderEmailStatus(data.email || '', data.is_legacy, data.pending_email || '', data.is_pending);
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    user.email = data.email || '';
                    user.is_legacy_email = data.is_legacy;
                    user.has_email = data.has_email;
                    user.pending_email = data.pending_email || '';
                    user.is_pending_email = Boolean(data.is_pending);
                    sessionStorage.setItem('psobb_user', JSON.stringify(user));
                } catch (e) { }
            }
        }
    } catch (e) { /* silent */ }

    function renderEmailStatus(email, isLegacyAcc, pending = '', isPendingConf = false) {
        const msgEl = document.getElementById('email-message');
        if (banner) {
            banner.style.display = (isLegacyAcc && !isPendingConf) ? 'block' : 'none';
        }
        if (input) {
            input.value = pending || email || '';
        }
        if (badge) {
            if (isPendingConf) {
                if (email && !isLegacyAcc) {
                    badge.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> ' + _t('Change Pending');
                    badge.style.background = 'rgba(255, 170, 0, 0.2)';
                    badge.style.border = '1px solid #ffaa00';
                    badge.style.color = '#ffaa00';
                } else {
                    badge.innerHTML = '<i class="fas fa-envelope-open-text"></i> ' + _t('Confirmation Pending');
                    badge.style.background = 'rgba(255, 170, 0, 0.2)';
                    badge.style.border = '1px solid #ffaa00';
                    badge.style.color = '#ffaa00';
                }
            } else if (isLegacyAcc || !email) {
                badge.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + _t('No Email Linked');
                badge.style.background = 'rgba(255, 170, 0, 0.2)';
                badge.style.border = '1px solid #ffaa00';
                badge.style.color = '#ffaa00';
            } else {
                badge.innerHTML = '<i class="fas fa-check-circle"></i> ' + _t('Linked');
                badge.style.background = 'rgba(0, 200, 81, 0.2)';
                badge.style.border = '1px solid #00C851';
                badge.style.color = '#00C851';
            }
        }
        if (isPendingConf && pending && msgEl) {
            if (email && !isLegacyAcc) {
                msgEl.textContent = _t('Current email:') + ' ' + email + ' — ' + _t('Confirmation link sent to:') + ' ' + pending + '. ' + _t('Check your inbox to confirm this change.');
            } else {
                msgEl.textContent = _t('Confirmation link sent to') + ' ' + pending + '. ' + _t('Check your inbox to activate.');
            }
            msgEl.style.color = '#ffaa00';
            msgEl.style.display = 'block';
        }
        if (btn) {
            if (isPendingConf) {
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + _t('Resend Link');
            } else if (isLegacyAcc || !email) {
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + _t('Send Link');
            } else {
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + _t('Update Email');
            }
        }
    }
};

window.saveAccountEmail = async function () {
    const input = document.getElementById('account-email-input');
    const btn = document.getElementById('btn-save-email');
    const msgEl = document.getElementById('email-message');
    const badge = document.getElementById('email-status-badge');
    const banner = document.getElementById('legacy-email-banner');
    if (!input) return;

    const email = input.value.trim();

    if (!email) {
        if (msgEl) {
            msgEl.textContent = _t('Please enter an email address.');
            msgEl.style.color = '#ff4444';
            msgEl.style.display = 'block';
        }
        return;
    }

    const cleanEmail = email.replace(/[\r\n]/g, '').trim();

    if (!cleanEmail.includes('@') || !cleanEmail.includes('.')) {
        if (msgEl) {
            msgEl.textContent = _t('Please enter a valid email address.');
            msgEl.style.color = '#ff4444';
            msgEl.style.display = 'block';
        }
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + _t('Sending Link...');
    if (msgEl) msgEl.style.display = 'none';

    try {
        const csrfToken = window.getCSRFToken ? window.getCSRFToken() : '';
        const response = await fetch('/api/set_account_email.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ email: cleanEmail, csrf_token: csrfToken })
        });
        const data = await response.json();

        if (response.ok && data.success) {
            if (msgEl) {
                msgEl.textContent = '✓ ' + (data.message || 'Confirmation email sent! Please check your inbox.');
                msgEl.style.color = '#00C851';
                msgEl.style.display = 'block';
            }
            if (badge) {
                if (data.is_change) {
                    badge.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> ' + _t('Change Pending');
                } else {
                    badge.innerHTML = '<i class="fas fa-envelope-open-text"></i> ' + _t('Confirmation Pending');
                }
                badge.style.background = 'rgba(255, 170, 0, 0.2)';
                badge.style.border = '1px solid #ffaa00';
                badge.style.color = '#ffaa00';
            }
            if (banner) {
                banner.style.display = 'none';
            }
            btn.innerHTML = '<i class="fas fa-check"></i> ' + _t('Link Sent!');

            const userStr = sessionStorage.getItem('psobb_user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    user.pending_email = data.email;
                    user.is_pending_email = true;
                    sessionStorage.setItem('psobb_user', JSON.stringify(user));
                } catch (e) { }
            }

            setTimeout(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + _t('Resend Link');
            }, 2500);
        } else {
            if (msgEl) {
                msgEl.textContent = data.error || _t('Failed to send confirmation email.');
                msgEl.style.color = '#ff4444';
                msgEl.style.display = 'block';
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + _t('Send Link');
        }
    } catch (e) {
        if (msgEl) {
            msgEl.textContent = 'Connection error.';
            msgEl.style.color = '#ff4444';
            msgEl.style.display = 'block';
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + _t('Send Link');
    }
};

window.openPromptEmailModal = function () {
    const modal = document.getElementById('prompt-email-modal');
    if (!modal) return;
    const input = document.getElementById('pem-email-input');
    const err = document.getElementById('pem-error');
    const succ = document.getElementById('pem-success');
    if (err) err.style.display = 'none';
    if (succ) succ.style.display = 'none';

    modal.style.display = 'flex';
    if (input) input.focus();
};

window.closePromptEmailModal = function (rememberDismissal = false) {
    const modal = document.getElementById('prompt-email-modal');
    if (modal) modal.style.display = 'none';
    if (rememberDismissal) {
        sessionStorage.setItem('psobb_email_prompt_dismissed', '1');
    }
};

window.confirmPromptEmail = async function () {
    const input = document.getElementById('pem-email-input');
    const btn = document.getElementById('btn-confirm-pem');
    const err = document.getElementById('pem-error');
    const succ = document.getElementById('pem-success');
    if (!input) return;

    const email = input.value.trim();

    if (!email) {
        if (err) {
            err.textContent = _t('Please enter an email address.');
            err.style.display = 'block';
        }
        return;
    }

    const cleanEmail = email.replace(/[\r\n]/g, '').trim();

    if (!cleanEmail.includes('@') || !cleanEmail.includes('.')) {
        if (err) {
            err.textContent = _t('Please enter a valid email address.');
            err.style.display = 'block';
        }
        return;
    }

    if (err) err.style.display = 'none';
    if (succ) succ.style.display = 'none';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + _t('Sending Link...');

    try {
        const csrfToken = window.getCSRFToken ? window.getCSRFToken() : '';
        const response = await fetch('/api/set_account_email.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ email: cleanEmail, csrf_token: csrfToken })
        });
        const data = await response.json();

        if (response.ok && data.success) {
            if (succ) {
                succ.textContent = '✓ ' + (data.message || 'Confirmation link sent! Please check your inbox.');
                succ.style.display = 'block';
            }
            btn.innerHTML = '<i class="fas fa-check"></i> ' + _t('Link Sent!');

            sessionStorage.setItem('psobb_email_prompt_dismissed', '1');
            const userStr = sessionStorage.getItem('psobb_user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    user.pending_email = data.email;
                    user.is_pending_email = true;
                    sessionStorage.setItem('psobb_user', JSON.stringify(user));
                } catch (e) { }
            }

            const settingsInput = document.getElementById('account-email-input');
            if (settingsInput) settingsInput.value = data.email;
            const badge = document.getElementById('email-status-badge');
            if (badge) {
                badge.innerHTML = '<i class="fas fa-envelope-open-text"></i> ' + _t('Confirmation Pending');
                badge.style.background = 'rgba(255, 170, 0, 0.2)';
                badge.style.border = '1px solid #ffaa00';
                badge.style.color = '#ffaa00';
            }
            const banner = document.getElementById('legacy-email-banner');
            if (banner) banner.style.display = 'none';

            setTimeout(() => {
                closePromptEmailModal(false);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + _t('Send Confirmation Link');
            }, 3000);
        } else {
            if (err) {
                err.textContent = data.error || _t('Failed to send confirmation link.');
                err.style.display = 'block';
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + _t('Send Confirmation Link');
        }
    } catch (e) {
        if (err) {
            err.textContent = _t('Connection error. Please try again.');
            err.style.display = 'block';
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + _t('Send Confirmation Link');
    }
};

async function loadActiveCharacterSectionId(accountId) {
    const secIdContainer = document.getElementById('section-id-change-container');
    if (!secIdContainer) return;

    secIdContainer.innerHTML = '<p>' + _t('Checking for online characters...') + '</p>';

    try {
        const response = await fetch('/api/summary.php');
        const data = await response.json();

        // Allow picking Section ID even if offline as a fallback for the admin/user
        let activeCharacter = null;
        if (data.Clients) {
            activeCharacter = data.Clients.find(c => c.AccountID === accountId && c.Name);
        }

        let html = '';
        if (activeCharacter) {
            html += `<p>Character: <strong>${escapeHtml(activeCharacter.Name)}</strong> (Level ${activeCharacter.Level})</p>
                     <p>Current Section ID: <strong class="section-id id-${(activeCharacter.SectionID || 'none').toLowerCase()}">${activeCharacter.SectionID}</strong></p>`;
            if (activeCharacter.Level > 50) {
                html += `<p style="color: #ff4444; margin-top: 10px;">Only characters level 50 and below can change their Section ID.</p>`;
                secIdContainer.innerHTML = html;
                return;
            }
        } else {
            html += `<p style="color: #ffaa00; margin-bottom: 15px;">Warning: You must be logged into a character in-game to apply a Section ID change immediately. You can still pre-select one here.</p>`;
        }

        const secIdInfo = {
            'Viridia': 'Partisans, Shots',
            'Greenill': 'Daggers, Rifles',
            'Skyly': 'Swords, Sealed J-Sword',
            'Bluefull': 'Partisans, Spread',
            'Purplenum': 'Mechguns, Units',
            'Pinkal': 'Wands, Force Weapons',
            'Redria': 'Slicers, Armors, Balanced',
            'Oran': 'Daggers, Handguns',
            'Yellowboze': 'All Weapons, Meseta',
            'Whitill': 'Slicers, High-end Rares'
        };
        const secIds = Object.keys(secIdInfo);

        html += `
            <div style="margin-top: 15px; border: 1px solid rgba(0,255,255,0.2); background: rgba(0,0,0,0.5); padding: 15px; border-radius: 4px;">
                <h4 style="margin-top: 0; color: #00ffff;">Select New Section ID</h4>
                <div class="section-id-grid" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; margin-bottom: 15px;">
                    ${secIds.map((id, index) => `
                        <label class="secid-option-lbl" style="cursor: pointer; text-align: center; display: flex; flex-direction: column; align-items: center; border: 1px solid ${index === 0 ? '#00ffff' : 'rgba(0,255,255,0.1)'}; padding: 6px 2px; border-radius: 4px; background: ${index === 0 ? 'rgba(0,255,255,0.1)' : 'transparent'}; transition: all 0.2s;" onclick="document.querySelectorAll('.secid-option-lbl').forEach(el=>{el.style.background='transparent';el.style.borderColor='rgba(0,255,255,0.1)'});this.style.background='rgba(0,255,255,0.1)';this.style.borderColor='#00ffff';">
                            <input type="radio" name="new-section-id" value="${id}" style="display: none;" ${index === 0 ? 'checked' : ''}>
                            <img src="/img/section_ids/${id}.png" alt="${id}" style="width: 32px; height: 32px; margin-bottom: 3px;">
                            <span style="font-size: 0.7em; font-weight: bold; color: #eee;">${id}</span>
                            <span style="font-size: 0.55em; color: #999; margin-top: 2px; line-height: 1.2;">${secIdInfo[id]}</span>
                        </label>
                    `).join('')}
                </div>
                <button id="btn-change-secid" class="dl-btn" style="width: 100%; border-color: #00ffff; background: rgba(0, 255, 255, 0.15); color: #00ffff; padding: 12px; font-weight: bold; font-family: 'Share Tech Mono', 'Segoe UI', sans-serif;">${_t('Change Section ID')}</button>
                <div id="secid-message" style="margin-top: 10px; display: none; font-weight: bold;"></div>
            </div>
        `;

        secIdContainer.innerHTML = html;

        const changeBtn = document.getElementById('btn-change-secid');
        if (changeBtn) {
            changeBtn.addEventListener('click', () => submitSectionIdChange(activeCharacter ? activeCharacter.Name : ''));
        }

    } catch (e) {
        secIdContainer.innerHTML = '<p style="color: #ff4444;">' + _t('Failed to load active character data.') + '</p>';
    }
}

async function submitSectionIdChange(characterName) {
    const newSecId = document.querySelector('input[name="new-section-id"]:checked').value;
    const msgEl = document.getElementById('secid-message');
    const btn = document.getElementById('btn-change-secid');

    btn.disabled = true;
    btn.textContent = _t("Processing...");
    msgEl.style.display = 'none';

    try {
        const response = await fetch('/api/change_section_id.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({ character_name: characterName, new_section_id: newSecId })
        });
        const data = await response.json();

        if (response.ok && data.success) {
            msgEl.textContent = data.message;
            msgEl.style.color = '#00C851';
            msgEl.style.display = 'block';
            btn.textContent = _t("Success");

            // Reload the character data after 2 seconds
            setTimeout(() => {
                const userStr = sessionStorage.getItem('psobb_user');
                if (userStr) {
                    const user = JSON.parse(userStr);
                    loadActiveCharacterSectionId(user.AccountID);
                }
            }, 2500);
        } else {
            msgEl.textContent = data.error || _t("Failed to change Section ID.");
            msgEl.style.color = '#ff4444';
            msgEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = _t("Change Section ID");
        }
    } catch (e) {
        msgEl.textContent = _t("Connection error: ") + e.message;
        msgEl.style.color = '#ff4444';
        msgEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = _t("Change Section ID");
    }
}

async function loadCharacterBankSwitcher(accountId) {
    const bankContainer = document.getElementById('bank-swap-container');
    if (!bankContainer) return;

    bankContainer.innerHTML = '<p>' + _t('Checking for online characters...') + '</p>';

    try {
        const response = await fetch('/api/summary.php');
        const data = await response.json();

        let activeCharacter = null;
        if (data.Clients) {
            activeCharacter = data.Clients.find(c => c.AccountID === accountId && c.Name);
        }

        let html = '';
        if (!activeCharacter) {
            html += `<p style="color: #ffaa00; margin-bottom: 15px;">Warning: You must be logged into a character in-game to apply a bank switch immediately. You can still pre-select one here.</p>`;
        }

        html += `
            <div style="border: 1px solid rgba(0,255,255,0.2); background: rgba(0,0,0,0.5); padding: 15px; border-radius: 4px;">
                <h4 style="margin-top: 0; color: #00ffff;">Bank Management</h4>
                <p style="font-size: 0.9em; margin-bottom: 15px;">
                    Select a bank to swap out your current bank with in-game. To view items in these banks, you must access the counter in the game lobby.
                </p>
                <select id="target-bank-index" style="width: 100%; padding: 10px; margin-bottom: 15px; background: rgba(0, 0, 0, 0.5); color: #fff; border: 1px solid rgba(0, 255, 255, 0.3); border-radius: 4px; box-sizing: border-box;">
                    <option value="-1" style="background:#111; color:#fff;">Shared Bank</option>
                    ${Array.from({ length: 20 }, (_, i) => `<option value="${i}" style="background:#111; color:#fff;">Slot ${i + 1} Character Bank</option>`).join('')}
                </select>
                <button id="btn-swap-bank" class="dl-btn" style="width: 100%; border-color: #00ffff; background: rgba(0, 255, 255, 0.15); color: #00ffff; padding: 12px; font-weight: bold; font-family: 'Share Tech Mono', 'Segoe UI', sans-serif;">${_t('Swap Bank In-Game')}</button>
                <div id="bank-message" style="margin-top: 10px; display: none; font-weight: bold;"></div>
            </div>
        `;

        bankContainer.innerHTML = html;

        const swapBtn = document.getElementById('btn-swap-bank');
        if (swapBtn) {
            swapBtn.addEventListener('click', () => submitBankSwap(activeCharacter ? activeCharacter.Name : ''));
        }

    } catch (e) {
        bankContainer.innerHTML = '<p style="color: #ff4444;">' + _t('Failed to load active character data for bank swapping.') + '</p>';
    }
}

async function submitBankSwap(characterName) {
    const targetBank = document.getElementById('target-bank-index').value;
    const msgEl = document.getElementById('bank-message');
    const btn = document.getElementById('btn-swap-bank');

    btn.disabled = true;
    btn.textContent = _t("Processing...");
    msgEl.style.display = 'none';

    try {
        const response = await fetch('/api/bank_swap.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({ character_name: characterName, target_bank_index: parseInt(targetBank) })
        });

        let data;
        const rawText = await response.text();
        try {
            data = JSON.parse(rawText);
        } catch (parseErr) {
            msgEl.textContent = "Server returned invalid response: " + rawText.substring(0, 200);
            msgEl.style.color = '#ff4444';
            msgEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = _t("Swap Bank In-Game");
            return;
        }

        if (response.ok && data.success) {
            msgEl.textContent = data.message;
            msgEl.style.color = '#00C851';
            msgEl.style.display = 'block';
            btn.textContent = _t("Success");

            setTimeout(() => {
                btn.disabled = false;
                btn.textContent = _t("Swap Bank In-Game");
                msgEl.style.display = 'none';
            }, 3000);
        } else {
            msgEl.textContent = data.error || _t("Failed to swap bank.");
            msgEl.style.color = '#ff4444';
            msgEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = _t("Swap Bank In-Game");
        }
    } catch (e) {
        msgEl.textContent = _t("Connection error: ") + e.message;
        msgEl.style.color = '#ff4444';
        msgEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = _t("Swap Bank In-Game");
    }
}


window.logout = function () {
    sessionStorage.removeItem('psobb_user');
    window.location.reload();
}

async function fetchServerStats() {
    try {
        const apiPrefix = window.location.pathname.includes('/admin/') ? '../api/' : 'api/';
        const response = await fetch(apiPrefix + 'server.php');
        if (!response.ok) throw new Error('Network response was not ok');
        const data = await response.json();

        // Update elements if they exist
        updateElement('server-name', data.ServerName);
        updateElement('uptime', data.Uptime);
        updateElement('client-count', data.ClientCount);
        updateElement('game-count', data.GameCount);
        if (data.EXPDisplay) {
            updateElement('rate-exp', data.EXPDisplay);
        } else if (data.BBGlobalEXPMultiplier !== undefined) {
            updateElement('rate-exp', data.BBGlobalEXPMultiplier + 'x');
        }
        if (data.DropDisplay) {
            updateElement('rate-drop', data.DropDisplay);
        } else if (data.ServerGlobalDropRateMultiplier !== undefined) {
            updateElement('rate-drop', data.ServerGlobalDropRateMultiplier + 'x');
        }

    } catch (error) {
        console.error('Error fetching server stats:', error);
    }
}

async function fetchDetailedStats() {
    try {
        const apiPrefix = window.location.pathname.includes('/admin/') ? '../api/' : 'api/';
        const response = await fetch(apiPrefix + 'summary.php');
        if (!response.ok) throw new Error('Network response was not ok');
        const data = await response.json();

        // Update simple stats using the Server object from summary
        if (data.Server) {
            updateElement('server-name', data.Server.ServerName);
            updateElement('uptime', data.Server.Uptime);
            updateElement('client-count', data.Server.ClientCount);
            updateElement('game-count', data.Server.GameCount);
            if (data.Server.EXPDisplay) {
                updateElement('rate-exp', data.Server.EXPDisplay);
            } else if (data.Server.BBGlobalEXPMultiplier !== undefined) {
                updateElement('rate-exp', data.Server.BBGlobalEXPMultiplier + 'x');
            }
            if (data.Server.DropDisplay) {
                updateElement('rate-drop', data.Server.DropDisplay);
            } else if (data.Server.ServerGlobalDropRateMultiplier !== undefined) {
                updateElement('rate-drop', data.Server.ServerGlobalDropRateMultiplier + 'x');
            }
        }

        renderPlayerList(data.Clients);
        renderGameList(data.Games);

    } catch (error) {
        console.error('Error fetching detailed stats:', error);
    }
}

const ID_MAP = {
    'server-name': ['server-name'],
    'uptime': ['uptime', 'uptime-stats'],
    'client-count': ['client-count', 'client-count-stats'],
    'game-count': ['game-count', 'game-count-stats'],
    'rate-exp': ['rate-exp', 'rate-exp-stats'],
    'rate-drop': ['rate-drop', 'rate-drop-stats']
};

function updateElement(key, value) {
    const ids = ID_MAP[key] || [key];
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = value;
        }
    });
}

function renderPlayerList(clients) {
    const list = document.getElementById('player-list');
    if (!list) return;

    list.innerHTML = '';

    // Filter out players with no name (connecting)
    const activeClients = (clients || []).filter(c => c.Name);

    // Dynamic Class Archetype Counter
    let huCount = 0;
    let raCount = 0;
    let foCount = 0;
    activeClients.forEach(c => {
        if (c.Class) {
            const upper = c.Class.toUpperCase();
            if (upper.startsWith('HU')) huCount++;
            else if (upper.startsWith('RA')) raCount++;
            else if (upper.startsWith('FO')) foCount++;
        }
    });
    const huEl = document.getElementById('class-hu-count');
    const raEl = document.getElementById('class-ra-count');
    const foEl = document.getElementById('class-fo-count');
    if (huEl) huEl.textContent = huCount;
    if (raEl) raEl.textContent = raCount;
    if (foEl) foEl.textContent = foCount;

    if (activeClients.length === 0) {
        list.innerHTML = '<tr><td colspan="4" style="text-align:center">' + _t('No players online') + '</td></tr>';
        return;
    }

    activeClients.forEach(c => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(c.Name)}</td>
            <td>${c.Level || '-'}</td>
            <td>${c.Class || '-'}</td>
            <td><span class="section-id id-${(c.SectionID || '').toLowerCase()}">${c.SectionID || '-'}</span></td>
        `;
        list.appendChild(row);
    });
}

function renderGameList(games) {
    const list = document.getElementById('game-list');
    if (!list) return;

    list.innerHTML = '';

    const activeGames = games || [];

    if (activeGames.length === 0) {
        list.innerHTML = '<tr><td colspan="5" style="text-align:center">' + _t('No active games') + '</td></tr>';
        return;
    }

    activeGames.forEach(g => {
        const row = document.createElement('tr');
        const players = g.Players !== undefined ? g.Players : '-';

        let displayName = g.Name || '';
        const mode = g.Mode || 'Normal';
        const displayMode = mode === 'Normal' ? 'Extermination/Normal' : mode;
        const modeClass = `mode-${mode.toLowerCase()}`;
        const passBadge = g.HasPassword
            ? '<span style="display: inline-flex; align-items: center; gap: 4px; background: rgba(255, 170, 0, 0.1); border: 1px solid rgba(255, 170, 0, 0.4); color: #ffaa00; padding: 2px 8px; border-radius: 20px; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.5px;"><i class="fas fa-lock" style="font-size: 0.85em;"></i> PRIVATE</span>'
            : '<span style="display: inline-flex; align-items: center; gap: 4px; background: rgba(0, 255, 200, 0.1); border: 1px solid rgba(0, 255, 200, 0.3); color: #00ffc8; padding: 2px 8px; border-radius: 20px; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.5px;"><i class="fas fa-unlock" style="font-size: 0.85em;"></i> OPEN</span>';

        row.innerHTML = `
            <td><span style="font-weight: 600; color: #fff; text-shadow: 0 0 5px rgba(255,255,255,0.3);">${escapeHtml(displayName)}</span></td>
            <td><span class="mode-badge ${modeClass}">${displayMode}</span></td>
            <td>${g.Episode || 'Ep1'}</td>
            <td>${g.Difficulty || 'Normal'}</td>
            <td>${players}/4</td>
            <td>${passBadge}</td>
        `;
        list.appendChild(row);
    });
}

// Star stream removed for stability

window.requestDeleteAccount = function () {
    const modal = document.getElementById('delete-modal');
    if (modal) {
        modal.style.display = 'flex';
        document.getElementById('delete-error').style.display = 'none';
        document.getElementById('delete-confirm-password').value = '';
    }
}

window.closeDeleteModal = function () {
    const modal = document.getElementById('delete-modal');
    if (modal) modal.style.display = 'none';
}

window.confirmDelete = async function () {
    const password = document.getElementById('delete-confirm-password').value;
    const errorEl = document.getElementById('delete-error');
    const btn = document.getElementById('btn-confirm-delete');

    if (!password) {
        errorEl.textContent = _t("Please enter your password.");
        errorEl.style.display = 'block';
        return;
    }

    const userStr = sessionStorage.getItem('psobb_user');
    if (!userStr) {
        window.location.reload();
        return;
    }
    const user = JSON.parse(userStr);

    // Extract Username from BBLicenses if available
    let username = user.username;
    if (!username && user.BBLicenses && user.BBLicenses.length > 0) {
        username = user.BBLicenses[0].UserName;
    }

    if (!username) {
        errorEl.textContent = _t("Could not determine username. Please re-login.");
        errorEl.style.display = 'block';
        return;
    }

    // Attempt deletion
    btn.disabled = true;
    btn.textContent = _t("Deleting...");

    try {
        const response = await fetch('/api/delete_account.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({ username: username, password: password })
        });

        const data = await response.json();

        if (response.ok && data.success) {
            alert(_t("Account deleted successfully."));
            logout();
        } else {
            errorEl.textContent = data.error || _t("Deletion failed.");
            errorEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = _t("Confirm Delete");
        }
    } catch (e) {
        errorEl.textContent = _t("Connection error.");
        errorEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = _t("Confirm Delete");
    }
};

// Change Password Logic
window.requestChangePassword = function () {
    const modal = document.getElementById('change-pass-modal');
    if (modal) {
        modal.display = 'flex'; // Wait, modal needs style.display
        modal.style.display = 'flex';
        document.getElementById('cp-old').value = '';
        document.getElementById('cp-new').value = '';
        document.getElementById('cp-confirm').value = '';
        document.getElementById('cp-error').style.display = 'none';
        document.getElementById('cp-success').style.display = 'none';
    }
};

window.closeChangePassModal = function () {
    const modal = document.getElementById('change-pass-modal');
    if (modal) modal.style.display = 'none';
};

window.confirmChangePass = async function () {
    const oldPass = document.getElementById('cp-old').value;
    const newPass = document.getElementById('cp-new').value;
    const confirmPass = document.getElementById('cp-confirm').value;
    const errEl = document.getElementById('cp-error');
    const succEl = document.getElementById('cp-success');
    const btn = document.getElementById('btn-confirm-cp');

    errEl.style.display = 'none';
    succEl.style.display = 'none';

    if (!oldPass || !newPass) {
        errEl.textContent = _t("Please fill in all fields.");
        errEl.style.display = 'block';
        return;
    }
    if (newPass !== confirmPass) {
        errEl.textContent = _t("New passwords do not match.");
        errEl.style.display = 'block';
        return;
    }
    if (newPass.includes(' ')) {
        errEl.textContent = _t("No spaces allowed.");
        errEl.style.display = 'block';
        return;
    }

    const userStr = sessionStorage.getItem('psobb_user');
    if (!userStr) { window.location.reload(); return; }
    const user = JSON.parse(userStr);

    // Extract Username
    let username = user.username;
    if (!username && user.BBLicenses && user.BBLicenses.length > 0) {
        username = user.BBLicenses[0].UserName;
    }

    btn.disabled = true;
    btn.textContent = _t("Updating...");

    try {
        const response = await fetch('/api/change_password.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({
                username: username,
                old_password: oldPass,
                new_password: newPass
            })
        });
        const data = await response.json();

        if (response.ok && data.success) {
            succEl.textContent = _t("Password Changed! Logging out...");
            succEl.style.display = 'block';
            btn.textContent = _t("Success");
            setTimeout(() => {
                closeChangePassModal();
                logout();
            }, 1000);
        } else {
            errEl.textContent = data.error || _t("Update failed.");
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = _t("Update");
        }
    } catch (e) {
        errEl.textContent = _t("Connection error.");
        errEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = _t("Update");
    }
};

// Enhanced to update rates from the new fields in /api/server.php
async function updateUIRates(data) {
    if (data.EXPDisplay) updateElement('rate-exp', data.EXPDisplay);
    else if (data.EXP) updateElement('rate-exp', parseFloat(data.EXP) + 'x');
    if (data.DropDisplay) updateElement('rate-drop', data.DropDisplay);
    else if (data.Drop) updateElement('rate-drop', parseFloat(data.Drop) + 'x');
}

// ==========================================================================
// Player Guide Modal Actions & Tab Toggling
// ==========================================================================
window.openPlayerGuideModal = function () {
    const modal = document.getElementById('player-guide-modal');
    if (modal) {
        modal.style.display = 'flex';
        // Reset scroll position to top
        const scrollBox = document.getElementById('guide-modal-content');
        if (scrollBox) scrollBox.scrollTop = 0;
        // Default to the first tab
        window.switchGuideTab('tab-portal');

        // Add keyboard ESC listener
        window.addEventListener('keydown', handleGuideEscKey);

        // Add click listener on the modal overlay itself to close it
        modal.addEventListener('click', handleGuideOverlayClick);
    }
};

window.closePlayerGuideModal = function () {
    const modal = document.getElementById('player-guide-modal');
    if (modal) {
        modal.style.display = 'none';
        // Cleanup listeners
        window.removeEventListener('keydown', handleGuideEscKey);
        modal.removeEventListener('click', handleGuideOverlayClick);
    }
};

window.switchGuideTab = function (tabId) {
    // Hide all tab panes
    const panes = document.querySelectorAll('.guide-tab-pane');
    panes.forEach(pane => {
        pane.style.display = 'none';
    });

    // Show the requested pane
    const targetPane = document.getElementById(tabId);
    if (targetPane) {
        targetPane.style.display = 'block';
    }

    // Update active tab buttons
    const buttons = document.querySelectorAll('.guide-tab-btn');
    buttons.forEach(btn => {
        if (btn.getAttribute('data-tab') === tabId) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
};

function handleGuideEscKey(e) {
    if (e.key === 'Escape') {
        window.closePlayerGuideModal();
    }
}

function handleGuideOverlayClick(e) {
    // If the click happened on the outer container (#player-guide-modal) and not inside the inner dialog
    if (e.target.id === 'player-guide-modal') {
        window.closePlayerGuideModal();
    }
}

// ==========================================================================
// Progressive Web App (PWA) & Single-Page Application (SPA) Portal Controller
// ==========================================================================

window.deferredPrompt = null;
window.activeSlot = 0;
window.activeCharData = null;
window.activeBankIndex = 0; // 0 = character, -1 = shared
window.bankCache = {};
window.currentClaimLevel = 0;

// Intercept PWA Install Prompts
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    window.deferredPrompt = e;
    // Show install card with Android/Chrome button
    const installCard = document.getElementById('pwa-install-card');
    const androidDiv = document.getElementById('pwa-install-android');
    if (installCard && sessionStorage.getItem('psobb_user')) {
        installCard.style.display = 'block';
        if (androidDiv) androidDiv.style.display = 'block';
    }
});

// Show install card for iOS after login (no beforeinstallprompt on Safari)
document.addEventListener('DOMContentLoaded', () => {
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    if (isStandalone) return;

    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (isIOS && sessionStorage.getItem('psobb_user')) {
        const installCard = document.getElementById('pwa-install-card');
        const iosDiv = document.getElementById('pwa-install-ios');
        if (installCard && iosDiv) {
            installCard.style.display = 'block';
            iosDiv.style.display = 'block';
        }
    }
});

// Trigger App Installation
window.installPortalApp = async function () {
    if (!window.deferredPrompt) {
        alert(_t('The installation prompt is not ready. If you are using an iOS device, please use "Add to Home Screen" from Safari\'s share menu.'));
        return;
    }
    window.deferredPrompt.prompt();
    const { outcome } = await window.deferredPrompt.userChoice;
    console.log(`[PWA] Install prompt outcome: ${outcome}`);
    window.deferredPrompt = null;
    const installCard = document.getElementById('pwa-install-card');
    if (installCard) {
        installCard.style.display = 'none';
    }
};

// Switch Dashboard Tab Panes
window.switchDashboardTab = function (tabId) {
    let target = document.getElementById(tabId);
    let targetBtn = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);

    // Graceful fallback if target tab or button is disabled / not in DOM
    if (!target || !targetBtn) {
        target = document.querySelector('.dashboard-tab-pane');
        if (target) {
            tabId = target.id;
            targetBtn = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
        }
    }
    if (!target) return;

    document.querySelectorAll('.dashboard-tab-pane').forEach(pane => {
        pane.classList.remove('active');
    });
    target.classList.add('active');

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    if (targetBtn) {
        targetBtn.classList.add('active');
    }

    // Lazy load tab data
    if (tabId === 'tab-banks' || tabId === 'tab-bank') {
        if (window.activeCharData) {
            if (tabId === 'tab-bank') renderActiveBank();
        } else {
            window.loadCharSlot(window.activeSlot || 0);
        }
    } else if (tabId === 'tab-guild') {
        window.loadUnlocks();
        window.loadStreak();
        window.loadMyBounties();
    } else if (tabId === 'tab-lfg') {
        window.loadLfgFeed();
    } else if (tabId === 'tab-tekker') {
        window.loadTekkerTokens();
    }

    // Start/stop bounty change-detection polling based on guild tab visibility
    if (tabId === 'tab-guild') {
        if (!window._bountyPollInterval) {
            window._lastBountyTs = 0;
            window._bountyPollInterval = setInterval(async () => {
                try {
                    const res = await fetch('/api/bounty_check.php', { credentials: 'same-origin' });
                    const data = await res.json();
                    if (data.ts && data.ts !== window._lastBountyTs) {
                        window._lastBountyTs = data.ts;
                        window.loadMyBounties();
                    }
                } catch (e) { /* silent */ }
            }, 10000); // Check every 10 seconds
        }
    } else {
        if (window._bountyPollInterval) {
            clearInterval(window._bountyPollInterval);
            window._bountyPollInterval = null;
        }
    }

    // Start/stop lobby feed polling based on chat tab visibility
    if (tabId === 'tab-chat') {
        if (window.startLobbyFeed) window.startLobbyFeed();
    } else {
        if (window.stopLobbyFeed) window.stopLobbyFeed();
    }
};

// Switch Character Slot
window.switchCharSlot = function (slotIndex) {
    window.activeSlot = slotIndex;
    const resetMsgEl = document.getElementById('reset-mat-message');
    if (resetMsgEl) {
        resetMsgEl.style.display = 'none';
        resetMsgEl.textContent = '';
    }
    document.querySelectorAll('.slot-btn').forEach(btn => {
        btn.classList.remove('active');
        if (parseInt(btn.getAttribute('data-slot')) === slotIndex) {
            btn.classList.add('active');
        }
    });
    window.loadCharSlot(slotIndex);
};

// Load Character Data via API
window.loadCharSlot = async function (slotIndex) {
    const pane = document.getElementById('viewer-content-pane');
    const loader = document.getElementById('viewer-loader');
    if (pane) pane.style.opacity = '0.4';
    if (loader) loader.style.display = 'block';

    try {
        const res = await fetch(`/api/character_viewer.php?slot=${slotIndex}`, { credentials: 'same-origin' });
        const data = await res.json();
        if (res.ok && data.success) {
            window.activeCharData = data.character;
            window.bankCache[slotIndex] = data.character.bank.items;
            window.bankCache['shared'] = data.character.shared_bank.items;

            renderCharacterProfile();
            renderInventory();
            renderActiveBank();
            populateChatCharacterSelect();
            renderActiveCharacterSectionId(data.character);
            if (window.loadKillCounts) window.loadKillCounts(data.character.name);
        } else {
            throw new Error(data.error || 'Failed to sync character metadata.');
        }
    } catch (e) {
        console.error(e);
        const contentPane = document.getElementById('viewer-content-pane');
        if (contentPane) {
            contentPane.innerHTML = `<div style="text-align:center; padding:3rem; color:#ff4444; font-family:'Share Tech Mono',monospace;">⚠️ ${e.message}</div>`;
        }
    } finally {
        if (pane) pane.style.opacity = '1';
        if (loader) loader.style.display = 'none';
    }
};

// Render Character Stats & Hero Card
function renderCharacterProfile() {
    const c = window.activeCharData;
    if (!c) return;

    document.getElementById('char-profile-name').textContent = c.name;
    document.getElementById('char-profile-level').textContent = c.level;
    document.getElementById('char-profile-playtime').textContent = `${c.play_time_hours} hrs`;

    const onlineBadge = document.getElementById('char-profile-online');
    if (onlineBadge) {
        if (c.online) {
            onlineBadge.innerHTML = '<span style="color: #00ffc8; text-shadow: 0 0 5px rgba(0,255,200,0.5);"><i class="fas fa-circle animate-pulse"></i> ' + _t('ONLINE') + '</span>';
        } else {
            onlineBadge.innerHTML = '<span style="color: #666;"><i class="far fa-circle"></i> ' + _t('OFFLINE') + '</span>';
        }
    }

    const classBadge = document.getElementById('char-profile-class');
    if (classBadge) classBadge.textContent = c.class;

    const fallbackImg = document.getElementById('char-profile-avatar-fallback');
    if (fallbackImg) {
        fallbackImg.src = `/img/classes/${c.class.toLowerCase()}.png`;
        fallbackImg.style.display = 'block';
        fallbackImg.onerror = () => { fallbackImg.src = '/img/favicon.svg'; };
    }

    const secIdBadge = document.getElementById('char-profile-secid');
    if (secIdBadge) {
        secIdBadge.innerHTML = `
            <img src="/img/section_ids/${c.section_id}.png" alt="${c.section_id}" style="width:18px; height:18px;">
            <span class="section-id id-${c.section_id.toLowerCase()}" style="font-size:0.7rem; font-weight:bold; font-family:'Share Tech Mono',monospace;">${c.section_id}</span>
        `;
    }

    // Meseta display
    const mesetaEl = document.getElementById('char-meseta-val');
    if (mesetaEl) mesetaEl.textContent = parseInt(c.stats.Meseta || 0).toLocaleString();

    // Animated stat bars (Total (Base) with TP & Class Max highlights)
    const statMaxes = { ATP: 2000, DFP: 900, MST: 2000, ATA: 280, EVP: 1200, LCK: 100, HP: 2500, TP: 2500 };
    const stats = ['ATP', 'DFP', 'MST', 'ATA', 'EVP', 'LCK', 'HP', 'TP'];
    const baseStats = c.base_stats || {};
    const maxStats = c.max_stats || {};
    stats.forEach(s => {
        const valEl = document.getElementById(`stat-val-${s.toLowerCase()}`);
        const barEl = document.getElementById(`bar-${s.toLowerCase()}`);
        const totalVal = parseInt(c.stats[s]) || 0;
        const baseVal = baseStats[s] !== undefined ? (parseInt(baseStats[s]) || 0) : null;
        const capVal = maxStats[s] !== undefined ? (parseInt(maxStats[s]) || 0) : null;
        if (valEl) {
            if (baseVal !== null && s !== 'HP' && s !== 'TP') {
                const isCapped = capVal > 0 && baseVal >= capVal;
                const baseColor = isCapped ? '#ffd54f' : '#88a8c0';
                valEl.innerHTML = `${totalVal} <span style="color:${baseColor}; font-weight:normal;">(${baseVal})</span>`;
            } else if (baseVal !== null && baseVal !== totalVal) {
                valEl.innerHTML = `${totalVal} <span style="color:#88a8c0; font-weight:normal;">(${baseVal})</span>`;
            } else {
                valEl.textContent = totalVal;
            }
        }
        if (barEl) {
            const denom = (capVal && capVal > 0 && s !== 'ATA') ? Math.max(capVal, totalVal, statMaxes[s] * 0.5) : statMaxes[s];
            const pct = Math.min(100, (totalVal / (denom || 1)) * 100);
            setTimeout(() => { barEl.style.width = pct + '%'; }, 100);
        }
    });

    // Material values (compact grid)
    const matVals = {
        'mat-val-hp': c.mats.HP,
        'mat-val-tp': c.mats.TP,
        'mat-val-power': c.mats.Power,
        'mat-val-mind': c.mats.Mind,
        'mat-val-evade': c.mats.Evade,
        'mat-val-def': c.mats.Def,
        'mat-val-luck': c.mats.Luck
    };
    Object.keys(matVals).forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = matVals[id];
    });
}

// Render Backpack Inventory
// Kill counts: the character's lifetime kills of each enemy and boss, by episode and difficulty
window.killCountsEpisode = null;
window.loadKillCounts = async function (characterName) {
    const table = document.getElementById('kill-counts-table');
    if (!table) return;
    table.innerHTML = '';
    try {
        const res = await fetch(`/api/kill_counts.php?character=${encodeURIComponent(characterName || '')}`, { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || 'Failed to load kill counts.');
        window.killCountsData = data;
        renderKillCounts();
    } catch (e) {
        table.innerHTML = `<div style="color:#ff4444; font-size:0.8rem;">⚠️ ${e.message}</div>`;
    }
};

function renderKillCounts() {
    const data = window.killCountsData;
    const tabs = document.getElementById('kill-counts-tabs');
    const table = document.getElementById('kill-counts-table');
    const summary = document.getElementById('kill-counts-summary');
    if (!data || !tabs || !table) return;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch]);
    const fmt = n => (n ? Number(n).toLocaleString() : '<span style="opacity:0.3;">0</span>');
    const eps = Object.keys(data.episodes || {});
    if (summary) {
        summary.textContent = data.totals && data.totals.kills
            ? [[data.totals.kills, 'kill', 'kills'], [data.totals.bosses, 'boss', 'bosses'], [data.totals.rares, 'rare enemy', 'rare enemies']]
                .map(([n, one, many]) => `${Number(n).toLocaleString()} ${_t(Number(n) === 1 ? one : many)}`).join(' · ')
            : '';
    }
    if (!eps.length) {
        tabs.innerHTML = '';
        table.innerHTML = `<p style="color:rgba(255,255,255,0.5); font-size:0.8rem;">${_t('No kills recorded for this character yet. Kills are counted while you play once the server reports them.')}</p>`;
        return;
    }
    if (!eps.includes(window.killCountsEpisode)) window.killCountsEpisode = eps[0];
    tabs.innerHTML = eps.map(ep => `<button class="dl-btn${ep === window.killCountsEpisode ? ' active' : ''}" style="font-size:0.75rem; padding:4px 10px;"
        onclick="window.killCountsEpisode='${ep}'; renderKillCounts();">${_t('Episode')} ${ep}</button>`).join('');
    const rows = data.episodes[window.killCountsEpisode] || [];
    const diffs = ['Normal', 'Hard', 'Very Hard', 'Ultimate'];
    const anyChallenge = rows.some(r => r.challenge > 0);
    const head = [_t('Enemy'), ...diffs.map(d => _t(d)), ...(anyChallenge ? [_t('Challenge')] : []), _t('Total')];
    const body = rows.map(r => {
        const tag = r.boss ? ' <span title="Boss" style="color:#ffcc33;">♛</span>' : (r.rare ? ' <span title="Rare" style="color:#c77dff;">★</span>' : '');
        const alt = r.ultimate_name ? ` <span style="opacity:0.5; font-size:0.85em;">(${esc(r.ultimate_name)})</span>` : '';
        const cells = [...diffs.map(d => fmt(r.counts[d])), ...(anyChallenge ? [fmt(r.challenge)] : []), `<strong>${fmt(r.total)}</strong>`];
        return `<tr><td style="text-align:left; padding:3px 8px;">${esc(r.name || r.enemy)}${alt}${tag}</td>${cells.map(c => `<td style="text-align:right; padding:3px 8px;">${c}</td>`).join('')}</tr>`;
    }).join('');
    table.innerHTML = `<table style="width:100%; border-collapse:collapse; font-size:0.8rem; font-family:'Share Tech Mono',monospace;">
        <thead><tr>${head.map((h, i) => `<th style="text-align:${i ? 'right' : 'left'}; padding:4px 8px; color:#00ffff; border-bottom:1px solid rgba(0,255,255,0.25);">${esc(h)}</th>`).join('')}</tr></thead>
        <tbody>${body}</tbody></table>
        <p style="color:rgba(255,255,255,0.4); font-size:0.7rem; margin-top:6px;">${_t('Your own killing blows. A boss counts once per fight. Names in brackets are the Ultimate forms.')}</p>`;
}

function renderInventory() {
    const c = window.activeCharData;
    if (!c || !c.inventory) return;

    const gearSlots = {
        'weapon': null, 'armor': null, 'shield': null,
        'unit1': null, 'unit2': null, 'unit3': null, 'unit4': null, 'mag': null
    };
    let unitCount = 1;

    c.inventory.forEach(item => {
        if (item.equipped) {
            if (item.group === 0x00) {
                gearSlots['weapon'] = item;
            } else if (item.group === 0x01) {
                // type1: 1=Armor, 2=Shield, 3=Unit
                if (item.type1 === 1) gearSlots['armor'] = item;
                else if (item.type1 === 2) gearSlots['shield'] = item;
                else if (item.type1 === 3 && unitCount <= 4) {
                    gearSlots[`unit${unitCount}`] = item;
                    unitCount++;
                }
            } else if (item.group === 0x02) {
                gearSlots['mag'] = item;
            }
        }
    });

    // --- Populate Paper Doll Slots ---
    const slotNames = ['weapon', 'armor', 'shield', 'unit1', 'unit2', 'unit3', 'unit4', 'mag'];
    const namesListEl = document.getElementById('equipped-item-names');
    if (namesListEl) namesListEl.innerHTML = '';

    slotNames.forEach(key => {
        const slotBox = document.getElementById(`pd-slot-${key}`);
        if (!slotBox) return;
        slotBox.innerHTML = '';
        slotBox.className = 'pd-slot-box' + (key === 'armor' ? ' pd-armor' : '');
        slotBox.removeAttribute('data-hex');

        const item = gearSlots[key];
        if (item) {
            slotBox.setAttribute('data-hex', item.hex);

            // PSOBB Vector Icon
            const iconWrap = document.createElement('span');
            iconWrap.className = 'pd-slot-icon';
            iconWrap.style.cssText = 'display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px;';
            iconWrap.appendChild(getItemCategoryIcon(item, 26));
            slotBox.appendChild(iconWrap);

            // Table-driven rarity glow
            if (item.is_rare) {
                slotBox.classList.add((item.stars >= 11) ? 'pd-rare-red' : 'pd-rare-orange');
            }

            // Add to names list (skip MAG — it has its own card)
            if (namesListEl && key !== 'mag') {
                const row = document.createElement('div');
                row.className = 'eq-name-row';
                const tag = document.createElement('span');
                tag.className = 'eq-slot-tag';
                tag.textContent = key.replace(/(\d)/, ' $1').toUpperCase();
                const nameSpan = document.createElement('span');
                nameSpan.className = 'eq-item-name';
                nameSpan.textContent = item.name;
                row.appendChild(tag);
                row.appendChild(nameSpan);
                namesListEl.appendChild(row);
            }
        }
    });

    // --- Legacy equipped grid (hidden, kept for tooltip compatibility) ---
    const equippedBox = document.getElementById('viewer-equipped-grid');
    if (equippedBox) equippedBox.style.display = 'none';

    // --- Backpack Grid ---
    const backpackGrid = document.getElementById('viewer-backpack-grid');
    if (backpackGrid) {
        backpackGrid.innerHTML = '';
        let count = 0;
        for (let i = 0; i < 30; i++) {
            const item = c.inventory[i] || null;
            if (item) count++;
            backpackGrid.appendChild(createItemSlotElement(item));
        }
        document.getElementById('viewer-backpack-count').textContent = `${count} / 30`;
    }

    // --- MAG Stats Card ---
    const magCard = document.getElementById('mag-stats-card');
    if (magCard) {
        const magItem = gearSlots['mag'];
        if (magItem && magItem.name) {
            magCard.style.display = 'block';
            // Parse MAG description: "Kalki LV26 9.83/9.56/8.02/0.58 52% 114IQ PB:E (black)"
            const desc = magItem.name;
            const lvMatch = desc.match(/LV(\d+)/i);
            const statMatch = desc.match(/([\d.]+)\/([\d.]+)\/([\d.]+)\/([\d.]+)/);
            const synchMatch = desc.match(/(\d+)%/);
            const iqMatch = desc.match(/(\d+)IQ/i);
            const pbMatch = desc.match(/PB:(\S+)/i);
            const colorMatch = desc.match(/\((\w+)\)/);
            const magName = desc.split(/\s+LV/i)[0] || 'MAG';
            const magLv = lvMatch ? lvMatch[1] : '?';
            const defVal = statMatch ? parseFloat(statMatch[1]) : 0;
            const powVal = statMatch ? parseFloat(statMatch[2]) : 0;
            const dexVal = statMatch ? parseFloat(statMatch[3]) : 0;
            const mindVal = statMatch ? parseFloat(statMatch[4]) : 0;
            const synchro = synchMatch ? synchMatch[1] : '?';
            const iq = iqMatch ? iqMatch[1] : '?';
            const pb = pbMatch ? pbMatch[1] : '--';
            const color = colorMatch ? colorMatch[1] : '';

            const maxStat = 200;
            const pct = v => Math.min(100, (v / maxStat) * 100);

            magCard.innerHTML = `
                <div class="mag-header">
                    <div class="mag-icon">${getItemCategoryIcon(magItem, 32).outerHTML}</div>
                    <div class="mag-name">${magName}</div>
                    <div class="mag-level">LV ${magLv}</div>
                </div>
                <div class="mag-stat-bars">
                    <div class="mag-stat-item"><span class="ms-label">DEF</span><div class="ms-bar"><div class="ms-fill ms-def" style="width:${pct(defVal)}%"></div></div><span class="ms-val">${defVal}</span></div>
                    <div class="mag-stat-item"><span class="ms-label">POW</span><div class="ms-bar"><div class="ms-fill ms-pow" style="width:${pct(powVal)}%"></div></div><span class="ms-val">${powVal}</span></div>
                    <div class="mag-stat-item"><span class="ms-label">DEX</span><div class="ms-bar"><div class="ms-fill ms-dex" style="width:${pct(dexVal)}%"></div></div><span class="ms-val">${dexVal}</span></div>
                    <div class="mag-stat-item"><span class="ms-label">MIND</span><div class="ms-bar"><div class="ms-fill ms-mind" style="width:${pct(mindVal)}%"></div></div><span class="ms-val">${mindVal}</span></div>
                </div>
                <div class="mag-info-row">
                    <span>Synchro: <span class="mi-val">${synchro}%</span></span>
                    <span>IQ: <span class="mi-val">${iq}</span></span>
                    <span>PB: <span class="mi-pb">${pb}</span></span>
                    ${color ? `<span>Color: <span class="mi-val">${color}</span></span>` : ''}
                </div>
            `;
        } else {
            magCard.style.display = 'none';
        }
    }

    setupTooltipTriggers();
}

// Render Bank Grid
function renderActiveBank() {
    const grid = document.getElementById('viewer-bank-grid');
    if (!grid) return;
    grid.innerHTML = '';

    const c = window.activeCharData;
    if (!c) return;

    const countEl = document.getElementById('viewer-bank-count');
    const currentBank = window.activeBankIndex === -1 ? c.shared_bank : c.bank;
    if (!currentBank || !currentBank.items) {
        // Show empty state
        if (countEl) countEl.textContent = '0 items';
        document.getElementById('viewer-bank-meseta').textContent = '0 Meseta';
        for (let i = 0; i < 200; i++) {
            grid.appendChild(createItemSlotElement(null));
        }
        setupTooltipTriggers();
        return;
    }

    if (countEl) countEl.textContent = `${currentBank.items.length.toLocaleString()} items`;
    document.getElementById('viewer-bank-meseta').textContent = parseInt(currentBank.meseta || 0).toLocaleString() + ' Meseta';

    const slotCount = Math.max(40, Math.min(1000, Math.ceil((currentBank.items.length + 10) / 10) * 10));
    for (let i = 0; i < slotCount; i++) {
        const item = currentBank.items[i] || null;
        grid.appendChild(createItemSlotElement(item));
    }

    setupTooltipTriggers();

    const searchInput = document.getElementById('viewer-bank-search');
    if (searchInput && searchInput.value) {
        filterBankGrid(searchInput.value.toLowerCase());
    }
}

// Trigger Bank Swap
window.triggerBankSwap = async function () {
    const c = window.activeCharData;
    const targetSelect = document.getElementById('viewer-bank-select');
    const swapResult = document.getElementById('bank-swap-result-msg');
    const swapBtn = document.getElementById('viewer-btn-swap-bank');
    if (!c || !targetSelect || !swapResult || !swapBtn) return;

    const targetBankIdx = parseInt(targetSelect.value);

    swapBtn.disabled = true;
    swapBtn.textContent = _t('SWAPPING...');
    swapResult.style.display = 'none';

    try {
        const response = await fetch('/api/bank_swap.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({
                character_name: c.name,
                target_bank_index: targetBankIdx
            })
        });

        const data = await response.json();
        if (response.ok && data.success) {
            swapResult.style.color = '#00ff88';
            swapResult.textContent = `✓ ${data.message}`;
            swapResult.style.display = 'block';
            setTimeout(() => window.loadCharSlot(window.activeSlot), 2000);
        } else {
            throw new Error(data.error || _t('Failed to swap bank.'));
        }
    } catch (e) {
        swapResult.style.color = '#ff4444';
        swapResult.textContent = `⚠️ ${e.message}`;
        swapResult.style.display = 'block';
    } finally {
        swapBtn.disabled = false;
        swapBtn.textContent = _t('Swap Bank in Game');
    }
};

// Section ID change render
function renderActiveCharacterSectionId(character) {
    const secIdContainer = document.getElementById('section-id-change-container');
    if (!secIdContainer) return;

    const _t = (typeof window.__ === 'function') ? window.__ : (s => s);

    let html = '';
    if (character) {
        html += `<p style="font-size:0.85rem; margin-bottom:8px; font-family:'Share Tech Mono',monospace;">${_t('Current Section ID:')} <strong class="section-id id-${character.section_id.toLowerCase()}">${character.section_id}</strong></p>`;
        if (character.level > 50) {
            html += `<p style="color: #ff4444; font-size:0.8rem; margin: 4px 0 0 0; font-weight:bold;">${_t('Only characters level 50 and below can change their Section ID.')}</p>`;
            secIdContainer.innerHTML = html;
            return;
        }
    }

    const secIdInfo = {
        'Viridia': _t('Partisans, Shots'),
        'Greenill': _t('Daggers, Rifles'),
        'Skyly': _t('Swords, Sealed J-Sword'),
        'Bluefull': _t('Partisans, Spread'),
        'Purplenum': _t('Mechguns, Units'),
        'Pinkal': _t('Wands, Force Weapons'),
        'Redria': _t('Slicers, Armors, Balanced'),
        'Oran': _t('Daggers, Handguns'),
        'Yellowboze': _t('All Weapons, Meseta'),
        'Whitill': _t('Slicers, High-end Rares')
    };
    const secIds = Object.keys(secIdInfo);

    html += `
        <div style="border: 1px solid rgba(0,255,255,0.2); background: rgba(0,0,0,0.5); padding: 12px; border-radius: 6px;">
            <h4 style="margin-top: 0; margin-bottom:10px; color: #00ffff; font-family:'Share Tech Mono',monospace; font-size:0.95rem;"><i class="fas fa-arrows-spin"></i> ${_t('Select New Section ID')}</h4>
            <div class="section-id-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; margin-bottom: 12px;">
                ${secIds.map((id) => `
                    <label class="secid-option-lbl" style="cursor: pointer; display: flex; align-items: center; gap: 8px; border: 1px solid ${id === character.section_id ? '#00ffff' : 'rgba(0,255,255,0.1)'}; padding: 8px 10px; border-radius: 6px; background: ${id === character.section_id ? 'rgba(0,255,255,0.1)' : 'transparent'}; transition: all 0.2s;" onclick="document.querySelectorAll('.secid-option-lbl').forEach(el=>{el.style.background='transparent';el.style.borderColor='rgba(0,255,255,0.1)'});this.style.background='rgba(0,255,255,0.1)';this.style.borderColor='#00ffff';">
                        <input type="radio" name="new-section-id" value="${id}" style="display: none;" ${id === character.section_id ? 'checked' : ''}>
                        <img src="/img/section_ids/${id}.png" alt="${id}" style="width: 22px; height: 22px; flex-shrink:0;">
                        <div style="min-width:0;">
                            <div style="font-size: 0.8rem; font-weight: bold; color: #eee; font-family:'Share Tech Mono',monospace;">${id}</div>
                            <div style="font-size: 0.6rem; color: #999; font-family:'Share Tech Mono',monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${secIdInfo[id]}</div>
                        </div>
                    </label>
                `).join('')}
            </div>
            <button id="btn-change-secid" onclick="triggerSectionIdChange()" class="dl-btn" style="width: 100%; border-color: #00ffff; background: rgba(0, 255, 255, 0.15); color: #00ffff; padding: 8px; font-weight: bold; font-family: 'Share Tech Mono', monospace; font-size:0.85rem;">${_t('Change Section ID')}</button>
            <div id="secid-message" style="margin-top: 8px; display: none; font-weight: bold; font-size:0.8rem;"></div>
        </div>
    `;
    secIdContainer.innerHTML = html;
}

window.triggerSectionIdChange = async function () {
    const checked = document.querySelector('input[name="new-section-id"]:checked');
    const c = window.activeCharData;
    const msgEl = document.getElementById('secid-message');
    const btn = document.getElementById('btn-change-secid');
    if (!checked || !c || !msgEl || !btn) return;

    const _t = (typeof window.__ === 'function') ? window.__ : (s => s);
    const newSecId = checked.value;
    btn.disabled = true;
    btn.textContent = _t("Processing...");
    msgEl.style.display = 'none';

    try {
        const response = await fetch('/api/change_section_id.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({ character_name: c.name, new_section_id: newSecId })
        });
        const data = await response.json();

        if (response.ok && data.success) {
            msgEl.textContent = `✓ ${data.message}`;
            msgEl.style.color = '#00C851';
            msgEl.style.display = 'block';
            btn.textContent = _t("Success");
            setTimeout(() => window.loadCharSlot(window.activeSlot), 2000);
        } else {
            throw new Error(data.error || _t("Failed to change Section ID."));
        }
    } catch (e) {
        msgEl.textContent = `⚠️ ${e.message}`;
        msgEl.style.color = '#ff4444';
        msgEl.style.display = 'block';
        btn.disabled = false;
        btn.textContent = _t("Change Section ID");
    }
};

// Material Reset
window.triggerMaterialReset = async function () {
    const c = window.activeCharData;
    const msgEl = document.getElementById('reset-mat-message');
    if (!c || !msgEl) return;

    const _t = (typeof window.__ === 'function') ? window.__ : (s => s);

    const cautionTemplate = _t("CAUTION: Are you absolutely sure you want to reset all consumed materials back to 0 for Character Slot %s (%s)?\n\nThis will permanently reset your character's stats and CANNOT be undone!");
    const cautionMsg = cautionTemplate
        .replace('%s', (window.activeSlot + 1))
        .replace('%s', c.name);

    const confirmed = confirm(cautionMsg);
    if (!confirmed) return;

    const typedConfirm = prompt(_t('To confirm this permanent reset, please type the word "WIPE" in all caps below:'));
    if (typedConfirm !== "WIPE") {
        alert(_t("Action cancelled. The confirmation word did not match."));
        return;
    }

    msgEl.textContent = _t("Recalibrating stats...");
    msgEl.style.color = "#ffaa00";
    msgEl.style.display = "block";

    try {
        const res = await fetch('/api/reset_materials.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({ slot: window.activeSlot })
        });
        const data = await res.json();
        if (res.ok && data.success) {
            msgEl.style.color = "#00ff88";
            msgEl.textContent = `✓ ${data.message}`;
            setTimeout(() => window.loadCharSlot(window.activeSlot), 2000);
        } else {
            throw new Error(data.error || _t('Failed to reset materials.'));
        }
    } catch (e) {
        msgEl.style.color = "#ff4444";
        msgEl.textContent = `⚠️ ${e.message}`;
    }
};

// Helper: item slot element creation
function psoWeaponSvg(kind, base, hi, grip) {
    switch (kind) {
        case 0: // Knuckle / Fist
            return `<path d="M9 12h11c2.5 0 4.5 2 4.5 4.5V20c0 2.5-2 4.5-4.5 4.5h-9c-2 0-3.5-1.5-3.5-3.5v-5.5C7.5 13.5 8 12 9 12z" fill="${base}" stroke="${hi}" stroke-width="1.2"/>
                    <circle cx="11" cy="14" r="1.4" fill="${hi}"/><circle cx="15" cy="14" r="1.4" fill="${hi}"/>
                    <circle cx="19" cy="14" r="1.4" fill="${hi}"/><rect x="9" y="19" width="13" height="2.5" rx="1" fill="${grip}"/>`;
        case 2: // Sword (Greatsword)
            return `<path d="M25 4l3 3-13.5 13.5-3.8-3.8L25 4z" fill="${base}" stroke="${hi}" stroke-width="1.1"/>
                    <path d="M24 6l-10 10" stroke="${hi}" stroke-width="1" opacity="0.8"/>
                    <path d="M9 14l9 9-2 2-9-9z" fill="${grip}" stroke="${hi}" stroke-width="0.9"/>
                    <path d="M5 27l5-5" stroke="${grip}" stroke-width="2.6" stroke-linecap="round"/>`;
        case 3: // Dagger
            return `<path d="M23 5l1.5 1.5-8 9.5-2.2-2.2L23 5z" fill="${base}" stroke="${hi}" stroke-width="1"/>
                    <path d="M26 11l1.5 1.5-8 9.5-2.2-2.2L26 11z" fill="${base}" stroke="${hi}" stroke-width="1"/>
                    <path d="M9 21l4.5-4.5M12 26l4.5-4.5" stroke="${grip}" stroke-width="2.2" stroke-linecap="round"/>`;
        case 4: // Partisan (Halberd)
            return `<line x1="6" y1="26" x2="21" y2="11" stroke="${grip}" stroke-width="2.2" stroke-linecap="round"/>
                    <path d="M27 5l-2 9-4-1.5-1.5-4z" fill="${base}" stroke="${hi}" stroke-width="1.1"/>
                    <line x1="17" y1="9" x2="23" y2="15" stroke="${hi}" stroke-width="1.4" stroke-linecap="round"/>`;
        case 5: // Slicer
            return `<path d="M7 18C7 10 14 5 22 6c-3 2.2-4.5 5-4 8.5 3.5.5 6.3-1 8.5-4-1 8-6 15-14 15-3 0-5.5-2.5-5.5-7.5z" fill="${base}" stroke="${hi}" stroke-width="1.2"/>
                    <circle cx="14" cy="18" r="2.5" fill="${grip}" stroke="${hi}" stroke-width="1"/>`;
        case 6: // Handgun
            return `<path d="M6 10h16l2 2v3l-12 .5v2.5H7.5V10z" fill="${base}" stroke="${hi}" stroke-width="1.1"/>
                    <path d="M8 15.5h5l-1.5 9.5H7.5z" fill="${grip}" stroke="${hi}" stroke-width="1"/>
                    <rect x="14" y="11.5" width="7" height="1.6" fill="${hi}"/>`;
        case 7: // Rifle
            return `<rect x="4" y="13" width="23" height="3.2" rx="1" fill="${base}" stroke="${hi}" stroke-width="1.1"/>
                    <rect x="13" y="10" width="8" height="2" rx="0.8" fill="${hi}" opacity="0.9"/>
                    <path d="M6 16.2h6l-1.5 7H5.5zM15 16.2h2.5l-.8 4.5H14z" fill="${grip}"/>`;
        case 8: // Mechgun
            return `<path d="M6 9h14v4H10l-1 6H6V9z" fill="${base}" stroke="${hi}" stroke-width="1"/>
                    <path d="M12 15h14v4H16l-1 6h-3V15z" fill="${base}" stroke="${hi}" stroke-width="1"/>
                    <rect x="14" y="10.2" width="4" height="1.4" fill="${hi}"/>
                    <rect x="20" y="16.2" width="4" height="1.4" fill="${hi}"/>`;
        case 9: // Shot
            return `<path d="M5 10h18l4 2.5v5l-4 2.5H5V10z" fill="${base}" stroke="${hi}" stroke-width="1.2"/>
                    <line x1="11" y1="12.5" x2="25" y2="12.5" stroke="${hi}" stroke-width="1.2"/>
                    <line x1="11" y1="15" x2="26" y2="15" stroke="${hi}" stroke-width="1.2"/>
                    <line x1="11" y1="17.5" x2="25" y2="17.5" stroke="${hi}" stroke-width="1.2"/>
                    <rect x="7" y="19.5" width="4" height="5" rx="0.8" fill="${grip}"/>`;
        case 10: // Cane
        case 11: // Rod
            return `<line x1="7" y1="26" x2="21" y2="12" stroke="${grip}" stroke-width="2.4" stroke-linecap="round"/>
                    <circle cx="22.5" cy="9.5" r="4.2" fill="${base}" stroke="${hi}" stroke-width="1.2"/>
                    <circle cx="22.5" cy="9.5" r="1.6" fill="${hi}"/>`;
        case 12: // Wand
            return `<line x1="7" y1="25" x2="19" y2="13" stroke="${grip}" stroke-width="2.2" stroke-linecap="round"/>
                    <path d="M21 5l2.2 4.5L28 10l-3.8 3.2.8 4.8L21 15.5 17 18l.8-4.8L14 10l4.8-.5z" fill="${base}" stroke="${hi}" stroke-width="1"/>`;
        case 13: // Claw
            return `<path d="M7 22l5-5 4 4-5 5z" fill="${grip}" stroke="${hi}" stroke-width="1"/>
                    <path d="M12 16L22 6l-6 12z" fill="${base}" stroke="${hi}" stroke-width="1"/>
                    <path d="M14 19L27 11l-9 10z" fill="${base}" stroke="${hi}" stroke-width="1"/>`;
        case 14: // Double Saber
            return `<path d="M19.5 10l6.5-5.5-4.5 7.5z" fill="${base}" stroke="${hi}" stroke-width="1.1"/>
                    <path d="M12.5 22L6 27.5 10.5 20z" fill="${base}" stroke="${hi}" stroke-width="1.1"/>
                    <line x1="11" y1="21" x2="21" y2="11" stroke="${grip}" stroke-width="2.8" stroke-linecap="round"/>`;
        case 15: // Twin Sword
            return `<path d="M24 5l2 2-13 13-2-2z" fill="${base}" stroke="${hi}" stroke-width="1"/>
                    <path d="M8 5l-2 2 13 13 2-2z" fill="${base}" stroke="${hi}" stroke-width="1"/>
                    <path d="M7 25l4-4M25 25l-4-4" stroke="${grip}" stroke-width="2.2" stroke-linecap="round"/>`;
        case 16: // Katana
            return `<path d="M26 5c-4 6-9.5 11.5-15.5 15.5l-1.5-1.8C14.8 14.5 20.5 9 25 4.2z" fill="${base}" stroke="${hi}" stroke-width="1.1"/>
                    <line x1="8" y1="17" x2="13" y2="22" stroke="${grip}" stroke-width="2.2" stroke-linecap="round"/>
                    <line x1="5.5" y1="26" x2="10.5" y2="21" stroke="${grip}" stroke-width="2" stroke-linecap="round"/>`;
        case 17: // Launcher
            return `<path d="M4 11h17l5 2v6l-5 2H4V11z" fill="${base}" stroke="${hi}" stroke-width="1.2"/>
                    <circle cx="24.5" cy="16" r="2.2" fill="${hi}"/>
                    <rect x="7" y="21" width="4" height="4.5" fill="${grip}"/><rect x="15" y="21" width="3" height="3.5" fill="${grip}"/>`;
        case 18: // Card / Talis
            return `<rect x="7" y="9" width="9" height="14" rx="1.5" transform="rotate(-14 11 16)" fill="${base}" stroke="${hi}" stroke-width="1.1"/>
                    <rect x="14" y="8" width="9" height="14" rx="1.5" transform="rotate(10 18 15)" fill="${base}" stroke="${hi}" stroke-width="1.1"/>
                    <path d="M18.5 12l2 3-2 3-2-3z" fill="${hi}"/>`;
        case 1: // Saber (default)
        default:
            return `<path d="M24 5l3 2.5-14 13.5-2.5-2.5z" fill="${base}" stroke="${hi}" stroke-width="1.1"/>
                    <line x1="8" y1="17" x2="14" y2="23" stroke="${grip}" stroke-width="2.2" stroke-linecap="round"/>
                    <line x1="6" y1="26" x2="11" y2="21" stroke="${grip}" stroke-width="2.4" stroke-linecap="round"/>`;
    }
}

function psoToolSvg(item) {
    const t1 = item.type1 || 0;
    const t2 = item.type2 || 0;
    if (t1 === 0 || t1 === 1) {
        // Mate (green) / Fluid (blue) angled medical canister
        const col = (t1 === 0) ? '#34d399' : '#38bdf8';
        const hi = (t1 === 0) ? '#bbf7d0' : '#bae6fd';
        const stripes = Math.min(3, t2 + 1);
        let stripeSvg = '';
        for (let i = 0; i < stripes; i++) {
            stripeSvg += `<line x1="11" y1="${14 + i * 3.2}" x2="21" y2="${14 + i * 3.2}" stroke="${hi}" stroke-width="1.5"/>`;
        }
        return `<rect x="12" y="5" width="8" height="3.5" rx="1" fill="${hi}"/>
                <rect x="9" y="8.5" width="14" height="18" rx="3" fill="${col}" fill-opacity="0.88" stroke="${hi}" stroke-width="1.2"/>
                ${stripeSvg}`;
    }
    if (t1 === 2) {
        // Tech Disk (element tinted ring)
        const elemColors = {
            fire: '#ff6b4a', ice: '#38bdf8', bolt: '#facc15',
            light: '#fef08a', dark: '#c084fc', support: '#4ade80'
        };
        const ring = elemColors[item.tech_element] || '#34d399';
        return `<circle cx="16" cy="16" r="10.5" fill="#1e293b" stroke="${ring}" stroke-width="2"/>
                <circle cx="16" cy="16" r="6" fill="none" stroke="${ring}" stroke-width="1.5" opacity="0.85"/>
                <circle cx="16" cy="16" r="2.4" fill="#e2e8f0"/>`;
    }
    if (t1 >= 3 && t1 <= 5) {
        // Sol / Moon / Star Atomizer
        const col = t1 === 3 ? '#facc15' : (t1 === 4 ? '#fde047' : '#f472b6');
        return `<rect x="13" y="5" width="6" height="3" rx="1" fill="#e2e8f0"/>
                <path d="M16 9l7 5v9c0 2.5-3 4-7 4s-7-1.5-7-4v-9z" fill="${col}" fill-opacity="0.88" stroke="#fef9c3" stroke-width="1.1"/>
                <circle cx="16" cy="18" r="2.5" fill="#fff" opacity="0.7"/>`;
    }
    if (t1 === 7) {
        // Telepipe
        return `<ellipse cx="16" cy="19" rx="9" ry="4" fill="none" stroke="#38bdf8" stroke-width="2"/>
                <path d="M11 19V9M21 19V9" stroke="#7dd3fc" stroke-width="1.5" stroke-dasharray="2 2"/>
                <ellipse cx="16" cy="9" rx="6" ry="2.5" fill="#38bdf8" fill-opacity="0.4" stroke="#bae6fd" stroke-width="1"/>`;
    }
    if (t1 === 9) {
        // Scape Doll
        return `<circle cx="16" cy="9.5" r="3.8" fill="#f472b6" stroke="#fbcfe8" stroke-width="1.1"/>
                <path d="M11 14h10l2 10H9z" fill="#ec4899" fill-opacity="0.88" stroke="#fbcfe8" stroke-width="1.1"/>
                <circle cx="14.7" cy="9.2" r="0.8" fill="#1e1b4b"/><circle cx="17.3" cy="9.2" r="0.8" fill="#1e1b4b"/>`;
    }
    if (t1 === 10) {
        // Grinder
        return `<polygon points="16,4 24,9 24,23 16,28 8,23 8,9" fill="#fb923c" fill-opacity="0.88" stroke="#fed7aa" stroke-width="1.2"/>
                <polygon points="16,8 20,11 20,21 16,24 12,21 12,11" fill="#fde047" opacity="0.45"/>`;
    }
    if (t1 === 11) {
        // Material
        return `<rect x="10" y="6" width="12" height="20" rx="6" fill="#4ade80" fill-opacity="0.85" stroke="#bbf7d0" stroke-width="1.2"/>
                <path d="M10 16h12v4c0 3.3-2.7 6-6 6s-6-2.7-6-6z" fill="#facc15" fill-opacity="0.85"/>`;
    }
    if (t1 === 15) {
        // AddSlot
        return `<rect x="7" y="7" width="18" height="18" rx="3" fill="#0ea5e9" fill-opacity="0.35" stroke="#38bdf8" stroke-width="1.4"/>
                <path d="M16 11v10M11 16h10" stroke="#bae6fd" stroke-width="2.2" stroke-linecap="round"/>`;
    }
    if (t1 === 16) {
        // Photon Drop / Sphere / Crystal
        const col = t2 === 1 ? '#fde047' : (t2 === 2 ? '#f472b6' : '#38bdf8');
        const hi = t2 === 1 ? '#fef9c3' : '#e0f2fe';
        return `<polygon points="16,4 24,14 20,27 12,27 8,14" fill="${col}" fill-opacity="0.88" stroke="${hi}" stroke-width="1.2"/>
                <polygon points="16,4 20,14 16,27 12,14" fill="${hi}" opacity="0.35"/>`;
    }
    if (t1 === 12 || t1 === 13 || t1 === 14) {
        // Mag Cell / Enemy Part / Rare Item
        return `<polygon points="16,5 25,13 21,26 11,26 7,13" fill="#c084fc" fill-opacity="0.85" stroke="#f3e8ff" stroke-width="1.2"/>
                <circle cx="16" cy="16" r="3" fill="#f0abfc"/>`;
    }
    // Generic Tool / Antidote / Badge / Report
    return `<rect x="11" y="5" width="10" height="3.5" rx="1" fill="#a7f3d0"/>
            <path d="M10 9h12l2 14c0 2-1.8 3.5-4 3.5H12c-2.2 0-4-1.5-4-3.5z" fill="#34d399" fill-opacity="0.85" stroke="#a7f3d0" stroke-width="1.1"/>`;
}

function getItemCategoryIcon(item, size = 32) {
    const svgNS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('viewBox', '0 0 32 32');
    svg.setAttribute('width', size);
    svg.setAttribute('height', size);

    const group = item ? item.group : 3;
    let paths = '';

    if (group === 0x00) {
        const base = item.is_rare ? '#ff3b5c' : '#ff5c6c';
        const hi = item.is_rare ? '#fde68a' : '#ffd1d6';
        const grip = item.is_rare ? '#f59e0b' : '#cbd5e1';
        paths = psoWeaponSvg(item.weapon_kind ?? 1, base, hi, grip);
    } else if (group === 0x01) {
        if (item.type1 === 1) {
            // Frame / Armor
            const c = item.is_rare ? '#fb923c' : '#38bdf8';
            const h = item.is_rare ? '#ffedd5' : '#bae6fd';
            paths = `<path d="M7 9l5-3h8l5 3 2 6-4 2-1 9H10l-1-9-4-2z" fill="${c}" fill-opacity="0.85" stroke="${h}" stroke-width="1.2"/>
                     <polygon points="16,11 19,15 16,19 13,15" fill="${h}" opacity="0.85"/>`;
        } else if (item.type1 === 2) {
            // Barrier / Shield
            const c = item.is_rare ? '#fb923c' : '#22d3ee';
            const h = item.is_rare ? '#ffedd5' : '#a5f3fc';
            paths = `<path d="M16 4l10 4v8c0 6.5-4.4 10.5-10 12.5C10.4 26.5 6 22.5 6 16V8z" fill="${c}" fill-opacity="0.82" stroke="${h}" stroke-width="1.3"/>
                     <circle cx="16" cy="15" r="4" fill="none" stroke="${h}" stroke-width="1.4" opacity="0.9"/>`;
        } else {
            // Slot Unit
            const c = item.is_rare ? '#fbbf24' : '#60a5fa';
            const h = item.is_rare ? '#fef08a' : '#dbeafe';
            paths = `<rect x="7" y="8" width="18" height="16" rx="2.5" fill="${c}" fill-opacity="0.85" stroke="${h}" stroke-width="1.2"/>
                     <path d="M10 6v2M14 6v2M18 6v2M22 6v2M10 24v2M14 24v2M18 24v2M22 24v2" stroke="#fcd34d" stroke-width="1.5"/>
                     <rect x="12" y="13" width="8" height="6" rx="1" fill="${h}" opacity="0.85"/>`;
        }
    } else if (group === 0x02) {
        // MAG floating photon companion
        const c = (item && item.is_rare) ? '#fb7185' : '#fbbf24';
        const h = '#fef08a';
        paths = `<path d="M4 13l6-3v7l-5 2zM28 13l-6-3v7l5 2z" fill="${c}" stroke="${h}" stroke-width="1"/>
                 <polygon points="16,6 23,13 21,24 11,24 9,13" fill="${c}" stroke="${h}" stroke-width="1.2"/>
                 <circle cx="16" cy="15.5" r="3.4" fill="#22d3ee" stroke="#ecfeff" stroke-width="1"/>`;
    } else if (group === 0x04) {
        // Meseta Crystal (faceted yellow gem)
        paths = `<polygon points="16,4 24,10 24,22 16,28 8,22 8,10" fill="#facc15" stroke="#fef9c3" stroke-width="1.3"/>
                 <polygon points="16,4 20,11 20,21 16,28 12,21 12,11" fill="#fef08a" opacity="0.5"/>`;
    } else {
        paths = psoToolSvg(item || {});
    }

    svg.innerHTML = paths;
    return svg;
}

function createItemSlotElement(item, label = '') {
    const slotEl = document.createElement('div');
    slotEl.className = 'item-slot';

    if (item) {
        slotEl.classList.add('filled');
        slotEl.setAttribute('data-hex', item.hex);

        if (item.is_rare) {
            slotEl.classList.add((item.stars >= 11) ? 'rare-red' : 'rare-orange');
            const pip = document.createElement('span');
            pip.className = 'item-slot-rare-pip';
            slotEl.appendChild(pip);
        }

        const iconSvg = getItemCategoryIcon(item);
        iconSvg.classList.add('item-slot-icon');
        slotEl.appendChild(iconSvg);

        if (item.equipped) {
            const eqBadge = document.createElement('span');
            eqBadge.className = 'item-slot-eq-pip';
            eqBadge.textContent = 'E';
            slotEl.appendChild(eqBadge);
        }

        let badgeText = '';
        let badgeGold = false;
        if (item.group === 0x03 && item.type1 === 2 && item.tech_level) {
            badgeText = `Lv${item.tech_level}`;
            badgeGold = item.tech_level >= 30;
        } else if (item.group === 0x03 && item.count > 1) {
            badgeText = `x${item.count}`;
        } else if (item.group === 0x00 && item.grind > 0) {
            badgeText = `+${item.grind}`;
            badgeGold = true;
        } else if (item.group === 0x02 && item.mag_stats && item.mag_stats.level !== undefined) {
            badgeText = `Lv${item.mag_stats.level}`;
        }

        if (badgeText) {
            const badge = document.createElement('span');
            badge.className = 'item-slot-count';
            if (badgeGold) badge.style.color = '#fde047';
            badge.textContent = badgeText;
            slotEl.appendChild(badge);
        }
    }

    if (label) {
        const lbl = document.createElement('span');
        lbl.className = 'item-slot-label';
        lbl.textContent = label;
        slotEl.appendChild(lbl);
    }

    return slotEl;
}

// Tooltip mechanisms
function setupTooltipTriggers() {
    let tooltip = document.getElementById('viewer-tooltip');
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.id = 'viewer-tooltip';
        tooltip.className = 'item-tooltip';
        document.body.appendChild(tooltip);
    }

    document.querySelectorAll('.item-slot, .pd-slot-box').forEach(slot => {
        const hex = slot.getAttribute('data-hex');
        if (!hex) return;

        slot.onmouseenter = (e) => {
            const item = findItemByHex(hex);
            if (!item) return;

            let detailsHtml = '';

            // Weapon details
            if (item.group === 0x00) {
                if (item.grind > 0) {
                    detailsHtml += `<div class="tooltip-stat-row"><span>Grind:</span><span class="tooltip-stat-val" style="color:#ffcc00;">+${item.grind}</span></div>`;
                }
                if (item.attrs && item.attrs.length > 0) {
                    item.attrs.forEach(a => {
                        const color = a.value >= 0 ? '#00ff88' : '#ff4444';
                        detailsHtml += `<div class="tooltip-stat-row"><span>${a.type}:</span><span class="tooltip-stat-val" style="color:${color};">${a.value > 0 ? '+' : ''}${a.value}%</span></div>`;
                    });
                }
                if (item.unidentified) {
                    detailsHtml += `<div style="color:#ff8800; font-size:0.7rem; margin-top:4px;">⚠ Unidentified</div>`;
                }
            }

            // Armor details
            if (item.group === 0x01 && item.type1 === 1) {
                if (item.slots !== undefined) detailsHtml += `<div class="tooltip-stat-row"><span>Slots:</span><span class="tooltip-stat-val" style="color:#00ccff;">${item.slots}</span></div>`;
                if (item.def_bonus) detailsHtml += `<div class="tooltip-stat-row"><span>DEF:</span><span class="tooltip-stat-val" style="color:#00ff88;">+${item.def_bonus}</span></div>`;
                if (item.evp_bonus) detailsHtml += `<div class="tooltip-stat-row"><span>EVP:</span><span class="tooltip-stat-val" style="color:#00ff88;">+${item.evp_bonus}</span></div>`;
            }

            // Shield details
            if (item.group === 0x01 && item.type1 === 2) {
                if (item.def_bonus) detailsHtml += `<div class="tooltip-stat-row"><span>DEF:</span><span class="tooltip-stat-val" style="color:#00ff88;">+${item.def_bonus}</span></div>`;
                if (item.evp_bonus) detailsHtml += `<div class="tooltip-stat-row"><span>EVP:</span><span class="tooltip-stat-val" style="color:#00ff88;">+${item.evp_bonus}</span></div>`;
            }

            // Unit details
            if (item.group === 0x01 && item.type1 === 3 && item.modifier) {
                detailsHtml += `<div class="tooltip-stat-row"><span>Modifier:</span><span class="tooltip-stat-val" style="color:#cc88ff;">+${item.modifier}</span></div>`;
            }

            // MAG details with stat bars
            if (item.group === 0x02 && item.mag_stats) {
                const ms = item.mag_stats;
                const maxStat = Math.max(ms.def, ms.pow, ms.dex, ms.mind, 1);
                const barMax = ms.level || maxStat;

                detailsHtml += `<div class="tooltip-stat-row"><span>Level:</span><span class="tooltip-stat-val" style="color:#ffcc00;">${ms.level}</span></div>`;

                const magStats = [
                    { label: 'DEF', val: ms.def, color: '#4ecdc4' },
                    { label: 'POW', val: ms.pow, color: '#ff6b6b' },
                    { label: 'DEX', val: ms.dex, color: '#a78bfa' },
                    { label: 'MIND', val: ms.mind, color: '#60a5fa' }
                ];

                magStats.forEach(s => {
                    const pct = barMax > 0 ? Math.min((s.val / barMax) * 100, 100) : 0;
                    detailsHtml += `
                        <div style="display:flex; align-items:center; gap:6px; margin:3px 0;">
                            <span style="width:36px; font-size:0.65rem; color:#aaa; text-align:right;">${s.label}</span>
                            <div style="flex:1; height:10px; background:rgba(255,255,255,0.08); border-radius:5px; overflow:hidden; min-width:80px;">
                                <div style="height:100%; width:${pct}%; background:linear-gradient(90deg, ${s.color}88, ${s.color}); border-radius:5px; transition:width 0.3s;"></div>
                            </div>
                            <span style="width:24px; font-size:0.65rem; color:${s.color}; text-align:right; font-weight:600;">${Math.floor(s.val)}</span>
                        </div>`;
                });

                detailsHtml += `<div style="display:flex; justify-content:space-between; margin-top:5px; font-size:0.6rem; color:#888;">
                    <span>Synchro: <span style="color:#fbbf24;">${ms.synchro}%</span></span>
                    <span>IQ: <span style="color:#34d399;">${ms.iq}</span></span>
                </div>`;
            }

            // Tool count / Technique Level
            if (item.group === 0x03) {
                if (item.type1 === 2 && item.tech_level) {
                    detailsHtml += `<div class="tooltip-stat-row"><span>Level:</span><span class="tooltip-stat-val" style="color:#66e0ff;">Lv.${item.tech_level}</span></div>`;
                } else if (item.count && item.count > 1) {
                    detailsHtml += `<div class="tooltip-stat-row"><span>Quantity:</span><span class="tooltip-stat-val" style="color:#00ccff;">x${item.count}</span></div>`;
                }
            }

            // Legacy stats fallback
            if (!detailsHtml && item.stats) {
                Object.keys(item.stats).forEach(s => {
                    detailsHtml += `<div class="tooltip-stat-row"><span>${s}:</span><span class="tooltip-stat-val">${item.stats[s]}</span></div>`;
                });
            }

            const starCount = parseInt(item.stars, 10) || 0;
            const starHtml = starCount > 0
                ? `<div style="color:${item.is_rare ? '#ff9d2e' : '#facc15'}; font-size:0.68rem; letter-spacing:1px; margin-bottom:2px;">${'★'.repeat(Math.min(starCount, 12))}</div>`
                : '';

            tooltip.innerHTML = `
                <div class="tooltip-title" style="${item.is_rare ? 'color:#ff7b7b;' : ''}">${item.name}</div>
                ${starHtml}
                ${detailsHtml}
            `;
            tooltip.style.display = 'block';
        };

        slot.onmousemove = (e) => {
            tooltip.style.left = (e.pageX + 15) + 'px';
            tooltip.style.top = (e.pageY + 15) + 'px';
        };

        slot.onmouseleave = () => {
            tooltip.style.display = 'none';
        };
    });
}

function findItemByHex(hex) {
    if (!window.activeCharData) return null;

    let found = window.activeCharData.inventory.find(i => i.hex === hex);
    if (found) return found;

    const currentBank = window.activeBankIndex === -1 ? window.activeCharData.shared_bank : window.activeCharData.bank;
    if (currentBank && currentBank.items) {
        found = currentBank.items.find(i => i && i.hex === hex);
    }
    if (found) return found;

    // Also search the other bank as fallback
    const otherBank = window.activeBankIndex === -1 ? window.activeCharData.bank : window.activeCharData.shared_bank;
    if (otherBank && otherBank.items) {
        found = otherBank.items.find(i => i && i.hex === hex);
    }
    return found || null;
}

function filterBankGrid(query) {
    document.querySelectorAll('#viewer-bank-grid .item-slot').forEach(slot => {
        const hex = slot.getAttribute('data-hex');
        if (!hex) {
            // Empty slot: hide when searching, show when cleared
            slot.style.display = query ? 'none' : '';
            return;
        }
        const item = findItemByHex(hex);
        if (!query || (item && item.name.toLowerCase().includes(query))) {
            slot.style.display = '';
        } else {
            slot.style.display = 'none';
        }
    });
}

// Chat select population
function populateChatCharacterSelect() {
    const select = document.getElementById('chat-character-select');
    if (!select || !window.activeCharData) return;

    const charName = window.activeCharData.name;
    let exists = false;
    for (let i = 0; i < select.options.length; i++) {
        if (select.options[i].value === charName) {
            exists = true;
            break;
        }
    }
    if (!exists) {
        const opt = document.createElement('option');
        opt.value = charName;
        opt.textContent = `${charName} (Lvl ${window.activeCharData.level})`;
        select.appendChild(opt);
    }
    select.value = charName;
}

// Web-to-Game chat sender
window.sendWebToGameMessage = async function () {
    const select = document.getElementById('chat-character-select');
    const input = document.getElementById('chat-message-input');
    const statusMsg = document.getElementById('chat-status-message');
    const log = document.getElementById('chat-messages-log');
    const btn = document.getElementById('chat-send-btn');
    if (!select || !input || !statusMsg || !log || !btn) return;

    const charName = select.value;
    const msg = input.value.trim();

    if (!charName) {
        statusMsg.style.color = '#ff4444';
        statusMsg.textContent = '⚠️ ' + _t('Please select a character.');
        statusMsg.style.display = 'block';
        return;
    }
    if (!msg) {
        statusMsg.style.color = '#ff4444';
        statusMsg.textContent = '⚠️ ' + _t('Message cannot be empty.');
        statusMsg.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Sending...';
    statusMsg.style.display = 'none';

    try {
        const response = await fetch('/api/send_chat_message.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({
                character_name: charName,
                message: msg
            })
        });

        const data = await response.json();
        if (response.ok && data.success) {
            statusMsg.style.color = '#00ff88';
            statusMsg.textContent = `✓ Message sent to game!`;
            statusMsg.style.display = 'block';
            input.value = '';

            appendChatBubble('sent', `[${charName}]: ${msg}`);
        } else {
            throw new Error(data.error || 'Failed to send message.');
        }
    } catch (e) {
        statusMsg.style.color = '#ff4444';
        statusMsg.textContent = `⚠️ ${e.message}`;
        statusMsg.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> ' + _t('Send');
    }
};

// Enter key to send chat message
document.addEventListener('DOMContentLoaded', () => {
    const chatInput = document.getElementById('chat-message-input');
    if (chatInput) {
        chatInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                window.sendWebToGameMessage();
            }
        });
    }
});

// ---- LFG Feed for Dashboard Tab ----
window._lfgFeedInterval = null;

window.loadLfgFeed = async function () {
    const container = document.getElementById('lfg-feed-container');
    if (!container) return;

    // Start auto-refresh if not already running
    if (!window._lfgFeedInterval) {
        window._lfgFeedInterval = setInterval(() => {
            const lfgPane = document.getElementById('tab-lfg');
            if (lfgPane && lfgPane.classList.contains('active')) {
                window.loadLfgFeed();
            } else {
                clearInterval(window._lfgFeedInterval);
                window._lfgFeedInterval = null;
            }
        }, 15000);
    }

    try {
        const [listingsRes, gamesRes] = await Promise.all([
            fetch('/api/lfg_requests.php', { credentials: 'same-origin' }),
            fetch('/api/lfg_games.php', { credentials: 'same-origin' })
        ]);
        const listingsData = await listingsRes.json();
        const gamesData = await gamesRes.json();

        const listings = (listingsData.success && listingsData.listings) ? listingsData.listings : [];
        const games = (gamesData.success && gamesData.games) ? gamesData.games : [];

        if (listings.length === 0) {
            container.innerHTML = `
                <div style="text-align:center; color:#888; padding:3rem; border:1px dashed rgba(255,255,255,0.1); border-radius:8px;">
                    <i class="fas fa-clipboard-list" style="font-size:2.5rem; margin-bottom:10px; color:#ffaa00;"></i><br>
                    ${_t('No active LFG posts found.')}<br>
                    <a href="lfg.php" style="color:#ffaa00; font-size:0.85rem;">${_t('Create one from the full LFG Terminal →')}</a>
                </div>`;
            return;
        }

        container.innerHTML = listings.map(l => {
            const arch = window.psoGetArchetype(l.class);
            const timeAgo = window.psoFormatTimeAgo(l.created_at);

            // Seeking badges
            let seekHtml = '';
            if (l.looking_for) {
                seekHtml = l.looking_for.split(',').map(a =>
                    `<span style="display:inline-block; padding:1px 6px; border-radius:3px; font-size:0.6rem; font-weight:bold; font-family:'Share Tech Mono',monospace; margin-right:3px; border:1px solid; ${a === 'HU' ? 'color:#ff6666; border-color:#ff4444; background:rgba(255,68,68,0.1);' :
                        a === 'RA' ? 'color:#66ccff; border-color:#33b5e5; background:rgba(51,181,229,0.1);' :
                            'color:#88ff88; border-color:#00c851; background:rgba(0,200,81,0.1);'
                    }">${a}</span>`
                ).join('');
            }

            // Game details
            let gameHtml = '';
            if (l.game_id !== null) {
                const game = games.find(g => parseInt(g.ID) === parseInt(l.game_id));
                if (game) {
                    const slots = [];
                    for (let i = 0; i < game.MaxClients; i++) {
                        const cc = game.ClientClasses[i];
                        if (cc) {
                            const sa = window.psoGetArchetype(cc);
                            slots.push(`<span style="color:${sa === 'HU' ? '#ff6666' : sa === 'RA' ? '#66ccff' : '#88ff88'}; font-size:0.65rem; font-family:'Share Tech Mono',monospace;">${sa}</span>`);
                        } else {
                            slots.push(`<span style="color:#555; font-size:0.65rem;">--</span>`);
                        }
                    }
                    gameHtml = `
                        <div style="background:rgba(0,255,255,0.03); border:1px solid rgba(0,255,255,0.12); border-radius:6px; padding:8px 10px; margin-top:8px; font-size:0.75rem;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <span style="color:#00ffc8; font-family:'Share Tech Mono',monospace;"><i class="fas fa-gamepad"></i> ${escapeHtml(game.Name)}</span>
                                <span style="color:#aaa;">${game.Players}/${game.MaxClients}</span>
                            </div>
                            <div style="display:flex; gap:4px; flex-wrap:wrap; align-items:center;">
                                <span style="color:#d288ff; font-size:0.65rem;">${game.Difficulty}</span>
                                <span style="color:#888;">·</span>
                                <span style="color:#aaa; font-size:0.65rem;">${game.Episode}</span>
                                <span style="color:#888;">·</span>
                                <span style="color:#aaa; font-size:0.65rem;">Lv. ${game.MinLevel}-${game.MaxLevel}</span>
                                <span style="color:#888;">·</span>
                                ${slots.join(' ')}
                            </div>
                        </div>`;
                }
            }

            // Bounty info
            let bountyHtml = '';
            if (l.bounty_id && l.bounty_title) {
                bountyHtml = `<div style="font-size:0.7rem; color:#ffaa00; margin-top:6px; padding:4px 8px; background:rgba(255,170,0,0.05); border:1px solid rgba(255,170,0,0.15); border-radius:4px;">
                    <i class="fas fa-crosshairs"></i> Bounty: ${escapeHtml(l.bounty_title)} ${l.bounty_reward ? `· <span style="color:#fbbf24;">${escapeHtml(l.bounty_reward)}</span>` : ''}
                </div>`;
            }

            return `
            <div style="border:1px solid rgba(255,170,0,0.2); background:rgba(0,10,20,0.5); border-radius:8px; padding:1rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:6px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="color:#ffaa00; font-weight:bold; font-family:'Share Tech Mono',monospace;">${escapeHtml(l.character_name)}</span>
                        <span style="font-size:0.7rem; color:#aaa;">Lv.${l.level}</span>
                        <span style="display:inline-block; padding:1px 5px; border-radius:3px; font-size:0.6rem; font-weight:bold; font-family:'Share Tech Mono',monospace; border:1px solid; ${arch === 'HU' ? 'color:#ff6666; border-color:#ff4444; background:rgba(255,68,68,0.15);' :
                    arch === 'RA' ? 'color:#66ccff; border-color:#33b5e5; background:rgba(51,181,229,0.15);' :
                        'color:#88ff88; border-color:#00c851; background:rgba(0,200,81,0.15);'
                }">${arch}</span>
                    </div>
                    <span style="font-size:0.65rem; color:#888;"><i class="far fa-clock"></i> ${timeAgo}</span>
                </div>
                <p style="color:rgba(255,255,255,0.9); font-size:0.85rem; margin:0 0 6px; font-style:italic; padding:6px 10px; background:rgba(0,0,0,0.25); border-radius:4px; border-left:2px solid #ffaa00;">"${escapeHtml(l.description)}"</p>
                ${seekHtml ? `<div style="margin-top:6px;"><span style="font-size:0.6rem; color:#888; margin-right:4px;">SEEKING:</span>${seekHtml}</div>` : ''}
                ${bountyHtml}
                ${gameHtml}
            </div>`;
        }).join('');

    } catch (e) {
        container.innerHTML = `<div style="text-align:center; color:#ff4444; padding:2rem;">
            <i class="fas fa-exclamation-triangle"></i> Failed to load LFG feed.
        </div>`;
    }
};

// ---- Live Lobby Feed for Chat Console ----
window._lobbyFeedInterval = null;
window._lobbyFeedPlayers = new Set();
window._lobbyFeedLastState = null;

function appendChatBubble(type, text) {
    const log = document.getElementById('chat-messages-log');
    if (!log) return;
    const bubble = document.createElement('div');
    bubble.className = `chat-message-bubble ${type}`;
    bubble.textContent = text;
    log.appendChild(bubble);
    while (log.children.length > 200) {
        log.removeChild(log.firstChild);
    }
    log.scrollTop = log.scrollHeight;
}

function updateLobbyHeader(data) {
    const header = document.getElementById('chat-lobby-header');
    if (!header) return;

    if (!data.online) {
        header.innerHTML = '<span style="color:#ff4444;"><i class="fas fa-times-circle"></i> Offline — Log into the game to use chat</span>';
        return;
    }
    if (!data.in_lobby) {
        header.innerHTML = '<span style="color:#ffaa00;"><i class="fas fa-hourglass-half"></i> Connected as ' + data.character + ' — Joining lobby...</span>';
        return;
    }

    const lobby = data.lobby || {};
    let lobbyDesc = '';
    if (lobby.is_game) {
        lobbyDesc = `<span style="color:#00ff88;"><i class="fas fa-gamepad"></i> ${lobby.name || 'Game'}`;
        if (lobby.difficulty) lobbyDesc += ` — ${lobby.difficulty}`;
        if (lobby.episode) lobbyDesc += ` ${lobby.episode}`;
        lobbyDesc += `</span>`;
        if (lobby.quest) {
            lobbyDesc += ` <span style="color:#ffaa00; font-size:0.8rem;"><i class="fas fa-scroll"></i> ${lobby.quest}</span>`;
        }
    } else {
        lobbyDesc = `<span style="color:#00ffff;"><i class="fas fa-users"></i> Lobby</span>`;
    }

    const playerList = (data.players || []).map(p => {
        const youTag = p.is_you ? ' (You)' : '';
        return `<span style="color:${p.is_you ? '#00ff88' : '#ccc'}; font-size:0.75rem;">${p.name} Lv${p.level}${youTag}</span>`;
    }).join(' · ');

    header.innerHTML = lobbyDesc + '<div style="margin-top:4px;">' + playerList + '</div>';
}

async function pollLobbyFeed() {
    try {
        const res = await fetch('/api/get_lobby_feed.php', { credentials: 'same-origin' });
        const data = await res.json();

        updateLobbyHeader(data);

        if (!data.online || !data.in_lobby) {
            window._lobbyFeedPlayers.clear();
            return;
        }

        const currentPlayers = new Set((data.players || []).map(p => p.name));

        const currentLobbyId = data.lobby?.id;
        if (window._lobbyFeedLastState !== null && window._lobbyFeedLastState !== currentLobbyId) {
            window._lobbyFeedPlayers.clear();
            const lobbyName = data.lobby?.is_game ? (data.lobby.name || 'Game') : 'Lobby';
            appendChatBubble('system', `SYSTEM: Moved to ${lobbyName}`);
        }
        window._lobbyFeedLastState = currentLobbyId;

        if (window._lobbyFeedPlayers.size > 0) {
            for (const name of currentPlayers) {
                if (!window._lobbyFeedPlayers.has(name)) {
                    const player = (data.players || []).find(p => p.name === name);
                    const cls = player ? ` (${player.class} Lv${player.level})` : '';
                    appendChatBubble('system', `▶ ${name}${cls} joined`);
                }
            }
            for (const name of window._lobbyFeedPlayers) {
                if (!currentPlayers.has(name)) {
                    appendChatBubble('system', `◀ ${name} left`);
                }
            }
        } else if (currentPlayers.size > 0) {
            const names = (data.players || []).map(p => `${p.name} (Lv${p.level})`).join(', ');
            appendChatBubble('system', `LOBBY: ${names}`);
        }

        window._lobbyFeedPlayers = currentPlayers;
    } catch (e) {
        // Silent fail
    }
}

window.startLobbyFeed = function () {
    if (window._lobbyFeedInterval) return;
    pollLobbyFeed();
    window._lobbyFeedInterval = setInterval(pollLobbyFeed, 5000);
};

window.stopLobbyFeed = function () {
    if (window._lobbyFeedInterval) {
        clearInterval(window._lobbyFeedInterval);
        window._lobbyFeedInterval = null;
    }
};

// System mail configurations pref
window.loadSystemMailPref = function () {
    const userStr = sessionStorage.getItem('psobb_user');
    if (!userStr) return;
    const user = JSON.parse(userStr);
    const checkbox = document.getElementById('system-mail-toggle');
    if (checkbox) {
        checkbox.checked = (user.receive_system_mail !== 0);
    }
};

window.toggleSystemMailPref = async function () {
    const checkbox = document.getElementById('system-mail-toggle');
    const userStr = sessionStorage.getItem('psobb_user');
    if (!checkbox || !userStr) return;

    const user = JSON.parse(userStr);
    const enabled = checkbox.checked;

    try {
        const response = await fetch('/api/toggle_system_mail.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({
                receive_system_mail: enabled ? 1 : 0
            })
        });
        const data = await response.json();
        if (response.ok && data.success) {
            user.receive_system_mail = enabled ? 1 : 0;
            sessionStorage.setItem('psobb_user', JSON.stringify(user));
            console.log(`[Preferences] System mail toggled successfully: ${enabled}`);
        } else {
            throw new Error(data.error);
        }
    } catch (e) {
        console.error('System mail preferences update failed:', e);
        checkbox.checked = !enabled;
    }
};

// Discord streak DM preferences toggle controllers
window.loadDiscordStreakPref = function () {
    const userStr = sessionStorage.getItem('psobb_user');
    if (!userStr) return;
    const user = JSON.parse(userStr);
    const checkbox = document.getElementById('discord-streak-toggle');
    if (checkbox) {
        checkbox.checked = (user.receive_discord_streak_msg !== 0);
    }
};

window.toggleDiscordStreakPref = async function () {
    const checkbox = document.getElementById('discord-streak-toggle');
    const userStr = sessionStorage.getItem('psobb_user');
    if (!checkbox || !userStr) return;

    const user = JSON.parse(userStr);
    const enabled = checkbox.checked;

    try {
        const response = await fetch('/api/toggle_discord_streak.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({
                receive_discord_streak_msg: enabled ? 1 : 0
            })
        });
        const data = await response.json();
        if (response.ok && data.success) {
            user.receive_discord_streak_msg = enabled ? 1 : 0;
            sessionStorage.setItem('psobb_user', JSON.stringify(user));
            console.log(`[Preferences] Discord streak DM alerts toggled successfully: ${enabled}`);
        } else {
            throw new Error(data.error);
        }
    } catch (e) {
        console.error('Discord streak alerts preferences update failed:', e);
        checkbox.checked = !enabled;
    }
};

// Translate raw goal_type + goal_target into human-readable bounty objectives
function describeBountyObjective(goalType, goalTarget) {
    const target = goalTarget || '';

    // Floor ID -> Boss Name mapping (matches cron_missions.php floor reference)
    const bossFloorMap = {
        '11': 'Dragon', '12': 'De Rol Le', '13': 'Vol Opt', '14': 'Dark Falz',
        '15': 'Gol Dragon', '9': 'Saint-Milion',
        'ANY_DRAGON': 'a Dragon-type boss'
    };

    // Boss targets are "<floor>" or, since the lore-grounded generator, "<floor>@<episode>":
    // Episodes 1 and 2 share floors 12-14 (see parse_boss_target() in api/functions.php).
    const ep2BossFloorMap = { '12': 'Gal Gryphon', '13': 'Olga Flow', '14': 'Barba Ray' };
    const bossInfoFor = (t) => {
        const m = String(t).match(/^\s*(\d+)(?:@([124]))?\s*$/);
        if (!m) return { ep: '', name: bossFloorMap[t] || `Boss (Floor ${t})` };
        const ep = m[2] || (m[1] === '15' ? '2' : m[1] === '9' ? '4' : '');
        const name = (ep === '2' && ep2BossFloorMap[m[1]])
            ? ep2BossFloorMap[m[1]]
            : (bossFloorMap[m[1]] || `Boss (Floor ${m[1]})`);
        return { ep, name };
    };
    const bossNameFor = (t) => {
        const info = bossInfoFor(t);
        return info.ep ? `[Ep ${info.ep}] ${info.name}` : info.name;
    };

    // Episode-aware Floor ID -> Area Name mapping matching api/pso_constants.php
    const episodeFloorMap = {
        '1': {
            '0': 'Pioneer 2', '1': 'Forest 1', '2': 'Forest 2',
            '3': 'Cave 1', '4': 'Cave 2', '5': 'Cave 3',
            '6': 'Mine 1', '7': 'Mine 2', '8': 'Ruins 1',
            '9': 'Ruins 2', '10': 'Ruins 3',
            '11': 'Dragon Arena', '12': 'De Rol Le Arena',
            '13': 'Vol Opt Arena', '14': 'Dark Falz Arena'
        },
        '2': {
            '0': 'Lab', '1': 'VR Temple Alpha', '2': 'VR Temple Beta',
            '3': 'VR Spaceship Alpha', '4': 'VR Spaceship Beta',
            '5': 'Central Control Area', '6': 'Jungle North',
            '7': 'Jungle East', '8': 'Mountain', '9': 'Seaside',
            '10': 'Seabed Upper', '11': 'Seabed Lower'
        },
        '4': {
            '0': 'Pioneer 2', '1': 'Crater East', '2': 'Crater West',
            '3': 'Crater South', '4': 'Crater North', '5': 'Crater Interior',
            '6': 'Subterranean Desert 1', '7': 'Subterranean Desert 2',
            '8': 'Subterranean Desert 3'
        }
    };
    const areaInfoFor = (raw) => {
        const m = String(raw).match(/^\s*(\d+)(?:@([124]))?\s*$/);
        if (!m) return { ep: '1', name: episodeFloorMap['1'][String(raw)] || `Area ${raw}` };
        const ep = m[2] || '1';
        const epMap = episodeFloorMap[ep] || episodeFloorMap['1'];
        return { ep, name: epMap[m[1]] || `Floor ${m[1]}` };
    };

    switch (goalType) {
        case 'LEVEL': return `Reach Level ${target}`;
        case 'MESETA': return `Accumulate ${Number(target).toLocaleString()} Meseta`;
        case 'PLAYTIME': return `Accumulate ${Number(target).toLocaleString()} seconds of play time`;
        case 'PLAY_HOURS': {
            // "<seconds needed>:<play time when the bounty started>"
            const need = parseInt(String(target).split(':')[0], 10) || 3600;
            const h = Math.floor(need / 3600), m = Math.floor((need % 3600) / 60);
            return `Play for ${h ? `${h} hour${h !== 1 ? 's' : ''}` : ''}${h && m ? ' ' : ''}${m ? `${m} min` : ''} (counted from when the bounty starts)`;
        }
        case 'BOSS_ARENA':
        case 'MENTOR_BOSS':
        case 'HARDCORE_MENTOR':
        case 'DIVERSE_PARTY_BOSS': {
            const info = bossInfoFor(target);
            const prefix = goalType === 'MENTOR_BOSS' ? 'Mentor a rookie and defeat '
                : goalType === 'HARDCORE_MENTOR' ? 'Carry 3+ rookies and defeat '
                    : goalType === 'DIVERSE_PARTY_BOSS' ? 'Defeat with a diverse party: '
                        : 'Defeat ';
            return (info.ep ? `[Ep ${info.ep}] ` : '') + prefix + info.name;
        }
        case 'SPEEDRUN_BOSS': {
            const parts = String(target).split('_');
            const info = bossInfoFor(parts[0]);
            const secs = parts[1] ? parseInt(parts[1], 10) : 0;
            const timeLimit = parts[1] ? ` in under ${Math.floor(secs / 60)}m ${secs % 60}s` : '';
            return `${info.ep ? `[Ep ${info.ep}] ` : ''}Speedrun ${info.name}${timeLimit}`;
        }
        case 'SPEEDRUN_FLOOR': {
            const parts = String(target).split('_');
            const a = areaInfoFor(parts[0]);
            const secs = parts[1] ? parseInt(parts[1], 10) : 0;
            const timeLimit = parts[1] ? ` in under ${Math.floor(secs / 60)}m ${secs % 60}s` : '';
            return `[Ep ${a.ep}] Clear ${a.name}${timeLimit}`;
        }
        case 'PATROL': {
            const a = areaInfoFor(target);
            return `[Ep ${a.ep}] Survive and patrol the ${a.name} for 10 minutes`;
        }
        case 'EXPLORATION': {
            const a = areaInfoFor(target);
            return `[Ep ${a.ep}] Explore the ${a.name}`;
        }
        case 'ITEM': return `Obtain the target item`;
        case 'TECHNIQUE': return `Learn technique: ${target}`;
        case 'BATTLE_WINS': return `Win ${target} Battle Mode match${parseInt(target) !== 1 ? 'es' : ''}`;
        case 'CHALLENGE_STAGES': return `Clear Challenge Mode stages until ${target} of the 14 are cleared (each stage counts once)`;
        case 'MAT_HP': return `Use ${target} HP Material${parseInt(target) !== 1 ? 's' : ''}`;
        case 'MAT_TP': return `Use ${target} TP Material${parseInt(target) !== 1 ? 's' : ''}`;
        case 'MAT_POWER': return `Use ${target} Power Material${parseInt(target) !== 1 ? 's' : ''}`;
        case 'MAT_MIND': return `Use ${target} Mind Material${parseInt(target) !== 1 ? 's' : ''}`;
        case 'MAT_DEF': return `Use ${target} Def Material${parseInt(target) !== 1 ? 's' : ''}`;
        case 'MAT_EVADE': return `Use ${target} Evade Material${parseInt(target) !== 1 ? 's' : ''}`;
        case 'MAT_LUCK': return `Use ${target} Luck Material${parseInt(target) !== 1 ? 's' : ''}`;
        // Bounty System Rework weeklies: targets are "N@Difficulty" (episode number for AREA_SWEEP)
        case 'AREA_SWEEP':
        case 'BOSS_HUNT':
        case 'ENEMY_HUNT':
        case 'RARE_HUNT': {
            const [n, diff = 'Normal'] = String(target).split('@');
            const where = diff === 'Ultimate' ? 'on Ultimate' : `on ${diff} or higher`;
            if (goalType === 'AREA_SWEEP') return `[Weekly] Visit every area of Episode ${n} ${where}`;
            const one = Number(n) === 1;
            const what = goalType === 'BOSS_HUNT' ? (one ? 'boss' : 'bosses') : goalType === 'RARE_HUNT' ? (one ? 'rare enemy' : 'rare enemies') : (one ? 'enemy' : 'enemies');
            return `[Weekly] Defeat ${Number(n).toLocaleString()} ${what} ${where}`;
        }
        case 'CHALLENGE_RUN': {
            // "E:first-last:limitSeconds:pace" (the API also sends the full wording as objective_text)
            const [ep, range = '1-1'] = String(target).split(':');
            const [a, b = a] = range.split('-');
            return a === b ? `[Challenge] Clear Episode ${ep} Challenge Mode Stage ${a}` : `[Challenge] Clear Episode ${ep} Challenge Mode Stages ${a}–${b}`;
        }
        case 'BATTLE_RULE_WINS': {
            const [quest, wins = '1'] = String(target).split(':');
            return `[Battle] Win ${wins} Battle Mode match${Number(wins) !== 1 ? 'es' : ''} of Battle ${Number(quest) - 500}`;
        }
        // Contracts (the API also sends the full wording as objective_text)
        case 'EPISODE_RUN': {
            const [ep = '1', variant = 'timed'] = String(target).split('@')[0].split(':');
            const how = { diverse: 'with a mixed party', carry: 'carrying newer hunters', timed: 'against the clock' }[variant] || '';
            return `[Contract] Full Episode ${ep} run in one game ${how}`.trim();
        }
        case 'RARE_TARGET': {
            const [what = '', n = '1', ep = '1'] = String(target).split('@')[0].split(':');
            const name = what.toLowerCase().split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
            return `[Contract] Defeat ${n} ${name} in Episode ${ep}`;
        }
        case 'CONTRACT_SHARE': return 'Your share of a contract another hunter finished with you in the game';
        case 'PENDING': return 'Waiting for a free slot: the objective and reward are set when it becomes active';
        default: return `${goalType}: ${target}`;
    }
}

// Load bounties & community events for Guild tab
window.loadMyBounties = async function () {
    try {
        const res = await fetch('/api/my_bounties.php', { credentials: 'same-origin' });
        if (!res.ok) return;
        const data = await res.json();
        if (!data.success) return;

        // --- Community Events ---
        const _t = (typeof window.__ === 'function') ? window.__ : (s => s);
        const ceSection = document.getElementById('community-event-section');
        const ceCards = document.getElementById('community-event-cards');
        if (ceSection && ceCards && data.community_events && data.community_events.length > 0) {
            ceSection.style.display = 'block';
            ceCards.innerHTML = data.community_events.map(ce => {
                const pct = Math.min(100, ce.progress_pct);
                return `
                <div style="border: 1px solid rgba(255,170,0,0.3); background: rgba(0,10,20,0.5); border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <strong style="color:#ffaa00; font-family:'Share Tech Mono',monospace; font-size:0.95rem;">${ce.title}</strong>
                        <span style="color:#fff; font-size:0.8rem; font-family:'Share Tech Mono',monospace;">${pct}%</span>
                    </div>
                    <p style="color:rgba(255,255,255,0.65); font-size:0.8rem; margin:0 0 10px 0;">${ce.description || ''}</p>
                    <div style="background:rgba(255,255,255,0.06); border-radius:4px; height:10px; overflow:hidden; margin-bottom:8px;">
                        <div style="height:100%; background: linear-gradient(90deg, #ffaa00, #ff6600); border-radius:4px; transition: width 0.8s ease; width:${pct}%;"></div>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:0.75rem; color:rgba(255,255,255,0.5); font-family:'Share Tech Mono',monospace;">
                        <span>${_t('Progress:')} ${Number(ce.current_progress).toLocaleString()} / ${Number(ce.target_amount).toLocaleString()}</span>
                        <span>${_t('Your Contribution:')} <strong style="color:#ffaa00;">${ce.user_contribution || 0}</strong></span>
                    </div>
                </div>`;
            }).join('');
        }

        // --- Claimable Community Events ---
        // Clear the claim list first to prevent duplication on re-renders
        const claimSection = document.getElementById('claimable-bounties-section');
        const claimList = document.getElementById('claimable-bounties-list');
        if (claimSection && claimList) {
            claimList.innerHTML = '';
        }

        if (data.claimable_events && data.claimable_events.length > 0) {
            if (claimSection && claimList) {
                claimSection.style.display = 'block';
                claimList.innerHTML = data.claimable_events.map(ce => {
                    const rewardStr = ce.reward_decoded || ce.reward_item_string || _t('Community Event Reward');
                    return `
                    <div style="border: 1px solid rgba(0,255,136,0.4); background: rgba(0,255,136,0.05); border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                            <div style="flex:1; min-width:0;">
                                <span style="color:#00ff88; font-weight:bold; font-family:'Share Tech Mono',monospace;">🏆 ${ce.title}</span>
                                <div style="color:#fbbf24; font-size:0.75rem; margin-top:4px; font-family:'Share Tech Mono',monospace;">🎁 ${rewardStr}</div>
                            </div>
                            <a href="missions.php" class="dl-btn" style="text-decoration:none; border-color:#00ff88; color:#00ff88; background:rgba(0,255,136,0.15); font-size:0.8rem; padding:6px 14px; white-space:nowrap;">${_t('Claim →')}</a>
                        </div>
                    </div>`;
                }).join('');
            }
        }

        // --- Bounties ---
        const completed = data.bounties.filter(b => b.status === 'ready_to_redeem');
        const inProgress = data.bounties.filter(b => b.status === 'in_progress' || b.status === 'rerolling' || b.status === 'queued');
        // Bounty System Rework: tier, kind, expiry and weekly progress (absent on older bounties)
        const tierColors = { 'Common': '#c8c8c8', 'Uncommon': '#1eff64', 'Rare': '#4da6ff', 'Ultra Rare': '#c77dff', 'Mythic Rare': '#ffcc33' };
        const bountyTags = (b) => {
            if (!b.tier_label) {
                return b.expires_in ? `<div style="margin-top:4px; font-size:0.65rem; font-family:'Share Tech Mono',monospace;"><span style="color:#ffaa00;">${_t(b.expires_in)}</span></div>` : '';
            }
            const col = tierColors[b.tier_label] || '#c8c8c8';
            return `<div style="margin-top:4px; font-size:0.65rem; font-family:'Share Tech Mono',monospace;">
                <span style="border:1px solid ${col}; color:${col}; border-radius:3px; padding:1px 6px; text-transform:uppercase;">${_t(b.tier_label)}</span>
                <span style="color:rgba(255,255,255,0.55); margin-left:6px;">${_t(b.kind || '')}</span>
                ${b.expires_in ? `<span style="color:#ffaa00; margin-left:6px;">${_t(b.expires_in)}</span>` : ''}
            </div>`;
        };

        // Claimable bounties
        if (completed.length > 0) {
            if (claimSection && claimList) {
                claimSection.style.display = 'block';
                claimList.innerHTML += completed.map(b => {
                    const rewardStr = b.reward_decoded || b.reward_item_string || _t('Mystery Reward');
                    const objText = b.objective_text || describeBountyObjective(b.goal_type, b.goal_target);
                    return `
                    <div style="border: 1px solid rgba(0,255,136,0.4); background: rgba(0,255,136,0.05); border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                            <div style="flex:1; min-width:0;">
                                <span style="color:#00ff88; font-weight:bold; font-family:'Share Tech Mono',monospace;">✅ ${escapeHtml(b.title)}</span>
                                ${b.description ? `<p style="color:rgba(224,240,255,0.78); font-size:0.75rem; line-height:1.45; margin:4px 0 0 0;">${escapeHtml(b.description)}</p>` : ''}
                                <div style="color:rgba(255,255,255,0.55); font-size:0.75rem; margin-top:4px;">${escapeHtml(b.character_name || 'Unknown')} · ${escapeHtml(objText)}</div>
                                <div style="color:#fbbf24; font-size:0.75rem; margin-top:4px; font-family:'Share Tech Mono',monospace;">🎁 ${rewardStr}</div>
                            </div>
                            <a href="missions.php" class="dl-btn" style="text-decoration:none; border-color:#00ff88; color:#00ff88; background:rgba(0,255,136,0.15); font-size:0.8rem; padding:6px 14px; white-space:nowrap;">${_t('Claim →')}</a>
                        </div>
                    </div>`;
                }).join('');
            }
        }

        // Active in-progress bounties
        if (inProgress.length > 0) {
            const activeSection = document.getElementById('active-bounties-section');
            const activeList = document.getElementById('active-bounties-list');
            if (activeSection && activeList) {
                activeSection.style.display = 'block';
                activeList.innerHTML = inProgress.map(b => {
                    const reward = b.reward_decoded || b.reward_item_string || (b.status === 'queued' ? _t('Chosen when it starts, from the area it sends you to') : '');
                    const rawObjective = b.status === 'rerolling' ? _t('Rerolling: a new objective arrives the next time this character is online')
                        : (b.objective_text || describeBountyObjective(b.goal_type, b.goal_target))
                            + (b.min_difficulty_text ? ` ${b.min_difficulty_text}` : '')
                            + (b.progress_text ? ` (${b.progress_text})` : '');
                    const objective = escapeHtml(rawObjective);
                    const statusLabel = b.status === 'queued' ? _t('UP NEXT') : b.status === 'rerolling' ? _t('REROLLING') : _t('IN PROGRESS');
                    return `
                    <div style="border: 1px solid rgba(0,255,255,0.2); background: rgba(0,10,20,0.4); border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px;">
                            <div style="flex:1; min-width:0;">
                                <span style="color:#00ffff; font-weight:bold; font-family:'Share Tech Mono',monospace;">${escapeHtml(b.title || _t('Bounty waiting for a free slot'))}</span>
                                ${bountyTags(b)}
                                ${b.description && b.status !== 'rerolling' && b.status !== 'queued' ? `<p style="color:rgba(224,240,255,0.78); font-size:0.75rem; line-height:1.45; margin:6px 0 0 0;">${escapeHtml(b.description)}</p>` : ''}
                                <div style="color:rgba(255,255,255,0.7); font-size:0.75rem; margin-top:6px;">📋 <strong>${_t('Objective:')}</strong> ${objective}</div>
                                <div style="color:rgba(255,255,255,0.3); font-size:0.65rem; margin-top:4px;">${_t('Character:')} ${escapeHtml(b.character_name || 'Unknown')}</div>
                                ${reward ? `<div style="color:#fbbf24; font-size:0.75rem; margin-top:6px; font-family:'Share Tech Mono',monospace;">🎁 ${reward}${b.stats_hidden ? ` <span style="color:rgba(255,255,255,0.4);">(${_t('stats roll on claim')})</span>` : ''}</div>` : ''}
                            </div>
                            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                                <span style="color:#ffaa00; font-size:0.7rem; font-family:'Share Tech Mono',monospace; white-space:nowrap;">${statusLabel}</span>
                                ${b.can_reroll ? `<button onclick="window.rerollPortalBounty(${b.player_mission_id})" style="background:rgba(0,220,255,0.1); border:1px solid rgba(0,220,255,0.35); color:#00e5ff; font-size:0.65rem; padding:3px 10px; border-radius:4px; cursor:pointer; font-family:'Share Tech Mono',monospace; white-space:nowrap;">${_t('↻ Reroll')}</button>` : ''}
                                ${b.status === 'rerolling' ? '' : `<button onclick="window.abandonBounty(${b.player_mission_id})" style="background:rgba(255,68,68,0.1); border:1px solid rgba(255,68,68,0.3); color:#ff6666; font-size:0.65rem; padding:3px 10px; border-radius:4px; cursor:pointer; font-family:'Share Tech Mono',monospace; white-space:nowrap;">${_t('✕ Abandon')}</button>`}
                            </div>
                        </div>
                    </div>`;
                }).join('');
            }
        }

    } catch (e) {
        console.error('Failed to load bounties:', e);
    }
};

// Reroll a bounty mission once for free
window.rerollPortalBounty = async function (playerMissionId) {
    const _t = (typeof window.__ === 'function') ? window.__ : (s => s);
    if (!confirm(_t('Reroll this bounty once for free? A new objective at the same tier will be generated.'))) return;
    try {
        const res = await fetch('/api/reroll_bounty.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({ player_mission_id: playerMissionId })
        });
        const data = await res.json();
        if (res.ok && data.success) {
            window.loadMyBounties();
        } else {
            alert(data.error || _t('Failed to reroll bounty.'));
        }
    } catch (e) {
        alert(_t('Connection error: ') + e.message);
    }
};

// Abandon a bounty mission
window.abandonBounty = async function (playerMissionId) {
    const _t = (typeof window.__ === 'function') ? window.__ : (s => s);
    if (!confirm(_t('Are you sure you want to abandon this bounty? Progress will be lost.'))) return;
    try {
        const res = await fetch('/api/abandon_bounty.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.getCSRFToken()
            },
            body: JSON.stringify({ player_mission_id: playerMissionId })
        });
        const data = await res.json();
        if (res.ok && data.success) {
            window.loadMyBounties();
        } else {
            alert(data.error || _t('Failed to abandon bounty.'));
        }
    } catch (e) {
        alert(_t('Connection error: ') + e.message);
    }
};

// Milestone reward loading
window.loadUnlocks = function () {
    const container = document.getElementById('milestones-container');
    const statusBox = document.getElementById('unlocks-status');
    const charInfo = document.getElementById('character-info');
    if (!container) return;

    fetch('/api/get_unlocks.php', { credentials: 'same-origin' })
        .then(res => {
            if (res.status === 401) {
                sessionStorage.removeItem('psobb_user');
                window.location.reload();
            }
            return res.json();
        })
        .then(data => {
            if (data.error) throw new Error(data.error);

            if (!data.is_online) {
                if (statusBox) {
                    statusBox.style.display = 'block';
                    statusBox.className = 'alert-box';
                    statusBox.innerHTML = `⚠️ ${data.message || "You must be online in-game to view and claim rewards on your character."}`;
                }
                container.innerHTML = '';
                if (charInfo) charInfo.style.display = 'none';
                return;
            }

            if (charInfo) {
                charInfo.style.display = 'block';
                document.getElementById('char-name').textContent = data.character.name;
                document.getElementById('char-class').textContent = data.character.class;
                document.getElementById('char-level').textContent = data.character.level;
            }

            if (!data.in_game) {
                if (statusBox) {
                    statusBox.style.display = 'block';
                    statusBox.className = 'alert-box';
                    statusBox.innerHTML = "⚠️ Character found in Lobby. <b>You must join or create a Game in-game to claim milestone rewards!</b>";
                }
            } else {
                if (statusBox) statusBox.style.display = 'none';
            }

            renderMilestones(data.milestones, data.in_game);
        })
        .catch(err => {
            if (statusBox) {
                statusBox.style.display = 'block';
                statusBox.className = 'alert-box';
                statusBox.innerHTML = err.message;
            }
            container.innerHTML = '';
        });
};

function renderMilestones(milestones, inGame) {
    const container = document.getElementById('milestones-container');
    if (!container) return;

    if (!milestones || milestones.length === 0) {
        container.innerHTML = '<p style="color: rgba(255,255,255,0.4); font-family:\'Share Tech Mono\',monospace;">You have not reached Level 5 yet. Keep hunting!</p>';
        return;
    }

    const unclaimed = milestones.filter(m => !m.claimed);
    const claimed = milestones.filter(m => m.claimed);

    container.innerHTML = '';

    const summaryBar = document.createElement('div');
    summaryBar.style.cssText = 'display:flex; gap:12px; margin-bottom:1rem; flex-wrap:wrap;';
    summaryBar.innerHTML = `
        <span style="font-family:'Share Tech Mono',monospace; font-size:0.8rem; padding:4px 10px; border-radius:4px; background:rgba(0,255,136,0.15); border:1px solid rgba(0,255,136,0.3); color:#00ff88;">
            <i class="fas fa-gift"></i> ${unclaimed.length} ${_t('Available')}
        </span>
        <span style="font-family:'Share Tech Mono',monospace; font-size:0.8rem; padding:4px 10px; border-radius:4px; background:rgba(170,102,204,0.15); border:1px solid rgba(170,102,204,0.3); color:#aa66cc;">
            <i class="fas fa-check"></i> ${claimed.length} ${_t('Claimed')}
        </span>
    `;
    container.appendChild(summaryBar);

    if (unclaimed.length > 0) {
        unclaimed.forEach(m => {
            const card = document.createElement('div');
            card.className = 'milestone-card';
            const disabledStr = !inGame ? 'disabled' : '';
            const glowClass = (m.level % 25 === 0) ? 'milestone-major' : '';
            card.innerHTML = `
                <div class="milestone-level" ${glowClass ? 'style="color:#ffaa00; text-shadow:0 0 10px rgba(255,170,0,0.5);"' : ''}>${_t('Level')} ${m.level}</div>
                <button class="open-claim-btn" data-level="${m.level}" ${disabledStr}>
                    <i class="fas fa-gift"></i> ${_t('Claim Reward')}
                </button>
            `;
            container.appendChild(card);
        });
    } else {
        const allDone = document.createElement('p');
        allDone.style.cssText = 'color:#00ff88; font-family:"Share Tech Mono",monospace; text-align:center; padding:1rem;';
        allDone.innerHTML = '<i class="fas fa-check-circle"></i> ' + _t('All available milestones claimed! Keep leveling for more.');
        container.appendChild(allDone);
    }

    if (claimed.length > 0) {
        const toggle = document.createElement('button');
        toggle.style.cssText = 'background:rgba(170,102,204,0.1); border:1px solid rgba(170,102,204,0.3); color:#aa66cc; padding:8px 16px; border-radius:6px; font-family:"Share Tech Mono",monospace; font-size:0.8rem; cursor:pointer; width:100%; margin-top:1rem; transition:all 0.3s;';
        toggle.innerHTML = `<i class="fas fa-chevron-down"></i> ` + _t('Show %d Claimed Milestones').replace('%d', claimed.length);

        const claimedContainer = document.createElement('div');
        claimedContainer.style.cssText = 'display:none; margin-top:0.75rem;';
        claimedContainer.className = 'milestones-grid';

        claimed.forEach(m => {
            const card = document.createElement('div');
            card.className = 'milestone-card claimed';
            card.style.cssText = 'opacity:0.6; transform:scale(0.95);';
            card.innerHTML = `
                <div class="milestone-level">Level ${m.level}</div>
                <p style="color:#aa66cc; margin-top:0.5rem; font-family:'Share Tech Mono',monospace; font-size:0.75rem; text-shadow:0 0 5px rgba(255,255,255,0.1);">
                    <i class="fas fa-check"></i> ${m.claimed_category}
                </p>
            `;
            claimedContainer.appendChild(card);
        });

        toggle.addEventListener('click', () => {
            const isHidden = claimedContainer.style.display === 'none';
            claimedContainer.style.display = isHidden ? '' : 'none';
            toggle.innerHTML = isHidden
                ? `<i class="fas fa-chevron-up"></i> Hide ${claimed.length} Claimed Milestones`
                : `<i class="fas fa-chevron-down"></i> Show ${claimed.length} Claimed Milestones`;
        });

        container.appendChild(toggle);
        container.appendChild(claimedContainer);
    }

    document.querySelectorAll('.open-claim-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            if (!inGame) return;
            window.currentClaimLevel = parseInt(e.target.closest('.open-claim-btn').getAttribute('data-level'));
            const modal = document.getElementById('claim-modal');
            const levelSpan = document.getElementById('modal-level');
            const modalError = document.getElementById('modal-error');
            if (modal && levelSpan && modalError) {
                levelSpan.textContent = window.currentClaimLevel;
                modalError.style.display = 'none';
                modal.style.display = 'flex';
            }
        });
    });
}

window.initClaimModalCategoryButtons = initClaimModalCategoryButtons;

function initClaimModalCategoryButtons() {
    const claimBtns = document.querySelectorAll('.claim-category-btn');
    if (claimBtns.length === 0) return;

    // Attach listeners once
    claimBtns.forEach(btn => {
        // Remove existing listener if any by cloning
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);

        newBtn.addEventListener('click', (e) => {
            const category = e.target.getAttribute('data-category');
            const modal = document.getElementById('claim-modal');
            const modalError = document.getElementById('modal-error');
            if (modal) modal.style.display = 'none';

            const overlay = document.getElementById('drop-animation-overlay');
            const box = document.getElementById('drop-item-box');
            const countdownEl = document.getElementById('countdown-text');
            const thankYouText = document.getElementById('thank-you-text');

            if (category === 'Random') {
                box.className = 'drop-item-box green-box';
            } else if (category === 'Armor' || category === 'Shield') {
                box.className = 'drop-item-box blue-box';
            } else if (category === 'Mag') {
                box.className = 'drop-item-box teal-box';
            } else if (category === 'Weapon') {
                if (window.currentClaimLevel % 25 === 0) {
                    box.className = 'drop-item-box';
                } else {
                    box.className = 'drop-item-box orange-box';
                }
            } else {
                box.className = 'drop-item-box';
            }

            thankYouText.style.animation = 'none';
            thankYouText.style.opacity = '0';

            const newBox = box.cloneNode(true);
            box.parentNode.replaceChild(newBox, box);

            overlay.style.display = 'flex';

            let count = 3;
            countdownEl.style.display = 'block';
            countdownEl.textContent = count;

            const countdownInterval = setInterval(() => {
                count--;
                if (count > 0) {
                    countdownEl.textContent = count;
                    countdownEl.style.transform = 'scale(1.5)';
                    setTimeout(() => countdownEl.style.transform = 'scale(1)', 100);
                } else if (count === 0) {
                    countdownEl.style.transform = 'scale(1.5)';
                    countdownEl.textContent = "DROPPING!";

                    fetch('/api/claim_unlock.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': window.getCSRFToken()
                        },
                        body: JSON.stringify({ level: window.currentClaimLevel, category: category })
                    })
                        .then(res => res.json())
                        .then(data => {
                            if (data.error) {
                                clearInterval(countdownInterval);
                                overlay.style.display = 'none';
                                if (modalError) {
                                    modalError.textContent = data.error;
                                    modalError.style.display = 'block';
                                }
                                if (modal) modal.style.display = 'flex';
                            } else {
                                countdownEl.style.display = 'none';
                                thankYouText.style.animation = 'textDrop 1.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards';
                                createFireworks();

                                setTimeout(() => {
                                    overlay.style.display = 'none';
                                    const statusBox = document.getElementById('unlocks-status');
                                    if (statusBox) {
                                        statusBox.style.display = 'block';
                                        statusBox.className = 'alert-box success';
                                        statusBox.innerHTML = `🎉 <b>Success!</b> ${category} reward dropped inside your game room. Enjoy!`;
                                    }
                                    loadUnlocks();
                                }, 3500);
                            }
                        })
                        .catch(err => {
                            clearInterval(countdownInterval);
                            overlay.style.display = 'none';
                            if (modalError) {
                                modalError.textContent = "A connection error occurred.";
                                modalError.style.display = 'block';
                            }
                            if (modal) modal.style.display = 'flex';
                        });

                    clearInterval(countdownInterval);
                }
            }, 1000);
        });
    });
}

// Daily Streak Calendar claims
window.loadStreak = function () {
    // Non-linear fill calculation based on evenly spaced milestone segments
    function calculateFillPercentage(streak) {
        const milestones = [0, 7, 30, 90, 180, 270, 365];
        const segmentWidth = 100 / (milestones.length - 1); // 20% per segment
        for (let i = 0; i < milestones.length - 1; i++) {
            const current = milestones[i];
            const next = milestones[i + 1];
            if (streak >= current && streak <= next) {
                const segmentProgress = (streak - current) / (next - current);
                return (i * segmentWidth) + (segmentProgress * segmentWidth);
            }
        }
        return 100;
    }

    fetch('/api/get_streak.php', { credentials: 'same-origin' })
        .then(res => {
            if (res.status === 401) {
                sessionStorage.removeItem('psobb_user');
            }
            return res.json();
        })
        .then(data => {
            if (data.error) return;

            const streakSection = document.getElementById('streak-section');
            if (streakSection) streakSection.style.display = 'block';
            const dailySection = document.getElementById('daily-reward-section');
            if (dailySection) dailySection.style.display = 'block';

            document.getElementById('streak-count').textContent = data.streak;

            const node365 = document.querySelector('.streak-node[data-day="365"] .streak-node-reward');
            if (node365) {
                node365.textContent = data.has_claimed_yahoo ? 'Rare Drop' : 'Yahoo! Mag';
            }

            const fillPct = calculateFillPercentage(data.streak);
            document.getElementById('streak-fill').style.width = fillPct + '%';

            const nodes = document.querySelectorAll('.streak-node');
            nodes.forEach(node => {
                const day = parseInt(node.dataset.day);
                node.classList.remove('reached', 'claimable', 'claimed');

                if (data.claimed.includes(day)) {
                    node.classList.add('claimed');
                } else if (data.claimable.includes(day)) {
                    node.classList.add('claimable');
                } else if (data.streak >= day) {
                    node.classList.add('reached');
                }
            });

            // Streak Calendar
            const claimsDiv = document.getElementById('streak-claims');
            if (claimsDiv) {
                claimsDiv.innerHTML = '';

                // Sliding 30-day window centered around their current streak
                let startDay = 1;
                if (data.streak > 15) {
                    startDay = Math.max(1, Math.min(336, data.streak - 14));
                }
                // Align to 10-day boundaries for clean grid row alignment
                startDay = Math.floor((startDay - 1) / 10) * 10 + 1;
                const endDay = Math.min(365, startDay + 29);

                // Add range label above the grid
                let labelEl = document.getElementById('streak-range-label');
                if (!labelEl) {
                    labelEl = document.createElement('div');
                    labelEl.id = 'streak-range-label';
                    labelEl.style.fontSize = '0.85rem';
                    labelEl.style.color = '#ffaa00';
                    labelEl.style.fontFamily = "'Share Tech Mono', monospace";
                    labelEl.style.marginBottom = '12px';
                    labelEl.style.textAlign = 'right';
                    labelEl.style.letterSpacing = '1px';
                    claimsDiv.parentNode.insertBefore(labelEl, claimsDiv);
                }
                labelEl.innerHTML = `<i class="fas fa-calendar-alt"></i> REWARD SCHEDULE: DAYS ${startDay} - ${endDay}`;

                const daysArray = [];
                for (let i = startDay; i <= endDay; i++) {
                    daysArray.push(i);
                }
                daysArray.forEach(m => {
                    let rewardName = 'Mono';
                    let tierClass = 'tier-mono';

                    if (m === 365) {
                        rewardName = data.has_claimed_yahoo ? 'Rare' : 'Yahoo!';
                        tierClass = 'tier-yahoo';
                    } else if (m === 7 || m === 30 || m === 90 || m === 180 || m === 270) {
                        rewardName = 'Mat';
                        tierClass = 'tier-stat';
                    } else if (m <= 30) {
                        if (m % 5 === 0) {
                            rewardName = 'Mat';
                            tierClass = 'tier-stat';
                        } else if (m % 3 === 0) {
                            rewardName = 'Dig';
                            tierClass = 'tier-dig';
                        } else {
                            rewardName = 'Mono';
                            tierClass = 'tier-mono';
                        }
                    } else if (m <= 90) {
                        if (m % 5 === 0) {
                            rewardName = 'Mat';
                            tierClass = 'tier-stat';
                        } else if (m % 3 === 0) {
                            rewardName = 'Tri';
                            tierClass = 'tier-tri';
                        } else {
                            rewardName = 'Dig';
                            tierClass = 'tier-dig';
                        }
                    } else if (m <= 180) {
                        if (m % 4 === 0) {
                            rewardName = 'Mat';
                            tierClass = 'tier-stat';
                        } else {
                            rewardName = 'Tri';
                            tierClass = 'tier-tri';
                        }
                    } else {
                        rewardName = 'Mat';
                        tierClass = 'tier-stat';
                    }

                    const day = document.createElement('div');
                    day.className = `streak-day ${tierClass}`;

                    let stateHtml = '';
                    if (data.claimed.includes(m)) {
                        day.classList.add('day-claimed');
                        stateHtml = '<span class="day-check">✓</span>';
                    } else if (data.claimable.includes(m)) {
                        day.classList.add('day-claimable');
                        stateHtml = '<span class="claim-label">Claim</span>';
                        day.addEventListener('click', () => claimStreak(m));
                    } else if (data.streak >= m) {
                        day.classList.add('day-reached');
                    }

                    const _t = (typeof window.__ === 'function') ? window.__ : (s => s);
                    day.innerHTML = `
                        ${stateHtml}
                        <div class="day-num">${_t('Day')} ${m}</div>
                        <div class="day-reward">${rewardName}</div>
                    `;
                    claimsDiv.appendChild(day);
                });
            }

            const _t = (typeof window.__ === 'function') ? window.__ : (s => s);
            const dailyBtn = document.getElementById('daily-claim-btn');
            const dailyResult = document.getElementById('daily-result');
            if (dailyBtn) {
                if (data.daily_claimed) {
                    startDailyCountdown(dailyBtn, data.next_daily_reset, data.server_time);
                } else if (!data.is_online) {
                    dailyBtn.textContent = _t('Log into the game first');
                    dailyBtn.disabled = true;
                } else {
                    dailyBtn.disabled = false;
                    dailyBtn.onclick = () => claimDaily();
                }
            }
        })
        .catch(err => console.error('Streak fetch error:', err));
};

function claimStreak(milestone) {
    const _t = (typeof window.__ === 'function') ? window.__ : (s => s);
    const overlay = document.getElementById('drop-animation-overlay');
    const box = document.getElementById('drop-item-box');
    const thankYouText = document.getElementById('thank-you-text');
    const countdown = document.getElementById('countdown-text');
    if (!overlay || !box || !thankYouText || !countdown) return;

    box.className = 'drop-item-box green-box';
    thankYouText.style.animation = 'none';
    thankYouText.style.opacity = '0';

    const newBox = box.cloneNode(true);
    box.parentNode.replaceChild(newBox, box);
    overlay.style.display = 'flex';

    let count = 3;
    countdown.textContent = count;
    countdown.style.display = 'block';

    const countInterval = setInterval(() => {
        count--;
        if (count > 0) {
            countdown.textContent = count;
            countdown.style.transform = 'scale(1.5)';
            setTimeout(() => countdown.style.transform = 'scale(1)', 100);
        } else {
            clearInterval(countInterval);
            countdown.style.transform = 'scale(1.5)';
            countdown.textContent = _t('DROPPING!');
            setTimeout(() => { countdown.style.display = 'none'; }, 600);

            fetch('/api/claim_streak.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.getCSRFToken() },
                body: JSON.stringify({ milestone })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        overlay.style.display = 'none';
                        alert(data.error);
                    } else {
                        thankYouText.style.animation = 'textDrop 1.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards';
                        createFireworks();
                        setTimeout(() => {
                            overlay.style.display = 'none';
                            window.loadStreak();
                        }, 4000);
                    }
                })
                .catch(() => {
                    overlay.style.display = 'none';
                    alert(_t('Connection error. Please try again.'));
                });
        }
    }, 1000);
}

function claimDaily() {
    const _t = (typeof window.__ === 'function') ? window.__ : (s => s);
    const dailyBtn = document.getElementById('daily-claim-btn');
    const dailyResult = document.getElementById('daily-result');
    if (!dailyBtn || !dailyResult) return;

    dailyBtn.disabled = true;
    dailyBtn.textContent = _t('Preparing...');

    const overlay = document.getElementById('drop-animation-overlay');
    const box = document.getElementById('drop-item-box');
    const thankYouText = document.getElementById('thank-you-text');
    const countdown = document.getElementById('countdown-text');

    box.className = 'drop-item-box teal-box';
    thankYouText.style.animation = 'none';
    thankYouText.style.opacity = '0';

    const newBox = box.cloneNode(true);
    box.parentNode.replaceChild(newBox, box);
    overlay.style.display = 'flex';

    let count = 3;
    countdown.textContent = count;
    countdown.style.display = 'block';

    const countInterval = setInterval(() => {
        count--;
        if (count > 0) {
            countdown.textContent = count;
            countdown.style.transform = 'scale(1.5)';
            setTimeout(() => countdown.style.transform = 'scale(1)', 100);
        } else {
            clearInterval(countInterval);
            countdown.style.transform = 'scale(1.5)';
            countdown.textContent = _t('DROPPING!');
            setTimeout(() => { countdown.style.display = 'none'; }, 600);

            fetch('/api/claim_daily.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.getCSRFToken() },
                body: JSON.stringify({})
            })
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        overlay.style.display = 'none';
                        dailyBtn.disabled = false;
                        dailyBtn.textContent = _t('🎲 Claim Daily Reward');
                        dailyResult.style.display = 'block';
                        dailyResult.style.color = '#ff4444';
                        dailyResult.textContent = data.error;
                    } else {
                        thankYouText.style.animation = 'textDrop 1.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards';
                        createFireworks();

                        setTimeout(() => {
                            overlay.style.display = 'none';
                            dailyResult.style.display = 'block';
                            dailyResult.style.color = '#00ff88';
                            dailyResult.textContent = '🎉 ' + data.item + ' ' + _t('dropped in-game!');

                            const nowUnix = Math.floor(Date.now() / 1000);
                            const midnightEstimate = nowUnix + (86400 - (nowUnix % 86400));
                            startDailyCountdown(dailyBtn, midnightEstimate, nowUnix);
                        }, 4000);
                    }
                })
                .catch(() => {
                    overlay.style.display = 'none';
                    dailyBtn.disabled = false;
                    dailyBtn.textContent = _t('🎲 Claim Daily Reward');
                    dailyResult.style.display = 'block';
                    dailyResult.style.color = '#ff4444';
                    dailyResult.textContent = _t('Connection error.');
                });
        }
    }, 1000);
}

let dailyCountdownInterval = null;
function startDailyCountdown(btn, resetTimestamp, serverTime) {
    const _t = (typeof window.__ === 'function') ? window.__ : (s => s);
    btn.disabled = true;
    btn.style.borderColor = 'rgba(255,255,255,0.15)';
    const offset = serverTime - Math.floor(Date.now() / 1000);

    function updateCountdown() {
        const nowServer = Math.floor(Date.now() / 1000) + offset;
        const remaining = resetTimestamp - nowServer;

        if (remaining <= 0) {
            btn.textContent = _t('🎲 Claim Daily Reward');
            btn.disabled = false;
            btn.style.borderColor = '#00ff88';
            if (dailyCountdownInterval) clearInterval(dailyCountdownInterval);
            window.loadStreak();
            return;
        }

        const hours = Math.floor(remaining / 3600);
        const mins = Math.floor((remaining % 3600) / 60);
        const secs = remaining % 60;
        const claimedPrefix = _t('Claimed — Next in');
        btn.textContent = `✓ ${claimedPrefix} ${hours}h ${mins}m ${secs}s`;
    }

    updateCountdown();
    if (dailyCountdownInterval) clearInterval(dailyCountdownInterval);
    dailyCountdownInterval = setInterval(updateCountdown, 1000);
}

// ---- Tekker Token Redemption Store ------------------------------------------
function getLang() {
    const match = document.cookie.match(/(?:^|; )psobb_lang=([^;]*)/);
    if (match && ['jp', 'ru'].includes(match[1])) return match[1];
    return 'en';
}

const tekkerI18n = {
    en: {
        choice1: "Weapon Choice 1",
        choice2: "Weapon Choice 2",
        choice3: "Weapon Choice 3",
        redeem: "Redeem Token",
        noAttr: "No attributes",
        earned: "Earned",
        loadError: "Failed to load tokens from server.",
        connError: "A connection error occurred.",
        select3: "You must select exactly 3 weapons",
        errorPrefix: "Error:",
        successPrefix: "Success:",
        dropping: "DROPPING!",
        maxSelect: "You can select at most 3 tokens.",
        tierInfo: "{count} Token(s) — Up to {stars}-star weapons unlocked",
        noSelected: "None",
        combinedLabel: "Combined Stats",
        keep3Error: "You must select exactly 3 attributes to keep.",
        noticePrefix: "Notice:",
        selectWeaponPrompt: "Please select a weapon in all three choice dropdowns.",
        invalidWeapon: "Invalid weapon selection.",
        tierLocked: "Weapon tier {tier} is locked. Select more tokens to unlock it.",
        noDupes: "For Tier {tier} weapons with {count} token(s), you must select different items (no duplicates allowed).",
        maxDupes: "You can select at most {max} duplicates of the same Tier {tier} weapon.",
        tierSuffix: " (Tier {tier})"
    },
    jp: {
        choice1: "武器候補 1",
        choice2: "武器候補 2",
        choice3: "武器候補 3",
        redeem: "トークンを交換する",
        noAttr: "属性なし",
        earned: "獲得日",
        loadError: "サーバーからトークンを読み込めませんでした。",
        connError: "接続エラーが発生しました。",
        select3: "武器を正確に3つ選択してください。",
        errorPrefix: "エラー:",
        successPrefix: "成功:",
        dropping: "ドロップ中!",
        maxSelect: "選択できるトークンは最大3個までです。",
        tierInfo: "{count}個のトークン — 最大 {stars}★ の武器をアンロック",
        noSelected: "選択なし",
        combinedLabel: "結合ステータス",
        keep3Error: "残す属性を正確に3つ選択してください。",
        noticePrefix: "注意:",
        selectWeaponPrompt: "3つの武器候補すべてを選択してください。",
        invalidWeapon: "無効な武器選択です。",
        tierLocked: "ティア {tier} の武器はロックされています。より多くのトークンを選択してアンロックしてください。",
        noDupes: "{count}個のトークンでティア {tier} の武器を選ぶ場合、すべて異なる武器を選択する必要があります（重複不可）。",
        maxDupes: "同じティア {tier} 武器は最大 {max} 個までしか選択できません。",
        tierSuffix: " (Tier {tier})"
    },
    ru: {
        choice1: "Выбор оружия 1",
        choice2: "Выбор оружия 2",
        choice3: "Выбор оружия 3",
        redeem: "Обменять жетон",
        noAttr: "Без атрибутов",
        earned: "Получено",
        loadError: "Не удалось загрузить жетоны с сервера.",
        connError: "Произошла ошибка соединения.",
        select3: "Необходимо выбрать ровно 3 оружия",
        errorPrefix: "Ошибка:",
        successPrefix: "Успех:",
        dropping: "ВЫПАДЕНИЕ!",
        maxSelect: "Можно выбрать максимум 3 жетона.",
        tierInfo: "Жетонов: {count} — Оружие до {stars}★ разблокировано",
        noSelected: "Нет",
        combinedLabel: "Общие характеристики",
        keep3Error: "Необходимо выбрать ровно 3 характеристики для сохранения.",
        noticePrefix: "Внимание:",
        selectWeaponPrompt: "Выберите оружие во всех трёх выпадающих списках.",
        invalidWeapon: "Недопустимый выбор оружия.",
        tierLocked: "Оружие уровня {tier} заблокировано. Выберите больше жетонов для разблокировки.",
        noDupes: "Для оружия {tier}★ с {count} жетоном(ами) нужно выбрать разные предметы (без дубликатов).",
        maxDupes: "Можно выбрать максимум {max} одинаковых оружия {tier}★.",
        tierSuffix: " ({tier}★)"
    }
};

const tier9Weapons = [
    // Hunter
    { hex: '000207', en: "Dragon Slayer", jp: "ドラゴンスレイヤー", classes: ['HU'] },
    { hex: '008900', en: "Musashi", jp: "ムサシ", classes: ['HU'] },
    { hex: '000206', en: "Last Survivor", jp: "ラストサバイバー", classes: ['HU'] },
    { hex: '000407', en: "Gae Bolg", jp: "ゲイボルグ", classes: ['HU'] },
    { hex: '000307', en: "Cross Scar", jp: "クロススカー", classes: ['HU'] },
    { hex: '000405', en: "Brionac", jp: "ブリオナック", classes: ['HU'] },
    { hex: '000305', en: "Blade Dance", jp: "ブレイドダンス", classes: ['HU'] },
    { hex: '000306', en: "Bloody Art", jp: "ブラッディ アート", classes: ['HU'] },
    // Ranger
    { hex: '00CD00', en: "Tanegashima", jp: "タネガシマ", classes: ['RA'] },
    { hex: '00D200', en: "Ano Bazooka", jp: "アノバズーカ", classes: ['RA'] },
    { hex: '000707', en: "Justy-23ST", jp: "ジャスティ−２３ＳＴ", classes: ['RA'] },
    { hex: '000706', en: "Wals-MK2", jp: "ヴァルス−ＭＫ２", classes: ['RA'] },
    { hex: '000705', en: "Visk-235W", jp: "ヴィスク−２３５Ｗ", classes: ['RA'] },
    { hex: '008B00', en: "Photon Launcher", jp: "フォトンランチャー", classes: ['RA'] },
    { hex: '000907', en: "Final Impact", jp: "ファイナルインパクト", classes: ['RA'] },
    { hex: '000906', en: "Meteor Smash", jp: "メテオスマッシュ", classes: ['RA'] },
    { hex: '000905', en: "Crush Bullet", jp: "クラッシュバレット", classes: ['RA'] },
    // Force
    { hex: '000B06', en: "Alive Aqhu", jp: "アライブアクウ", classes: ['FO'] },
    { hex: '005500', en: "Rabbit Wand", jp: "ラビットウォンド", classes: ['FO'] },
    { hex: '000B05', en: "Brave Hammer", jp: "ブレイブハンマー", classes: ['FO'] },
    { hex: '000B04', en: "Battle Verge", jp: "バトルヴァージ", classes: ['FO'] },
    { hex: '008C00', en: "Talis", jp: "タリス", classes: ['FO'] },
    { hex: '000A06', en: "Club of Zumiuran", jp: "ズミウランの杖", classes: ['FO'] },
    { hex: '000A05', en: "Mace of Adaman", jp: "アダマンの杖", classes: ['FO'] },
    { hex: '000A04', en: "Club of Laconium", jp: "ラコニウムの杖", classes: ['FO'] },
    { hex: '000C06', en: "Storm Wand: Indra", jp: "インドラの稲妻", classes: ['FO'] },
    { hex: '000C05', en: "Ice Staff: Dagon", jp: "ダゴンの氷杖", classes: ['FO'] },
    // All Classes
    { hex: '000F00', en: "Brave Knuckle", jp: "ブレイブナックル", classes: ['HU', 'RA', 'FO'] },
    { hex: '000107', en: "Durandal", jp: "デュランダル", classes: ['HU', 'RA', 'FO'] },
    { hex: '000106', en: "Kaladbolg", jp: "カラドボルグ", classes: ['HU', 'RA', 'FO'] },
    { hex: '000D00', en: "Photon Claw", jp: "フォトンクロー", classes: ['HU', 'RA', 'FO'] },
    { hex: '00CB00', en: "Tyrell's Parasol", jp: "タイレルズパラソル", classes: ['HU', 'RA', 'FO'] },
    { hex: '000607', en: "Bravace", jp: "ブレイバズ", classes: ['HU', 'RA', 'FO'] },
    { hex: '000605', en: "Varista", jp: "バリスタ", classes: ['HU', 'RA', 'FO'] },
    { hex: '000606', en: "Custom Ray ver.OO", jp: "カスタムレイｖｅｒ．ＯＯ", classes: ['HU', 'RA', 'FO'] },
    { hex: '000E00', en: "Double Saber", jp: "ダブルセイバー", classes: ['HU', 'RA', 'FO'] },
    { hex: '000506', en: "Diska of Liberator", jp: "リベレイターの円刃", classes: ['HU', 'RA', 'FO'] }
];

const tier10Weapons = [
    // Hunter
    { hex: '00B700', en: "Shouren", jp: "ショウレン", classes: ['HU'] },
    { hex: '002001', en: "Laconium Axe", jp: "ラコニウムの斧", classes: ['HU'] },
    { hex: '006900', en: "Heart of Poumn", jp: "ハートオブポウム", classes: ['HU'] },
    { hex: '008A02', en: "Kamui", jp: "カムイ", classes: ['HU'] },
    { hex: '003400', en: "Red Sword", jp: "レッドソード", classes: ['HU'] },
    // Ranger
    { hex: '00070B', en: "Rianov 303SNR-3", jp: "リアノフ３０３ＳＮＲ−３", classes: ['RA'] },
    { hex: '004E00', en: "Panzer Faust", jp: "パンツァーファウスト", classes: ['RA'] },
    { hex: '00070C', en: "Rianov 303SNR-4", jp: "リアノフ３０３ＳＮＲ−４", classes: ['RA'] },
    { hex: '001500', en: "Flame Visit", jp: "フレームビジット", classes: ['RA'] },
    { hex: '006B00', en: "Yasminkov 7000V", jp: "ヤスミノコフ７０００Ｖ", classes: ['RA'] },
    // Force
    { hex: '000C07', en: "Earth Wand Brownie", jp: "大地の杖「ブラウニー」", classes: ['FO'] },
    { hex: '00C400', en: "Siren Glass Hammer", jp: "サイレンガラスハンマー", classes: ['FO'] },
    { hex: '002200', en: "Caduceus", jp: "カドゥケウス", classes: ['FO'] },
    { hex: '00C200', en: "Solferino", jp: "ソルフェリーノ", classes: ['FO'] },
    { hex: '009200', en: "Guardianna", jp: "ガーディアンナ", classes: ['FO'] },
    // Multi-class (Hunter / Ranger)
    { hex: '00B500', en: "Sacred Duster", jp: "セイクリッドダスター", classes: ['HU', 'RA'] },
    { hex: '000F02', en: "God Hand", jp: "ゴッドハンド", classes: ['HU', 'RA'] },
    { hex: '009800', en: "Rika's Claw", jp: "リカのクロー", classes: ['HU', 'RA'] },
    { hex: '002900', en: "Yamigarasu", jp: "ヤミガラス", classes: ['HU', 'RA'] },
    { hex: '00B400', en: "Kusanagi", jp: "クサナギ", classes: ['HU', 'RA'] },
    // Multi-class (Hunter / Force)
    { hex: '002700', en: "Ancient Saber", jp: "古の剣", classes: ['HU', 'FO'] },
    { hex: '001101', en: "Soul Banish", jp: "ソウルバニッシュ", classes: ['HU', 'FO'] },
    // All Classes
    { hex: '000B07', en: "Valkyrie", jp: "ヴァルキリー", classes: ['HU', 'RA', 'FO'] },
    { hex: '000D03', en: "Phoenix Claw", jp: "フェニックスクロー", classes: ['HU', 'RA', 'FO'] },
    { hex: '000F01', en: "Angry Fist", jp: "アングリーフィスト", classes: ['HU', 'RA', 'FO'] },
    { hex: '00C600', en: "Shichishito", jp: "シチシトウ", classes: ['HU', 'RA', 'FO'] },
    { hex: '009400', en: "Morning Glory", jp: "モーニンググローリー", classes: ['HU', 'RA', 'FO'] }
];

const tier11Weapons = [
    // Hunter
    { hex: '001001', en: "Agito (1975)", jp: "アギト (1975)", classes: ['HU'] },
    // Ranger
    { hex: '008D00', en: "Nug2000-Bazooka", jp: "ヌグ２０００バズーカ", classes: ['RA'] },
    // Force
    { hex: '00C900', en: "Decalog", jp: "デカログ", classes: ['FO'] },
    { hex: '005A00', en: "Prophets of Motav", jp: "モタブの預言書", classes: ['FO'] },
    // All Classes
    { hex: '003A00', en: "Madam's Parasol", jp: "マダムのパラソル", classes: ['HU', 'RA', 'FO'] }
];

window.loadTekkerTokens = async function () {
    const loader = document.getElementById('tekker-loader');
    const unlinked = document.getElementById('tekker-unlinked-state');
    const empty = document.getElementById('tekker-empty-state');
    const container = document.getElementById('tekker-tokens-container');
    const alertBox = document.getElementById('tekker-status-alert');
    const redemptionCard = document.getElementById('tekker-redemption-card');
    
    const lang = getLang();
    const t = tekkerI18n[lang];

    if (alertBox) alertBox.style.display = 'none';
    if (loader) loader.style.display = 'block';
    if (unlinked) unlinked.style.display = 'none';
    if (empty) empty.style.display = 'none';
    if (redemptionCard) redemptionCard.style.display = 'none';
    if (container) { container.style.display = 'none'; container.innerHTML = ''; }

    try {
        const res = await fetch('/api/claim_tekker_drop.php', { credentials: 'same-origin' });
        const data = await res.json();

        if (loader) loader.style.display = 'none';

        if (!data.linked) {
            if (unlinked) unlinked.style.display = 'block';
            return;
        }

        if (!data.tokens || data.tokens.length === 0) {
            if (empty) empty.style.display = 'block';
            return;
        }

        if (container) {
            container.style.display = 'flex';
            container.innerHTML = data.tokens.map(token => {
                const statParts = [];
                statParts.push(`<span class="tekker-stat-badge ${token.stat_native > 0 ? 'has-val' : ''}">Native: +${token.stat_native}%</span>`);
                statParts.push(`<span class="tekker-stat-badge ${token.stat_abeast > 0 ? 'has-val' : ''}">A.Beast: +${token.stat_abeast}%</span>`);
                statParts.push(`<span class="tekker-stat-badge ${token.stat_machine > 0 ? 'has-val' : ''}">Machine: +${token.stat_machine}%</span>`);
                statParts.push(`<span class="tekker-stat-badge ${token.stat_dark > 0 ? 'has-val' : ''}">Dark: +${token.stat_dark}%</span>`);
                statParts.push(`<span class="tekker-stat-badge ${token.stat_hit > 0 ? 'has-hit' : ''}">Hit: +${token.stat_hit}%</span>`);
                const statsDisplay = statParts.join('');

                return `
                    <div id="tekker-card-${token.token_id}" class="tekker-card-premium" onclick="window.handleCardClick(event, '${token.token_id}')">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <label class="tekker-checkbox-container" onclick="event.stopPropagation()">
                                    <input type="checkbox" class="tekker-select-cb" value="${token.token_id}" 
                                           data-native="${token.stat_native}" 
                                           data-abeast="${token.stat_abeast}" 
                                           data-machine="${token.stat_machine}" 
                                           data-dark="${token.stat_dark}" 
                                           data-hit="${token.stat_hit}" 
                                           onchange="window.updateTekkerSelection()" />
                                    <span class="tekker-checkmark"></span>
                                </label>
                                <div>
                                    <span style="font-family:'Share Tech Mono', monospace; font-size:0.8rem; color:#888;">TOKEN: ${token.token_id}</span>
                                    <div style="margin-top:6px; display:flex; flex-wrap:wrap; gap:4px;">
                                        ${statsDisplay}
                                    </div>
                                </div>
                            </div>
                            <span style="font-size:0.75rem; color:#666; font-family:'Share Tech Mono', monospace;">${t.earned}: ${token.created_at}</span>
                        </div>
                    </div>
                `;
            }).join('');
        }
    } catch (e) {
        if (loader) loader.style.display = 'none';
        if (alertBox) {
            alertBox.style.display = 'block';
            alertBox.className = 'alert-box danger';
            alertBox.textContent = t.loadError;
        }
    }
};

window.handleCardClick = function (event, tokenId) {
    if (event.target.closest('.tekker-checkbox-container') || event.target.tagName === 'INPUT') {
        return;
    }
    const cb = document.querySelector(`.tekker-select-cb[value="${tokenId}"]`);
    if (cb) {
        cb.checked = !cb.checked;
        window.updateTekkerSelection();
    }
};

function getWeaponTier(hex) {
    if (tier9Weapons.some(w => w.hex === hex)) return 9;
    if (tier10Weapons.some(w => w.hex === hex)) return 10;
    if (tier11Weapons.some(w => w.hex === hex)) return 11;
    return null;
}

window.syncWeaponDropdowns = function (changedSelectId, _unused, classFilterChanged = false) {
    const checkboxes = Array.from(document.querySelectorAll('.tekker-select-cb'));
    const checked = checkboxes.filter(cb => cb.checked);
    const count = checked.length;
    if (count === 0) return;

    const w1 = document.getElementById('tekker-weapon-1');
    const w2 = document.getElementById('tekker-weapon-2');
    const w3 = document.getElementById('tekker-weapon-3');
    const classFilter = document.getElementById('tekker-class-filter');
    if (!w1 || !w2 || !w3) return;

    const lang = getLang();
    const t = tekkerI18n[lang];
    const filterVal = classFilter ? classFilter.value : 'ALL';

    let allAvailableWeapons = [];
    tier9Weapons.forEach(w => { allAvailableWeapons.push({ ...w, tier: 9 }); });
    if (count >= 2) {
        tier10Weapons.forEach(w => { allAvailableWeapons.push({ ...w, tier: 10 }); });
    }
    if (count >= 3) {
        tier11Weapons.forEach(w => { allAvailableWeapons.push({ ...w, tier: 11 }); });
    }

    let filteredWeapons = allAvailableWeapons;
    if (filterVal !== 'ALL') {
        filteredWeapons = allAvailableWeapons.filter(w => w.classes && w.classes.includes(filterVal));
    }
    if (filteredWeapons.length === 0) {
        filteredWeapons = allAvailableWeapons;
    }

    const selects = [w1, w2, w3];
    const validHexes = filteredWeapons.map(w => w.hex);

    selects.forEach((el, idx) => {
        const prevVal = el.value;
        let valToSet = prevVal;
        if (classFilterChanged || !validHexes.includes(prevVal)) {
            valToSet = validHexes[idx % validHexes.length];
        }

        el.innerHTML = filteredWeapons.map(w => {
            const selectedAttr = (w.hex === valToSet) ? 'selected' : '';
            const tierSuffix = t.tierSuffix.replace('{tier}', w.tier);
            const labelName = (lang === 'jp' ? w.jp : w.en) + tierSuffix;
            return `<option value="${w.hex}" ${selectedAttr}>${labelName}</option>`;
        }).join('');

        el.value = valToSet;
    });

    const val1 = w1.value;
    const val2 = w2.value;
    const val3 = w3.value;

    const claimBtn = document.getElementById('tekker-claim-btn');
    let hasSelectionError = false;
    let selectionErrorMsg = '';

    if (!val1 || !val2 || !val3) {
        hasSelectionError = true;
        selectionErrorMsg = t.selectWeaponPrompt;
    } else {
        const counts = {};
        [val1, val2, val3].forEach(v => {
            counts[v] = (counts[v] || 0) + 1;
        });

        for (const hex in counts) {
            const tier = getWeaponTier(hex);
            if (!tier) {
                hasSelectionError = true;
                selectionErrorMsg = t.invalidWeapon;
                break;
            }
            if (tier > 8 + count) {
                hasSelectionError = true;
                selectionErrorMsg = t.tierLocked.replace('{tier}', tier);
                break;
            }
            const maxAllowed = count - (tier - 9);
            if (counts[hex] > maxAllowed) {
                hasSelectionError = true;
                if (maxAllowed === 1) {
                    selectionErrorMsg = t.noDupes.replace('{tier}', tier).replace('{count}', count);
                } else {
                    selectionErrorMsg = t.maxDupes.replace('{max}', maxAllowed).replace('{tier}', tier);
                }
                break;
            }
        }
    }

    const errContainer = document.getElementById('tekker-selection-error-container');
    const errText = document.getElementById('tekker-selection-error-text');

    if (hasSelectionError) {
        if (errContainer && errText) {
            errContainer.style.display = 'block';
            errText.innerHTML = `⚠️ <b>${t.noticePrefix}</b> ${selectionErrorMsg}`;
        }
        if (claimBtn) claimBtn.disabled = true;
        return;
    }

    if (errContainer) {
        errContainer.style.display = 'none';
    }

    const keeperContainer = document.getElementById('tekker-keeper-selection-container');
    const keeperActive = keeperContainer && keeperContainer.style.display !== 'none';
    let keeperOk = true;
    if (keeperActive) {
        const keeperCheckedCount = document.querySelectorAll('.tekker-keeper-cb:checked').length;
        if (keeperCheckedCount !== 3) {
            keeperOk = false;
        }
    }

    const stats = { stat_native: 0, stat_abeast: 0, stat_machine: 0, stat_dark: 0, stat_hit: 0 };
    checked.forEach(cb => {
        stats.stat_native += parseInt(cb.getAttribute('data-native') || 0);
        stats.stat_abeast += parseInt(cb.getAttribute('data-abeast') || 0);
        stats.stat_machine += parseInt(cb.getAttribute('data-machine') || 0);
        stats.stat_dark += parseInt(cb.getAttribute('data-dark') || 0);
        stats.stat_hit += parseInt(cb.getAttribute('data-hit') || 0);
    });
    if (keeperActive) {
        const kept = Array.from(document.querySelectorAll('.tekker-keeper-cb:checked')).map(cb => cb.value);
        Object.keys(stats).forEach(k => { if (!kept.includes(k)) stats[k] = 0; });
    }
    const overCap = Object.values(stats).some(v => v > 90);
    const warningContainer = document.getElementById('tekker-cap-warning-container');
    if (warningContainer) {
        warningContainer.style.display = overCap ? 'block' : 'none';
    }

    if (claimBtn) {
        claimBtn.disabled = !keeperOk || overCap;
    }
};

window.handleKeeperChange = function () {
    const checkboxes = Array.from(document.querySelectorAll('.tekker-keeper-cb'));
    const checked = checkboxes.filter(cb => cb.checked);
    const count = checked.length;

    checkboxes.forEach(cb => {
        if (!cb.checked) {
            cb.disabled = (count >= 3);
        } else {
            cb.disabled = false;
        }
    });

    const claimBtn = document.getElementById('tekker-claim-btn');
    if (claimBtn) {
        claimBtn.disabled = (count < 3);
    }

    window.syncWeaponDropdowns();
};

window.updateTekkerSelection = function () {
    const checkboxes = Array.from(document.querySelectorAll('.tekker-select-cb'));
    const checked = checkboxes.filter(cb => cb.checked);
    const alertBox = document.getElementById('tekker-status-alert');
    const redemptionCard = document.getElementById('tekker-redemption-card');
    
    const lang = getLang();
    const t = tekkerI18n[lang];

    if (alertBox) alertBox.style.display = 'none';

    // Enforce max 3 tokens selected
    if (checked.length > 3) {
        // Find the last checkbox that was clicked and uncheck it
        checked[checked.length - 1].checked = false;
        checked.pop(); // Remove from checked array
        
        if (alertBox) {
            alertBox.style.display = 'block';
            alertBox.className = 'alert-box danger';
            alertBox.innerHTML = `❌ <b>${t.errorPrefix}</b> ${t.maxSelect}`;
        }
    }

    // Toggle active-selected class on card containers based on selection state
    checkboxes.forEach(cb => {
        const card = document.getElementById(`tekker-card-${cb.value}`);
        if (card) {
            if (cb.checked) {
                card.classList.add('active-selected');
            } else {
                card.classList.remove('active-selected');
            }
        }
    });

    const count = checked.length;
    if (count === 0) {
        if (redemptionCard) redemptionCard.style.display = 'none';
        return;
    }

    if (redemptionCard) redemptionCard.style.display = 'block';

    // Aggregate stats
    let native = 0, abeast = 0, machine = 0, dark = 0, hit = 0;
    checked.forEach(cb => {
        native += parseInt(cb.getAttribute('data-native') || 0);
        abeast += parseInt(cb.getAttribute('data-abeast') || 0);
        machine += parseInt(cb.getAttribute('data-machine') || 0);
        dark += parseInt(cb.getAttribute('data-dark') || 0);
        hit += parseInt(cb.getAttribute('data-hit') || 0);
    });

    const overCap = (native > 90 || abeast > 90 || machine > 90 || dark > 90 || hit > 90);
    const warningContainer = document.getElementById('tekker-cap-warning-container');
    if (warningContainer) {
        warningContainer.style.display = overCap ? 'block' : 'none';
    }

    const statParts = [];
    statParts.push(`<span class="tekker-stat-badge ${native > 0 ? 'has-val' : ''} ${native > 90 ? 'over-cap' : ''}">Native: +${native}%</span>`);
    statParts.push(`<span class="tekker-stat-badge ${abeast > 0 ? 'has-val' : ''} ${abeast > 90 ? 'over-cap' : ''}">A.Beast: +${abeast}%</span>`);
    statParts.push(`<span class="tekker-stat-badge ${machine > 0 ? 'has-val' : ''} ${machine > 90 ? 'over-cap' : ''}">Machine: +${machine}%</span>`);
    statParts.push(`<span class="tekker-stat-badge ${dark > 0 ? 'has-val' : ''} ${dark > 90 ? 'over-cap' : ''}">Dark: +${dark}%</span>`);
    statParts.push(`<span class="tekker-stat-badge ${hit > 0 ? 'has-hit' : ''} ${hit > 90 ? 'over-cap' : ''}">Hit: +${hit}%</span>`);
    const combinedStatsHtml = statParts.join('');

    document.getElementById('tekker-selected-count').textContent = count;
    document.getElementById('tekker-combined-stats').innerHTML = combinedStatsHtml;

    // Handle >3 stats keeper selection checkboxes
    const activeAttributes = [];
    if (native > 0) activeAttributes.push({ key: 'stat_native', label: 'Native' });
    if (abeast > 0) activeAttributes.push({ key: 'stat_abeast', label: 'A.Beast' });
    if (machine > 0) activeAttributes.push({ key: 'stat_machine', label: 'Machine' });
    if (dark > 0) activeAttributes.push({ key: 'stat_dark', label: 'Dark' });
    if (hit > 0) activeAttributes.push({ key: 'stat_hit', label: 'Hit' });

    const keeperContainer = document.getElementById('tekker-keeper-selection-container');
    const keeperCheckboxesDiv = document.getElementById('tekker-keeper-checkboxes');
    const claimBtn = document.getElementById('tekker-claim-btn');

    if (activeAttributes.length > 3) {
        if (keeperContainer && keeperCheckboxesDiv) {
            const checkedKeys = Array.from(document.querySelectorAll('.tekker-keeper-cb:checked')).map(cb => cb.value);
            keeperContainer.style.display = 'block';
            keeperCheckboxesDiv.innerHTML = activeAttributes.map(attr => {
                const isChecked = checkedKeys.includes(attr.key) ? 'checked' : '';
                return `
                    <label style="font-family:'Share Tech Mono', monospace; font-size:0.85rem; color:#fff; display:flex; align-items:center; gap:4px; cursor:pointer;">
                        <input type="checkbox" class="tekker-keeper-cb" value="${attr.key}" ${isChecked} onchange="window.handleKeeperChange()" />
                        ${attr.label}
                    </label>
                `;
            }).join('');
            window.handleKeeperChange();
        }
    } else {
        if (keeperContainer) keeperContainer.style.display = 'none';
        if (keeperCheckboxesDiv) keeperCheckboxesDiv.innerHTML = '';
        if (claimBtn) claimBtn.disabled = false;
    }

    let stars = 9;
    if (count === 1) stars = 9;
    else if (count === 2) stars = 10;
    else stars = 11;

    document.getElementById('tekker-unlocked-tier').textContent = t.tierInfo.replace('{count}', count).replace('{stars}', stars);

    window.syncWeaponDropdowns();
};

window.submitTekkerClaim = async function () {
    const checkboxes = Array.from(document.querySelectorAll('.tekker-select-cb'));
    const checked = checkboxes.filter(cb => cb.checked);
    const tokenIds = checked.map(cb => (cb.value || '').replace(/[\r\n\t ]/g, ''));
    
    const w1 = document.getElementById('tekker-weapon-1').value;
    const w2 = document.getElementById('tekker-weapon-2').value;
    const w3 = document.getElementById('tekker-weapon-3').value;
    const alertBox = document.getElementById('tekker-status-alert');
    const claimBtn = document.getElementById('tekker-claim-btn');

    const lang = getLang();
    const t = tekkerI18n[lang];

    if (alertBox) alertBox.style.display = 'none';
    if (claimBtn) claimBtn.disabled = true;

    const keeperContainer = document.getElementById('tekker-keeper-selection-container');
    let keepAttributes = [];
    if (keeperContainer && keeperContainer.style.display !== 'none') {
        const keeperCBs = Array.from(document.querySelectorAll('.tekker-keeper-cb:checked'));
        keepAttributes = keeperCBs.map(cb => cb.value);
        if (keepAttributes.length !== 3) {
            if (alertBox) {
                alertBox.style.display = 'block';
                alertBox.className = 'alert-box danger';
                alertBox.innerHTML = `❌ <b>${t.errorPrefix}</b> ${t.keep3Error}`;
            }
            if (claimBtn) claimBtn.disabled = false;
            return;
        }
    }

    const overlay = document.getElementById('drop-animation-overlay');
    const box = document.getElementById('drop-item-box');
    const countdownEl = document.getElementById('countdown-text');
    const thankYouText = document.getElementById('thank-you-text');

    if (overlay && box && countdownEl && thankYouText) {
        box.className = 'drop-item-box orange-box';
        thankYouText.style.animation = 'none';
        thankYouText.style.opacity = '0';

        const newBox = box.cloneNode(true);
        box.parentNode.replaceChild(newBox, box);

        overlay.style.display = 'flex';

        let count = 3;
        countdownEl.style.display = 'block';
        countdownEl.textContent = count;

        const countdownInterval = setInterval(() => {
            count--;
            if (count > 0) {
                countdownEl.textContent = count;
                countdownEl.style.transform = 'scale(1.5)';
                setTimeout(() => countdownEl.style.transform = 'scale(1)', 100);
            } else if (count === 0) {
                countdownEl.style.transform = 'scale(1.5)';
                countdownEl.textContent = t.dropping;

                fetch('/api/claim_tekker_drop.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.getCSRFToken()
                    },
                    body: JSON.stringify({
                        token_ids: tokenIds,
                        weapons: [w1, w2, w3],
                        keep_attributes: keepAttributes,
                        csrf_token: window.getCSRFToken()
                    })
                })
                .then(res => res.json())
                .then(data => {
                    clearInterval(countdownInterval);
                    if (data.error) {
                        overlay.style.display = 'none';
                        if (claimBtn) claimBtn.disabled = false;
                        if (alertBox) {
                            alertBox.style.display = 'block';
                            alertBox.className = 'alert-box danger';
                            alertBox.innerHTML = `❌ <b>${t.errorPrefix}</b> ${data.error}`;
                        }
                    } else {
                        countdownEl.style.display = 'none';
                        thankYouText.style.animation = 'textDrop 1.5s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards';
                        if (window.createFireworks) window.createFireworks();

                        setTimeout(() => {
                            overlay.style.display = 'none';
                            if (claimBtn) claimBtn.disabled = false;
                            if (alertBox) {
                                alertBox.style.display = 'block';
                                alertBox.className = 'alert-box success';
                                alertBox.innerHTML = `🎉 <b>${t.successPrefix}</b> ${data.message}`;
                            }
                            window.loadTekkerTokens();
                        }, 3500);
                    }
                })
                .catch(err => {
                    clearInterval(countdownInterval);
                    overlay.style.display = 'none';
                    if (claimBtn) claimBtn.disabled = false;
                    if (alertBox) {
                        alertBox.style.display = 'block';
                        alertBox.className = 'alert-box danger';
                        alertBox.textContent = t.connError;
                    }
                });
            }
        }, 1000);
    }
};

window.claimTekkerToken = function (tokenId) {
    const cb = document.querySelector(`.tekker-select-cb[value="${tokenId}"]`);
    if (cb) {
        cb.checked = true;
        window.updateTekkerSelection();
    }
};


