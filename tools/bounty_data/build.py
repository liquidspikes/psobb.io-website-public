#!/usr/bin/env python3
"""
Builds the item data the Bounty System Rework uses to pick rewards, from a newserv checkout.

    python3 tools/bounty_data/build.py --newserv /path/to/newserv [--out api/data]

Reads newserv's Blue Burst tables and quests (no server needs to run):
  system/tables/rare-table-v4.json           rare drops: enemy/box, section ID, difficulty, rate
  system/tables/common-table-v3-v4.json      each enemy's chance to drop anything (DAR), common tool classes
  system/tables/item-parameter-table-bb-v4.json   stars (rarity), class usability, equip requirements, combinations
  system/tables/level-table-v4.json          class stats per level (to turn weapon requirements into a level)
  system/tables/names-v4.json                item names
  system/config.example.json                 rare-enemy spawn rates; Coren / Black Paper's Deal / Gallon's Plan prizes
  system/quests/**/*-bb-e.bin                each quest's list of items it may create (BB quest header),
                                             battle rule sets and Challenge Mode rank times / prizes (scripts)

Writes:
  bounty_items.json        one entry per item: rarity data, how else it is obtained, equip level per class
  battle_rule_sets.json    the eight BB battle rule sets, decoded from their scripts
  challenge_mode.json      Challenge Mode stages, rank times and prizes, decoded from their scripts
  bounty_levels.json       EXP needed for each level, and the EXP an average enemy gives per difficulty
                           (from level-table-v4.json and battle-params.json), so level objectives stay realistic

Drop rate model (matches newserv's ItemCreator): an enemy first rolls its drop-anything chance (DAR), then its rare
specs; a box rolls its rare specs directly. Rare enemy forms are scaled by their spawn chance (1/500, Kondrieu 1/10,
Mericus/Merikle 1/5). Rates are per kill or per box, for Normal (multiplayer) games; seasonal Rappies are recorded
but not counted as normal drops.
"""
import argparse, collections, glob, json, os, re, struct, sys
from fractions import Fraction

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from prs import prs_decompress

SECTIONS = ['Viridia', 'Greennill', 'Skyly', 'Bluefull', 'Purplenum', 'Pinkal', 'Redria', 'Oran', 'Yellowboze', 'Whitill']
DIFFS = ['Normal', 'Hard', 'VeryHard', 'Ultimate']
CLASSES = ['HUmar', 'HUnewearl', 'HUcast', 'RAmar', 'RAcast', 'RAcaseal', 'FOmarl', 'FOnewm', 'FOnewearl', 'HUcaseal', 'FOmar', 'RAmarl']
# item usability bits: 01 hunter 02 ranger 04 force 08 human 10 android 20 newman 40 male 80 female
CLASS_BITS = {'HUmar': 0x49, 'HUnewearl': 0xA1, 'HUcast': 0x51, 'RAmar': 0x4A, 'RAcast': 0x52, 'RAcaseal': 0x92,
              'FOmarl': 0x8C, 'FOnewm': 0x64, 'FOnewearl': 0xA4, 'HUcaseal': 0x91, 'FOmar': 0x4C, 'RAmarl': 0x8A}
RARE_ENEMY_SPAWN = {
    'HILDEBLUE': 1 / 500, 'AL_RAPPY': 1 / 500, 'LOVE_RAPPY': 1 / 500, 'EGG_RAPPY': 1 / 500, 'HALLO_RAPPY': 1 / 500,
    'SAINT_RAPPY': 1 / 500, 'DEL_RAPPY_CRATER': 1 / 500, 'DEL_RAPPY_DESERT': 1 / 500, 'NAR_LILY': 1 / 500,
    'POUILLY_SLIME': 1 / 500, 'MERISSA_AA': 1 / 500, 'PAZUZU_CRATER': 1 / 500, 'PAZUZU_DESERT': 1 / 500,
    'DORPHON_ECLAIR': 1 / 500, 'KONDRIEU': 1 / 10, 'MERICUS': 1 / 5, 'MERIKLE': 1 / 5,
}
SEASONAL_ENEMIES = {'LOVE_RAPPY', 'EGG_RAPPY', 'HALLO_RAPPY', 'SAINT_RAPPY'}
FINAL_BOSSES = {'DARK_FALZ_2', 'DARK_FALZ_3', 'OLGA_FLOW_2'}   # always drop; not in the DAR tables
# Challenge Mode S-rank prizes are S-rank weapons, chosen and named by the player (types 70-88 Ep1, A5-A9 Ep2)
S_RANK_WEAPON_TYPES = set(range(0x70, 0x89)) | set(range(0xA5, 0xAA))
EXCHANGE_QUESTS = {   # quests whose item lists are trades, shops or lotteries rather than story rewards
    'Gallon\'s Shop': 'Photon Drop shop', 'Item Present': 'Item Ticket exchange', 'Claire\'s Deal 5': 'item trade',
    'Good Luck!': 'lottery', 'Black Paper\'s Deal': 'trade', 'Rappy\'s Holiday': 'item trade',
    'To The Deepest Blue -MA4 Venue-': 'event prize exchange', 'Beach Laughter': 'event prize exchange',
    'AOL CUP -Maximum Attack-': 'event prize', 'AOL CUP -Sunset Base-': 'event prize',
}


def load_jsonc(path):
    """newserv's JSON with // comments, hex literals and trailing commas."""
    t = open(path, encoding='utf-8').read()
    out, i, instr = [], 0, False
    while i < len(t):
        c = t[i]
        if instr:
            out.append(c)
            if c == '\\':
                out.append(t[i + 1]); i += 2; continue
            if c == '"':
                instr = False
            i += 1; continue
        if c == '"':
            instr = True; out.append(c); i += 1; continue
        if t.startswith('//', i):
            j = t.find('\n', i); i = len(t) if j < 0 else j; continue
        m = re.match(r'(-?)0x([0-9A-Fa-f]+)', t[i:])
        if m and (i == 0 or not t[i - 1].isalnum()):
            out.append(str(-int(m.group(2), 16)) if m.group(1) else '"0x%s"' % m.group(2).upper())
            i += len(m.group(0)); continue
        out.append(c); i += 1
    return json.loads(re.sub(r',(\s*[}\]])', r'\1', ''.join(out)))


def H(v):
    return int(v, 16) if isinstance(v, str) and v.startswith('0x') else int(v)


def prob(p):
    if isinstance(p, str) and '/' in p:
        a, b = p.split('/'); return float(Fraction(int(a), int(b)))
    return H(p) / 2 ** 32


def item_code(desc):
    h = desc[2:] if isinstance(desc, str) and desc.startswith('0x') else str(desc)
    h = h.upper()
    return h.rjust(6, '0') if len(h) <= 6 else h[:6]


def kind_of(code):
    a, b = int(code[0:2], 16), int(code[2:4], 16)
    if a == 0: return 'Weapon'
    if a == 1: return {1: 'Armor', 2: 'Shield', 3: 'Unit'}.get(b, 'Other')
    if a == 2: return 'Mag'
    if a == 3: return 'Tool'
    return 'Other'



ATTRS = ('native', 'abeast', 'machine', 'dark')


def mix(*parts):
    """Averages attribute shares, each part optionally weighted: mix(a, b) or mix((a, 2), (b, 1))."""
    tot = {k: 0.0 for k in ATTRS}
    wsum = 0.0
    for p in parts:
        shares, w = p if isinstance(p, tuple) else (p, 1.0)
        for k in ATTRS:
            tot[k] += shares[k] * w
        wsum += w
    return {k: round(tot[k] / wsum, 3) for k in ATTRS}


def build_areas(here):
    """Lore settings, newserv floors and Challenge Mode stages, each with its enemy-class shares
    (used to choose a bounty weapon's attributes) and its drop setting (used to choose items)."""
    ac = load_jsonc(os.path.join(here, 'area_classes.json'))
    ac = {k: v for k, v in ac.items() if not k.startswith('_')}
    s = {
        'ep1_forest': ac['ep1_forest'], 'ep1_caves': ac['ep1_caves'],
        'ep1_mines': ac['ep1_mines'], 'ep1_ruins': ac['ep1_ruins'],
        'ep2_vr_temple': ac['ep2_vr_temple'], 'ep2_vr_spaceship': ac['ep2_vr_spaceship'],
        'ep2_gal_da_val': mix(ac['ep2_jungle'], ac['ep2_mountain'], ac['ep2_seaside'], ac['ep2_control_tower']),
        'ep2_seabed': ac['ep2_seabed'],
        'ep4_crater': ac['ep4_crater'], 'ep4_desert': ac['ep4_desert'],
    }
    s['ep1_pioneer2'] = mix(s['ep1_forest'], s['ep1_caves'], s['ep1_mines'], s['ep1_ruins'])
    s['ep2_lab'] = mix(s['ep2_vr_temple'], s['ep2_vr_spaceship'], s['ep2_gal_da_val'], s['ep2_seabed'])
    s['ep4_pioneer2'] = mix(s['ep4_crater'], s['ep4_desert'])
    settings = {}
    for k, v in s.items():
        ep = int(k[2])
        hub = k in ('ep1_pioneer2', 'ep2_lab', 'ep4_pioneer2')
        settings[k] = {'episode': ep, 'hub': hub, 'drop_setting': None if hub else k, 'attributes': v}
    # newserv LocationFloor -> setting, per episode (boss arenas use the boss's class mix)
    floors = {
        '1': {'1': 'ep1_forest', '2': 'ep1_forest', '3': 'ep1_caves', '4': 'ep1_caves', '5': 'ep1_caves',
              '6': 'ep1_mines', '7': 'ep1_mines', '8': 'ep1_ruins', '9': 'ep1_ruins', '10': 'ep1_ruins',
              '11': 'ep1_forest', '12': 'ep1_caves', '13': 'ep1_mines', '14': 'ep1_ruins'},
        '2': {'1': 'ep2_vr_temple', '2': 'ep2_vr_temple', '3': 'ep2_vr_spaceship', '4': 'ep2_vr_spaceship',
              '5': 'ep2_gal_da_val', '6': 'ep2_gal_da_val', '7': 'ep2_gal_da_val', '8': 'ep2_gal_da_val',
              '9': 'ep2_gal_da_val', '10': 'ep2_seabed', '11': 'ep2_seabed', '12': 'ep2_gal_da_val',
              '13': 'ep2_seabed', '14': 'ep2_vr_temple', '15': 'ep2_vr_spaceship', '16': 'ep2_gal_da_val',
              '17': 'ep2_gal_da_val'},
        '4': {'1': 'ep4_crater', '2': 'ep4_crater', '3': 'ep4_crater', '4': 'ep4_crater', '5': 'ep4_crater',
              '6': 'ep4_desert', '7': 'ep4_desert', '8': 'ep4_desert', '9': 'ep4_desert'},
    }
    floor_attrs = {
        '1': {'11': ac['boss_dragon'], '12': ac['boss_de_rol_le'], '13': ac['boss_vol_opt'], '14': ac['boss_dark_falz']},
        '2': {'6': ac['ep2_jungle'], '7': ac['ep2_jungle'], '8': ac['ep2_mountain'], '9': ac['ep2_seaside'],
              '12': ac['boss_gal_gryphon'], '13': ac['boss_olga_flow'], '14': ac['boss_barba_ray'],
              '15': ac['boss_gol_dragon'], '16': ac['ep2_seaside'], '17': ac['ep2_control_tower']},
        '4': {'9': ac['boss_saint_milion']},
    }
    floor_out = {}
    for ep, m in floors.items():
        floor_out[ep] = {}
        for f, setting in m.items():
            floor_out[ep][f] = {'setting': setting, 'attributes': floor_attrs.get(ep, {}).get(f, s[setting])}
    # Challenge Mode stages (BB): areas from the stage scripts
    stages = {
        '1': [('ep1_forest', s['ep1_forest']), ('ep1_caves', s['ep1_caves']), ('ep1_caves', s['ep1_caves']),
              ('ep1_caves', mix((s['ep1_caves'], 5), (ac['boss_de_rol_le'], 1))), ('ep1_mines', s['ep1_mines']),
              ('ep1_mines', s['ep1_mines']), ('ep1_ruins', s['ep1_ruins']), ('ep1_ruins', s['ep1_ruins']),
              ('ep1_ruins', s['ep1_ruins'])],
        '2': [('ep2_vr_temple', s['ep2_vr_temple']), ('ep2_vr_spaceship', s['ep2_vr_spaceship']),
              ('ep2_gal_da_val', mix(ac['ep2_jungle'], ac['ep2_mountain'], ac['ep2_seaside'])),
              ('ep2_seabed', s['ep2_seabed']), ('ep2_gal_da_val', ac['ep2_control_tower'])],
    }
    challenge = {ep: [{'stage': i + 1, 'drop_setting': st, 'attributes': at} for i, (st, at) in enumerate(lst)]
                 for ep, lst in stages.items()}
    return {
        '_comment': 'Generated by tools/bounty_data/build.py from tools/bounty_data/area_classes.json.',
        'settings': settings,
        'floors': floor_out,
        'challenge_stages': challenge,
        'bosses': {k: v for k, v in ac.items() if k.startswith('boss_')},
    }

# Bosses are left out of the average EXP per enemy: they are a few kills a run, not the steady pace of an area.
LEVEL_BOSSES = {'DRAGON', 'DE_ROL_LE', 'VOL_OPT_2', 'DARK_FALZ_2', 'DARK_FALZ_3', 'BARBA_RAY', 'BARBA_RAY_JOINT', 'GOL_DRAGON',
                'GAL_GRYPHON', 'OLGA_FLOW_2', 'SAINT_MILION', 'SHAMBERTIN', 'KONDRIEU'}
LEVEL_DIFFS = ['Normal', 'Hard', 'Very Hard', 'Ultimate']


def build_levels(T, here):
    """EXP needed for each level (the same for every class) and the EXP an average enemy gives, per difficulty: the
    mean over each field area's enemies (bosses and rare forms left out, Online tables), then the median area."""
    lt = load_jsonc(T + 'level-table-v4.json')
    exp_for_level = [int(row['EXP']) for row in lt['LevelDeltas'][0]]
    bp = load_jsonc(T + 'battle-params.json')
    es = load_jsonc(os.path.join(here, 'enemy_settings.json'))
    skip = LEVEL_BOSSES | set(RARE_ENEMY_SPAWN) | SEASONAL_ENEMIES
    per_area = {}
    for ep, key in (('Ep1', 'Episode1-Online'), ('Ep2', 'Episode2-Online'), ('Ep4', 'Episode4-Online')):
        for diff in LEVEL_DIFFS:
            exp_of = {}
            for r in bp[key][diff]:
                for e in r['Stats'].get('Enemies', []):
                    exp_of.setdefault(e, r['Stats']['EXP'])
            for area, enemies in es[ep].items():
                if area.startswith('_'):
                    continue
                vals = [exp_of[n] for n in enemies if n in exp_of and n not in skip]
                if vals:
                    per_area.setdefault(area, {})[diff] = round(sum(vals) / len(vals), 1)
    per_kill = {}
    for diff in LEVEL_DIFFS:
        vals = sorted(v[diff] for v in per_area.values() if diff in v)
        m = len(vals) // 2
        per_kill[diff] = round(vals[m] if len(vals) % 2 else (vals[m - 1] + vals[m]) / 2, 1)
    return {
        '_comment': 'exp_for_level[L - 1] = total EXP to reach level L. exp_per_kill = EXP from an average enemy on each '
                    'difficulty (median of the field areas). Used to offer level objectives only when they are realistic.',
        'exp_for_level': exp_for_level, 'exp_per_kill': per_kill, 'exp_per_kill_by_area': per_area,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--newserv', required=True)
    ap.add_argument('--out', default=os.path.join(os.path.dirname(__file__), '..', '..', 'api', 'data'))
    a = ap.parse_args()
    T = os.path.join(a.newserv, 'system', 'tables') + '/'
    Q = os.path.join(a.newserv, 'system', 'quests') + '/'
    names = load_jsonc(T + 'names-v4.json')
    rt = load_jsonc(T + 'rare-table-v4.json')
    ct = load_jsonc(T + 'common-table-v3-v4.json')
    ipt = load_jsonc(T + 'item-parameter-table-bb-v4.json')
    lt = load_jsonc(T + 'level-table-v4.json')
    cfg = load_jsonc(os.path.join(a.newserv, 'system', 'config.example.json'))

    # --- Drop-anything rates, with newserv's table inheritance (CommonItemSet::get_prev_table)
    cache = {}
    def dar(ep, diff, sec, mode='Normal'):
        key = (ep, mode, diff, sec)
        if key not in cache:
            own = ct.get(f'{ep}:{mode}:{diff}:{SECTIONS[sec]}', {}).get('EnemyTypeDropProbs')
            if own is not None: cache[key] = own
            elif sec: cache[key] = dar(ep, diff, sec - 1, mode)
            elif diff != 'Normal': cache[key] = dar(ep, DIFFS[DIFFS.index(diff) - 1], 0, mode)
            elif mode != 'Normal': cache[key] = dar(ep, 'Normal', 0, 'Normal')
            else: cache[key] = {}
        return cache[key]

    # --- Rare table
    sources = collections.defaultdict(list)
    for mode, eps in rt.items():
        for ep_name, diffs in eps.items():
            ep = {'Episode1': 'Ep1', 'Episode2': 'Ep2', 'Episode4': 'Ep4'}[ep_name]
            for diff, secs in diffs.items():
                for sec_name, wh in secs.items():
                    sec = SECTIONS.index(sec_name)
                    for where, specs in wh.items():
                        # newserv requests Dark Falz's drop as phase 2 on Normal and phase 3 on other difficulties
                        if (where == 'DARK_FALZ_2' and diff != 'Normal') or (where == 'DARK_FALZ_3' and diff == 'Normal'):
                            continue
                        for p, d in specs:
                            pr = prob(p)
                            if where.startswith('Box-'):
                                kill_dar, spawn = 1.0, 1.0
                            else:
                                kill_dar = 1.0 if where in FINAL_BOSSES else dar(ep, diff, sec).get(where, 0) / 100
                                spawn = RARE_ENEMY_SPAWN.get(where, 1.0)
                            sources[item_code(d)].append({
                                'ep': ep, 'diff': diff, 'sec': sec, 'where': where, 'rate': pr * kill_dar * spawn,
                                'seasonal': where in SEASONAL_ENEMIES})

    # --- Which bounty setting each drop source belongs to
    es = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'enemy_settings.json')))
    setting_of = {}
    for ep_key, by_setting in es.items():
        if ep_key.startswith('_'): continue
        for sid, wheres in by_setting.items():
            for w in wheres: setting_of.setdefault((ep_key, w), []).append(sid)
    unmapped = sorted({(s['ep'], s['where']) for lst in sources.values() for s in lst if (s['ep'], s['where']) not in setting_of})
    if unmapped: print('drop sources without a setting:', unmapped)

    # --- Stars, usability, equip levels
    SV = [H(x) for x in ipt['StarValues']]; SVB = H(ipt['StarValueBaseIndex'])
    def stars(code, d):
        k = int(code[:2], 16)
        if k in (0, 1):
            i = H(d['ID']) - SVB
            return SV[i] if 0 <= i < len(SV) else 0
        if k == 3: return 12 if H(d.get('ItemFlags', 0)) & 0x80 else 0
        if k == 2: return 12 if int(code[2:4], 16) >= 0x28 else 0
        return 0
    stat_by_level = {}
    for ci, cn in enumerate(CLASSES):
        base = lt['BaseStats'][ci]; cur = {s: base[s] for s in ('ATP', 'ATA', 'MST')}
        rows = [dict(cur)]
        for lvl in range(1, 200):
            dl = lt['LevelDeltas'][ci][lvl]
            for s in cur: cur[s] += dl[s]
            rows.append(dict(cur))
        stat_by_level[cn] = rows   # rows[L-1] = natural stats at level L
    def equip_levels(code, d):
        k = kind_of(code)
        if k == 'Weapon':
            req = {s: H(d.get(s + 'Required', 0)) for s in ('ATP', 'ATA', 'MST')}
            out = []
            for cn in CLASSES:
                rows = stat_by_level[cn]; lv = 201
                for L in range(1, 201):
                    # natural stats plus a typical mag/material allowance of 15%
                    if all(rows[L - 1][s] * 1.15 >= req[s] for s in req):
                        lv = L; break
                out.append(lv)
            return out
        if k in ('Armor', 'Shield'):
            return [H(d.get('RequiredLevel', 0)) + 1] * 12
        return [1] * 12

    # common tools (tool classes with any chance in the common tables)
    tool_classes = set()
    for t in ct.values():
        for row_i, row in enumerate(t.get('ToolClassProbTable', [])):
            if any(int(x) > 0 for x in row): tool_classes.add(row_i)
    tools_by_id = {}
    for code, d in ipt['Items'].items():
        if code.startswith('03'): tools_by_id.setdefault(H(d['ID']), code)
    common_tools = {tools_by_id[c] for c in tool_classes if c in tools_by_id}

    # combinations (item + item -> result)
    combos = collections.defaultdict(list)
    for c in ipt.get('ItemCombinations', []):
        res = '%06X' % H(c['ResultItem']); used = '%06X' % H(c['UsedItem']); eq = '%06X' % H(c['EquippedItem'])
        combos[res].append(f"{names.get(eq, eq)} + {names.get(used, used)}")

    # --- Quests: item lists from the BB header; battle rules and Challenge Mode data from scripts
    quest_items = collections.defaultdict(list)
    quests = []
    for path in sorted(glob.glob(Q + '*/*-bb-e.bin')):
        b = prs_decompress(open(path, 'rb').read())
        code_off = struct.unpack_from('<I', b, 0)[0]
        qname = b[0x18:0x58].decode('utf-16-le', 'ignore').split('\x00')[0]
        cat = os.path.basename(os.path.dirname(path))
        created = []
        if code_off >= 0x122C:
            for i in range(0x40):
                f = struct.unpack_from('<12i', b, 0x42C + i * 0x38)
                if not (f[0] or f[1] or f[2]): continue
                rng = [(0, 255) if v < 0 else (v, v) if v < 0x100 else (v % 1000, (v // 1000) % 1000) for v in f[:3]]
                for x0 in range(rng[0][0], rng[0][1] + 1):
                    for x1 in range(rng[1][0], rng[1][1] + 1):
                        for x2 in range(rng[2][0], rng[2][1] + 1):
                            c = '%02X%02X%02X' % (x0, x1, x2)
                            if c in names: created.append(c)
        for c in set(created):
            quest_items[c].append({'quest': qname, 'category': cat, 'exchange': EXCHANGE_QUESTS.get(qname)})
        quests.append({'file': os.path.relpath(path, Q), 'name': qname, 'category': cat, 'creates': sorted(set(created))})

    # newserv's built-in exchanges (config.example.json)
    exchange_cfg = collections.defaultdict(list)
    for rtype, by_diff in enumerate(cfg.get('QuestF95EResultItems', [])):
        for di, lst in enumerate(by_diff):
            for v in lst:
                c = ('%08X' % H(v))[:6]
                exchange_cfg[c].append(f"Black Paper's Dangerous Deal (route {rtype}, {DIFFS[di] if di < 4 else di}): 1 of {len(lst)}")
    for n_tickets, v in [x for x in cfg.get('QuestF95FResultItems', [])][1:]:
        c = ('%08X' % H(v))[:6]
        exchange_cfg[c].append(f"Gallon's Plan: {H(n_tickets)} Photon Tickets")
    for ti, tier in enumerate(cfg.get('QuestF960SuccessResultItems', [])):
        for day in ('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'):
            lst = tier.get(day, [])
            for v in lst:
                c = ('%08X' % H(v))[:6]
                exchange_cfg[c].append(f"Coren tier {ti + 1} ({H(tier['MesetaCost'])} Meseta), {day}: 4% x 1/{len(lst)}")

    # Challenge Mode: rank thresholds and A/B prizes (opcodes F868 / F86A)
    challenge = {'Ep1': {'stages': [], 'rank_seconds': None, 'prizes': []}, 'Ep2': {'stages': [], 'rank_seconds': None, 'prizes': []}}
    for path in sorted(glob.glob(Q + 'challenge-ep1/*-bb-e.bin') + glob.glob(Q + 'challenge-ep2/*-bb-e.bin')):
        b = prs_decompress(open(path, 'rb').read())
        code_off, label_off = struct.unpack_from('<II', b, 0)
        ep = 'Ep1' if b[0x14] == 0 else 'Ep2'
        desc = b[0x58:0x158].decode('utf-16-le', 'ignore').split('\x00')[0]
        m = re.search(r'Area\s*(\d+)\s*-\s*(\d+)', desc)
        code = b[code_off:label_off]
        regs, thresholds, prizes, i = {}, [], [], 0
        while i < len(code) - 6:
            op = code[i]
            if op == 0x09: regs[code[i + 1]] = struct.unpack_from('<i', code, i + 2)[0]; i += 6; continue
            if op == 0x0A: regs[code[i + 1]] = code[i + 2]; i += 3; continue
            if op == 0x0B: regs[code[i + 1]] = struct.unpack_from('<H', code, i + 2)[0]; i += 4; continue
            if op == 0xF8 and code[i + 1] == 0x68:
                r = code[i + 2]; thresholds.append((regs.get(r), regs.get(r + 1))); i += 4; continue
            if op == 0xF8 and code[i + 1] == 0x6A:
                r = code[i + 2]; v = [regs.get(r + k) for k in range(3)]
                if None not in v: prizes.append('%02X%02X%02X' % tuple(x & 0xFF for x in v))
                i += 4; continue
            i += 1
        challenge[ep]['stages'].append({'stage': len(challenge[ep]['stages']) + 1, 'file': os.path.basename(path),
                                        'areas': [int(m.group(1)), int(m.group(2))] if m else None})
        if thresholds:
            challenge[ep]['rank_seconds'] = {['B', 'A', 'S'][r]: s for r, s in thresholds if r is not None and r < 3}
        for p in prizes:
            if p not in challenge[ep]['prizes']: challenge[ep]['prizes'].append(p)
    # Ep2's A prize and class-dependent B prizes are set through branches the simple register scan can't follow;
    # these come from reading the stage scripts by hand (see api/BOUNTY_SYSTEM.md, Challenge Mode).
    challenge['Ep1']['prizes'] = sorted(set(challenge['Ep1']['prizes']) | {'01022C', '01022E'})
    challenge['Ep2']['prizes'] = sorted((set(challenge['Ep2']['prizes']) | {'010266', '010118', '010119', '01011A'}) & set(names))
    challenge_prizes = set(challenge['Ep1']['prizes']) | set(challenge['Ep2']['prizes'])

    # --- Per item
    items = {}
    for code, name in names.items():
        if code not in ipt['Items'] and kind_of(code) != 'Mag':
            continue
        d = ipt['Items'].get(code, {})
        k = kind_of(code)
        st = stars(code, d) if d else 0
        rare = st >= 9
        src = [s for s in sources.get(code, []) if not s['seasonal']]
        per_sec = [0.0] * 10
        for s in src: per_sec[s['sec']] = max(per_sec[s['sec']], s['rate'])
        best = max(src, key=lambda s: s['rate']) if src else None
        a0, a1, a2 = int(code[0:2], 16), int(code[2:4], 16), int(code[4:6], 16)
        if a0 == 3: common = code in common_tools or a1 == 2
        elif rare: common = False
        elif a0 == 0: common = 1 <= a1 <= 0x0C and a2 <= 4
        elif a0 == 1 and a1 in (1, 2): common = a2 <= 0x18
        elif a0 == 1 and a1 == 3: common = True
        elif a0 == 2: common = a1 == 0
        else: common = False
        challenge_prize = (a0 == 0 and a1 in S_RANK_WEAPON_TYPES) or code in challenge_prizes
        qs = quest_items.get(code, [])
        ex = exchange_cfg.get(code, []) + [f"{q['quest']} ({q['exchange']})" for q in qs if q['exchange']]
        story = [q['quest'] for q in qs if not q['exchange']]
        if challenge_prize: how = 'challenge_prize'
        elif best: how = 'rare_drop'
        elif common: how = 'common_drop'
        elif ex: how = 'exchange_only'
        elif story: how = 'quest_reward_only'
        elif code in combos: how = 'combination_only'
        elif any(s['seasonal'] for s in sources.get(code, [])): how = 'seasonal_drop_only'
        else: how = 'not_obtainable'
        bits = H(d.get('UsabilityFlags', 0xFF)) if d else 0xFF
        items[code] = {
            'name': name, 'kind': k, 'stars': st, 'rare': rare, 'obtained': how,
            'usable_by': [cn for cn in CLASSES if (bits & CLASS_BITS[cn]) == CLASS_BITS[cn]] if k in ('Weapon', 'Armor', 'Shield') else CLASSES,
            'equip_level': equip_levels(code, d) if d else [1] * 12,
            'drop_rate_by_section': [round(x, 9) for x in per_sec],
            'best_drop_rate': round(best['rate'], 9) if best else 0,
            'best_drop_source': f"{best['ep']} {best['diff']} {SECTIONS[best['sec']]} {best['where']}" if best else None,
            'drop_sources': len(src),
            'drop_settings': sorted({sid for s in src for sid in setting_of.get((s['ep'], s['where']), [])}),
            'drop_episodes': sorted({s['ep'] for s in src}),
            'common_drop': common,
            'exchanges': ex, 'quest_rewards': sorted(set(story)), 'combinations': combos.get(code, []),
            'seasonal_drop': any(s['seasonal'] for s in sources.get(code, [])),
        }
        if k in ('Armor', 'Shield') and d:
            items[code]['def_range'] = H(d.get('DFPRange', 0))
            items[code]['evp_range'] = H(d.get('EVPRange', 0))

    # --- Battle rule sets
    battle = []
    ARG = {0x12: 'tech_disks', 0x13: 'weapons_armor', 0x14: 'mags', 0x15: 'tools', 0x18: 'respawn', 0x19: 'replace_char',
           0x1A: 'drop_weapon_on_death', 0x1B: 'teams', 0x1D: 'death_level_up', 0x1E: 'meseta', 0x6F: 'lives',
           0x70: 'max_tech_level', 0x71: 'char_level', 0x72: 'time_limit_minutes', 0x9E: 'forbid_scape_dolls'}
    VAL = {'tech_disks': {0: 'forbidden', 1: 'allowed', 2: 'level-limited'},
           'weapons_armor': {0: 'forbidden', 1: 'own equipment', 2: 'start empty, pick up only', 3: 'no rares'},
           'mags': {0: 'forbidden', 1: 'allowed'}, 'tools': {0: 'forbidden', 1: 'own items', 2: 'start empty, pick up only'},
           'respawn': {0: 'no revival', 1: 'unlimited revival', 2: 'limited lives'},
           'meseta': {0: 'own Meseta', 1: 'forbidden', 2: 'start at 0, pick up only'}}
    SCORE = {0x48: 'player_damage_given', 0x49: 'player_damage_taken', 0x4C: 'player_kill', 0x4D: 'death_to_player',
             0x4E: 'enemy_kill', 0x4F: 'death_to_enemy', 0x50: 'per_meseta'}
    TRAP = {0: 'damage', 1: 'slow', 2: 'confuse', 3: 'freeze'}
    for path in sorted(glob.glob(Q + 'battle/*-bb-e.bin')):
        b = prs_decompress(open(path, 'rb').read())
        code_off, label_off = struct.unpack_from('<II', b, 0)
        qnum = struct.unpack_from('<H', b, 0x10)[0]
        qname = b[0x18:0x58].decode('utf-16-le', 'ignore').split('\x00')[0]
        desc = ' '.join(b[0x58:0x158].decode('utf-16-le', 'ignore').split('\x00')[0].split())
        code = b[code_off:label_off]
        regs, args, rules, traps, scores, i = {}, [], {}, {}, {}, 0
        while i < len(code) - 1:
            op = code[i]
            if op == 0x09: regs[code[i + 1]] = struct.unpack_from('<i', code, i + 2)[0]; i += 6; continue
            if op == 0x0A: regs[code[i + 1]] = code[i + 2]; i += 3; continue
            if op == 0x0B: regs[code[i + 1]] = struct.unpack_from('<H', code, i + 2)[0]; i += 4; continue
            if op == 0x49: args.append(struct.unpack_from('<i', code, i + 1)[0]); i += 5; continue
            if op == 0x4A: args.append(code[i + 1]); i += 2; continue
            if op == 0x4B: args.append(struct.unpack_from('<H', code, i + 1)[0]); i += 3; continue
            if op == 0x48: args.append(regs.get(code[i + 1])); i += 2; continue
            if op == 0x4E:
                j = i + 1
                while j + 1 < len(code) and (code[j] or code[j + 1]): j += 2
                args.append(None); i = j + 2; continue
            if op == 0xF8:
                sub = code[i + 1]
                if sub in ARG:
                    if args: rules[ARG[sub]] = args[-1]
                    args = []; i += 2; continue
                if sub in (0x10, 0x16, 0x17, 0x1C, 0x52, 0x5E, 0x5F, 0x6B, 0xA8):
                    args = []; i += 2; continue
                if sub == 0x51:
                    r = code[i + 2]
                    if regs.get(r) is not None: traps[TRAP.get(regs[r], regs[r])] = regs.get(r + 1)
                    i += 3; continue
                if sub in SCORE:
                    r = code[i + 2]; x, y, z = regs.get(r), regs.get(r + 1), regs.get(r + 2)
                    if None not in (x, y, z) and z: scores[SCORE[sub]] = round(x + y / z, 4)
                    i += 3; continue
            i += 1
        rd = {k2: VAL.get(k2, {}).get(v, v) for k2, v in rules.items()}
        battle.append({'quest_number': qnum, 'name': qname, 'description': desc, 'rules': rd, 'traps': traps, 'scores': scores})

    os.makedirs(a.out, exist_ok=True)
    meta = {'generated_from': 'newserv system/tables and system/quests (Blue Burst)', 'rate_unit': 'per kill or per box, Normal-mode games'}
    json.dump({'meta': meta, 'items': items}, open(os.path.join(a.out, 'bounty_items.json'), 'w'), indent=0, ensure_ascii=False, sort_keys=True)
    json.dump(battle, open(os.path.join(a.out, 'battle_rule_sets.json'), 'w'), indent=1, ensure_ascii=False)
    json.dump({'episodes': challenge, 'prize_names': {c: names.get(c) for c in challenge_prizes}},
              open(os.path.join(a.out, 'challenge_mode.json'), 'w'), indent=1, ensure_ascii=False)
    json.dump(quests, open(os.path.join(a.out, 'quest_item_lists.json'), 'w'), indent=1, ensure_ascii=False)
    json.dump(build_areas(os.path.dirname(os.path.abspath(__file__))), open(os.path.join(a.out, 'bounty_areas.json'), 'w'), indent=1, ensure_ascii=False)
    json.dump(build_levels(T, os.path.dirname(os.path.abspath(__file__))), open(os.path.join(a.out, 'bounty_levels.json'), 'w'), indent=1, ensure_ascii=False)
    c = collections.Counter(v['obtained'] for v in items.values())
    print('items:', len(items), dict(c))


if __name__ == '__main__':
    main()
