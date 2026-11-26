<?php
/**
 * PSOBB Canonical Game Constants & Low-Level Binary/Player Helpers
 *
 * Single source of truth for Blue Burst Class IDs, Section IDs, Technique IDs,
 * Unsealable item limits, Boss Arena floors, DB path resolution, character name
 * decoding, and 20-byte ItemData binary parsing.
 */

if (!defined('PSO_CHAR_CLASSES')) {
    define('PSO_CHAR_CLASSES', [
        0  => 'HUmar',
        1  => 'HUnewearl',
        2  => 'HUcast',
        3  => 'RAmar',
        4  => 'RAcast',
        5  => 'RAcaseal',
        6  => 'FOmarl',
        7  => 'FOnewm',
        8  => 'FOnewearl',
        9  => 'HUcaseal',
        10 => 'FOmar',
        11 => 'RAmarl',
    ]);
}

if (!defined('PSO_HUNTER_CLASSES')) {
    define('PSO_HUNTER_CLASSES', ['HUmar', 'HUnewearl', 'HUcast', 'HUcaseal']);
}
if (!defined('PSO_RANGER_CLASSES')) {
    define('PSO_RANGER_CLASSES', ['RAmar', 'RAmarl', 'RAcast', 'RAcaseal']);
}
if (!defined('PSO_FORCE_CLASSES')) {
    define('PSO_FORCE_CLASSES', ['FOmar', 'FOmarl', 'FOnewm', 'FOnewearl']);
}
if (!defined('PSO_CAST_CLASSES')) {
    define('PSO_CAST_CLASSES', ['HUcast', 'HUcaseal', 'RAcast', 'RAcaseal']);
}

if (!defined('PSO_SECTION_IDS')) {
    define('PSO_SECTION_IDS', [
        0 => 'Viridia',
        1 => 'Greenill',
        2 => 'Skyly',
        3 => 'Bluefull',
        4 => 'Purplenum',
        5 => 'Pinkal',
        6 => 'Redria',
        7 => 'Oran',
        8 => 'Yellowboze',
        9 => 'Whitill',
    ]);
}

if (!defined('PSO_SECTION_IDS_LOWER')) {
    define('PSO_SECTION_IDS_LOWER', [
        'viridia', 'greenill', 'skyly', 'bluefull', 'purplenum',
        'pinkal', 'redria', 'oran', 'yellowboze', 'whitill',
    ]);
}

if (!defined('PSO_TECHNIQUES')) {
    define('PSO_TECHNIQUES', [
        0x00 => 'Foie',
        0x01 => 'Gifoie',
        0x02 => 'Rafoie',
        0x03 => 'Barta',
        0x04 => 'Gibarta',
        0x05 => 'Rabarta',
        0x06 => 'Zonde',
        0x07 => 'Gizonde',
        0x08 => 'Razonde',
        0x09 => 'Grants',
        0x0A => 'Deband',
        0x0B => 'Jellen',
        0x0C => 'Zalure',
        0x0D => 'Shifta',
        0x0E => 'Ryuker',
        0x0F => 'Resta',
        0x10 => 'Anti',
        0x11 => 'Reverser',
        0x12 => 'Megid',
    ]);
}

if (!defined('PSO_UNSEALABLE_WEAPONS')) {
    define('PSO_UNSEALABLE_WEAPONS', ['003300', '00AB00']);
}
if (!defined('PSO_UNSEALABLE_UNITS')) {
    define('PSO_UNSEALABLE_UNITS', ['01034D', '01034F']);
}
if (!defined('PSO_UNSEALABLE_ITEMS')) {
    define('PSO_UNSEALABLE_ITEMS', ['003300', '00AB00', '01034D', '01034F']);
}

if (!defined('PSO_WEAPON_ATTR_NAMES')) {
    define('PSO_WEAPON_ATTR_NAMES', [
        1 => 'Native',
        2 => 'A.Beast',
        3 => 'Machine',
        4 => 'Dark',
        5 => 'Hit',
    ]);
}

if (!defined('PSO_WEAPON_SPECIALS')) {
    define('PSO_WEAPON_SPECIALS', [
        1  => 'Draw',    2  => 'Drain',   3  => 'Fill',    4  => 'Gush',
        5  => 'Heart',   6  => 'Mind',    7  => 'Soul',    8  => 'Geist',
        9  => 'Masters', 10 => 'Lords',   11 => 'Kings',   12 => 'Charge',
        13 => 'Spirit',  14 => 'Berserk', 15 => 'Ice',     16 => 'Frost',
        17 => 'Freeze',  18 => 'Blizzard',19 => 'Bind',    20 => 'Hold',
        21 => 'Seize',   22 => 'Arrest',  23 => 'Heat',    24 => 'Fire',
        25 => 'Flame',   26 => 'Burning', 27 => 'Shock',   28 => 'Thunder',
        29 => 'Storm',   30 => 'Tempest', 31 => 'Dim',     32 => 'Shadow',
        33 => 'Dark',    34 => 'Hell',    35 => 'Panic',   36 => 'Riot',
        37 => 'Havoc',   38 => 'Chaos',   39 => "Devil's", 40 => "Demon's",
    ]);
}

if (!defined('PSO_EP1_FLOORS')) {
    define('PSO_EP1_FLOORS', [
        1  => 'Forest 1',
        2  => 'Forest 2',
        3  => 'Cave 1',
        4  => 'Cave 2',
        5  => 'Cave 3',
        6  => 'Mine 1',
        7  => 'Mine 2',
        8  => 'Ruins 1',
        9  => 'Ruins 2',
        10 => 'Ruins 3',
    ]);
}

if (!defined('PSO_EP2_FLOORS')) {
    define('PSO_EP2_FLOORS', [
        1  => 'VR Temple Alpha',
        2  => 'VR Temple Beta',
        3  => 'VR Spaceship Alpha',
        4  => 'VR Spaceship Beta',
        5  => 'Central Control Area',
        6  => 'Jungle North',
        7  => 'Jungle East',
        8  => 'Mountain',
        9  => 'Seaside',
        10 => 'Seabed Upper',
        11 => 'Seabed Lower',
    ]);
}

if (!defined('PSO_EP4_FLOORS')) {
    define('PSO_EP4_FLOORS', [
        1 => 'Crater East',
        2 => 'Crater West',
        3 => 'Crater South',
        4 => 'Crater North',
        5 => 'Crater Interior',
        6 => 'Subterranean Desert 1',
        7 => 'Subterranean Desert 2',
        8 => 'Subterranean Desert 3',
    ]);
}

if (!function_exists('pso_episode_floor_map')) {
    /**
     * Field floor ID => display name map for an episode (1, 2, or 4).
     */
    function pso_episode_floor_map(int $episode): array
    {
        if ($episode === 2) {
            return PSO_EP2_FLOORS;
        }
        if ($episode === 4) {
            return PSO_EP4_FLOORS;
        }
        return PSO_EP1_FLOORS;
    }
}

if (!function_exists('pso_floor_display_name')) {
    /**
     * Resolve display name for a field floor in an episode.
     */
    function pso_floor_display_name(int $floor, int $episode = 1): string
    {
        $map = pso_episode_floor_map($episode);
        return $map[$floor] ?? "Floor {$floor}";
    }
}

if (!function_exists('pso_speedrun_valid_next_floors')) {
    /**
     * Valid destination floor IDs considered a genuine clear of $fromFloor in $episode.
     */
    function pso_speedrun_valid_next_floors(int $episode, int $fromFloor): array
    {
        if ($episode === 2) {
            $edges = [
                1  => [2],
                2  => [3, 14],
                3  => [4],
                4  => [5, 6, 15],
                5  => [6, 7, 8, 9, 10, 12, 17],
                6  => [5, 7],
                7  => [5, 8],
                8  => [5, 9],
                9  => [5, 12, 16],
                10 => [11],
                11 => [13],
            ];
            return $edges[$fromFloor] ?? [$fromFloor + 1];
        }
        if ($episode === 4) {
            $edges = [
                1 => [2, 3, 4, 5],
                2 => [1, 3, 4, 5],
                3 => [1, 2, 4, 5],
                4 => [1, 2, 3, 5],
                5 => [6],
                6 => [7],
                7 => [8],
                8 => [9],
            ];
            return $edges[$fromFloor] ?? [$fromFloor + 1];
        }
        $edges = [
            1  => [2],
            2  => [3, 11, 14],
            3  => [4],
            4  => [5, 11, 15],
            5  => [6, 7, 8, 9, 12],
            6  => [7],
            7  => [8, 13],
            8  => [9, 12],
            9  => [10, 12],
            10 => [11, 14],
            11 => [12, 13],
        ];
        return $edges[$fromFloor] ?? [$fromFloor + 1];
    }
}

if (!function_exists('get_db_path')) {
    /**
     * Resolve SQLite database path from DB_PATH env var or default db/website.db.
     */
    function get_db_path(): string
    {
        return !empty($_ENV['DB_PATH'])
            ? (string) $_ENV['DB_PATH']
            : (getenv('DB_PATH') ?: (dirname(__DIR__) . '/db/website.db'));
    }
}

if (!function_exists('pso_resolve_class_name')) {
    /**
     * Normalize either a numeric newserv Class ID or string CharClass to canonical class name.
     */
    function pso_resolve_class_name($raw, string $default = 'HUmar'): string
    {
        if (is_string($raw) && !is_numeric($raw)) {
            $trimmed = trim($raw);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
        if ($raw !== null && $raw !== '' && is_numeric($raw)) {
            $idx = (int) $raw;
            if (isset(PSO_CHAR_CLASSES[$idx])) {
                return PSO_CHAR_CLASSES[$idx];
            }
        }
        return $default;
    }
}

if (!function_exists('pso_class_archetype')) {
    /**
     * Return 'HU', 'RA', or 'FO' for a numeric Class ID or string CharClass, or null if unknown.
     */
    function pso_class_archetype($raw): ?string
    {
        $name = pso_resolve_class_name($raw, '');
        if ($name === '') {
            return null;
        }
        $prefix = strtoupper(substr($name, 0, 2));
        return in_array($prefix, ['HU', 'RA', 'FO'], true) ? $prefix : null;
    }
}

if (!function_exists('pso_tech_map_lower')) {
    /**
     * Cached lowercase technique name -> ID map derived from PSO_TECHNIQUES.
     */
    function pso_tech_map_lower(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (PSO_TECHNIQUES as $id => $name) {
                $map[strtolower($name)] = $id;
            }
        }
        return $map;
    }
}

if (!function_exists('pso_weapon_special_map_lower')) {
    /**
     * Cached lowercase weapon special name -> ID map derived from PSO_WEAPON_SPECIALS.
     */
    function pso_weapon_special_map_lower(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (PSO_WEAPON_SPECIALS as $id => $name) {
                $map[strtolower($name)] = $id;
            }
        }
        return $map;
    }
}

if (!function_exists('pso_boss_arena_floors')) {
    /**
     * Boss arena floor IDs for an episode (1, 2, or 4).
     */
    function pso_boss_arena_floors($episode, bool $require_known_episode = false): array
    {
        switch ((int) $episode) {
            case 1:
                return [11, 12, 13, 14]; // Dragon, De Rol Le, Vol Opt, Dark Falz
            case 2:
                return [12, 13, 14, 15]; // Gal Gryphon, Olga Flow, Barba Ray, Gol Dragon
            case 4:
                return [9];              // Saint-Milion / Shambertin / Kondrieu
            default:
                return $require_known_episode ? [] : [9, 11, 12, 13, 14, 15];
        }
    }
}

if (!function_exists('decode_psochar_name')) {
    /**
     * Decodes a PSOBB character name from raw .psochar binary data (offset 0x3C8 / 968, 32 bytes),
     * matching NewServ's pstring<TextEncoding::UTF16_ALWAYS_MARKED, 0x10>::decode():
     *   - Scans 2 bytes at a time up to the first UTF-16LE NUL code unit (\x00\x00).
     *   - Converts UTF-16LE -> UTF-8.
     *   - Strips any 2-byte "\t<lang>" prefix (E/J/B/T/K/etc.) while preserving "\tC" color codes.
     */
    function decode_psochar_name(string $charData, bool $trim = true): string
    {
        if (strlen($charData) < 0x3C8 + 0x20) {
            return '';
        }
        $raw = substr($charData, 0x3C8, 0x20);
        $byteLen = 0x20;
        for ($z = 0; $z < 0x20; $z += 2) {
            if ($raw[$z] === "\x00" && $raw[$z + 1] === "\x00") {
                $byteLen = $z;
                break;
            }
        }
        if ($byteLen === 0) {
            return '';
        }
        $slice = substr($raw, 0, $byteLen);
        $utf8 = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($slice, 'UTF-8', 'UTF-16LE')
            : @iconv('UTF-16LE', 'UTF-8//IGNORE', $slice);
        if (!is_string($utf8) || $utf8 === '') {
            return '';
        }
        if (strlen($utf8) >= 2 && $utf8[0] === "\t" && $utf8[1] !== 'C' && ord($utf8[1]) < 0x80) {
            $utf8 = substr($utf8, 2);
        }
        return $trim ? trim($utf8, " \r\n\0\x0B") : $utf8;
    }
}

if (!function_exists('pso_parse_item_data')) {
    /**
     * Parse 20-byte binary PSOBB ItemData into structured array matching names-v4.json.
     */
    function pso_parse_item_data(string $bytes): ?array
    {
        if (strlen($bytes) < 20) {
            return null;
        }

        $data1 = substr($bytes, 0, 12);
        $id = unpack('V', substr($bytes, 12, 4))[1];
        $data2 = substr($bytes, 16, 4);

        $group = ord($data1[0]);
        $type1 = ord($data1[1]);
        $type2 = ord($data1[2]);
        $hex = strtoupper(bin2hex($bytes));

        $isSRank = ($group === 0x00) && (($type1 > 0x6F && $type1 < 0x89) || ($type1 > 0xA4 && $type1 < 0xAA));

        if ($group === 0x04) {
            $primaryId = 0x04000000;
        } elseif ($group === 0x03 && $type1 === 0x02) {
            $primaryId = 0x03020000 | (ord($data1[4]) << 8) | $type2;
        } elseif ($group === 0x02) {
            $primaryId = 0x02000000 | ($type1 << 16);
        } elseif ($isSRank) {
            $primaryId = ($group << 24) | ($type1 << 16);
        } else {
            $primaryId = ($group << 24) | ($type1 << 16) | ($type2 << 8);
        }
        $lookupKey = strtolower(substr(sprintf('%08X', $primaryId), 0, 6));

        static $codeToName = null;
        if ($codeToName === null) {
            $codeToName = [];
            $mapPath = __DIR__ . '/names-v4.json';
            if (file_exists($mapPath)) {
                $map = json_decode((string) file_get_contents($mapPath), true);
                if (is_array($map)) {
                    foreach ($map as $code => $name) {
                        $codeToName[strtolower((string) $code)] = $name;
                    }
                }
            }
        }

        $item = [
            'hex'      => $hex,
            'item_id'  => $id,
            'group'    => $group,
            'type1'    => $type1,
            'type2'    => $type2,
            'equipped' => false,
            'name'     => 'Unknown Item',
            'attrs'    => [],
        ];

        if ($group === 0x00) {
            $grind = ord($data1[3]);
            $isUnid = (ord($data1[4]) & 0x80) !== 0;
            $wName = $codeToName[$lookupKey]
                ?? $codeToName[strtolower(sprintf('%02X%02X00', $group, $type1))]
                ?? 'Weapon';
            $item['grind'] = $grind;
            if ($isUnid) {
                $item['unidentified'] = true;
            }
            $item['name'] = ($isUnid ? '???? ' : '') . ucwords($wName) . ($grind > 0 ? " +{$grind}" : '');
            for ($a = 0; $a < 3; $a++) {
                $aType = ord($data1[6 + $a * 2]);
                $aVal = ord($data1[7 + $a * 2]);
                if ($aType > 0 && isset(PSO_WEAPON_ATTR_NAMES[$aType])) {
                    if ($aVal > 127) {
                        $aVal -= 256;
                    }
                    $item['attrs'][] = ['type' => PSO_WEAPON_ATTR_NAMES[$aType], 'value' => $aVal];
                }
            }
        } elseif ($group === 0x01) {
            $aName = $codeToName[$lookupKey] ?? null;
            $item['name'] = $aName
                ? ucwords($aName)
                : match ($type1) {
                    0x01 => 'Armor',
                    0x02 => 'Shield',
                    0x03 => 'Unit',
                    default => 'Armor/Shield',
                };
            if ($type1 === 0x01) {
                $item['slots'] = ord($data1[5]);
                $item['def_bonus'] = unpack('s', substr($data1, 6, 2))[1];
                $item['evp_bonus'] = unpack('s', substr($data1, 8, 2))[1];
            } elseif ($type1 === 0x02) {
                $item['def_bonus'] = unpack('s', substr($data1, 6, 2))[1];
                $item['evp_bonus'] = unpack('s', substr($data1, 8, 2))[1];
            } elseif ($type1 === 0x03) {
                $item['modifier'] = unpack('s', substr($data1, 6, 2))[1];
            }
        } elseif ($group === 0x02) {
            $magName = $codeToName[$lookupKey] ?? 'Mag';
            $item['name'] = ucwords($magName);
            $item['mag_stats'] = [
                'level'    => ord($data1[2]),
                'def'      => round(unpack('v', substr($data1, 4, 2))[1] / 100, 2),
                'pow'      => round(unpack('v', substr($data1, 6, 2))[1] / 100, 2),
                'dex'      => round(unpack('v', substr($data1, 8, 2))[1] / 100, 2),
                'mind'     => round(unpack('v', substr($data1, 10, 2))[1] / 100, 2),
                'synchro'  => ord($data2[0]),
                'iq'       => ord($data2[1]),
                'pb_flags' => ord($data1[3]),
            ];
        } elseif ($group === 0x03) {
            if ($type1 === 0x02) {
                $techLvl = ord($data1[2]) + 1;
                $techNum = ord($data1[4]);
                $techName = PSO_TECHNIQUES[$techNum] ?? 'Technique';
                $item['name'] = "Disk: {$techName} Lv.{$techLvl}";
            } else {
                $tName = $codeToName[$lookupKey] ?? 'Consumable';
                $item['name'] = ucwords($tName);
                $count = ord($data1[5]);
                $item['count'] = $count > 0 ? $count : 1;
            }
        } elseif ($group === 0x04) {
            $item['name'] = 'Meseta';
            $item['count'] = unpack('V', $data2)[1];
        }

        return $item;
    }
}
