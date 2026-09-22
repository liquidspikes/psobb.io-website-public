<?php
/**
 * PSOBB.io - Agent Telemetry Receiver (telemetry_ingest.php)
 * 
 * Secure wrapper to accept the Python agent's JSON POST requests 
 * and stash them in a flat file for the public dashboard to consume.
 */

// 1. Define your secure webhook token here (Must match 'CHANGE_ME' in decrypter.py)
define('AGENT_SECRET', 'CHANGE_ME');

// 2. Setup Headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// 3. Authenticate the Python script's POST request
$headers = getallheaders();
$auth = isset($headers['Authorization']) ? $headers['Authorization'] : '';
if ($auth !== 'Bearer ' . AGENT_SECRET) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

// 4. Capture and validate the JSON payload
$json_data = file_get_contents("php://input");
$decoded = json_decode($json_data, true);

if (!$decoded) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON payload"]);
    exit;
}

function sanitize_model_name($name) {
    if (!$name) return 'Qwen 3.8 Flash Next (176B Distributed)';
    $clean = basename(str_replace('\\', '/', $name));
    $clean = preg_replace('/\.(gguf|bin)$/i', '', $clean);
    $clean = preg_replace('/-\d{5}-of-\d{5}$/i', '', $clean);
    if (stripos($clean, 'qwen3.8-flash-next') !== false || stripos($name, 'qwen3.8-flash-next') !== false) {
        return 'Qwen 3.8 Flash Next (176B Distributed)';
    }
    return $clean ?: 'Qwen 3.8 Flash Next (176B Distributed)';
}

// Sanitize paths from all incoming data
if (isset($decoded['model'])) {
    $decoded['model'] = sanitize_model_name($decoded['model']);
}
array_walk_recursive($decoded, function(&$val) {
    if (is_string($val)) {
        $val = str_ireplace('/home/alexzimmerman/models/qwen3.8-flash-next/', '', $val);
        $val = str_ireplace('/home/alexzimmerman/', '', $val);
    }
});

// 5. Setup Exclusive Concurrency Lock to prevent race conditions
$lock_file = __DIR__ . '/agent_state.lock';
$lock_fp = fopen($lock_file, 'c+');
if (!$lock_fp) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Could not open lock file"]);
    exit;
}

// Acquire exclusive write lock (blocks until lock is free)
flock($lock_fp, LOCK_EX);

$target_file = __DIR__ . '/agent_state.json';
$temp_file = $target_file . '.tmp';

$all_states = [];
if (file_exists($target_file)) {
    $existing = json_decode(file_get_contents($target_file), true);
    if ($existing && isset($existing['agents'])) {
        $all_states = $existing['agents'];
    } elseif ($existing) {
        $all_states['1'] = $existing; // Legacy migration
    }
}

$agent_id = isset($decoded['agent_id']) ? $decoded['agent_id'] : '1';
$all_states[$agent_id] = $decoded;

// Remove any agent data older than 2 minutes (they probably finished/died)
foreach ($all_states as $id => $state) {
    if (isset($state['_last_update']) && (time() - $state['_last_update'] > 120)) {
        unset($all_states[$id]);
    }
}
$all_states[$agent_id]['_last_update'] = time();

// Merge logic to create a unified state for the frontend
$master_state = [];
if (isset($all_states['master'])) {
    $master_state = $all_states['master'];
} else {
    foreach ($all_states as $id => $st) {
        if ($id !== 'cluster') {
            $master_state = $st;
            break;
        }
    }
}
if (empty($master_state)) {
    $master_state = $decoded;
}

$swarm_count = 0;
foreach ($all_states as $id => $s) {
    if ($id !== 'master' && $id !== 'cluster') {
        $swarm_count++;
    }
}

// Check if any agent or master is in revalidation mode
$is_revalidating = false;
foreach ($all_states as $id => $s) {
    if ((isset($s['mode']) && $s['mode'] === 'revalidate') || 
        (isset($s['status']) && stripos($s['status'], 'revalidat') !== false)) {
        $is_revalidating = true;
        break;
    }
}

$default_status = $swarm_count > 0 
    ? ($is_revalidating ? 'Revalidation Swarm Active (' . $swarm_count . ' agents)' : 'Swarm Active (' . $swarm_count . ' agents)') 
    : ($is_revalidating ? 'Revalidation Commander Active' : 'Strategic Commander Active');

if (isset($master_state['status']) && stripos($master_state['status'], 'revalidat') !== false) {
    $default_status = $master_state['status'];
}

$merged = [
    'agents' => $all_states,
    'mode' => $is_revalidating ? 'revalidate' : 'discovery',
    'batch_num' => 0,
    'status' => $default_status,
    'model' => isset($master_state['model']) ? $master_state['model'] : '',
    'active_tools' => [],
    'terminal_feed' => [],
    'modifications' => [],
    'unknown_fns' => isset($master_state['unknown_fns']) ? $master_state['unknown_fns'] : '0',
    'unknown_thunks' => isset($master_state['unknown_thunks']) ? $master_state['unknown_thunks'] : '0',
    'unknown_dat' => isset($master_state['unknown_dat']) ? $master_state['unknown_dat'] : '0',
    'unknown_ptr' => isset($master_state['unknown_ptr']) ? $master_state['unknown_ptr'] : '0',
    'unknown_floats' => isset($master_state['unknown_floats']) ? $master_state['unknown_floats'] : '0',
    'unknown_strings' => isset($master_state['unknown_strings']) ? $master_state['unknown_strings'] : '0',
    'unknown_vtables' => isset($master_state['unknown_vtables']) ? $master_state['unknown_vtables'] : '0',
    'unknown_vars' => isset($master_state['unknown_vars']) ? $master_state['unknown_vars'] : '0',
    'total_fns' => isset($master_state['total_fns']) ? $master_state['total_fns'] : '0',
    'total_vars' => isset($master_state['total_vars']) ? $master_state['total_vars'] : '0',
    'total_mods_all_time' => 0,
    'total_tokens' => 0,
    'tps' => 0,
    'eta' => isset($master_state['eta']) ? $master_state['eta'] : 'Calculating...',
    'pipeline_phase' => isset($master_state['pipeline_phase']) ? (int)$master_state['pipeline_phase'] : 1,
    'recompiler_status' => isset($master_state['recompiler_status']) ? $master_state['recompiler_status'] : 'Standby',
    'recompiler_attempts' => isset($master_state['recompiler_attempts']) ? (int)$master_state['recompiler_attempts'] : 0,
    'compile_errors' => isset($master_state['compile_errors']) ? (int)$master_state['compile_errors'] : 0,
    'last_build_output' => isset($master_state['last_build_output']) ? $master_state['last_build_output'] : '',
    'banked_fns' => isset($master_state['banked_fns']) ? $master_state['banked_fns'] : (isset($decoded['banked_fns']) ? $decoded['banked_fns'] : 171),
    'total_belt_fns' => isset($master_state['total_belt_fns']) ? $master_state['total_belt_fns'] : (isset($decoded['total_belt_fns']) ? $decoded['total_belt_fns'] : 2684),
    'promotable_fns' => isset($master_state['promotable_fns']) ? $master_state['promotable_fns'] : (isset($decoded['promotable_fns']) ? $decoded['promotable_fns'] : 108),
    'extracted_files' => isset($master_state['extracted_files']) ? (int)$master_state['extracted_files'] : 0,
    'current_target' => isset($master_state['current_target']) ? $master_state['current_target'] : (isset($decoded['current_target']) ? $decoded['current_target'] : null),
    'cluster' => isset($master_state['cluster']) ? $master_state['cluster'] : (isset($decoded['cluster']) ? $decoded['cluster'] : null)
];

foreach ($all_states as $id => $state) {
    if (isset($state['cluster']) && !empty($state['cluster'])) {
        $merged['cluster'] = $state['cluster'];
    }
    if (isset($state['current_target']) && !empty($state['current_target'])) {
        $merged['current_target'] = $state['current_target'];
    }
    $merged['total_mods_all_time'] += isset($state['total_mods_all_time']) ? (int)$state['total_mods_all_time'] : 0;
    $merged['total_tokens'] += isset($state['total_tokens']) ? (int)$state['total_tokens'] : 0;
    
    // Only aggregate TPS from active worker nodes
    if ($id !== 'master') {
        $merged['tps'] += isset($state['tps']) ? (float)$state['tps'] : 0;
    }
    
    $merged['batch_num'] = max($merged['batch_num'], isset($state['batch_num']) ? (int)$state['batch_num'] : 0);
    
    if (isset($state['active_tools']) && is_array($state['active_tools'])) {
        $merged['active_tools'] = array_unique(array_merge($merged['active_tools'], $state['active_tools']));
    }
    
    if (isset($state['terminal_feed']) && is_array($state['terminal_feed'])) {
        foreach ($state['terminal_feed'] as $feed) {
            $feed['agent_id'] = $id;
            $feed['content'] = "[Agent $id] " . $feed['content'];
            $merged['terminal_feed'][] = $feed;
        }
    }
    
    if (isset($state['modifications']) && is_array($state['modifications'])) {
        foreach ($state['modifications'] as $mod) {
            $action = isset($mod['action']) ? $mod['action'] : '';
            $details = isset($mod['details']) ? $mod['details'] : '';
            $ts = isset($mod['timestamp']) ? $mod['timestamp'] : '';
            $raw_action = trim(preg_replace('/^\[Agent [^\]]+\]\s*/', '', $action));
            $agent_tag = ($id === 'master' ? '[Agent master]' : "[Agent $id]");
            
            $addr = isset($mod['address']) ? $mod['address'] : '';
            $old_name = isset($mod['old_name']) ? $mod['old_name'] : '';
            $new_name = isset($mod['new_name']) ? $mod['new_name'] : '';
            $prototype = isset($mod['prototype']) ? $mod['prototype'] : '';
            $description = isset($mod['description']) ? $mod['description'] : '';

            // Fallback string extraction if missing
            if (empty($addr) && preg_match("/'(?:function_)?address':\s*'([^']+)'/", $details, $m)) {
                $addr = $m[1];
            }
            if (empty($new_name) && preg_match("/'(?:new_name|name)':\s*'([^']+)'/", $details, $m)) {
                $new_name = $m[1];
            }
            if (empty($old_name) && preg_match("/'old_name':\s*'([^']+)'/", $details, $m)) {
                $old_name = $m[1];
            }
            if (empty($prototype) && preg_match("/'prototype':\s*'([^']+)'/", $details, $m)) {
                $prototype = $m[1];
            }
            if (empty($prototype) && preg_match("/'type_name':\s*'([^']+)'/", $details, $m)) {
                $prototype = $m[1];
            }

            $dedup_key = $ts . '|' . $raw_action . '|' . $details;
            if (!isset($unique_mods[$dedup_key]) || $id !== 'master') {
                $unique_mods[$dedup_key] = [
                    'timestamp' => $ts,
                    'action' => $agent_tag . ' ' . $raw_action,
                    'raw_action' => $raw_action,
                    'details' => $details,
                    'address' => $addr,
                    'old_name' => $old_name,
                    'new_name' => $new_name,
                    'prototype' => $prototype,
                    'description' => $description,
                    'agent_id' => $id
                ];
            }
        }
    }
}

$merged['modifications'] = array_values(isset($unique_mods) ? $unique_mods : []);
usort($merged['terminal_feed'], function($a, $b) {
    return strtotime($a['timestamp']) - strtotime($b['timestamp']);
});
$merged['terminal_feed'] = array_slice($merged['terminal_feed'], -100);

usort($merged['modifications'], function($a, $b) {
    return strtotime($b['timestamp']) - strtotime($a['timestamp']);
});
$merged['modifications'] = array_slice($merged['modifications'], 0, 100);

$merged['tps'] = sprintf("%.1f", $merged['tps']);

file_put_contents($temp_file, json_encode($merged));
rename($temp_file, $target_file);

// 6. Release lock
flock($lock_fp, LOCK_UN);
fclose($lock_fp);

// 7. Return success
echo json_encode(["status" => "success", "message" => "Telemetry synchronized and aggregated."]);
?>
