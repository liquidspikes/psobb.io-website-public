<?php
require_once 'config.php';
header('Content-Type: application/json');

$rates = get_server_rates();

$url = $NEWSERV_API_URL . "/y/summary";
$data_json = @file_get_contents($url);

if ($data_json === FALSE) {
    echo json_encode([
        "Server" => [
            "ServerName" => "Offline",
            "Uptime" => "Offline",
            "ClientCount" => "Offline",
            "GameCount" => "Offline",
            "BBGlobalEXPMultiplier" => $rates['raw_exp'],
            "ServerGlobalDropRateMultiplier" => $rates['raw_drop'],
            "EXP" => (string)$rates['raw_exp'],
            "Drop" => (string)$rates['raw_drop']
        ],
        "Clients" => [],
        "Games" => []
    ]);
} else {
    $data = json_decode($data_json, true);
    if (isset($data["Server"])) {
        $data["Server"]["BBGlobalEXPMultiplier"] = $rates['raw_exp'];
        $data["Server"]["ServerGlobalDropRateMultiplier"] = $rates['raw_drop'];
        $data["Server"]["EXP"] = (string)$rates['raw_exp'];
        $data["Server"]["Drop"] = (string)$rates['raw_drop'];
    }

    // Extract Mode and Strip Newserv mode prefix from Game Names (E=Normal, B=Battle, C=Challenge)
    if (isset($data['Games']) && is_array($data['Games'])) {
        foreach ($data['Games'] as &$game) {
            if (isset($game['Name']) && is_string($game['Name']) && strlen($game['Name']) > 0) {
                $cleanName = trim($game['Name']);
                if (strlen($cleanName) > 0) {
                    $modeChar = strtoupper($cleanName[0]);
                    if (in_array($modeChar, ['E', 'B', 'C'])) {
                        $game['Name'] = trim(substr($cleanName, 1));
                        if ($modeChar === 'B') {
                            $game['Mode'] = 'Battle';
                        } elseif ($modeChar === 'C') {
                            $game['Mode'] = 'Challenge';
                        } else {
                            $game['Mode'] = 'Normal';
                        }
                    } else {
                        $game['Mode'] = 'Normal'; // Fallback for games without known prefix
                    }
                } else {
                    $game['Mode'] = 'Normal'; // Fallback
                }
            } else {
                $game['Mode'] = 'Normal'; // Fallback for empty names
            }
        }
    }

    echo json_encode($data);
}
?>
