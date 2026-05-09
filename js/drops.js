/**
 * Rare drops (search-first) + Section ID weapon chart.
 */

(function () {
    'use strict';

    const UINT32 = 4294967296;
    const PAGE_SIZE = 125;
    const DATA_URL_RARE = '/api/data/rare-drops-v4.json';
    const DATA_URL_WEAPON_CHART = '/api/data/weapon-type-by-section-v4.json';

    const DIFF_ORDER = ['Normal', 'Hard', 'VeryHard', 'Ultimate'];
    const EP_ORDER = ['Episode1', 'Episode2', 'Episode4'];

    /** BB Section ID canonical order for mix cards */
    const SECTION_ID_ORDER = [
        'Viridia',
        'Greennill',
        'Skyly',
        'Bluefull',
        'Purplenum',
        'Pinkal',
        'Redria',
        'Oran',
        'Yellowboze',
        'Whitill'
    ];

    let allRows = [];
    let filtered = [];
    let page = 0;
    let debounceId = null;
    /** @type {{ key: string, dir: 1|-1 }} */
    let sortState = { key: '_default', dir: 1 };

    let weaponChartEntries = [];
    /** @type {{ code: string, label: string }[]} */
    let weaponTypes = [];
    /** @type {{ default_episode_token?: string; default_mode?: string; default_difficulty?: string } & Record<string, unknown>} */
    let weaponChartMeta = {};

    function difficultyRank(d) {
        const i = DIFF_ORDER.indexOf(d);
        return i === -1 ? 99 : i;
    }

    function episodeRank(e) {
        const i = EP_ORDER.indexOf(e);
        return i === -1 ? 99 : i;
    }

    function gcdPositive(a, b) {
        let x = Math.abs(Math.floor(a));
        let y = Math.abs(Math.floor(b));
        while (y !== 0) {
            const t = x % y;
            x = y;
            y = t;
        }
        return x || 1;
    }

    /** @param {string|number|null|undefined} p */
    function fmtProbability(p) {
        if (p === null || p === undefined || p === '') {
            return '—';
        }
        if (typeof p === 'string') {
            return p;
        }
        if (typeof p === 'number' && Number.isFinite(p)) {
            const n = Math.round(p);
            if (Number.isInteger(n) && n > 0 && n <= UINT32) {
                const g = gcdPositive(n, UINT32);
                const num = Math.floor(n / g);
                const den = Math.floor(UINT32 / g);
                return `${num}/${den}`;
            }
            return String(p);
        }
        return String(p);
    }

    function fmtPercent(row) {
        if (typeof row.approx_percent === 'number' && Number.isFinite(row.approx_percent)) {
            if (row.approx_percent < 0.0001 && row.approx_percent > 0) {
                return row.approx_percent.toExponential(3);
            }
            return row.approx_percent.toFixed(5).replace(/\.?0+$/, '');
        }
        return '—';
    }

    function norm(s) {
        return (s || '').toLowerCase().trim();
    }

    function hasActiveQuery() {
        return !!(
            document.getElementById('drops-episode').value ||
            document.getElementById('drops-difficulty').value ||
            document.getElementById('drops-section').value ||
            norm(document.getElementById('drops-source-q').value) ||
            norm(document.getElementById('drops-item-q').value)
        );
    }

    function defaultCompareRare(a, b) {
        let c = episodeRank(a.episode) - episodeRank(b.episode);
        if (c !== 0) return c;
        c = difficultyRank(a.difficulty) - difficultyRank(b.difficulty);
        if (c !== 0) return c;
        c = String(a.section_id || '').localeCompare(String(b.section_id || ''));
        if (c !== 0) return c;
        c = String(a.source || '').localeCompare(String(b.source || ''));
        if (c !== 0) return c;
        return String(a.item_hex || '').localeCompare(String(b.item_hex || ''));
    }

    function probabilityNumeric(row) {
        const p = row.probability;
        if (typeof p === 'number' && Number.isFinite(p)) {
            return p / UINT32;
        }
        if (typeof p === 'string') {
            const s = p.trim();
            const slash = s.indexOf('/');
            if (slash !== -1) {
                const num = parseFloat(s.slice(0, slash));
                const den = parseFloat(s.slice(slash + 1));
                if (Number.isFinite(num) && Number.isFinite(den) && den !== 0) {
                    return num / den;
                }
            }
        }
        return null;
    }

    function compareRarePrimary(a, b, key) {
        switch (key) {
            case 'episode':
                return episodeRank(a.episode) - episodeRank(b.episode);
            case 'difficulty':
                return difficultyRank(a.difficulty) - difficultyRank(b.difficulty);
            case 'section_id':
                return String(a.section_id || '').localeCompare(String(b.section_id || ''));
            case 'source':
                return String(a.source || '').localeCompare(String(b.source || ''));
            case 'probability': {
                const va = probabilityNumeric(a);
                const vb = probabilityNumeric(b);
                if (va == null && vb == null) {
                    return String(a.probability ?? '').localeCompare(String(b.probability ?? ''));
                }
                if (va == null) return 1;
                if (vb == null) return -1;
                return va - vb;
            }
            case 'approx_percent': {
                const va =
                    typeof a.approx_percent === 'number' && Number.isFinite(a.approx_percent)
                        ? a.approx_percent
                        : null;
                const vb =
                    typeof b.approx_percent === 'number' && Number.isFinite(b.approx_percent)
                        ? b.approx_percent
                        : null;
                if (va == null && vb == null) return 0;
                if (va == null) return 1;
                if (vb == null) return -1;
                return va - vb;
            }
            case 'item_name':
                return String(a.item_name || '').localeCompare(String(b.item_name || ''), undefined, {
                    sensitivity: 'base'
                });
            case 'item_hex': {
                const na = parseInt(String(a.item_hex || ''), 16);
                const nb = parseInt(String(b.item_hex || ''), 16);
                const va = Number.isFinite(na) ? na : -1;
                const vb = Number.isFinite(nb) ? nb : -1;
                return va - vb;
            }
            default:
                return 0;
        }
    }

    function sortFilteredRare() {
        if (sortState.key === '_default') {
            filtered.sort(defaultCompareRare);
            return;
        }
        filtered.sort((a, b) => {
            const c = sortState.dir * compareRarePrimary(a, b, sortState.key);
            if (c !== 0) return c;
            return defaultCompareRare(a, b);
        });
    }

    function updateRareSortHeaders() {
        const wrap = document.getElementById('drops-table-wrap');
        if (!wrap) return;
        wrap.querySelectorAll('thead .sortable-th').forEach((th) => {
            th.classList.remove('sort-active', 'sort-asc', 'sort-desc');
            th.removeAttribute('aria-sort');
            const k = th.getAttribute('data-sort');
            if (sortState.key !== '_default' && k === sortState.key) {
                const asc = sortState.dir === 1;
                th.classList.add('sort-active', asc ? 'sort-asc' : 'sort-desc');
                th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
            } else {
                th.setAttribute('aria-sort', 'none');
            }
        });
    }

    function applyFilters() {
        if (!hasActiveQuery()) {
            filtered = [];
        } else {
            const ep = document.getElementById('drops-episode').value;
            const diff = document.getElementById('drops-difficulty').value;
            const sec = document.getElementById('drops-section').value;
            const sq = norm(document.getElementById('drops-source-q').value);
            const iq = norm(document.getElementById('drops-item-q').value);

            filtered = allRows.filter((row) => {
                if (ep && row.episode !== ep) return false;
                if (diff && row.difficulty !== diff) return false;
                if (sec && row.section_id !== sec) return false;
                if (sq && !norm(row.source).includes(sq)) return false;
                if (iq) {
                    const name = norm(row.item_name);
                    const hx = norm(row.item_hex);
                    const prob = typeof row.probability === 'string' ? norm(row.probability) : '';
                    if (!name.includes(iq) && !hx.includes(iq.replace(/^0x/i, '')) && !prob.includes(iq)) {
                        return false;
                    }
                }
                return true;
            });
        }

        sortFilteredRare();

        page = 0;
        renderPage();
        updateSummary();
        updateWeaponHighlight();
    }

    function renderPage() {
        const tbody = document.getElementById('drops-tbody');
        const pager = document.getElementById('drops-pager');
        const wrap = document.getElementById('drops-table-wrap');
        tbody.textContent = '';

        if (!hasActiveQuery()) {
            pager.style.display = 'none';
            wrap.style.display = 'block';
            tbody.innerHTML = `<tr><td colspan="8" class="drops-empty-msg">${escapeHtml(
                'Enter a search parameter to populate the table.'
            )}</td></tr>`;
            updateRareSortHeaders();
            return;
        }

        const total = filtered.length;
        const maxPage = Math.max(0, Math.ceil(total / PAGE_SIZE) - 1);
        if (page > maxPage) page = maxPage;

        const start = page * PAGE_SIZE;
        const slice = filtered.slice(start, start + PAGE_SIZE);

        const frag = document.createDocumentFragment();
        slice.forEach((row) => {
            const tr = document.createElement('tr');
            const name = row.item_name || '—';
            tr.innerHTML = [
                `<td>${escapeHtml(row.episode)}</td>`,
                `<td>${escapeHtml(row.difficulty)}</td>`,
                `<td>${escapeHtml(row.section_id)}</td>`,
                `<td class="drops-mono">${escapeHtml(row.source)}</td>`,
                `<td class="drops-prob">${escapeHtml(fmtProbability(row.probability))}</td>`,
                `<td class="drops-pct">${escapeHtml(fmtPercent(row))}</td>`,
                `<td>${escapeHtml(name)}</td>`,
                `<td class="drops-mono">${escapeHtml(row.item_hex)}</td>`
            ].join('');
            frag.appendChild(tr);
        });
        tbody.appendChild(frag);

        if (total <= PAGE_SIZE || !slice.length) {
            pager.style.display = 'none';
        } else {
            pager.style.display = 'flex';
            const hi = Math.min(total, start + slice.length);
            document.getElementById('drops-page-info').textContent =
                `${start + 1}–${hi} · ${total.toLocaleString()}`;
            document.getElementById('drops-prev').disabled = page <= 0;
            document.getElementById('drops-next').disabled = page >= maxPage;
        }

        wrap.style.display = 'block';
        if (!slice.length) {
            tbody.innerHTML = `<tr><td colspan="8" class="drops-empty-msg">No matching rare rows.</td></tr>`;
        }
        updateRareSortHeaders();
    }

    function escapeHtml(t) {
        const d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    function updateSummary() {
        const el = document.getElementById('drops-summary');
        if (!hasActiveQuery()) {
            el.textContent = '—';
            return;
        }
        const n = filtered.length;
        el.textContent =
            n.toLocaleString() +
            ' row' +
            (n === 1 ? '' : 's') +
            ' (of ' +
            allRows.length.toLocaleString() +
            ' rare lines)';
    }

    function fillSelect(sel, values, cmp) {
        const cur = sel.value;
        const opts = sel.querySelectorAll('option:not([value=""])');
        opts.forEach((o) => o.remove());
        const sorted = [...values];
        sorted.sort(cmp);
        sorted.forEach((v) => {
            const o = document.createElement('option');
            o.value = v;
            o.textContent = v;
            sel.appendChild(o);
        });
        sel.value = sorted.includes(cur) ? cur : '';
    }

    function sectionIdSortKey(name) {
        const i = SECTION_ID_ORDER.indexOf(name);
        return i === -1 ? 1000 + name.charCodeAt(0) : i;
    }

    /** @param {number} p */
    function fmtWeaponPctDisplay(p) {
        if (!Number.isFinite(p)) return '—';
        const rounded = Math.round(p * 10) / 10;
        if (Number.isInteger(rounded)) {
            return String(rounded);
        }
        return rounded.toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    }

    function getDefaultWeaponSubset() {
        const de = weaponChartMeta.default_episode_token || 'Ep1';
        const dm = weaponChartMeta.default_mode || 'Normal';
        const dd =
            weaponChartMeta.default_difficulty != null
                ? weaponChartMeta.default_difficulty
                : 'Ultimate';
        let rows = weaponChartEntries.filter(
            (e) => e.episode_token === de && e.mode === dm && e.difficulty === dd
        );
        if (!rows.length) {
            rows = weaponChartEntries.filter(
                (e) => e.episode_token === 'Ep1' && e.mode === 'Normal' && e.difficulty === 'Ultimate'
            );
        }
        return rows.slice().sort((a, b) => {
            const cmp =
                sectionIdSortKey(String(a.section_id || '')) -
                sectionIdSortKey(String(b.section_id || ''));
            return cmp !== 0 ? cmp : String(a.section_id).localeCompare(String(b.section_id));
        });
    }

    function renderWeaponMixChart() {
        const thead = document.getElementById('weapon-mix-thead');
        const tbody = document.getElementById('weapon-mix-tbody');
        if (!thead || !tbody || !weaponTypes.length) return;

        const subset = getDefaultWeaponSubset();
        thead.textContent = '';
        tbody.textContent = '';

        if (!subset.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 1 + weaponTypes.length;
            td.className = 'drops-empty-msg';
            td.textContent = 'Chart data unavailable.';
            tr.appendChild(td);
            tbody.appendChild(tr);
            return;
        }

        const headCells = weaponTypes
            .map((t) => {
                const lab = t.label || String(t.code || '');
                return `<th class="weapon-mix-col-pct" scope="col" title="${escapeHtml(lab)}">${escapeHtml(
                    lab
                )}</th>`;
            })
            .join('');
        thead.innerHTML = `<tr>
            <th scope="col" class="weapon-mix-col-sec">Section ID</th>
            ${headCells}
        </tr>`;

        const frag = document.createDocumentFragment();
        subset.forEach((row) => {
            const tr = document.createElement('tr');
            tr.setAttribute('data-section-id', row.section_id || '');

            const secTd = document.createElement('th');
            secTd.scope = 'row';
            secTd.className = 'weapon-mix-sec-cell';
            secTd.textContent = row.section_id || '—';
            tr.appendChild(secTd);

            weaponTypes.forEach((_t, i) => {
                const td = document.createElement('td');
                td.className = 'weapon-mix-col-pct';
                const p = Array.isArray(row.percent) ? row.percent[i] : null;
                const txt =
                    p == null || !Number.isFinite(Number(p))
                        ? '—'
                        : `${fmtWeaponPctDisplay(Number(p))}%`;
                td.textContent = txt;
                tr.appendChild(td);
            });

            frag.appendChild(tr);
        });
        tbody.appendChild(frag);

        updateWeaponHighlight();
    }

    function updateWeaponHighlight() {
        const sec = document.getElementById('drops-section').value;
        document.querySelectorAll('#weapon-mix-tbody tr[data-section-id]').forEach((tr) => {
            const sid = tr.getAttribute('data-section-id') || '';
            tr.classList.toggle('weapon-chart-row-highlight', !!sec && sid === sec);
        });
    }

    function bootstrapWeaponMix(data) {
        const st = document.getElementById('weapon-chart-status');
        const panel = document.getElementById('weapon-mix-panel');
        if (!st || !panel) return;
        weaponChartMeta = data.meta || {};
        weaponTypes = Array.isArray(data.types) ? data.types : [];
        weaponChartEntries = Array.isArray(data.entries) ? data.entries : [];
        if (!weaponTypes.length || !weaponChartEntries.length) {
            st.textContent = 'Chart data unavailable.';
            st.style.display = 'block';
            return;
        }
        st.style.display = 'none';
        panel.style.display = 'block';
        renderWeaponMixChart();
    }

    function wireEvents() {
        ['drops-episode', 'drops-difficulty', 'drops-section'].forEach((id) => {
            document.getElementById(id).addEventListener('change', () => {
                applyFilters();
            });
        });
        ['drops-source-q', 'drops-item-q'].forEach((id) => {
            document.getElementById(id).addEventListener('input', () => {
                clearTimeout(debounceId);
                debounceId = setTimeout(applyFilters, 180);
            });
        });

        document.getElementById('drops-reset').addEventListener('click', () => {
            document.getElementById('drops-episode').value = '';
            document.getElementById('drops-difficulty').value = '';
            document.getElementById('drops-section').value = '';
            document.getElementById('drops-source-q').value = '';
            document.getElementById('drops-item-q').value = '';
            applyFilters();
        });

        const thead = document.querySelector('#drops-table-wrap thead');
        if (thead) {
            thead.addEventListener('click', (ev) => {
                const th = ev.target.closest('th[data-sort]');
                if (!th) return;
                const key = th.getAttribute('data-sort');
                if (!key) return;
                if (sortState.key === key) {
                    sortState.dir = sortState.dir === 1 ? -1 : 1;
                } else {
                    sortState.key = key;
                    sortState.dir = 1;
                }
                sortFilteredRare();
                page = 0;
                renderPage();
                updateSummary();
            });
            thead.addEventListener('keydown', (ev) => {
                if (ev.key !== 'Enter' && ev.key !== ' ') return;
                const th = ev.target.closest('th[data-sort]');
                if (!th) return;
                ev.preventDefault();
                th.click();
            });
        }

        document.getElementById('drops-prev').addEventListener('click', () => {
            page = Math.max(0, page - 1);
            renderPage();
        });
        document.getElementById('drops-next').addEventListener('click', () => {
            page += 1;
            renderPage();
        });
    }

    let bootstrapStarted = false;

    function bootstrap() {
        if (bootstrapStarted) return;
        bootstrapStarted = true;

        const status = document.getElementById('drops-status');
        const filters = document.getElementById('drops-filters');
        const wrap = document.getElementById('drops-table-wrap');

        Promise.allSettled([
            fetch(DATA_URL_RARE).then((r) => {
                if (!r.ok) throw new Error('rare ' + String(r.status));
                return r.json();
            }),
            fetch(DATA_URL_WEAPON_CHART).then((r) => {
                if (!r.ok) throw new Error('weapon chart ' + String(r.status));
                return r.json();
            })
        ]).then((results) => {
            const rareRes = results[0];
            const weaponRes = results[1];

            if (rareRes.status !== 'fulfilled' || !Array.isArray(rareRes.value)) {
                if (status) {
                    status.textContent =
                        'Could not load rare data: ' +
                        (rareRes.status === 'rejected' ? rareRes.reason.message : 'invalid JSON');
                    status.classList.add('drops-status-err');
                }
                return;
            }

            const rareData = rareRes.value;
            allRows = rareData;

            const eps = new Set();
            const diffs = new Set();
            const secs = new Set();
            rareData.forEach((row) => {
                eps.add(row.episode);
                diffs.add(row.difficulty);
                secs.add(row.section_id);
            });

            fillSelect(document.getElementById('drops-episode'), eps, (a, b) =>
                episodeRank(a) - episodeRank(b));
            fillSelect(document.getElementById('drops-difficulty'), diffs, (a, b) =>
                difficultyRank(a) - difficultyRank(b));
            fillSelect(document.getElementById('drops-section'), secs, (a, b) =>
                String(a).localeCompare(String(b)));

            status.style.display = 'none';
            filters.style.display = 'block';
            wrap.style.display = 'block';

            wireEvents();

            const wSt = document.getElementById('weapon-chart-status');
            if (weaponRes.status === 'fulfilled') {
                bootstrapWeaponMix(weaponRes.value);
            } else if (wSt) {
                wSt.textContent =
                    'Weapon chart unavailable: ' +
                    (weaponRes.reason && weaponRes.reason.message
                        ? weaponRes.reason.message
                        : weaponRes.reason);
                wSt.classList.add('drops-status-err');
            }

            applyFilters();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
