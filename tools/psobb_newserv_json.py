"""
Parse newserv JSON table files:
- // line comments outside strings
- optional 0x.. integer literals outside strings -> decimal (rare-table)
- trailing commas before } ]

Used by generate_rare_drops.py and generate_common_drops.py.
"""

from __future__ import annotations


def strip_newserv_jsonc(raw: str) -> str:
    out: list[str] = []
    i = 0
    n = len(raw)
    in_str = False
    esc = False

    while i < n:
        c = raw[i]
        if in_str:
            out.append(c)
            if esc:
                esc = False
            elif c == "\\":
                esc = True
            elif c == '"':
                in_str = False
            i += 1
            continue

        if c == '"':
            in_str = True
            out.append(c)
            i += 1
            continue

        if c == "/" and i + 1 < n and raw[i + 1] == "/":
            i += 2
            while i < n and raw[i] not in "\n\r":
                i += 1
            continue

        if c == "0" and i + 1 < n and raw[i + 1] in "xX":
            j = i + 2
            while j < n and raw[j] in "0123456789abcdefABCDEF":
                j += 1
            out.append(str(int(raw[i:j], 16)))
            i = j
            continue

        out.append(c)
        i += 1

    s2 = "".join(out)
    out2: list[str] = []
    in_str = False
    esc = False
    i = 0
    n = len(s2)

    while i < n:
        c = s2[i]
        if in_str:
            out2.append(c)
            if esc:
                esc = False
            elif c == "\\":
                esc = True
            elif c == '"':
                in_str = False
            i += 1
            continue

        if c == '"':
            in_str = True
            out2.append(c)
            i += 1
            continue

        if c == ",":
            j = i + 1
            while j < n and s2[j] in " \t\n\r":
                j += 1
            if j < n and s2[j] in "}]":
                i = j
                continue

        out2.append(c)
        i += 1

    return "".join(out2)
