<?php
require_once 'config.php';
header('Content-Type: application/json');

$rates = get_server_rates();

$url = $NEWSERV_API_URL . "/y/server";
$data_json = @file_get_contents($url);

if ($data_json === FALSE) {
    echo json_encode([
        'ServerName' => 'Offline',
        'Uptime' => 'Offline',
        'ClientCount' => 'Offline',
        'GameCount' => 'Offline',
        'BBGlobalEXPMultiplier' => $rates['raw_exp'],
        'ServerGlobalDropRateMultiplier' => $rates['raw_drop'],
        'EXP' => (string)$rates['raw_exp'],
        'Drop' => (string)$rates['raw_drop']
    ]);
} else {
    $data = json_decode($data_json, true);
    $data["BBGlobalEXPMultiplier"] = $rates['raw_exp'];
    $data["ServerGlobalDropRateMultiplier"] = $rates['raw_drop'];
    $data["EXP"] = (string)$rates['raw_exp'];
    $data["Drop"] = (string)$rates['raw_drop'];
    echo json_encode($data);
}
?>
