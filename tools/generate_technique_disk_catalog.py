#!/usr/bin/env python3
"""
Emit a small JSON catalog of technique disk *items* (tool class) from api/item_map.json.

This is a reference list of disk item codes, not enemy/box drop weights.

  python3 tools/generate_technique_disk_catalog.py
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from generate_rare_drops import humanize_item_name

# BB technique disk item codes in item_map (tool = 03, subtype 02, technique id = low byte).
_TECH_DISK_HEX = re.compile(r"^0302[0-9A-Fa-f]{2}$")


def disk_catalog_display_name(raw_key: str) -> str:
    body = raw_key.strip()
    if body.lower().startswith("disk:"):
        body = body[5:].strip()
    return "Disk: " + humanize_item_name(body.replace("Lv.", "lv.")).replace("lv.", "Lv.")


def main() -> None:
    root = Path(__file__).resolve().parents[1]
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "--item-map",
        type=Path,
        default=root / "api/item_map.json",
    )
    ap.add_argument(
        "--out",
        type=Path,
        default=root / "api/data/technique-disk-catalog-v4.json",
    )
    args = ap.parse_args()

    raw = json.loads(args.item_map.read_text(encoding="utf-8"))
    if not isinstance(raw, dict):
        raise SystemExit("item_map must be object")

    by_hex: dict[str, list[str]] = {}
    for name, hx in raw.items():
        if not isinstance(name, str) or not isinstance(hx, str):
            continue
        if not name.lower().startswith("disk:"):
            continue
        hk = hx.strip().lower()
        if not _TECH_DISK_HEX.match(hk):
            continue
        by_hex.setdefault(hk, []).append(name)

    items: list[dict[str, str]] = []
    for hk in sorted(by_hex):
        cand = sorted(by_hex[hk], key=len)
        no_x = [x for x in cand if not x.lower().strip().endswith("x1")]
        pick = sorted(no_x or cand, key=len)[0]
        items.append(
            {"item_hex": hk.upper(), "item_name": disk_catalog_display_name(pick)}
        )

    meta = {"source": "api/item_map.json", "entries": len(items)}
    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(
        json.dumps({"meta": meta, "items": items}, separators=(",", ":"), ensure_ascii=False)
        + "\n",
        encoding="utf-8",
    )
    print(f"Wrote {args.out.relative_to(root)} ({len(items)} items).")


if __name__ == "__main__":
    main()
