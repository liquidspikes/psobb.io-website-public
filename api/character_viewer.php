<?php
/**
 * PSOBB API: Character & Bank Viewer
 * 
 * Fetches and parses a player's binary character (.psochar) and bank (.psobank) files
 * directly from the game server's player database folder.
 * Gracefully overlays live online stats if the character is active in-game.
 * Falls back to high-fidelity, lore-friendly Mock data if no files exist locally.
 */
require_once __DIR__ . '/config.php';

if (ob_get_length()) ob_clean();
start_secure_session();
header('Content-Type: application/json');

// 1. Verify User Login
if (empty($_SESSION['user']) || empty($_SESSION['user']['account_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$accountId = (int)$_SESSION['user']['account_id'];
$username = '';

// Retrieve BB username from Session user data
if (isset($_SESSION['user']['BBLicenses']) && is_array($_SESSION['user']['BBLicenses']) && count($_SESSION['user']['BBLicenses']) > 0) {
    $username = $_SESSION['user']['BBLicenses'][0]['UserName'] ?? '';
}

// Fallback search in session variables if username is not directly set
if (empty($username)) {
    $username = $_SESSION['user']['LastPlayerName'] ?? $_SESSION['user']['username'] ?? '';
}

$username = strtolower(trim($username));
$slot = isset($_GET['slot']) ? clamp((int)$_GET['slot'], 0, 19) : 0;

// Path definition to players folder
$playersDir = get_newserv_players_dir();

$psocharPath = resolve_player_file($playersDir, "player_{$username}_{$slot}.psochar");

// Helper to parse binary ItemData (20 bytes)
function parse_item_data($bytes) {
    return pso_parse_item_data((string)$bytes);
}

// 2. Fetch File Contents OR fallback to Mock
if (file_exists($psocharPath)) {
    // Parsing real binary player profile
    $charData = @file_get_contents($psocharPath);
    if ($charData === false || strlen($charData) < 0x399C) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to read binary character file."]);
        exit;
    }
    
    // Inventory starts at offset 8 (size 844)
    $invBlock = substr($charData, 8, 844);
    $numItems = ord($invBlock[0]);
    $hpMats = ord($invBlock[1]) >> 1;
    $tpMats = ord($invBlock[2]) >> 1;
    
    $inventory = [];
    for ($i = 0; $i < 30; $i++) {
        $offset = 4 + $i * 28;
        $present = ord($invBlock[$offset]);
        if ($present) {
            $flags = unpack('V', substr($invBlock, $offset + 4, 4))[1];
            $itemBytes = substr($invBlock, $offset + 8, 20);
            
            $item = parse_item_data($itemBytes);
            if ($item) {
                $item['equipped'] = ($flags & 8) !== 0;
                $inventory[] = $item;
            }
        }
    }
    
    // Display data (stats + visual + name) starts at offset 852 (size 400)
    $dispBlock = substr($charData, 852, 400);
    
    // Stats Block
    $atp = unpack('v', substr($dispBlock, 0, 2))[1];
    $mst = unpack('v', substr($dispBlock, 2, 2))[1];
    $evp = unpack('v', substr($dispBlock, 4, 2))[1];
    $hp = unpack('v', substr($dispBlock, 6, 2))[1];
    $dfp = unpack('v', substr($dispBlock, 8, 2))[1];
    $ata = unpack('v', substr($dispBlock, 10, 2))[1];
    $lck = unpack('v', substr($dispBlock, 12, 2))[1];
    
    $lvl = unpack('V', substr($dispBlock, 24, 4))[1];
    $exp = unpack('V', substr($dispBlock, 28, 4))[1];
    $meseta = unpack('V', substr($dispBlock, 32, 4))[1];
    
    // Visual Block
    $sectionIdVal = ord($dispBlock[36 + 0x30]);
    $charClassVal = ord($dispBlock[36 + 0x31]);
    
    $charClass = PSO_CHAR_CLASSES[$charClassVal] ?? 'HUmar';
    $sectionId = PSO_SECTION_IDS[$sectionIdVal] ?? 'Viridia';
    
    // Decode UTF16_ALWAYS_MARKED name at offset 0x3C8 (strips \tE/\tJ/\tB/\tT/\tK, preserves \tC color codes)
    $charName = decode_psochar_name($charData);
    
    // Material stats from extensions striped across items
    $powerMats = ord($invBlock[4 + 8 * 28 + 3]);
    $mindMats = ord($invBlock[4 + 9 * 28 + 3]);
    $evadeMats = ord($invBlock[4 + 10 * 28 + 3]);
    $defMats = ord($invBlock[4 + 11 * 28 + 3]);
    $luckMats = ord($invBlock[4 + 12 * 28 + 3]);
    
    // Play time
    $playTime = unpack('V', substr($charData, 8 + 0x04E8, 4))[1];
    
    // Parse Bank
    $bankItems = [];
    $bankMeseta = 0;
    
    // Try to load dedicated .psobank file first, then fall back to embedded bank
    $psobankPath = resolve_player_file($playersDir, "player_{$username}_{$slot}.psobank");
    $bankData = false;
    if (file_exists($psobankPath)) {
        $bankData = @file_get_contents($psobankPath);
    }
    
    if ($bankData === false) {
        // Embedded bank inside .psochar starts at offset 8 + 1784 = 1792 (size 4808)
        $bankBlock = substr($charData, 1792, 4808);
    } else {
        // Raw .psobank file starting at offset 0 (same structural format)
        $bankBlock = $bankData;
    }
    
    if (strlen($bankBlock) >= 8) {
        $bankNumItems = unpack('V', substr($bankBlock, 0, 4))[1];
        $bankMeseta = unpack('V', substr($bankBlock, 4, 4))[1];
        
        for ($i = 0; $i < min(1000, $bankNumItems); $i++) {
            $offset = 8 + $i * 24;
            if ($offset + 24 <= strlen($bankBlock)) {
                $itemBytes = substr($bankBlock, $offset, 20);
                $amount = unpack('v', substr($bankBlock, $offset + 20, 2))[1];
                
                $item = parse_item_data($itemBytes);
                if ($item && $item['group'] <= 0x04) {
                    if ($item['group'] === 0x03 && $item['name'] !== 'Disk') {
                        $item['count'] = $amount;
                    }
                    $bankItems[] = $item;
                }
            }
        }
    }
    
    // Shared Bank parsing
    $sharedBankItems = [];
    $sharedBankMeseta = 0;
    $sharedBankPath = resolve_player_file($playersDir, "shared_bank_{$username}.psobank");
    if (file_exists($sharedBankPath)) {
        $sharedData = @file_get_contents($sharedBankPath);
        if ($sharedData && strlen($sharedData) >= 8) {
            $shNumItems = unpack('V', substr($sharedData, 0, 4))[1];
            $sharedBankMeseta = unpack('V', substr($sharedData, 4, 4))[1];
            for ($i = 0; $i < min(1000, $shNumItems); $i++) {
                $offset = 8 + $i * 24;
                if ($offset + 24 <= strlen($sharedData)) {
                    $itemBytes = substr($sharedData, $offset, 20);
                    $amount = unpack('v', substr($sharedData, $offset + 20, 2))[1];
                    
                    $item = parse_item_data($itemBytes);
                    if ($item && $item['group'] <= 0x04) {
                        if ($item['group'] === 0x03 && $item['name'] !== 'Disk') {
                            $item['count'] = $amount;
                        }
                        $sharedBankItems[] = $item;
                    }
                }
            }
        }
    }
    
    $character = [
        'name' => $charName,
        'class' => $charClass,
        'level' => $lvl + 1,
        'section_id' => $sectionId,
        'experience' => $exp,
        'play_time_hours' => round($playTime / 3600, 1),
        'stats' => [
            'ATP' => $atp, 'MST' => $mst, 'EVP' => $evp, 'HP' => $hp, 
            'DFP' => $dfp, 'ATA' => $ata, 'LCK' => $lck, 'Meseta' => $meseta
        ],
        'mats' => [
            'HP' => $hpMats, 'TP' => $tpMats, 'Power' => $powerMats, 
            'Mind' => $mindMats, 'Evade' => $evadeMats, 'Def' => $defMats, 'Luck' => $luckMats
        ],
        'inventory' => $inventory,
        'bank' => [
            'items' => $bankItems,
            'meseta' => $bankMeseta
        ],
        'shared_bank' => [
            'items' => $sharedBankItems,
            'meseta' => $sharedBankMeseta
        ]
    ];
    
    // Check if character is online to dynamically merge stats from live server memory
    $clients = newserv_fetch_clients();
    $accountOnline = false;
    $onlineCharName = '';
    if ($clients !== null) {
        $c = newserv_find_online_client($clients, (int) $accountId);
        if ($c !== null) {
            $accountOnline = true;
            // newserv's decode() already strips the \t+language prefix
            $onlineCharName = $c['Name'] ?? '';

            if ($onlineCharName === $charName) {
                $character['online'] = true;
                $character['lobby_id'] = $c['LobbyID'] ?? null;

                // Pull material counts from the internal server API
                $character['mats']['HP'] = (int)($c['NumHPMaterialsUsed'] ?? $character['mats']['HP']);
                $character['mats']['TP'] = (int)($c['NumTPMaterialsUsed'] ?? $character['mats']['TP']);
                $character['mats']['Power'] = (int)($c['NumPowerMaterialsUsed'] ?? $character['mats']['Power']);
                $character['mats']['Def'] = (int)($c['NumDefMaterialsUsed'] ?? $character['mats']['Def']);
                $character['mats']['Mind'] = (int)($c['NumMindMaterialsUsed'] ?? $character['mats']['Mind']);
                $character['mats']['Evade'] = (int)($c['NumEvadeMaterialsUsed'] ?? $character['mats']['Evade']);
                $character['mats']['Luck'] = (int)($c['NumLuckMaterialsUsed'] ?? $character['mats']['Luck']);

                // Live stats directly from newserv memory
                if (isset($c['ATP'])) $character['stats']['ATP'] = (int)$c['ATP'];
                if (isset($c['DFP'])) $character['stats']['DFP'] = (int)$c['DFP'];
                if (isset($c['MST'])) $character['stats']['MST'] = (int)$c['MST'];
                if (isset($c['ATA'])) $character['stats']['ATA'] = (int)$c['ATA'];
                if (isset($c['EVP'])) $character['stats']['EVP'] = (int)$c['EVP'];
                if (isset($c['LCK'])) $character['stats']['LCK'] = (int)$c['LCK'];
                if (isset($c['HP'])) $character['stats']['HP'] = (int)$c['HP'];
                if (isset($c['Meseta'])) $character['stats']['Meseta'] = (int)$c['Meseta'];
                if (isset($c['Level'])) $character['level'] = (int)$c['Level'];
                if (isset($c['SectionID'])) $character['section_id'] = $c['SectionID'];
                if (isset($c['CharClass'])) $character['class'] = $c['CharClass'];

                // Direct override of inventory items with memory allocation mapping
                if (isset($c['InventoryItems']) && is_array($c['InventoryItems'])) {
                    $liveInv = [];
                    foreach ($c['InventoryItems'] as $item) {
                        $desc = $item['Description'] ?? '';
                        $data = $item['Data'] ?? '';
                        $itemId = $item['ItemID'] ?? 0;
                        $flags = $item['Flags'] ?? 0;

                        $bin = hex2bin(preg_replace('/[^a-fA-F0-9]/', '', $data));
                        $parsed = parse_item_data($bin);
                        if ($parsed) {
                            $parsed['item_id'] = $itemId;
                            $parsed['equipped'] = ($flags & 8) !== 0;
                            if (!empty($desc)) $parsed['name'] = $desc;
                            $liveInv[] = $parsed;
                        }
                    }
                    $character['inventory'] = $liveInv;
                }
            }
        }
    }

    require_once __DIR__ . '/psochar_stats.php';
    finalize_psobb_character_data($character);

    echo json_encode([
        'success' => true, 
        'character' => $character, 
        'mock' => false,
        'account_online' => $accountOnline,
        'online_char_name' => $onlineCharName
    ]);
    exit;
} else {
    http_response_code(404);
    echo json_encode(["success" => false, "error" => "Character file for Slot " . ($slot + 1) . " does not exist. Please log in-game and create a character first!"]);
    exit;
}
?>
