<?php
/**
 * Admin: Item Search
 * Searches canonical PSOBB V4 names-v4.json by name and returns matching items with their hex codes.
 * Used by the special deliveries admin page to look up item IDs.
 */
require_once 'config.php';
start_secure_session();
header('Content-Type: application/json');

if (empty($_SESSION['user']) || empty($_SESSION['user']['is_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$q = strtolower(trim($_GET['q'] ?? ''));
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$path = __DIR__ . '/names-v4.json';
if (!file_exists($path)) {
    http_response_code(500);
    echo json_encode(['error' => 'names-v4.json not found']);
    exit;
}

$namesMap = json_decode((string)file_get_contents($path), true);
if (!is_array($namesMap)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to parse names-v4.json']);
    exit;
}

$results = [];
$limit   = 20;

foreach ($namesMap as $rawCode => $rawName) {
    $code = strtoupper(trim((string)$rawCode));
    $name = trim((string)$rawName);

    // Skip unnamed, placeholder, or tech-disk template entries
    if (
        $name === '' ||
        $name === '????' ||
        $name === '<TECH-DISK>' ||
        str_starts_with($name, '!ID:')
    ) {
        continue;
    }

    if (str_contains(strtolower($name), $q)) {
        // Determine item category from first two bytes of code
        $byte0 = hexdec(substr($code, 0, 2));
        $byte1 = hexdec(substr($code, 2, 2));

        if ($byte0 === 0x00) {
            $cat = 'Weapon';
        } elseif ($byte0 === 0x01 && $byte1 === 0x01) {
            $cat = 'Armor';
        } elseif ($byte0 === 0x01 && $byte1 === 0x02) {
            $cat = 'Shield';
        } elseif ($byte0 === 0x01 && $byte1 === 0x03) {
            $cat = 'Unit';
        } elseif ($byte0 === 0x02) {
            $cat = 'Mag';
        } elseif ($byte0 === 0x03 && $byte1 === 0x02) {
            $cat = 'Disk';
        } elseif ($byte0 === 0x03) {
            $cat = 'Tool';
        } elseif ($byte0 === 0x04) {
            $cat = 'Meseta';
        } else {
            $cat = 'Tool';
        }

        $results[] = ['code' => $code, 'name' => $name, 'cat' => $cat];
    }
}

// Sort exact matches first, then starts-with, then ascending hex code, and slice to limit
usort($results, function ($a, $b) use ($q) {
    $aLower = strtolower($a['name']);
    $bLower = strtolower($b['name']);
    $aExact = $aLower === $q ? 0 : 1;
    $bExact = $bLower === $q ? 0 : 1;
    if ($aExact !== $bExact) return $aExact - $bExact;
    $aStart = str_starts_with($aLower, $q) ? 0 : 1;
    $bStart = str_starts_with($bLower, $q) ? 0 : 1;
    if ($aStart !== $bStart) return $aStart - $bStart;
    return strcmp($a['code'], $b['code']);
});

echo json_encode(array_slice(array_values($results), 0, $limit));
