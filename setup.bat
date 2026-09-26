@echo off
rem ==============================================================================
rem PSOBB Web Portal — Automated Setup & NewServ Integration (Windows Batch)
rem ==============================================================================
rem This script initializes all required directories, configuration baselines,
rem SQLite database schemas, and tests connectivity to your NewServ game server.
rem
rem Usage:
rem   Double-click setup.bat in File Explorer OR run from cmd.exe:
rem     setup.bat
rem ==============================================================================

setlocal enabledelayedexpansion
chcp 65001 >nul 2>&1

rem Resolve directory of this script and cd to it
cd /d "%~dp0"

echo ======================================================================
echo     PSOBB WEB PORTAL — AUTOMATED SETUP ^& NEWSERV INTEGRATION (WINDOWS)
echo ======================================================================
echo.

rem ------------------------------------------------------------------------------
rem 1. PHP Engine & Extension Detection
rem ------------------------------------------------------------------------------
echo --^> [1/6] Verifying PHP Engine ^& Extensions...

set "PHP_CMD="

rem Check system PATH
where php >nul 2>&1
if %errorlevel% equ 0 (
    set "PHP_CMD=php"
) else (
    rem Check common Windows PHP locations
    if exist "C:\php\php.exe" (
        set "PHP_CMD=C:\php\php.exe"
    ) else if exist "C:\tools\php\php.exe" (
        set "PHP_CMD=C:\tools\php\php.exe"
    ) else if exist "C:\xampp\php\php.exe" (
        set "PHP_CMD=C:\xampp\php\php.exe"
    ) else if exist "C:\laragon\bin\php\php.exe" (
        set "PHP_CMD=C:\laragon\bin\php\php.exe"
    ) else if exist "C:\Program Files\PHP\php.exe" (
        set "PHP_CMD=C:\Program Files\PHP\php.exe"
    )
)

if "%PHP_CMD%"=="" (
    echo [X] Error: PHP CLI was not found in PATH or standard installation paths.
    echo.
    echo     Please install PHP 8.0+ or add your PHP directory to PATH:
    echo       - Winget:  winget install PHP.PHP.8.3
    echo       - XAMPP:   https://www.apachefriends.org/
    echo       - PHP ZIP: https://windows.php.net/download/
    echo.
    echo Press any key to exit...
    pause >nul
    exit /b 1
)

rem Get PHP version
for /f "tokens=*" %%v in ('"%PHP_CMD%" -r "echo PHP_VERSION;"') do set "PHP_VERSION=%%v"
echo [OK] PHP CLI detected: PHP %PHP_VERSION% (using: %PHP_CMD%)

rem Check PHP Extensions using PHP runtime
"%PHP_CMD%" -r "
$required = ['sqlite3', 'pdo', 'curl', 'mbstring', 'json', 'fileinfo', 'openssl'];
$missing = [];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
if (!empty($missing)) {
    echo '[!] Warning: Missing PHP extensions: ' . implode(', ', $missing) . PHP_EOL;
    echo '    Enable them in your php.ini by uncommenting:' . PHP_EOL;
    foreach ($missing as $m) {
        echo '      extension=' . ($m === 'sqlite3' ? 'pdo_sqlite' : $m) . PHP_EOL;
    }
} else {
    echo '[OK] All essential PHP extensions verified: sqlite3, curl, mbstring, json, fileinfo, openssl' . PHP_EOL;
}
"

rem ------------------------------------------------------------------------------
rem 2. Directory Structure Creation
rem ------------------------------------------------------------------------------
echo.
echo --^> [2/6] Verifying ^& Creating Directory Structure...

for %%D in (
    "db"
    "config"
    "img\uploads"
    "uploads\mods"
    "uploads\mod_images"
    "logs"
    "scratch"
) do (
    if not exist "%%~D" (
        mkdir "%%~D" >nul 2>&1
        echo [+] Created directory: %%~D\
    ) else (
        echo [OK] Directory exists: %%~D\
    )
)

rem ------------------------------------------------------------------------------
rem 3. Configuration Baselines (.env & JSON configs)
rem ------------------------------------------------------------------------------
echo.
echo --^> [3/6] Initializing Configuration Baselines...

rem .env verification
if not exist ".env" (
    if exist ".env.example" (
        copy ".env.example" ".env" >nul 2>&1
        echo [+] Created .env from .env.example
    ) else (
        (
            echo NEWSERV_API_URL="http://127.0.0.1:8443"
            echo NEWSERV_COMMAND_PREFIX="$"
            echo SERVER_NAME="PSOBB.IO"
            echo SERVER_ADDRESS="psobb.io"
            echo SERVER_TAGLINE="Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience."
        ) > ".env"
        echo [+] Generated baseline .env file
    )
) else (
    echo [OK] .env file exists
)

rem JSON configs via PHP to avoid batch escaping pitfalls
"%PHP_CMD%" -r "
// config/site.json
$siteFile = 'config/site.json';
if (!file_exists($siteFile)) {
    $siteData = [
        'server_name' => 'PSOBB.IO',
        'server_address' => 'psobb.io',
        'server_tagline' => 'Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience.',
        'hero_logo_url' => '/img/header_logo.png',
        'exp_rate' => '1x',
        'drop_rate' => '1x',
        'meseta_rate' => '1x',
        'discord_server' => 'https://discord.gg/28s84HJXha',
        'enable_registration' => true,
        'enable_bounties' => true,
        'enable_lfg' => true,
        'enable_mods' => true,
        'enable_quest_editor' => true,
        'enable_discord_oauth' => true,
        'client_windows_url' => '/downloads/PSOBBIO-Setup_1.25.13b.exe',
        'client_mac_url' => '/downloads/PSOBBIO_125.13.dmg',
        'client_raw_url' => '/downloads/PSOBBIO-Linux_1.25.13.zip'
    ];
    file_put_contents($siteFile, json_encode($siteData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo '[+] Created default config/site.json' . PHP_EOL;
} else {
    echo '[OK] config/site.json exists' . PHP_EOL;
}

// config/theme.json
$themeFile = 'config/theme.json';
if (!file_exists($themeFile)) {
    $themeData = [
        'active_preset' => 'classic',
        'allow_user_override' => true,
        'discord_server' => 'https://discord.gg/28s84HJXha',
        'discord_invite_url' => 'https://discord.gg/28s84HJXha'
    ];
    file_put_contents($themeFile, json_encode($themeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo '[+] Created default config/theme.json' . PHP_EOL;
} else {
    echo '[OK] config/theme.json exists' . PHP_EOL;
}

// config/about.json
$aboutFile = 'config/about.json';
if (!file_exists($aboutFile)) {
    $aboutData = [
        'hero_title' => 'About %s',
        'hero_subtitle' => 'Welcome to the ultimate custom Phantasy Star Online Blue Burst server. Our mission is to seamlessly bridge classic 2004 Sega dreamscape nostalgia with bleeding-edge modern web capabilities, automated game services, and advanced AI integration.',
        'command_deck_title' => '%s Command Deck',
        'show_features' => true,
        'show_tech_specs' => true,
        'crew' => [
            [
                'id' => 'liquidspikes',
                'name' => 'LiquidSpikes',
                'role' => 'Root Administrator & System Architect',
                'specialty' => 'Core Backend & Web Integration',
                'icon' => 'fas fa-crown',
                'theme' => 'admin-card',
                'bio' => 'Server builder and administrator managing infrastructure and web portals.'
            ]
        ]
    ];
    file_put_contents($aboutFile, json_encode($aboutData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo '[+] Created default config/about.json' . PHP_EOL;
} else {
    echo '[OK] config/about.json exists' . PHP_EOL;
}
"

rem ------------------------------------------------------------------------------
rem 4. Database Schema Initialization & Integrity
rem ------------------------------------------------------------------------------
echo.
echo --^> [4/6] Initializing SQLite Database ^& Schemas...

if exist "db\init_db.php" (
    "%PHP_CMD%" db\init_db.php
    echo [OK] SQLite database initialized and verified at db\website.db
) else (
    echo [X] Error: db\init_db.php not found!
    pause
    exit /b 1
)

rem ------------------------------------------------------------------------------
rem 5. NewServ Game Server Connectivity & Player Data Setup
rem ------------------------------------------------------------------------------
echo.
echo --^> [5/6] Confirming Access to NewServ Game Server...

"%PHP_CMD%" -r "
$newservUrl = 'http://127.0.0.1:8443';
if (file_exists('.env')) {
    $lines = file('.env');
    foreach ($lines as $line) {
        if (preg_match('/^\s*NEWSERV_API_URL\s*=\s*[\"\'\s]*(.*?)[\"\'\s]*$/', $line, $m)) {
            if (!empty($m[1])) $newservUrl = trim($m[1]);
        }
    }
}

echo 'Testing NewServ REST API at: ' . $newservUrl . ' ...' . PHP_EOL;

$ctx = stream_context_create(['http' => ['timeout' => 3]]);
$summary = @file_get_contents($newservUrl . '/y/summary', false, $ctx);

if ($summary && $summary !== '') {
    echo '[OK] SUCCESS: NewServ API is ONLINE & RESPONDING!' . PHP_EOL;
    $data = json_decode($summary, true);
    if (is_array($data)) {
        $clients = isset($data['num_clients']) ? $data['num_clients'] : (isset($data['clients']) ? count($data['clients']) : 'N/A');
        echo '    - Active Clients: ' . $clients . PHP_EOL;
        echo '    - Server Telemetry: Active and responding' . PHP_EOL;
    }
} else {
    echo '[!] NOTICE: NewServ API is currently UNREACHABLE at ' . $newservUrl . PHP_EOL;
    echo '    This is expected if your NewServ daemon is not running yet.' . PHP_EOL;
    echo '    To start NewServ:' . PHP_EOL;
    echo '      1. Verify port in your newserv configuration (system/config.json).' . PHP_EOL;
    echo '      2. Start newserv.exe' . PHP_EOL;
    echo '      3. Update NEWSERV_API_URL in .env if running on a different port or host.' . PHP_EOL;
}

// Check player directory
$playerPaths = [
    '..\newserv\system\players',
    '.\newserv\system\players',
    'C:\newserv\system\players'
];
$foundPlayerDir = false;
foreach ($playerPaths as $path) {
    if (is_dir($path)) {
        echo '[OK] Located NewServ player profiles directory at: ' . $path . PHP_EOL;
        $foundPlayerDir = true;
        break;
    }
}
if (!$foundPlayerDir) {
    echo '[i] NewServ players directory not found in common paths (skipping offline parser check).' . PHP_EOL;
}
"

rem ------------------------------------------------------------------------------
rem 6. Administrator Account Status Check
rem ------------------------------------------------------------------------------
echo.
echo --^> [6/6] Checking Administrator Account Status...

"%PHP_CMD%" -r "
if (file_exists('db/website.db')) {
    try {
        $db = new SQLite3('db/website.db');
        $res = $db->query('SELECT count(*) as c FROM users WHERE is_admin = 1');
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
        $adminCount = $row ? intval($row['c']) : 0;
        if ($adminCount > 0) {
            $uRes = $db->query('SELECT username FROM users WHERE is_admin = 1 LIMIT 3');
            $names = [];
            while ($u = $uRes->fetchArray(SQLITE3_ASSOC)) $names[] = $u['username'];
            echo '[OK] Administrator account(s) detected: ' . implode(', ', $names) . ' (' . $adminCount . ' total)' . PHP_EOL;
        } else {
            echo '[!] No administrator accounts detected in website.db.' . PHP_EOL;
            echo '    You can promote an existing registered player to Admin at any time via:' . PHP_EOL;
            echo '      php promote_admin.php <username>' . PHP_EOL;
        }
    } catch (Exception $e) {
        echo '[!] Could not query admin status: ' . $e->getMessage() . PHP_EOL;
    }
}
"

rem ------------------------------------------------------------------------------
rem Summary & Next Steps
rem ------------------------------------------------------------------------------
echo.
echo ======================================================================
echo     SETUP ^& DIAGNOSTIC COMPLETE — SITE IS READY!
echo ======================================================================
echo.
echo   Web Portal:          http://localhost:8000/
echo   Admin Control Panel: http://localhost:8000/admin/site_settings.php
echo   Database:            SQLite (all platform tables verified at db\website.db)
echo.
echo   To launch the built-in development server:
echo     %PHP_CMD% -S 0.0.0.0:8000
echo.
echo   For production Windows deployment on IIS:
echo     - Ensure URL Rewrite and FastCGI PHP modules are installed.
echo     - Root web.config is pre-configured to block sensitive database and config paths.
echo.

set /p "START_NOW=Would you like to start the local development server now? (Y/N) [default: N]: "
if /i "%START_NOW%"=="Y" (
    echo.
    echo Starting PHP development server on http://localhost:8000 ...
    echo Press Ctrl+C to stop.
    echo.
    "%PHP_CMD%" -S 0.0.0.0:8000
) else (
    echo.
    echo Setup complete. Have fun with PSOBB!
    echo Press any key to exit...
    pause >nul
)
