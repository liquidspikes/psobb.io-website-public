<?php
/**
 * --------------------------------------------------------------------------
 * Contracts (Bounty System Rework)
 * --------------------------------------------------------------------------
 * A quarter of dropped Ultra Rare and Mythic Rare bounties come as contracts: big jobs tied to the
 * episode they dropped in, which never expire. A character holds up to 3 (their own slots; no queue)
 * and can abandon one for free. With 3 held, a contract drop stays a normal weekly drop.
 *
 *   EPISODE_RUN  a full run of the episode in one game: every field area and every boss, on the
 *                contract's difficulty or higher. Ultra Rare: with a mixed party (a Hunter, a Ranger
 *                and a Force at every boss). Mythic Rare: a hardcore carry (3+ players 10+ levels
 *                below the holder, by the levels they joined the game at, at every boss) or against
 *                the clock (from entering the first area). Leaving the game starts the run over.
 *                Target "EP:VARIANT:MINUTES@Difficulty" ("4:carry:0@Ultimate", "1:timed:150@Very Hard").
 *   RARE_TARGET  defeat a rare creature in the episode (Kondrieu, a Hildeblue, a Nar Lily ...). A kill
 *                by anyone in the holder's game counts. Needs the newserv kill patch.
 *                Target "TARGET:N:EP@Difficulty" ("KONDRIEU:1:4@Ultimate").
 *
 * Rewards: the holder gets the contract's own reward, chosen when it starts: an Ultra Rare run pays
 * from the episode's Ultra Rare items, a Mythic Rare run from the episode's items rarer than
 * 1/75,000 a kill server-wide (Challenge Mode's reserve among them, so Episode 4's are payable too),
 * a hunt from the creature's own drops. Everyone else in the game when it is done gets a reward one
 * tier lower, built for their own level, class and Section ID (a run: if they were there for at
 * least half its bosses).
 */

function bounty_contract_cfg()
{
    return bounty_config()['contracts'];
}

/**
 * Rare creatures for hunts (api/data/bounty_areas.json, built by tools/bounty_data/build.py).
 */
function bounty_rare_enemy_data()
{
    return bounty_area_data()['rare_enemies'] ?? [];
}

function bounty_contract_run_bosses($episode)
{
    return array_map('intval', bounty_contract_cfg()['run_bosses'][(string) (int) $episode] ?? bounty_boss_arena_floors($episode));
}

/**
 * Hunt targets of a tier that are met in an episode.
 */
function bounty_contract_hunt_targets($tier, $episode)
{
    $out = [];
    $data = bounty_rare_enemy_data();
    foreach (bounty_contract_cfg()['hunt_targets'][$tier] ?? [] as $t) {
        if (!empty($data[$t]['episodes']['Ep' . (int) $episode]['items'])) {
            $out[] = $t;
        }
    }
    return $out;
}

/**
 * How many of a creature a hunt asks for, by effort: about hunt_runs area runs' worth (Ultra Rare 10,
 * Mythic Rare 30) in the area where it is met most, at least 1 and at most hunt_max. With newserv's
 * default rates that is 3 Kondrieus (1 in 10 boss runs) or 3 Del Rappies (1 in 4 Crater runs), and one
 * of the rest (a Hildeblue is about 1 in 100 Forest runs, so it stays at one).
 */
function bounty_contract_hunt_count($tier, array $episode_info)
{
    $cc = bounty_contract_cfg();
    $per_run = $episode_info['per_run'] ? max(array_map('floatval', $episode_info['per_run'])) : 0.0;
    $n = (int) round((float) ($cc['hunt_runs'][$tier] ?? 10) * $per_run);
    return max(1, min((int) ($cc['hunt_max'] ?? 5), $n));
}

/**
 * The reward area of a contract: its whole episode (items from anywhere in it), with weapon
 * attributes for the episode's enemies or, for a hunt, those of the area the creature lives in.
 */
function bounty_contract_reward_area($episode, $setting = null)
{
    $ep = (int) $episode;
    $hub = ['1' => 'ep1_pioneer2', '2' => 'ep2_lab', '4' => 'ep4_pioneer2'][(string) $ep] ?? 'ep1_pioneer2';
    $card = bounty_area_data()['settings'][$setting ?: $hub] ?? null;
    $area = bounty_reward_area(null, $ep, $card['attributes'] ?? null, []);
    $area['label'] = "contract_ep{$ep}" . ($setting ? "_$setting" : '');
    return $area;
}

/**
 * Chooses a contract's objective when it starts (bounty_pick_rework_objective() calls this).
 *
 * @return array goal, target, friendly, episode, setting_id, difficulty, location_override, extra_lines,
 *               reward_area, reward_opts
 */
function bounty_pick_contract_objective(array $client, array $row, $tier)
{
    $cc = bounty_contract_cfg();
    $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
    $ep = in_array((int) ($meta['episode'] ?? 0), [1, 2, 4], true) ? (int) $meta['episode'] : [1, 2, 4][mt_rand(0, 2)];
    $diff = bounty_weekly_difficulty((int) ($client['Level'] ?? 1), $tier);
    $at = ($diff === 'Ultimate') ? 'on Ultimate' : "on $diff or higher";
    $weights = array_map('floatval', $cc['objectives'][$tier] ?? ['RUN_TIMED' => 1]);
    $targets = bounty_contract_hunt_targets($tier, $ep);
    if (!isset($client['RareEnemyKillCount']) || !$targets) {
        unset($weights['RARE_TARGET']); // hunts need the newserv kill patch, and a creature met here
    }
    $kind = bounty_weighted_pick($weights) ?: 'RUN_TIMED';
    $hub = bounty_lore()['world']['hubs'][(string) $ep] ?? ['1' => 'ep1_pioneer2', '2' => 'ep2_lab', '4' => 'ep4_pioneer2'][(string) $ep];
    $scale = $cc['reward_scale'] ?? [];
    $never = 'A contract never expires: the client will wait for the right hunter, however long it takes.';

    if ($kind === 'RARE_TARGET') {
        $tid = $targets[array_rand($targets)];
        $info = bounty_rare_enemy_data()[$tid];
        $epi = $info['episodes']['Ep' . $ep];
        $n = bounty_contract_hunt_count($tier, $epi);
        $target = "$tid:$n:$ep@$diff";
        $setting = $epi['settings'][0] ?? null;
        $where = [];
        foreach ($epi['settings'] as $sid) {
            $where[] = bounty_lore()['settings'][$sid]['name'] ?? $sid;
        }
        return [
            'goal' => 'RARE_TARGET',
            'target' => $target,
            'friendly' => bounty_contract_objective_text('RARE_TARGET', $target),
            'episode' => $ep,
            'setting_id' => (isset(bounty_lore()['settings'][$setting])) ? $setting : $hub,
            'difficulty' => $diff,
            'location_override' => "This is a contract to hunt a rare creature, the {$info['label']}, which shows itself only rarely (" . implode(', ', $where) . ") $at. "
                . "The hunter must defeat " . ($n === 1 ? 'one' : $n) . ". It is the creature itself the client wants: name it. $never",
            'extra_lines' => [],
            'reward_area' => bounty_contract_reward_area($ep, $setting),
            'reward_opts' => ['pool' => 'creature', 'creature_items' => $epi['items'], 'max_area' => 1, 'scale' => (float) ($scale['hunt'] ?? 1.5)],
        ];
    }

    $variant = ['RUN_DIVERSE' => 'diverse', 'RUN_CARRY' => 'carry', 'RUN_TIMED' => 'timed'][$kind] ?? 'timed';
    $minutes = ($variant === 'timed') ? (int) ($cc['run_minutes'][(string) $ep] ?? 150) : 0;
    $target = "$ep:$variant:$minutes@$diff";
    $bosses = bounty_contract_boss_names($ep);
    $carry = $cc['carry'];
    $party = [
        'diverse' => 'The client wants a mixed party along: a hunter, a ranger and a force must all be there at every boss.',
        'carry' => "The client wants a veteran to take at least {$carry['players']} far less experienced hunters ({$carry['levels']} or more levels below the hunter) through the whole expedition, all of them there at every boss.",
        'timed' => 'The client wants it done fast: the whole expedition within ' . bounty_duration_text($minutes * 60) . ' of setting foot in the first area.',
    ][$variant];
    return [
        'goal' => 'EPISODE_RUN',
        'target' => $target,
        'friendly' => bounty_contract_objective_text('EPISODE_RUN', $target),
        'episode' => $ep,
        'setting_id' => $hub,
        'difficulty' => $diff,
        'location_override' => "This is a contract for a full expedition through Episode $ep in one outing: " . bounty_episode_area_names($ep)
            . ', and every guardian there (' . implode(', ', $bosses) . "), $at. $party $never",
        'extra_lines' => [],
        'reward_area' => bounty_contract_reward_area($ep),
        'reward_opts' => ['pool' => ($tier === 'mythic') ? 'contract_run' : $tier, 'max_area' => 1, 'scale' => (float) ($scale['run'] ?? 2.0)],
    ];
}

// ---------------------------------------------------------------------------
// Progress (each cron pass)
// ---------------------------------------------------------------------------

/**
 * True if the party in a game meets a run's requirement right now: a Hunter, a Ranger and a Force
 * (diverse), or enough players far enough below the holder by the levels they joined at (carry).
 *
 * @param array $members  [['key', 'acc', 'class', 'join_level', ...], ...] everyone in the game
 */
function bounty_contract_party_ok($variant, $holder_key, array $members)
{
    if ($variant === 'diverse') {
        $have = [];
        foreach ($members as $mem) {
            $have[strtoupper(substr((string) $mem['class'], 0, 2))] = true;
        }
        return isset($have['HU'], $have['RA'], $have['FO']);
    }
    if ($variant === 'carry') {
        $c = bounty_contract_cfg()['carry'];
        $mine = null;
        foreach ($members as $mem) {
            if ($mem['key'] === $holder_key) {
                $mine = (int) $mem['join_level'];
            }
        }
        if ($mine === null) {
            return false;
        }
        $n = 0;
        foreach ($members as $mem) {
            if ($mem['key'] !== $holder_key && (int) $mem['join_level'] <= $mine - (int) $c['levels']) {
                $n++;
            }
        }
        return $n >= (int) $c['players'];
    }
    return true;
}

/**
 * Updates a contract's progress for this cron pass and reports whether it is done.
 *
 * @param array $m     the mission row (id = player_missions id, goal_type, goal_target, progress, activated_at)
 * @param array $kills from bounty_kill_deltas() (boss_events)
 * @param array $cctx  'key' (the holder's state key), 'members' [lobby id => members], 'lobby_rares'
 *                     [lobby id => [newserv name => kills this pass]]
 * @param array|null $progress_out the progress after this pass
 */
function bounty_evaluate_contract($db, array $m, array $client, $lobby, array $kills, array $cctx, &$progress_out = null)
{
    $p = json_decode((string) ($m['progress'] ?? ''), true) ?: [];
    $changed = false;
    $done = false;
    $lobby_id = $client['LobbyID'] ?? null;
    $here = function ($ep, $diff) use ($lobby) {
        return $lobby && !empty($lobby['is_game']) && in_array($lobby['mode'], ['Normal', 'Solo'], true)
            && (int) $lobby['episode'] === (int) $ep && bounty_difficulty_rank($lobby['difficulty']) >= bounty_difficulty_rank($diff);
    };

    if ($m['goal_type'] === 'EPISODE_RUN') {
        $t = bounty_parse_run_target($m['goal_target']);
        $ep = $t['episode'];
        $floors = bounty_episode_floors($ep);
        $bosses = bounty_contract_run_bosses($ep);
        $in_run_game = $here($ep, $t['difficulty']);
        if ($in_run_game && ($p['lobby'] ?? null) !== $lobby_id) {
            // A new game: the run starts over
            $p = ['lobby' => $lobby_id, 'floors' => [], 'bosses' => [], 'party_ok' => [], 'seen' => [], 'started' => null];
            $changed = true;
        }
        if ($in_run_game) {
            $f = (int) ($client['LocationFloor'] ?? -1);
            if (in_array($f, $floors, true)) {
                if (!in_array($f, $p['floors'], true)) {
                    $p['floors'][] = $f;
                    sort($p['floors']);
                    $changed = true;
                }
                if (empty($p['started'])) {
                    $p['started'] = time();
                    $changed = true;
                }
            }
        }
        // Boss kills in the run's game (a kill seen after the holder left the arena still has its room)
        $finish = time();
        if (isset($p['lobby'])) {
            // Bosses killed in the run's game by anyone in it count (the player's own kills are among them)
            foreach (array_merge($cctx['lobby_boss_events'][$p['lobby']] ?? [], $kills['boss_events']) as $e) {
                if (!empty($m['activated_at']) && (int) $e['time'] < (int) $m['activated_at']) {
                    continue;
                }
                if (($e['lobby_id'] ?? null) !== $p['lobby'] || (isset($e['episode']) && (int) $e['episode'] !== $ep)) {
                    continue;
                }
                $bf = (int) $e['floor'];
                if (!in_array($bf, $bosses, true)) {
                    continue;
                }
                $members = $cctx['members'][$p['lobby']] ?? [];
                $ok = bounty_contract_party_ok($t['variant'], $cctx['key'], $members);
                if (!in_array($bf, $p['bosses'], true)) {
                    $p['bosses'][] = $bf;
                }
                $p['party_ok'][(string) $bf] = !empty($p['party_ok'][(string) $bf]) || $ok;
                foreach ($members as $mem) {
                    if ($mem['key'] !== $cctx['key'] && !in_array($bf, $p['seen'][$mem['key']] ?? [], true)) {
                        $p['seen'][$mem['key']][] = $bf;
                    }
                }
                if (empty($p['started'])) {
                    $p['started'] = (int) ($e['entry_time'] ?? $e['time']);
                }
                $finish = (int) $e['time'];
                $changed = true;
            }
            $all = !array_diff($floors, $p['floors'] ?? []) && !array_diff($bosses, $p['bosses'] ?? []);
            $party = true;
            if (in_array($t['variant'], ['diverse', 'carry'], true)) {
                foreach ($bosses as $bf) {
                    if (in_array($bf, $p['bosses'] ?? [], true) && empty($p['party_ok'][(string) $bf])) {
                        $party = false; // a boss fell without the party: this game can't count any more
                        if (empty($p['missed'])) {
                            $p['missed'] = true;
                            $changed = true;
                        }
                    }
                }
            }
            if ($t['variant'] === 'timed' && !empty($p['started']) && empty($p['late'])) {
                // timed from entering the first area to the last requirement met
                $end = ($all && $party) ? $finish : time();
                if ($end - (int) $p['started'] > $t['minutes'] * 60) {
                    $p['late'] = true; // too slow in this game: a new game starts the run over
                    $changed = true;
                }
            }
            $in_time = empty($p['late']);
            $done = $all && $party && $in_time;
            if ($done) {
                $p['finished'] = $finish;
                $changed = true;
            }
        }
    } elseif ($m['goal_type'] === 'RARE_TARGET') {
        $t = bounty_parse_hunt_target($m['goal_target']);
        $names = bounty_rare_enemy_data()[$t['target']]['names'] ?? [$t['target']];
        if ($here($t['episode'], $t['difficulty']) && $lobby_id !== null) {
            $add = 0;
            foreach ($cctx['lobby_rares'][$lobby_id] ?? [] as $name => $n) {
                if (in_array($name, $names, true)) {
                    $add += (int) $n;
                }
            }
            if ($add > 0) {
                $p['count'] = (int) ($p['count'] ?? 0) + $add;
                $p['lobby'] = $lobby_id;
                $changed = true;
            }
        }
        $done = (int) ($p['count'] ?? 0) >= $t['count'];
    }

    if ($changed) {
        $u = $db->prepare("UPDATE player_missions SET progress = :p WHERE id = :id");
        $u->bindValue(':p', json_encode($p), SQLITE3_TEXT);
        $u->bindValue(':id', (int) $m['id'], SQLITE3_INTEGER);
        $u->execute();
    }
    $progress_out = $p;
    return $done;
}

// ---------------------------------------------------------------------------
// Shares for the rest of the party
// ---------------------------------------------------------------------------

/**
 * When a contract is done, everyone else in the game gets a reward one tier lower (Ultra Rare →
 * Rare, Mythic Rare → Ultra Rare), built for them, waiting in their Claim Center. For a run, only
 * players who were there for at least half of its bosses.
 *
 * @param array $m        the contract's mission row (tier, meta, goal_type, goal_target, mission_title)
 * @param array $progress its progress (bounty_evaluate_contract())
 * @param array $members  everyone in the game (cron: lobby members)
 * @return int shares paid
 */
function bounty_contract_pay_shares($db, array $m, array $holder, array $progress, array $members)
{
    $cc = bounty_contract_cfg();
    $tier = $cc['share_tier'][$m['tier']] ?? 'rare';
    $holder_acc = (int) ($holder['Account']['AccountID'] ?? 0);
    $holder_key = $holder_acc . '_' . ($holder['Name'] ?? 'Unknown');
    $run = ($m['goal_type'] === 'EPISODE_RUN');
    $ep = $run ? bounty_parse_run_target($m['goal_target'])['episode'] : bounty_parse_hunt_target($m['goal_target'])['episode'];
    $need_seen = $run ? (int) ceil(count(bounty_contract_run_bosses($ep)) / 2) : 0;
    $area = bounty_contract_reward_area($ep);
    $objective = $run ? bounty_contract_objective_text('EPISODE_RUN', $m['goal_target']) : bounty_contract_objective_text('RARE_TARGET', $m['goal_target']);
    $title = 'Contract share: ' . ($m['mission_title'] ?? 'a contract');
    $desc = ($holder['Name'] ?? 'A hunter') . " finished a contract with you in the game ($objective). This is your share, one tier below the contract's own reward.";
    $paid = 0;
    $srv = get_server_address();
    foreach ($members as $mem) {
        if ((int) $mem['acc'] === $holder_acc || $mem['key'] === $holder_key) {
            continue;
        }
        if ($need_seen && count($progress['seen'][$mem['key']] ?? []) < $need_seen) {
            continue; // not there for enough of the run
        }
        $spec = bounty_build_reward_spec_v2($tier, $mem['client'], $area);
        $ins = $db->prepare("INSERT INTO missions (title, description, goal_type, goal_target, reward_item_string, tier, cadence, pool, source, reward_spec, meta)
                             VALUES (:t, :d, 'CONTRACT_SHARE', '0', :ri, :tier, 'share', 'share', 'contract_share', :spec, :meta)");
        $ins->bindValue(':t', $title, SQLITE3_TEXT);
        $ins->bindValue(':d', $desc, SQLITE3_TEXT);
        $ins->bindValue(':ri', bounty_reward_display($spec), SQLITE3_TEXT);
        $ins->bindValue(':tier', $tier, SQLITE3_TEXT);
        $ins->bindValue(':spec', json_encode($spec), SQLITE3_TEXT);
        $ins->bindValue(':meta', json_encode(['contract_mission' => (int) ($m['mission_id'] ?? 0), 'holder' => $holder['Name'] ?? null]), SQLITE3_TEXT);
        if (!$ins->execute()) {
            continue;
        }
        $mid = $db->lastInsertRowID();
        $pm = $db->prepare("INSERT INTO player_missions (account_id, character_name, mission_id, status, earned_at, rerolled, progress)
                            VALUES (:acc, :cname, :mid, 'ready_to_redeem', :now, 0, '{}')");
        $pm->bindValue(':acc', (int) $mem['acc'], SQLITE3_INTEGER);
        $pm->bindValue(':cname', (string) $mem['name'], SQLITE3_TEXT);
        $pm->bindValue(':mid', $mid, SQLITE3_INTEGER);
        $pm->bindValue(':now', time(), SQLITE3_INTEGER);
        $pm->execute();
        $paid++;
        $lang = $db->querySingle("SELECT language FROM users WHERE account_id = " . (int) $mem['acc']) ?: 'en';
        $label = bounty_tier_label($tier);
        if ($lang === 'jp') {
            send_personal_mail($mem['acc'], "ハンターズギルド", "契約の達成に参加しました！{$label}の報酬が{$srv}で受け取れます。");
        } elseif ($lang === 'ru') {
            send_personal_mail($mem['acc'], "Гильдия Охотников", "Вы помогли выполнить контракт! Ваша доля ({$label}) ждёт на {$srv}.");
        } else {
            send_personal_mail($mem['acc'], "Hunters Guild", "You helped finish a contract! Your {$label} share is waiting at {$srv}.");
        }
        @touch(__DIR__ . '/../db/.bounty_' . (int) $mem['acc']);
        echo "[BOUNTY] {$mem['name']}: contract share ({$label}) for helping {$holder['Name']}\n";
    }
    return $paid;
}
