<?php
/**
 * PSOBB Streak Expiration Alert
 * Run this every day at 11:00 PM (Server Time)
 */
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/db.php";

if (php_sapi_name() !== "cli") exit;

$db = get_db();
$today = date("Y-m-d");
$yesterday = date("Y-m-d", strtotime("-1 day"));

$botToken = !empty($BOT_TOKEN) ? $BOT_TOKEN : '';
if (empty($botToken) && file_exists("/psobb-bot/discord_config.json")) {
    $discordConfig = json_decode(@file_get_contents("/psobb-bot/discord_config.json"), true);
    $botToken = $discordConfig["bot_token"] ?? '';
}
if (empty($botToken)) {
    echo "[CRON_STREAK] BOT_TOKEN not configured in .env or discord_config.json. Exiting.\n";
    exit;
}

// 1. Find all users who logged in yesterday but NOT today and have alerts enabled
$query = "SELECT u.discord_id, u.username, u.account_id, u.language 
          FROM users u 
          JOIN daily_logins dl ON u.account_id = dl.account_id 
          WHERE dl.login_date = :yesterday 
          AND u.discord_id IS NOT NULL 
          AND (u.receive_discord_streak_msg IS NULL OR u.receive_discord_streak_msg = 1)
          AND u.account_id NOT IN (SELECT account_id FROM daily_logins WHERE login_date = :today)";

$stmt = $db->prepare($query);
$stmt->bindValue(":yesterday", $yesterday, SQLITE3_TEXT);
$stmt->bindValue(":today", $today, SQLITE3_TEXT);
$res = $stmt->execute();

while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $discordId = $row["discord_id"];
    $username = $row["username"];
    $lang = $row["language"] ?? "en";
    $srvAddr = get_server_address();
    
    // Send DM via Discord Bot in user's preferred language
    if ($lang === "jp") {
        $content = "⚠️ **ストリーク通知:** ハンター {$username}、デイリーログインストリークの期限まであと**6時間**です！ゲームまたはウェブサイト（https://{$srvAddr}）にログインして継続してください！";
    } elseif ($lang === "ru") {
        $content = "⚠️ **ВНИМАНИЕ:** Охотник {$username}, ваша серия ежедневных входов сгорит через **6 часов**! Зайдите в игру или на сайт (https://{$srvAddr}), чтобы сохранить серию!";
    } else {
        $content = "⚠️ **STREAK ALERT:** Hunter {$username}, your daily login streak is about to expire in **6 hours**! Log in to the website (https://{$srvAddr}) or game now to keep it alive!";
    }
    
    // We need to create a DM channel first
    $dmCmd = "curl -s -X POST \"https://discord.com/api/v10/users/@me/channels\" " .
             "-H \"Authorization: Bot $botToken\" " .
             "-H \"Content-Type: application/json\" " .
             "-d " . escapeshellarg(json_encode(["recipient_id" => $discordId]));
    
    $dmRes = json_decode(shell_exec($dmCmd), true);
    
    if (isset($dmRes["id"])) {
        $channelId = $dmRes["id"];
        $msgCmd = "curl -s -X POST \"https://discord.com/api/v10/channels/$channelId/messages\" " .
                  "-H \"Authorization: Bot $botToken\" " .
                  "-H \"Content-Type: application/json\" " .
                  "-d " . escapeshellarg(json_encode(["content" => $content]));
        shell_exec($msgCmd);
        echo "Alert sent to $username ($discordId)\n";
    }
}
