<?php
/**
 * Accurate PSOBB combat stat calculation & item table enrichment
 * Derived from newserv LevelTable, SaveFileFormats, and ItemParameterTable (v4/BB).
 */

function load_psobb_item_stats_table(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = __DIR__ . '/item_stats_v4.json';
    if (!is_file($path)) {
        $cache = [];
        return $cache;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    $cache = is_array($decoded) ? $decoded : [];
    return $cache;
}

function get_psobb_class_tables(): array {
    return [
        0  => ['name' => 'HUmar',     'ata_base' => 65, 'hp_mult' => 2.00, 'tp_mult' => 1.0, 'cast' => false, 'max' => ['atp' => 1387, 'dfp' => 579, 'mst' => 732,  'ata_raw' => 1355, 'evp' => 756, 'lck' => 100]],
        1  => ['name' => 'HUnewearl', 'ata_base' => 61, 'hp_mult' => 2.00, 'tp_mult' => 1.0, 'cast' => false, 'max' => ['atp' => 1227, 'dfp' => 589, 'mst' => 1177, 'ata_raw' => 1388, 'evp' => 811, 'lck' => 100]],
        2  => ['name' => 'HUcast',    'ata_base' => 61, 'hp_mult' => 2.00, 'tp_mult' => 0.0, 'cast' => true,  'max' => ['atp' => 1629, 'dfp' => 601, 'mst' => 0,    'ata_raw' => 1300, 'evp' => 660, 'lck' => 100]],
        3  => ['name' => 'RAmar',     'ata_base' => 76, 'hp_mult' => 1.85, 'tp_mult' => 1.0, 'cast' => false, 'max' => ['atp' => 1255, 'dfp' => 515, 'mst' => 665,  'ata_raw' => 1739, 'evp' => 715, 'lck' => 100]],
        4  => ['name' => 'RAcast',    'ata_base' => 71, 'hp_mult' => 1.85, 'tp_mult' => 0.0, 'cast' => true,  'max' => ['atp' => 1345, 'dfp' => 606, 'mst' => 0,    'ata_raw' => 1530, 'evp' => 699, 'lck' => 100]],
        5  => ['name' => 'RAcaseal',  'ata_base' => 73, 'hp_mult' => 1.85, 'tp_mult' => 0.0, 'cast' => true,  'max' => ['atp' => 1170, 'dfp' => 688, 'mst' => 0,    'ata_raw' => 1580, 'evp' => 787, 'lck' => 100]],
        6  => ['name' => 'FOmarl',    'ata_base' => 62, 'hp_mult' => 1.45, 'tp_mult' => 1.5, 'cast' => false, 'max' => ['atp' => 869,  'dfp' => 498, 'mst' => 1284, 'ata_raw' => 1088, 'evp' => 588, 'lck' => 100]],
        7  => ['name' => 'FOnewm',    'ata_base' => 60, 'hp_mult' => 1.45, 'tp_mult' => 1.5, 'cast' => false, 'max' => ['atp' => 811,  'dfp' => 463, 'mst' => 1500, 'ata_raw' => 1200, 'evp' => 679, 'lck' => 100]],
        8  => ['name' => 'FOnewearl', 'ata_base' => 60, 'hp_mult' => 1.45, 'tp_mult' => 1.5, 'cast' => false, 'max' => ['atp' => 580,  'dfp' => 390, 'mst' => 1750, 'ata_raw' => 1260, 'evp' => 883, 'lck' => 100]],
        9  => ['name' => 'HUcaseal',  'ata_base' => 68, 'hp_mult' => 2.00, 'tp_mult' => 0.0, 'cast' => true,  'max' => ['atp' => 1291, 'dfp' => 525, 'mst' => 0,    'ata_raw' => 1500, 'evp' => 877, 'lck' => 100]],
        10 => ['name' => 'FOmar',     'ata_base' => 62, 'hp_mult' => 1.45, 'tp_mult' => 1.5, 'cast' => false, 'max' => ['atp' => 999,  'dfp' => 470, 'mst' => 1340, 'ata_raw' => 1010, 'evp' => 651, 'lck' => 100]],
        11 => ['name' => 'RAmarl',    'ata_base' => 68, 'hp_mult' => 1.85, 'tp_mult' => 1.0, 'cast' => false, 'max' => ['atp' => 1140, 'dfp' => 577, 'mst' => 1031, 'ata_raw' => 1730, 'evp' => 900, 'lck' => 100]],
    ];
}

function resolve_psobb_class_id($classNameOrId): int {
    if (is_int($classNameOrId) || (is_string($classNameOrId) && ctype_digit($classNameOrId))) {
        $cid = (int)$classNameOrId;
        return ($cid >= 0 && $cid <= 11) ? $cid : 0;
    }
    $map = [
        'humar' => 0, 'hunewearl' => 1, 'hucast' => 2, 'ramar' => 3,
        'racast' => 4, 'racaseal' => 5, 'fomarl' => 6, 'fonewm' => 7,
        'fonewearl' => 8, 'hucaseal' => 9, 'fomar' => 10, 'ramarl' => 11,
    ];
    $key = strtolower(trim((string)$classNameOrId));
    return $map[$key] ?? 0;
}

function psobb_tech_element(int $techNum): string {
    if ($techNum <= 2) return 'fire';     // Foie, Gifoie, Rafoie
    if ($techNum <= 5) return 'ice';      // Barta, Gibarta, Rabarta
    if ($techNum <= 8) return 'bolt';     // Zonde, Gizonde, Razonde
    if ($techNum === 9) return 'light';   // Grants
    if ($techNum === 18) return 'dark';   // Megid
    return 'support';                     // Deband, Jellen, Zalure, Shifta, Ryuker, Resta, Anti, Reverser
}

function enrich_psobb_item(array $item, array $tbl): array {
    if (!isset($item['group'])) {
        return $item;
    }
    $group = (int)$item['group'];
    $type1 = (int)($item['type1'] ?? 0);
    $type2 = (int)($item['type2'] ?? 0);
    $lookupKey = sprintf('%02x%02x%02x', $group, $type1, $type2);

    $hexClean = preg_replace('/[^0-9a-fA-F]/', '', (string)($item['raw'] ?? ($item['hex'] ?? '')));
    $b4 = (strlen($hexClean) >= 10) ? hexdec(substr($hexClean, 8, 2)) : 0;

    $stars = 0;
    $isRare = false;

    if ($group === 0x00) {
        $w = $tbl['weapons'][$lookupKey] ?? null;
        if ($w) {
            $item['weapon_kind'] = (int)$w['kind'];
            $item['wpn_atp_min'] = (int)$w['atp_min'];
            $item['wpn_atp_max'] = (int)$w['atp_max'];
            $item['wpn_ata'] = (int)$w['ata'];
            $item['wpn_mst'] = (int)$w['mst'];
            $stars = (int)$w['stars'];
            $isUnid = !empty($item['unidentified']) || (($b4 & 0x80) !== 0);
            if ($stars < 9 && !$isUnid) {
                $sp = (string)($b4 & 0x3F);
                $stars += (int)($tbl['special_stars'][$sp] ?? 0);
            }
            $isRare = ($w['stars'] >= 9) || ($stars >= 9);
        }
    } elseif ($group === 0x01) {
        if ($type1 === 1) {
            $a = $tbl['armors'][$lookupKey] ?? null;
            if ($a) {
                $stars = (int)$a['stars'];
                $item['armor_dfp'] = (int)$a['dfp'];
                $item['armor_evp'] = (int)$a['evp'];
            }
        } elseif ($type1 === 2) {
            $s = $tbl['shields'][$lookupKey] ?? null;
            if ($s) {
                $stars = (int)$s['stars'];
                $item['shield_dfp'] = (int)$s['dfp'];
                $item['shield_evp'] = (int)$s['evp'];
            }
        } elseif ($type1 === 3) {
            $u = $tbl['units'][$lookupKey] ?? null;
            if ($u) {
                $stars = (int)$u['stars'];
                $mod = (int)($item['modifier'] ?? 0);
                if ($mod < 0) {
                    $stars = max(0, $stars - 1);
                } elseif ($mod > 0) {
                    $stars++;
                }
            }
        }
        $isRare = ($stars >= 9);
    } elseif ($group === 0x02) {
        $magKey = sprintf('02%02x', $type1);
        $stars = (int)($tbl['mags'][$magKey]['stars'] ?? (($type1 >= 0x28) ? 12 : 0));
        $isRare = ($stars >= 9);
    } elseif ($group === 0x03) {
        if ($type1 === 2) {
            $techNum = (strlen($hexClean) >= 10) ? $b4 : 0;
            $item['tech_num'] = $techNum;
            $item['tech_level'] = $type2 + 1;
            $item['tech_element'] = psobb_tech_element($techNum);
            $toolKey = sprintf('0302%02x', $techNum);
        } else {
            $toolKey = $lookupKey;
        }
        $t = $tbl['tools'][$toolKey] ?? null;
        if ($t) {
            $stars = (int)$t['stars'];
        }
        $isRare = ($stars >= 9);
    }

    $item['stars'] = $stars;
    $item['is_rare'] = $isRare;
    return $item;
}

function apply_psobb_stat_boost(int $sbIdx, array $tbl, array &$stats, bool $isCast): void {
    if ($sbIdx <= 0 || empty($tbl['stat_boosts'][$sbIdx])) {
        return;
    }
    [$stat, $amt] = $tbl['stat_boosts'][$sbIdx];
    if ($stat === 0 || $amt === 0) {
        return;
    }
    switch ($stat) {
        case 0x01: $stats['atp'] += $amt; break;
        case 0x02: $stats['ata_tenths'] += $amt * 10; break;
        case 0x03: $stats['evp'] += $amt; break;
        case 0x04: $stats['dfp'] += $amt; break;
        case 0x05: if (!$isCast) $stats['mst'] += $amt; break;
        case 0x06: $stats['hp'] += $amt; break;
        case 0x07: $stats['lck'] += $amt; break;
        case 0x08:
            $stats['atp'] += $amt;
            $stats['ata_tenths'] += $amt * 10;
            $stats['evp'] += $amt;
            $stats['dfp'] += $amt;
            if (!$isCast) $stats['mst'] += $amt;
            $stats['lck'] += $amt;
            break;
        case 0x09: $stats['atp'] -= $amt; break;
        case 0x0A: $stats['ata_tenths'] -= $amt * 10; break;
        case 0x0B: $stats['evp'] -= $amt; break;
        case 0x0C: $stats['dfp'] -= $amt; break;
        case 0x0D: if (!$isCast) $stats['mst'] -= $amt; break;
        case 0x0E: $stats['hp'] -= $amt; break;
        case 0x0F: $stats['lck'] -= $amt; break;
        case 0x10:
            $stats['atp'] -= $amt;
            $stats['ata_tenths'] -= $amt * 10;
            $stats['evp'] -= $amt;
            $stats['dfp'] -= $amt;
            if (!$isCast) $stats['mst'] -= $amt;
            $stats['lck'] -= $amt;
            break;
    }
}

function compute_psobb_combat_stats(array $char, array $tbl): array {
    $classes = get_psobb_class_tables();
    $cid = resolve_psobb_class_id($char['class_id'] ?? ($char['class'] ?? 0));
    $cls = $classes[$cid];
    $max = $cls['max'];
    $isCast = $cls['cast'];

    $level = max(1, (int)($char['level'] ?? 1));
    $raw = $char['stats'] ?? [];
    $mats = $char['mats'] ?? [];

    $rawAtp = (int)($raw['ATP'] ?? 0);
    $rawDfp = (int)($raw['DFP'] ?? 0);
    $rawMst = $isCast ? 0 : (int)($raw['MST'] ?? 0);
    $rawAta = (int)($raw['ATA'] ?? 0);
    $rawEvp = (int)($raw['EVP'] ?? 0);
    $rawLck = (int)($raw['LCK'] ?? 10);
    $rawHp  = (int)($raw['HP'] ?? 0);

    $hpMats = max(0, (int)($mats['hp'] ?? 0));
    $tpMats = max(0, (int)($mats['tp'] ?? 0));

    // Character base stats already include Power/Mind/Evade/Def/Luck materials in PlayerDispDataV4.
    // Clamp non-GM stats to class max; preserve intentionally GM-edited >max values.
    $clampOrKeep = static function (int $val, int $cap): int {
        return ($val <= $cap) ? max(0, $val) : $val;
    };

    $baseAtp = $clampOrKeep($rawAtp, $max['atp']);
    $baseDfp = $clampOrKeep($rawDfp, $max['dfp']);
    $baseMst = $isCast ? 0 : $clampOrKeep($rawMst, $max['mst']);
    $baseAtaRaw = $clampOrKeep($rawAta, $max['ata_raw']);
    $baseAtaTenths = ($cls['ata_base'] * 10) + $baseAtaRaw;
    $baseEvp = $clampOrKeep($rawEvp, $max['evp']);
    $baseLck = $clampOrKeep($rawLck, $max['lck']);

    $baseHp = (int)floor(($rawHp + max(0, $level - 1)) * $cls['hp_mult']) + ($hpMats * 2);
    $baseTp = $isCast
        ? 0
        : ((int)floor(($baseMst + max(0, $level - 1)) * $cls['tp_mult']) + ($tpMats * 2));

    $baseStats = [
        'ATP' => $baseAtp,
        'DFP' => $baseDfp,
        'MST' => $baseMst,
        'ATA' => (int)floor($baseAtaTenths / 10),
        'ATA_tenths' => $baseAtaTenths,
        'EVP' => $baseEvp,
        'LCK' => $baseLck,
        'HP'  => max(0, $baseHp),
        'TP'  => max(0, $baseTp),
    ];

    $charMagUnit = [
        'atp'        => $baseAtp,
        'dfp'        => $baseDfp,
        'mst'        => $baseMst,
        'ata_tenths' => $baseAtaTenths,
        'evp'        => $baseEvp,
        'lck'        => $baseLck,
        'hp'         => max(0, $baseHp),
        'tp'         => max(0, $baseTp),
    ];

    $equipped = [];
    foreach (($char['inventory'] ?? []) as $item) {
        if (!empty($item['equipped'])) {
            $equipped[] = $item;
        }
    }

    // 1) Mag + Units (subject to class MaxStats except HP/TP)
    foreach ($equipped as $item) {
        $group = (int)($item['group'] ?? -1);
        $type1 = (int)($item['type1'] ?? 0);
        $type2 = (int)($item['type2'] ?? 0);
        $lookupKey = sprintf('%02x%02x%02x', $group, $type1, $type2);

        if ($group === 0x02 && !empty($item['mag_stats'])) {
            $ms = $item['mag_stats'];
            $mDef  = (int)floor((float)($ms['def'] ?? 0));
            $mPow  = (int)floor((float)($ms['pow'] ?? 0));
            $mDex  = (int)floor((float)($ms['dex'] ?? 0));
            $mMind = (int)floor((float)($ms['mind'] ?? 0));

            $charMagUnit['atp'] += $mPow * 2;
            $charMagUnit['dfp'] += $mDef;
            if (!$isCast) {
                $charMagUnit['mst'] += $mMind * 2;
            }
            $charMagUnit['ata_tenths'] += $mDex * 5;
        } elseif ($group === 0x01 && $type1 === 3) {
            $u = $tbl['units'][$lookupKey] ?? null;
            if ($u) {
                $mod = max(-2, min(2, (int)($item['modifier'] ?? 0)));
                $eff = (int)$u['amt'] + ($mod * (int)$u['mod']);
                switch ((int)$u['stat']) {
                    case 0: $charMagUnit['atp'] += $eff; break;
                    case 1: if (!$isCast) $charMagUnit['mst'] += $eff; break;
                    case 2: $charMagUnit['ata_tenths'] += $eff * 10; break;
                    case 3: $charMagUnit['evp'] += $eff; break;
                    case 4: $charMagUnit['hp'] += $eff; break;
                    case 5: if (!$isCast) $charMagUnit['tp'] += $eff; break;
                    case 6: $charMagUnit['dfp'] += $eff; break;
                    case 7: $charMagUnit['lck'] += $eff; break;
                    case 8:
                        $charMagUnit['atp'] += $eff;
                        $charMagUnit['dfp'] += $eff;
                        $charMagUnit['evp'] += $eff;
                        $charMagUnit['lck'] += $eff;
                        $charMagUnit['ata_tenths'] += $eff; // +10 -> +1.0 ATA, +15 -> +1.5 ATA
                        if (!$isCast) $charMagUnit['mst'] += $eff;
                        break;
                }
            }
        }
    }

    // Cap Character+Mag+Units to class max unless Base was already GM >max
    $maxAtaTenths = ($cls['ata_base'] * 10) + $max['ata_raw'];
    if ($rawAtp <= $max['atp']) $charMagUnit['atp'] = min($charMagUnit['atp'], $max['atp']);
    if ($rawDfp <= $max['dfp']) $charMagUnit['dfp'] = min($charMagUnit['dfp'], $max['dfp']);
    if ($rawMst <= $max['mst']) $charMagUnit['mst'] = min($charMagUnit['mst'], $max['mst']);
    if ($rawAta <= $max['ata_raw']) $charMagUnit['ata_tenths'] = min($charMagUnit['ata_tenths'], $maxAtaTenths);
    if ($rawEvp <= $max['evp']) $charMagUnit['evp'] = min($charMagUnit['evp'], $max['evp']);
    if ($rawLck <= $max['lck']) $charMagUnit['lck'] = min($charMagUnit['lck'], $max['lck']);

    $total = $charMagUnit;

    // 2) Equipped Weapon, Armor, Shield + StatBoosts (applied on top of Mag+Unit caps, like Red Ring)
    foreach ($equipped as $item) {
        $group = (int)($item['group'] ?? -1);
        $type1 = (int)($item['type1'] ?? 0);
        $type2 = (int)($item['type2'] ?? 0);
        $lookupKey = sprintf('%02x%02x%02x', $group, $type1, $type2);

        if ($group === 0x00) {
            $w = $tbl['weapons'][$lookupKey] ?? null;
            if ($w) {
                $grind = max(0, (int)($item['grind'] ?? 0));
                $total['atp'] += (int)$w['atp_max'] + (2 * $grind);
                $total['ata_tenths'] += (int)$w['ata'] * 10;
                if (!$isCast) {
                    $total['mst'] += (int)$w['mst'];
                }
                if (!empty($w['sb'])) {
                    apply_psobb_stat_boost((int)$w['sb'], $tbl, $total, $isCast);
                }
            }
        } elseif ($group === 0x01 && $type1 === 1) {
            $a = $tbl['armors'][$lookupKey] ?? null;
            if ($a) {
                $total['dfp'] += (int)$a['dfp'] + (int)($item['def_bonus'] ?? 0);
                $total['evp'] += (int)$a['evp'] + (int)($item['evp_bonus'] ?? 0);
                if (!empty($a['sb'])) {
                    apply_psobb_stat_boost((int)$a['sb'], $tbl, $total, $isCast);
                }
            }
        } elseif ($group === 0x01 && $type1 === 2) {
            $s = $tbl['shields'][$lookupKey] ?? null;
            if ($s) {
                $total['dfp'] += (int)$s['dfp'] + (int)($item['def_bonus'] ?? 0);
                $total['evp'] += (int)$s['evp'] + (int)($item['evp_bonus'] ?? 0);
                if (!empty($s['sb'])) {
                    apply_psobb_stat_boost((int)$s['sb'], $tbl, $total, $isCast);
                }
            }
        }
    }

    if ($isCast) {
        $total['mst'] = 0;
        $total['tp'] = 0;
    }
    if ($rawLck <= 100) {
        $total['lck'] = max(0, min($total['lck'], 100));
    }

    $totalStats = [
        'ATP' => max(0, (int)$total['atp']),
        'DFP' => max(0, (int)$total['dfp']),
        'MST' => max(0, (int)$total['mst']),
        'ATA' => max(0, (int)floor($total['ata_tenths'] / 10)),
        'ATA_tenths' => max(0, (int)$total['ata_tenths']),
        'EVP' => max(0, (int)$total['evp']),
        'LCK' => max(0, (int)$total['lck']),
        'HP'  => max(0, (int)$total['hp']),
        'TP'  => max(0, (int)$total['tp']),
    ];

    $maxStats = [
        'ATP' => $max['atp'],
        'DFP' => $max['dfp'],
        'MST' => $max['mst'],
        'ATA' => (int)floor($maxAtaTenths / 10),
        'ATA_tenths' => $maxAtaTenths,
        'EVP' => $max['evp'],
        'LCK' => $max['lck'],
    ];

    return [
        'stats'      => $totalStats,
        'base_stats' => $baseStats,
        'max_stats'  => $maxStats,
    ];
}

function finalize_psobb_character_data(array &$character): void {
    $tbl = load_psobb_item_stats_table();
    if (!$tbl) {
        return;
    }

    if (!empty($character['inventory']) && is_array($character['inventory'])) {
        foreach ($character['inventory'] as $idx => $item) {
            if (is_array($item)) {
                $character['inventory'][$idx] = enrich_psobb_item($item, $tbl);
            }
        }
    }

    foreach (['bank', 'shared_bank'] as $bankKey) {
        if (!empty($character[$bankKey]['items']) && is_array($character[$bankKey]['items'])) {
            foreach ($character[$bankKey]['items'] as $idx => $item) {
                if (is_array($item)) {
                    $character[$bankKey]['items'][$idx] = enrich_psobb_item($item, $tbl);
                }
            }
        }
    }

    $computed = compute_psobb_combat_stats($character, $tbl);
    $character['stats'] = $computed['stats'];
    $character['base_stats'] = $computed['base_stats'];
    $character['max_stats'] = $computed['max_stats'];
}
