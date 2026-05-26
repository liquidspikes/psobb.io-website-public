#!/usr/bin/env python3
"""
Flatten newserv's rare-table-v4.json (JSONC + hex ints + trailing commas) into strict JSON
for the website. Run after updating the rare table:

  python3 tools/generate_rare_drops.py \
    --table ../newserv/system/item-tables/rare-table-v4.json \
    --item-map api/item_map.json \
    --out api/data/rare-drops-v4.json

Paths are relative to the website repo root when invoked from there.
"""

from __future__ import annotations

import argparse
import json
import math
import sys
from pathlib import Path

UINT32 = 2 ** 32

sys.path.insert(0, str(Path(__file__).resolve().parent))
from psobb_newserv_json import strip_newserv_jsonc


def parse_fraction_prob(s: str) -> float | None:
    s = s.strip()
    if "/" not in s:
        return None
    a, b = s.split("/", 1)
    try:
        num = float(a)
        den = float(b)
        if den == 0:
            return None
        return (num / den) * 100.0
    except ValueError:
        return None


def approx_percent(prob) -> float | None:
    if isinstance(prob, str):
        return parse_fraction_prob(prob)
    if isinstance(prob, int):
        return (prob / UINT32) * 100.0
    if isinstance(prob, float):
        return (prob / UINT32) * 100.0
    return None


def load_item_lookup(item_map_path: Path) -> dict[str, str]:
    data = json.loads(item_map_path.read_text(encoding="utf-8"))
    by_hex: dict[str, list[str]] = {}
    for name, hx in data.items():
        key = str(hx).strip().lower()
        by_hex.setdefault(key, []).append(name)
    # Pick a stable display string: shortest name, then lexicographic
    display: dict[str, str] = {}
    for hx, names in by_hex.items():
        pick = sorted(names, key=lambda n: (len(n), n.lower()))[0]
        display[hx] = pick
    return display


def format_item_hex(code: int) -> str:
    return f"{code:06X}"


def humanize_item_name(raw: str) -> str:
    """Title-ish display while keeping apostrophes and hyphens readable."""
    parts = raw.replace("'", "'").split()
    out = []
    for p in parts:
        if not p:
            continue
        if "'" in p:
            bits = p.split("'")
            bits = [b[:1].upper() + b[1:].lower() if b else b for b in bits]
            out.append("'".join(bits))
        elif "-" in p and len(p) > 1:
            out.append("-".join(x[:1].upper() + x[1:].lower() if x else x for x in p.split("-")))
        else:
            out.append(p[:1].upper() + p[1:].lower())
    return " ".join(out)


def flatten_rare_table(data: dict, item_by_hex: dict[str, str]) -> list[dict]:
    rows: list[dict] = []
    normal = data.get("Normal", data)
    for episode, ep_node in normal.items():
        if not isinstance(ep_node, dict):
            continue
        for difficulty, diff_node in ep_node.items():
            if not isinstance(diff_node, dict):
                continue
            for section_id, sec_node in diff_node.items():
                if not isinstance(sec_node, dict):
                    continue
                for source, specs in sec_node.items():
                    if not isinstance(specs, list):
                        continue
                    for spec in specs:
                        if not isinstance(spec, list) or len(spec) < 2:
                            continue
                        prob, item_code = spec[0], spec[1]
                        if not isinstance(item_code, int):
                            continue
                        hx = format_item_hex(item_code)
                        raw_name = item_by_hex.get(hx.lower())
                        item_name = humanize_item_name(raw_name) if raw_name else None
                        pct = approx_percent(prob)
                        row = {
                            "episode": episode,
                            "difficulty": difficulty,
                            "section_id": section_id,
                            "source": source,
                            "probability": prob,
                            "approx_percent": (round(pct, 6) if pct is not None and math.isfinite(pct) else None),
                            "item_hex": hx,
                            "item_name": item_name,
                        }
                        rows.append(row)
    return rows


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "--table",
        type=Path,
        default=Path(__file__).resolve().parents[2] / "newserv/system/item-tables/rare-table-v4.json",
        help="Path to rare-table-v4.json from newserv",
    )
    ap.add_argument(
        "--item-map",
        type=Path,
        default=Path(__file__).resolve().parents[1] / "api/item_map.json",
        help="Path to api/item_map.json",
    )
    ap.add_argument(
        "--out",
        type=Path,
        default=Path(__file__).resolve().parents[1] / "api/data/rare-drops-v4.json",
        help="Output JSON path",
    )
    args = ap.parse_args()

    raw = args.table.read_text(encoding="utf-8")
    clean = strip_newserv_jsonc(raw)
    data = json.loads(clean)
    item_by_hex = load_item_lookup(args.item_map)
    rows = flatten_rare_table(data, item_by_hex)

    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(
        json.dumps(rows, separators=(",", ":"), ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    print(f"Wrote {len(rows)} rows to {args.out}")


if __name__ == "__main__":
    main()
