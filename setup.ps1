<#
.SYNOPSIS
    PSOBB Web Portal — Automated Setup & NewServ Integration (PowerShell)
.DESCRIPTION
    Initializes directory structure, configuration files, SQLite database schemas,
    and validates connectivity to the NewServ PSOBB game server on Windows.
.PARAMETER StartServer
    If specified, automatically starts the PHP built-in web server upon completion.
.PARAMETER Port
    Port to bind the built-in development server (default: 8000).
.PARAMETER PhpPath
    Optional manual path to php.exe if not in PATH or standard directories.
.EXAMPLE
    .\setup.ps1
.EXAMPLE
    .\setup.ps1 -StartServer -Port 8080
#>

[CmdletBinding()]
param(
    [switch]$StartServer,
    [int]$Port = 8000,
    [string]$PhpPath = ""
)

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
Set-Location -Path $ScriptDir

function Write-StepHeader([string]$Step, [string]$Title) {
    Write-Host ""
    Write-Host "--> [$Step] $Title" -ForegroundColor Cyan
}

function Write-Success([string]$Message) {
    Write-Host "[✔] $Message" -ForegroundColor Green
}

function Write-Info([string]$Message) {
    Write-Host "[i] $Message" -ForegroundColor DarkCyan
}

function Write-WarnMsg([string]$Message) {
    Write-Host "[!] $Message" -ForegroundColor Yellow
}

function Write-Failure([string]$Message) {
    Write-Host "[✘] $Message" -ForegroundColor Red
}

Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "    PSOBB WEB PORTAL — AUTOMATED SETUP & NEWSERV INTEGRATION (WINDOWS)" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host ""

# ------------------------------------------------------------------------------
# 1. PHP Engine & Extension Detection
# ------------------------------------------------------------------------------
Write-StepHeader "1/6" "Verifying PHP Engine & Extensions..."

$phpExe = ""

if ($PhpPath -and (Test-Path $PhpPath)) {
    $phpExe = $PhpPath
} else {
    $cmd = Get-Command "php.exe" -ErrorAction SilentlyContinue
    if ($cmd) {
        $phpExe = $cmd.Source
    } else {
        $commonLocations = @(
            "C:\php\php.exe",
            "C:\tools\php\php.exe",
            "C:\xampp\php\php.exe",
            "C:\laragon\bin\php\php.exe",
            "C:\Program Files\PHP\php.exe"
        )
        foreach ($loc in $commonLocations) {
            if (Test-Path $loc) {
                $phpExe = $loc
                break
            }
        }
    }
}

if (-not $phpExe) {
    Write-Failure "PHP CLI was not found in PATH or standard installation locations."
    Write-Host ""
    Write-Host "Please install PHP (version 8.0+ recommended) or add it to PATH:" -ForegroundColor Yellow
    Write-Host "  1. Winget:  winget install PHP.PHP.8.3" -ForegroundColor White
    Write-Host "  2. XAMPP:   https://www.apachefriends.org/" -ForegroundColor White
    Write-Host "  3. ZIP:     https://windows.php.net/download/" -ForegroundColor White
    Write-Host ""
    exit 1
}

$phpVersion = & $phpExe -r 'echo PHP_VERSION;' 2>$null
Write-Success "PHP CLI detected: PHP $phpVersion (using: $phpExe)"

# Test Extensions
$checkExtCode = @'
$required = ['sqlite3', 'pdo', 'curl', 'mbstring', 'json', 'fileinfo', 'openssl'];
$missing = [];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) { $missing[] = $ext; }
}
echo implode(',', $missing);
'@
$missingExts = (& $phpExe -r $checkExtCode 2>$null)

if ($missingExts) {
    Write-WarnMsg "Missing PHP extensions: $missingExts"
    Write-Host "    Enable them in php.ini by uncommenting the relevant extension lines." -ForegroundColor Gray
} else {
    Write-Success "All essential PHP extensions verified: sqlite3, curl, mbstring, json, fileinfo, openssl"
}

# ------------------------------------------------------------------------------
# 2. Directory Structure Creation
# ------------------------------------------------------------------------------
Write-StepHeader "2/6" "Verifying & Creating Directory Structure..."

$requiredDirs = @(
    "db",
    "config",
    "img\uploads",
    "uploads\mods",
    "uploads\mod_images",
    "logs",
    "scratch"
)

foreach ($dir in $requiredDirs) {
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        Write-Success "Created directory: $dir\"
    } else {
        Write-Success "Directory exists: $dir\"
    }
}

# ------------------------------------------------------------------------------
# 3. Configuration Baselines (.env & JSON configs)
# ------------------------------------------------------------------------------
Write-StepHeader "3/6" "Initializing Configuration Baselines..."

if (-not (Test-Path ".env")) {
    if (Test-Path ".env.example") {
        Copy-Item -Path ".env.example" -Destination ".env"
        Write-Success "Created .env from .env.example"
    } else {
        $defaultEnv = @"
NEWSERV_API_URL="http://127.0.0.1:8443"
NEWSERV_COMMAND_PREFIX="$"
SERVER_NAME="PSOBB.IO"
SERVER_ADDRESS="psobb.io"
SERVER_TAGLINE="Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience."
"@
        Set-Content -Path ".env" -Value $defaultEnv -Encoding utf8
        Write-Success "Generated baseline .env file"
    }
} else {
    Write-Success ".env file exists"
}

# Baseline JSON files
if (-not (Test-Path "config\site.json")) {
    $siteConfig = @{
        server_name = "PSOBB.IO"
        server_address = "psobb.io"
        server_tagline = "Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience."
        default_language = "auto"
        hero_logo_url = "/img/header_logo.png"
        exp_rate = "1x"
        drop_rate = "1x"
        meseta_rate = "1x"
        discord_server = "https://discord.gg/28s84HJXha"
        enable_registration = $true
        enable_bounties = $true
        enable_lfg = $true
        enable_mods = $true
        enable_quest_editor = $true
        enable_discord_oauth = $true
        client_windows_url = "/downloads/PSOBBIO-Setup_1.25.13b.exe"
        client_mac_url = "/downloads/PSOBBIO_125.13.dmg"
        client_raw_url = "/downloads/PSOBBIO-Linux_1.25.13.zip"
    }
    $siteConfig | ConvertTo-Json -Depth 4 | Set-Content "config\site.json" -Encoding utf8
    Write-Success "Created default config\site.json"
} else {
    Write-Success "config\site.json exists"
}

if (-not (Test-Path "config\theme.json")) {
    $themeConfig = @{
        active_preset = "classic"
        allow_user_override = $true
        discord_server = "https://discord.gg/28s84HJXha"
        discord_invite_url = "https://discord.gg/28s84HJXha"
    }
    $themeConfig | ConvertTo-Json -Depth 4 | Set-Content "config\theme.json" -Encoding utf8
    Write-Success "Created default config\theme.json"
} else {
    Write-Success "config\theme.json exists"
}

if (-not (Test-Path "config\about.json")) {
    $aboutConfig = @{
        hero_title = "About %s"
        hero_subtitle = "Welcome to the ultimate custom Phantasy Star Online Blue Burst server. Our mission is to seamlessly bridge classic 2004 Sega dreamscape nostalgia with bleeding-edge modern web capabilities, automated game services, and advanced AI integration."
        command_deck_title = "%s Command Deck"
        show_features = $true
        show_tech_specs = $true
        crew = @(
            @{
                id = "liquidspikes"
                name = "LiquidSpikes"
                role = "Root Administrator & System Architect"
                specialty = "Core Backend & Web Integration"
                icon = "fas fa-crown"
                theme = "admin-card"
                bio = "Server builder and administrator managing infrastructure and web portals."
            }
        )
    }
    $aboutConfig | ConvertTo-Json -Depth 4 | Set-Content "config\about.json" -Encoding utf8
    Write-Success "Created default config\about.json"
} else {
    Write-Success "config\about.json exists"
}

# ------------------------------------------------------------------------------
# 4. Database Schema Initialization & Integrity
# ------------------------------------------------------------------------------
Write-StepHeader "4/6" "Initializing SQLite Database & Schemas..."

if (Test-Path "db\init_db.php") {
    $initOutput = & $phpExe "db\init_db.php"
    Write-Host $initOutput -ForegroundColor Gray
    Write-Success "SQLite database initialized and verified at db\website.db"
} else {
    Write-Failure "Error: db\init_db.php not found!"
    exit 1
}

# ------------------------------------------------------------------------------
# 5. NewServ Game Server Connectivity & Player Data Setup
# ------------------------------------------------------------------------------
Write-StepHeader "5/6" "Confirming Access to NewServ Game Server..."

$newservUrl = "http://127.0.0.1:8443"
if (Test-Path ".env") {
    $envLines = Get-Content ".env"
    foreach ($line in $envLines) {
        if ($line -match '^\s*NEWSERV_API_URL\s*=\s*["'']?(.*?)["'']?\s*$') {
            if ($matches[1]) { $newservUrl = $matches[1].Trim() }
        }
    }
}

Write-Host "Testing NewServ REST API at: $newservUrl ..." -ForegroundColor Gray

$newservOnline = $false
try {
    $res = Invoke-RestMethod -Uri "$newservUrl/y/summary" -TimeoutSec 3 -ErrorAction Stop
    $newservOnline = $true
    Write-Success "SUCCESS: NewServ API is ONLINE & RESPONDING!"
    $clients = if ($res.num_clients) { $res.num_clients } elseif ($res.clients) { $res.clients.Count } else { "N/A" }
    Write-Host "    - Active Clients: $clients" -ForegroundColor Gray
} catch {
    Write-WarnMsg "NewServ API is currently UNREACHABLE at $newservUrl"
    Write-Host "    This is expected if your NewServ daemon is not running yet." -ForegroundColor Gray
    Write-Host "    To start NewServ:" -ForegroundColor Gray
    Write-Host "      1. Verify port in system\config.json" -ForegroundColor Gray
    Write-Host "      2. Start newserv.exe" -ForegroundColor Gray
    Write-Host "      3. Update NEWSERV_API_URL in .env if running on a different port or host." -ForegroundColor Gray
}

# Check Player Profiles Directory
$playerPaths = @(
    "..\newserv\system\players",
    ".\newserv\system\players",
    "C:\newserv\system\players"
)
$foundPlayers = $false
foreach ($p in $playerPaths) {
    if (Test-Path $p) {
        Write-Success "Located NewServ player profiles directory at: $p"
        $foundPlayers = $true
        break
    }
}
if (-not $foundPlayers) {
    Write-Info "NewServ players directory not found in common locations (skipping offline parser check)."
}

# ------------------------------------------------------------------------------
# 6. Administrator Account Status Check
# ------------------------------------------------------------------------------
Write-StepHeader "6/6" "Checking Administrator Account Status..."

$adminCountCode = @'
try {
    $db = new SQLite3('db/website.db');
    $res = $db->query('SELECT count(*) as c FROM users WHERE is_admin = 1');
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    echo $row ? intval($row['c']) : 0;
} catch (Exception $e) { echo 0; }
'@
$adminCount = [int](& $phpExe -r $adminCountCode 2>$null)

if ($adminCount -gt 0) {
    $adminNamesCode = @'
try {
    $db = new SQLite3('db/website.db');
    $res = $db->query('SELECT username FROM users WHERE is_admin = 1 LIMIT 3');
    $names = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $names[] = $r['username'];
    echo implode(', ', $names);
} catch (Exception $e) { echo ''; }
'@
    $adminNames = (& $phpExe -r $adminNamesCode 2>$null)
    Write-Success "Administrator account(s) detected: $adminNames ($adminCount total)"
} else {
    Write-WarnMsg "No administrator accounts detected in website.db."
    Write-Host "    You can promote an existing registered player to Admin at any time via:" -ForegroundColor Gray
    Write-Host "      php promote_admin.php <username>" -ForegroundColor White
}

# ------------------------------------------------------------------------------
# Summary Output
# ------------------------------------------------------------------------------
Write-Host ""
Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "    SETUP & DIAGNOSTIC COMPLETE — SITE IS READY!                     " -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Web Portal:          http://localhost:$Port/" -ForegroundColor White
Write-Host "  Admin Control Panel: http://localhost:$Port/admin/site_settings.php" -ForegroundColor White
Write-Host "  NewServ API Status:  $([string]($newservOnline ? 'ONLINE' : 'UNREACHABLE'))" -ForegroundColor ($newservOnline ? "Green" : "Yellow")
Write-Host "  Database:            SQLite (db\website.db)" -ForegroundColor White
Write-Host ""
Write-Host "  To launch the built-in development server:" -ForegroundColor Gray
Write-Host "    $phpExe -S 0.0.0.0:$Port" -ForegroundColor Cyan
Write-Host ""
Write-Host "  For IIS production deployment:" -ForegroundColor Gray
Write-Host "    - The root web.config is pre-configured with security requestFiltering to block .db and config." -ForegroundColor Gray
Write-Host ""

if ($StartServer) {
    Write-Host "Starting PHP development server on http://localhost:$Port ..." -ForegroundColor Green
    & $phpExe -S "0.0.0.0:$Port"
} else {
    $choice = Read-Host "Would you like to start the local development server now? (Y/N) [default: N]"
    if ($choice -match '^[Yy]') {
        Write-Host "Starting PHP development server on http://localhost:$Port ..." -ForegroundColor Green
        & $phpExe -S "0.0.0.0:$Port"
    }
}
