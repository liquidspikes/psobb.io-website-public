<?php
/**
 * --------------------------------------------------------------------------
 * Bounty System Rework (https://feedback.psobb.io/posts/12/bounty-system-rework)
 * --------------------------------------------------------------------------
 * Tiers (Common → Mythic Rare), daily and weekly bounties, active slots and free-earn caps per
 * reset, drops from bosses and rare enemies, Battle/Challenge pools, a waiting queue, expiry at
 * the reset, one free reroll for team bounties, and tier-based rewards whose stats stay hidden
 * until the bounty is claimed. The numbers live in bounty_default_config() and can be
 * overridden in config/bounties.json (see api/BOUNTY_SYSTEM.md).
 *
 * Life of a bounty (player_missions.status):
 *   queued        earned (login, completion, drop, Battle/Challenge play) and waiting for a slot.
 *                 Only its tier, pool, cadence and reward base exist yet.
 *   in_progress   active: when a slot frees, the cron picks the objective and Gemini writes
 *                 the story, so targets are always set against the hunter's current stats.
 *   rerolling     a team bounty whose one free reroll was requested; the cron gives it a new
 *                 objective and story, keeping the tier and reward.
 *   ready_to_redeem → redeeming → completed   as before; the reward's hidden stats roll on claim.
 *   expired       queued/active/rerolling bounties at the daily or weekly reset.
 *   abandoned     as before.
 *
 * Rows from before the rework have no cadence; they are left alone (no expiry, no slot use).
 */
require_once __DIR__ . '/bounty_generator.php';
require_once __DIR__ . '/reward_tables.php';

const BOUNTY_TIERS = ['common', 'uncommon', 'rare', 'ultra', 'mythic'];

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

function bounty_default_config()
{
    return [
        // Master switch: false restores the original bounty flow (3 bounties, 10% chance a minute).
        'enabled' => true,
        // Whose slots and caps: 'character' (each character has its own) or 'account' (shared).
        'limit_scope' => 'character',
        // Resets, in the server's time zone (or 'timezone', e.g. "America/Chicago").
        // weekly_day: 1 = Monday ... 7 = Sunday.
        'reset' => ['daily_hour' => 0, 'weekly_day' => 1, 'timezone' => null],
        'pools' => [
            // Points 3-4: 3 + 3 active; free earning capped at 5 dailies a day and 10 weeklies a week.
            'standard' => [
                'daily' => ['active' => 3, 'free_cap' => 5],
                'weekly' => ['active' => 3, 'free_cap' => 10],
            ],
            // Drops (point 6): their own 5 active slots; at 10 held (active + waiting) nothing more drops.
            'drop' => ['active' => 5, 'held_max' => 10],
            // Point 13: Battle Mode gives 3 dailies, Challenge Mode 3 weeklies, earned by playing them.
            'battle' => ['daily' => ['active' => 3, 'cap' => 3]],
            'challenge' => ['weekly' => ['active' => 3, 'cap' => 3]],
        ],
        'earn' => [
            // Chance per cron pass (about once a minute) while logged in, if under the cap.
            'login_daily_chance' => 10,
            'login_weekly_chance' => 3,
            // Each completed standard bounty earns another of the same cadence (if under the cap).
            'completion_grants' => 1,
            // Chance per cron pass while in a Battle or Challenge game, if under that pool's cap.
            'battle_chance' => 25,
            'challenge_chance' => 25,
            // Tier odds for free bounties: Common, Uncommon, Rare.
            'free_tier_weights' => [60, 30, 10],
        ],
        'drops' => [
            // Points 6-7: chance (%) of a bounty drop per boss kill / rare-enemy kill, by difficulty.
            'boss_chance' => ['Normal' => 5, 'Hard' => 8, 'Very Hard' => 12, 'Ultimate' => 18],
            'rare_enemy_chance' => ['Normal' => 10, 'Hard' => 15, 'Very Hard' => 20, 'Ultimate' => 30],
            // Tier odds for drops by difficulty: Common, Uncommon, Rare, Ultra Rare, Mythic Rare.
            'tier_weights' => [
                'Normal' => [55, 30, 12, 2, 1],
                'Hard' => [45, 32, 17, 4, 2],
                'Very Hard' => [35, 33, 22, 7, 3],
                'Ultimate' => [25, 30, 30, 10, 5],
            ],
            // Point 8: Ultra Rare only drops in a Multi-mode game with 2+ players.
            // Point 9: Mythic Rare only drops when playing alone (Solo mode, or alone in a Multi-mode game).
            // Point 10: alone in a Multi-mode game, the Mythic weight is multiplied by this.
            'solo_in_multi_mythic_multiplier' => 1.5,
            // Cadence of dropped bounties: these tiers are weeklies, Rare is weekly this % of the time,
            // the rest are dailies.
            'weekly_tiers' => ['ultra', 'mythic'],
            'rare_weekly_chance' => 50,
        ],
        // Points 15-16: levels of "growth" added when picking equipment from the level-scaled pools.
        'level_delta' => ['common' => 10, 'uncommon' => 10, 'rare' => 10, 'ultra' => 50, 'mythic' => 50],
        // Point 14: these objectives can be rerolled once.
        'reroll_goal_types' => ['MENTOR_BOSS', 'HARDCORE_MENTOR', 'DIVERSE_PARTY_BOSS'],
        'rewards' => [
            // Point 17: Common = tools and consumables. [hex, min qty, max qty, weight, min level]
            'common_items' => [
                ['030000', 3, 5, 10, 1], ['030001', 2, 4, 10, 20], ['030002', 1, 3, 8, 60],
                ['030100', 3, 5, 8, 1], ['030101', 2, 4, 8, 20], ['030102', 1, 2, 6, 60],
                ['030300', 2, 4, 6, 1], ['030400', 1, 3, 6, 1], ['030500', 1, 2, 4, 30],
                ['030600', 2, 4, 5, 1], ['030601', 2, 4, 5, 1], ['030700', 2, 4, 6, 1],
                ['030800', 2, 3, 3, 1], ['030A00', 1, 3, 5, 10], ['030A01', 1, 2, 4, 40],
                ['030A02', 1, 1, 2, 80], ['030900', 1, 1, 1, 50],
            ],
            // Point 18: Ultra Rare = Mag Cells and combination items. [hex, weight]
            'ultra_items' => [
                ['030C00', 6], ['030C01', 6], ['030C02', 4], ['030C03', 4], ['030C04', 4], ['030C05', 4],
                ['030E0B', 3], ['030E0C', 3], ['030E0D', 2], ['030E0E', 2], ['030E0F', 3], ['030E10', 3],
                ['030E11', 3], ['030E12', 3], ['030E13', 3], ['030E14', 3], ['030E15', 3], ['030E26', 2],
                ['031806', 2], ['031807', 3], ['031808', 3], ['03180A', 3],
                ['03180B', 2], ['03180C', 2], ['03180D', 2], ['03180E', 2], ['03180F', 2],
                ['030D00', 3], ['030D0A', 3], ['030D0D', 3], ['030E00', 3], ['030E05', 4], ['030E06', 3],
                ['030E09', 3], ['030E27', 3], ['031802', 3], ['031804', 3],
            ],
            // Point 18: Mythic Rare = the server's most coveted drops. [hex, weight]
            'mythic_items' => [
                ['030E01', 10], // Parasitic Gene "Flow" (PGF)
                ['031002', 4],  // Photon Crystal
                ['031803', 2],  // Heaven Striker Coat
            ],
            // Meseta per tier before the level multiplier (1 + level / 50), randomised ±50%.
            'meseta' => ['common' => 1000, 'uncommon' => 2500, 'rare' => 5000, 'ultra' => 15000, 'mythic' => 30000],
        ],
        // Point 19: hidden stats rolled on claim, by profile; attribute and hit caps are scaled by
        // difficulty (Normal 0.6, Hard 0.8, Very Hard 1.0, Ultimate 1.2), in steps of 5.
        'roll_profiles' => [
            'uncommon' => ['attrs' => [0, 2], 'attr_max' => 20, 'hit_chance' => 0, 'hit_max' => 0, 'slots' => [0, 1], 'bonus_max' => 10],
            'rare_common' => ['attrs' => [1, 3], 'attr_max' => 45, 'hit_chance' => 30, 'hit_max' => 20, 'slots' => [1, 3], 'bonus_max' => 20],
            'rare' => ['attrs' => [1, 3], 'attr_max' => 40, 'hit_chance' => 20, 'hit_max' => 15, 'slots' => [1, 3], 'bonus_max' => 20],
            'ultra' => ['attrs' => [2, 3], 'attr_max' => 55, 'hit_chance' => 40, 'hit_max' => 25, 'slots' => [2, 4], 'bonus_max' => 25],
            'mythic' => ['attrs' => [2, 3], 'attr_max' => 70, 'hit_chance' => 60, 'hit_max' => 40, 'slots' => [3, 4], 'bonus_max' => 30],
        ],
    ];
}

/**
 * The settings: defaults, then config/bounties.json. Passing $override (tests only) merges it
 * over the current settings for the rest of the run.
 */
function bounty_config(?array $override = null)
{
    static $cfg = null;
    if ($override !== null) {
        $cfg = array_replace_recursive(bounty_config(), $override);
        return $cfg;
    }
    if ($cfg !== null) {
        return $cfg;
    }
    $cfg = bounty_default_config();
    $path = __DIR__ . '/../config/bounties.json';
    if (is_readable($path)) {
        $over = json_decode((string) file_get_contents($path), true);
        if (is_array($over)) {
            $cfg = array_replace_recursive($cfg, $over);
        } else {
            // error_log, not echo: web pages and JSON endpoints load this file too.
            error_log("[BOUNTY] config/bounties.json is not valid JSON; using defaults.");
        }
    }
    return $cfg;
}

function bounty_rework_enabled()
{
    return !empty(bounty_config()['enabled']);
}

function bounty_tier_label($tier)
{
    $labels = ['common' => 'Common', 'uncommon' => 'Uncommon', 'rare' => 'Rare', 'ultra' => 'Ultra Rare', 'mythic' => 'Mythic Rare'];
    return $labels[$tier] ?? '';
}

function bounty_kind_label($pool, $cadence)
{
    $c = ($cadence === 'weekly') ? 'Weekly' : 'Daily';
    switch ($pool) {
        case 'drop': return "Drop · $c";
        case 'battle': return "Battle Mode · $c";
        case 'challenge': return "Challenge Mode · $c";
    }
    return $c;
}

// ---------------------------------------------------------------------------
// Resets
// ---------------------------------------------------------------------------

function bounty_timezone()
{
    $tz = bounty_config()['reset']['timezone'] ?? null;
    try {
        return new DateTimeZone($tz ?: date_default_timezone_get());
    } catch (Exception $e) {
        return new DateTimeZone(date_default_timezone_get());
    }
}

/**
 * Start and end (unix seconds) of the current daily or weekly period.
 */
function bounty_period_bounds($cadence, $now = null)
{
    $now = $now ?? time();
    $cfg = bounty_config()['reset'];
    $hour = (int) ($cfg['daily_hour'] ?? 0);
    $d = (new DateTime('@' . $now))->setTimezone(bounty_timezone());
    $start = (clone $d)->setTime($hour, 0, 0);
    if ($start->getTimestamp() > $now) {
        $start->modify('-1 day');
    }
    if ($cadence === 'weekly') {
        $want = max(1, min(7, (int) ($cfg['weekly_day'] ?? 1)));
        $back = ((int) $start->format('N') - $want + 7) % 7;
        if ($back) {
            $start->modify("-{$back} day");
        }
        $end = (clone $start)->modify('+7 day');
    } else {
        $end = (clone $start)->modify('+1 day');
    }
    return [$start->getTimestamp(), $end->getTimestamp()];
}

function bounty_period_key($cadence, $now = null)
{
    list($start) = bounty_period_bounds($cadence, $now);
    $d = (new DateTime('@' . $start))->setTimezone(bounty_timezone());
    return (($cadence === 'weekly') ? 'W' : 'D') . $d->format('Y-m-d');
}

/**
 * Point 5: unfinished bounties expire at their reset. Completed-but-unclaimed ones never do.
 */
function bounty_expire_sweep($db, $now = null)
{
    $now = $now ?? time();
    $db->exec("UPDATE player_missions SET status = 'expired'
               WHERE status IN ('queued', 'in_progress', 'rerolling') AND expires_at IS NOT NULL AND expires_at <= " . (int) $now);
    return $db->changes();
}

// ---------------------------------------------------------------------------
// Rooms
// ---------------------------------------------------------------------------

/**
 * Summarises /y/lobbies by lobby ID: episode (1, 2, 4), difficulty, mode (Normal, Battle,
 * Challenge, Solo) and the number of players in the room.
 */
function bounty_lobby_info_map(array $lobbies)
{
    $out = [];
    foreach ($lobbies as $l) {
        if (!isset($l['ID'])) {
            continue;
        }
        $ep = null;
        if (isset($l['Episode']) && preg_match('/(\d)/', (string) $l['Episode'], $mm)) {
            $ep = (int) $mm[1];
        }
        $players = 0;
        foreach (($l['ClientIDs'] ?? []) as $cid) {
            if ($cid !== null) {
                $players++;
            }
        }
        $out[$l['ID']] = [
            'is_game' => !empty($l['IsGame']),
            'episode' => $ep,
            'difficulty' => $l['Difficulty'] ?? 'Normal',
            'mode' => $l['Mode'] ?? 'Normal',
            'players' => $players,
        ];
    }
    return $out;
}

function bounty_difficulty_rank($difficulty)
{
    $r = ['Normal' => 0, 'Hard' => 1, 'Very Hard' => 2, 'Ultimate' => 3];
    return $r[$difficulty] ?? 0;
}

function bounty_recommended_difficulty($level)
{
    if ($level >= 80) return 'Ultimate';
    if ($level >= 40) return 'Very Hard';
    if ($level >= 20) return 'Hard';
    return 'Normal';
}

// ---------------------------------------------------------------------------
// Slots and caps
// ---------------------------------------------------------------------------

/**
 * SQL condition (and bindings) selecting this player's rows, per limit_scope.
 */
function bounty_scope($acc, $char)
{
    if ((bounty_config()['limit_scope'] ?? 'character') === 'account') {
        return ['pm.account_id = :acc', [':acc' => [(int) $acc, SQLITE3_INTEGER]]];
    }
    return ['pm.account_id = :acc AND pm.character_name = :cname', [':acc' => [(int) $acc, SQLITE3_INTEGER], ':cname' => [(string) $char, SQLITE3_TEXT]]];
}

function bounty_count($db, $acc, $char, $where, array $binds = [])
{
    list($scope, $sb) = bounty_scope($acc, $char);
    $stmt = $db->prepare("SELECT COUNT(*) FROM player_missions pm JOIN missions m ON pm.mission_id = m.id WHERE $scope AND $where");
    foreach ($sb + $binds as $k => $v) {
        $stmt->bindValue($k, $v[0], $v[1]);
    }
    $row = $stmt->execute()->fetchArray(SQLITE3_NUM);
    return (int) ($row[0] ?? 0);
}

function bounty_active_count($db, $acc, $char, $pool, $cadence = null)
{
    $where = "m.pool = :pool AND pm.status IN ('in_progress', 'rerolling')";
    $b = [':pool' => [$pool, SQLITE3_TEXT]];
    if ($cadence !== null) {
        $where .= " AND m.cadence = :cad";
        $b[':cad'] = [$cadence, SQLITE3_TEXT];
    }
    return bounty_count($db, $acc, $char, $where, $b);
}

function bounty_queued_count($db, $acc, $char, $pool, $cadence = null)
{
    $where = "m.pool = :pool AND pm.status = 'queued'";
    $b = [':pool' => [$pool, SQLITE3_TEXT]];
    if ($cadence !== null) {
        $where .= " AND m.cadence = :cad";
        $b[':cad'] = [$cadence, SQLITE3_TEXT];
    }
    return bounty_count($db, $acc, $char, $where, $b);
}

/**
 * Bounties of a pool earned in the current period (counts every one earned, whatever became of it).
 */
function bounty_earned_count($db, $acc, $char, $pool, $cadence, array $sources = [])
{
    $where = "m.pool = :pool AND m.cadence = :cad AND pm.period_key = :pk";
    $b = [':pool' => [$pool, SQLITE3_TEXT], ':cad' => [$cadence, SQLITE3_TEXT], ':pk' => [bounty_period_key($cadence), SQLITE3_TEXT]];
    if ($sources) {
        $in = [];
        foreach (array_values($sources) as $i => $s) {
            $in[] = ":src$i";
            $b[":src$i"] = [$s, SQLITE3_TEXT];
        }
        $where .= " AND m.source IN (" . implode(',', $in) . ")";
    }
    return bounty_count($db, $acc, $char, $where, $b);
}

// ---------------------------------------------------------------------------
// Tiers
// ---------------------------------------------------------------------------

function bounty_weighted_pick(array $weights_by_key)
{
    $total = 0;
    foreach ($weights_by_key as $w) {
        $total += max(0, $w);
    }
    if ($total <= 0) {
        return null;
    }
    $r = mt_rand(1, (int) round($total * 100)) / 100;
    foreach ($weights_by_key as $k => $w) {
        $r -= max(0, $w);
        if ($r <= 0) {
            return $k;
        }
    }
    return array_key_last($weights_by_key);
}

function bounty_roll_free_tier()
{
    $w = bounty_config()['earn']['free_tier_weights'];
    return bounty_weighted_pick(['common' => $w[0] ?? 60, 'uncommon' => $w[1] ?? 30, 'rare' => $w[2] ?? 10]);
}

/**
 * Points 7-10: drop tier by difficulty and room. Returns null when nothing can drop here
 * (Battle/Challenge games, or not in a game).
 */
function bounty_roll_drop_tier($lobby)
{
    if (!$lobby || empty($lobby['is_game']) || !in_array($lobby['mode'], ['Normal', 'Solo'], true)) {
        return null;
    }
    $cfg = bounty_config()['drops'];
    $w = $cfg['tier_weights'][$lobby['difficulty']] ?? $cfg['tier_weights']['Normal'];
    $weights = array_combine(BOUNTY_TIERS, array_pad(array_slice($w, 0, 5), 5, 0));
    $alone = ((int) $lobby['players'] <= 1);
    if ($lobby['mode'] === 'Solo') {
        $weights['ultra'] = 0;                    // Ultra Rare: Multi-mode only
    } elseif ($alone) {
        $weights['ultra'] = 0;                    // a Multi-mode room with one player is not a party
        $weights['mythic'] *= (float) $cfg['solo_in_multi_mythic_multiplier'];
    } else {
        $weights['mythic'] = 0;                   // Mythic Rare: only when playing alone
    }
    return bounty_weighted_pick($weights);
}

// ---------------------------------------------------------------------------
// Rewards (points 15-19)
// ---------------------------------------------------------------------------

function bounty_random_class()
{
    $classes = ['HUmar', 'HUnewearl', 'HUcast', 'HUcaseal', 'RAmar', 'RAmarl', 'RAcast', 'RAcaseal', 'FOmar', 'FOmarl', 'FOnewm', 'FOnewearl'];
    return $classes[array_rand($classes)];
}

/**
 * The reward tables pre-roll some stats into their 32-hex payloads (attributes on common
 * weapons; slots, DEF and EVP on armor and shields). A bounty's stats are rolled on claim
 * instead (point 19), so those bytes are cleared here. The weapon's special and its
 * "untekked" flag stay. Units keep their table modifier (the "++").
 */
function bounty_strip_rolled_stats($base, $cat)
{
    $first = explode(' ', (string) $base)[0];
    if (!ctype_xdigit($first) || strlen($first) !== 32) {
        return $base;
    }
    $data = hex2bin($first);
    if ($cat === 'Weapon' && $data[0] === "\x00") {
        for ($i = 6; $i < 12; $i++) $data[$i] = "\x00";
    } elseif (($cat === 'Armor' || $cat === 'Shield') && $data[0] === "\x01" && ($data[1] === "\x01" || $data[1] === "\x02")) {
        for ($i = 5; $i < 10; $i++) $data[$i] = "\x00";
    } else {
        return $base;
    }
    return strtoupper(bin2hex($data));
}

/**
 * Builds the reward when the bounty is earned. Item bases are chosen now (and shown on the
 * board); attributes, hit, slots and DEF/EVP bonuses are only rolled on claim.
 *
 * @return array ['tier', 'difficulty', 'items' => [['base', 'cat', 'profile']], 'meseta']
 */
function bounty_build_reward_spec($tier, $level, $class, $difficulty)
{
    $cfg = bounty_config();
    $delta = (int) ($cfg['level_delta'][$tier] ?? 10);
    $grow = min(200, (int) $level + $delta);
    $items = [];
    $pick_cat = function () {
        $r = mt_rand(1, 100);
        return ($r <= 45) ? 'Weapon' : (($r <= 75) ? 'Armor' : 'Shield');
    };
    switch ($tier) {
        case 'common':
            $pool = [];
            foreach ($cfg['rewards']['common_items'] as $i => $ci) {
                if ($level >= ($ci[4] ?? 1)) {
                    $pool[$i] = $ci[3];
                }
            }
            $n = mt_rand(2, 3);
            for ($k = 0; $k < $n && $pool; $k++) {
                $i = bounty_weighted_pick($pool);
                unset($pool[$i]);
                $ci = $cfg['rewards']['common_items'][$i];
                $qty = mt_rand($ci[1], $ci[2]);
                $items[] = ['base' => $ci[0] . ($qty > 1 ? " x$qty" : ''), 'cat' => 'Tool', 'profile' => null];
            }
            break;
        case 'uncommon':
            $n = mt_rand(1, 2);
            for ($k = 0; $k < $n; $k++) {
                $cat = $pick_cat();
                $items[] = ['base' => bounty_strip_rolled_stats(get_common_reward_item($grow, $class, $cat), $cat), 'cat' => $cat, 'profile' => 'uncommon'];
            }
            break;
        case 'rare':
            $cat = (mt_rand(1, 100) <= 70) ? $pick_cat() : 'Unit';
            if ($cat !== 'Unit' && mt_rand(0, 1)) {
                // "better attributes / hit %" on standard equipment
                $items[] = ['base' => bounty_strip_rolled_stats(get_common_reward_item($grow, $class, $cat), $cat), 'cat' => $cat, 'profile' => 'rare_common'];
            } else {
                $items[] = ['base' => bounty_strip_rolled_stats(get_reward_item($grow, $class, $cat), $cat), 'cat' => $cat, 'profile' => 'rare'];
            }
            break;
        case 'ultra':
        case 'mythic':
            $list = $cfg['rewards'][$tier . '_items'];
            $w = [];
            foreach ($list as $i => $it) {
                $w[$i] = $it[1];
            }
            $items[] = ['base' => $list[bounty_weighted_pick($w)][0], 'cat' => 'Tool', 'profile' => null];
            // Point 16: may be for any class
            $cat = (mt_rand(1, 100) <= 80) ? $pick_cat() : 'Unit';
            $items[] = ['base' => bounty_strip_rolled_stats(get_reward_item($grow, bounty_random_class(), $cat), $cat), 'cat' => $cat, 'profile' => $tier];
            break;
    }
    $base = (int) ($cfg['rewards']['meseta'][$tier] ?? 1000);
    $meseta = (int) (round($base * (1 + $level / 50) * mt_rand(50, 150) / 100 / 100) * 100);
    return ['tier' => $tier, 'difficulty' => $difficulty, 'items' => $items, 'meseta' => max(100, $meseta)];
}

/**
 * What the board shows before the claim: item bases only, no stats.
 */
function bounty_reward_display(array $spec)
{
    $parts = [];
    foreach ($spec['items'] as $it) {
        $parts[] = $it['base'];
    }
    $parts[] = $spec['meseta'] . ' Meseta';
    return implode(', ', $parts);
}

/**
 * True while a reward still has stats to roll on claim (equipment with a roll profile).
 */
function bounty_reward_has_hidden_stats($spec)
{
    if (!is_array($spec) || !empty($spec['rolled'])) {
        return false;
    }
    foreach ($spec['items'] ?? [] as $it) {
        if (!empty($it['profile']) && in_array($it['cat'], ['Weapon', 'Armor', 'Shield'], true)) {
            return true;
        }
    }
    return false;
}

function bounty_scaled_cap($cap, $difficulty)
{
    $mult = [0 => 0.6, 1 => 0.8, 2 => 1.0, 3 => 1.2][bounty_difficulty_rank($difficulty)];
    return max(5, min(100, (int) (floor($cap * $mult / 5) * 5)));
}

/**
 * Point 19: rolls the hidden stats and returns the final reward string for the game server.
 */
function bounty_roll_reward_string(array $spec)
{
    $profiles = bounty_config()['roll_profiles'];
    $diff = $spec['difficulty'] ?? 'Normal';
    $parts = [];
    foreach ($spec['items'] as $it) {
        $s = $it['base'];
        $p = $it['profile'] ? ($profiles[$it['profile']] ?? null) : null;
        if ($p && $it['cat'] === 'Weapon') {
            $stats = [0, 0, 0, 0];
            $n = mt_rand($p['attrs'][0], $p['attrs'][1]);
            $idx = [0, 1, 2, 3];
            shuffle($idx);
            $cap = bounty_scaled_cap($p['attr_max'], $diff);
            for ($i = 0; $i < $n; $i++) {
                $stats[$idx[$i]] = mt_rand(1, (int) ($cap / 5)) * 5;
            }
            $hit = 0;
            if ($p['hit_chance'] > 0 && mt_rand(1, 100) <= $p['hit_chance']) {
                $hit = mt_rand(1, max(1, (int) (bounty_scaled_cap($p['hit_max'], $diff) / 5))) * 5;
            }
            if (array_sum($stats) > 0 || $hit > 0) {
                $s .= ' ' . implode('/', $stats) . ($hit > 0 ? "/$hit" : '');
            }
        } elseif ($p && ($it['cat'] === 'Armor' || $it['cat'] === 'Shield')) {
            $bmax = max(1, (int) ($p['bonus_max'] / 5));
            $def = mt_rand(0, $bmax) * 5;
            $evp = mt_rand(0, $bmax) * 5;
            if ($def > 0) $s .= " +{$def}def";
            if ($evp > 0) $s .= " +{$evp}evp";
            $slots = ($it['cat'] === 'Armor') ? mt_rand($p['slots'][0], $p['slots'][1]) : 0;
            if ($slots > 0) $s .= " +{$slots}";
        }
        $parts[] = $s;
    }
    $parts[] = $spec['meseta'] . ' Meseta';
    return implode(', ', $parts);
}

// ---------------------------------------------------------------------------
// Earning (points 4, 6, 13)
// ---------------------------------------------------------------------------

/**
 * Creates a queued bounty: its tier, pool, cadence and reward now; its objective and story
 * when it is activated.
 *
 * @return int|null player_missions id
 */
function bounty_earn($db, $acc, $char, array $client, $pool, $cadence, $tier, $source, array $meta = [])
{
    $level = (int) ($client['Level'] ?? 1);
    $class = (string) ($client['CharClass'] ?? 'HUmar');
    $difficulty = $meta['difficulty'] ?? bounty_recommended_difficulty($level);
    $spec = bounty_build_reward_spec($tier, $level, $class, $difficulty);
    $now = time();
    list(, $expires) = bounty_period_bounds($cadence, $now);

    $ins = $db->prepare("INSERT INTO missions (title, description, goal_type, goal_target, reward_item_string, tier, cadence, pool, source, reward_spec, meta)
                         VALUES ('', '', 'PENDING', '', :ri, :tier, :cad, :pool, :src, :spec, :meta)");
    $ins->bindValue(':ri', bounty_reward_display($spec), SQLITE3_TEXT);
    $ins->bindValue(':tier', $tier, SQLITE3_TEXT);
    $ins->bindValue(':cad', $cadence, SQLITE3_TEXT);
    $ins->bindValue(':pool', $pool, SQLITE3_TEXT);
    $ins->bindValue(':src', $source, SQLITE3_TEXT);
    $ins->bindValue(':spec', json_encode($spec), SQLITE3_TEXT);
    $ins->bindValue(':meta', json_encode($meta), SQLITE3_TEXT);
    if (!$ins->execute()) {
        return null;
    }
    $mid = $db->lastInsertRowID();
    $pm = $db->prepare("INSERT INTO player_missions (account_id, character_name, mission_id, status, period_key, expires_at, earned_at, rerolled, progress)
                        VALUES (:acc, :cname, :mid, 'queued', :pk, :exp, :now, 0, '{}')");
    $pm->bindValue(':acc', (int) $acc, SQLITE3_INTEGER);
    $pm->bindValue(':cname', (string) $char, SQLITE3_TEXT);
    $pm->bindValue(':mid', $mid, SQLITE3_INTEGER);
    $pm->bindValue(':pk', bounty_period_key($cadence, $now), SQLITE3_TEXT);
    $pm->bindValue(':exp', $expires, SQLITE3_INTEGER);
    $pm->bindValue(':now', $now, SQLITE3_INTEGER);
    $pm->execute();
    $id = $db->lastInsertRowID();
    echo "[BOUNTY] {$char}: earned " . bounty_tier_label($tier) . " " . bounty_kind_label($pool, $cadence) . " bounty ($source)\n";
    return $id;
}

/**
 * Point 4: a free standard bounty, if this period's cap allows it.
 */
function bounty_try_free_earn($db, $acc, $char, array $client, $cadence, $source)
{
    $cap = (int) (bounty_config()['pools']['standard'][$cadence]['free_cap'] ?? 0);
    if (bounty_earned_count($db, $acc, $char, 'standard', $cadence, ['login', 'completion']) >= $cap) {
        return null;
    }
    return bounty_earn($db, $acc, $char, $client, 'standard', $cadence, bounty_roll_free_tier(), $source);
}

/**
 * Point 13: a Battle (daily) or Challenge (weekly) bounty, earned by playing that mode.
 */
function bounty_try_mode_earn($db, $acc, $char, array $client, $pool)
{
    $cadence = ($pool === 'challenge') ? 'weekly' : 'daily';
    $cap = (int) (bounty_config()['pools'][$pool][$cadence]['cap'] ?? 0);
    if (bounty_earned_count($db, $acc, $char, $pool, $cadence) >= $cap) {
        return null;
    }
    return bounty_earn($db, $acc, $char, $client, $pool, $cadence, bounty_roll_free_tier(), $pool);
}

/**
 * Points 6-10: a chance of a dropped bounty after a boss or rare-enemy kill.
 *
 * @param string $trigger 'boss' or 'rare'
 * @return array|null ['id', 'tier', 'cadence'] if one dropped
 */
function bounty_try_drop($db, $acc, $char, array $client, $lobby, $trigger, $what = '')
{
    $cfg = bounty_config();
    if (!$lobby || empty($lobby['is_game'])) {
        return null;
    }
    if (bounty_count($db, $acc, $char, "m.pool = 'drop' AND pm.status IN ('queued', 'in_progress', 'rerolling')") >= (int) $cfg['pools']['drop']['held_max']) {
        return null; // holding 10: nothing more drops
    }
    $table = ($trigger === 'rare') ? $cfg['drops']['rare_enemy_chance'] : $cfg['drops']['boss_chance'];
    $chance = (float) ($table[$lobby['difficulty']] ?? 0);
    if (mt_rand(1, 10000) > $chance * 100) {
        return null;
    }
    $tier = bounty_roll_drop_tier($lobby);
    if ($tier === null) {
        return null;
    }
    $weekly = in_array($tier, $cfg['drops']['weekly_tiers'], true)
        || ($tier === 'rare' && mt_rand(1, 100) <= (int) $cfg['drops']['rare_weekly_chance']);
    $cadence = $weekly ? 'weekly' : 'daily';
    $id = bounty_earn($db, $acc, $char, $client, 'drop', $cadence, $tier, $trigger === 'rare' ? 'rare_enemy' : 'boss', [
        'difficulty' => $lobby['difficulty'],
        'mode' => $lobby['mode'],
        'party' => (int) $lobby['players'],
        'dropped_by' => $what,
    ]);
    return $id ? ['id' => $id, 'tier' => $tier, 'cadence' => $cadence] : null;
}

// ---------------------------------------------------------------------------
// Kill tracking
// ---------------------------------------------------------------------------

/**
 * New kills since the last cron pass. Enemy and rare-enemy counts need the newserv patch
 * (EnemyKillCount / RareEnemyKillCount in /y/clients); boss kills come from cron_boss_tracker.php.
 *
 * @return array enemy, rare, rare_name, has_counters, boss_events (new ones), state (to save)
 */
function bounty_kill_deltas(array $client, array $prev_state, array $boss_events)
{
    $has = isset($client['EnemyKillCount']);
    $enemy = $rare = 0;
    if ($has) {
        $cur_e = (int) $client['EnemyKillCount'];
        $cur_r = (int) ($client['RareEnemyKillCount'] ?? 0);
        if (isset($prev_state['bounty_enemy_kills'])) {
            // A lower reading means the player reconnected and newserv's counter restarted.
            $enemy = ($cur_e >= $prev_state['bounty_enemy_kills']) ? $cur_e - $prev_state['bounty_enemy_kills'] : $cur_e;
            $rare = ($cur_r >= ($prev_state['bounty_rare_kills'] ?? 0)) ? $cur_r - ($prev_state['bounty_rare_kills'] ?? 0) : $cur_r;
        }
    }
    $first_sight = !isset($prev_state['bounty_boss_event_time']);
    $last = (int) ($prev_state['bounty_boss_event_time'] ?? 0);
    $new_events = [];
    $max = $last;
    foreach ($boss_events as $e) {
        $t = (int) ($e['time'] ?? 0);
        if ($t > $last && !$first_sight) {
            $new_events[] = $e;
        }
        $max = max($max, $t);
    }
    return [
        'enemy' => $enemy,
        'rare' => $rare,
        'rare_name' => $client['LastRareEnemyKilled'] ?? '',
        'has_counters' => $has,
        'boss_events' => $new_events,
        'state' => [
            'bounty_enemy_kills' => $has ? (int) $client['EnemyKillCount'] : null,
            'bounty_rare_kills' => $has ? (int) ($client['RareEnemyKillCount'] ?? 0) : null,
            // First sight of a player: don't treat kills from before tracking began as new.
            'bounty_boss_event_time' => $first_sight ? max($max, time()) : $max,
        ],
    ];
}

// ---------------------------------------------------------------------------
// Weekly objectives (points 11-12)
// ---------------------------------------------------------------------------

// bounty_episode_floors(), bounty_parse_weekly_target(), bounty_weekly_objective_text() and
// bounty_progress_text() live in functions.php, which every page that shows objectives loads.

/**
 * Point 12: weeklies never require Ultimate, except Mythic Rare ones.
 */
function bounty_weekly_difficulty($level, $tier)
{
    $d = bounty_recommended_difficulty($level);
    if ($d === 'Ultimate' && $tier !== 'mythic') {
        $d = 'Very Hard';
    }
    return $d;
}

/**
 * Updates a weekly objective's progress for this cron pass and reports whether it is done.
 *
 * @param array $m     the mission row (id = player_missions id, goal_type, goal_target, progress, activated_at)
 * @param array $kills from bounty_kill_deltas()
 */
function bounty_evaluate_weekly($db, array $m, array $client, $lobby, array $kills, array $lobby_map)
{
    list($n, $diff) = bounty_parse_weekly_target($m['goal_target']);
    $need = bounty_difficulty_rank($diff);
    $p = json_decode((string) ($m['progress'] ?? ''), true) ?: [];
    $here_ok = $lobby && !empty($lobby['is_game']) && in_array($lobby['mode'], ['Normal', 'Solo'], true)
        && bounty_difficulty_rank($lobby['difficulty']) >= $need;
    $changed = false;
    $done = false;
    switch ($m['goal_type']) {
        case 'AREA_SWEEP':
            $floors = bounty_episode_floors($n);
            $f = (int) ($client['LocationFloor'] ?? -1);
            $visited = $p['visited'] ?? [];
            if ($here_ok && (int) $lobby['episode'] === $n && in_array($f, $floors, true) && !in_array($f, $visited, true)) {
                $visited[] = $f;
                sort($visited);
                $p['visited'] = $visited;
                $changed = true;
            }
            $done = count(array_intersect($floors, $p['visited'] ?? [])) >= count($floors);
            break;
        case 'BOSS_HUNT':
            foreach ($kills['boss_events'] as $e) {
                if (!empty($m['activated_at']) && (int) $e['time'] < (int) $m['activated_at']) {
                    continue;
                }
                $el = $lobby_map[$e['lobby_id'] ?? -1] ?? $lobby;
                if ($el && bounty_difficulty_rank($el['difficulty']) >= $need) {
                    $p['count'] = (int) ($p['count'] ?? 0) + 1;
                    $changed = true;
                }
            }
            $done = (int) ($p['count'] ?? 0) >= $n;
            break;
        case 'ENEMY_HUNT':
        case 'RARE_HUNT':
            $add = ($m['goal_type'] === 'ENEMY_HUNT') ? $kills['enemy'] : $kills['rare'];
            if ($add > 0 && $here_ok) {
                $p['count'] = (int) ($p['count'] ?? 0) + $add;
                $changed = true;
            }
            $done = (int) ($p['count'] ?? 0) >= $n;
            break;
    }
    if ($changed) {
        $u = $db->prepare("UPDATE player_missions SET progress = :p WHERE id = :id");
        $u->bindValue(':p', json_encode($p), SQLITE3_TEXT);
        $u->bindValue(':id', (int) $m['id'], SQLITE3_INTEGER);
        $u->execute();
    }
    return $done;
}

// ---------------------------------------------------------------------------
// Objectives for the rework's pools
// ---------------------------------------------------------------------------

/**
 * Chooses the objective for a bounty being activated.
 *
 * @return array as bounty_pick_objective(), plus 'location_override' and 'extra_lines' for the story
 */
function bounty_pick_rework_objective(array $client, array $row, $curr_f, array $active_types)
{
    $tier = $row['tier'];
    $pool = $row['pool'];
    $cadence = $row['cadence'];
    $level = (int) ($client['Level'] ?? 1);
    $exclude = ['BATTLE_WINS', 'CHALLENGE_STAGES'];
    if (($row['status'] ?? '') === 'rerolling') {
        // A reroll replaces a team objective; don't hand back another one.
        $exclude = array_merge($exclude, bounty_config()['reroll_goal_types']);
    }
    $base = bounty_pick_objective($client, $curr_f, $active_types, ['exclude' => $exclude]);
    $obj = $base;
    $obj['location_override'] = null;

    if ($pool === 'battle') {
        $wins = (int) ($client['BattlePlaceCounts'][0] ?? 0);
        $obj['selected_goal'] = 'BATTLE_WINS';
        $obj['selected_target_id'] = $wins + mt_rand(1, 2);
        $obj['selected_target_friendly'] = $obj['selected_target_id'] . ' total Battle Mode wins';
        $obj['is_location_goal'] = false;
        list($obj['mission_episode'], $obj['setting_id']) = bounty_pick_hub_setting();
    } elseif ($pool === 'challenge') {
        $done = count(array_merge($client['ChallengeTimesEp1Online'] ?? [], $client['ChallengeTimesEp2Online'] ?? []));
        $obj['selected_goal'] = 'CHALLENGE_STAGES';
        $obj['selected_target_id'] = $done + mt_rand(2, 4);
        $obj['selected_target_friendly'] = $obj['selected_target_id'] . ' total Challenge Stages cleared';
        $obj['is_location_goal'] = false;
        list($obj['mission_episode'], $obj['setting_id']) = bounty_pick_hub_setting();
    } elseif ($cadence === 'weekly') {
        $diff = bounty_weekly_difficulty($level, $tier);
        $choices = ['AREA_SWEEP', 'AREA_SWEEP', 'BOSS_HUNT', 'BOSS_HUNT'];
        if (isset($client['EnemyKillCount'])) {
            $choices[] = 'ENEMY_HUNT';
            $choices[] = 'ENEMY_HUNT';
            if (in_array($tier, ['ultra', 'mythic'], true)) {
                $choices[] = 'RARE_HUNT';
            }
        }
        if ($level <= 195) {
            $choices[] = 'LEVEL';
        }
        $choices = array_values(array_diff($choices, $active_types)) ?: ['AREA_SWEEP'];
        $goal = $choices[array_rand($choices)];
        $scale = ['common' => 1.0, 'uncommon' => 1.2, 'rare' => 1.5, 'ultra' => 2.0, 'mythic' => 2.5][$tier] ?? 1.0;
        $obj['selected_goal'] = $goal;
        $obj['is_location_goal'] = false;
        list($obj['mission_episode'], $obj['setting_id']) = bounty_pick_hub_setting();
        $at = ($diff === 'Ultimate') ? 'on Ultimate' : "on $diff or higher";
        if (in_array($goal, ['BOSS_HUNT', 'ENEMY_HUNT', 'RARE_HUNT'], true)) {
            $obj['location_override'] = "This is a week-long job done wherever the hunter fights on Ragol $at; do not tie it to one particular area.";
        }
        switch ($goal) {
            case 'AREA_SWEEP':
                $ep = [1, 2, 4][array_rand([1, 2, 4])];
                $obj['selected_target_id'] = "$ep@$diff";
                $obj['mission_episode'] = $ep;
                $obj['setting_id'] = bounty_lore()['world']['hubs'][(string) $ep] ?? $obj['setting_id'];
                $obj['location_override'] = "This is a week-long job that takes the hunter through every area of Episode $ep (" . bounty_episode_area_names($ep) . ") $at; the client should have a reason to want all of them visited.";
                break;
            case 'BOSS_HUNT':
                $obj['selected_target_id'] = (int) round(mt_rand(3, 5) * $scale) . "@$diff";
                break;
            case 'ENEMY_HUNT':
                $obj['selected_target_id'] = (int) (round(mt_rand(150, 300) * $scale / 10) * 10) . "@$diff";
                break;
            case 'RARE_HUNT':
                $obj['selected_target_id'] = mt_rand(1, 2) . "@$diff";
                break;
            case 'LEVEL':
                $obj['selected_target_id'] = min(200, $level + mt_rand(3, 5));
                break;
        }
        $obj['selected_target_friendly'] = ($goal === 'LEVEL')
            ? 'Level ' . $obj['selected_target_id']
            : bounty_weekly_objective_text($goal, $obj['selected_target_id']);
        $obj['difficulty'] = $diff;
    }

    $obj['extra_lines'] = [
        'Bounty tier: ' . bounty_tier_label($tier) . ' (' . strtolower(bounty_kind_label($pool, $cadence)) . '). '
        . (in_array($tier, ['ultra', 'mythic'], true) ? 'An exceptional job: let the client and the stakes be worthy of it.' : 'Let the stakes fit the tier.'),
    ];
    if ($cadence === 'weekly' && $pool !== 'challenge') {
        $obj['extra_lines'][] = 'It runs for the rest of the week, so it is a longer piece of work than a day job.';
    }
    if ($pool === 'drop' && !empty($row['meta'])) {
        $meta = json_decode($row['meta'], true) ?: [];
        // Rare-enemy drops name the creature. It may belong to another area or a later time than
        // this setting, so the story may use it only when the setting card lists it.
        if (!empty($meta['dropped_by']) && $meta['dropped_by'] !== 'a boss') {
            $obj['extra_lines'][] = 'The hunter came by this job after defeating a rare creature, ' . $meta['dropped_by'] . '. Mention it only if the setting card lists that creature; otherwise leave it out.';
        }
    }
    return $obj;
}

// ---------------------------------------------------------------------------
// Activation and rerolls
// ---------------------------------------------------------------------------

/**
 * Gives a queued or rerolling bounty its objective and story, and makes it active.
 */
function bounty_fill($db, array $row, array $client, array $ctx)
{
    $obj = bounty_pick_rework_objective($client, $row, $ctx['curr_f'], $ctx['active_types']);
    $story = bounty_write_story($client, $obj, [
        'db' => $db,
        'account_id' => $ctx['account_id'],
        'user_lang' => $ctx['user_lang'],
        'recent_titles' => $ctx['recent_titles'],
        'completed_any' => false,
        'extra_lines' => $obj['extra_lines'],
        'location_override' => $obj['location_override'],
    ]);
    if (!$story) {
        return false; // stays queued/rerolling; tried again next pass
    }
    $u = $db->prepare("UPDATE missions SET title = :t, description = :d, goal_type = :g, goal_target = :gt WHERE id = :mid");
    $u->bindValue(':t', $story['title'], SQLITE3_TEXT);
    $u->bindValue(':d', $story['description'], SQLITE3_TEXT);
    $u->bindValue(':g', $obj['selected_goal'], SQLITE3_TEXT);
    $u->bindValue(':gt', (string) $obj['selected_target_id'], SQLITE3_TEXT);
    $u->bindValue(':mid', (int) $row['mission_id'], SQLITE3_INTEGER);
    $u->execute();
    $p = $db->prepare("UPDATE player_missions SET status = 'in_progress', activated_at = :now, progress = '{}' WHERE id = :id");
    $p->bindValue(':now', time(), SQLITE3_INTEGER);
    $p->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
    $p->execute();

    $label = bounty_tier_label($row['tier']) . ' · ' . bounty_kind_label($row['pool'], $row['cadence']);
    $obj_text = getClearObjective($obj['selected_goal'], (string) $obj['selected_target_id'], $story['title'], $story['description']);
    $reward = renderRewardString($row['reward_item_string']);
    $srv = get_server_address();
    $lang = $ctx['user_lang'];
    if ($lang === 'jp') {
        $msg = "新ミッション【{$label}】「{$story['title']}」\n目標: {$obj_text}\n報酬: {$reward}（能力値は受取時に決定）\n{$srv}";
        send_personal_mail($ctx['account_id'], "ハンターズギルド", $msg);
    } elseif ($lang === 'ru') {
        $msg = "Новый заказ [{$label}]: {$story['title']}\nЦель: {$obj_text}\nНаграда: {$reward} (характеристики при получении)\n{$srv}";
        send_personal_mail($ctx['account_id'], "Гильдия Охотников", $msg);
    } else {
        $msg = "New Bounty [{$label}]: {$story['title']}\nObjective: {$obj_text}\nReward: {$reward} (stats roll when claimed)\n\n" . mb_strimwidth($story['description'], 0, 200, '...');
        send_personal_mail($ctx['account_id'], "Hunters Guild", $msg);
    }
    @touch(__DIR__ . '/../db/.bounty_' . $ctx['account_id']);
    echo "[BOUNTY] {$client['Name']}: activated {$label} \"{$story['title']}\" ({$obj['selected_goal']} {$obj['selected_target_id']})\n";
    return true;
}

/**
 * One activation per player per cron pass (each needs a Gemini call): a pending reroll first,
 * then the oldest waiting bounty of any pool with a free slot.
 */
function bounty_activate_next($db, $acc, $char, array $client, array $ctx)
{
    list($scope, $sb) = bounty_scope($acc, $char);
    $base = "SELECT pm.id, pm.mission_id, pm.status, m.tier, m.cadence, m.pool, m.meta, m.reward_item_string
             FROM player_missions pm JOIN missions m ON pm.mission_id = m.id WHERE $scope";
    $fetch = function ($sql_tail, array $binds = []) use ($db, $base, $sb) {
        $stmt = $db->prepare("$base $sql_tail");
        foreach ($sb + $binds as $k => $v) {
            $stmt->bindValue($k, $v[0], $v[1]);
        }
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    };

    $row = $fetch("AND pm.status = 'rerolling' ORDER BY pm.id LIMIT 1");
    if ($row) {
        return bounty_fill($db, $row, $client, $ctx);
    }
    $cfg = bounty_config()['pools'];
    $order = [
        ['standard', 'daily', $cfg['standard']['daily']['active']],
        ['standard', 'weekly', $cfg['standard']['weekly']['active']],
        ['drop', null, $cfg['drop']['active']],
        ['battle', 'daily', $cfg['battle']['daily']['active']],
        ['challenge', 'weekly', $cfg['challenge']['weekly']['active']],
    ];
    foreach ($order as list($pool, $cadence, $limit)) {
        if (bounty_active_count($db, $acc, $char, $pool, $cadence) >= (int) $limit) {
            continue;
        }
        $binds = [':pool' => [$pool, SQLITE3_TEXT], ':now' => [time(), SQLITE3_INTEGER]];
        $tail = "AND pm.status = 'queued' AND m.pool = :pool AND (pm.expires_at IS NULL OR pm.expires_at > :now)";
        if ($cadence !== null) {
            $tail .= " AND m.cadence = :cad";
            $binds[':cad'] = [$cadence, SQLITE3_TEXT];
        }
        $row = $fetch("$tail ORDER BY pm.earned_at, pm.id LIMIT 1", $binds);
        if ($row) {
            return bounty_fill($db, $row, $client, $ctx);
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// The per-player cron step
// ---------------------------------------------------------------------------

/**
 * Runs the rework for one online player in one cron pass: drops, earning, activation.
 * (Expiry is swept once per pass by the cron; objectives are evaluated by the cron's existing
 * loop, which calls bounty_evaluate_weekly() for the weekly types.)
 *
 * @param array $ctx account_id, char_name, user_lang, curr_f, lobby, lobby_map, kills
 *                   (from bounty_kill_deltas()), completed (missions completed this pass),
 *                   active_types, recent_titles
 */
function bounty_tick_for_client($db, array $client, array $ctx)
{
    $acc = $ctx['account_id'];
    $char = $ctx['char_name'];
    $cfg = bounty_config();
    $lobby = $ctx['lobby'];
    $kills = $ctx['kills'];
    $lang = $ctx['user_lang'];
    $srv = get_server_address();

    // Drops (points 6-10)
    $drops = [];
    foreach ($kills['boss_events'] as $e) {
        $el = $ctx['lobby_map'][$e['lobby_id'] ?? -1] ?? $lobby;
        $d = bounty_try_drop($db, $acc, $char, $client, $el, 'boss', 'a boss');
        if ($d) $drops[] = $d;
    }
    for ($i = 0; $i < $kills['rare']; $i++) {
        $d = bounty_try_drop($db, $acc, $char, $client, $lobby, 'rare', $kills['rare_name'] ? preg_replace('/_/', ' ', ucwords(strtolower($kills['rare_name']), '_')) : 'a rare enemy');
        if ($d) $drops[] = $d;
    }
    foreach ($drops as $d) {
        $label = bounty_tier_label($d['tier']) . ' ' . (($d['cadence'] === 'weekly') ? 'weekly' : 'daily');
        if ($lang === 'jp') {
            send_personal_mail($acc, "ハンターズギルド", "{$label}バウンティがドロップしました！\n{$srv}で確認してください。");
        } elseif ($lang === 'ru') {
            send_personal_mail($acc, "Гильдия Охотников", "Выпал заказ: {$label}!\nСмотрите на {$srv}.");
        } else {
            send_personal_mail($acc, "Hunters Guild", "A {$label} bounty dropped!\nIt's on your bounty board at {$srv}.");
        }
    }

    // Completing bounties earns more (point 4)
    foreach ($ctx['completed'] as $m) {
        if (($m['pool'] ?? null) === 'standard' && in_array($m['cadence'] ?? null, ['daily', 'weekly'], true)) {
            for ($i = 0; $i < (int) $cfg['earn']['completion_grants']; $i++) {
                bounty_try_free_earn($db, $acc, $char, $client, $m['cadence'], 'completion');
            }
        }
    }

    // Being logged in earns more (point 4)
    if (mt_rand(1, 100) <= (int) $cfg['earn']['login_daily_chance']) {
        bounty_try_free_earn($db, $acc, $char, $client, 'daily', 'login');
    }
    if (mt_rand(1, 100) <= (int) $cfg['earn']['login_weekly_chance']) {
        bounty_try_free_earn($db, $acc, $char, $client, 'weekly', 'login');
    }

    // Playing Battle or Challenge Mode earns that pool's bounties (point 13)
    if ($lobby && !empty($lobby['is_game'])) {
        if ($lobby['mode'] === 'Battle' && mt_rand(1, 100) <= (int) $cfg['earn']['battle_chance']) {
            bounty_try_mode_earn($db, $acc, $char, $client, 'battle');
        } elseif ($lobby['mode'] === 'Challenge' && mt_rand(1, 100) <= (int) $cfg['earn']['challenge_chance']) {
            bounty_try_mode_earn($db, $acc, $char, $client, 'challenge');
        }
    }

    bounty_activate_next($db, $acc, $char, $client, $ctx);
    return $kills['state'];
}

// ---------------------------------------------------------------------------
// Board summary (missions.php)
// ---------------------------------------------------------------------------

function bounty_expiry_text($expires_at)
{
    if (!$expires_at) {
        return '';
    }
    $left = (int) $expires_at - time();
    if ($left <= 0) {
        return 'Expiring';
    }
    $d = intdiv($left, 86400);
    $h = intdiv($left % 86400, 3600);
    $m = intdiv($left % 3600, 60);
    if ($d > 0) return "Expires in {$d}d {$h}h";
    if ($h > 0) return "Expires in {$h}h {$m}m";
    return "Expires in {$m}m";
}

/**
 * Slot and cap usage per character, for the board header.
 *
 * @return array [character_name => ['daily' => [active, queued, earned], 'weekly' => [...],
 *               'drop' => [active, held], 'battle' => [active, earned], 'challenge' => [active, earned]]]
 */
function bounty_board_summary($db, $acc)
{
    $out = [];
    $res = $db->prepare("SELECT DISTINCT pm.character_name FROM player_missions pm JOIN missions m ON pm.mission_id = m.id
                         WHERE pm.account_id = :acc AND m.cadence IS NOT NULL AND pm.character_name IS NOT NULL
                         AND (pm.status IN ('queued', 'in_progress', 'rerolling') OR pm.period_key IN (:d, :w))");
    $res->bindValue(':acc', (int) $acc, SQLITE3_INTEGER);
    $res->bindValue(':d', bounty_period_key('daily'), SQLITE3_TEXT);
    $res->bindValue(':w', bounty_period_key('weekly'), SQLITE3_TEXT);
    $r = $res->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $c = $row['character_name'];
        $out[$c] = [
            'daily' => [bounty_active_count($db, $acc, $c, 'standard', 'daily'), bounty_queued_count($db, $acc, $c, 'standard', 'daily'), bounty_earned_count($db, $acc, $c, 'standard', 'daily', ['login', 'completion'])],
            'weekly' => [bounty_active_count($db, $acc, $c, 'standard', 'weekly'), bounty_queued_count($db, $acc, $c, 'standard', 'weekly'), bounty_earned_count($db, $acc, $c, 'standard', 'weekly', ['login', 'completion'])],
            'drop' => [bounty_active_count($db, $acc, $c, 'drop'), bounty_active_count($db, $acc, $c, 'drop') + bounty_queued_count($db, $acc, $c, 'drop')],
            'battle' => [bounty_active_count($db, $acc, $c, 'battle', 'daily'), bounty_earned_count($db, $acc, $c, 'battle', 'daily')],
            'challenge' => [bounty_active_count($db, $acc, $c, 'challenge', 'weekly'), bounty_earned_count($db, $acc, $c, 'challenge', 'weekly')],
        ];
    }
    return $out;
}
