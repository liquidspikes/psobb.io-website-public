<?php
/**
 * --------------------------------------------------------------------------
 * Bounty rewards, built from Blue Burst's own drop data
 * --------------------------------------------------------------------------
 * The reward for a bounty is chosen when it starts, once its objective and area are known:
 *
 *  - Items come from the drop data of the bounty's area (its lore setting, or its Challenge stages),
 *    widening to the episode only when the area has too few items for the hunter.
 *  - Rare and Ultra Rare items are sorted by how often they drop for the hunter's own Section ID.
 *    Rare: 1/500 to 1/5000 a kill. Ultra Rare: rarer than 1/5000, or never for that Section ID.
 *    Mythic Rare: quest-reward items no enemy or box drops, plus any allowed exchange items.
 *    Combination results are never rewards.
 *  - The 10x rule: every item in a pool is at least 10 times as likely from one bounty of that tier
 *    as from one kill of its best enemy (or one box) in normal play, for that Section ID.
 *  - Strength class: the item's level requirement for the hunter's class, at most one class above
 *    the hunter's level plus the tier's head start (Rare +10, Ultra +30, Mythic +50).
 *  - Weapons get at most 2 attributes plus hit. The first always matches the enemies of the area
 *    (by their share of Native, A.Beast, Machine and Dark enemies there). Attributes never pass 90 and
 *    hit never passes 50. Both scale with the tier and the hunter's level.
 *  - Photon Drops are paid like a currency. 99 Photon Drops make a Photon Sphere (the exchange rate),
 *    and no bounty pays more than one Photon Sphere.
 *  - Challenge Mode prizes are never bounty rewards. The rarest drops in the game (rarer than 1/5000
 *    a kill for every Section ID) are reserved for Mythic Rare Challenge Mode full runs.
 *  - Exchange-only items stay with their exchanges. Instead, Uncommon to Mythic Rare bounties pay the
 *    tokens those exchanges take: Item Tickets, Secret Tickets, Photon Tickets, Photon Crystals,
 *    Weapons Badges, and the enemy parts Dr. Osto trades for (from the bounty's area).
 *  - Mythic Rare has a small chance (3%) of an exchange-only item instead, except the Item Ticket
 *    shop's and Dr. Osto's, whose tokens bounties already pay.
 *
 * Data: api/data/bounty_items.json and api/data/bounty_areas.json, generated from newserv's tables
 * and quests by tools/bounty_data/build.py.
 */

const BOUNTY_SECTION_IDS = ['viridia', 'greenill', 'skyly', 'bluefull', 'purplenum', 'pinkal', 'redria', 'oran', 'yellowboze', 'whitill'];
const BOUNTY_CHAR_CLASSES = ['HUmar', 'HUnewearl', 'HUcast', 'RAmar', 'RAcast', 'RAcaseal', 'FOmarl', 'FOnewm', 'FOnewearl', 'HUcaseal', 'FOmar', 'RAmarl'];
const BOUNTY_ATTRS = ['native', 'abeast', 'machine', 'dark'];
const BOUNTY_PHOTON_DROP = '031000';
const BOUNTY_PHOTON_SPHERE = '031001';
// Strength classes, by level requirement
const BOUNTY_STRENGTH_BANDS = [[1, 20], [21, 40], [41, 60], [61, 80], [81, 100], [101, 130], [131, 160], [161, 200]];

function bounty_reward_defaults()
{
    return [
        // Item rarity bands, as the best chance per kill (or per box) for the hunter's Section ID.
        // Anything commoner than rare_min_rate (1/500) is left to normal play.
        'rare_min_rate' => 1 / 5000,
        'rare_max_rate' => 1 / 500,
        // Reserved for Mythic Rare Challenge Mode full runs: drops rarer than this for every Section ID.
        'challenge_reserve_max_rate' => 1 / 5000,
        // The 10x rule: each pool item's chance from one bounty >= this x its best chance per kill.
        'drop_rate_multiplier' => 10,
        // Strength class: levels added to the hunter's level before choosing the class.
        'level_delta' => ['uncommon' => 10, 'rare' => 10, 'ultra' => 30, 'mythic' => 50],
        // A pool smaller than this widens (strength classes, then area to episode, then anywhere).
        'min_pool' => 6,
        // Item categories Mythic Rare draws from (items no enemy or box drops). Combination results
        // (Merges, combination Mags and weapons) are never rewards.
        'mythic_categories' => ['quest_reward_only'],
        // Exchange-only items to add to the Mythic Rare pool outright (hex codes). Normally empty: exchange
        // items stay with their exchanges, bounties pay their tokens, and Mythic Rare has a small chance of one.
        'exchange_allow' => [],
        // Chance (%) that a Mythic Rare bounty (not a Challenge full run) pays an exchange-only item
        // instead of its usual item. Not the Item Ticket shop's or Dr. Osto's items: bounties pay their
        // tokens. Not tokens, combination results or 'never' items.
        'mythic_exchange_chance' => 3,
        // Exchange tokens: the chance (%) per tier of one on top of the reward, and how likely each kind is.
        // qty: [min, max] per tier. Enemy parts come from the bounty's area (or episode).
        'exchange_tokens' => [
            'chance' => ['uncommon' => 20, 'rare' => 35, 'ultra' => 60, 'mythic' => 100],
            'kinds' => [
                'enemy_part' => ['weight' => 30, 'qty' => ['uncommon' => [1, 1], 'rare' => [1, 1], 'ultra' => [1, 2], 'mythic' => [1, 2]]],
                'item_ticket' => ['code' => '031209', 'weight' => 25, 'qty' => ['uncommon' => [1, 1], 'rare' => [1, 1], 'ultra' => [1, 1], 'mythic' => [1, 2]]],
                'secret_ticket' => ['code' => '031003', 'weight' => 15, 'qty' => ['uncommon' => [1, 1], 'rare' => [1, 1], 'ultra' => [1, 2], 'mythic' => [1, 2]]],
                'photon_ticket' => ['code' => '031004', 'weight' => 15, 'qty' => ['uncommon' => [1, 1], 'rare' => [1, 2], 'ultra' => [2, 4], 'mythic' => [3, 6]]],
                'photon_crystal' => ['code' => '031002', 'weight' => 10, 'qty' => ['rare' => [1, 1], 'ultra' => [1, 1], 'mythic' => [1, 2]]],
                'weapons_badge' => ['weight' => 10, 'qty' => ['uncommon' => [1, 1], 'rare' => [1, 1], 'ultra' => [1, 1], 'mythic' => [1, 1]]],
            ],
            // Weapons Badge metal by tier: [Bronze, Silver, Gold] weights
            'badge_metal' => ['uncommon' => [100, 0, 0], 'rare' => [70, 30, 0], 'ultra' => [0, 70, 30], 'mythic' => [0, 0, 100]],
            // The enemy parts Dr. Osto's Research and the Unsealed Door trade for weapons
            'enemy_parts' => ['030D00', '030D01', '030D02', '030D03', '030D04', '030D06', '030D07', '030D08', '030D09', '030D0A',
                              '030D0B', '030D0C', '030D0D', '030D0E', '030D0F', '030D10', '030D11', '030D12', '030D13'],
        ],
        // Never rewards: seasonal event tools from quests, joke items, and Photon Drops/Spheres (paid separately).
        'never' => ['03120A', '031210', '031212', '031401', '031402', '031500', '031502', '031700', '01013C',
                    '031000', '031001', '031003'],
        // Weapon and armor stats, rolled on claim. Caps are at level 200 and scale down to 40% at level 1.
        // attrs: [min, max] attributes (never more than 2). attr_cap / hit_cap: the top of the roll.
        // slots: [min, max] armor slots. bonus: share of the item's own DEF/EVP bonus range.
        'stat_rolls' => [
            'uncommon' => ['attrs' => [0, 2], 'attr_cap' => 30, 'hit_chance' => 0, 'hit_cap' => 0, 'slots' => [0, 1], 'bonus' => 0.3],
            'rare' => ['attrs' => [1, 2], 'attr_cap' => 50, 'hit_chance' => 25, 'hit_cap' => 25, 'slots' => [1, 3], 'bonus' => 0.6],
            'ultra' => ['attrs' => [2, 2], 'attr_cap' => 70, 'hit_chance' => 45, 'hit_cap' => 40, 'slots' => [2, 4], 'bonus' => 0.8],
            'mythic' => ['attrs' => [2, 2], 'attr_cap' => 90, 'hit_chance' => 65, 'hit_cap' => 50, 'slots' => [3, 4], 'bonus' => 1.0],
            // Mythic Rare Challenge Mode full runs: high attributes whatever the hunter's level.
            'challenge_reserve' => ['attrs' => [2, 2], 'attr_cap' => 90, 'attr_floor' => 60, 'hit_chance' => 100, 'hit_cap' => 50,
                                    'hit_floor' => 30, 'level_scaled' => false, 'slots' => [4, 4], 'bonus' => 1.0],
        ],
        'max_attribute' => 90,
        'max_hit' => 50,
        // Photon Drops paid per tier, [min, max] at level 200 (half at level 1; Mythic is not scaled).
        // 99 or more pays one Photon Sphere plus the rest in Photon Drops.
        'photon_drops' => ['common' => [0, 2], 'uncommon' => [1, 5], 'rare' => [4, 15], 'ultra' => [20, 60], 'mythic' => [99, 150]],
    ];
}

function bounty_reward_cfg()
{
    $over = bounty_config()['reward_engine'] ?? [];
    return array_replace_recursive(bounty_reward_defaults(), is_array($over) ? $over : []);
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------

function bounty_item_data()
{
    static $items = null;
    if ($items === null) {
        $raw = @file_get_contents(__DIR__ . '/data/bounty_items.json');
        $d = $raw ? json_decode($raw, true) : null;
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        if (!$items) {
            error_log('[BOUNTY] api/data/bounty_items.json is missing or invalid; bounty rewards fall back to the reward tables.');
        }
    }
    return $items;
}

function bounty_area_data()
{
    static $areas = null;
    if ($areas === null) {
        $raw = @file_get_contents(__DIR__ . '/data/bounty_areas.json');
        $areas = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return $areas;
}

function bounty_section_index($name)
{
    $n = strtolower((string) $name);
    if ($n === 'greennill') {
        $n = 'greenill';
    }
    $i = array_search($n, BOUNTY_SECTION_IDS, true);
    return ($i === false) ? 0 : $i;
}

function bounty_class_index($name)
{
    $i = array_search((string) $name, BOUNTY_CHAR_CLASSES, true);
    return ($i === false) ? null : $i;
}

/**
 * Lore setting and enemy-class mix for a newserv floor (LocationFloor) in an episode.
 *
 * @return array|null ['setting', 'attributes']
 */
function bounty_area_for_floor($episode, $floor)
{
    return bounty_area_data()['floors'][(string) (int) $episode][(string) (int) $floor] ?? null;
}

/**
 * A bounty's reward area: where its items drop and whose enemies its weapon attributes fit.
 *
 * @return array ['label', 'episodes' => [1], 'drop_settings' => [...] (empty = episode-wide), 'attributes' => [...]]
 */
function bounty_reward_area($setting, $episode, ?array $attributes = null, ?array $drop_settings = null)
{
    $areas = bounty_area_data();
    $card = $areas['settings'][$setting] ?? null;
    $ep = (int) ($card['episode'] ?? $episode ?? 1);
    if ($drop_settings === null) {
        $drop_settings = ($card && !empty($card['drop_setting'])) ? [$card['drop_setting']] : [];
    }
    if ($attributes === null) {
        $attributes = $card['attributes'] ?? ($areas['settings'][['1' => 'ep1_pioneer2', '2' => 'ep2_lab', '4' => 'ep4_pioneer2'][(string) $ep] ?? 'ep1_pioneer2']['attributes'] ?? null);
    }
    if (!is_array($attributes) || array_sum($attributes) <= 0) {
        $attributes = array_fill_keys(BOUNTY_ATTRS, 0.25);
    }
    return [
        'label' => $setting ?: "ep{$ep}",
        'episodes' => [$ep],
        'drop_settings' => array_values(array_unique($drop_settings)),
        'attributes' => $attributes,
    ];
}

// ---------------------------------------------------------------------------
// Pools
// ---------------------------------------------------------------------------

/**
 * Level an item belongs to: its level requirement for the class (or the lowest of any class),
 * or, for items without one (units, mags, tools), a level from its star rating.
 * Returns 201 if the class can never equip it.
 */
function bounty_item_strength(array $it, $class_idx = null)
{
    $levels = $it['equip_level'] ?? [];
    if ($class_idx !== null) {
        $lv = (int) ($levels[$class_idx] ?? 1);
    } else {
        $lv = $levels ? min(array_map('intval', $levels)) : 1;
    }
    if ($lv > 200) {
        return 201;
    }
    if ($lv <= 1) {
        $stars = (int) ($it['stars'] ?? 0);
        $lv = ($stars >= 12) ? 150 : (($stars >= 11) ? 100 : (($stars >= 10) ? 60 : (($stars >= 9) ? 30 : 1)));
    }
    return $lv;
}

function bounty_strength_band($level)
{
    foreach (BOUNTY_STRENGTH_BANDS as $i => list($lo, $hi)) {
        if ($level <= $hi) {
            return $i;
        }
    }
    return count(BOUNTY_STRENGTH_BANDS) - 1;
}

/**
 * True if an item may ever be a bounty reward.
 */
function bounty_item_allowed($code, array $it, array $rc)
{
    if (in_array($code, $rc['never'], true)) {
        return false;
    }
    switch ($it['obtained'] ?? '') {
        case 'challenge_prize':      // Challenge Mode prizes stay Challenge Mode's own
        case 'not_obtainable':
        case 'seasonal_drop_only':
            return false;
        case 'exchange_only':
            return in_array($code, $rc['exchange_allow'], true);
    }
    return true;
}

/**
 * Exchange-only items a Mythic Rare bounty may pay, rarely: not the Item Ticket shop's or Dr. Osto's
 * (bounties pay their tokens), not tokens themselves, not combination results.
 */
function bounty_is_mythic_exchange_item($code, array $it, array $rc)
{
    if (($it['obtained'] ?? '') !== 'exchange_only' || in_array($code, $rc['never'], true) || !empty($it['combinations'])) {
        return false;
    }
    $tokens = ['031209', '031003', '031004', '031002', '031200', '031201', '031202', '031800', '030E07'];
    if (in_array($code, $tokens, true) || in_array($code, [BOUNTY_PHOTON_DROP, BOUNTY_PHOTON_SPHERE], true)) {
        return false; // tokens, currency, the Tablet and the Heart Key
    }
    foreach ($it['exchanges'] ?? [] as $e) {
        if (strpos($e, 'Item Present') === 0) {
            return false;
        }
    }
    foreach ($it['quest_rewards'] ?? [] as $q) {
        if ($q === "Dr. Osto's Research" || $q === 'Unsealed Door') {
            return false;
        }
    }
    return true;
}

function bounty_mythic_exchange_items()
{
    static $codes = null;
    if ($codes === null) {
        $rc = bounty_reward_cfg();
        $codes = [];
        foreach (bounty_item_data() as $code => $it) {
            if (bounty_is_mythic_exchange_item($code, $it, $rc)) {
                $codes[] = $code;
            }
        }
    }
    return $codes;
}

/**
 * An exchange token on top of a reward (Uncommon to Mythic Rare), or null.
 *
 * @return array|null item entry: base ("031004 x3"), cat Tool, token kind
 */
function bounty_pick_exchange_token($tier, array $area)
{
    $cfg = bounty_reward_cfg()['exchange_tokens'];
    if (mt_rand(1, 100) > (int) ($cfg['chance'][$tier] ?? 0)) {
        return null;
    }
    $weights = [];
    foreach ($cfg['kinds'] as $kind => $k) {
        if (isset($k['qty'][$tier]) && (float) $k['weight'] > 0) {
            $weights[$kind] = (float) $k['weight'];
        }
    }
    // Enemy parts from the bounty's area, else its episode; none there (Episode 4): another token
    $parts = [];
    $items = bounty_item_data();
    foreach ([0, 1] as $wide) {
        foreach ($cfg['enemy_parts'] as $code) {
            $it = $items[$code] ?? null;
            if (!$it) {
                continue;
            }
            $here = $wide === 0
                ? ($area['drop_settings'] && array_intersect($area['drop_settings'], $it['drop_settings'] ?? []))
                : (bool) array_intersect(array_map(function ($e) { return 'Ep' . (int) $e; }, $area['episodes'] ?? []), $it['drop_episodes'] ?? []);
            if ($here) {
                $parts[] = $code;
            }
        }
        if ($parts) {
            break;
        }
    }
    if (!$parts) {
        unset($weights['enemy_part']);
    }
    if (!$weights) {
        return null;
    }
    $kind = bounty_weighted_float_pick($weights);
    list($lo, $hi) = $cfg['kinds'][$kind]['qty'][$tier];
    $qty = mt_rand((int) $lo, (int) $hi);
    if ($kind === 'enemy_part') {
        $code = $parts[array_rand($parts)];
    } elseif ($kind === 'weapons_badge') {
        $w = $cfg['badge_metal'][$tier] ?? [100, 0, 0];
        $code = ['031200', '031201', '031202'][(int) bounty_weighted_float_pick(array_filter([(float) $w[0], (float) $w[1], (float) $w[2]]))];
    } else {
        $code = $cfg['kinds'][$kind]['code'];
    }
    return ['base' => $code . ($qty > 1 ? " x$qty" : ''), 'cat' => 'Tool', 'profile' => null, 'token' => $kind];
}

/**
 * Items reserved for Mythic Rare Challenge Mode full runs: drops rarer than 1/5000 a kill for
 * every Section ID.
 */
function bounty_is_challenge_reserved(array $it, array $rc)
{
    return ($it['obtained'] ?? '') === 'rare_drop'
        && (float) ($it['best_drop_rate'] ?? 0) > 0
        && (float) $it['best_drop_rate'] < (float) $rc['challenge_reserve_max_rate'];
}

/**
 * The candidates for one tier, before widening: [code => rate for the hunter's Section ID].
 *
 * @param string $tier  'rare', 'ultra', 'mythic' or 'challenge_reserve'
 * @param array  $ctx   section (index), class (index or null), level, area (bounty_reward_area())
 * @param array  $relax 'area' (0 = area, 1 = episode, 2 = anywhere), 'strength' (0 = two classes, 1 = up to target, 2 = any)
 */
function bounty_pool_candidates($tier, array $ctx, array $relax = [])
{
    $rc = bounty_reward_cfg();
    $sec = (int) $ctx['section'];
    $class = ($tier === 'rare') ? $ctx['class'] : null; // Ultra and Mythic may be for any class
    $level = max(1, min(200, (int) $ctx['level']));
    $target = min(200, $level + (int) ($rc['level_delta'][$tier] ?? 0));
    $tband = bounty_strength_band($target);
    $ra = (int) ($relax['area'] ?? 0);
    $rs = (int) ($relax['strength'] ?? 0);
    $area = $ctx['area'];
    $eps = array_map(function ($e) { return 'Ep' . (int) $e; }, $area['episodes'] ?? []);
    $out = [];
    foreach (bounty_item_data() as $code => $it) {
        if (!bounty_item_allowed($code, $it, $rc)) {
            continue;
        }
        $kind = $it['kind'] ?? '';
        $rate = (float) ($it['drop_rate_by_section'][$sec] ?? 0);
        $reserved = bounty_is_challenge_reserved($it, $rc);
        switch ($tier) {
            case 'rare':
                if ($reserved || ($it['obtained'] ?? '') !== 'rare_drop' || $rate < $rc['rare_min_rate'] || $rate >= $rc['rare_max_rate']) {
                    continue 2;
                }
                break;
            case 'ultra':
                if ($reserved || ($it['obtained'] ?? '') !== 'rare_drop' || $rate >= $rc['rare_min_rate']) {
                    continue 2;
                }
                break;
            case 'mythic':
                if (!in_array($it['obtained'] ?? '', $rc['mythic_categories'], true)
                    && !(($it['obtained'] ?? '') === 'exchange_only' && in_array($code, $rc['exchange_allow'], true))) {
                    continue 2;
                }
                break;
            case 'challenge_reserve':
                if (!$reserved) {
                    continue 2;
                }
                break;
            default:
                continue 2;
        }
        // Class (Rare only; point 16 lets Ultra and Mythic be for any class)
        if ($class !== null && !in_array(BOUNTY_CHAR_CLASSES[$class], $it['usable_by'] ?? [], true)) {
            continue;
        }
        // Area: where the item drops (Mythic items don't drop, so they aren't tied to an area)
        if ($tier !== 'mythic' && $ra < 2) {
            if ($ra === 0 && !empty($area['drop_settings'])) {
                if (!array_intersect($area['drop_settings'], $it['drop_settings'] ?? [])) {
                    continue;
                }
            } elseif (!array_intersect($eps, $it['drop_episodes'] ?? [])) {
                continue;
            }
        }
        // Strength class (the reserve is the reserve at any level)
        if ($tier !== 'challenge_reserve' && $rs < 2) {
            $str = bounty_item_strength($it, $class);
            if ($str > 200 || $str > $target) {
                continue;
            }
            if ($rs === 0 && bounty_strength_band($str) < $tband - 1) {
                continue;
            }
        }
        $out[$code] = $rate;
    }
    return $out;
}

/**
 * The pool for a tier, widening until it has at least min_pool items: first the strength
 * classes, then from the area to its episode, then anywhere.
 *
 * @return array ['items' => [code => rate], 'widened' => string]
 */
function bounty_reward_pool($tier, array $ctx)
{
    $rc = bounty_reward_cfg();
    $min = (int) $rc['min_pool'];
    $steps = [
        [['area' => 0, 'strength' => 0], 'area, near class'],
        [['area' => 0, 'strength' => 1], 'area, any lower class'],
        [['area' => 1, 'strength' => 0], 'episode'],
        [['area' => 1, 'strength' => 1], 'episode, any lower class'],
        [['area' => 2, 'strength' => 0], 'anywhere'],
        [['area' => 2, 'strength' => 1], 'anywhere, any lower class'],
        [['area' => 2, 'strength' => 2], 'anything'],
    ];
    if ($tier === 'mythic' || $tier === 'challenge_reserve') {
        // Mythic items aren't tied to an area, and the reserve is the reserve at any level:
        // only the upper strength limit applies.
        $steps = array_values(array_filter($steps, function ($st) { return $st[0]['strength'] >= 1; }));
    }
    $best = ['items' => [], 'widened' => 'none'];
    foreach ($steps as list($relax, $label)) {
        $c = bounty_pool_candidates($tier, $ctx, $relax);
        if (count($c) >= $min) {
            return ['items' => $c, 'widened' => $label];
        }
        if (count($c) > count($best['items'])) {
            $best = ['items' => $c, 'widened' => $label];
        }
    }
    return $best;
}

/**
 * Chances for each pool item that keep the 10x rule: p_i = m*r_i + (1 - m*sum(r)) / n.
 * If the pool's rates are too high to keep it (m*sum(r) > 1), its commonest items are dropped
 * until it holds.
 *
 * @param array $rates [code => rate per kill]
 * @return array [code => chance from one bounty], summing to 1
 */
function bounty_pool_chances(array $rates, $multiplier = null)
{
    $m = (float) ($multiplier ?? bounty_reward_cfg()['drop_rate_multiplier']);
    arsort($rates);
    while ($rates && $m * array_sum($rates) > 1.0) {
        array_shift($rates);
    }
    $n = count($rates);
    if ($n === 0) {
        return [];
    }
    $spare = (1.0 - $m * array_sum($rates)) / $n;
    $out = [];
    foreach ($rates as $code => $r) {
        $out[$code] = $m * $r + $spare;
    }
    return $out;
}

function bounty_pick_from_chances(array $chances)
{
    $x = mt_rand() / mt_getrandmax();
    $acc = 0.0;
    $last = null;
    foreach ($chances as $code => $p) {
        $acc += $p;
        $last = $code;
        if ($x <= $acc) {
            return $code;
        }
    }
    return $last;
}

// ---------------------------------------------------------------------------
// Stats (rolled on claim)
// ---------------------------------------------------------------------------

function bounty_level_factor($level)
{
    return 0.4 + 0.6 * max(1, min(200, (int) $level)) / 200;
}

function bounty_round5($v)
{
    return (int) (floor($v / 5) * 5);
}

/**
 * Weapon attributes and hit: at most 2 attributes plus hit. The first attribute is drawn from the
 * area's enemy classes; the second from the area's other classes, or, where the area has only one
 * class, from the rest of the episode.
 *
 * @return array [[native, abeast, machine, dark], hit]
 */
function bounty_roll_weapon_stats($profile, $level, array $attributes, array $episode_attributes = null)
{
    $rc = bounty_reward_cfg();
    $p = $rc['stat_rolls'][$profile] ?? $rc['stat_rolls']['rare'];
    $lf = (($p['level_scaled'] ?? true) === false) ? 1.0 : bounty_level_factor($level);
    $cap = min((int) $rc['max_attribute'], bounty_round5($p['attr_cap'] * $lf));
    $floor = isset($p['attr_floor']) ? (int) $p['attr_floor'] : max(5, bounty_round5($cap * 0.4));
    $floor = min($floor, $cap);
    $n = min(2, mt_rand((int) $p['attrs'][0], (int) $p['attrs'][1]));
    $stats = array_fill_keys(BOUNTY_ATTRS, 0);
    $w = [];
    foreach (BOUNTY_ATTRS as $a) {
        $w[$a] = max(0, (float) ($attributes[$a] ?? 0));
    }
    for ($i = 0; $i < $n && $cap >= 5; $i++) {
        $choices = array_filter($w, function ($x) { return $x > 0; });
        if (!$choices && $episode_attributes) {
            foreach (BOUNTY_ATTRS as $a) {
                if ($stats[$a] === 0) {
                    $choices[$a] = max(0, (float) ($episode_attributes[$a] ?? 0));
                }
            }
            $choices = array_filter($choices, function ($x) { return $x > 0; });
        }
        if (!$choices) {
            break;
        }
        $a = bounty_weighted_float_pick($choices);
        $stats[$a] = mt_rand((int) ($floor / 5), (int) ($cap / 5)) * 5;
        unset($w[$a]);
    }
    $hit = 0;
    $hcap = min((int) $rc['max_hit'], bounty_round5($p['hit_cap'] * $lf));
    if ($hcap >= 5 && mt_rand(1, 100) <= (int) $p['hit_chance']) {
        $hfloor = min($hcap, max(5, (int) ($p['hit_floor'] ?? 5)));
        $hit = mt_rand((int) ($hfloor / 5), (int) ($hcap / 5)) * 5;
    }
    return [array_values($stats), $hit];
}

function bounty_weighted_float_pick(array $weights)
{
    $tot = array_sum($weights);
    $x = mt_rand() / mt_getrandmax() * $tot;
    $acc = 0.0;
    $last = null;
    foreach ($weights as $k => $w) {
        $acc += $w;
        $last = $k;
        if ($x <= $acc) {
            return $k;
        }
    }
    return $last;
}

/**
 * Armor and shield bonuses, capped by the item's own DEF/EVP bonus ranges.
 *
 * @return array [def, evp, slots]
 */
function bounty_roll_armor_stats($profile, $level, $cat, $def_range, $evp_range)
{
    $rc = bounty_reward_cfg();
    $p = $rc['stat_rolls'][$profile] ?? $rc['stat_rolls']['rare'];
    $lf = (($p['level_scaled'] ?? true) === false) ? 1.0 : bounty_level_factor($level);
    $dmax = (int) floor(max(0, (int) $def_range) * $p['bonus'] * $lf);
    $emax = (int) floor(max(0, (int) $evp_range) * $p['bonus'] * $lf);
    $def = ($dmax > 0) ? mt_rand((int) ceil($dmax / 2), $dmax) : 0;
    $evp = ($emax > 0) ? mt_rand((int) ceil($emax / 2), $emax) : 0;
    $slots = ($cat === 'Armor') ? mt_rand((int) $p['slots'][0], (int) $p['slots'][1]) : 0;
    return [$def, $evp, $slots];
}

// ---------------------------------------------------------------------------
// Photon Drops
// ---------------------------------------------------------------------------

/**
 * Photon Drops paid by a bounty, as items: one Photon Sphere at most (99 Photon Drops by exchange)
 * and the rest in Photon Drops (a stack holds 99).
 *
 * @param float $scale extra multiplier (Challenge Mode: the stages' length)
 * @return array ['value' => total in Photon Drops, 'spheres' => 0|1, 'drops' => n]
 */
function bounty_photon_reward($tier, $level, $scale = 1.0)
{
    $range = bounty_reward_cfg()['photon_drops'][$tier] ?? [0, 0];
    $v = mt_rand((int) $range[0], (int) $range[1]);
    if ($tier !== 'mythic') {
        $v = (int) round($v * (0.5 + 0.5 * max(1, min(200, (int) $level)) / 200) * $scale);
    } else {
        $v = (int) round($v * max(1.0, $scale));
    }
    $spheres = ($v >= 99) ? 1 : 0;
    $drops = min(99, $v - 99 * $spheres);
    return ['value' => $spheres * 99 + $drops, 'spheres' => $spheres, 'drops' => $drops];
}

// ---------------------------------------------------------------------------
// The reward spec
// ---------------------------------------------------------------------------

/**
 * Builds a bounty's reward when it starts.
 *
 * @param array $client the hunter (/y/clients entry): Level, CharClass, SectionID
 * @param array $area   bounty_reward_area()
 * @param array $opts   'challenge_reserve' => true for a Mythic Rare full run; 'scale' => Photon/Meseta multiplier
 * @return array spec: version 2, tier, area, items [['base', 'cat', 'profile', 'attributes', 'def_range', 'evp_range']],
 *               photon (bounty_photon_reward()), meseta, pool (widening note)
 */
function bounty_build_reward_spec_v2($tier, array $client, array $area, array $opts = [])
{
    $cfg = bounty_config();
    $level = max(1, min(200, (int) ($client['Level'] ?? 1)));
    $class_name = (string) ($client['CharClass'] ?? 'HUmar');
    $class = bounty_class_index($class_name);
    $ctx = ['section' => bounty_section_index($client['SectionID'] ?? 'Viridia'), 'class' => $class ?? 0, 'level' => $level, 'area' => $area];
    $items = [];
    $pool_note = null;
    $ep_attrs = bounty_area_data()['settings'][['1' => 'ep1_pioneer2', '2' => 'ep2_lab', '4' => 'ep4_pioneer2'][(string) ($area['episodes'][0] ?? 1)] ?? 'ep1_pioneer2']['attributes'] ?? null;
    $add_item = function ($code, $profile) use (&$items, $area, $ep_attrs) {
        $it = bounty_item_data()[$code] ?? [];
        $items[] = [
            'base' => $code,
            'cat' => $it['kind'] ?? 'Tool',
            'profile' => in_array($it['kind'] ?? '', ['Weapon', 'Armor', 'Shield'], true) ? $profile : null,
            'attributes' => $area['attributes'],
            'episode_attributes' => $ep_attrs,
            'def_range' => (int) ($it['def_range'] ?? 0),
            'evp_range' => (int) ($it['evp_range'] ?? 0),
        ];
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
            $grow = min(200, $level + (int) (bounty_reward_cfg()['level_delta']['uncommon'] ?? 10));
            $n = mt_rand(1, 2);
            for ($k = 0; $k < $n; $k++) {
                $r = mt_rand(1, 100);
                $cat = ($r <= 45) ? 'Weapon' : (($r <= 75) ? 'Armor' : 'Shield');
                $base = bounty_strip_rolled_stats(get_common_reward_item($grow, $class_name, $cat), $cat);
                $code = strtoupper(substr(explode(' ', $base)[0], 0, 6));
                $it = bounty_item_data()[$code] ?? [];
                $items[] = [
                    'base' => $base, 'cat' => $cat, 'profile' => 'uncommon',
                    'attributes' => $area['attributes'], 'episode_attributes' => $ep_attrs,
                    'def_range' => (int) ($it['def_range'] ?? 0), 'evp_range' => (int) ($it['evp_range'] ?? 0),
                ];
            }
            break;
        case 'rare':
        case 'ultra':
        case 'mythic':
            $pool_tier = !empty($opts['challenge_reserve']) ? 'challenge_reserve' : $tier;
            if ($pool_tier === 'mythic' && mt_rand(1, 10000) <= (int) round(100 * (float) bounty_reward_cfg()['mythic_exchange_chance'])) {
                // Rarely, an item otherwise only found at an exchange
                $ex = bounty_mythic_exchange_items();
                if ($ex) {
                    $add_item($ex[array_rand($ex)], 'mythic');
                    $pool_note = 'exchange item (' . count($ex) . ')';
                    break;
                }
            }
            $pool = bounty_reward_pool($pool_tier, $ctx);
            $chances = bounty_pool_chances($pool['items']);
            $pool_note = count($chances) . ' items (' . $pool['widened'] . ')';
            $code = $chances ? bounty_pick_from_chances($chances) : null;
            if ($code !== null) {
                $add_item($code, $pool_tier === 'challenge_reserve' ? 'challenge_reserve' : $tier);
            } else {
                // Nothing in the data fits: rare equipment from the reward tables
                $r = mt_rand(1, 100);
                $cat = ($r <= 45) ? 'Weapon' : (($r <= 75) ? 'Armor' : 'Shield');
                $grow = min(200, $level + (int) (bounty_reward_cfg()['level_delta'][$tier] ?? 10));
                $items[] = ['base' => bounty_strip_rolled_stats(get_reward_item($grow, $class_name, $cat), $cat), 'cat' => $cat,
                            'profile' => $tier, 'attributes' => $area['attributes'], 'episode_attributes' => $ep_attrs];
                $pool_note = 'reward tables (no data match)';
            }
            break;
    }

    // Exchange tokens on top (Uncommon to Mythic Rare)
    $token = bounty_pick_exchange_token($tier, $area);
    if ($token) {
        $items[] = $token;
    }

    $scale = (float) ($opts['scale'] ?? 1.0);
    $photon = bounty_photon_reward($tier, $level, $scale);
    $base = (int) ($cfg['rewards']['meseta'][$tier] ?? 1000);
    $meseta = (int) (round($base * (1 + $level / 50) * $scale * mt_rand(50, 150) / 100 / 100) * 100);
    return [
        'version' => 2,
        'tier' => $tier,
        'level' => $level,
        'area' => $area['label'],
        'items' => $items,
        'photon' => $photon,
        'meseta' => max(100, $meseta),
        'pool' => $pool_note,
    ];
}

/**
 * The Photon Drop and Photon Sphere entries of a reward string.
 */
function bounty_photon_parts(array $spec)
{
    $parts = [];
    $ph = $spec['photon'] ?? null;
    if ($ph) {
        if (!empty($ph['spheres'])) {
            $parts[] = BOUNTY_PHOTON_SPHERE;
        }
        if (!empty($ph['drops'])) {
            $parts[] = BOUNTY_PHOTON_DROP . ($ph['drops'] > 1 ? ' x' . (int) $ph['drops'] : '');
        }
    }
    return $parts;
}

/**
 * Rolls a v2 spec's hidden stats into the final reward string.
 */
function bounty_roll_reward_string_v2(array $spec)
{
    $level = (int) ($spec['level'] ?? 100);
    $parts = [];
    foreach ($spec['items'] as $it) {
        $s = $it['base'];
        $profile = $it['profile'] ?? null;
        if ($profile && $it['cat'] === 'Weapon') {
            list($stats, $hit) = bounty_roll_weapon_stats($profile, $level, $it['attributes'] ?? [], $it['episode_attributes'] ?? null);
            if (array_sum($stats) > 0 || $hit > 0) {
                $s .= ' ' . implode('/', $stats) . ($hit > 0 ? "/$hit" : '');
            }
        } elseif ($profile && ($it['cat'] === 'Armor' || $it['cat'] === 'Shield')) {
            list($def, $evp, $slots) = bounty_roll_armor_stats($profile, $level, $it['cat'], $it['def_range'] ?? 0, $it['evp_range'] ?? 0);
            if ($def > 0) $s .= " +{$def}def";
            if ($evp > 0) $s .= " +{$evp}evp";
            if ($slots > 0) $s .= " +{$slots}";
        }
        $parts[] = $s;
    }
    $parts = array_merge($parts, bounty_photon_parts($spec));
    $parts[] = $spec['meseta'] . ' Meseta';
    return implode(', ', $parts);
}
