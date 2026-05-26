#!/usr/bin/env python3
"""
Export enemy spawn zones for the drops page area filter.

Source of truth: newserv/src/RareItemSet.cc zone_types_for_episode (drop chart layout)
and floor / area labels aligned with tools/generate_common_drops.py AREA_LABELS.

  python3 tools/generate_enemy_spawns.py --out api/data/enemy-spawns-by-area-v4.json
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

# EnemyType enum tokens (must match rare/common table source ids).
ZONE_TYPES: dict[str, list[tuple[str, list[int], list[str]]]] = {
    "Ep1": [
        (
            "Forest",
            [0x01, 0x02, 0x0B],
            [
                "BOOMA",
                "GOBOOMA",
                "GIGOBOOMA",
                "SAVAGE_WOLF",
                "BARBAROUS_WOLF",
                "RAG_RAPPY",
                "AL_RAPPY",
                "MONEST",
                "MOTHMANT",
                "HILDEBEAR",
                "HILDEBLUE",
                "DRAGON",
            ],
        ),
        (
            "Caves",
            [0x03, 0x04, 0x05, 0x0C],
            [
                "EVIL_SHARK",
                "PAL_SHARK",
                "GUIL_SHARK",
                "POISON_LILY",
                "NAR_LILY",
                "POFUILLY_SLIME",
                "POUILLY_SLIME",
                "NANO_DRAGON",
                "GRASS_ASSASSIN",
                "PAN_ARMS",
                "HIDOOM",
                "MIGIUM",
                "DE_ROL_LE_BODY",
                "DE_ROL_LE_MINE",
                "DE_ROL_LE",
            ],
        ),
        (
            "Mines",
            [0x06, 0x07, 0x0D],
            [
                "GILLCHIC",
                "DUBCHIC",
                "DUBWITCH",
                "CANADINE",
                "CANADINE_GROUP",
                "CANANE",
                "SINOW_BEAT",
                "SINOW_GOLD",
                "GARANZ",
                "VOL_OPT_AMP",
                "VOL_OPT_CORE",
                "VOL_OPT_MONITOR",
                "VOL_OPT_PILLAR",
                "VOL_OPT_1",
                "VOL_OPT_2",
            ],
        ),
        (
            "Ruins",
            [0x08, 0x09, 0x0A, 0x0E],
            [
                "DIMENIAN",
                "LA_DIMENIAN",
                "SO_DIMENIAN",
                "CLAW",
                "BULK",
                "BULCLAW",
                "DELSABER",
                "CHAOS_SORCERER",
                "BEE_L",
                "BEE_R",
                "DARK_BELRA",
                "DARK_GUNNER",
                "DARK_GUNNER_CONTROL",
                "DEATH_GUNNER",
                "CHAOS_BRINGER",
                "DARVANT",
                "DARK_FALZ_1",
                "DARK_FALZ_2",
                "DARK_FALZ_3",
            ],
        ),
    ],
    "Ep2": [
        (
            "VR Temple",
            [0x01, 0x02, 0x0E],
            [
                "RAG_RAPPY",
                "LOVE_RAPPY",
                "EGG_RAPPY",
                "HALLO_RAPPY",
                "SAINT_RAPPY",
                "DIMENIAN",
                "LA_DIMENIAN",
                "SO_DIMENIAN",
                "POISON_LILY",
                "NAR_LILY",
                "MONEST",
                "MOTHMANT",
                "GRASS_ASSASSIN",
                "HILDEBEAR",
                "HILDEBLUE",
                "DARK_BELRA",
                "PIG_RAY",
                "BARBA_RAY",
            ],
        ),
        (
            "VR Spaceship",
            [0x03, 0x04, 0x0F],
            [
                "SAVAGE_WOLF",
                "BARBAROUS_WOLF",
                "GILLCHIC",
                "DUBCHIC",
                "DUBWITCH",
                "PAN_ARMS",
                "HIDOOM",
                "MIGIUM",
                "DELSABER",
                "GARANZ",
                "CHAOS_SORCERER",
                "BEE_L",
                "BEE_R",
                "GOL_DRAGON",
            ],
        ),
        (
            "CCA",
            [0x05, 0x06, 0x07, 0x08, 0x09, 0x0C, 0x10],
            [
                "MERILLIA",
                "MERILTAS",
                "GEE",
                "UL_GIBBON",
                "ZOL_GIBBON",
                "SINOW_BERILL",
                "SINOW_SPIGELL",
                "GI_GUE",
                "GIBBLES",
                "MERICARAND",
                "MERICAROL",
                "MERICUS",
                "MERIKLE",
                "GAL_GRYPHON",
            ],
        ),
        (
            "Seabed",
            [0x0A, 0x0B, 0x0D],
            [
                "DOLMOLM",
                "DOLMDARL",
                "SINOW_ZOA",
                "SINOW_ZELE",
                "RECOBOX",
                "RECON",
                "MORFOS",
                "DELDEPTH",
                "DELBITER",
                "GAEL_OR_GIEL",
                "OLGA_FLOW_1",
                "OLGA_FLOW_2",
            ],
        ),
        (
            "Tower",
            [0x11],
            [
                "MERICARAND",
                "MERICAROL",
                "MERICUS",
                "MERIKLE",
                "GIBBLES",
                "GI_GUE",
                "DELBITER",
                "ILL_GILL",
                "DEL_LILY",
                "EPSILON",
                "EPSIGARD",
            ],
        ),
    ],
    "Ep4": [
        (
            "Crater",
            [0x01, 0x02, 0x03, 0x04, 0x05],
            [
                "SAND_RAPPY_CRATER",
                "DEL_RAPPY_CRATER",
                "SATELLITE_LIZARD_CRATER",
                "YOWIE_CRATER",
                "BOOTA",
                "ZE_BOOTA",
                "BA_BOOTA",
                "ZU_CRATER",
                "PAZUZU_CRATER",
                "ASTARK",
                "DORPHON",
                "DORPHON_ECLAIR",
            ],
        ),
        (
            "Desert",
            [0x06, 0x07, 0x08, 0x09],
            [
                "SAND_RAPPY_DESERT",
                "DEL_RAPPY_DESERT",
                "SATELLITE_LIZARD_DESERT",
                "YOWIE_DESERT",
                "GORAN",
                "PYRO_GORAN",
                "GORAN_DETONATOR",
                "MERISSA_A",
                "MERISSA_AA",
                "ZU_DESERT",
                "PAZUZU_DESERT",
                "GIRTABLULU",
                "SAINT_MILION",
                "SHAMBERTIN",
                "KONDRIEU",
            ],
        ),
    ],
}

# Floor id -> area_label (box column names / UI tokens).
FLOOR_AREA_LABEL: dict[str, dict[int, str]] = {
    "Ep1": {
        0x01: "Forest1",
        0x02: "Forest2",
        0x03: "Cave1",
        0x04: "Cave2",
        0x05: "Cave3",
        0x06: "Mine1",
        0x07: "Mine2",
        0x08: "Ruins1",
        0x09: "Ruins2",
        0x0A: "Ruins3",
        0x0B: "Dragon",
        0x0C: "DeRolLe",
        0x0D: "VolOpt",
        0x0E: "DarkFalz",
    },
    "Ep2": {
        0x01: "VRT-A",
        0x02: "VRT-B",
        0x03: "VRS-A",
        0x04: "VRS-B",
        0x05: "JunN/Ctrl",
        0x06: "JunS",
        0x07: "Mountain",
        0x08: "Seaside",
        0x0A: "SbUpper",
        0x0B: "SbLower",
        0x0C: "GalGryphon",
        0x0D: "OlgaFlow",
        0x0E: "BarbaRay",
        0x0F: "GolDragon",
        0x10: "SeasideNight",
        0x11: "Tower",
    },
    "Ep4": {
        0x01: "CraterE",
        0x02: "CraterW",
        0x03: "CraterS",
        0x04: "CraterN",
        0x05: "CrInterior",
        0x06: "Desert1",
        0x07: "Desert2",
        0x08: "Desert3",
        0x09: "SaintMil",
    },
}


def area_group_label(area_label: str) -> str:
    m = re.match(r"^([A-Za-z]+)", area_label)
    return m.group(1) if m else area_label


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "--out",
        type=Path,
        default=Path("api/data/enemy-spawns-by-area-v4.json"),
    )
    args = ap.parse_args()

    by_group: dict[tuple[str, str], dict] = {}

    for ep_token, zones in ZONE_TYPES.items():
        floor_map = FLOOR_AREA_LABEL[ep_token]
        for zone_name, floors, enemies in zones:
            for floor in floors:
                area_label = floor_map.get(floor)
                if not area_label:
                    continue
                group = area_group_label(area_label)
                key = (ep_token, group)
                if key not in by_group:
                    by_group[key] = {
                        "episode_token": ep_token,
                        "area_group": group,
                        "zone": zone_name,
                        "area_labels": [],
                        "enemies": [],
                    }
                entry = by_group[key]
                if area_label not in entry["area_labels"]:
                    entry["area_labels"].append(area_label)
                for en in enemies:
                    if en not in entry["enemies"]:
                        entry["enemies"].append(en)

    groups = sorted(by_group.values(), key=lambda e: (e["episode_token"], e["area_group"]))

    out = {
        "meta": {
            "source": "newserv/src/RareItemSet.cc zone_types_for_episode",
            "note": "Maps area filter groups (Forest, VRT, …) to enemy source ids for rare/common rows.",
        },
        "groups": groups,
    }

    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {args.out} ({len(groups)} area groups)")


if __name__ == "__main__":
    main()
