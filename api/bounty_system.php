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
 * Contracts (api/bounty_contracts.php) are drops too, in their own 3 slots, with cadence 'contract' and
 * no expiry. Party shares of a finished contract are rows of pool and cadence 'share', ready to claim.
 *
 * Rows from before the rework have no cadence; they are left alone (no expiry, no slot use).
 */
require_once __DIR__ . '/bounty_generator.php';
require_once __DIR__ . '/reward_tables.php';
require_once __DIR__ . '/bounty_rewards.php';
require_once __DIR__ . '/bounty_contracts.php';

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
            // Contracts: 3 held (all active, no queue); they never expire and abandoning one is free.
            'contract' => ['held_max' => 3],
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
        // Contracts (api/bounty_contracts.php): big jobs that never expire, from Ultra Rare and Mythic Rare drops.
        'contracts' => [
            // Share (%) of dropped Ultra Rare and Mythic Rare bounties that come as contracts, while fewer
            // than pools.contract.held_max are held (otherwise they stay normal weekly drops).
            'drop_share' => 25,
            // Objectives by tier, with weights. RUN_* are full runs of the episode the contract dropped in;
            // RARE_TARGET hunts a rare creature there (needs the newserv kill patch).
            'objectives' => [
                'ultra' => ['RUN_DIVERSE' => 1, 'RARE_TARGET' => 1],
                'mythic' => ['RUN_CARRY' => 1, 'RUN_TIMED' => 1, 'RARE_TARGET' => 1],
            ],
            // Hunt targets by tier (api/data/bounty_areas.json rare_enemies); only those met in the episode.
            'hunt_targets' => [
                'ultra' => ['LOVE_RAPPY', 'NAR_LILY', 'POUILLY_SLIME', 'MERISSA_AA', 'DEL_RAPPY'],
                'mythic' => ['HILDEBLUE', 'AL_RAPPY', 'KONDRIEU', 'PAZUZU', 'DORPHON_ECLAIR'],
            ],
            // A hunt asks for about this many area runs' worth of the creature (at least 1, at most hunt_max).
            'hunt_runs' => ['ultra' => 10, 'mythic' => 30],
            'hunt_max' => 5,
            // A full run: every field area (bounty_episode_floors()) and these boss arenas, in one game.
            'run_bosses' => ['1' => [11, 12, 13, 14], '2' => [12, 13, 14, 15], '4' => [9]],
            // Timed runs: minutes from entering the episode's first field area in that game.
            'run_minutes' => ['1' => 150, '2' => 150, '4' => 90],
            // Carry runs: at least this many players this many levels below the holder (by the levels each
            // joined the game at), in the game at every boss.
            'carry' => ['players' => 3, 'levels' => 10],
            // Everyone else in the game when a contract is done gets a reward of this tier, built for them.
            'share_tier' => ['ultra' => 'rare', 'mythic' => 'ultra'],
            // Photon Drop and Meseta multipliers of the holder's reward.
            'reward_scale' => ['run' => 2.0, 'hunt' => 1.5],
        ],
        // Challenge Mode bounties (weeklies earned by playing Challenge Mode).
        'challenge' => [
            // Tier odds: Common, Uncommon, Rare, Ultra Rare, Mythic Rare.
            'tier_weights' => [45, 30, 17, 6, 2],
            // Each stage's S-rank pace in minutes (they add up to the S-rank limit: 7 h and 5 h).
            'stage_minutes' => ['1' => [13, 47, 40, 45, 40, 50, 60, 55, 70], '2' => [65, 55, 45, 85, 50]],
            // Rank limits in hours, from newserv's BB stage scripts (set_cmode_rank_threshold).
            // A stage's A pace is its S pace times A/S. B has no real limit (99 h), so B = any clear.
            'rank_hours' => ['1' => ['S' => 7, 'A' => 13], '2' => ['S' => 5, 'A' => 7]],
            // Objectives by tier: [min stages, max stages, pace]. 0 stages = a full run of the episode.
            // Several stages are judged on their combined time, like the game's own rank.
            'tier_objectives' => [
                'common' => [[1, 1, 'B']],
                'uncommon' => [[1, 1, 'A']],
                'rare' => [[1, 1, 'S']],
                'ultra' => [[2, 3, 'S'], [3, 4, 'A']],
                'mythic' => [[0, 0, 'S']],
            ],
            // Photon Drops and Meseta scale with the objective's S-pace minutes over this many.
            'reward_minutes' => 50,
        ],
        // Battle Mode bounties (dailies earned by playing Battle Mode): win N matches of one rule set.
        'battle' => [
            'quests' => [501, 502, 503, 504, 505, 506, 507, 508],
            'wins' => ['common' => 1, 'uncommon' => 2, 'rare' => 3],
        ],
        // Point 14: these objectives can be rerolled once.
        'reroll_goal_types' => ['MENTOR_BOSS', 'HARDCORE_MENTOR', 'DIVERSE_PARTY_BOSS'],
        // Objectives the rework never offers, because a day or a week is no fair measure of them:
        // Meseta held (a bank withdrawal meets it, spending undoes it) and materials used (most drop
        // too rarely to find in a day, they are capped per character, and using them is a build choice).
        // Set one to false in config/bounties.json to offer it again.
        'excluded_goal_types' => ['MESETA' => true, 'MAT_HP' => true, 'MAT_TP' => true, 'MAT_POWER' => true, 'MAT_DEF' => true,
                                  'MAT_MIND' => true, 'MAT_EVADE' => true, 'MAT_LUCK' => true],
        // Daily objectives by tier, so the effort fits the reward (free and dropped dailies): errands for
        // Common, a boss or a timed area for Uncommon, a boss under time or with a team for Rare. Each
        // objective type names its tier; change one in config/bounties.json to move it.
        'daily_objective_tier' => [
            'EXPLORATION' => 'common', 'PATROL' => 'common', 'ITEM' => 'common', 'TECHNIQUE' => 'common', 'PLAYTIME' => 'common',
            'BOSS_ARENA' => 'uncommon', 'SPEEDRUN_FLOOR' => 'uncommon', 'LEVEL' => 'uncommon',
            'SPEEDRUN_BOSS' => 'rare', 'MENTOR_BOSS' => 'rare', 'HARDCORE_MENTOR' => 'rare', 'DIVERSE_PARTY_BOSS' => 'rare',
        ],
        // Play time is counted from when the bounty starts (PLAY_HOURS), not as a total.
        'play_hours' => 1,
        // "Reach level N" is offered only when the EXP still needed fits in a day's or a week's play:
        // hours x kills_per_hour x the EXP an average enemy gives on the hardest difficulty the hunter
        // can play (api/data/bounty_levels.json, from newserv's battle params) x exp_multiplier (set it
        // to newserv's BBGlobalEXPMultiplier). It asks for as many levels as fit, up to max_levels.
        'level' => [
            'hours' => ['daily' => 1, 'weekly' => 5],
            'kills_per_hour' => 500,
            'exp_multiplier' => 1,
            'max_levels' => ['daily' => 2, 'weekly' => 5],
        ],
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
            // Rare, Ultra Rare and Mythic Rare items come from the drop data: see 'reward_engine'
            // (api/bounty_rewards.php, bounty_reward_defaults()).
            // Meseta per tier before the level multiplier (1 + level / 50), randomised ±50%.
            'meseta' => ['common' => 1000, 'uncommon' => 2500, 'rare' => 5000, 'ultra' => 15000, 'mythic' => 30000],
        ],
        // Hidden stats for bounties earned before the data-driven rewards (api/bounty_rewards.php,
        // whose settings are under 'reward_engine'). Caps scale by difficulty (Normal 0.6 ...
        // Ultimate 1.2), in steps of 5, never past 2 attributes, 90 per attribute or 50 hit.
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
        case 'contract': return 'Contract';
        case 'share': return 'Contract share';
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
            'quest_number' => isset($l['Quest']['Metadata']['QuestNumber']) ? (int) $l['Quest']['Metadata']['QuestNumber'] : null,
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
 * What the board shows before the claim: item bases, Photon Drops and Meseta; no stats.
 * Empty while the bounty waits (its reward is chosen when it starts).
 */
function bounty_reward_display($spec)
{
    if (!is_array($spec) || !isset($spec['items'])) {
        return '';
    }
    $parts = [];
    foreach ($spec['items'] as $it) {
        $parts[] = $it['base'];
    }
    $parts = array_merge($parts, bounty_photon_parts($spec));
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
 * Specs from before the data-driven rewards (no 'version') roll with roll_profiles, under the
 * same limits: 2 attributes at most, attributes up to 90 and hit up to 50.
 */
function bounty_roll_reward_string(array $spec)
{
    if ((int) ($spec['version'] ?? 1) >= 2) {
        return bounty_roll_reward_string_v2($spec);
    }
    $profiles = bounty_config()['roll_profiles'];
    $diff = $spec['difficulty'] ?? 'Normal';
    $parts = [];
    foreach ($spec['items'] as $it) {
        $s = $it['base'];
        $p = $it['profile'] ? ($profiles[$it['profile']] ?? null) : null;
        if ($p && $it['cat'] === 'Weapon') {
            $stats = [0, 0, 0, 0];
            $n = min(2, mt_rand($p['attrs'][0], $p['attrs'][1]));
            $idx = [0, 1, 2, 3];
            shuffle($idx);
            $cap = min(90, bounty_scaled_cap($p['attr_max'], $diff));
            for ($i = 0; $i < $n; $i++) {
                $stats[$idx[$i]] = mt_rand(1, (int) ($cap / 5)) * 5;
            }
            $hit = 0;
            if ($p['hit_chance'] > 0 && mt_rand(1, 100) <= $p['hit_chance']) {
                $hit = mt_rand(1, max(1, (int) (min(50, bounty_scaled_cap($p['hit_max'], $diff)) / 5))) * 5;
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
 * Creates a queued bounty: its tier, pool and cadence now; its objective, story and reward
 * when it is activated.
 *
 * @return int|null player_missions id
 */
function bounty_earn($db, $acc, $char, array $client, $pool, $cadence, $tier, $source, array $meta = [])
{
    // The reward is chosen when the bounty starts (bounty_fill()), once its area is known.
    $now = time();
    // Contracts never expire and belong to no reset period
    list(, $expires) = ($cadence === 'contract') ? [null, null] : bounty_period_bounds($cadence, $now);
    $period = ($cadence === 'contract') ? null : bounty_period_key($cadence, $now);

    $ins = $db->prepare("INSERT INTO missions (title, description, goal_type, goal_target, reward_item_string, tier, cadence, pool, source, reward_spec, meta)
                         VALUES ('', '', 'PENDING', '', '', :tier, :cad, :pool, :src, NULL, :meta)");
    $ins->bindValue(':tier', $tier, SQLITE3_TEXT);
    $ins->bindValue(':cad', $cadence, SQLITE3_TEXT);
    $ins->bindValue(':pool', $pool, SQLITE3_TEXT);
    $ins->bindValue(':src', $source, SQLITE3_TEXT);
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
    $pm->bindValue(':pk', $period, $period === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $pm->bindValue(':exp', $expires, $expires === null ? SQLITE3_NULL : SQLITE3_INTEGER);
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
    $tier = ($pool === 'challenge') ? bounty_roll_challenge_tier() : bounty_roll_free_tier();
    return bounty_earn($db, $acc, $char, $client, $pool, $cadence, $tier, $pool);
}

/**
 * Challenge Mode bounties can be any tier: Ultra Rare ones span several stages, Mythic Rare ones
 * are full runs.
 */
function bounty_roll_challenge_tier()
{
    $w = bounty_config()['challenge']['tier_weights'];
    return bounty_weighted_pick(array_combine(BOUNTY_TIERS, array_map('floatval', array_pad($w, 5, 0))));
}

/**
 * Points 6-10: a chance of a dropped bounty after a boss or rare-enemy kill.
 *
 * @param string $trigger 'boss' or 'rare'
 * @return array|null ['id', 'tier', 'cadence'] if one dropped
 */
function bounty_try_drop($db, $acc, $char, array $client, $lobby, $trigger, $what = '', $floor = null)
{
    $cfg = bounty_config();
    if (!$lobby || empty($lobby['is_game'])) {
        return null;
    }
    $held = function ($pool) use ($db, $acc, $char) {
        return bounty_count($db, $acc, $char, "m.pool = :pool AND pm.status IN ('queued', 'in_progress', 'rerolling')", [':pool' => [$pool, SQLITE3_TEXT]]);
    };
    $drop_full = $held('drop') >= (int) $cfg['pools']['drop']['held_max']; // holding 10: no more normal drops
    $contract_full = $held('contract') >= (int) ($cfg['pools']['contract']['held_max'] ?? 3);
    if ($drop_full && $contract_full) {
        return null;
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
    // A share of Ultra Rare and Mythic Rare drops are contracts (with a contract slot free)
    $pool = 'drop';
    if (in_array($tier, ['ultra', 'mythic'], true) && !$contract_full
        && mt_rand(1, 10000) <= (int) round(100 * (float) ($cfg['contracts']['drop_share'] ?? 0))) {
        $pool = 'contract';
    } elseif ($drop_full) {
        return null;
    }
    $weekly = in_array($tier, $cfg['drops']['weekly_tiers'], true)
        || ($tier === 'rare' && mt_rand(1, 100) <= (int) $cfg['drops']['rare_weekly_chance']);
    $cadence = ($pool === 'contract') ? 'contract' : ($weekly ? 'weekly' : 'daily');
    $where = bounty_area_for_floor((int) ($lobby['episode'] ?? 1), $floor ?? (int) ($client['LocationFloor'] ?? -1));
    $id = bounty_earn($db, $acc, $char, $client, $pool, $cadence, $tier, $trigger === 'rare' ? 'rare_enemy' : 'boss', [
        'difficulty' => $lobby['difficulty'],
        'mode' => $lobby['mode'],
        'party' => (int) $lobby['players'],
        'dropped_by' => $what,
        // Where it dropped: its reward comes from there if its objective isn't tied to an area
        'episode' => (int) ($lobby['episode'] ?? 1),
        'area' => $where['setting'] ?? null,
        'area_attributes' => $where['attributes'] ?? null,
    ]);
    return $id ? ['id' => $id, 'tier' => $tier, 'cadence' => $cadence, 'pool' => $pool] : null;
}

// ---------------------------------------------------------------------------
// Kill tracking
// ---------------------------------------------------------------------------

/**
 * Challenge stages cleared since the last pass: [[episode, stage, seconds], ...].
 * With the newserv patch (ChallengeClearCount / LastChallengeClear in /y/clients) every clear
 * counts. Without it, a clear shows when a stage's record time in ChallengeTimesEp1Online /
 * ChallengeTimesEp2Online changes, which misses replays slower than the record.
 *
 * @return array [clears, state to save]
 */
function bounty_challenge_clear_deltas(array $client, array $prev_state)
{
    $clears = [];
    $state = [];
    if (isset($client['ChallengeClearCount'])) {
        $cur = (int) $client['ChallengeClearCount'];
        $state['bounty_challenge_clears'] = $cur;
        if (isset($prev_state['bounty_challenge_clears'])) {
            $prev = (int) $prev_state['bounty_challenge_clears'];
            $new = ($cur >= $prev) ? $cur - $prev : $cur; // lower = reconnected
            $last = $client['LastChallengeClear'] ?? null;
            if ($new > 0 && is_array($last)) {
                $qn = (int) ($last['QuestNumber'] ?? -1);
                // Challenge quests are numbered 881NN (Episode 1) and 882NN (Episode 2)
                if ($qn >= 88101 && $qn <= 88299 && in_array(intdiv($qn, 100), [881, 882], true)) {
                    $clears[] = [intdiv($qn, 100) - 880, $qn % 100, (int) ($last['Seconds'] ?? 0)];
                }
            }
        }
        return [$clears, $state];
    }
    foreach ([1, 2] as $ep) {
        $times = array_map('intval', $client["ChallengeTimesEp{$ep}Online"] ?? []);
        $state["bounty_challenge_times_ep{$ep}"] = $times;
        $prev = $prev_state["bounty_challenge_times_ep{$ep}"] ?? null;
        if (!is_array($prev)) {
            continue; // first sight
        }
        foreach ($times as $i => $t) {
            // a new or faster record (65535 = never cleared)
            if (challenge_time_cleared($t) && $t !== (int) ($prev[$i] ?? 0)) {
                $clears[] = [$ep, $i + 1, $t];
            }
        }
    }
    return [$clears, $state];
}

/**
 * Battle wins since the last pass, credited to the rule set (battle quest number) the character
 * was last seen playing. Blue Burst reports battle records when the player leaves the game, so a
 * win shows up after the battle room is left.
 *
 * @return array [['quest' => n|null, 'wins' => n], state to save]
 */
function bounty_battle_win_deltas(array $client, array $prev_state, $lobby)
{
    $quest = $prev_state['bounty_battle_quest'] ?? null;
    if ($lobby && !empty($lobby['is_game']) && ($lobby['mode'] ?? '') === 'Battle' && !empty($lobby['quest_number'])) {
        $quest = (int) $lobby['quest_number'];
    }
    $cur = (int) ($client['BattlePlaceCounts'][0] ?? 0);
    $wins = 0;
    if (isset($prev_state['bounty_battle_firsts'])) {
        $prev = (int) $prev_state['bounty_battle_firsts'];
        $wins = ($cur > $prev) ? $cur - $prev : 0;
    }
    return [['quest' => $quest, 'wins' => $wins], ['bounty_battle_firsts' => $cur, 'bounty_battle_quest' => $quest]];
}

/**
 * New kills since the last cron pass. Enemy and rare-enemy counts need the newserv patch
 * (EnemyKillCount / RareEnemyKillCount in /y/clients); boss kills come from cron_boss_tracker.php.
 *
 * @return array enemy, rare, rare_name, has_counters, boss_events (new ones), state (to save)
 */
function bounty_kill_deltas(array $client, array $prev_state, array $boss_events, $lobby = null)
{
    list($clears, $cstate) = bounty_challenge_clear_deltas($client, $prev_state);
    list($battle, $bstate) = bounty_battle_win_deltas($client, $prev_state, $lobby);
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
        'challenge_clears' => $clears,
        'battle_wins' => $battle,
        'state' => $cstate + $bstate + [
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
 * The lowest difficulty a daily area or boss objective counts on, by tier (null = any): Rare on the
 * difficulty weeklies use for the hunter's level, Uncommon one below it, Common anywhere.
 */
function bounty_daily_min_difficulty($level, $tier)
{
    $order = ['Normal', 'Hard', 'Very Hard', 'Ultimate'];
    $step = ['uncommon' => 1, 'rare' => 0][$tier] ?? null;
    if ($step === null) {
        return null;
    }
    $i = (int) array_search(bounty_weekly_difficulty($level, $tier), $order, true);
    $d = $order[max(0, $i - $step)];
    return ($d === 'Normal') ? null : $d;
}

function bounty_level_data()
{
    static $d = null;
    if ($d === null) {
        $raw = @file_get_contents(__DIR__ . '/data/bounty_levels.json');
        $d = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return $d;
}

/**
 * The hardest difficulty a character can play (Blue Burst: Hard from level 20, Very Hard from 40,
 * Ultimate from 80).
 */
function bounty_level_difficulty($level)
{
    $level = (int) $level;
    return ($level >= 80) ? 'Ultimate' : (($level >= 40) ? 'Very Hard' : (($level >= 20) ? 'Hard' : 'Normal'));
}

/**
 * How many levels a hunter can realistically gain in a day or a week (0 = not even one, so no level
 * objective is offered): the EXP still needed for each level must fit in the cadence's hours of play
 * (config 'level'), counted from the hunter's current EXP. At most max_levels.
 */
function bounty_realistic_levels(array $client, $cadence)
{
    $cfg = bounty_config()['level'];
    $d = bounty_level_data();
    $table = $d['exp_for_level'] ?? [];
    $level = max(1, min(200, (int) ($client['Level'] ?? 1)));
    if (!$table || $level >= 200) {
        return 0;
    }
    $exp = isset($client['EXP']) ? (int) $client['EXP'] : (int) ($table[$level - 1] ?? 0);
    $per_kill = (float) ($d['exp_per_kill'][bounty_level_difficulty($level)] ?? 0);
    $budget = (float) ($cfg['hours'][$cadence] ?? 0) * (float) $cfg['kills_per_hour'] * $per_kill * (float) $cfg['exp_multiplier'];
    $max = (int) ($cfg['max_levels'][$cadence] ?? 0);
    $n = 0;
    // table[L - 1] is the total EXP to reach level L, so the next level is table[$level]
    while ($n < $max && $level + $n < 200 && (int) $table[$level + $n] - $exp <= $budget) {
        $n++;
    }
    return $n;
}

/**
 * Updates a weekly objective's progress for this cron pass and reports whether it is done.
 *
 * @param array $m     the mission row (id = player_missions id, goal_type, goal_target, progress, activated_at)
 * @param array $kills from bounty_kill_deltas()
 */
function bounty_evaluate_weekly($db, array $m, array $client, $lobby, array $kills, array $lobby_map)
{
    list($n, $diff) = in_array($m['goal_type'], ['CHALLENGE_RUN', 'BATTLE_RULE_WINS', 'PLAY_HOURS'], true) ? [0, 'Normal'] : bounty_parse_weekly_target($m['goal_target']);
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
            // A boss killed in the player's game counts for everyone in it, even players who weren't in the
            // arena: the boss tracker's kills for anyone in this game, and the player's own anywhere. Each fight
            // counts once (the players in an arena are each recorded, a few seconds apart).
            $fights = $p['fights'] ?? [];
            foreach (array_merge($kills['party_boss_events'] ?? [], $kills['boss_events']) as $e) {
                if (!empty($m['activated_at']) && (int) $e['time'] < (int) $m['activated_at']) {
                    continue;
                }
                $fk = ($e['lobby_id'] ?? '-') . ':' . ($e['episode'] ?? '-') . ':' . (int) ($e['floor'] ?? 0);
                if (isset($fights[$fk]) && abs((int) $e['time'] - (int) $fights[$fk]) < 120) {
                    continue; // the same fight
                }
                $el = $lobby_map[$e['lobby_id'] ?? -1] ?? $lobby;
                if ($el && bounty_difficulty_rank($el['difficulty']) >= $need) {
                    $p['count'] = (int) ($p['count'] ?? 0) + 1;
                    $fights[$fk] = (int) $e['time'];
                    $changed = true;
                }
            }
            if ($changed) {
                arsort($fights);
                $p['fights'] = array_slice($fights, 0, 20, true);
            }
            $done = (int) ($p['count'] ?? 0) >= $n;
            break;
        case 'CHALLENGE_RUN':
            // Best time per stage since the bounty started; done when every stage is cleared within
            // the limit (several stages: on their combined time, like the game's rank).
            $c = bounty_parse_challenge_target($m['goal_target']);
            $best = $p['best'] ?? [];
            foreach ($kills['challenge_clears'] ?? [] as list($ep, $stage, $secs)) {
                if ($ep === $c['episode'] && $stage >= $c['first'] && $stage <= $c['last'] && $secs > 0) {
                    $k = (string) $stage;
                    if (!isset($best[$k]) || $secs < (int) $best[$k]) {
                        $best[$k] = $secs;
                        $changed = true;
                    }
                }
            }
            $p['best'] = $best;
            $sum = 0;
            $all = true;
            for ($st = $c['first']; $st <= $c['last']; $st++) {
                if (!isset($best[(string) $st])) {
                    $all = false;
                    break;
                }
                $sum += (int) $best[(string) $st];
            }
            $done = $all && ($c['limit'] === 0 || $sum <= $c['limit']);
            break;
        case 'BATTLE_RULE_WINS':
            list($quest, $need_wins) = bounty_parse_battle_target($m['goal_target']);
            $bw = $kills['battle_wins'] ?? ['quest' => null, 'wins' => 0];
            if ($bw['wins'] > 0 && (int) $bw['quest'] === $quest) {
                $p['count'] = (int) ($p['count'] ?? 0) + (int) $bw['wins'];
                $changed = true;
            }
            $done = (int) ($p['count'] ?? 0) >= $need_wins;
            break;
        case 'PLAY_HOURS':
            // Play time since the bounty started (newserv's PlayTimeSeconds for the character)
            list($need_s, $start) = bounty_parse_play_target($m['goal_target']);
            if (isset($client['PlayTimeSeconds'])) {
                $played = max(0, (int) $client['PlayTimeSeconds'] - $start);
                if (intdiv($played, 60) !== intdiv((int) ($p['seconds'] ?? 0), 60)) {
                    $changed = true; // saved once a minute
                }
                $p['seconds'] = $played;
            }
            $done = (int) ($p['seconds'] ?? 0) >= $need_s;
            break;
        case 'ENEMY_HUNT':
        case 'RARE_HUNT':
            // Kills by anyone in the player's game count for everyone in it ('party': what the game killed this
            // pass, the player's own kills included); without that summary, the player's own kills
            $party = $kills['party'] ?? null;
            if (is_array($party)) {
                $add = (int) (($m['goal_type'] === 'ENEMY_HUNT') ? ($party['enemy'] ?? 0) : ($party['rare'] ?? 0));
            } else {
                $add = ($m['goal_type'] === 'ENEMY_HUNT') ? $kills['enemy'] : $kills['rare'];
            }
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
    $exclude = array_merge(['BATTLE_WINS', 'CHALLENGE_STAGES'], array_keys(array_filter(bounty_config()['excluded_goal_types'])));
    $day_levels = bounty_realistic_levels($client, 'daily');
    if ($day_levels < 1) {
        $exclude[] = 'LEVEL'; // not even one level fits in a day's play
    }
    if (($row['status'] ?? '') === 'rerolling') {
        // A reroll replaces a team objective; don't hand back another one.
        $exclude = array_merge($exclude, bounty_config()['reroll_goal_types']);
    }
    $pick_opts = ['exclude' => $exclude];
    if ($cadence === 'daily' && !in_array($pool, ['battle', 'challenge'], true)) {
        // the objective types that fit this tier (bounty_pick_objective falls back if none is available);
        // a type daily_objective_tier doesn't list (such as a material objective set back on) is Common
        $tiers = bounty_config()['daily_objective_tier'];
        $all = array_unique(array_merge(array_keys($tiers), ['PLAYTIME', 'ITEM', 'EXPLORATION', 'PATROL', 'BOSS_ARENA', 'MESETA', 'LEVEL', 'TECHNIQUE',
            'MAT_HP', 'MAT_TP', 'MAT_POWER', 'MAT_DEF', 'MAT_MIND', 'MAT_EVADE', 'MAT_LUCK', 'SPEEDRUN_BOSS', 'SPEEDRUN_FLOOR', 'MENTOR_BOSS', 'HARDCORE_MENTOR', 'DIVERSE_PARTY_BOSS']));
        $pick_opts['only'] = array_values(array_filter($all, function ($g) use ($tiers, $tier) {
            return ($tiers[$g] ?? 'common') === $tier;
        }));
    }
    $base = bounty_pick_objective($client, $curr_f, $active_types, $pick_opts);
    $obj = $base;
    $obj['location_override'] = null;
    $obj['min_difficulty'] = null;
    if ($cadence === 'daily' && !in_array($pool, ['battle', 'challenge'], true) && in_array($obj['selected_goal'], BOUNTY_DAILY_PLACE_GOALS, true)) {
        // Uncommon and Rare dailies in an area or at a boss count only on a difficulty that fits the hunter
        $obj['min_difficulty'] = bounty_daily_min_difficulty($level, $tier);
        if ($obj['min_difficulty']) {
            $obj['difficulty'] = $obj['min_difficulty'];
        }
    }
    if ($obj['selected_goal'] === 'LEVEL') {
        $obj['selected_target_id'] = min(200, $level + mt_rand(1, max(1, $day_levels)));
        $obj['selected_target_friendly'] = 'Level ' . $obj['selected_target_id'];
    }
    if ($obj['selected_goal'] === 'PLAYTIME') {
        // "Reach N total hours" becomes play time counted from now: "<seconds needed>:<play time now>"
        $hours = max(1, (int) bounty_config()['play_hours']);
        $obj['selected_goal'] = 'PLAY_HOURS';
        $obj['selected_target_id'] = ($hours * 3600) . ':' . (int) ($client['PlayTimeSeconds'] ?? 0);
        $obj['selected_target_friendly'] = ($hours === 1) ? '1 hour of play' : "$hours hours of play";
    }

    if ($pool === 'battle') {
        $target = bounty_pick_battle_objective($tier);
        list($quest) = bounty_parse_battle_target($target);
        $rules = bounty_battle_rule_summary($quest);
        $obj['selected_goal'] = 'BATTLE_RULE_WINS';
        $obj['selected_target_id'] = $target;
        $obj['selected_target_friendly'] = bounty_battle_objective_text($target);
        $obj['is_location_goal'] = false;
        list($obj['mission_episode'], $obj['setting_id']) = bounty_pick_hub_setting();
        $obj['location_override'] = "This job is won in sanctioned battle-simulation bouts between hunters, fought in a simulator rather than on Ragol, under these rules: {$rules['name']} ({$rules['rules']}). Do not set it in a field area.";
    } elseif ($pool === 'challenge') {
        $ch = bounty_pick_challenge_objective($client, $tier);
        $obj['selected_goal'] = 'CHALLENGE_RUN';
        $obj['selected_target_id'] = $ch['target'];
        $obj['selected_target_friendly'] = bounty_challenge_objective_text($ch['target']);
        $obj['is_location_goal'] = false;
        $obj['mission_episode'] = $ch['episode'];
        $obj['setting_id'] = bounty_lore()['world']['hubs'][(string) $ch['episode']] ?? (($ch['episode'] === 2) ? 'ep2_lab' : 'ep1_pioneer2');
        $obj['location_override'] = 'This job is a Challenge Mode trial: a set course where the team starts over with basic, issued gear and must clear it against the clock. '
            . ($ch['full'] ? "It is the whole Episode {$ch['episode']} course, start to finish, a long and demanding run." : 'Name the course only in general terms.');
        $obj['difficulty'] = 'Normal'; // every Challenge stage is played on Normal
        $obj['reward_area'] = bounty_challenge_reward_area($ch['episode'], $ch['first'], $ch['last']);
        $obj['reward_opts'] = [
            'challenge_reserve' => $ch['full'] && $tier === 'mythic',
            'scale' => max(0.5, min(2.0, $ch['minutes'] / max(1, (int) bounty_config()['challenge']['reward_minutes']))),
        ];
    } elseif ($pool === 'contract') {
        $c = bounty_pick_contract_objective($client, $row, $tier);
        $obj['selected_goal'] = $c['goal'];
        $obj['selected_target_id'] = $c['target'];
        $obj['selected_target_friendly'] = $c['friendly'];
        $obj['is_location_goal'] = false;
        $obj['mission_episode'] = $c['episode'];
        $obj['setting_id'] = $c['setting_id'];
        $obj['location_override'] = $c['location_override'];
        $obj['difficulty'] = $c['difficulty'];
        $obj['reward_area'] = $c['reward_area'];
        $obj['reward_opts'] = $c['reward_opts'];
    } elseif ($cadence === 'weekly') {
        $diff = bounty_weekly_difficulty($level, $tier);
        // Ultra Rare and Mythic Rare weeklies are hunts only: visiting every area or a few levels
        // (minutes at a low level) is too light for them.
        $top = in_array($tier, ['ultra', 'mythic'], true);
        $choices = $top ? ['BOSS_HUNT', 'BOSS_HUNT'] : ['AREA_SWEEP', 'AREA_SWEEP', 'BOSS_HUNT', 'BOSS_HUNT'];
        if (isset($client['EnemyKillCount'])) {
            $choices[] = 'ENEMY_HUNT';
            $choices[] = 'ENEMY_HUNT';
            if ($top) {
                $choices[] = 'RARE_HUNT';
                $choices[] = 'RARE_HUNT';
            }
        }
        $week_levels = bounty_realistic_levels($client, 'weekly');
        if ($week_levels >= 1 && !$top) {
            $choices[] = 'LEVEL';
        }
        $choices = array_values(array_diff($choices, $active_types)) ?: [$top ? 'BOSS_HUNT' : 'AREA_SWEEP'];
        $goal = $choices[array_rand($choices)];
        $scale = ['common' => 1.0, 'uncommon' => 1.2, 'rare' => 1.5, 'ultra' => 2.0, 'mythic' => 2.5][$tier] ?? 1.0;
        // Enemy counts grow faster than boss counts: about 500 kills is an hour on the floor
        $kill_scale = ['common' => 1.0, 'uncommon' => 1.5, 'rare' => 2.5, 'ultra' => 4.0, 'mythic' => 6.0][$tier] ?? 1.0;
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
                $obj['selected_target_id'] = (int) (round(mt_rand(150, 300) * $kill_scale / 10) * 10) . "@$diff";
                break;
            case 'RARE_HUNT':
                $obj['selected_target_id'] = (($tier === 'mythic') ? mt_rand(2, 3) : mt_rand(1, 2)) . "@$diff";
                break;
            case 'LEVEL':
                $obj['selected_target_id'] = min(200, $level + mt_rand(min(3, $week_levels), $week_levels));
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
    if ($pool === 'challenge' && $tier === 'mythic') {
        $obj['extra_lines'][] = 'Its reward is one of the rarest finds on Ragol, kept for hunters who complete a full trial run.';
    }
    if ($cadence === 'weekly' && $pool !== 'challenge') {
        $obj['extra_lines'][] = 'It runs for the rest of the week, so it is a longer piece of work than a day job.';
    }
    if ($pool === 'contract') {
        $obj['extra_lines'][] = 'It is a contract: one of the biggest jobs the guild posts, with no deadline.';
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
// Challenge Mode and Battle Mode objectives
// ---------------------------------------------------------------------------

/**
 * Highest Challenge stage the character has cleared in an episode (0 = none). Stages unlock in
 * order, so a character can play any stage up to this + 1.
 */
function bounty_challenge_highest_cleared(array $client, $episode)
{
    $h = 0;
    foreach (($client["ChallengeTimesEp{$episode}Online"] ?? []) as $i => $t) {
        if (challenge_time_cleared($t)) {
            $h = max($h, $i + 1);
        }
    }
    return $h;
}

/**
 * Time limit, in seconds, for stages first..last at a pace: S = the stages' S-rank pace,
 * A = that times the A/S rank limits, B = no limit (0).
 */
function bounty_challenge_pace_seconds($episode, $first, $last, $pace)
{
    if ($pace !== 'S' && $pace !== 'A') {
        return 0;
    }
    $c = bounty_config()['challenge'];
    $mins = $c['stage_minutes'][(string) $episode] ?? [];
    $rh = $c['rank_hours'][(string) $episode] ?? ['S' => 1, 'A' => 1];
    $ratio = ($pace === 'A') ? ((float) $rh['A'] / max(0.01, (float) $rh['S'])) : 1.0;
    $total = 0;
    for ($st = $first; $st <= $last; $st++) {
        $total += (int) ceil((float) ($mins[$st - 1] ?? 60) * $ratio);
    }
    return $total * 60;
}

/**
 * A Challenge objective for a tier: one stage (Common: any clear, Uncommon: A pace, Rare: S pace),
 * a run of stages on their combined time (Ultra Rare), or a full run of the episode (Mythic Rare).
 * Stages start no later than one past the character's progress.
 *
 * @return array target, episode, first, last, minutes (S pace), full
 */
function bounty_pick_challenge_objective(array $client, $tier)
{
    $c = bounty_config()['challenge'];
    $ep = mt_rand(0, 1) ? 1 : 2;
    $mins = $c['stage_minutes'][(string) $ep];
    $n = count($mins);
    $choices = $c['tier_objectives'][$tier] ?? [[1, 1, 'B']];
    list($kmin, $kmax, $pace) = $choices[array_rand($choices)];
    $full = ((int) $kmin <= 0);
    if ($full) {
        $first = 1;
        $last = $n;
    } else {
        $k = max(1, min($n, mt_rand((int) $kmin, (int) $kmax)));
        $h = bounty_challenge_highest_cleared($client, $ep);
        $first = mt_rand(1, max(1, min($h + 1, $n - $k + 1)));
        $last = $first + $k - 1;
    }
    $limit = bounty_challenge_pace_seconds($ep, $first, $last, $pace);
    return [
        'target' => "$ep:$first-$last:$limit:$pace",
        'episode' => $ep,
        'first' => $first,
        'last' => $last,
        'minutes' => array_sum(array_slice($mins, $first - 1, $last - $first + 1)),
        'full' => $full,
    ];
}

/**
 * The reward area of Challenge stages: items that drop in their areas, weapon attributes that fit
 * their enemies (weighted by each stage's length).
 */
function bounty_challenge_reward_area($episode, $first, $last)
{
    $stages = bounty_area_data()['challenge_stages'][(string) $episode] ?? [];
    $mins = bounty_config()['challenge']['stage_minutes'][(string) $episode] ?? [];
    $settings = [];
    $attr = array_fill_keys(BOUNTY_ATTRS, 0.0);
    $wsum = 0.0;
    for ($st = $first; $st <= $last; $st++) {
        $info = $stages[$st - 1] ?? null;
        if (!$info) {
            continue;
        }
        $settings[] = $info['drop_setting'];
        $w = (float) ($mins[$st - 1] ?? 1);
        foreach (BOUNTY_ATTRS as $a) {
            $attr[$a] += (float) ($info['attributes'][$a] ?? 0) * $w;
        }
        $wsum += $w;
    }
    if ($wsum > 0) {
        foreach ($attr as $a => $v) {
            $attr[$a] = round($v / $wsum, 3);
        }
    }
    $area = bounty_reward_area(null, $episode, $wsum > 0 ? $attr : null, $settings);
    $area['label'] = "challenge_ep{$episode}_" . (($first === $last) ? "stage{$first}" : "stages{$first}-{$last}");
    return $area;
}

function bounty_pick_battle_objective($tier)
{
    $b = bounty_config()['battle'];
    $quests = $b['quests'] ?: [501];
    $q = (int) $quests[array_rand($quests)];
    return $q . ':' . max(1, (int) ($b['wins'][$tier] ?? 1));
}

/**
 * Where a bounty's reward comes from: its Challenge stages; else its objective's area (a boss's
 * own enemy class weighs in for boss objectives); else, for a drop whose objective isn't tied to
 * an area, the area it dropped in; else its episode.
 */
function bounty_objective_reward_area(array $obj, array $row)
{
    if (!empty($obj['reward_area'])) {
        return $obj['reward_area'];
    }
    $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
    $setting = $obj['setting_id'] ?? null;
    $card = bounty_area_data()['settings'][$setting] ?? null;
    if ((!$card || !empty($card['hub'])) && !empty($meta['area'])) {
        return bounty_reward_area($meta['area'], $meta['episode'] ?? null, $meta['area_attributes'] ?? null);
    }
    $area = bounty_reward_area($setting, $obj['mission_episode'] ?? null);
    if (in_array($obj['selected_goal'] ?? '', ['BOSS_ARENA', 'MENTOR_BOSS', 'HARDCORE_MENTOR', 'DIVERSE_PARTY_BOSS', 'SPEEDRUN_BOSS'], true)) {
        $bt = parse_boss_target((string) $obj['selected_target_id']);
        $boss = bounty_area_for_floor($bt['episode'] ?? ($obj['mission_episode'] ?? 1), $bt['floor']);
        if ($boss) {
            foreach (BOUNTY_ATTRS as $a) {
                $area['attributes'][$a] = round(0.5 * (float) ($area['attributes'][$a] ?? 0) + 0.5 * (float) ($boss['attributes'][$a] ?? 0), 3);
            }
        }
    }
    return $area;
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
    // The reward is chosen now, from the bounty's area (a reroll keeps the reward it has)
    $spec = json_decode((string) ($row['reward_spec'] ?? ''), true);
    if (!is_array($spec) || !isset($spec['items'])) {
        $spec = bounty_build_reward_spec_v2($row['tier'], $client, bounty_objective_reward_area($obj, $row), $obj['reward_opts'] ?? []);
        $row['reward_item_string'] = bounty_reward_display($spec);
    }
    $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
    unset($meta['min_difficulty']);
    if (!empty($obj['min_difficulty'])) {
        $meta['min_difficulty'] = $obj['min_difficulty'];
    }
    $u = $db->prepare("UPDATE missions SET title = :t, description = :d, goal_type = :g, goal_target = :gt, reward_spec = :spec, reward_item_string = :ri, meta = :meta WHERE id = :mid");
    $u->bindValue(':meta', $meta ? json_encode($meta) : null, $meta ? SQLITE3_TEXT : SQLITE3_NULL);
    $u->bindValue(':t', $story['title'], SQLITE3_TEXT);
    $u->bindValue(':d', $story['description'], SQLITE3_TEXT);
    $u->bindValue(':g', $obj['selected_goal'], SQLITE3_TEXT);
    $u->bindValue(':gt', (string) $obj['selected_target_id'], SQLITE3_TEXT);
    $u->bindValue(':spec', json_encode($spec), SQLITE3_TEXT);
    $u->bindValue(':ri', (string) $row['reward_item_string'], SQLITE3_TEXT);
    $u->bindValue(':mid', (int) $row['mission_id'], SQLITE3_INTEGER);
    $u->execute();
    $p = $db->prepare("UPDATE player_missions SET status = 'in_progress', activated_at = :now, progress = '{}' WHERE id = :id");
    $p->bindValue(':now', time(), SQLITE3_INTEGER);
    $p->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
    $p->execute();

    $label = bounty_tier_label($row['tier']) . ' · ' . bounty_kind_label($row['pool'], $row['cadence']);
    $obj_text = getClearObjective($obj['selected_goal'], (string) $obj['selected_target_id'], $story['title'], $story['description'], $obj['min_difficulty'] ?? null);
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
    $base = "SELECT pm.id, pm.mission_id, pm.status, m.tier, m.cadence, m.pool, m.meta, m.reward_item_string, m.reward_spec
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
        ['contract', 'contract', $cfg['contract']['held_max'] ?? 3],
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
        $d = bounty_try_drop($db, $acc, $char, $client, $el, 'boss', 'a boss', isset($e['floor']) ? (int) $e['floor'] : null);
        if ($d) $drops[] = $d;
    }
    for ($i = 0; $i < $kills['rare']; $i++) {
        $d = bounty_try_drop($db, $acc, $char, $client, $lobby, 'rare', $kills['rare_name'] ? preg_replace('/_/', ' ', ucwords(strtolower($kills['rare_name']), '_')) : 'a rare enemy');
        if ($d) $drops[] = $d;
    }
    foreach ($drops as $d) {
        if (($d['pool'] ?? 'drop') === 'contract') {
            $label = bounty_tier_label($d['tier']);
            if ($lang === 'jp') {
                send_personal_mail($acc, "ハンターズギルド", "{$label}の契約がドロップしました！契約に期限はありません。\n{$srv}で確認してください。");
            } elseif ($lang === 'ru') {
                send_personal_mail($acc, "Гильдия Охотников", "Выпал контракт: {$label}! У контрактов нет срока.\nСмотрите на {$srv}.");
            } else {
                send_personal_mail($acc, "Hunters Guild", "A {$label} contract dropped! Contracts never expire.\nIt's on your bounty board at {$srv}.");
            }
            continue;
        }
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
 *               'drop' => [active, held], 'battle' => [active, earned], 'challenge' => [active, earned],
 *               'contract' => [held]]]
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
            'contract' => [bounty_active_count($db, $acc, $c, 'contract') + bounty_queued_count($db, $acc, $c, 'contract')],
        ];
    }
    return $out;
}
