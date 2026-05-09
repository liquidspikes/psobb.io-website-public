#!/usr/bin/env python3
"""
Merge newserv common-table-v3-v4.json using the same inheritance chain as JSONCommonItemSet
(CommonItemSet.cc) and export flattened JSON for the website.

  python3 tools/generate_common_drops.py --table ../newserv/system/item-tables/common-table-v3-v4.json

  Defaults: api/data/common-enemies-v4.json and api/data/common-boxes-v4.json (override with --out-enemies/--out-boxes).
"""

from __future__ import annotations

import argparse
import copy
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from psobb_newserv_json import strip_newserv_jsonc

EPS = ["Ep1", "Ep2", "Ep4"]
MODES = ["Normal", "Battle", "Challenge", "Solo"]
DIFFS = ["Normal", "Hard", "VeryHard", "Ultimate"]
SECTION_NAMES = [
    "Viridia",
    "Greennill",
    "Skyly",
    "Bluefull",
    "Purplenum",
    "Pinkal",
    "Redria",
    "Oran",
    "Yellowboze",
    "Whitill",
]

AREA_LABELS = {
    "Ep1": [
        "Forest1",
        "Forest2",
        "Cave1",
        "Cave2",
        "Cave3",
        "Mine1",
        "Mine2",
        "Ruins1",
        "Ruins2",
        "Ruins3",
    ],
    "Ep2": [
        "VRT-A",
        "VRT-B",
        "VRS-A",
        "VRS-B",
        "JunN/Ctrl",
        "JunS",
        "Mountain",
        "Seaside",
        "SbUpper",
        "SbLower",
    ],
    "Ep4": [
        "CraterE",
        "CraterW",
        "CraterS",
        "CraterN",
        "CrInterior",
        "Desert1",
        "Desert2",
        "Desert3",
        "SaintMil",
        "(10)",
    ],
}

ARRAY_FIELDS = (
    "BaseWeaponTypeProbTable",
    "SubtypeBaseTable",
    "SubtypeAreaLengthTable",
    "GrindProbTable",
    "ArmorShieldTypeIndexProbTable",
    "ArmorSlotCountProbTable",
    "BoxMesetaRanges",
    "BonusValueProbTable",
    "NonRareBonusProbSpec",
    "BonusTypeProbTable",
    "SpecialMult",
    "SpecialPercent",
    "ToolClassProbTable",
    "TechniqueIndexProbTable",
    "TechniqueLevelRanges",
    "UnitMaxStarsTable",
    "BoxItemClassProbTable",
)

ITEM_CLASS_LABELS = {
    0: "WEAPON",
    1: "ARMOR",
    2: "SHIELD",
    3: "UNIT",
    4: "TOOL",
    5: "MESETA",
    6: "NOTHING",
}


def scenario_key(ep: str, mode: str, diff: str, sec: str) -> str:
    return f"{ep}:{mode}:{diff}:{sec}"


def json_key(coords: tuple[int, int, int, int]) -> str:
    ep_i, mi, di, si = coords
    return scenario_key(EPS[ep_i], MODES[mi], DIFFS[di], SECTION_NAMES[si])


def get_prev_coords(coords: tuple[int, int, int, int]) -> tuple[int, int, int, int] | None:
    _, mi, di, si = coords
    ep_i = coords[0]
    if si > 0:
        return (ep_i, mi, di, si - 1)
    if di > 0:
        return (ep_i, mi, di - 1, 0)
    if mi > 0:
        return (ep_i, 0, 0, 0)
    return None


def merge_enemy_dict_field(
    patch: dict, field: str, prev: dict | None
) -> dict[str, object]:
    if field in patch:
        return {str(k): copy.deepcopy(v) for k, v in patch[field].items()}
    if prev and field in prev:
        return copy.deepcopy(prev[field])
    return {}


def merge_table(prev: dict | None, patch: dict) -> dict:
    out: dict = {}

    for key in ARRAY_FIELDS:
        if key in patch:
            out[key] = copy.deepcopy(patch[key])
        elif prev and key in prev:
            out[key] = copy.deepcopy(prev[key])

    if "HasRareBonusValueProbTable" in patch:
        out["HasRareBonusValueProbTable"] = bool(patch["HasRareBonusValueProbTable"])
    elif prev:
        out["HasRareBonusValueProbTable"] = prev.get("HasRareBonusValueProbTable", False)
    else:
        out["HasRareBonusValueProbTable"] = False

    if "ArmorOrShieldTypeBias" in patch:
        out["ArmorOrShieldTypeBias"] = int(patch["ArmorOrShieldTypeBias"])
    elif prev and "ArmorOrShieldTypeBias" in prev:
        out["ArmorOrShieldTypeBias"] = prev["ArmorOrShieldTypeBias"]
    else:
        out["ArmorOrShieldTypeBias"] = 0

    out["EnemyMesetaRanges"] = merge_enemy_dict_field(patch, "EnemyMesetaRanges", prev)
    out["EnemyTypeDropProbs"] = merge_enemy_dict_field(patch, "EnemyTypeDropProbs", prev)
    out["EnemyItemClasses"] = merge_enemy_dict_field(patch, "EnemyItemClasses", prev)

    return out


def fmt_meseta(v: object) -> str:
    if isinstance(v, list) and len(v) >= 2:
        return f"{v[0]}-{v[1]}"
    return str(v)


def item_class_name(code: int) -> str:
    if code in ITEM_CLASS_LABELS:
        return ITEM_CLASS_LABELS[code]
    return f"0x{int(code):02X}"


def norm_weights(weights: list[int]) -> list[float | None]:
    tot = sum(weights)
    if tot <= 0:
        return [None] * len(weights)
    return [100.0 * float(w) / float(tot) for w in weights]


def build_merged_tables(patches_by_key: dict[str, dict]) -> dict[str, dict]:
    tables: dict[str, dict] = {}
    coords_order: list[tuple[int, int, int, int]] = []
    for ei in range(len(EPS)):
        for mi in range(len(MODES)):
            for di in range(len(DIFFS)):
                for si in range(10):
                    coords_order.append((ei, mi, di, si))

    for coords in coords_order:
        jk = json_key(coords)
        patch = patches_by_key.get(jk)
        prv = get_prev_coords(coords)
        prev_table = tables.get(json_key(prv)) if prv is not None else None
        if patch is None and prev_table is None:
            continue
        if patch is None:
            merged = copy.deepcopy(prev_table)
        elif prev_table is None:
            merged = merge_table(None, patch)
        else:
            merged = merge_table(prev_table, patch)
        tables[jk] = merged

    return tables


def flatten(tables: dict[str, dict]) -> tuple[list[dict], list[dict]]:
    enemy_rows: list[dict] = []
    box_rows: list[dict] = []

    BOX_CLASS_SHORT = ["Wep", "Arm", "Shd", "Uni", "Tl", "Mes", "Empty"]

    for scen, m in sorted(tables.items()):
        parts = scen.split(":")
        ep_token = parts[0] if parts else ""

        meseta_en = m.get("EnemyMesetaRanges", {})
        drop_en = m.get("EnemyTypeDropProbs", {})
        class_en = m.get("EnemyItemClasses", {})

        enemies = sorted(set(meseta_en) | set(drop_en) | set(class_en))
        for en in enemies:
            dp_raw = drop_en.get(en)
            dp = None if dp_raw is None else int(dp_raw)
            ic_raw = class_en.get(en)
            ic = None if ic_raw is None else int(ic_raw)
            row = {
                "scenario": scen,
                "episode_token": ep_token,
                "enemy": en,
                "dar_percent": dp,
                "meseta_range": meseta_en.get(en),
                "meseta_display": fmt_meseta(meseta_en[en]) if en in meseta_en else None,
                "item_class_code": ic,
                "item_class": None if ic is None else item_class_name(ic),
            }
            enemy_rows.append(row)

        box_ranges = m.get("BoxMesetaRanges")
        box_cls = m.get("BoxItemClassProbTable")
        if isinstance(box_ranges, list) and isinstance(box_cls, list):
            labels = AREA_LABELS.get(ep_token, [f"A{i}" for i in range(10)])
            for area_i in range(min(10, len(box_ranges), len(box_cls[0]) if box_cls else 0)):
                bm = box_ranges[area_i]
                if isinstance(bm, list) and len(bm) >= 2:
                    mlow, mhigh = int(bm[0]), int(bm[1])
                else:
                    mlow = mhigh = None

                ws = []
                for icls in range(7):
                    try:
                        ws.append(int(box_cls[icls][area_i]))
                    except (IndexError, TypeError, ValueError):
                        ws.append(0)
                pct = norm_weights(ws)
                summary_parts = []
                for i in range(7):
                    if pct[i] is not None and pct[i] > 0 and pct[i] < 99.995:
                        summary_parts.append(f"{BOX_CLASS_SHORT[i]} {pct[i]:.1f}%")
                box_rows.append(
                    {
                        "scenario": scen,
                        "episode_token": ep_token,
                        "area_index": area_i,
                        "area_label": labels[area_i] if area_i < len(labels) else str(area_i),
                        "meseta_low": mlow,
                        "meseta_high": mhigh,
                        "weights": ws,
                        "approx_percent": [None if p is None else round(p, 4) for p in pct],
                        "summary_short": "; ".join(summary_parts[:8]),
                    }
                )

    return enemy_rows, box_rows


# CommonItemSet: non-rare weapon types 01..0C in BaseWeaponTypeProbTable order (index 0 = 0x01).
WEAPON_TYPE_CODES = [
    "01",
    "02",
    "03",
    "04",
    "05",
    "06",
    "07",
    "08",
    "09",
    "0A",
    "0B",
    "0C",
]

WEAPON_TYPE_LABELS = [
    "Saber",
    "Sword",
    "Dagger",
    "Partisan",
    "Slicer",
    "Twin",
    "Claw",
    "Katana",
    "Shot",
    "Launcher",
    "Rifle",
    "Wand",
]


def flatten_weapon_type_chart_rows(tables: dict[str, dict]) -> list[dict]:
    """One row per merged scenario key: Ep:Mode:Diff:Section → BaseWeaponTypeProbTable percentages."""
    out: list[dict] = []
    for scen, m in sorted(tables.items()):
        parts = scen.split(":")
        if len(parts) < 4:
            continue
        ep_tok, mode, diff, sec = parts[0], parts[1], parts[2], parts[3]
        wt = m.get("BaseWeaponTypeProbTable")
        if not isinstance(wt, list) or len(wt) < 12:
            continue
        try:
            weights = [max(0, int(x)) for x in wt[:12]]
        except (TypeError, ValueError):
            continue
        tot = sum(weights)
        if tot <= 0:
            pct = [None] * 12
        else:
            pct = [round(100.0 * float(w) / float(tot), 4) for w in weights]
        out.append(
            {
                "scenario_key": scen,
                "episode_token": ep_tok,
                "mode": mode,
                "difficulty": diff,
                "section_id": sec,
                "weights_raw": weights,
                "percent": pct,
            }
        )
    return out


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "--table",
        type=Path,
        default=Path(__file__).resolve().parents[2]
        / "newserv/system/item-tables/common-table-v3-v4.json",
    )
    root = Path(__file__).resolve().parents[1]
    ap.add_argument(
        "--out-enemies",
        type=Path,
        default=root / "api/data/common-enemies-v4.json",
    )
    ap.add_argument(
        "--out-boxes",
        type=Path,
        default=root / "api/data/common-boxes-v4.json",
    )
    ap.add_argument(
        "--out-weapon-chart",
        type=Path,
        default=root / "api/data/weapon-type-by-section-v4.json",
    )
    args = ap.parse_args()

    raw = args.table.read_text(encoding="utf-8")
    patches = json.loads(strip_newserv_jsonc(raw))
    if not isinstance(patches, dict):
        raise SystemExit("expected dict root")

    tables = build_merged_tables(patches)
    enemies, boxes = flatten(tables)
    weapon_chart = flatten_weapon_type_chart_rows(tables)

    meta = {
        "source": "newserv/system/item-tables/common-table-v3-v4.json",
        "merged_scenarios": len(tables),
        "enemy_rows": len(enemies),
        "box_rows": len(boxes),
        "note_enemy": "DAR% = percent chance the enemy drops anything (ItemCreator roll), before the rare table is checked.",
        "note_box": "Box columns are area_norm (dungeon areas). Weights are index weights; approx columns are share of the column total.",
    }

    for out_path, key, rows in (
        (args.out_enemies, "enemies", enemies),
        (args.out_boxes, "boxes", boxes),
    ):
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(
            json.dumps({"meta": meta, key: rows}, separators=(",", ":"), ensure_ascii=False) + "\n",
            encoding="utf-8",
        )

    wmeta = {
        "source": "newserv/system/item-tables/common-table-v3-v4.json merged BaseWeaponTypeProbTable",
        "rows": len(weapon_chart),
        "note": "Percent = weight / sum(weights) among 12 BB non-rare types (01–0C). Dungeon floor subtype masking may zero types in-game; see ItemCreator.",
        "default_episode_token": "Ep1",
        "default_mode": "Normal",
        "default_difficulty": "Ultimate",
    }
    wtypes = [
        {"code": WEAPON_TYPE_CODES[i], "label": WEAPON_TYPE_LABELS[i]} for i in range(12)
    ]
    args.out_weapon_chart.parent.mkdir(parents=True, exist_ok=True)
    args.out_weapon_chart.write_text(
        json.dumps(
            {"meta": wmeta, "types": wtypes, "entries": weapon_chart},
            separators=(",", ":"),
            ensure_ascii=False,
        )
        + "\n",
        encoding="utf-8",
    )

    print(
        f"Wrote {args.out_enemies.name} ({len(enemies)} rows) + {args.out_boxes.name} ({len(boxes)} rows); ",
        end="",
    )
    print(
        f"{args.out_weapon_chart.name} ({len(weapon_chart)} rows); {len(tables)} merged scenarios."
    )


if __name__ == "__main__":
    main()
