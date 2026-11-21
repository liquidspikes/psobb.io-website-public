<?php
/**
 * --------------------------------------------------------------------------
 * Enemy kill counts
 * --------------------------------------------------------------------------
 * With the newserv kill-count patches, /y/clients reports each player's killing blows on this
 * connection by episode, difficulty, game mode and enemy (KillCounts), plus totals (EnemyKillCount,
 * RareEnemyKillCount). A boss counts once per fight, by its main form; its parts don't count.
 *
 * - Each character's own kills are kept for good in enemy_kill_counts (one row per episode,
 *   difficulty, mode and enemy), shown in the dashboard's character viewer (api/kill_counts.php).
 * - Bounties give credit to the whole game: a kill by anyone in a game counts for every player's
 *   active kill bounties there (api/cron_missions.php builds a summary per game each pass).
 *
 * newserv's counters start at zero on each connection. The cron keeps the last reading in its player
 * state and records the difference; a lower reading means the player reconnected (or newserv
 * restarted), so the new reading is all new kills. Kills from before a character was first seen are
 * not counted.
 */

/**
 * "Episode 1|Ultimate|Normal|BOOMA"
 */
function enemy_kill_key(array $e)
{
    return ($e['Episode'] ?? '') . '|' . ($e['Difficulty'] ?? '') . '|' . ($e['Mode'] ?? '') . '|' . ($e['Enemy'] ?? '');
}

/**
 * Episode number (1, 2, 4) from newserv's "Episode 1".
 */
function enemy_kill_episode_number($name)
{
    return preg_match('/(\d)/', (string) $name, $m) ? (int) $m[1] : 0;
}

/**
 * New kills since the last pass, from a /y/clients entry and the cron's saved state.
 *
 * @return array ['has' => bool (KillCounts reported), 'entries' => [[episode, difficulty, mode, enemy, name,
 *               rare, boss, n], ...], 'state' => [key => count] (to save), 'reset' => bool]
 */
function enemy_kill_deltas(array $client, array $prev_state)
{
    if (!isset($client['KillCounts']) || !is_array($client['KillCounts'])) {
        return ['has' => false, 'entries' => [], 'state' => null, 'reset' => false];
    }
    $cur = [];
    $info = [];
    foreach ($client['KillCounts'] as $e) {
        if (!is_array($e) || !isset($e['Enemy'], $e['Count'])) {
            continue;
        }
        $k = enemy_kill_key($e);
        $cur[$k] = ($cur[$k] ?? 0) + (int) $e['Count'];
        $info[$k] = $e;
    }
    $prev = $prev_state['kill_counts'] ?? null;
    if (!is_array($prev)) {
        // First sight: kills from before tracking began don't count
        return ['has' => true, 'entries' => [], 'state' => $cur, 'reset' => false];
    }
    $reset = false;
    foreach ($prev as $k => $n) {
        if ((int) ($cur[$k] ?? 0) < (int) $n) {
            $reset = true; // newserv's counters restarted (a reconnect, or a server restart)
            break;
        }
    }
    $entries = [];
    foreach ($cur as $k => $n) {
        $d = $reset ? $n : $n - (int) ($prev[$k] ?? 0);
        if ($d <= 0) {
            continue;
        }
        $e = $info[$k];
        $entries[] = [
            'episode' => enemy_kill_episode_number($e['Episode'] ?? ''),
            'difficulty' => (string) ($e['Difficulty'] ?? 'Normal'),
            'mode' => (string) ($e['Mode'] ?? 'Normal'),
            'enemy' => (string) $e['Enemy'],
            'name' => (string) ($e['Name'] ?? $e['Enemy']),
            'rare' => !empty($e['Rare']),
            'boss' => !empty($e['Boss']),
            'n' => (int) $d,
        ];
    }
    return ['has' => true, 'entries' => $entries, 'state' => $cur, 'reset' => $reset];
}

/**
 * Adds a character's new kills to their lifetime counts.
 */
function enemy_kills_record($db, $account_id, $character, array $entries)
{
    if (!$entries) {
        return;
    }
    $now = time();
    $upd = $db->prepare("UPDATE enemy_kill_counts SET kills = kills + :n, name = :name, rare = MAX(rare, :rare), last_kill_at = :now
                         WHERE account_id = :acc AND character_name = :cname AND episode = :ep AND difficulty = :diff AND mode = :mode AND enemy = :enemy");
    $ins = $db->prepare("INSERT INTO enemy_kill_counts (account_id, character_name, episode, difficulty, mode, enemy, name, rare, boss, kills, first_kill_at, last_kill_at)
                         VALUES (:acc, :cname, :ep, :diff, :mode, :enemy, :name, :rare, :boss, :n, :now, :now)");
    foreach ($entries as $e) {
        foreach ([$upd, $ins] as $st) {
            $st->reset();
            $st->bindValue(':acc', (int) $account_id, SQLITE3_INTEGER);
            $st->bindValue(':cname', (string) $character, SQLITE3_TEXT);
            $st->bindValue(':ep', (int) $e['episode'], SQLITE3_INTEGER);
            $st->bindValue(':diff', $e['difficulty'], SQLITE3_TEXT);
            $st->bindValue(':mode', $e['mode'], SQLITE3_TEXT);
            $st->bindValue(':enemy', $e['enemy'], SQLITE3_TEXT);
            $st->bindValue(':name', $e['name'], SQLITE3_TEXT);
            $st->bindValue(':rare', $e['rare'] ? 1 : 0, SQLITE3_INTEGER);
            $st->bindValue(':n', (int) $e['n'], SQLITE3_INTEGER);
            $st->bindValue(':now', $now, SQLITE3_INTEGER);
        }
        $ins->bindValue(':boss', $e['boss'] ? 1 : 0, SQLITE3_INTEGER);
        $upd->execute();
        if ($db->changes() === 0) {
            $ins->execute();
        }
    }
}

/**
 * Adds one player's new kills to their game's summary (what the whole game killed this pass).
 *
 * @param array $summary  ['enemy', 'rare', 'boss', 'by_name' => [ENEMY => n], 'rare_names' => [ENEMY => n],
 *                         'entries' => [...], 'typed' => bool]
 * @param array|null $entries kill entries (enemy_kill_deltas()), or null to add plain totals
 */
function enemy_kills_add_to_summary(array &$summary, $entries, $enemy = 0, $rare = 0, $rare_name = null)
{
    $summary += ['enemy' => 0, 'rare' => 0, 'boss' => 0, 'by_name' => [], 'rare_names' => [], 'entries' => [], 'typed' => true];
    if ($entries === null) {
        // newserv without KillCounts: totals only, and the last rare enemy's name
        $summary['typed'] = false;
        $summary['enemy'] += (int) $enemy;
        $summary['rare'] += (int) $rare;
        if ($rare > 0 && $rare_name) {
            $summary['rare_names'][$rare_name] = ($summary['rare_names'][$rare_name] ?? 0) + (int) $rare;
        }
        return;
    }
    foreach ($entries as $e) {
        $summary['enemy'] += $e['n'];
        $summary['by_name'][$e['enemy']] = ($summary['by_name'][$e['enemy']] ?? 0) + $e['n'];
        if ($e['rare']) {
            $summary['rare'] += $e['n'];
            $summary['rare_names'][$e['enemy']] = ($summary['rare_names'][$e['enemy']] ?? 0) + $e['n'];
        }
        if ($e['boss']) {
            $summary['boss'] += $e['n'];
        }
        $summary['entries'][] = $e;
    }
}

/**
 * Display names of the bosses' main forms (newserv's types).
 */
function enemy_kill_boss_label($enemy)
{
    $names = ['DRAGON' => 'Dragon', 'DE_ROL_LE' => 'De Rol Le', 'VOL_OPT_2' => 'Vol Opt', 'DARK_FALZ_2' => 'Dark Falz', 'DARK_FALZ_3' => 'Dark Falz',
              'BARBA_RAY' => 'Barba Ray', 'GOL_DRAGON' => 'Gol Dragon', 'GAL_GRYPHON' => 'Gal Gryphon', 'OLGA_FLOW_2' => 'Olga Flow',
              'SAINT_MILION' => 'Saint-Milion', 'SHAMBERTIN' => 'Shambertin', 'KONDRIEU' => 'Kondrieu'];
    return $names[$enemy] ?? null;
}

/**
 * A character's lifetime kill counts, grouped for display: per episode, one row per enemy with its
 * kills on each difficulty (Normal and Solo games), in Challenge Mode, and in all.
 *
 * @return array ['episodes' => ['1' => [rows], ...], 'totals' => ['kills', 'bosses', 'rares']]
 */
function enemy_kills_for_character($db, $account_id, $character)
{
    $st = $db->prepare("SELECT episode, difficulty, mode, enemy, name, rare, boss, kills FROM enemy_kill_counts
                        WHERE account_id = :acc AND character_name = :cname");
    $st->bindValue(':acc', (int) $account_id, SQLITE3_INTEGER);
    $st->bindValue(':cname', (string) $character, SQLITE3_TEXT);
    $r = $st->execute();
    $rows = [];
    $totals = ['kills' => 0, 'bosses' => 0, 'rares' => 0];
    $diffs = ['Normal', 'Hard', 'Very Hard', 'Ultimate'];
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) {
        $ep = (string) (int) $x['episode'];
        // Dark Falz's final form is DARK_FALZ_2 on Normal and DARK_FALZ_3 above: one row
        $id = ($x['enemy'] === 'DARK_FALZ_2') ? 'DARK_FALZ_3' : $x['enemy'];
        $row = &$rows[$ep][$id];
        if (!$row) {
            $row = ['enemy' => $id, 'name' => null, 'ultimate_name' => null, 'rare' => false, 'boss' => false,
                    'counts' => array_fill_keys($diffs, 0), 'challenge' => 0, 'battle' => 0, 'total' => 0];
        }
        $n = (int) $x['kills'];
        $label = enemy_kill_boss_label($x['enemy']) ?? $x['name'];
        if ($x['difficulty'] === 'Ultimate') {
            $row['ultimate_name'] = $label;
        } else {
            $row['name'] = $label;
        }
        $row['rare'] = $row['rare'] || (int) $x['rare'] === 1;
        $row['boss'] = $row['boss'] || (int) $x['boss'] === 1;
        if ($x['mode'] === 'Challenge') {
            $row['challenge'] += $n;
        } elseif ($x['mode'] === 'Battle') {
            $row['battle'] += $n;
        } elseif (isset($row['counts'][$x['difficulty']])) {
            $row['counts'][$x['difficulty']] += $n;
        }
        $row['total'] += $n;
        $totals['kills'] += $n;
        if ((int) $x['boss'] === 1) $totals['bosses'] += $n;
        if ((int) $x['rare'] === 1) $totals['rares'] += $n;
        unset($row);
    }
    $out = [];
    foreach ($rows as $ep => $list) {
        foreach ($list as &$row) {
            $row['name'] = $row['name'] ?? $row['ultimate_name'];
            if ($row['ultimate_name'] === $row['name']) {
                $row['ultimate_name'] = null;
            }
        }
        unset($row);
        // Bosses first, then by kills
        usort($list, function ($a, $b) {
            return [$b['boss'], $b['total']] <=> [$a['boss'], $a['total']];
        });
        $out[$ep] = array_values($list);
    }
    ksort($out);
    return ['episodes' => $out, 'totals' => $totals];
}
