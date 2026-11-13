<?php
/**
 * Checks the bounty reward pools for every Section ID, class, level and area, and reports their
 * sizes and the 10x rule: php tools/bounty_data/pool_report.php [--json out.json]
 */
chdir(__DIR__ . '/../../api');
require 'functions.php';
require 'bounty_system.php';

$levels = [10, 30, 60, 90, 120, 160, 200];
$areas = [];
foreach (bounty_area_data()['settings'] as $k => $v) {
    $areas[$k] = bounty_reward_area($k, $v['episode']);
}
foreach ([1 => 9, 2 => 5] as $ep => $n) {
    $areas["challenge_ep{$ep}_full"] = bounty_challenge_reward_area($ep, 1, $n);
}
$m = (float) bounty_reward_cfg()['drop_rate_multiplier'];
$stats = [];
$violations = 0;
$checked = 0;
foreach (['rare', 'ultra', 'mythic', 'challenge_reserve'] as $tier) {
    foreach (range(0, 9) as $sec) {
        foreach (BOUNTY_CHAR_CLASSES as $ci => $cname) {
            foreach ($levels as $lv) {
                foreach ($areas as $ak => $area) {
                    if ($tier === 'challenge_reserve' && strpos($ak, 'challenge_') !== 0) continue;
                    if ($tier !== 'rare' && $ci > 0) continue; // Ultra, Mythic and the reserve ignore class
                    $pool = bounty_reward_pool($tier, ['section' => $sec, 'class' => $ci, 'level' => $lv, 'area' => $area]);
                    $ch = bounty_pool_chances($pool['items']);
                    foreach ($ch as $code => $p) {
                        $checked++;
                        if ($p + 1e-12 < $m * $pool['items'][$code]) $violations++;
                    }
                    $stats[$tier]['n'][] = count($ch);
                    $stats[$tier]['widened'][$pool['widened']] = ($stats[$tier]['widened'][$pool['widened']] ?? 0) + 1;
                    $stats[$tier]['dropped'] = ($stats[$tier]['dropped'] ?? 0) + (count($pool['items']) - count($ch));
                    foreach ($ch as $code => $p) $stats[$tier]['items'][$code] = true;
                }
            }
        }
    }
}
foreach ($stats as $tier => $s) {
    sort($s['n']);
    $cnt = count($s['n']);
    printf("%-18s pools %5d  size min %d / median %d / max %d  empty %d  distinct items %d  dropped for 10x %d\n", $tier, $cnt,
        $s['n'][0], $s['n'][intdiv($cnt, 2)], $s['n'][$cnt - 1], count(array_filter($s['n'], function ($x) { return $x === 0; })), count($s['items']), $s['dropped']);
    arsort($s['widened']);
    foreach ($s['widened'] as $w => $c) printf("    %-28s %5d\n", $w, $c);
}
printf("10x rule: %d item chances checked, %d below 10x their best per-kill rate\n", $checked, $violations);
