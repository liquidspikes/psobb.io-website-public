<?php
/**
 * Unit & integration tests for portal bounty flavor text and episode-qualified area naming.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/api/functions.php';

function assert_pbd_eq($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL [$label]: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assert_pbd_true(bool $cond, string $label): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL [$label]: condition was false\n");
        exit(1);
    }
}

// 1. PHP getClearObjective() checks
$patrolText = html_entity_decode(
    strip_tags(getClearObjective('PATROL', '2@4', 'Watching the Western Rim', 'Crater Rim patrol story')),
    ENT_QUOTES | ENT_HTML5,
    'UTF-8'
);
assert_pbd_eq('[Ep 4] Survive and patrol the Crater West for 10 minutes', $patrolText, 'PHP getClearObjective formats 2@4 PATROL');
assert_pbd_true(strpos($patrolText, '(on Hard or higher)') === false, 'PHP getClearObjective without min_difficulty omits difficulty suffix');

// Ensure api/my_bounties.php populates objective_text via getClearObjective without duplicating min_difficulty
$myBountiesSrc = (string)file_get_contents(dirname(__DIR__) . '/api/my_bounties.php');
assert_pbd_true(strpos($myBountiesSrc, "getClearObjective(\$row['goal_type'], \$row['goal_target'], \$row['title'] ?? '', \$row['description'] ?? '')") !== false, 'api/my_bounties.php populates objective_text via getClearObjective for all goal types');

// 2. JS describeBountyObjective + loadMyBounties tests executed via Node against actual source files
$nodeScript = <<<'JS'
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const repoRoot = process.argv[2];
const utilsSrc = fs.readFileSync(path.join(repoRoot, 'js/pso_utils.js'), 'utf8');
const mainSrc = fs.readFileSync(path.join(repoRoot, 'js/main.js'), 'utf8');

function extractBlock(src, startMarker) {
    const start = src.indexOf(startMarker);
    if (start === -1) throw new Error('Missing marker: ' + startMarker);
    let i = src.indexOf('{', start);
    let depth = 0;
    let inSingle = false, inDouble = false, inBacktick = false, inLineComment = false, inBlockComment = false, escape = false;
    for (; i < src.length; i++) {
        const c = src[i];
        const next = src[i + 1] || '';
        if (inLineComment) {
            if (c === '\n') inLineComment = false;
            continue;
        }
        if (inBlockComment) {
            if (c === '*' && next === '/') { inBlockComment = false; i++; }
            continue;
        }
        if (inSingle) {
            if (escape) { escape = false; continue; }
            if (c === '\\') { escape = true; continue; }
            if (c === "'") inSingle = false;
            continue;
        }
        if (inDouble) {
            if (escape) { escape = false; continue; }
            if (c === '\\') { escape = true; continue; }
            if (c === '"') inDouble = false;
            continue;
        }
        if (inBacktick) {
            if (escape) { escape = false; continue; }
            if (c === '\\') { escape = true; continue; }
            if (c === '`') inBacktick = false;
            continue;
        }
        if (c === '/' && next === '/') { inLineComment = true; i++; continue; }
        if (c === '/' && next === '*') { inBlockComment = true; i++; continue; }
        if (c === "'") { inSingle = true; continue; }
        if (c === '"') { inDouble = true; continue; }
        if (c === '`') { inBacktick = true; continue; }
        if (c === '{') depth++;
        else if (c === '}') {
            depth--;
            if (depth === 0) return src.slice(start, i + 1);
        }
    }
    throw new Error('Unterminated block for: ' + startMarker);
}

const describeSrc = extractBlock(mainSrc, 'function describeBountyObjective(goalType, goalTarget)');
const loadSrc = extractBlock(mainSrc, 'window.loadMyBounties = async function ()');

const elements = {};
for (const id of [
    'community-event-section', 'community-event-cards',
    'claimable-bounties-section', 'claimable-bounties-list',
    'active-bounties-section', 'active-bounties-list',
]) {
    elements[id] = { style: {}, innerHTML: '' };
}

const mockResponse = {
    success: true,
    community_events: [],
    claimable_events: [],
    bounties: [
        {
            player_mission_id: 31326,
            title: 'Watching the Western Rim',
            description: 'Principal Tyrell requires a patrol <script>alert(1)</script> along the Crater.',
            goal_type: 'PATROL',
            goal_target: '2@4',
            objective_text: '[Ep 4] Survive and patrol the Crater West for 10 minutes',
            min_difficulty_text: '(on Hard or higher)',
            character_name: 'Alakaboom',
            reward_decoded: 'Monofluid x4, 5500 Meseta',
            status: 'in_progress',
            tier_label: 'Common',
            kind: 'Daily',
            expires_in: 'Expires in 12h 19m',
        },
        {
            player_mission_id: 31327,
            title: 'Ready Bounty',
            description: 'Claimable lore line <tag>',
            goal_type: 'EXPLORATION',
            goal_target: '5@2',
            objective_text: '[Ep 2] Explore the Central Control Area',
            character_name: 'Alakaboom',
            reward_decoded: '10000 Meseta',
            status: 'ready_to_redeem',
        },
    ],
};

const ctx = {
    console,
    _t: (s) => s,
    escapeHtml: (s) => (s == null ? '' : String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')),
    window: { addEventListener: () => {} },
    document: {
        getElementById: (id) => elements[id] || null,
        addEventListener: () => {},
    },
    fetch: async () => ({ ok: true, json: async () => mockResponse }),
};
ctx.window.document = ctx.document;
vm.createContext(ctx);
vm.runInContext(utilsSrc, ctx);
vm.runInContext(describeSrc + ';\n' + loadSrc + ';', ctx);

assert.strictEqual(
    ctx.describeBountyObjective('PATROL', '2@4'),
    '[Ep 4] Survive and patrol the Crater West for 10 minutes',
    'PATROL 2@4 resolves Episode 4 Crater West'
);
assert.strictEqual(
    ctx.describeBountyObjective('EXPLORATION', '5@2'),
    '[Ep 2] Explore the Central Control Area',
    'EXPLORATION 5@2 resolves Episode 2 Central Control Area'
);
assert.ok(
    ctx.describeBountyObjective('SPEEDRUN_FLOOR', '8@1_676').includes('[Ep 1] Clear Ruins 1 in under 11m 16s'),
    'SPEEDRUN_FLOOR 8@1_676 resolves Episode 1 Ruins 1'
);
assert.ok(
    ctx.describeBountyObjective('BOSS_ARENA', '12@2').includes('Gal Gryphon'),
    'BOSS_ARENA 12@2 resolves Gal Gryphon'
);
assert.ok(
    ctx.describeBountyObjective('BOSS_ARENA', '9@4').includes('Saint-Milion'),
    'BOSS_ARENA 9@4 resolves Saint-Milion'
);

(async () => {
    await ctx.window.loadMyBounties();
    const activeHtml = elements['active-bounties-list'].innerHTML;
    const claimHtml = elements['claimable-bounties-list'].innerHTML;

    assert.ok(
        activeHtml.includes('Principal Tyrell requires a patrol &lt;script&gt;alert(1)&lt;/script&gt; along the Crater.'),
        'active card renders HTML-escaped description'
    );
    assert.ok(
        activeHtml.includes('[Ep 4] Survive and patrol the Crater West for 10 minutes (on Hard or higher)'),
        'active card shows [Ep 4] objective without [Challenge] prefix'
    );
    assert.ok(
        !activeHtml.includes('[Challenge] [Ep 4]'),
        'no stray [Challenge] prefix on Ep 4 objective_text'
    );
    assert.ok(
        claimHtml.includes('Claimable lore line &lt;tag&gt;') && claimHtml.includes('[Ep 2] Explore the Central Control Area'),
        'claimable card renders escaped description and readable objective'
    );
    console.log('NODE PORTAL BOUNTY DISPLAY TESTS PASSED');
})().catch((err) => {
    console.error(err);
    process.exit(1);
});
JS;

$tmpNode = sys_get_temp_dir() . '/test_portal_bounty_display_' . getmypid() . '.js';
file_put_contents($tmpNode, $nodeScript);
$repoRoot = dirname(__DIR__);
$out = [];
$rc = -1;
exec('node ' . escapeshellarg($tmpNode) . ' ' . escapeshellarg($repoRoot) . ' 2>&1', $out, $rc);
@unlink($tmpNode);
echo implode("\n", $out) . "\n";
assert_pbd_eq(0, $rc, 'Node portal bounty display test suite exits 0');
echo "ALL PORTAL BOUNTY DISPLAY TESTS PASSED\n";
