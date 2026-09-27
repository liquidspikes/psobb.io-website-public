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
rem     setup.bat -y  (non-interactive mode)
rem ==============================================================================

setlocal enabledelayedexpansion
chcp 65001 >nul 2>&1

rem Resolve directory of this script and cd to it
cd /d "%~dp0"

rem Check command line arguments for non-interactive mode
set "NON_INTERACTIVE=0"
if /i "%~1"=="-y" set "NON_INTERACTIVE=1"
if /i "%~1"=="/y" set "NON_INTERACTIVE=1"
if /i "%~1"=="--yes" set "NON_INTERACTIVE=1"

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
            echo NEWSERV_PLAYERS_DIR="C:/newserv/system/players"
            echo NEWSERV_COMMAND_PREFIX="$"
            echo SERVER_NAME="PSOBB.IO"
            echo SERVER_ADDRESS="psobb.io"
            echo SERVER_TAGLINE="Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience."
            echo EXP_RATE="1x"
            echo DROP_RATE="1x"
            echo MESETA_RATE="1x"
        ) > ".env"
        echo [+] Generated baseline .env file
    )
) else (
    echo [OK] .env file exists
)

rem Ensure essential settings exist in .env and config/site.json via PHP
"%PHP_CMD%" -r "
$envFile = '.env';
$env = file_exists($envFile) ? file_get_contents($envFile) : '';
$q = chr(34);
$defaults = [
    'NEWSERV_API_URL' => ['http://127.0.0.1:8443', 'NewServ REST API base URL'],
    'NEWSERV_PLAYERS_DIR' => ['C:/newserv/system/players', 'NewServ player save profiles directory (.psochar / .psobank)'],
    'NEWSERV_COMMAND_PREFIX' => ['$', 'NewServ chat command prefix'],
    'SERVER_NAME' => ['PSOBB.IO', 'Public server brand name'],
    'SERVER_ADDRESS' => ['psobb.io', 'Public server domain or IP address'],
    'SERVER_TAGLINE' => ['Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience.', 'Public server descriptive tagline'],
    'EXP_RATE' => ['1x', 'Displayed EXP multiplier'],
    'DROP_RATE' => ['1x', 'Displayed Rare Drop multiplier'],
    'MESETA_RATE' => ['1x', 'Displayed Meseta multiplier']
];
$changed = false;
foreach ($defaults as $k => $item) {
    if (!preg_match('/^\s*' . preg_quote($k, '/') . '\s*=/m', $env)) {
        $env = rtrim($env) . PHP_EOL . PHP_EOL . '# ' . $item[1] . PHP_EOL . $k . '=' . $q . $item[0] . $q . PHP_EOL;
        echo '[+] Added missing setting to .env: ' . $k . '=' . $q . $item[0] . $q . PHP_EOL;
        $changed = true;
    }
}
if ($changed) {
    file_put_contents($envFile, $env);
}

// config/site.json
$siteFile = 'config/site.json';
if (!file_exists($siteFile)) {
    if (file_exists('config/site.example.json')) {
        copy('config/site.example.json', $siteFile);
        echo '[+] Created config/site.json from config/site.example.json' . PHP_EOL;
    } else {
        $siteData = [
            'server_name' => 'PSOBB.IO',
            'server_address' => 'psobb.io',
            'server_tagline' => 'Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience.',
            'default_language' => 'auto',
            'hero_logo_url' => '/img/header_logo.png',
            'exp_rate' => '1x',
            'drop_rate' => '1x',
            'meseta_rate' => '1x',
            'discord_server' => 'https://discord.gg/28s84HJXha',
            'newserv_players_dir' => 'C:/newserv/system/players',
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
    }
} else {
    echo '[OK] config/site.json exists' . PHP_EOL;
    $site = @json_decode(file_get_contents($siteFile), true) ?: [];
    $siteDefaults = [
        'server_name' => 'PSOBB.IO',
        'server_address' => 'psobb.io',
        'server_tagline' => 'Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience.',
        'newserv_players_dir' => 'C:/newserv/system/players'
    ];
    $siteChanged = false;
    foreach ($siteDefaults as $sk => $sv) {
        if (!isset($site[$sk])) {
            $site[$sk] = $sv;
            $siteChanged = true;
        }
    }
    if ($siteChanged) {
        file_put_contents($siteFile, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
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
rem 5. NewServ Game Server Connectivity & Configuration
rem ------------------------------------------------------------------------------
echo.
echo --^> [5/6] Confirming Access to NewServ Game Server...

set "NEWSERV_REACHABLE=0"
set "CUR_SERVER_NAME="
set "CUR_SERVER_ADDRESS="
set "CUR_API_URL="
set "CUR_PLAYERS_DIR="

rem Query NewServ API, auto-detect branding, and output current settings to batch
for /f "usebackq tokens=1,* delims==" %%A in (`"%PHP_CMD%" -r "
    \$q = chr(34);
    \$envFile = '.env';
    \$env = file_exists(\$envFile) ? file_get_contents(\$envFile) : '';
    
    function getEnvVal(\$key, \$subject, \$def) {
        if (preg_match('/^\s*' . preg_quote(\$key, '/') . '\s*=\s*(.*)$/m', \$subject, \$m)) {
            return trim(\$m[1], chr(34) . chr(39) . ' ');
        }
        return \$def;
    }
    
    \$siteFile = 'config/site.json';
    \$site = file_exists(\$siteFile) ? @json_decode(file_get_contents(\$siteFile), true) : [];
    
    \$apiUrl = getEnvVal('NEWSERV_API_URL', \$env, 'http://127.0.0.1:8443');
    \$name = getEnvVal('SERVER_NAME', \$env, \$site['server_name'] ?? 'PSOBB.IO');
    \$addr = getEnvVal('SERVER_ADDRESS', \$env, \$site['server_address'] ?? 'psobb.io');
    \$pDir = getEnvVal('NEWSERV_PLAYERS_DIR', \$env, \$site['newserv_players_dir'] ?? '');
    
    // Auto-detect players dir if not set or default does not exist
    if (empty(\$pDir) || !is_dir(\$pDir)) {
        \$candidates = [
            '..\newserv\system\players',
            '.\newserv\system\players',
            'C:\newserv\system\players',
            'D:\newserv\system\players'
        ];
        foreach (\$candidates as \$c) {
            if (is_dir(\$c)) {
                \$pDir = \$c;
                break;
            }
        }
        if (empty(\$pDir)) \$pDir = 'C:/newserv/system/players';
    }
    
    echo 'Testing NewServ REST API at: ' . \$apiUrl . ' ...' . PHP_EOL;
    
    \$ctx = stream_context_create(['http' => ['timeout' => 3]]);
    \$summary = @file_get_contents(\$apiUrl . '/y/summary', false, \$ctx);
    \$server = @file_get_contents(\$apiUrl . '/y/server', false, \$ctx);
    \$online = 0;
    
    if (\$summary && \$summary !== '') {
        \$online = 1;
        echo 'INFO_MSG=[OK] SUCCESS: NewServ API is ONLINE & RESPONDING!' . PHP_EOL;
        \$sData = @json_decode(\$summary, true);
        if (is_array(\$sData)) {
            \$clients = isset(\$sData['num_clients']) ? \$sData['num_clients'] : (isset(\$sData['clients']) ? count(\$sData['clients']) : 'N/A');
            echo 'INFO_CLIENTS=    - Active Clients: ' . \$clients . PHP_EOL;
            echo 'INFO_TELEMETRY=    - Server Telemetry: Active and responding' . PHP_EOL;
        }
        
        if (\$server && \$server !== '') {
            \$srvData = @json_decode(\$server, true);
            if (is_array(\$srvData) && !empty(\$srvData['ServerName'])) {
                \$detectedName = trim(\$srvData['ServerName']);
                echo 'INFO_DETECTED=[OK] Auto-detected Server Name from NewServ API: ' . \$detectedName . PHP_EOL;
                if ((\$name === 'PSOBB.IO' || empty(\$name)) && \$detectedName !== 'newserv' && \$detectedName !== 'PSOBB.IO') {
                    echo 'INFO_SYNC=    Syncing website server branding to: ' . \$detectedName . PHP_EOL;
                    \$name = \$detectedName;
                    
                    if (preg_match('/^\s*SERVER_NAME\s*=/m', \$env)) {
                        \$env = preg_replace('/^\s*SERVER_NAME\s*=.*$/m', 'SERVER_NAME=' . \$q . \$name . \$q, \$env);
                        file_put_contents(\$envFile, \$env);
                    }
                    if (!empty(\$site)) {
                        \$site['server_name'] = \$name;
                        file_put_contents(\$siteFile, json_encode(\$site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }
            }
        }
    } else {
        echo 'WARN_MSG=[!] NOTICE: NewServ API is currently UNREACHABLE at ' . \$apiUrl . PHP_EOL;
        echo 'WARN_HINT1=    This is expected if your NewServ daemon is not running yet.' . PHP_EOL;
        echo 'WARN_HINT2=    To start NewServ: verify port in system\config.json, then run newserv.exe' . PHP_EOL;
    }
    
    echo 'NEWSERV_REACHABLE=' . \$online . PHP_EOL;
    echo 'CUR_SERVER_NAME=' . \$name . PHP_EOL;
    echo 'CUR_SERVER_ADDRESS=' . \$addr . PHP_EOL;
    echo 'CUR_API_URL=' . \$apiUrl . PHP_EOL;
    echo 'CUR_PLAYERS_DIR=' . \$pDir . PHP_EOL;
"`) do (
    if "%%A"=="INFO_MSG" echo %%B
    if "%%A"=="INFO_CLIENTS" echo %%B
    if "%%A"=="INFO_TELEMETRY" echo %%B
    if "%%A"=="INFO_DETECTED" echo %%B
    if "%%A"=="INFO_SYNC" echo %%B
    if "%%A"=="WARN_MSG" echo %%B
    if "%%A"=="WARN_HINT1" echo %%B
    if "%%A"=="WARN_HINT2" echo %%B
    if "%%A"=="NEWSERV_REACHABLE" set "NEWSERV_REACHABLE=%%B"
    if "%%A"=="CUR_SERVER_NAME" set "CUR_SERVER_NAME=%%B"
    if "%%A"=="CUR_SERVER_ADDRESS" set "CUR_SERVER_ADDRESS=%%B"
    if "%%A"=="CUR_API_URL" set "CUR_API_URL=%%B"
    if "%%A"=="CUR_PLAYERS_DIR" set "CUR_PLAYERS_DIR=%%B"
)

if "%NON_INTERACTIVE%"=="0" (
    echo.
    echo --^> Configure Public Server Identity ^& Branding...
    set /p "PROMPT_SRV_NAME=    Enter Server Brand Name [!CUR_SERVER_NAME!]: "
    if not "!PROMPT_SRV_NAME!"=="" set "CUR_SERVER_NAME=!PROMPT_SRV_NAME!"

    set /p "PROMPT_SRV_ADDR=    Enter Server Domain / Host Address [!CUR_SERVER_ADDRESS!]: "
    if not "!PROMPT_SRV_ADDR!"=="" set "CUR_SERVER_ADDRESS=!PROMPT_SRV_ADDR!"

    if "!NEWSERV_REACHABLE!"=="0" (
        set /p "PROMPT_API_URL=    Enter NewServ REST API URL [!CUR_API_URL!]: "
        if not "!PROMPT_API_URL!"=="" set "CUR_API_URL=!PROMPT_API_URL!"
    )

    echo.
    echo --^> Configure NewServ Player Directory (.psochar / .psobank files)...
    echo     The website reads offline character slots, bank storage, and material usage
    echo     from NewServ's player save directory (system\players\).
    set /p "PROMPT_PLAYERS_DIR=    Enter path to NewServ system\players directory [!CUR_PLAYERS_DIR!]: "
    if not "!PROMPT_PLAYERS_DIR!"=="" set "CUR_PLAYERS_DIR=!PROMPT_PLAYERS_DIR!"
)

rem Save confirmed settings to .env and config/site.json, and verify directory
"%PHP_CMD%" -r "
    \$q = chr(34);
    \$name = getenv('CUR_SERVER_NAME') ?: 'PSOBB.IO';
    \$addr = getenv('CUR_SERVER_ADDRESS') ?: 'psobb.io';
    \$apiUrl = getenv('CUR_API_URL') ?: 'http://127.0.0.1:8443';
    \$pDir = getenv('CUR_PLAYERS_DIR') ?: 'C:/newserv/system/players';
    
    // Normalize path: convert backslashes to forward slashes, trim trailing slashes
    \$pDir = rtrim(str_replace(chr(92), '/', \$pDir), '/');
    
    // 1. Update .env
    \$envFile = '.env';
    if (file_exists(\$envFile)) {
        \$env = file_get_contents(\$envFile);
        \$updates = [
            'SERVER_NAME' => \$name,
            'SERVER_ADDRESS' => \$addr,
            'NEWSERV_API_URL' => \$apiUrl,
            'NEWSERV_PLAYERS_DIR' => \$pDir
        ];
        foreach (\$updates as \$k => \$v) {
            if (preg_match('/^\s*' . preg_quote(\$k, '/') . '\s*=/m', \$env)) {
                \$env = preg_replace('/^\s*' . preg_quote(\$k, '/') . '\s*=.*$/m', \$k . '=' . \$q . \$v . \$q, \$env);
            } else {
                \$env = rtrim(\$env) . PHP_EOL . \$k . '=' . \$q . \$v . \$q . PHP_EOL;
            }
        }
        file_put_contents(\$envFile, \$env);
    }
    
    // 2. Update config/site.json
    \$siteFile = 'config/site.json';
    if (file_exists(\$siteFile)) {
        \$site = @json_decode(file_get_contents(\$siteFile), true) ?: [];
        \$site['server_name'] = \$name;
        \$site['server_address'] = \$addr;
        \$site['newserv_players_dir'] = \$pDir;
        file_put_contents(\$siteFile, json_encode(\$site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    echo '[OK] Saved configuration:' . PHP_EOL;
    echo '    - Server Brand Name: ' . \$name . PHP_EOL;
    echo '    - Server Address:    ' . \$addr . PHP_EOL;
    echo '    - NewServ Players:   ' . \$pDir . PHP_EOL;
    
    if (is_dir(\$pDir)) {
        echo '[OK] Verified NewServ player directory exists at: ' . \$pDir . PHP_EOL;
    } else {
        echo '[!] Notice: Directory not found at: ' . \$pDir . PHP_EOL;
        echo '    The website will function with live API queries, but offline slot/bank viewing requires this directory.' . PHP_EOL;
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
echo   Server Name:         !CUR_SERVER_NAME!
echo   Server Address:      !CUR_SERVER_ADDRESS!
echo   Web Portal:          http://localhost:8000/
echo   Admin Control Panel: http://localhost:8000/admin/site_settings.php
echo   NewServ API:         !CUR_API_URL!
echo   NewServ Players:     !CUR_PLAYERS_DIR!
echo   Database:            SQLite (all platform tables verified at db\website.db)
echo.
echo   To launch the built-in development server:
echo     %PHP_CMD% -S 0.0.0.0:8000
echo.
echo   For production Windows deployment on IIS:
echo     - Ensure URL Rewrite and FastCGI PHP modules are installed.
echo     - Root web.config is pre-configured to block sensitive database and config paths.
echo.

if "%NON_INTERACTIVE%"=="1" (
    echo Non-interactive setup complete.
    exit /b 0
)

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
