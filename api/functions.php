<?php
/**
 * Shared utility functions for the PSOBB platform.
 * Include via require_once from any script that needs these helpers.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Send a Simple Mail (command 0x81) to a connected BB client via newserv's shell-exec API.
 *
 * @param int    $client_acc_id  The target player's account ID (decimal).
 * @param string $from_name      The sender name displayed in the mail (max ~15 chars).
 * @param string $text            The mail body text (max ~511 chars).
 */
function send_personal_mail($client_acc_id, $from_name, $text)
{
    global $NEWSERV_API_URL;

    $date_str = date('Y-m-d H:i:s');

    // Fetch player preferences; default to English and allow sending
    $marker = "\tE";
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT language, receive_system_mail FROM users WHERE account_id = :acc LIMIT 1");
        $stmt->bindValue(':acc', $client_acc_id, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($row && isset($row['receive_system_mail']) && (int) $row['receive_system_mail'] === 0) {
            return; // Player has opted out of system mails.
        }
        if ($row && isset($row['language'])) {
            $lang = strtolower(trim((string)$row['language']));
            if ($lang === 'jp' || $lang === 'ja') {
                $marker = "\tJ";
            } elseif ($lang === 'zh' || $lang === 'zh-cn' || $lang === 'cn') {
                $marker = "\tB";
            } elseif ($lang === 'zh-tw' || $lang === 'zh-hk' || $lang === 'tw') {
                $marker = "\tT";
            } elseif ($lang === 'ko' || $lang === 'kr') {
                $marker = "\tK";
            }
        }
    } catch (Exception $e) {
        // Fallback to English on DB error
    }

    // NewServ uses \tE/\tJ/\tB/\tT/\tK as the language marker for from_name (UTF16_ALWAYS_MARKED)
    $from_name = $marker . trim($from_name);
    $text = trim($text);

    // UTF-16LE encoding helper — tries the fastest available method
    $to_utf16 = function (string $s) {
        if (function_exists('mb_convert_encoding'))
            return mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        if (function_exists('iconv'))
            return iconv('UTF-8', 'UTF-16LE', $s);
        return preg_replace('/(.)/s', "$1\x00", $s); // ASCII-only fallback
    };

    $from_utf16 = (string) $to_utf16($from_name);
    $date_utf16 = (string) $to_utf16($date_str);
    $text_utf16 = (string) $to_utf16($text);

    // 8-byte header (size=0x0458, cmd=0x81, flag=0x00010000) + 1104 zero bytes = 1112 total
    $packet = pack('vvV', 0x0458, 0x0081, 0x00010000) . str_repeat("\x00", 1104);

    $packet = substr_replace($packet, str_pad(substr($from_utf16, 0, 30), 30, "\x00"), 12, 30); // from_name
    $packet = substr_replace($packet, pack('V', $client_acc_id), 44, 4); // to_guild_card_number
    $packet = substr_replace($packet, str_pad(substr($date_utf16, 0, 38), 38, "\x00"), 48, 38); // received_date
    $packet = substr_replace($packet, str_pad(substr($text_utf16, 0, 1022), 1022, "\x00"), 88, 1022); // text

    newserv_shell_exec('on ' . $client_acc_id . ' sc ' . bin2hex($packet));
}

/**
 * Translates underlying backend mission goal types and targets into human-readable descriptions.
 * This is used strictly for rendering objectives gracefully on the frontend UI layout.
 *
 * @param string $type The category of the objective (e.g., 'ITEM', 'BOSS_ARENA').
 * @param mixed $target The specific numerical ID or string required to satisfy the goal.
 * @param string $title The title of the mission for context disambiguation.
 * @param string $desc The description of the mission for context disambiguation.
 * @return string A fully formatted, user-friendly description of what the player must do.
 */
/**
 * Splits a boss goal_target into its parts.
 *
 * Boss targets are a newserv floor ID, optionally followed by "@<episode>" (since the
 * lore-grounded generator, so Ep1 and Ep2 bosses that share a floor ID are never confused)
 * and, for SPEEDRUN_BOSS, "_<seconds>". Examples: "13", "13@2", "14@1_600".
 *
 * @return array ['floor' => int, 'episode' => int|null, 'time_limit' => int|null]
 */
function parse_boss_target($target)
{
    $out = ['floor' => 0, 'episode' => null, 'time_limit' => null];
    if (preg_match('/^\s*(\d+)(?:@([124]))?(?:_(\d+))?\s*$/', (string) $target, $mm)) {
        $out['floor'] = (int) $mm[1];
        if (isset($mm[2]) && $mm[2] !== '') {
            $out['episode'] = (int) $mm[2];
        }
        if (isset($mm[3]) && $mm[3] !== '') {
            $out['time_limit'] = (int) $mm[3];
        }
    } else {
        $out['floor'] = (int) $target;
    }
    return $out;
}

/**
 * Builds a boss goal_target string (see parse_boss_target()).
 */
function make_boss_target($floor, $episode = null, $time_limit = null)
{
    $s = (string) (int) $floor;
    if ($episode !== null) {
        $s .= '@' . (int) $episode;
    }
    if ($time_limit !== null) {
        $s .= '_' . (int) $time_limit;
    }
    return $s;
}

/**
 * True if $text contains any of $terms. ASCII terms must match as whole words (so "gal"
 * does not match "legal" and "ray" does not match "betrayal"); other terms (Japanese)
 * match as substrings.
 */
function text_has_any_term($text, array $terms)
{
    foreach ($terms as $term) {
        if (preg_match('/^[\x20-\x7E]+$/', $term)) {
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $text)) {
                return true;
            }
        } elseif (mb_strpos($text, $term) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Works out which episode a boss floor belongs to. Ep1 and Ep2 reuse floor IDs 12-14.
 *
 * New bounties carry the episode explicitly in their goal_target ("13@2"); pass it as
 * $explicit_episode and it wins. Older bounties fall back to keywords in the title and
 * description, matched as whole words.
 */
function get_boss_episode_by_context($title, $desc, $default_floor, $explicit_episode = null)
{
    if (in_array((int) $explicit_episode, [1, 2, 4], true)) {
        return (int) $explicit_episode;
    }

    $text = mb_strtolower($title . ' ' . $desc);
    $default_floor = (int) $default_floor;

    // Olga Flow (Ep2) vs Vol Opt (Ep1)
    if ($default_floor === 13) {
        return text_has_any_term($text, ['olga', 'olga flow', 'seabed', 'フロウ', '海底']) ? 2 : 1;
    }

    // Gal Gryphon (Ep2) vs De Rol Le (Ep1)
    if ($default_floor === 12) {
        return text_has_any_term($text, ['gryphon', 'gal gryphon', 'gal da val', 'central control area', 'cca', 'jungle', 'mountain', 'seaside', 'グリフォン', '中央管理区', '高山', '海岸', 'ジャングル']) ? 2 : 1;
    }

    // Barba Ray (Ep2) vs Dark Falz (Ep1)
    if ($default_floor === 14) {
        return text_has_any_term($text, ['barba', 'barba ray', 'vr temple', 'temple alpha', 'temple beta', 'バルバレイ', '神殿']) ? 2 : 1;
    }

    // Gol Dragon (Ep2)
    if ($default_floor === 15) {
        return 2;
    }

    // Saint-Milion (Ep4)
    if ($default_floor === 9) {
        return 4;
    }

    // Forest Dragon & Sil Dragon are both Episode 1 Forest
    return 1;
}

/**
 * Display name of the boss on a floor in an episode, as used by getClearObjective().
 * Floor 11 shows "Sil Dragon" when the bounty text names it.
 */
function boss_display_name($target_floor, $ep, $title = '', $desc = '')
{
    $ep = (string) $ep;
    switch ((int) $target_floor) {
        case 11:
            $text = mb_strtolower($title . ' ' . $desc);
            return text_has_any_term($text, ['sil dragon', 'sil', 'シル']) ? 'Sil Dragon (Forest)' : 'Dragon (Forest)';
        case 12:
            return ($ep === '2') ? 'Gal Gryphon (CCA)' : 'De Rol Le (Caves)';
        case 13:
            return ($ep === '2') ? 'Olga Flow (Seabed)' : 'Vol Opt (Mines)';
        case 14:
            return ($ep === '2') ? 'Barba Ray (Temple)' : 'Dark Falz (Ruins)';
        case 15:
            return 'Gol Dragon (Spaceship)';
        case 9:
            return 'Saint-Milion (Crater)';
    }
    return "Boss at Floor $target_floor";
}

// ---------------------------------------------------------------------------
// Weekly objectives of the Bounty System Rework (see api/bounty_system.php)
// ---------------------------------------------------------------------------

function bounty_episode_floors($episode)
{
    if ($episode == 2) return range(1, 11);  // VR Temple, VR Spaceship, CCA, Jungle, Mountain, Seaside, Seabed
    if ($episode == 4) return range(1, 8);   // Crater routes and interior, Subterranean Desert
    return range(1, 10);                     // Forest, Caves, Mines, Ruins
}

if (!function_exists('get_bb_item_name_maps')) {
    /**
     * Loads the canonical PSO BB V4 item table from names-v4.json and builds
     * cached uppercase hex->name and lowercase name->hex lookup maps.
     * Excludes placeholder entries ('????', '<TECH-DISK>') so broken items
     * can never be generated or resolved from legacy V1/V2 tables.
     */
    function get_bb_item_name_maps(): array
    {
        static $maps = null;
        if ($maps !== null) {
            return $maps;
        }

        $hexToName = [];
        $nameToHex = [];
        $namesPath = __DIR__ . '/names-v4.json';
        if (file_exists($namesPath)) {
            $raw = json_decode((string)file_get_contents($namesPath), true);
            if (is_array($raw)) {
                foreach ($raw as $hex => $name) {
                    $hexUpper = strtoupper(trim((string)$hex));
                    $cleanName = trim((string)$name);
                    if (
                        strlen($hexUpper) !== 6 ||
                        !ctype_xdigit($hexUpper) ||
                        $cleanName === '' ||
                        $cleanName === '????' ||
                        $cleanName === '<TECH-DISK>' ||
                        stripos($cleanName, '!ID:') === 0
                    ) {
                        continue;
                    }
                    $hexToName[$hexUpper] = $cleanName;

                    $normName = strtolower($cleanName);
                    // Prefer non-Mag Tool IDs when Mag and Cell Tool share a name (e.g. Cell of MAG 0503..0507).
                    if (
                        !isset($nameToHex[$normName]) ||
                        (str_starts_with($nameToHex[$normName], '02') && str_starts_with($hexUpper, '03'))
                    ) {
                        $nameToHex[$normName] = $hexUpper;
                    }
                }
            }
        }

        // Known safe aliases used across reward/streak generators
        $aliases = [
            'heart of opa-opa' => '030C03',
            'yahoo! mag'       => '024A00',
            'amities memo'     => '031805',
            'yahoo!s engine'   => '031808',
        ];
        foreach ($aliases as $alias => $aliasHex) {
            if (isset($hexToName[$aliasHex])) {
                $nameToHex[$alias] = $aliasHex;
            }
        }

        $maps = [
            'hex_to_name' => $hexToName,
            'name_to_hex' => $nameToHex,
        ];
        return $maps;
    }
}

if (!function_exists('decode_pso_tech_disk_hex')) {
    /**
     * Decodes a PSOBB Tech Disk hex payload (0302....) into 'Disk:<Name> Lv.<N>'.
     * Handles both native BB layout (byte 2 = level-1, byte 4 = tech 0..18) and
     * legacy swapped payloads (byte 2 = tech 0..18, byte 4 = level-1) gracefully.
     */
    function decode_pso_tech_disk_hex(string $hex): ?string
    {
        $hex = strtoupper(trim($hex));
        if (strpos($hex, '0302') !== 0 || strlen($hex) < 10 || !ctype_xdigit(substr($hex, 0, 10))) {
            return null;
        }
        static $techNames = [
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
        ];

        $byte2 = hexdec(substr($hex, 4, 2));
        $byte4 = hexdec(substr($hex, 8, 2));
        if ($byte4 <= 18 && $byte2 <= 29 && isset($techNames[$byte4])) {
            return 'Disk:' . $techNames[$byte4] . ' Lv.' . ($byte2 + 1);
        }
        // Fallback for unmigrated legacy swapped rows (e.g. 030209001D...)
        if ($byte4 > 18 && $byte4 <= 29 && $byte2 <= 18 && isset($techNames[$byte2])) {
            return 'Disk:' . $techNames[$byte2] . ' Lv.' . ($byte4 + 1);
        }
        return null;
    }
}

if (!function_exists('count_cleared_challenge_stages')) {
    /**
     * Count cleared online Challenge Mode stages (Ep1 1C1-1C9 + Ep2 2C1-2C5 = 14 max)
     * from a newserv /y/clients entry. newserv always emits 9 + 5 fixed array slots
     * where 0xFFFF (65535) denotes an uncleared stage and 1..65534 is a valid clear time.
     */
    function count_cleared_challenge_stages(array $client): int
    {
        $times = array_merge(
            $client['ChallengeTimesEp1Online'] ?? [],
            $client['ChallengeTimesEp2Online'] ?? []
        );
        $cleared = 0;
        foreach ($times as $t) {
            $val = (int)$t;
            if ($val > 0 && $val < 65535) {
                $cleared++;
            }
        }
        return $cleared;
    }
}

/**
 * Boss arena floors by episode (as api/cron_boss_tracker.php reads them). Floor numbers repeat
 * across episodes: 9 is Ruins 2 in Episode 1 and the Seaside in Episode 2, 11 the lower Seabed in
 * Episode 2. With no episode known, the old episode-blind list.
 */
function bounty_boss_arena_floors($episode)
{
    if ($episode == 1) return [11, 12, 13, 14];  // Dragon, De Rol Le, Vol Opt, Dark Falz
    if ($episode == 2) return [12, 13, 14, 15];  // Gal Gryphon, Olga Flow, Barba Ray, Gol Dragon
    if ($episode == 4) return [9];               // Saint-Milion / Shambertin / Kondrieu
    return [9, 11, 12, 13, 14, 15];
}

function bounty_episode_area_names($episode)
{
    if ($episode == 2) return 'the VR Temple, the VR Spaceship, the Central Control Area, the Jungle, the Mountain, the Seaside and the Seabed';
    if ($episode == 4) return 'every Crater route, the Crater interior and the Subterranean Desert';
    return 'the Forest, the Caves, the Mines and the Ruins';
}

/**
 * Splits a weekly target "N@Difficulty" or "E@Difficulty".
 */
function bounty_parse_weekly_target($target)
{
    $p = explode('@', (string) $target, 2);
    return [(int) $p[0], $p[1] ?? 'Normal'];
}

/**
 * Human-readable objective for the rework's weekly types (getClearObjective() calls this).
 */
function bounty_weekly_objective_text($type, $target)
{
    list($n, $diff) = bounty_parse_weekly_target($target);
    $or_higher = ($diff === 'Ultimate') ? 'on Ultimate' : "on $diff or higher";
    switch ($type) {
        case 'AREA_SWEEP':
            return "Visit every area of Episode $n $or_higher";
        case 'BOSS_HUNT':
            return "Defeat $n " . ($n === 1 ? 'boss' : 'bosses') . " $or_higher (a kill by anyone in your game counts)";
        case 'ENEMY_HUNT':
            return "Defeat $n " . ($n === 1 ? 'enemy' : 'enemies') . " $or_higher (a kill by anyone in your game counts)";
        case 'RARE_HUNT':
            return "Defeat $n " . ($n === 1 ? 'rare enemy' : 'rare enemies') . " $or_higher (a kill by anyone in your game counts)";
    }
    return '';
}

// ---------------------------------------------------------------------------
// Challenge Mode and Battle Mode bounties of the rework
// ---------------------------------------------------------------------------

/**
 * Splits a Challenge target "E:first-last:limit_seconds:pace" ("1:6-6:3000:S").
 * pace is S, A or B; limit 0 = any time (B).
 */
function bounty_parse_challenge_target($target)
{
    $p = explode(':', (string) $target);
    $range = explode('-', $p[1] ?? '1-1');
    $first = max(1, (int) ($range[0] ?? 1));
    $last = max($first, (int) ($range[1] ?? $first));
    $ep = ((int) ($p[0] ?? 1) === 2) ? 2 : 1;
    return ['episode' => $ep, 'first' => $first, 'last' => $last, 'limit' => max(0, (int) ($p[2] ?? 0)), 'pace' => $p[3] ?? 'B'];
}

function bounty_challenge_stage_total($episode)
{
    return ((int) $episode === 2) ? 5 : 9;
}

/**
 * "1 h 05 min", "50 min".
 */
function bounty_duration_text($seconds)
{
    $m = (int) ceil(max(0, (int) $seconds) / 60);
    if ($m < 60) {
        return "$m min";
    }
    return intdiv($m, 60) . ' h' . (($m % 60) ? ' ' . sprintf('%02d', $m % 60) . ' min' : '');
}

function bounty_challenge_objective_text($target)
{
    $c = bounty_parse_challenge_target($target);
    $ep = $c['episode'];
    $pace = ['S' => 'S pace', 'A' => 'A pace', 'B' => ''][$c['pace']] ?? '';
    $under = $c['limit'] > 0 ? ' under ' . bounty_duration_text($c['limit']) : '';
    if ($c['first'] === 1 && $c['last'] >= bounty_challenge_stage_total($ep)) {
        $rank = ($c['pace'] === 'S') ? 'S rank' : (($c['pace'] === 'A') ? 'A rank or better' : '');
        return "Complete an Episode $ep Challenge Mode run (Stages 1–{$c['last']})"
            . ($c['limit'] > 0 ? " with a combined time$under ($rank)" : '');
    }
    if ($c['first'] === $c['last']) {
        return "Clear Episode $ep Challenge Mode Stage {$c['first']}" . ($c['limit'] > 0 ? " in$under ($pace)" : '');
    }
    return "Clear Episode $ep Challenge Mode Stages {$c['first']}–{$c['last']}"
        . ($c['limit'] > 0 ? " with a combined time$under ($pace)" : '');
}

/**
 * The eight Battle Mode rule sets (api/data/battle_rule_sets.json), by quest number.
 */
function bounty_battle_rule_sets()
{
    static $sets = null;
    if ($sets === null) {
        $sets = [];
        $raw = @file_get_contents(__DIR__ . '/data/battle_rule_sets.json');
        foreach (($raw ? json_decode($raw, true) : []) ?: [] as $b) {
            $sets[(int) $b['quest_number']] = $b;
        }
    }
    return $sets;
}

/**
 * Battle K's name and its rules in a few words, from the rule set in its quest script.
 *
 * @return array ['name' => 'Battle 3: Warp Battle', 'rules' => 'Spaceship; everyone at Lv5 ...']
 */
function bounty_battle_rule_summary($quest)
{
    // In-game titles (BB English quest descriptions)
    $names = [501 => 'Most Kills Wins: Beginner', 502 => 'Most Kills Wins (Level 1)', 503 => 'Warp Battle',
              504 => 'Meseta Battle (Level 2)', 505 => 'Meseta Battle (No Revivals)', 506 => 'Most Kills Wins (Level 20)',
              507 => 'Obstacle Race', 508 => 'Trap Hell'];
    $q = (int) $quest;
    $b = bounty_battle_rule_sets()[$q] ?? null;
    $name = 'Battle ' . ($q - 500) . (isset($names[$q]) ? ': ' . $names[$q] : '');
    if (!$b) {
        return ['name' => $name, 'rules' => ''];
    }
    $r = $b['rules'];
    $out = [];
    $out[] = (stripos($b['description'], 'PALACE') !== false) ? 'Palace' : 'Spaceship';
    $out[] = !empty($r['replace_char'])
        ? 'everyone at Lv' . ((int) ($r['char_level'] ?? 0) + 1) . (!empty($r['death_level_up']) ? ' (+' . (int) $r['death_level_up'] . ' per death)' : '')
        : 'your own level';
    if (!empty($r['teams'])) {
        $out[] = 'teams of 2';
    }
    $out[] = (($r['weapons_armor'] ?? '') === 'own equipment') ? 'your own gear' : 'start with nothing';
    if (($r['mags'] ?? '') === 'forbidden') {
        $out[] = 'no Mags';
    }
    if (($r['tech_disks'] ?? '') === 'level-limited') {
        $out[] = 'techniques up to Lv' . ((int) ($r['max_tech_level'] ?? 0) + 1);
    }
    $resp = $r['respawn'] ?? '';
    $out[] = ($resp === 'limited lives') ? ((int) ($r['lives'] ?? 0)) . ' lives' : (($resp === 'no revival') ? 'no revival' : 'unlimited revivals');
    if (empty($r['forbid_scape_dolls'])) {
        $out[] = 'Scape Dolls allowed';
    }
    if ((float) ($b['scores']['per_meseta'] ?? 0) > 0 && stripos($b['description'], 'Meseta') !== false) {
        $out[] = 'scored on Meseta held';
    } elseif (($r['meseta'] ?? '') === 'forbidden') {
        $out[] = 'no Meseta';
    }
    $traps = $b['traps'] ?? [];
    if ($traps && max($traps) >= 10) {
        $out[] = max($traps) . ' of each ' . implode('/', array_keys(array_filter($traps, function ($n) { return $n >= 10; }))) . ' trap';
    }
    $out[] = (int) ($r['time_limit_minutes'] ?? 10) . ' min';
    return ['name' => $name, 'rules' => implode(', ', $out)];
}

/**
 * Battle target "quest:wins" ("503:2").
 */
function bounty_parse_battle_target($target)
{
    $p = explode(':', (string) $target);
    return [(int) ($p[0] ?? 501), max(1, (int) ($p[1] ?? 1))];
}

/**
 * Play-time target "<seconds needed>:<play time when the bounty started>" ("3600:435600").
 */
function bounty_parse_play_target($target)
{
    $p = explode(':', (string) $target);
    return [max(60, (int) ($p[0] ?? 3600)), max(0, (int) ($p[1] ?? 0))];
}

function bounty_play_objective_text($target)
{
    list($need) = bounty_parse_play_target($target);
    $h = intdiv($need, 3600);
    $m = intdiv($need % 3600, 60);
    $t = ($h ? $h . ' ' . ($h === 1 ? 'hour' : 'hours') : '') . ($h && $m ? ' ' : '') . ($m ? "$m min" : '');
    return "Play for $t";
}

function bounty_battle_objective_text($target)
{
    list($q, $n) = bounty_parse_battle_target($target);
    $s = bounty_battle_rule_summary($q);
    return "Win $n Battle Mode " . ($n === 1 ? 'match' : 'matches') . " of {$s['name']}" . ($s['rules'] ? " ({$s['rules']})" : '');
}

// ---------------------------------------------------------------------------
// Contracts of the rework (api/bounty_contracts.php)
// ---------------------------------------------------------------------------

/**
 * A rare creature of the contract hunts, by target ID ("KONDRIEU"): label, newserv names, spawn chance,
 * episodes (from api/data/bounty_areas.json), or null.
 */
function bounty_rare_enemy_info($id)
{
    static $data = null;
    if ($data === null) {
        $raw = @file_get_contents(__DIR__ . '/data/bounty_areas.json');
        $data = $raw ? ((json_decode($raw, true) ?: [])['rare_enemies'] ?? []) : [];
    }
    return $data[$id] ?? null;
}

/**
 * Splits a run contract's target "EP:VARIANT:MINUTES@Difficulty" ("1:timed:150@Very Hard").
 * VARIANT is diverse, carry or timed; MINUTES only matters for timed.
 */
function bounty_parse_run_target($target)
{
    list($a, $diff) = array_pad(explode('@', (string) $target, 2), 2, 'Normal');
    $p = explode(':', $a);
    $ep = (int) ($p[0] ?? 1);
    return [
        'episode' => in_array($ep, [1, 2, 4], true) ? $ep : 1,
        'variant' => in_array($p[1] ?? '', ['diverse', 'carry', 'timed'], true) ? $p[1] : 'timed',
        'minutes' => max(0, (int) ($p[2] ?? 0)),
        'difficulty' => $diff,
    ];
}

/**
 * Splits a hunt contract's target "TARGET:N:EP@Difficulty" ("KONDRIEU:1:4@Ultimate").
 */
function bounty_parse_hunt_target($target)
{
    list($a, $diff) = array_pad(explode('@', (string) $target, 2), 2, 'Normal');
    $p = explode(':', $a);
    $ep = (int) ($p[2] ?? 1);
    return ['target' => (string) ($p[0] ?? ''), 'count' => max(1, (int) ($p[1] ?? 1)), 'episode' => in_array($ep, [1, 2, 4], true) ? $ep : 1, 'difficulty' => $diff];
}

/**
 * The guardians a full run defeats, as the story prompt names them: as people in the episode's
 * deepest area know them (bounty_episode_setting()). Episode 1's are told without the names its
 * cards keep hidden (Vol Opt, Dark Falz).
 */
function bounty_contract_boss_names($episode)
{
    if ($episode == 2) return ['Barba Ray', 'Gol Dragon', 'Gal Gryphon', 'Olga Flow'];
    if ($episode == 4) return ['Saint-Milion, Shambertin or Kondrieu'];
    return ['the Dragon beneath the Dome', 'the giant worm of the underground waterway', "the Mines' main computer", 'whatever was let loose at the heart of the ruins'];
}

function bounty_creature_label($id, $plural = false)
{
    $info = bounty_rare_enemy_info($id);
    $label = $info['label'] ?? ucwords(strtolower(str_replace('_', ' ', (string) $id)));
    if (!$plural) {
        return $label;
    }
    if (substr($label, -1) === 'y') return substr($label, 0, -1) . 'ies';
    if (substr($label, -1) === 's') return $label . 'es';
    return $label . 's';
}

/**
 * The carry rule's numbers: [players, levels below the holder].
 */
function bounty_contract_carry_rule()
{
    $c = function_exists('bounty_config') ? (bounty_config()['contracts']['carry'] ?? []) : [];
    return [(int) ($c['players'] ?? 3), (int) ($c['levels'] ?? 10)];
}

/**
 * Human-readable objective of a contract (getClearObjective() calls this).
 */
function bounty_contract_objective_text($type, $target)
{
    if ($type === 'RARE_TARGET') {
        $t = bounty_parse_hunt_target($target);
        $or = ($t['difficulty'] === 'Ultimate') ? 'on Ultimate' : "on {$t['difficulty']} or higher";
        $label = bounty_creature_label($t['target']);
        $what = ($t['count'] === 1) ? (preg_match('/^[AEIOU]/i', $label) ? "an $label" : "a $label") : $t['count'] . ' ' . bounty_creature_label($t['target'], true);
        return "Defeat $what in Episode {$t['episode']} $or (a kill by anyone in your game counts)";
    }
    $t = bounty_parse_run_target($target);
    $or = ($t['difficulty'] === 'Ultimate') ? 'on Ultimate' : "on {$t['difficulty']} or higher";
    $nb = count(bounty_boss_arena_floors($t['episode']));
    $base = "Full Episode {$t['episode']} run in one game $or: every area and " . ($nb === 1 ? 'the boss' : "all $nb bosses");
    switch ($t['variant']) {
        case 'diverse':
            return "$base, with a Hunter, a Ranger and a Force in the game at every boss";
        case 'carry':
            list($n, $lv) = bounty_contract_carry_rule();
            return "$base, carrying $n or more hunters $lv+ levels below you (by the levels you each joined the game at), all there at every boss";
    }
    return "$base, within " . bounty_duration_text($t['minutes'] * 60) . " of entering the first area";
}

/**
 * Progress line of a contract on the board.
 */
function bounty_contract_progress_text($type, $target, array $p)
{
    if ($type === 'RARE_TARGET') {
        $t = bounty_parse_hunt_target($target);
        return (int) ($p['count'] ?? 0) . " / {$t['count']} " . bounty_creature_label($t['target'], $t['count'] !== 1);
    }
    $t = bounty_parse_run_target($target);
    if (empty($p['lobby'])) {
        return "Not started: join an Episode {$t['episode']} game";
    }
    $nf = count(bounty_episode_floors($t['episode']));
    $nb = count(bounty_boss_arena_floors($t['episode']));
    $s = 'This game: ' . count($p['floors'] ?? []) . " / $nf areas, " . count($p['bosses'] ?? []) . " / $nb " . ($nb === 1 ? 'boss' : 'bosses');
    if (!empty($p['missed'])) {
        return "$s; a boss fell without the party, so start a new game";
    }
    if (!empty($p['late'])) {
        return "$s; over time, so start a new game";
    }
    if ($t['variant'] === 'timed' && !empty($p['started'])) {
        $s .= ', ' . bounty_duration_text(time() - (int) $p['started']) . ' in';
    }
    return $s;
}

// Objectives whose progress the board shows (kept in player_missions.progress)
const BOUNTY_PROGRESS_GOALS = ['AREA_SWEEP', 'BOSS_HUNT', 'ENEMY_HUNT', 'RARE_HUNT', 'CHALLENGE_RUN', 'BATTLE_RULE_WINS', 'PLAY_HOURS', 'EPISODE_RUN', 'RARE_TARGET'];

/**
 * Progress line for the board, e.g. "4 / 10 areas".
 */
function bounty_progress_text($type, $target, $progress_json)
{
    $p = json_decode((string) $progress_json, true) ?: [];
    if ($type === 'EPISODE_RUN' || $type === 'RARE_TARGET') {
        return bounty_contract_progress_text($type, $target, $p);
    }
    if ($type === 'CHALLENGE_RUN') {
        $c = bounty_parse_challenge_target($target);
        $best = $p['best'] ?? [];
        $need = $c['last'] - $c['first'] + 1;
        $have = 0;
        $sum = 0;
        for ($st = $c['first']; $st <= $c['last']; $st++) {
            if (isset($best[(string) $st])) {
                $have++;
                $sum += (int) $best[(string) $st];
            }
        }
        if ($need === 1) {
            return $have ? 'Best: ' . bounty_duration_text($sum) : 'Not cleared yet';
        }
        return "$have / $need stages" . ($have ? ', ' . bounty_duration_text($sum) . ' so far' : '');
    }
    if ($type === 'PLAY_HOURS') {
        list($need) = bounty_parse_play_target($target);
        $mins = function ($s) {
            $m = intdiv(max(0, (int) $s), 60);
            return ($m >= 60) ? intdiv($m, 60) . ' h' . (($m % 60) ? ' ' . sprintf('%02d', $m % 60) . ' min' : '') : "$m min";
        };
        return $mins(min($need, (int) ($p['seconds'] ?? 0))) . ' / ' . $mins($need) . ' played';
    }
    if ($type === 'BATTLE_RULE_WINS') {
        list(, $n) = bounty_parse_battle_target($target);
        return (int) ($p['count'] ?? 0) . " / $n " . ($n === 1 ? 'win' : 'wins');
    }
    list($n) = bounty_parse_weekly_target($target);
    switch ($type) {
        case 'AREA_SWEEP':
            return count($p['visited'] ?? []) . ' / ' . count(bounty_episode_floors($n)) . ' areas';
        case 'BOSS_HUNT':
            return (int) ($p['count'] ?? 0) . " / $n " . ($n === 1 ? 'boss' : 'bosses');
        case 'ENEMY_HUNT':
            return (int) ($p['count'] ?? 0) . " / $n " . ($n === 1 ? 'enemy' : 'enemies');
        case 'RARE_HUNT':
            return (int) ($p['count'] ?? 0) . " / $n " . ($n === 1 ? 'rare enemy' : 'rare enemies');
    }
    return '';
}

/**
 * newserv reports every Challenge stage's record time, cleared or not: ChallengeTimesEp1Online holds
 * 9 stages and ChallengeTimesEp2Online 5. A cleared stage shows its time in seconds; a stage never
 * cleared shows 65535, which is how newserv decodes an empty record (0 is treated the same way).
 */
function challenge_time_cleared($t)
{
    $t = (int) $t;
    return $t > 0 && $t < 0xFFFF;
}

/**
 * How many of the 14 online Challenge stages (Episode 1: 9, Episode 2: 5) the character has cleared.
 */
function challenge_stages_cleared(array $client)
{
    $n = 0;
    foreach (array_merge($client['ChallengeTimesEp1Online'] ?? [], $client['ChallengeTimesEp2Online'] ?? []) as $t) {
        if (challenge_time_cleared($t)) {
            $n++;
        }
    }
    return $n;
}

// Daily objectives that happen in an area or at a boss (Uncommon and Rare ones may need a difficulty)
const BOUNTY_DAILY_PLACE_GOALS = ['EXPLORATION', 'PATROL', 'SPEEDRUN_FLOOR', 'BOSS_ARENA', 'MENTOR_BOSS', 'HARDCORE_MENTOR', 'DIVERSE_PARTY_BOSS', 'SPEEDRUN_BOSS'];

/**
 * The lowest difficulty a bounty's objective counts on, from its missions.meta (null = any).
 */
function bounty_meta_min_difficulty($meta)
{
    $m = is_array($meta) ? $meta : (json_decode((string) $meta, true) ?: []);
    $d = $m['min_difficulty'] ?? null;
    return in_array($d, ['Hard', 'Very Hard', 'Ultimate'], true) ? $d : null;
}

function getClearObjective($type, $target, $title = '', $desc = '', $min_difficulty = null)
{
    if ($min_difficulty && in_array($type, BOUNTY_DAILY_PLACE_GOALS, true)) {
        $at = ($min_difficulty === 'Ultimate') ? __('(on Ultimate)') : __('(on %s or higher)', __($min_difficulty));
        return getClearObjective($type, $target, $title, $desc) . ' ' . $at;
    }
    switch ($type) {
        case 'MESETA':
            if ($target === 'ANY')
                return __('Collect Meseta (Any source)');
            return __('Hold at least %s Meseta in inventory', number_format((int) $target));
        case 'LEVEL':
            return __('Reach Level %s', htmlspecialchars($target));
        case 'LEVEL_UP':
            return __('Earn Level Ups (Any Character)');
        case 'MAT_CONSUME':
            return __('Consume Any Materials');
        case 'PLAY_HOURS':
            return __(bounty_play_objective_text($target)) . ' ' . __('(counted from when the bounty starts)');
        case 'PLAYTIME':
            if ($target === 'ANY')
                return __('Accumulate Playtime (Server-wide Tracker)');
            return __('Accumulate %s total hours of playtime', (int) ($target / 3600));
        case 'CHALLENGE_STAGES':
            if ($target === 'ANY')
                return __('Clear Any Challenge Mode Stages');
            return __('Clear Challenge Mode stages until %s of the 14 are cleared (each stage counts once)', htmlspecialchars($target));
        case 'ITEM':
            // Legacy items are stored as "ID:Name". Generated base weapons are stored just as "String".
            // If the string is a pure Hex payload, reverse map it back to an English string.
            $parts = explode(':', $target, 2);
            $itemName = isset($parts[1]) ? $parts[1] : $target;

            // Clean up modifier fragments if present
            $itemName = explode(' ', $itemName)[0];

            if (ctype_xdigit($itemName) && strlen($itemName) >= 6) {
                $hex_base = substr($itemName, 0, 6);

                $generic_weapon_hex_map = [
                    'Saber' => '000100',
                    'Brand' => '000101',
                    'Buster' => '000102',
                    'Pallasch' => '000103',
                    'Gladius' => '000104',
                    'Sword' => '000200',
                    'Gigush' => '000201',
                    'Breaker' => '000202',
                    'Claymore' => '000203',
                    'Calibur' => '000204',
                    'Dagger' => '000300',
                    'Knife' => '000301',
                    'Blade' => '000302',
                    'Edge' => '000303',
                    'Ripper' => '000304',
                    'Partisan' => '000400',
                    'Halbert' => '000401',
                    'Glaive' => '000402',
                    'Berdys' => '000403',
                    'Gungnir' => '000404',
                    'Slicer' => '000500',
                    'Spinner' => '000501',
                    'Cutter' => '000502',
                    'Sawcer' => '000503',
                    'Diska' => '000504',
                    'Handgun' => '000600',
                    'Autogun' => '000601',
                    'Lockgun' => '000602',
                    'Railgun' => '000603',
                    'Raygun' => '000604',
                    'Rifle' => '000700',
                    'Sniper' => '000701',
                    'Blaster' => '000702',
                    'Beam' => '000703',
                    'Laser' => '000704',
                    'Mechgun' => '000800',
                    'Assault' => '000801',
                    'Repeater' => '000802',
                    'Gatling' => '000803',
                    'Vulcan' => '000804',
                    'Shot' => '000900',
                    'Spread' => '000901',
                    'Cannon' => '000902',
                    'Launcher' => '000903',
                    'Arms' => '000904',
                    'Cane' => '000A00',
                    'Stick' => '000A01',
                    'Mace' => '000A02',
                    'Club' => '000A03',
                    'Rod' => '000B00',
                    'Pole' => '000B01',
                    'Pillar' => '000B02',
                    'Striker' => '000B03',
                    'Wand' => '000C00',
                    'Staff' => '000C01',
                    'Baton' => '000C02',
                    'Scepter' => '000C03',
                    'Talis' => '008C00',
                    'Mahu' => '008C01',
                    'Hitogata' => '008C02'
                ];
                $reverse_generic_map = array_flip($generic_weapon_hex_map);

                if (isset($reverse_generic_map[$hex_base])) {
                    $itemName = $reverse_generic_map[$hex_base];
                } else {
                    $resolvedName = resolve_bb_item_display_name($hex_base);
                    if ($resolvedName !== null) {
                        $itemName = $resolvedName;
                    }
                }
            }
            return __('Find and hold the item: %s', htmlspecialchars(__($itemName)));
        case 'TECHNIQUE':
            return __('Learn the technique: %s', htmlspecialchars($target));
        case 'BATTLE_WINS':
            return __('Achieve %s 1st place Battle Mode wins', htmlspecialchars($target));
        case 'MAT_HP':
            return __('Consume a total of %s HP Materials', htmlspecialchars($target));
        case 'MAT_TP':
            return __('Consume a total of %s TP Materials', htmlspecialchars($target));
        case 'MAT_POWER':
            return __('Consume a total of %s Power Materials', htmlspecialchars($target));
        case 'MAT_DEF':
            return __('Consume a total of %s Def Materials', htmlspecialchars($target));
        case 'MAT_MIND':
            return __('Consume a total of %s Mind Materials', htmlspecialchars($target));
        case 'MAT_EVADE':
            return __('Consume a total of %s Evade Materials', htmlspecialchars($target));
        case 'MAT_LUCK':
            return __('Consume a total of %s Luck Materials', htmlspecialchars($target));
        case 'EXPLORATION':
            // Maps integer Floor IDs returned by the game client memory map to friendly Names
            $floors = [1 => 'Forest 1', 2 => 'Forest 2', 3 => 'Cave 1', 4 => 'Cave 2', 5 => 'Cave 3', 6 => 'Mine 1', 7 => 'Mine 2', 8 => 'Ruins 1', 9 => 'Ruins 2', 10 => 'Ruins 3'];
            $loc = $floors[$target] ?? "Floor $target";
            return __('[Ep 1] Explore the %s', htmlspecialchars(__($loc)));
        case 'PATROL':
            $floors = [1 => 'Forest 1', 2 => 'Forest 2', 3 => 'Cave 1', 4 => 'Cave 2', 5 => 'Cave 3', 6 => 'Mine 1', 7 => 'Mine 2', 8 => 'Ruins 1', 9 => 'Ruins 2', 10 => 'Ruins 3'];
            $loc = $floors[$target] ?? "Floor $target";
            return __('[Ep 1] Survive and patrol the %s for 10 minutes', htmlspecialchars(__($loc)));
        case 'BOSS_ARENA':
            if ($target === 'ANY_DRAGON')
                return __('Defeat Any Dragon Boss (Forest, Sil, or Gol)');

            // Custom multi-boss community event targets (tracked dynamically in cron_community.php)
            $custom_boss_events = [
                'DIGITAL_BLASPHEMY' => __('Defeat Vol Opt (+1 pt), Gol Dragon (+2 pts), or Shambertin (+3 pts). Difficulty bonus: +1 Hard, +2 VHard, +3 Ult'),
                'EP1_BOSS_RUSH' => __('Defeat Any Episode 1 Boss'),
                'EP2_BOSS_RUSH' => __('Defeat Any Episode 2 Boss'),
                'ALL_BOSSES' => __('Defeat Any Boss (All Episodes)'),
                'DRACONIC_DOMINION' => __('Defeat Dragon (Ep1), Gol Dragon (Ep2), or Shambertin (Ep4)'),
                'CATACLYSMIC_CORE' => __('Defeat Dark Falz (Ep1), Olga Flow (Ep2), or Shambertin (Ep4)'),
            ];
            if (isset($custom_boss_events[$target]))
                return $custom_boss_events[$target];

            $target_floor = (int) $target;
            if ($target_floor === 0)
                return __('Boss Bounty Completed!');
            $ep = (string) get_boss_episode_by_context($title, $desc, $target_floor, parse_boss_target($target)['episode']);

            $boss = boss_display_name($target_floor, $ep, $title, $desc);

            $has_the = true;
            foreach (['Vol Opt', 'Dark Falz', 'Olga Flow', 'Gal Gryphon', 'Saint-Milion', 'Gol Dragon', 'Barba Ray'] as $ntb) {
                if (stripos($boss, $ntb) !== false) {
                    $has_the = false;
                    break;
                }
            }
            if ($has_the) {
                return __('[Ep %s] Defeat the %s', $ep, htmlspecialchars(__($boss)));
            } else {
                return __('[Ep %s] Defeat %s', $ep, htmlspecialchars(__($boss)));
            }
        case 'MENTOR_BOSS':
            if ($target === 'ANY_DRAGON')
                return __('Mentor a player (5+ levels lower) through Any Dragon Boss (Forest, Sil, or Gol)');

            $target_floor = (int) $target;
            if ($target_floor === 0)
                return __('Mentor Bounty Completed!');
            $ep = (string) get_boss_episode_by_context($title, $desc, $target_floor, parse_boss_target($target)['episode']);

            $boss = boss_display_name($target_floor, $ep, $title, $desc);
            return __('[Ep %s] Carry a lower-level player (5+ levels lower) through the %s fight', $ep, htmlspecialchars(__($boss)));
        case 'HARDCORE_MENTOR':
            if ($target === 'ANY_DRAGON')
                return __('Hardcore Carry 3 lower-level players (10+ levels lower) through Any Dragon Boss');

            $target_floor = (int) $target;
            if ($target_floor === 0)
                return __('Hardcore Carry Completed!');
            $ep = (string) get_boss_episode_by_context($title, $desc, $target_floor, parse_boss_target($target)['episode']);

            $boss = boss_display_name($target_floor, $ep, $title, $desc);
            return __('[Ep %s] Hardcore Carry 3 lower-level players (10+ levels lower) through the %s fight', $ep, htmlspecialchars(__($boss)));
        case 'DIVERSE_PARTY_BOSS':
            if ($target === 'ANY_DRAGON')
                return __('Defeat Any Dragon Boss with a diverse party (HU, RA, FO)');

            $target_floor = (int) $target;
            if ($target_floor === 0)
                return __('Diverse Party Bounty Completed!');
            $ep = (string) get_boss_episode_by_context($title, $desc, $target_floor, parse_boss_target($target)['episode']);

            $boss = boss_display_name($target_floor, $ep, $title, $desc);

            $has_the = true;
            foreach (['Vol Opt', 'Dark Falz', 'Olga Flow', 'Gal Gryphon', 'Saint-Milion', 'Gol Dragon', 'Barba Ray'] as $ntb) {
                if (stripos($boss, $ntb) !== false) {
                    $has_the = false;
                    break;
                }
            }
            if ($has_the) {
                return __('[Ep %s] Defeat the %s with a diverse party (HU, RA, FO)', $ep, htmlspecialchars(__($boss)));
            } else {
                return __('[Ep %s] Defeat %s with a diverse party (HU, RA, FO)', $ep, htmlspecialchars(__($boss)));
            }
        case 'SPEEDRUN_BOSS':
            list($target_floor, $time_limit) = explode('_', $target);
            $target_floor = (int) $target_floor;
            $ep = (string) get_boss_episode_by_context($title, $desc, $target_floor, parse_boss_target($target)['episode']);

            $boss = boss_display_name($target_floor, $ep, $title, $desc);

            $has_the = true;
            foreach (['Vol Opt', 'Dark Falz', 'Olga Flow', 'Gal Gryphon', 'Saint-Milion', 'Gol Dragon', 'Barba Ray'] as $ntb) {
                if (stripos($boss, $ntb) !== false) {
                    $has_the = false;
                    break;
                }
            }

            $mins = floor($time_limit / 60);
            $secs = $time_limit % 60;
            if ($mins > 0) {
                if ($secs == 0) {
                    return $has_the
                        ? __('[Ep %s] Defeat the %s in under %s minutes', $ep, htmlspecialchars(__($boss)), $mins)
                        : __('[Ep %s] Defeat %s in under %s minutes', $ep, htmlspecialchars(__($boss)), $mins);
                }
                return $has_the
                    ? __('[Ep %s] Defeat the %s in under %s minutes and %s seconds', $ep, htmlspecialchars(__($boss)), $mins, $secs)
                    : __('[Ep %s] Defeat %s in under %s minutes and %s seconds', $ep, htmlspecialchars(__($boss)), $mins, $secs);
            }
            return $has_the
                ? __('[Ep %s] Defeat the %s in under %s seconds', $ep, htmlspecialchars(__($boss)), htmlspecialchars($time_limit))
                : __('[Ep %s] Defeat %s in under %s seconds', $ep, htmlspecialchars(__($boss)), htmlspecialchars($time_limit));
        case 'AREA_SWEEP':
        case 'BOSS_HUNT':
        case 'ENEMY_HUNT':
        case 'RARE_HUNT':
            return __('[Weekly] %s', htmlspecialchars(__(bounty_weekly_objective_text($type, $target))));
        case 'CHALLENGE_RUN':
            return __('[Challenge] %s', htmlspecialchars(__(bounty_challenge_objective_text($target))));
        case 'EPISODE_RUN':
        case 'RARE_TARGET':
            return __('[Contract] %s', htmlspecialchars(__(bounty_contract_objective_text($type, $target))));
        case 'CONTRACT_SHARE':
            return __('Your share of a contract another hunter finished with you in the game');
        case 'BATTLE_RULE_WINS':
            return __('[Battle] %s', htmlspecialchars(__(bounty_battle_objective_text($target))));
        case 'PENDING':
            return __('Waiting for a free slot: the objective and reward are set when it becomes active');
        case 'SPEEDRUN_FLOOR':
            list($target_floor, $time_limit) = explode('_', $target);
            $floors = [1 => 'Forest 1', 2 => 'Forest 2', 3 => 'Cave 1', 4 => 'Cave 2', 5 => 'Cave 3', 6 => 'Mine 1', 7 => 'Mine 2', 8 => 'Ruins 1', 9 => 'Ruins 2', 10 => 'Ruins 3'];
            $loc = $floors[$target_floor] ?? "Floor $target_floor";
            $mins = floor($time_limit / 60);
            $secs = $time_limit % 60;
            if ($secs == 0)
                return __('[Ep 1] Clear %s in under %s minutes', htmlspecialchars(__($loc)), $mins);
            return __('[Ep 1] Clear %s in under %s minutes and %s seconds', htmlspecialchars(__($loc)), $mins, $secs);
        default:
            return htmlspecialchars($type) . ": " . htmlspecialchars($target);
    }
}

/**
 * Display names for item codes from item_map.json, as [code => lower-case name].
 *
 * item_map.json maps names to codes, and some codes have several names, a few of them wrong
 * ("monomate" => 031200, the Weapons Bronze Badge; "gekko" => 008A00, Sange). A plain
 * array_flip keeps the last name. This keeps the name that matches newserv's name for the
 * code when there is one, and uses newserv's name where item_map.json has only a bare part
 * of it ("barrier" for ASSIST BARRIER). Other names (such as "agito (1975)") are kept.
 */
function item_map_display_names()
{
    static $rev = null;
    if ($rev !== null) {
        return $rev;
    }
    $rev = [];
    $map = json_decode((string) @file_get_contents(__DIR__ . '/item_map.json'), true) ?: [];
    $v4 = json_decode((string) @file_get_contents(__DIR__ . '/names-v4.json'), true) ?: [];
    $norm = function ($s) {
        return preg_replace('/[^a-z0-9]/', '', strtolower(preg_replace('/\s+x1$/i', '', (string) $s)));
    };
    $by_code = [];
    foreach ($map as $name => $code) {
        $by_code[$code][] = (string) $name;
    }
    foreach ($by_code as $code => $names) {
        $pick = end($names);
        $real = isset($v4[$code]) ? $norm($v4[$code]) : '';
        if ($real !== '' && $norm($pick) !== $real) {
            foreach ($names as $nm) {
                if ($norm($nm) === $real) {
                    $pick = $nm;
                    break;
                }
            }
            $p = $norm($pick);
            if ($p !== '' && $p !== $real && strlen($real) > strlen($p) && strpos($real, $p) !== false) {
                $pick = strtolower(preg_replace('/\s+/', ' ', trim($v4[$code])));
            }
        }
        $rev[$code] = preg_replace('/\s+x1$/i', '', $pick);
    }
    return $rev;
}

/**
 * Resolves a 6-character PSOBB item hex code to a clean display name,
 * preferring disambiguated item_map names and falling back to names-v4.
 */
function resolve_bb_item_display_name(string $hexBase): ?string
{
    $hexUpper = strtoupper(trim($hexBase));
    $reverse_map = item_map_display_names();
    if (isset($reverse_map[$hexUpper])) {
        $candidate = preg_replace('/\s+/', ' ', preg_replace('/\s+x1$/i', '', ucwords($reverse_map[$hexUpper])));
        if ($candidate !== '' && strpos($candidate, '?') === false && !ctype_digit(trim($candidate))) {
            return $candidate;
        }
    }
    $bbMaps = get_bb_item_name_maps();
    if (isset($bbMaps['hex_to_name'][$hexUpper])) {
        return preg_replace('/\s+/', ' ', $bbMaps['hex_to_name'][$hexUpper]);
    }
    return null;
}

/**
 * Obfuscates Unidentified weapon strings before rendering them to the Bounty Board UI.
 * Prevents players from knowing the exact special ability or grind modifiers prior to taking the weapon to the Tekker.
 *
 * @param string $rewardStr The raw reward string stored in the database (e.g., '? Charge Saber 20/0/45/0' or '000A01008100...')
 * @return string Safely obfuscated string rendering identically to the in-game display ('???? Saber')
 */
function renderRewardString($rewardStr)
{
    if (empty($rewardStr))
        return "";
    $segments = explode(',', $rewardStr);
    $processed = [];

    foreach ($segments as $segment) {
        $segment = trim($segment);
        if (empty($segment))
            continue;
        $parts = explode(' ', $segment);
        $firstPart = $parts[0];

        // If the segment starts with a 32-character or 6-character Hex payload, parse it
        if (ctype_xdigit($firstPart) && (strlen($firstPart) >= 32 || strlen($firstPart) === 6)) {
            $base = strtoupper(substr($firstPart, 0, 6));
            $untekked_flag = (substr($base, 0, 2) === '00' && strlen($firstPart) >= 10) ? hexdec(substr($firstPart, 8, 2)) : 0;

            $weaponName = $base;

            $generic_item_hex_map = [
                'Saber' => '000100',
                'Brand' => '000101',
                'Buster' => '000102',
                'Pallasch' => '000103',
                'Gladius' => '000104',
                'Sword' => '000200',
                'Gigush' => '000201',
                'Breaker' => '000202',
                'Claymore' => '000203',
                'Calibur' => '000204',
                'Dagger' => '000300',
                'Knife' => '000301',
                'Blade' => '000302',
                'Edge' => '000303',
                'Ripper' => '000304',
                'Partisan' => '000400',
                'Halbert' => '000401',
                'Glaive' => '000402',
                'Berdys' => '000403',
                'Gungnir' => '000404',
                'Slicer' => '000500',
                'Spinner' => '000501',
                'Cutter' => '000502',
                'Sawcer' => '000503',
                'Diska' => '000504',
                'Handgun' => '000600',
                'Autogun' => '000601',
                'Lockgun' => '000602',
                'Railgun' => '000603',
                'Raygun' => '000604',
                'Rifle' => '000700',
                'Sniper' => '000701',
                'Blaster' => '000702',
                'Beam' => '000703',
                'Laser' => '000704',
                'Mechgun' => '000800',
                'Assault' => '000801',
                'Repeater' => '000802',
                'Gatling' => '000803',
                'Vulcan' => '000804',
                'Shot' => '000900',
                'Spread' => '000901',
                'Cannon' => '000902',
                'Launcher' => '000903',
                'Arms' => '000904',
                'Cane' => '000A00',
                'Stick' => '000A01',
                'Mace' => '000A02',
                'Club' => '000A03',
                'Rod' => '000B00',
                'Pole' => '000B01',
                'Pillar' => '000B02',
                'Striker' => '000B03',
                'Wand' => '000C00',
                'Staff' => '000C01',
                'Baton' => '000C02',
                'Scepter' => '000C03',
                'Talis' => '008C00',
                'Mahu' => '008C01',
                'Hitogata' => '008C02',
                'Frame' => '010100',
                'Armor' => '010101',
                'Psy Armor' => '010102',
                'Giga Frame' => '010103',
                'Soul Frame' => '010104',
                'Cross Armor' => '010105',
                'Solid Frame' => '010106',
                'Brave Armor' => '010107',
                'Hyper Frame' => '010108',
                'Grand Armor' => '010109',
                'Shock Frame' => '01010A',
                'King\'s Frame' => '01010B',
                'Dragon Frame' => '01010C',
                'Absorb Armor' => '01010D',
                'Protect Frame' => '01010E',
                'General Armor' => '01010F',
                'Perfect Frame' => '010110',
                'Valiant Frame' => '010111',
                'Imperial Armor' => '010112',
                'Holiness Armor' => '010113',
                'Guardian Armor' => '010114',
                'Divinity Armor' => '010115',
                'Ultimate Frame' => '010116',
                'Celestial Armor' => '010117',
                'Barrier' => '010200',
                'Shield' => '010201',
                'Core Shield' => '010202',
                'Giga Shield' => '010203',
                'Soul Barrier' => '010204',
                'Hard Shield' => '010205',
                'Brave Barrier' => '010206',
                'Solid Shield' => '010207',
                'Flame Barrier' => '010208',
                'Plasma Barrier' => '010209',
                'Freeze Barrier' => '01020A',
                'Psychic Barrier' => '01020B',
                'General Shield' => '01020C',
                'Protect Barrier' => '01020D',
                'Glorious Shield' => '01020E',
                'Imperial Barrier' => '01020F',
                'Guardian Shield' => '010210',
                'Divinity Barrier' => '010211',
                'Ultimate Shield' => '010212',
                'Spiritual Shield' => '010213',
                'Celestial Shield' => '010214'
            ];
            $reverse_generic_map = array_flip($generic_item_hex_map);

            $decodedDisk = (strpos($base, '0302') === 0) ? decode_pso_tech_disk_hex($firstPart) : null;

            if ($decodedDisk !== null) {
                $weaponName = $decodedDisk;
            } elseif (isset($reverse_generic_map[$base])) {
                $weaponName = $reverse_generic_map[$base];
            } else {
                $resolvedName = resolve_bb_item_display_name($base);
                if ($resolvedName !== null) {
                    $weaponName = $resolvedName;
                }
            }

            $prefix = ($untekked_flag & 0x80) ? "???? " : "";

            $suffix = "";
            if (strpos($base, '0103') === 0 && strlen($firstPart) >= 14) {
                $modHex = substr($firstPart, 12, 2);
                $modVal = hexdec($modHex);
                if ($modVal === 1) {
                    $suffix = "+";
                } elseif ($modVal === 2) {
                    $suffix = "++";
                } elseif ($modVal === 255) {
                    $suffix = "-";
                }
            }

            array_shift($parts);
            $rest = implode(' ', $parts);

            $processed[] = $prefix . $weaponName . $suffix . ($rest ? " " . $rest : "");
        }
        // Legacy Support: If the string starts with "? ", it indicates an untekked weapon reward
        else if (strpos($segment, '? ') === 0) {
            $legacy_parts = explode(' ', $segment);
            array_shift($legacy_parts);
            array_shift($legacy_parts);
            if (!empty($legacy_parts) && strpos(end($legacy_parts), '/') !== false) {
                array_pop($legacy_parts);
            }
            $weaponName = implode(' ', $legacy_parts);
            $processed[] = "???? " . $weaponName;
        }
        // Non-untekked rewards (Meseta, Materials, Rares, Disks)
        else {
            // Normalize Disk: technique names → "Disk:Megid Lv.1" regardless of stored case
            if (preg_match('/^Disk:([A-Za-z]+)\s+Lv\.(\d+)$/i', $segment, $dm)) {
                $segment = 'Disk:' . ucfirst(strtolower($dm[1])) . ' Lv.' . (int)$dm[2];
            }
            $processed[] = $segment;
        }
    }

    return implode(', ', array_map('htmlspecialchars', $processed));
}

/**
 * Converts an item hex string with optional stats/attributes into a complete 32-character NewServ hex payload.
 * Handles:
 *  - Weapons: parses '00xxxx Native/ABeast/Machine/Dark/Hit' and writes stats into byte offsets 6..11
 *  - Armors/Shields: parses '+Xdef', '+Xevp', '+X' slot and writes byte offsets 5..9
 *  - Units: parses '+X' modifiers and writes byte offsets 6..7
 *
 * @param string $itemStr Raw item definition string
 * @return string 32-character uppercase hex string or original item string if non-hex
 */
if (!function_exists('buildHexPayload')) {
    function buildHexPayload($itemStr) {
        $itemStr = trim($itemStr);
        if (empty($itemStr)) return $itemStr;

        $parts = explode(' ', $itemStr);
        $firstPart = array_shift($parts);

        if (ctype_xdigit($firstPart) && strlen($firstPart) >= 6) {
            $hex = str_pad(substr($firstPart, 0, 32), 32, "0");
            $data = hex2bin($hex);
            
            $is_weapon = ($data[0] === "\x00");
            $is_armor_shield = ($data[0] === "\x01" && ($data[1] === "\x01" || $data[1] === "\x02"));
            $is_unit = ($data[0] === "\x01" && $data[1] === "\x03");
            $is_mag = ($data[0] === "\x02");
            $base_id = strtoupper(substr($hex, 0, 6));
            $is_unsealable = in_array($base_id, ['003300', '00AB00', '01034D', '01034F'], true);

            if ($is_weapon) {
                $max_idx = $is_unsealable ? 10 : 12;
                if (!empty($parts) && strpos($parts[0], '/') !== false) {
                    for ($z = 6; $z < $max_idx; $z++) {
                        $data[$z] = "\x00";
                    }
                    $stats = explode('/', $parts[0]);
                    $idx = 6;
                    // Native=1, A.Beast=2, Machine=3, Dark=4, Hit=5
                    for ($i = 0; $i < 5; $i++) {
                        if (isset($stats[$i]) && (int)$stats[$i] > 0 && $idx < $max_idx) {
                            $data[$idx] = chr($i + 1);
                            $data[$idx + 1] = chr((int)$stats[$i]);
                            $idx += 2;
                        }
                    }
                }
                if ($is_unsealable) {
                    $data[10] = "\x80";
                    $data[11] = "\x00";
                }
            } else if ($is_armor_shield) {
                foreach ($parts as $token) {
                    if (substr($token, 0, 1) === '+') {
                        if (strpos($token, 'def') !== false) {
                            $val = intval(str_replace('def', '', substr($token, 1)));
                            $data[6] = chr($val & 0xFF);
                            $data[7] = chr(($val >> 8) & 0xFF);
                        } else if (strpos($token, 'evp') !== false) {
                            $val = intval(str_replace('evp', '', substr($token, 1)));
                            $data[8] = chr($val & 0xFF);
                            $data[9] = chr(($val >> 8) & 0xFF);
                        } else {
                            $val = intval(substr($token, 1));
                            $data[5] = chr($val & 0xFF);
                        }
                    }
                }
            } else if ($is_unit) {
                foreach ($parts as $token) {
                    if (substr($token, 0, 1) === '+') {
                        preg_match('/\+([0-9]+)/', $token, $matches);
                        if (!empty($matches[1])) {
                            $val = intval($matches[1]);
                            $data[6] = chr($val & 0xFF);
                            $data[7] = chr(($val >> 8) & 0xFF);
                        }
                    }
                }
                if ($is_unsealable) {
                    $data[10] = "\x80";
                    $data[11] = "\x00";
                }
            } else if ($is_mag && strlen($firstPart) === 6) {
                // Default Lv.5 Mag stats matching ItemMagStats (5 DEF, 40% Synchro, Red color=14)
                $data[2] = chr(5);
                $data[4] = chr(0xF4); // 500 & 0xFF
                $data[5] = chr(0x01); // 500 >> 8
                $data[12] = chr(40);  // Synchro
                $data[15] = chr(14);  // Default color index
            }
            
            return strtoupper(bin2hex($data));
        }
        
        return $itemStr;
    }
}

/**
 * Robustly parses a reward string (like "Photon Drop x2", "Disk:Shifta Lv.15", or "001006 0/30/0/20")
 * and sends the exact shell-exec payload(s) to NewServ.
 * 
 * @return array Assc. array with "success" (bool), "dropped" (array of payloads), or "error" (string).
 */
function parse_and_drop_items($accountId, $itemString, $characterName = null)
{
    global $NEWSERV_API_URL, $NEWSERV_COMMAND_PREFIX;

    // Disambiguate the target identity to bypass NewServ's "multiple clients found" bug.
    // NewServ parses numeric identifiers as both Hex and Decimal simultaneously, causing collisions.
    // BB Usernames are alphanumeric, bypassing this flaw.
    $targetIdent = $accountId;
    $clients_raw = @file_get_contents($NEWSERV_API_URL . "/y/clients");
    if ($clients_raw !== FALSE) {
        $clients = json_decode($clients_raw, true);
        if (is_array($clients)) {
            foreach ($clients as $c) {
                if (isset($c['Account']) && $c['Account']['AccountID'] == $accountId) {
                    // Enforce strict character matching if a character name was provided
                    if ($characterName !== null && isset($c['Name']) && $c['Name'] !== $characterName) {
                        continue;
                    }
                    if (!empty($c['Account']['BBLicenses']) && is_array($c['Account']['BBLicenses'])) {
                        $targetIdent = $c['Account']['BBLicenses'][0]['UserName'];
                    }
                    break;
                }
            }
        }
    }

    $segments = array_values(array_filter(array_map('trim', explode(',', (string)$itemString)), fn($s) => $s !== ''));
    $droppedPayloads = [];
    $buildRemaining = function (int $segIdx, int $doneInSeg = 0, int $totalInSeg = 1, string $segBase = '') use ($segments): string {
        $rem = [];
        if ($doneInSeg > 0 && $doneInSeg < $totalInSeg && $segBase !== '') {
            $left = $totalInSeg - $doneInSeg;
            $rem[] = ($left > 1) ? "{$segBase} x{$left}" : $segBase;
            $rem = array_merge($rem, array_slice($segments, $segIdx + 1));
        } else {
            $rem = array_slice($segments, $segIdx);
        }
        return implode(', ', $rem);
    };

    foreach ($segments as $segIdx => $rawItem) {
        $multiplier = 1;
        $baseItemName = $rawItem;

        // Parse multipliers (e.g. "Photon Drop x2" -> 2x "Photon Drop", or "2x Photon Drop")
        if (preg_match('/^(.*)\s+x(\d+)$/i', $rawItem, $matches)) {
            $baseItemName = trim($matches[1]);
            $multiplier = intval($matches[2]);
        } else if (preg_match('/^(\d+)x\s+(.*)$/i', $rawItem, $matches)) {
            $multiplier = intval($matches[1]);
            $baseItemName = trim($matches[2]);
        }
        $origBaseName = $baseItemName;

        // Defer cap until after stackable Photon Drops/Tickets (03100[0-4]) are packed into byte 5.

        // Parse Meseta natively into pure hex payload (e.g. "1000 Meseta")
        if (preg_match('/^(\d+)\s*meseta$/i', $baseItemName, $mmatch)) {
            $amount = intval($mmatch[1]);
            $data = str_repeat("\x00", 16);
            $data[0] = chr(0x04);
            $data[12] = chr($amount & 0xFF);
            $data[13] = chr(($amount >> 8) & 0xFF);
            $data[14] = chr(($amount >> 16) & 0xFF);
            $data[15] = chr(($amount >> 24) & 0xFF);
            $baseItemName = strtoupper(bin2hex($data));
        }

        // Parse Disks specifically because of their level mechanic.
        // In PSOBB ItemData: byte 2 = level - 1 (0..29), byte 4 = technique ID (0x00..0x12).
        if (stripos($baseItemName, 'disk:') === 0) {
            if (preg_match('/^Disk:([A-Za-z]+)(?:\s+Lv\.(\d+))?$/i', $baseItemName, $dmatch)) {
                $techName = strtolower($dmatch[1]);
                $techLevel = isset($dmatch[2]) ? max(0, min(29, intval($dmatch[2]) - 1)) : 0; // 0-indexed in memory

                $techMap = [
                    'foie' => 0x00,
                    'gifoie' => 0x01,
                    'rafoie' => 0x02,
                    'barta' => 0x03,
                    'gibarta' => 0x04,
                    'rabarta' => 0x05,
                    'zonde' => 0x06,
                    'gizonde' => 0x07,
                    'razonde' => 0x08,
                    'grants' => 0x09,
                    'deband' => 0x0A,
                    'jellen' => 0x0B,
                    'zalure' => 0x0C,
                    'shifta' => 0x0D,
                    'ryuker' => 0x0E,
                    'resta' => 0x0F,
                    'anti' => 0x10,
                    'reverser' => 0x11,
                    'megid' => 0x12
                ];

                if (isset($techMap[$techName]) && (isset($dmatch[2]) || $techName === 'ryuker' || $techName === 'reverser')) {
                    if ($techName === 'ryuker' || $techName === 'reverser') {
                        $techLevel = 0;
                    }
                    $data = str_repeat("\x00", 16);
                    $data[0] = chr(0x03);
                    $data[1] = chr(0x02);
                    $data[2] = chr($techLevel);
                    $data[4] = chr($techMap[$techName]);
                    $baseItemName = strtoupper(bin2hex($data));
                }
            }
        }

        // Canonical BB V4 exact name lookup (avoids legacy V1/V2 shifted codes from item_hex.txt)
        $bbMaps = get_bb_item_name_maps();
        $firstWord = explode(' ', $baseItemName)[0];
        if (!ctype_xdigit($firstWord) || strlen($firstWord) < 6) {
            if (stripos($baseItemName, 'ES ') !== 0) {
                $searchName = strtolower(preg_replace('/\s+x1$/i', '', trim($baseItemName)));
                if (isset($bbMaps['name_to_hex'][$searchName])) {
                    $baseItemName = $bbMaps['name_to_hex'][$searchName];
                } else {
                    return [
                        "success" => false,
                        "error" => "Unknown or unsupported PSOBB item name: " . $baseItemName,
                        "dropped" => $droppedPayloads,
                        "remaining" => $buildRemaining($segIdx),
                    ];
                }
            }
        }

        // Stackable BB consumables/tools (type1 0x00, 0x01, 0x03..0x09 -> 10; 0x0A..0x15 -> 99)
        // drop as a single stack (byte 5 = count) rather than spamming separate floor boxes.
        if ($multiplier > 1 && preg_match('/^03([0-9a-f]{2})[0-9a-f]{2}$/i', trim($baseItemName), $tm)) {
            $toolT1 = hexdec($tm[1]);
            if ($toolT1 !== 0x02 && $toolT1 <= 0x15) {
                $maxToolStack = ($toolT1 <= 0x09) ? 10 : 99;
                if ($multiplier <= $maxToolStack) {
                    $stackData = hex2bin(str_pad(strtoupper(trim($baseItemName)), 32, '0'));
                    $stackData[5] = chr($multiplier);
                    $baseItemName = strtoupper(bin2hex($stackData));
                    $multiplier = 1;
                }
            }
        }
        if ($multiplier > 50) {
            $multiplier = 50; // Cap non-stacked drops to prevent server spam
        }

        // Execute the drop multiple times if necessary
        for ($i = 0; $i < $multiplier; $i++) {
            $finalPayload = buildHexPayload($baseItemName);
            if (stripos($finalPayload, 'ES ') !== 0) {
                if (!ctype_xdigit($finalPayload) || strlen($finalPayload) !== 32) {
                    return [
                        "success" => false,
                        "error" => "Blocked malformed item payload: " . $finalPayload,
                        "dropped" => $droppedPayloads,
                        "remaining" => $buildRemaining($segIdx, $i, $multiplier, $origBaseName),
                    ];
                }
                $b0 = hexdec(substr($finalPayload, 0, 2));
                $b1 = hexdec(substr($finalPayload, 2, 2));
                $base6 = strtoupper(substr($finalPayload, 0, 6));
                if ($b0 === 0x04) {
                    // Valid Meseta payload
                } elseif ($b0 === 0x02) {
                    // Mags encode level in byte 2 (02<magIndex><level>), while names-v4.json indexes byte 2 as 00
                    $magBase6 = sprintf('02%02X00', $b1);
                    if (!isset($bbMaps['hex_to_name'][$magBase6])) {
                        return [
                            "success" => false,
                            "error" => "Blocked invalid PSOBB Mag code: " . $magBase6,
                            "dropped" => $droppedPayloads,
                            "remaining" => $buildRemaining($segIdx, $i, $multiplier, $origBaseName),
                        ];
                    }
                } elseif ($b0 === 0x03 && $b1 === 0x02) {
                    $diskLvl = hexdec(substr($finalPayload, 4, 2));
                    $diskTech = hexdec(substr($finalPayload, 8, 2));
                    if ($diskLvl > 29 || $diskTech > 18) {
                        return [
                            "success" => false,
                            "error" => "Blocked invalid Tech Disk payload: " . $finalPayload,
                            "dropped" => $droppedPayloads,
                            "remaining" => $buildRemaining($segIdx, $i, $multiplier, $origBaseName),
                        ];
                    }
                } elseif ($b0 === 0x00 && (($b1 >= 0x70 && $b1 <= 0x88) || ($b1 >= 0xA5 && $b1 <= 0xA9))) {
                    // Valid S-Rank weapon payload
                } elseif (!isset($bbMaps['hex_to_name'][$base6])) {
                    return [
                        "success" => false,
                        "error" => "Blocked invalid PSOBB item code: " . $base6,
                        "dropped" => $droppedPayloads,
                        "remaining" => $buildRemaining($segIdx, $i, $multiplier, $origBaseName),
                    ];
                }
            }
            $cmd = "on " . $targetIdent . " cc {$NEWSERV_COMMAND_PREFIX}item " . $finalPayload;

            $execRes = newserv_shell_exec($cmd);
            if ($execRes === false) {
                return [
                    "success" => false,
                    "error" => "Failed to connect to game server API.",
                    "dropped" => $droppedPayloads,
                    "remaining" => $buildRemaining($segIdx, $i, $multiplier, $origBaseName),
                ];
            }

            $execData = json_decode($execRes, true);
            if (isset($execData['Error']) && $execData['Error']) {
                $serverMsg = $execData['Message'] ?? "Unknown server error.";
                return [
                    "success" => false,
                    "error" => "Game server rejected the drop: " . $serverMsg,
                    "dropped" => $droppedPayloads,
                    "remaining" => $buildRemaining($segIdx, $i, $multiplier, $origBaseName),
                ];
            }
            if (isset($execData['result'])) {
                $resStr = strtolower($execData['result']);
                if (strpos($resStr, 'error') !== false || strpos($resStr, 'not found') !== false || strpos($resStr, 'failed') !== false) {
                    return [
                        "success" => false,
                        "error" => "Could not drop item: " . $execData['result'],
                        "dropped" => $droppedPayloads,
                        "remaining" => $buildRemaining($segIdx, $i, $multiplier, $origBaseName),
                    ];
                }
            }

            $droppedPayloads[] = $finalPayload;

            // 100ms stutter-step to ensure NewServ correctly renders each physical box 
            // on the client floor instead of dropping packets on multi-item rewards
            usleep(100000);
        }
    }

    return ["success" => true, "dropped" => $droppedPayloads];
}

