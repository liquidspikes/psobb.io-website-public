/**
 * Item drops: unified searchable table — rare + common (enemy / box).
 */

(function () {
    'use strict';

    const UINT32 = 4294967296;
    const PAGE_SIZE = 125;
    const DATA_URL_RARE_API = '/api/get_drops.php';
    const DATA_URL_RARE_FALLBACK = '/api/data/rare-drops-v4.json';
    const DATA_URL_COMMON_ENEMIES = '/api/data/common-enemies-v4.json';
    const DATA_URL_COMMON_BOXES = '/api/data/common-boxes-v4.json';
    const DATA_URL_DISK_CATALOG = '/api/data/technique-disk-catalog-v4.json';
    const DATA_URL_WEAPON_CHART = '/api/data/weapon-type-by-section-v4.json';
    const DATA_URL_ENEMY_SPAWNS = '/api/data/enemy-spawns-by-area-v4.json';

    const DIFF_ORDER = ['Normal', 'Hard', 'VeryHard', 'Ultimate'];
    const EP_ORDER = ['Episode1', 'Episode2', 'Episode4'];

    const EP_TOKEN_MAP = { Ep1: 'Episode1', Ep2: 'Episode2', Ep4: 'Episode4' };
    const EP_NUM_MAP = { 1: 'Episode1', 2: 'Episode2', 4: 'Episode4' };

    /** @type {boolean} */
    let rareFromLiveApi = false;

    /** @type {object[]} */
    let poolRare = [];
    /** @type {object[]} */
    let poolMonster = [];
    /** @type {object[]} */
    let poolBox = [];
    /** @type {object[]} */
    let poolDisks = [];

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

    /** @type {Record<string, { label: string; codes: string[] }[]>} */
    let areaGroupsByEpisode = {};
    /** @type {Record<string, Set<string>>} episode|areaGroup -> enemy source ids */
    let spawnEnemiesByEpAreaGroup = {};

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

    function i18n(key, fallback) {
        const bag = typeof window !== 'undefined' && window.__DROPS_I18N ? window.__DROPS_I18N : {};
        const v = bag[key];
        return v != null && v !== '' ? v : fallback;
    }

    function fullPool() {
        const out = [];
        out.push(...poolRare, ...poolMonster, ...poolBox, ...poolDisks);
        return out;
    }

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
    function fmtRareRawProbability(p) {
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

    /** @param {object} row */
    function fmtProbability(row) {
        if (row.row_kind === 'common_enemy' && row.dar_percent != null && Number.isFinite(row.dar_percent)) {
            return `${row.dar_percent}% DAR`;
        }
        if (row.row_kind === 'common_box' || row.row_kind === 'disk_catalog') {
            return '—';
        }
        return fmtRareRawProbability(row.probability);
    }

    /** @param {object} row */
    function fmtPercent(row) {
        if (row.row_kind === 'common_enemy') {
            if (typeof row.approx_percent === 'number' && Number.isFinite(row.approx_percent)) {
                return String(row.approx_percent);
            }
            return '—';
        }
        if (row.row_kind === 'common_box' || row.row_kind === 'disk_catalog') {
            return '—';
        }
        if (typeof row.approx_percent === 'number' && Number.isFinite(row.approx_percent)) {
            if (row.approx_percent < 0.0001 && row.approx_percent > 0) {
                return row.approx_percent.toExponential(3);
            }
            return row.approx_percent.toFixed(5).replace(/\.?0+$/, '');
        }
        return '—';
    }

    function typeLabel(row) {
        if (row.row_kind === 'common_enemy') {
            return i18n('typeMonster', 'Monster (common)');
        }
        if (row.row_kind === 'common_box') {
            return i18n('typeBox', 'Box (common)');
        }
        if (row.row_kind === 'disk_catalog') {
            return i18n('typeDisk', 'Disk');
        }
        return i18n('typeRare', 'Rare');
    }

    function typeSortKey(row) {
        if (row.row_kind === 'common_enemy') return 1;
        if (row.row_kind === 'common_box') return 2;
        if (row.row_kind === 'disk_catalog') return 3;
        return 0;
    }

    function norm(s) {
        return (s || '').toLowerCase().trim();
    }

    /** @param {object} row */
    function formatSource(row) {
        const id = String(row.source || '').trim();
        const disp = String(row.source_display || '').trim();
        if (disp && disp !== id) {
            return disp;
        }
        return id || '—';
    }

    function normalizeDifficulty(d) {
        const s = String(d || '').trim();
        if (s === 'Very Hard') return 'VeryHard';
        return s;
    }

    /** @param {number|string} ep */
    function episodeFromApi(ep) {
        const n = typeof ep === 'number' ? ep : parseInt(String(ep), 10);
        return EP_NUM_MAP[n] || (Number.isFinite(n) && n > 0 ? `Episode${n}` : '');
    }

    /**
     * @param {object} raw
     * @returns {object|null}
     */
    function normalizeApiRareRow(raw) {
        const ep = episodeFromApi(raw.episode);
        if (!ep) return null;
        const difficulty = normalizeDifficulty(raw.difficulty);
        const section_id = raw.section_id != null ? String(raw.section_id) : '';
        const source =
            raw.monster_id != null && String(raw.monster_id) !== ''
                ? String(raw.monster_id)
                : String(raw.monster || '');
        const source_display = raw.monster != null ? String(raw.monster) : source;
        let probability = raw.rate != null ? String(raw.rate) : '';
        if (!probability && raw.rate_percent != null) {
            probability = String(raw.rate_percent);
        }
        const approx_percent =
            typeof raw.rate_percent === 'number' && Number.isFinite(raw.rate_percent)
                ? raw.rate_percent
                : null;
        const item_hex =
            raw.item_hex != null && String(raw.item_hex) !== '' ? String(raw.item_hex).toUpperCase() : '—';
        return {
            row_kind: 'rare',
            episode: ep,
            difficulty,
            section_id,
            source,
            source_display,
            probability,
            approx_percent,
            item_name: raw.item != null ? String(raw.item) : '—',
            item_hex,
            item_type: raw.type != null ? String(raw.type) : '',
            item_subtype: raw.subtype != null ? String(raw.subtype) : ''
        };
    }

    async function loadRareDrops() {
        try {
            const r = await fetch(DATA_URL_RARE_API);
            if (!r.ok) throw new Error('api ' + String(r.status));
            const body = await r.json();
            if (!body || body.success !== true || !Array.isArray(body.data)) {
                throw new Error('invalid api payload');
            }
            rareFromLiveApi = !body.mock;
            return body.data.map((row) => normalizeApiRareRow(row)).filter(Boolean);
        } catch (apiErr) {
            rareFromLiveApi = false;
            const r = await fetch(DATA_URL_RARE_FALLBACK);
            if (!r.ok) throw apiErr;
            const rows = await r.json();
            if (!Array.isArray(rows)) throw apiErr;
            return rows.map((row) => ({ ...row, row_kind: 'rare' }));
        }
    }

    function hasActiveQuery() {
        return !!(
            document.getElementById('drops-episode').value ||
            document.getElementById('drops-area').value ||
            document.getElementById('drops-difficulty').value ||
            document.getElementById('drops-section').value ||
            norm(document.getElementById('drops-source-q').value) ||
            norm(document.getElementById('drops-item-q').value)
        );
    }

    /** Placeholder labels from common-table area index 10 (not a real dungeon name). */
    function isUsableAreaToken(a) {
        const s = String(a).trim();
        if (!s) return false;
        if (/^\(\d+\)$/.test(s)) return false;
        return true;
    }

    function rebuildAreaGroups() {
        areaGroupsByEpisode = {};
        EP_ORDER.forEach((ep) => {
            const areas = new Set();
            fullPool().forEach((row) => {
                if (row.episode !== ep) return;
                if (row.area_label && isUsableAreaToken(row.area_label)) {
                    areas.add(String(row.area_label));
                }
                const src = String(row.source || '');
                if (src.startsWith('Box-')) {
                    const part = src.slice(4);
                    if (isUsableAreaToken(part)) areas.add(part);
                }
            });
            const groups = new Map();
            [...areas].sort().forEach((a) => {
                const m = a.match(/^([A-Za-z]+)/);
                const key = m ? m[1] : a;
                if (!groups.has(key)) groups.set(key, []);
                const arr = groups.get(key);
                if (!arr.includes(a)) arr.push(a);
            });
            areaGroupsByEpisode[ep] = [...groups.entries()]
                .sort((a, b) => a[0].localeCompare(b[0]))
                .map(([label, codes]) => ({ label, codes }));
        });
    }

    function fillAreaSelect(episode) {
        const sel = document.getElementById('drops-area');
        if (!sel) return;
        const cur = sel.value;
        sel.querySelectorAll('option:not([value=""])').forEach((o) => o.remove());
        sel.disabled = !episode;
        if (!episode) {
            sel.value = '';
            return;
        }
        const groups = areaGroupsByEpisode[episode] || [];
        groups.forEach((g) => {
            const o = document.createElement('option');
            o.value = g.label;
            o.textContent = g.label;
            sel.appendChild(o);
        });
        sel.value = groups.some((g) => g.label === cur) ? cur : '';
    }

    function isEnemyDropRow(row) {
        if (row.row_kind === 'common_enemy') return true;
        if (row.row_kind === 'rare') {
            const src = String(row.source || '');
            return src && !src.startsWith('Box-');
        }
        return false;
    }

    function buildSpawnEnemiesIndex() {
        spawnEnemiesByEpAreaGroup = {};
        EP_ORDER.forEach((ep) => {
            const groups = areaGroupsByEpisode[ep] || [];
            groups.forEach((g) => {
                spawnEnemiesByEpAreaGroup[`${ep}|${g.label}`] = new Set();
            });
        });
    }

    /**
     * @param {{ groups?: { episode_token: string; area_group: string; enemies: string[] }[] }} data
     */
    function applySpawnData(data) {
        if (!data || !Array.isArray(data.groups)) return;
        buildSpawnEnemiesIndex();
        data.groups.forEach((entry) => {
            const ep = EP_TOKEN_MAP[entry.episode_token] || entry.episode_token;
            const key = `${ep}|${entry.area_group}`;
            if (!spawnEnemiesByEpAreaGroup[key]) {
                spawnEnemiesByEpAreaGroup[key] = new Set();
            }
            (entry.enemies || []).forEach((en) => spawnEnemiesByEpAreaGroup[key].add(String(en)));
        });
    }

    /** @param {object} row */
    function rowMatchesAreaGroup(row, episode, areaGroup) {
        if (!areaGroup || !episode) return true;
        if (row.episode !== episode) return false;
        const groups = areaGroupsByEpisode[episode] || [];
        const g = groups.find((x) => x.label === areaGroup);
        if (!g) return true;

        if (isEnemyDropRow(row)) {
            const allowed = spawnEnemiesByEpAreaGroup[`${episode}|${areaGroup}`];
            if (!allowed || !allowed.size) return false;
            return allowed.has(String(row.source || ''));
        }

        const tokens = [];
        if (row.area_label) tokens.push(String(row.area_label));
        const src = String(row.source || '');
        if (src.startsWith('Box-')) tokens.push(src.slice(4));
        if (!tokens.length) return false;
        return tokens.some((t) => g.codes.includes(t));
    }

    function defaultCompareRare(a, b) {
        let c = typeSortKey(a) - typeSortKey(b);
        if (c !== 0) return c;
        c = episodeRank(a.episode) - episodeRank(b.episode);
        if (c !== 0) return c;
        c = difficultyRank(a.difficulty) - difficultyRank(b.difficulty);
        if (c !== 0) return c;
        c = String(a.section_id || '').localeCompare(String(b.section_id || ''));
        if (c !== 0) return c;
        c = String(a.source || '').localeCompare(String(b.source || ''));
        if (c !== 0) return c;
        return String(a.item_hex || '').localeCompare(String(b.item_hex || ''));
    }

    /** @param {object} row */
    function probabilityNumeric(row) {
        if (row.row_kind === 'common_enemy' && row.dar_percent != null && Number.isFinite(row.dar_percent)) {
            return row.dar_percent / 100;
        }
        if (row.row_kind === 'common_box' || row.row_kind === 'disk_catalog') {
            return null;
        }
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

    /**
     * @param {object} raw
     * @returns {object|null}
     */
    function normalizeCommonEnemyRow(raw) {
        const parts = String(raw.scenario || '').split(':');
        if (parts.length < 4 || parts[1] !== 'Normal') {
            return null;
        }
        const epTok = parts[0];
        const ep = EP_TOKEN_MAP[epTok] || epTok;
        const difficulty = parts[2];
        const section_id = parts[3];
        const dar = raw.dar_percent;
        const meseta = raw.meseta_display || '';
        const ic = raw.item_class || '—';
        let item_name = ic;
        if (meseta) {
            item_name = `${ic} · ${meseta}`;
        }
        const approx = typeof dar === 'number' && Number.isFinite(dar) ? dar : null;
        return {
            row_kind: 'common_enemy',
            episode: ep,
            difficulty,
            section_id,
            source: raw.enemy,
            probability: dar,
            dar_percent: typeof dar === 'number' && Number.isFinite(dar) ? dar : null,
            approx_percent: approx,
            item_name,
            item_hex: '—'
        };
    }

    /**
     * @param {object} raw
     * @returns {object|null}
     */
    function normalizeCommonBoxRow(raw) {
        const parts = String(raw.scenario || '').split(':');
        if (parts.length < 4 || parts[1] !== 'Normal') {
            return null;
        }
        const epTok = parts[0];
        const ep = EP_TOKEN_MAP[epTok] || epTok;
        const difficulty = parts[2];
        const section_id = parts[3];
        const area = raw.area_label != null ? String(raw.area_label) : String(raw.area_index);
        const source = `Box-${area}`;
        const ml = raw.meseta_low;
        const mh = raw.meseta_high;
        let mesetaPart = '';
        if (ml != null && mh != null) {
            mesetaPart = ` · ${ml}–${mh} meseta`;
        }
        const item_name = (raw.summary_short || '—') + mesetaPart;
        return {
            row_kind: 'common_box',
            episode: ep,
            difficulty,
            section_id,
            source,
            probability: null,
            dar_percent: null,
            approx_percent: null,
            item_name,
            item_hex: '—',
            area_label: area,
            summary_short: raw.summary_short || ''
        };
    }

    /**
     * @param {object} raw
     * @returns {object|null}
     */
    function normalizeDiskCatalogRow(raw) {
        const hx = raw && raw.item_hex != null ? String(raw.item_hex).trim().toUpperCase() : '';
        const nm = raw && raw.item_name != null ? String(raw.item_name).trim() : '';
        if (!hx || !nm) return null;
        return {
            row_kind: 'disk_catalog',
            episode: '',
            difficulty: '',
            section_id: '',
            source: '',
            probability: null,
            dar_percent: null,
            approx_percent: null,
            item_name: nm,
            item_hex: hx,
            summary_short: ''
        };
    }

    function compareRarePrimary(a, b, key) {
        switch (key) {
            case 'row_kind':
                return typeSortKey(a) - typeSortKey(b);
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
                    return String(fmtProbability(a)).localeCompare(String(fmtProbability(b)));
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
        document.querySelectorAll('#drops-rare-block thead .sortable-th').forEach((th) => {
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

    function itemSearchMatches(row, iq) {
        const tl = norm(typeLabel(row));
        const name = norm(row.item_name);
        const hx = norm(row.item_hex);
        const prob = row.probability != null ? norm(String(row.probability)) : '';
        const summ = row.summary_short ? norm(row.summary_short) : '';
        const cls = row.row_kind === 'common_enemy' && row.item_name ? norm(row.item_name.split('·')[0]) : '';
        if (
            tl.includes(iq) ||
            name.includes(iq) ||
            hx.includes(iq.replace(/^0x/i, '')) ||
            prob.includes(iq) ||
            summ.includes(iq) ||
            (cls && cls.includes(iq))
        ) {
            return true;
        }
        return false;
    }

    function applyFilters() {
        if (!hasActiveQuery()) {
            filtered = [];
        } else {
            const ep = document.getElementById('drops-episode').value;
            const area = document.getElementById('drops-area').value;
            const diff = document.getElementById('drops-difficulty').value;
            const sec = document.getElementById('drops-section').value;
            const sq = norm(document.getElementById('drops-source-q').value);
            const iq = norm(document.getElementById('drops-item-q').value);

            const pool = fullPool();
            filtered = pool.filter((row) => {
                // Disk catalog rows are a reference list, not tied to episode/difficulty/section/source.
                // Only include them when the user is actively searching by item.
                if (row.row_kind === 'disk_catalog') {
                    if (!iq) return false;
                    return itemSearchMatches(row, iq);
                }
                if (ep && row.episode !== ep) return false;
                if (area && ep && !rowMatchesAreaGroup(row, ep, area)) return false;
                if (diff && row.difficulty !== diff) return false;
                if (sec && row.section_id !== sec) return false;
                if (sq) {
                    const src = norm(row.source);
                    const disp = norm(row.source_display || '');
                    if (!src.includes(sq) && !disp.includes(sq)) return false;
                }
                if (iq && !itemSearchMatches(row, iq)) {
                    return false;
                }
                return true;
            });
        }

        sortFilteredRare();

        page = 0;
        updateResultsLayout();
        updateSummary();
        updateWeaponHighlight();
    }

    function updateResultsLayout() {
        const sec = document.getElementById('drops-section') ? document.getElementById('drops-section').value : '';
        const tableWrap = document.getElementById('drops-table-wrap');
        const pager = document.getElementById('drops-pager');
        const matrixWrap = document.getElementById('drops-matrix-wrap');
        const secWrap = document.getElementById('drops-sec-wrap');

        // One results view: sortable table (matrix hidden — no column sort there).
        if (matrixWrap) matrixWrap.style.display = 'none';

        if (sec) {
            if (tableWrap) tableWrap.style.display = 'none';
            if (pager) pager.style.display = 'none';
            if (secWrap) secWrap.style.display = hasActiveQuery() ? 'block' : 'none';
            renderSectionTable();
            updateRareSortHeaders();
            return;
        }

        if (secWrap) secWrap.style.display = 'none';
        if (tableWrap) tableWrap.style.display = hasActiveQuery() ? 'block' : 'none';
        renderPage();
        updateRareSortHeaders();
    }

    function matrixCellContent(row) {
        if (row.row_kind === 'rare') {
            const p = fmtProbability(row);
            const nm = row.item_name || '—';
            return `${nm} (${p})`;
        }
        const t = row.row_kind === 'common_box' || row.row_kind === 'common_enemy' ? 'Common' : typeLabel(row);
        const nm = row.item_name || row.summary_short || '—';
        return `${t}: ${nm}`;
    }

    function renderMatrix() {
        const wrap = document.getElementById('drops-matrix-wrap');
        const thead = document.getElementById('drops-matrix-thead');
        const tbody = document.getElementById('drops-matrix-tbody');
        if (!wrap || !thead || !tbody) return;

        // When a specific section is selected, use the section-focused table instead of the wide matrix.
        const sec = document.getElementById('drops-section') ? document.getElementById('drops-section').value : '';
        if (sec) {
            wrap.style.display = 'none';
            thead.textContent = '';
            tbody.textContent = '';
            return;
        }

        if (!hasActiveQuery()) {
            wrap.style.display = 'none';
            thead.textContent = '';
            tbody.textContent = '';
            return;
        }

        wrap.style.display = 'block';

        /** @type {Map<string, string>} */
        const pairToParts = new Map();
        filtered.forEach((row) => {
            const src = row.source || '—';
            const sid = row.section_id || '';
            const k = `${src}\x00${sid}`;
            const part = matrixCellContent(row);
            const prev = pairToParts.get(k);
            if (!prev) {
                pairToParts.set(k, part);
            } else if (!prev.includes(part)) {
                pairToParts.set(k, `${prev}; ${part}`);
            }
        });

        const sources = [...new Set(filtered.map((r) => r.source || '—'))].sort((a, b) =>
            String(a).localeCompare(String(b))
        );

        thead.innerHTML = `<tr>
            <th scope="col" class="drops-matrix-sticky">${escapeHtml(i18n('matrixEnemyOrBox', 'Enemy / box'))}</th>
            ${SECTION_ID_ORDER.map(
                (sid) => `<th scope="col" class="drops-matrix-sec">${escapeHtml(sid)}</th>`
            ).join('')}
        </tr>`;

        tbody.textContent = '';
        if (!sources.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = 1 + SECTION_ID_ORDER.length;
            td.className = 'drops-empty-msg';
            td.textContent = i18n('noMatch', 'No matching rows.');
            tr.appendChild(td);
            tbody.appendChild(tr);
            return;
        }

        const frag = document.createDocumentFragment();
        sources.forEach((src) => {
            const tr = document.createElement('tr');
            const th = document.createElement('th');
            th.scope = 'row';
            th.className = 'drops-matrix-sticky drops-mono';
            th.textContent = src;
            tr.appendChild(th);
            SECTION_ID_ORDER.forEach((sid) => {
                const td = document.createElement('td');
                td.className = 'drops-matrix-cell';
                const txt = pairToParts.get(`${src}\x00${sid}`) || '';
                td.textContent = txt || '—';
                tr.appendChild(td);
            });
            frag.appendChild(tr);
        });
        tbody.appendChild(frag);
    }

    function renderSectionTable() {
        const wrap = document.getElementById('drops-sec-wrap');
        const tbody = document.getElementById('drops-sec-tbody');
        if (!wrap || !tbody) return;

        const secSel = document.getElementById('drops-section');
        const sec = secSel ? secSel.value : '';
        if (!hasActiveQuery() || !sec) {
            wrap.style.display = 'none';
            tbody.textContent = '';
            return;
        }

        wrap.style.display = 'block';
        tbody.textContent = '';

        const noMatchMsg = i18n('noMatch', 'No matching rows.');
        if (!filtered.length) {
            tbody.innerHTML = `<tr><td colspan="8" class="drops-empty-msg">${escapeHtml(noMatchMsg)}</td></tr>`;
            return;
        }

        const frag = document.createDocumentFragment();
        filtered.forEach((row) => {
            const tr = document.createElement('tr');
            const name = row.item_name || '—';
            tr.innerHTML = [
                `<td>${escapeHtml(row.episode)}</td>`,
                `<td>${escapeHtml(row.difficulty)}</td>`,
                `<td class="drops-mono">${escapeHtml(formatSource(row))}</td>`,
                `<td>${escapeHtml(typeLabel(row))}</td>`,
                `<td class="drops-prob">${escapeHtml(fmtProbability(row))}</td>`,
                `<td class="drops-pct">${escapeHtml(fmtPercent(row))}</td>`,
                `<td>${escapeHtml(name)}</td>`,
                `<td class="drops-mono">${escapeHtml(row.item_hex)}</td>`
            ].join('');
            frag.appendChild(tr);
        });
        tbody.appendChild(frag);
    }

    function renderPage() {
        const tbody = document.getElementById('drops-tbody');
        const pager = document.getElementById('drops-pager');
        const wrap = document.getElementById('drops-table-wrap');
        if (!tbody || !pager || !wrap) return;

        tbody.textContent = '';

        const emptyMsg = i18n('emptyFilter', 'Enter a search parameter to populate the table.');
        const noMatchMsg = i18n('noMatch', 'No matching rows.');

        if (!hasActiveQuery()) {
            pager.style.display = 'none';
            wrap.style.display = 'block';
            tbody.innerHTML = `<tr><td colspan="9" class="drops-empty-msg">${escapeHtml(emptyMsg)}</td></tr>`;
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
                `<td>${escapeHtml(typeLabel(row))}</td>`,
                `<td>${escapeHtml(row.episode)}</td>`,
                `<td>${escapeHtml(row.difficulty)}</td>`,
                `<td>${escapeHtml(row.section_id)}</td>`,
                `<td class="drops-mono">${escapeHtml(formatSource(row))}</td>`,
                `<td class="drops-prob">${escapeHtml(fmtProbability(row))}</td>`,
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
            tbody.innerHTML = `<tr><td colspan="9" class="drops-empty-msg">${escapeHtml(noMatchMsg)}</td></tr>`;
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
        if (!el) return;
        if (!hasActiveQuery()) {
            el.textContent = '—';
            return;
        }
        const n = filtered.length;
        const y = fullPool().length;
        el.textContent = i18n('summaryTpl', '{{n}} row(s) (of {{total}} total lines)')
            .replace('{{n}}', n.toLocaleString())
            .replace('{{total}}', y.toLocaleString());
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

    function unionFilterOptions() {
        const eps = new Set();
        const diffs = new Set();
        const secs = new Set();
        [poolRare, poolMonster, poolBox].forEach((pool) => {
            pool.forEach((row) => {
                if (row.episode && row.episode !== '—') eps.add(row.episode);
                if (row.difficulty && row.difficulty !== '—') diffs.add(row.difficulty);
                if (row.section_id && row.section_id !== '—') secs.add(row.section_id);
            });
        });
        fillSelect(document.getElementById('drops-episode'), eps, (a, b) =>
            episodeRank(a) - episodeRank(b));
        fillSelect(document.getElementById('drops-difficulty'), diffs, (a, b) =>
            difficultyRank(a) - difficultyRank(b));
        fillSelect(document.getElementById('drops-section'), secs, (a, b) =>
            sectionIdSortKey(String(a)) - sectionIdSortKey(String(b)) ||
            String(a).localeCompare(String(b)));
    }

    function wireEvents() {
        document.getElementById('drops-episode').addEventListener('change', () => {
            fillAreaSelect(document.getElementById('drops-episode').value);
            applyFilters();
        });
        ['drops-area', 'drops-difficulty', 'drops-section'].forEach((id) => {
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
            fillAreaSelect('');
            document.getElementById('drops-difficulty').value = '';
            document.getElementById('drops-section').value = '';
            document.getElementById('drops-source-q').value = '';
            document.getElementById('drops-item-q').value = '';
            sortState = { key: '_default', dir: 1 };
            applyFilters();
        });

        function onSortHeaderActivate(ev) {
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
            updateResultsLayout();
            updateSummary();
        }

        document.querySelectorAll('#drops-table-wrap thead, #drops-sec-wrap thead').forEach((thead) => {
            thead.addEventListener('click', onSortHeaderActivate);
            thead.addEventListener('keydown', (ev) => {
                if (ev.key !== 'Enter' && ev.key !== ' ') return;
                const th = ev.target.closest('th[data-sort]');
                if (!th) return;
                ev.preventDefault();
                th.click();
            });
        });

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
            loadRareDrops(),
            fetch(DATA_URL_COMMON_ENEMIES).then((r) => {
                if (!r.ok) throw new Error('common enemies ' + String(r.status));
                return r.json();
            }),
            fetch(DATA_URL_COMMON_BOXES).then((r) => {
                if (!r.ok) throw new Error('common boxes ' + String(r.status));
                return r.json();
            }),
            fetch(DATA_URL_DISK_CATALOG).then((r) => {
                if (!r.ok) throw new Error('disk catalog ' + String(r.status));
                return r.json();
            }),
            fetch(DATA_URL_WEAPON_CHART).then((r) => {
                if (!r.ok) throw new Error('weapon chart ' + String(r.status));
                return r.json();
            }),
            fetch(DATA_URL_ENEMY_SPAWNS).then((r) => {
                if (!r.ok) throw new Error('enemy spawns ' + String(r.status));
                return r.json();
            })
        ]).then((results) => {
            const rareRes = results[0];
            const comEnRes = results[1];
            const comBoxRes = results[2];
            const diskRes = results[3];
            const weaponRes = results[4];
            const spawnRes = results[5];

            if (rareRes.status !== 'fulfilled' || !Array.isArray(rareRes.value) || !rareRes.value.length) {
                if (status) {
                    status.textContent =
                        'Could not load rare data: ' +
                        (rareRes.status === 'rejected' ? rareRes.reason.message : 'no rows');
                    status.classList.add('drops-status-err');
                }
                return;
            }

            poolRare = rareRes.value;

            poolMonster = [];
            if (comEnRes.status === 'fulfilled' && comEnRes.value && Array.isArray(comEnRes.value.enemies)) {
                comEnRes.value.enemies.forEach((raw) => {
                    const n = normalizeCommonEnemyRow(raw);
                    if (n) poolMonster.push(n);
                });
            }

            poolBox = [];
            if (comBoxRes.status === 'fulfilled' && comBoxRes.value && Array.isArray(comBoxRes.value.boxes)) {
                comBoxRes.value.boxes.forEach((raw) => {
                    const n = normalizeCommonBoxRow(raw);
                    if (n) poolBox.push(n);
                });
            }

            poolDisks = [];
            if (diskRes.status === 'fulfilled' && diskRes.value && Array.isArray(diskRes.value.items)) {
                diskRes.value.items.forEach((raw) => {
                    const n = normalizeDiskCatalogRow(raw);
                    if (n) poolDisks.push(n);
                });
            }

            unionFilterOptions();
            rebuildAreaGroups();
            applySpawnData(spawnRes.status === 'fulfilled' ? spawnRes.value : null);
            fillAreaSelect(document.getElementById('drops-episode').value);

            status.style.display = 'none';
            filters.style.display = 'block';
            wrap.style.display = 'block';

            wireEvents();

            const loadWarn = [];
            const rareNote = document.getElementById('drops-rare-source-note');
            if (rareNote) {
                rareNote.textContent = rareFromLiveApi
                    ? i18n('rareLive', 'Rare drops loaded from live server data.')
                    : i18n('rareFallback', 'Rare drops loaded from cached/fallback data (API unavailable).');
                rareNote.style.display = 'block';
            }
            if (!rareFromLiveApi) {
                loadWarn.push('rare (cached/fallback)');
            }
            if (comEnRes.status !== 'fulfilled') {
                loadWarn.push('monster common');
            }
            if (comBoxRes.status !== 'fulfilled') {
                loadWarn.push('box common');
            }
            if (diskRes.status !== 'fulfilled') {
                loadWarn.push('disk catalog');
            }
            if (spawnRes.status !== 'fulfilled') {
                loadWarn.push('area spawns');
            }
            if (loadWarn.length) {
                const w = document.getElementById('drops-load-warn');
                if (w) {
                    w.textContent =
                        i18n('partialLoad', 'Partial load: ') +
                        loadWarn.join(', ') +
                        i18n('partialLoadSuffix', ' data unavailable.');
                    w.style.display = 'block';
                }
            }

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
