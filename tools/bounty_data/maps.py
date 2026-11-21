"""
How many of each enemy and box a player meets in each area, from newserv's Blue Burst map files
(system/maps/bb-v4), so that item rarity can be measured by effort rather than per kill of one enemy.

An item's rate per kill of its enemy says little on its own: a Booma drop is easy to farm because an area is
full of Boomas, a Hildeblue drop is rare because only 1 in 500 Hildebears is blue, and a boss drop costs a whole
run of the area. build.py combines the rare table with these counts: for each place a player can farm (a single
floor, or the full run of an area to its boss),

    drops per run = sum over its enemies (count x rate per kill) + boxes x rate per box
    rate per kill in that place = drops per run / enemies killed in the run

and an item's rarity is that rate in the best place to farm it.

The enemy-set files are arrays of 0x48-byte entries. Each entry's base type (and parameters) are read the way
newserv's Map.cc reads them; groups add their members. A few counts are approximations, noted below.
"""
import collections, glob, os, struct

# Floors that free play reaches, with the online (non-solo) map files, the floor's area code (as newserv's Map.cc
# uses it to tell variants apart) and the rare table's box source for that floor.
FLOORS = {
    'Forest 1': ('forest01_0[0-4]', 0x01, 'Box-Forest1'), 'Forest 2': ('forest02_0[0-4]', 0x02, 'Box-Forest2'),
    'Cave 1': ('cave01_*', 0x03, 'Box-Cave1'), 'Cave 2': ('cave02_*', 0x04, 'Box-Cave2'), 'Cave 3': ('cave03_*', 0x05, 'Box-Cave3'),
    'Mine 1': ('machine01_*', 0x06, 'Box-Mine1'), 'Mine 2': ('machine02_*', 0x07, 'Box-Mine2'),
    'Ruins 1': ('ancient01_*', 0x08, 'Box-Ruins1'), 'Ruins 2': ('ancient02_*', 0x09, 'Box-Ruins2'),
    'Ruins 3': ('ancient03_*', 0x0A, 'Box-Ruins3'),
    'VR Temple Alpha': ('ruins01_*', 0x13, 'Box-VRTempleAlpha'), 'VR Temple Beta': ('ruins02_*', 0x14, 'Box-VRTempleBeta'),
    'VR Spaceship Alpha': ('space01_*', 0x15, 'Box-VRSpaceshipAlpha'),
    'VR Spaceship Beta': ('space02_*', 0x16, 'Box-VRSpaceshipBeta'),
    'Central Control Area': ('jungle01_*', 0x17, None), 'Jungle North': ('jungle02_*', 0x18, 'Box-JungleNorth'),
    'Jungle East': ('jungle03_*', 0x19, 'Box-JungleEast'), 'Mountain': ('jungle04_*', 0x1A, 'Box-Mountain'),
    'Seaside': ('jungle05_*', 0x1B, 'Box-Seaside'),
    'Seabed Upper': ('seabed01_*', 0x1C, 'Box-SeabedUpper'), 'Seabed Lower': ('seabed02_*', 0x1D, 'Box-SeabedLower'),
    'Crater East': ('wilds01_00_*', 0x24, 'Box-CraterEast'), 'Crater West': ('wilds01_01_*', 0x25, 'Box-CraterWest'),
    'Crater South': ('wilds01_02_*', 0x26, 'Box-CraterSouth'), 'Crater North': ('wilds01_03_*', 0x27, 'Box-CraterNorth'),
    'Crater Interior': ('crater01_*', 0x28, 'Box-CraterInterior'),
    'Desert 1': ('desert01_*', 0x29, 'Box-Desert1'), 'Desert 2': ('desert02_*', 0x2A, 'Box-Desert2'),
    'Desert 3': ('desert03_*', 0x2B, 'Box-Desert3'),
}

# The full run of each area, with its boss: (episode, floors, {boss source: count per run}).
# Saint-Milion and Shambertin are one boss or the other each run; Kondrieu's 1 in 10 is already in its rate.
# Barba Ray's ten joints each have a drop of their own.
AREA_RUNS = {
    'ep1_forest': ('Ep1', ['Forest 1', 'Forest 2'], {'DRAGON': 1}),
    'ep1_caves': ('Ep1', ['Cave 1', 'Cave 2', 'Cave 3'], {'DE_ROL_LE': 1}),
    'ep1_mines': ('Ep1', ['Mine 1', 'Mine 2'], {'VOL_OPT_2': 1}),
    'ep1_ruins': ('Ep1', ['Ruins 1', 'Ruins 2', 'Ruins 3'], {'DARK_FALZ_2': 1, 'DARK_FALZ_3': 1}),
    'ep2_vr_temple': ('Ep2', ['VR Temple Alpha', 'VR Temple Beta'], {'BARBA_RAY': 1, 'BARBA_RAY_JOINT': 10}),
    'ep2_vr_spaceship': ('Ep2', ['VR Spaceship Alpha', 'VR Spaceship Beta'], {'GOL_DRAGON': 1}),
    'ep2_gal_da_val': ('Ep2', ['Central Control Area', 'Jungle North', 'Jungle East', 'Mountain', 'Seaside'],
                       {'GAL_GRYPHON': 1}),
    'ep2_seabed': ('Ep2', ['Seabed Upper', 'Seabed Lower'], {'OLGA_FLOW_2': 1}),
    'ep4_crater': ('Ep4', ['Crater East', 'Crater West', 'Crater South', 'Crater North', 'Crater Interior'], {}),
    'ep4_desert': ('Ep4', ['Desert 1', 'Desert 2', 'Desert 3'], {'SAINT_MILION': 0.5, 'SHAMBERTIN': 0.5, 'KONDRIEU': 1}),
}

# A rare form replaces its base enemy; the rare table's rate already includes the replacement chance (build.py's
# RARE_ENEMY_SPAWN), so it is counted on the base enemy's spawns.
RARE_BASE = {
    'HILDEBLUE': 'HILDEBEAR', 'AL_RAPPY': 'RAG_RAPPY', 'NAR_LILY': 'POISON_LILY', 'POUILLY_SLIME': 'POFUILLY_SLIME',
    'MERISSA_AA': 'MERISSA_A', 'PAZUZU_CRATER': 'ZU_CRATER', 'PAZUZU_DESERT': 'ZU_DESERT',
    'DORPHON_ECLAIR': 'DORPHON', 'DEL_RAPPY_CRATER': 'SAND_RAPPY_CRATER', 'DEL_RAPPY_DESERT': 'SAND_RAPPY_DESERT',
    'MERICUS': 'MERICARAND', 'MERIKLE': 'MERICARAND',
    'LOVE_RAPPY': 'RAG_RAPPY', 'EGG_RAPPY': 'RAG_RAPPY', 'HALLO_RAPPY': 'RAG_RAPPY', 'SAINT_RAPPY': 'RAG_RAPPY',
}
# Enemies only met in quests (the Control Tower): no free-play map has them, so they count as one enemy type
# among a typical area's dozen.
QUEST_ONLY_SHARE = 1 / 12

BOX_TYPES = {0x0088, 0x0092, 0x0161, 0x0162, 0x0200, 0x0203}   # item boxes (Map.cc object definitions)
NOT_KILLS = {'MONEST', 'RECOBOX', 'DUBWITCH'}                   # spawners and controllers


def _enemies(path, area):
    """Enemy counts in one enemy-set file, by rare-table name."""
    data = open(path, 'rb').read()
    out = collections.Counter()
    for off in range(0, len(data) - 0x47, 0x48):
        t, _, _, kids = struct.unpack_from('<HHHH', data, off)
        p2 = struct.unpack_from('<f', data, off + 0x30)[0]
        p6 = struct.unpack_from('<h', data, off + 0x40)[0]

        def add(name, n=1):
            out[name] += n
        if t == 0x40: add('HILDEBEAR')
        elif t == 0x41: add('RAG_RAPPY' if area < 0x24 else ('SAND_RAPPY_CRATER' if area <= 0x28 else 'SAND_RAPPY_DESERT'))
        elif t == 0x42: add('MONEST'); add('MOTHMANT', min(kids or 30, 5))   # approx.: a few of its Mothmants
        elif t == 0x43: add('BARBAROUS_WOLF' if p2 >= 1 else 'SAVAGE_WOLF')
        elif t == 0x44: add(['BOOMA', 'GOBOOMA', 'GIGOBOOMA'][max(0, min(2, p6))])
        elif t == 0x60: add('GRASS_ASSASSIN')
        elif t == 0x61: add('DEL_LILY' if area == 0x23 else 'POISON_LILY')
        elif t == 0x62: add('NANO_DRAGON')
        elif t == 0x63: add(['EVIL_SHARK', 'PAL_SHARK', 'GUIL_SHARK'][max(0, min(2, p6))])
        elif t == 0x64: add('POFUILLY_SLIME', (kids or 4) + 1)
        elif t == 0x65: add('PAN_ARMS'); add('HIDOOM'); add('MIGIUM')
        elif t == 0x80: add('GILLCHIC' if p6 != 0 else 'DUBCHIC')
        elif t == 0x81: add('GARANZ')
        elif t == 0x82: add('SINOW_GOLD' if p2 >= 1 else 'SINOW_BEAT', 1 + (kids or 4))
        elif t == 0x83: add('CANADINE')
        elif t == 0x84: add('CANANE'); add('CANADINE_GROUP', kids or 8)
        elif t == 0x85: add('DUBWITCH')
        elif t == 0xA0: add('DELSABER')
        elif t == 0xA1: add('CHAOS_SORCERER')
        elif t == 0xA2: add('DARK_GUNNER')
        elif t == 0xA4: add('CHAOS_BRINGER')
        elif t == 0xA5: add('DARK_BELRA')
        elif t == 0xA6: add(['DIMENIAN', 'LA_DIMENIAN', 'SO_DIMENIAN'][max(0, min(2, p6))])
        elif t == 0xA7: add('BULCLAW'); add('CLAW', kids or 4)
        elif t == 0xA8: add('CLAW')
        elif t == 0xD4: add('SINOW_SPIGELL' if p6 > 0 else 'SINOW_BERILL', 1 + (kids or 4))
        elif t == 0xD5: add('MERILTAS' if p6 > 0 else 'MERILLIA')
        elif t == 0xD6: add({0: 'MERICAROL', 1: 'MERIKLE', 2: 'MERICUS'}.get(p6, 'MERICARAND'))
        elif t == 0xD7: add('ZOL_GIBBON' if p6 > 0 else 'UL_GIBBON')
        elif t == 0xD8: add('GIBBLES')
        elif t == 0xD9: add('GEE')
        elif t == 0xDA: add('GI_GUE')
        elif t == 0xDB: add('DELDEPTH')
        elif t == 0xDC: add('DELBITER')
        elif t == 0xDD: add('DOLMDARL' if p6 > 0 else 'DOLMOLM')
        elif t == 0xDE: add('MORFOS')
        elif t == 0xDF: add('RECOBOX'); add('RECON', min(max(kids - 1, 0), 10))   # approx.: up to 10 Recons a box
        elif t == 0xE0: add('EPSILON') if area in (0x22, 0x23) else add('SINOW_ZELE' if p6 > 0 else 'SINOW_ZOA')
        elif t == 0xE1: add('ILL_GILL')
        elif t == 0x110: add('ASTARK')
        elif t == 0x111: add(('YOWIE_' if p2 else 'SATELLITE_LIZARD_') + ('CRATER' if area <= 0x28 else 'DESERT'))
        elif t == 0x112: add('MERISSA_A')
        elif t == 0x113: add('GIRTABLULU')
        elif t == 0x114: add('ZU_CRATER' if area <= 0x28 else 'ZU_DESERT')
        elif t == 0x115: add(['BOOTA', 'ZE_BOOTA', 'BA_BOOTA'][max(0, min(2, p6))])
        elif t == 0x116: add('DORPHON')
        elif t == 0x117: add(['GORAN', 'PYRO_GORAN', 'GORAN_DETONATOR'][max(0, min(2, p6))])
    return out


def _boxes(path):
    data = open(path, 'rb').read()
    return sum(1 for off in range(0, len(data) - 0x43, 0x44) if struct.unpack_from('<H', data, off)[0] in BOX_TYPES)


def floor_counts(newserv):
    """{floor: {'enemies': {name: mean count}, 'boxes': mean count, 'box_source': name, 'kills': n}} over the
    floor's online layouts."""
    maps = os.path.join(newserv, 'system', 'maps', 'bb-v4')
    out = {}
    for floor, (pattern, area, box_source) in FLOORS.items():
        efiles = [f for f in sorted(glob.glob(os.path.join(maps, f'map_{pattern}e.dat'))) if '_off' not in f]
        ofiles = [f for f in sorted(glob.glob(os.path.join(maps, f'map_{pattern}o.dat'))) if '_off' not in f]
        total = collections.Counter()
        for f in efiles:
            total.update(_enemies(f, area))
        enemies = {k: v / max(1, len(efiles)) for k, v in total.items()}
        out[floor] = {
            'enemies': {k: round(v, 2) for k, v in sorted(enemies.items())},
            'boxes': round(sum(_boxes(f) for f in ofiles) / max(1, len(ofiles)), 2),
            'box_source': box_source,
            'kills': round(sum(v for k, v in enemies.items() if k not in NOT_KILLS), 2),
        }
    return out


def farming_places(newserv):
    """Every place an item can be farmed: each floor on its own, and each area's full run with its boss.
    [{'name', 'setting', 'episode', 'kills', 'counts': {source: count per run}}]"""
    floors = floor_counts(newserv)
    places = []
    for setting, (ep, fls, bosses) in AREA_RUNS.items():
        for fl in fls:
            f = floors[fl]
            counts = dict(f['enemies'])
            if f['box_source']:
                counts[f['box_source']] = f['boxes']
            places.append({'name': fl, 'setting': setting, 'episode': ep, 'kills': f['kills'], 'counts': counts})
        counts = collections.Counter()
        for fl in fls:
            counts.update(floors[fl]['enemies'])
            if floors[fl]['box_source']:
                counts[floors[fl]['box_source']] += floors[fl]['boxes']
        counts.update(bosses)
        kills = sum(floors[fl]['kills'] for fl in fls) + (1 if bosses else 0)
        places.append({'name': setting + ' run', 'setting': setting, 'episode': ep, 'kills': kills, 'counts': dict(counts)})
    return places, floors


def count_for(place, where):
    """How many times a drop source is met in a place: rare forms on their base enemy's spawns, boxes by count."""
    c = place['counts']
    if where in ('MERICUS', 'MERIKLE'):
        # random Mericarols can turn out Merikle or Mericus (1 in 5, already in the rate); placed ones count in full
        return c.get('MERICARAND', 0) + 5 * c.get(where, 0)
    if where in RARE_BASE:
        return c.get(RARE_BASE[where], 0)
    return c.get(where, 0)
