<?php
/**
 * CLI-only retroactive lore backfill for active/claimable bounties in website.db.
 *
 * Updates ONLY `missions.title`, `missions.description`, and (when an Episode-ambiguous
 * floor 12/13/14 boss bounty lacks an explicit `@episode` tag) locks `missions.goal_target`
 * via `make_boss_target()` using the episode resolved from the original text so rewriting
 * flavor text can never silently flip Episode 1 <-> Episode 2 targets.
 *
 * Leaves `goal_type`, `reward_item_string`, `player_missions.progress`, `status`,
 * `account_id`, and `character_name` untouched.
 *
 * Usage:
 *   php api/backfill_bounty_lore.php [--dry-run] [--apply] [--in-progress-only]
 *                                    [--all-open] [--workers=6] [--limit=N]
 *                                    [--backup-file=/path/to/backup.db]
 *                                    [--db-file=/path/to/website.db]
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/bounty_lore.php';

$opts = getopt('', [
    'dry-run',
    'apply',
    'in-progress-only',
    'all-open',
    'workers::',
    'limit::',
    'backup-file::',
    'db-file::',
]);

$apply = isset($opts['apply']);
$inProgressOnly = isset($opts['in-progress-only']);
$allOpen = isset($opts['all-open']);
$workers = max(1, min(12, (int) ($opts['workers'] ?? 6)));
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;
$backupFile = isset($opts['backup-file']) ? trim((string) $opts['backup-file']) : '';
$dbFile = isset($opts['db-file']) ? trim((string) $opts['db-file']) : '';

if (!bounty_lore()) {
    fwrite(STDERR, "ERROR: api/lore/*.json files are missing or invalid.\n");
    exit(1);
}

if ($apply && empty($GEMINI_API_KEY)) {
    fwrite(STDERR, "ERROR: GEMINI_API_KEY is not configured.\n");
    exit(1);
}

$defaultDbPath = __DIR__ . '/../db/website.db';
$targetDbPath = $dbFile !== '' ? $dbFile : $defaultDbPath;

if (!$apply) {
    if (!is_readable($targetDbPath)) {
        fwrite(STDERR, "ERROR: Database file is not readable at specified path.\n");
        exit(1);
    }
    $db = new SQLite3($targetDbPath, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(5000);
} elseif ($dbFile !== '') {
    $db = new SQLite3($dbFile, SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(15000);
} else {
    $db = get_db();
}

/**
 * Infers difficulty tier and nominal hunter level from a bounty's Meseta payout.
 * Mirrors the tier multipliers used by cron_missions.php (`1x`, `2x`, `5x`, `10x`).
 */
function backfill_infer_tier($rewardStr, $goalType)
{
    $meseta = 0;
    if (preg_match('/(\d+)\s*Meseta/i', (string) $rewardStr, $m)) {
        $meseta = (int) $m[1];
    }
    if ($goalType === 'CHALLENGE_STAGES' && $meseta > 0) {
        $meseta = (int) round($meseta / 3);
    }
    if ($meseta >= 10000) {
        return ['Ultimate', 90];
    }
    if ($meseta >= 5000) {
        return ['Very Hard', 50];
    }
    if ($meseta >= 2000) {
        return ['Hard', 25];
    }
    return ['Normal', 10];
}

/**
 * Normalizes a user language column into 'en', 'jp', or 'ru'.
 */
function backfill_normalize_lang($lang)
{
    $l = strtolower(trim((string) $lang));
    if ($l === 'jp' || $l === 'ja') {
        return 'jp';
    }
    if ($l === 'ru') {
        return 'ru';
    }
    return 'en';
}

/**
 * Runs a batch of Gemini JSON prompts in parallel using `/usr/bin/curl -K -`
 * so `GEMINI_API_KEY` stays in stdin config (never visible on process argv).
 *
 * @param array  $items Each item must contain 'prompt'
 * @param string $model
 * @param string $apiKey
 * @return array Keyed by input array index => ['ok'=>bool, 'title'=>string, 'description'=>string, 'error'=>string]
 */
function backfill_run_gemini_batch(array $items, $model, $apiKey)
{
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent";
    $procs = [];
    $results = [];

    foreach ($items as $idx => $item) {
        $payloadFile = tempnam(sys_get_temp_dir(), 'bounty_lore_req_');
        if ($payloadFile === false) {
            $results[$idx] = ['ok' => false, 'title' => '', 'description' => '', 'error' => 'tempnam failed'];
            continue;
        }
        @chmod($payloadFile, 0600);
        $payloadJson = json_encode([
            'contents' => [['parts' => [['text' => $item['prompt']]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.95,
            ],
        ], JSON_UNESCAPED_UNICODE);
        file_put_contents($payloadFile, $payloadJson);

        $curlCfg = implode("\n", [
            'url = "' . $url . '"',
            'header = "Content-Type: application/json"',
            'header = "x-goog-api-key: ' . str_replace(['\\', '"', "\n", "\r"], '', $apiKey) . '"',
            'request = "POST"',
            'max-time = 30',
            'silent',
            'show-error',
            'data-binary = "@' . str_replace(['\\', '"'], ['\\\\', '\\"'], $payloadFile) . '"',
        ]) . "\n";

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(['/usr/bin/curl', '-K', '-'], $descriptors, $pipes);
        if (!is_resource($proc)) {
            @unlink($payloadFile);
            $results[$idx] = ['ok' => false, 'title' => '', 'description' => '', 'error' => 'proc_open failed'];
            continue;
        }
        fwrite($pipes[0], $curlCfg);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $procs[$idx] = [
            'proc' => $proc,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'out' => '',
            'err' => '',
            'file' => $payloadFile,
        ];
    }

    while (!empty($procs)) {
        foreach ($procs as $idx => &$st) {
            $chunkOut = stream_get_contents($st['stdout']);
            if ($chunkOut !== false) {
                $st['out'] .= $chunkOut;
            }
            $chunkErr = stream_get_contents($st['stderr']);
            if ($chunkErr !== false) {
                $st['err'] .= $chunkErr;
            }
            $status = proc_get_status($st['proc']);
            if (!$status['running']) {
                $st['out'] .= (string) stream_get_contents($st['stdout']);
                $st['err'] .= (string) stream_get_contents($st['stderr']);
                fclose($st['stdout']);
                fclose($st['stderr']);
                $exitCode = proc_close($st['proc']);
                @unlink($st['file']);

                if ($exitCode !== 0 || trim($st['out']) === '') {
                    $errTxt = trim($st['err']) ?: "curl exit {$exitCode}";
                    $results[$idx] = ['ok' => false, 'title' => '', 'description' => '', 'error' => $errTxt];
                } else {
                    $outer = json_decode($st['out'], true);
                    $text = $outer['candidates'][0]['content']['parts'][0]['text'] ?? '';
                    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) $text));
                    $inner = json_decode($text, true);
                    $newTitle = trim((string) ($inner['title'] ?? ''));
                    $newDesc = trim((string) ($inner['description'] ?? ''));
                    if (is_array($inner) && $newTitle !== '' && $newDesc !== '') {
                        $results[$idx] = [
                            'ok' => true,
                            'title' => mb_substr($newTitle, 0, 140, 'UTF-8'),
                            'description' => mb_substr($newDesc, 0, 750, 'UTF-8'),
                            'error' => '',
                        ];
                    } else {
                        $apiErr = $outer['error']['message'] ?? 'invalid JSON from Gemini';
                        $results[$idx] = ['ok' => false, 'title' => '', 'description' => '', 'error' => (string) $apiErr];
                    }
                }
                unset($procs[$idx]);
            }
        }
        unset($st);
        if (!empty($procs)) {
            usleep(20000);
        }
    }

    return $results;
}

$statuses = $inProgressOnly
    ? "'in_progress'"
    : "'in_progress', 'ready_to_redeem', 'redeeming'";

$whereCutoff = $allOpen
    ? ""
    : " AND COALESCE(m.created_at, '') < '2026-11-20 18:20:00'";

$sql = "SELECT m.id, m.title, m.description, m.goal_type, m.goal_target, m.reward_item_string, m.created_at,
               MIN(pm.character_name) AS character_name,
               MAX(COALESCE(u.language, 'en')) AS language,
               COUNT(pm.id) AS holder_count
        FROM player_missions pm
        JOIN missions m ON pm.mission_id = m.id
        LEFT JOIN users u ON pm.account_id = u.account_id
        WHERE pm.status IN ({$statuses}){$whereCutoff}
        GROUP BY m.id
        ORDER BY m.id DESC";
if ($limit > 0) {
    $sql .= " LIMIT " . (int) $limit;
}

$res = $db->query($sql);
$jobs = [];
$stats = [
    'total' => 0,
    'episode_locked' => 0,
    'ep2_locked' => 0,
    'blank_char' => 0,
    'lang_en' => 0,
    'lang_jp' => 0,
    'lang_ru' => 0,
    'prompt_bytes_min' => PHP_INT_MAX,
    'prompt_bytes_max' => 0,
];

$bossGoals = ['BOSS_ARENA', 'SPEEDRUN_BOSS', 'MENTOR_BOSS', 'HARDCORE_MENTOR', 'DIVERSE_PARTY_BOSS'];
$floorGoals = ['EXPLORATION', 'PATROL', 'SPEEDRUN_FLOOR'];

while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $stats['total']++;
    $missionId = (int) $row['id'];
    $goalType = (string) $row['goal_type'];
    $oldTarget = (string) $row['goal_target'];
    $newTarget = $oldTarget;
    $lockedEp = null;

    if (in_array($goalType, $bossGoals, true)) {
        $parsed = parse_boss_target($oldTarget);
        if (in_array($parsed['floor'], [12, 13, 14], true) && $parsed['episode'] === null) {
            $origEp = get_boss_episode_by_context((string) $row['title'], (string) $row['description'], $parsed['floor'], null);
            $newTarget = make_boss_target($parsed['floor'], $origEp, $parsed['time_limit']);
            $lockedEp = $origEp;
            $stats['episode_locked']++;
            if ($origEp === 2) {
                $stats['ep2_locked']++;
            }
        }
    }

    $ctx = bounty_lore_setting_for_objective(
        $goalType,
        $newTarget,
        (string) $row['title'],
        (string) $row['description'],
        $missionId
    );
    $sid = $ctx['setting_id'];
    $giver = bounty_pick_giver_seeded($sid, $missionId);
    $questGiver = $giver['name'] ?? "the Hunter's Guild";

    list($difficulty, $level) = backfill_infer_tier($row['reward_item_string'], $goalType);
    $userLang = backfill_normalize_lang($row['language'] ?? 'en');
    $stats['lang_' . $userLang] = ($stats['lang_' . $userLang] ?? 0) + 1;

    $objective = html_entity_decode(
        strip_tags(getClearObjective($goalType, $newTarget, (string) $row['title'], (string) $row['description'])),
        ENT_QUOTES,
        'UTF-8'
    );

    $charName = trim((string) ($row['character_name'] ?? ''));
    $holders = (int) ($row['holder_count'] ?? 1);
    $floor = in_array($goalType, $floorGoals, true) ? parse_floor_target($newTarget)['floor'] : $ctx['floor'];

    if ($charName !== '' && $holders === 1) {
        $prompt = bounty_build_prompt($sid, $giver, [
            'hunter_name' => $charName,
            'level' => $level,
            'class' => 'Hunter',
            'completed_line' => 'They are continuing active Guild service.',
            'goal' => $goalType,
            'objective' => $objective,
            'difficulty' => $difficulty,
            'is_location_goal' => $ctx['is_location_goal'],
            'quest_giver' => $questGiver,
            'hex_twist' => '',
            'recent_titles' => 'None',
            'floor' => $floor,
            'target' => $newTarget,
            'location_override' => $ctx['location_override'],
        ]) . bounty_prompt_tail($userLang, $goalType, $newTarget);
    } else {
        $stats['blank_char']++;
        $prompt = bounty_build_admin_mission_prompt($sid, $giver, [
            'goal' => $goalType,
            'objective' => $objective,
            'difficulty' => $difficulty,
            'is_location_goal' => $ctx['is_location_goal'],
            'floor' => $floor,
            'target' => $newTarget,
            'reward' => renderRewardString((string) $row['reward_item_string']),
            'recent_titles' => 'None',
            'location_override' => $ctx['location_override'],
        ], $userLang);
    }

    $plen = strlen($prompt);
    if ($plen < $stats['prompt_bytes_min']) $stats['prompt_bytes_min'] = $plen;
    if ($plen > $stats['prompt_bytes_max']) $stats['prompt_bytes_max'] = $plen;

    $jobs[] = [
        'id' => $missionId,
        'goal_type' => $goalType,
        'old_target' => $oldTarget,
        'new_target' => $newTarget,
        'setting' => $sid,
        'client' => $questGiver,
        'lang' => $userLang,
        'prompt' => $prompt,
    ];
}
$res->finalize();

echo "[BACKFILL] Mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";
echo "[BACKFILL] Scope: " . ($inProgressOnly ? 'in_progress only' : 'in_progress + ready_to_redeem + redeeming') . ($allOpen ? ' (all-open)' : ' (pre-2026-11-20 18:20Z)') . "\n";
echo "[BACKFILL] Matched missions: {$stats['total']} (EN={$stats['lang_en']}, JP={$stats['lang_jp']}, RU={$stats['lang_ru']})\n";
echo "[BACKFILL] Generic/blank-char prompts: {$stats['blank_char']} | Boss targets @ep-locked: {$stats['episode_locked']} (Ep2 locked: {$stats['ep2_locked']})\n";
if ($stats['total'] > 0) {
    echo "[BACKFILL] Prompt byte range: {$stats['prompt_bytes_min']}..{$stats['prompt_bytes_max']} bytes\n";
}

foreach (array_slice($jobs, 0, 5) as $sample) {
    $targetNote = ($sample['old_target'] !== $sample['new_target'])
        ? "{$sample['old_target']} -> {$sample['new_target']}"
        : $sample['new_target'];
    echo "[SAMPLE] mission #{$sample['id']} | {$sample['goal_type']} ({$targetNote}) | {$sample['setting']} | {$sample['client']} | lang={$sample['lang']}\n";
}

if (!$apply) {
    echo "[BACKFILL] Dry-run complete. No database changes or API calls were made.\n";
    exit(0);
}

if (empty($jobs)) {
    echo "[BACKFILL] Nothing to update.\n";
    exit(0);
}

// Create pre-apply SQLite backup outside the webroot using SQLite online backup API
if ($backupFile === '') {
    $backupFile = rtrim(sys_get_temp_dir(), '/') . '/website_pre_lore_backfill_' . date('Ymd_His') . '.db';
}
$bkpDir = dirname($backupFile);
if (!is_dir($bkpDir)) {
    mkdir($bkpDir, 0700, true);
}
$bkpDb = new SQLite3($backupFile);
if (!$db->backup($bkpDb)) {
    fwrite(STDERR, "ERROR: Failed to create pre-apply SQLite backup at {$backupFile}\n");
    exit(1);
}
$bkpDb->close();
@chmod($backupFile, 0600);
echo "[BACKFILL] Pre-apply DB backup written: {$backupFile}\n";

$model = !empty($GEMINI_MODEL) ? $GEMINI_MODEL : 'gemini-2.5-flash';
$updated = 0;
$failed = 0;
$chunks = array_chunk($jobs, $workers);

foreach ($chunks as $chunkIdx => $chunk) {
    $batchRes = backfill_run_gemini_batch($chunk, $model, $GEMINI_API_KEY);

    $retryItems = [];
    foreach ($batchRes as $idx => $r) {
        if (!$r['ok']) {
            $retryItems[$idx] = $chunk[$idx];
        }
    }
    if (!empty($retryItems)) {
        usleep(400000);
        $retryRes = backfill_run_gemini_batch($retryItems, $model, $GEMINI_API_KEY);
        foreach ($retryRes as $idx => $rr) {
            $batchRes[$idx] = $rr;
        }
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        $upd = $db->prepare('UPDATE missions SET title = :t, description = :d, goal_target = :gt WHERE id = :id');
        foreach ($chunk as $idx => $job) {
            $r = $batchRes[$idx] ?? ['ok' => false, 'error' => 'missing result'];
            if ($r['ok']) {
                $upd->reset();
                $upd->bindValue(':t', $r['title'], SQLITE3_TEXT);
                $upd->bindValue(':d', $r['description'], SQLITE3_TEXT);
                $upd->bindValue(':gt', $job['new_target'], SQLITE3_TEXT);
                $upd->bindValue(':id', $job['id'], SQLITE3_INTEGER);
                $upd->execute()->finalize();
                $updated++;
            } else {
                $failed++;
                fwrite(STDERR, "[WARN] Skipped mission #{$job['id']}: {$r['error']}\n");
            }
        }
        $upd->close();
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        fwrite(STDERR, "[ERROR] Batch {$chunkIdx} DB commit failed: " . $e->getMessage() . "\n");
        $failed += count($chunk);
    }

    $done = min(($chunkIdx + 1) * $workers, count($jobs));
    echo "[BACKFILL] Progress: {$done}/" . count($jobs) . " (updated={$updated}, failed={$failed})\n";
    usleep(100000);
}

echo "[BACKFILL] Finished: updated={$updated}, failed={$failed}, backup={$backupFile}\n";
exit($failed > 0 && $updated === 0 ? 1 : 0);
