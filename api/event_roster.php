<?php
/**
 * Shared 6-Month Curated highly scaled month-long community boss rush events roster.
 */

$roster = [
    1 => [
        'title' => "Operation: Digital Blasphemy",
        'description' => "Corrupted Pioneer 1 control units and VR combat simulations are escalating across three fronts. Principal Tyrell's office and the Lab request Hunter strikes against Vol Opt in the Mines (+1 pt), Gol Dragon in the VR Spaceship (+2 pts), or Shambertin beneath the Subterranean Desert (+3 pts), with difficulty bonuses (+1 Hard, +2 VHard, +3 Ult), for 3 level-fit rare drops!",
        'goal_type' => "BOSS_ARENA",
        'goal_target' => "DIGITAL_BLASPHEMY",
        'target_amount' => 75000,
        'reward_item_string' => "3x Random Rare Drops",
        'top_3_reward_item_string' => "Heart of Chao | Cell of MAG 502 | Amitie's Memo"
    ],
    2 => [
        'title' => "Operation: Ancient Cataclysm",
        'description' => "Aggressive native creatures and erratic machines beneath the Central Dome are blocking Pioneer 2 survey teams across Episode I. Take any contract down in the Forest, Caves, Mines, or Ruins: Dragon (+1 pt), De Rol Le (+2 pts), Vol Opt (+3 pts), or Dark Falz (+4 pts), plus difficulty bonuses (+1 Hard, +2 VHard, +3 Ult)!",
        'goal_type' => "BOSS_ARENA",
        'goal_target' => "EP1_BOSS_RUSH",
        'target_amount' => 90000,
        'reward_item_string' => "3x Random Rare Drops",
        'top_3_reward_item_string' => "Kit of DREAMCAST | Kit of SEGA SATURN | Kit of GENESIS"
    ],
    3 => [
        'title' => "Operation: Seabed Infestation",
        'description' => "Natasha Milarose and the Pioneer 2 Laboratory need veteran Hunters to clear mutated bio-specimens and malfunctioning VR trainers across Episode II. Hunt Barba Ray in the VR Temple (+1 pt), Gol Dragon in the VR Spaceship (+2 pts), Gal Gryphon on Gal Da Val (+3 pts), or Olga Flow in the Seabed depths (+4 pts), plus difficulty bonuses (+1 Hard, +2 VHard, +3 Ult)!",
        'goal_type' => "BOSS_ARENA",
        'goal_target' => "EP2_BOSS_RUSH",
        'target_amount' => 75000,
        'reward_item_string' => "3x Random Rare Drops",
        'top_3_reward_item_string' => "Heart of Opa Opa | Parts of RoboChao | Heart of Angel"
    ],
    4 => [
        'title' => "Operation: Draconic Dominion",
        'description' => "Dragon-class threats and giant subterranean serpents are nesting across Pioneer 1 ruins and VR arenas alike. Guild command calls on Hunters to fell the Dragon under the Central Dome (+1 pt), Gol Dragon inside the Lab's VR Spaceship (+2 pts), or Shambertin in the Subterranean Desert (+3 pts), plus difficulty bonuses (+1 Hard, +2 VHard, +3 Ult)!",
        'goal_type' => "BOSS_ARENA",
        'goal_target' => "DRACONIC_DOMINION",
        'target_amount' => 80000,
        'reward_item_string' => "3x Random Rare Drops",
        'top_3_reward_item_string' => "Dragon Scale | Heart of Devil | Heart of Morolian"
    ],
    5 => [
        'title' => "Operation: The Ultimate Gauntlet",
        'description' => "The Hunter's Guild has issued an all-sectors extermination order spanning Ragol's surface and the Lab's VR fields. Defeat ANY major boss across Episodes I, II, or IV (Dragon/Barba Ray +1, De Rol Le/Gol Dragon +2, Vol Opt/Gal Gryphon +3, Dark Falz/Olga Flow/Shambertin +4) plus difficulty bonuses (+1 Hard, +2 VHard, +3 Ult)!",
        'goal_type' => "BOSS_ARENA",
        'goal_target' => "ALL_BOSSES",
        'target_amount' => 150000,
        'reward_item_string' => "3x Random Rare Drops",
        'top_3_reward_item_string' => "Kit of MASTER SYSTEM | Kit of MARK3 | Kit of Hamburger"
    ],
    6 => [
        'title' => "Operation: Cataclysmic Core",
        'description' => "Hostile photon activity has peaked inside the deepest containment zones across Pioneer 1's ruins, the Seabed research floor, and the desert caverns beneath the Crater. Coordinate strikes against Dark Falz in Ep1 (+3 pts), Olga Flow in Ep2 (+4 pts), or Shambertin in Ep4 (+4 pts), plus difficulty bonuses (+1 Hard, +2 VHard, +3 Ult)!",
        'goal_type' => "BOSS_ARENA",
        'goal_target' => "CATACLYSMIC_CORE",
        'target_amount' => 50000,
        'reward_item_string' => "3x Random Rare Drops",
        'top_3_reward_item_string' => "Heart of Chao | Cell of MAG 502 | Amitie's Memo"
    ]
];

function launch_event($db, $roster, $index) {
    if (!isset($roster[$index])) {
        if (php_sapi_name() === 'cli') {
            echo "[ERROR] Invalid event index: $index\n";
        }
        return false;
    }
    
    $event = $roster[$index];
    
    try {
        // Deactivate active events
        $db->exec("UPDATE community_events SET status = 'inactive' WHERE status = 'active'");
        
        // Insert new active event
        $stmt = $db->prepare("INSERT INTO community_events (title, description, goal_type, goal_target, target_amount, reward_item_string, top_3_reward_item_string, status) 
                              VALUES (:t, :d, :gt, :gta, :amt, :ri, :top3, 'active')");
        $stmt->bindValue(':t', $event['title'], SQLITE3_TEXT);
        $stmt->bindValue(':d', $event['description'], SQLITE3_TEXT);
        $stmt->bindValue(':gt', $event['goal_type'], SQLITE3_TEXT);
        $stmt->bindValue(':gta', $event['goal_target'], SQLITE3_TEXT);
        $stmt->bindValue(':amt', $event['target_amount'], SQLITE3_INTEGER);
        $stmt->bindValue(':ri', $event['reward_item_string'], SQLITE3_TEXT);
        $stmt->bindValue(':top3', $event['top_3_reward_item_string'], SQLITE3_TEXT);
        
        if ($stmt->execute()) {
            if (php_sapi_name() === 'cli') {
                echo "\n[SUCCESS] Launched Event #$index: " . $event['title'] . "\n";
                echo "Description: " . $event['description'] . "\n\n";
            }
            return true;
        }
    } catch (Exception $e) {
        if (php_sapi_name() === 'cli') {
            echo "[ERROR] Failed to launch event: " . $e->getMessage() . "\n";
        }
    }
    return false;
}
?>
