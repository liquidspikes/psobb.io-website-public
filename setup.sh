#!/bin/bash
# ==============================================================================
# PSOBB Web Portal — Automated Setup, Diagnostic & Deployment Script
# ==============================================================================
# This script initializes all required directories, configuration baselines,
# SQLite database schemas, tests connectivity to your NewServ game server,
# and configures secure POSIX permissions.
#
# Can be run on Linux VPS (root/sudo) or locally for development.
# Usage:
#   sudo ./setup.sh           # Full production VPS setup with web server ownership
#   ./setup.sh                # Local dev / non-root environment setup
# ==============================================================================

set -e

# ANSI Color Codes
CYAN='\033[0;36m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo -e "${CYAN}${BOLD}"
echo "======================================================================"
echo "    PSOBB WEB PORTAL — AUTOMATED SETUP & NEWSERV INTEGRATION         "
echo "======================================================================"
echo -e "${NC}"

# ------------------------------------------------------------------------------
# 1. Environment & User Detection
# ------------------------------------------------------------------------------
NON_INTERACTIVE=0
for arg in "$@"; do
    case "$arg" in
        -y|--yes|--non-interactive)
            NON_INTERACTIVE=1
            ;;
    esac
done

IS_ROOT=0
if [ "$EUID" -eq 0 ]; then
    IS_ROOT=1
fi

# Detect web server user if running as root
WEB_USER="${WEB_USER:-}"
WEB_GROUP="${WEB_GROUP:-}"

if [ "$IS_ROOT" -eq 1 ]; then
    if [ -z "$WEB_USER" ]; then
        if id "www-data" &>/dev/null; then
            WEB_USER="www-data"
            WEB_GROUP="www-data"
        elif id "nginx" &>/dev/null; then
            WEB_USER="nginx"
            WEB_GROUP="nginx"
        elif id "apache" &>/dev/null; then
            WEB_USER="apache"
            WEB_GROUP="apache"
        elif id "_www" &>/dev/null; then
            WEB_USER="_www"
            WEB_GROUP="_www"
        else
            WEB_USER=$(logname 2>/dev/null || echo "nobody")
            WEB_GROUP=$(id -gn "$WEB_USER" 2>/dev/null || echo "nobody")
        fi
    fi
    echo -e "${CYAN}[i] Execution mode: ${BOLD}Root / Production VPS${NC} (Web user: ${BOLD}$WEB_USER:$WEB_GROUP${NC})"
else
    WEB_USER=$(whoami)
    WEB_GROUP=$(id -gn 2>/dev/null || echo "$WEB_USER")
    echo -e "${CYAN}[i] Execution mode: ${BOLD}Non-root / Local Development${NC} (User: ${BOLD}$WEB_USER${NC})"
fi

# ------------------------------------------------------------------------------
# 2. PHP & Extension Dependency Verification
# ------------------------------------------------------------------------------
echo ""
echo -e "${BOLD}--> [1/6] Verifying PHP Engine & Extensions...${NC}"

if ! command -v php >/dev/null 2>&1; then
    echo -e "${RED}[✘] Error: PHP CLI is not installed or not in PATH.${NC}"
    echo "    Please install PHP (version 7.4 or 8.0+ recommended):"
    echo "      Ubuntu/Debian: sudo apt update && sudo apt install -y php-cli php-sqlite3 php-curl php-mbstring"
    echo "      RHEL/CentOS:   sudo dnf install -y php-cli php-pdo php-mbstring"
    exit 1
fi

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
echo -e "${GREEN}[✔] PHP CLI detected:${NC} PHP $PHP_VER"

# Check required extensions
MISSING_EXTS=()
for ext in sqlite3 pdo json mbstring curl fileinfo openssl; do
    if ! php -m | grep -qi "^$ext$"; then
        MISSING_EXTS+=("$ext")
    fi
done

if [ ${#MISSING_EXTS[@]} -gt 0 ]; then
    echo -e "${YELLOW}[!] Warning: The following PHP extensions were not detected: ${MISSING_EXTS[*]}${NC}"
    echo "    On Ubuntu/Debian, install them via:"
    echo "      sudo apt-get install -y php-sqlite3 php-curl php-mbstring"
else
    echo -e "${GREEN}[✔] All essential PHP extensions verified:${NC} sqlite3, curl, mbstring, json, fileinfo, openssl"
fi

# ------------------------------------------------------------------------------
# 3. Directory Structure Creation
# ------------------------------------------------------------------------------
echo ""
echo -e "${BOLD}--> [2/6] Verifying & Creating Directory Structure...${NC}"

REQUIRED_DIRS=(
    "db"
    "config"
    "img/uploads"
    "uploads/mods"
    "uploads/mod_images"
    "logs"
    "scratch"
)

for dir in "${REQUIRED_DIRS[@]}"; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        echo -e "${GREEN}[+] Created directory:${NC} $dir/"
    else
        echo -e "${GREEN}[✔] Directory exists:${NC} $dir/"
    fi
done

# ------------------------------------------------------------------------------
# 4. Configuration Baselines (.env & JSON configs)
# ------------------------------------------------------------------------------
echo ""
echo -e "${BOLD}--> [3/6] Initializing Configuration Baselines...${NC}"

# Helper: Ensure an environment variable exists in .env, append with default if missing
ensure_env_setting() {
    local key="$1"
    local val="$2"
    local comment="$3"
    if [ -f ".env" ] && ! grep -qE "^\s*${key}\s*=" .env 2>/dev/null; then
        if [ -n "$comment" ]; then
            echo "" >> .env
            echo "# $comment" >> .env
        fi
        echo "${key}=\"${val}\"" >> .env
        echo -e "${GREEN}[+] Added missing setting to .env:${NC} ${BOLD}${key}=\"${val}\"${NC}"
    fi
}

# Helper: Update or insert an environment variable in .env
set_env_setting() {
    local key="$1"
    local val="$2"
    if [ -f ".env" ]; then
        if grep -qE "^\s*${key}\s*=" .env 2>/dev/null; then
            sed -i.bak -E "s|^\s*${key}\s*=.*|${key}=\"${val}\"|" .env && rm -f .env.bak
        else
            echo "${key}=\"${val}\"" >> .env
        fi
    fi
}

# Helper: Update a key in config/site.json
set_site_json_setting() {
    local key="$1"
    local val="$2"
    php -r "
        \$path = 'config/site.json';
        if (file_exists(\$path)) {
            \$d = @json_decode(file_get_contents(\$path), true) ?: [];
            \$d['$key'] = '$val';
            file_put_contents(\$path, json_encode(\$d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    " 2>/dev/null || true
}

# .env check
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        cp .env.example .env
        echo -e "${GREEN}[+] Created .env from .env.example${NC}"
    else
        cat > .env << 'ENV_EOF'
NEWSERV_API_URL="http://127.0.0.1:8443"
NEWSERV_PLAYERS_DIR="/opt/newserv/system/players"
NEWSERV_COMMAND_PREFIX="$"
SERVER_NAME="PSOBB.IO"
SERVER_ADDRESS="psobb.io"
SERVER_TAGLINE="Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience."
EXP_RATE="1x"
DROP_RATE="1x"
MESETA_RATE="1x"
ENV_EOF
        echo -e "${GREEN}[+] Generated baseline .env file${NC}"
    fi
else
    echo -e "${GREEN}[✔] .env file exists${NC}"
fi

# Ensure all essential settings are defined in .env
ensure_env_setting "NEWSERV_API_URL" "http://127.0.0.1:8443" "NewServ REST API base URL"
ensure_env_setting "NEWSERV_PLAYERS_DIR" "/opt/newserv/system/players" "NewServ player save profiles directory (.psochar / .psobank)"
ensure_env_setting "NEWSERV_COMMAND_PREFIX" "$" "NewServ chat command prefix"
ensure_env_setting "SERVER_NAME" "PSOBB.IO" "Public server brand name"
ensure_env_setting "SERVER_ADDRESS" "psobb.io" "Public server domain or IP address"
ensure_env_setting "SERVER_TAGLINE" "Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience." "Public server descriptive tagline"
ensure_env_setting "EXP_RATE" "1x" "Displayed EXP multiplier"
ensure_env_setting "DROP_RATE" "1x" "Displayed Rare Drop multiplier"
ensure_env_setting "MESETA_RATE" "1x" "Displayed Meseta multiplier"

# config/site.json
if [ ! -f "config/site.json" ]; then
    if [ -f "config/site.example.json" ]; then
        cp config/site.example.json config/site.json
        echo -e "${GREEN}[+] Created config/site.json from config/site.example.json${NC}"
    else
        cat > config/site.json << 'SITE_EOF'
{
    "server_name": "PSOBB.IO",
    "server_address": "psobb.io",
    "server_tagline": "Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience.",
    "default_language": "auto",
    "hero_logo_url": "/img/header_logo.png",
    "exp_rate": "1x",
    "drop_rate": "1x",
    "meseta_rate": "1x",
    "discord_server": "https://discord.gg/28s84HJXha",
    "newserv_players_dir": "/opt/newserv/system/players",
    "enable_registration": true,
    "enable_bounties": true,
    "enable_lfg": true,
    "enable_mods": true,
    "enable_quest_editor": true,
    "enable_discord_oauth": true,
    "client_windows_url": "/downloads/PSOBBIO-Setup_1.25.13b.exe",
    "client_mac_url": "/downloads/PSOBBIO_125.13.dmg",
    "client_raw_url": "/downloads/PSOBBIO-Linux_1.25.13.zip"
}
SITE_EOF
        echo -e "${GREEN}[+] Created default config/site.json${NC}"
    fi
else
    echo -e "${GREEN}[✔] config/site.json exists${NC}"
    # Verify essential keys exist in config/site.json
    php -r "
        \$path = 'config/site.json';
        if (file_exists(\$path)) {
            \$d = @json_decode(file_get_contents(\$path), true) ?: [];
            \$changed = false;
            \$defaults = [
                'server_name' => 'PSOBB.IO',
                'server_address' => 'psobb.io',
                'server_tagline' => 'Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience.',
                'newserv_players_dir' => '/opt/newserv/system/players'
            ];
            foreach (\$defaults as \$k => \$v) {
                if (!isset(\$d[\$k])) {
                    \$d[\$k] = \$v;
                    \$changed = true;
                }
            }
            if (\$changed) {
                file_put_contents(\$path, json_encode(\$d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }
    " 2>/dev/null || true
fi

# config/theme.json
if [ ! -f "config/theme.json" ]; then
    cat > config/theme.json << 'THEME_EOF'
{
    "active_preset": "classic",
    "allow_user_override": true,
    "discord_server": "https://discord.gg/28s84HJXha",
    "discord_invite_url": "https://discord.gg/28s84HJXha"
}
THEME_EOF
    echo -e "${GREEN}[+] Created default config/theme.json${NC}"
else
    echo -e "${GREEN}[✔] config/theme.json exists${NC}"
fi

# config/about.json
if [ ! -f "config/about.json" ]; then
    cat > config/about.json << 'ABOUT_EOF'
{
    "hero_title": "About %s",
    "hero_subtitle": "Welcome to the ultimate custom Phantasy Star Online Blue Burst server. Our mission is to seamlessly bridge classic 2004 Sega dreamscape nostalgia with bleeding-edge modern web capabilities, automated game services, and advanced AI integration.",
    "command_deck_title": "%s Command Deck",
    "show_features": true,
    "show_tech_specs": true,
    "crew": [
        {
            "id": "liquidspikes",
            "name": "LiquidSpikes",
            "role": "Root Administrator & System Architect",
            "specialty": "Core Backend & Web Integration",
            "icon": "fas fa-crown",
            "theme": "admin-card",
            "bio": "Server builder and administrator managing infrastructure and web portals."
        }
    ]
}
ABOUT_EOF
    echo -e "${GREEN}[+] Created default config/about.json${NC}"
else
    echo -e "${GREEN}[✔] config/about.json exists${NC}"
fi

# ------------------------------------------------------------------------------
# 5. Database Schema Initialization & Integrity
# ------------------------------------------------------------------------------
echo ""
echo -e "${BOLD}--> [4/6] Initializing SQLite Database & Schemas...${NC}"

if [ -f "db/init_db.php" ]; then
    php db/init_db.php
    echo -e "${GREEN}[✔] SQLite database initialized and verified at db/website.db${NC}"
else
    echo -e "${RED}[✘] Error: db/init_db.php not found!${NC}"
    exit 1
fi

# ------------------------------------------------------------------------------
# 6. NewServ Game Server Connectivity & Player Data Setup
# ------------------------------------------------------------------------------
echo ""
echo -e "${BOLD}--> [5/6] Confirming Access to NewServ Game Server...${NC}"

# Resolve NewServ URL from .env or config/site.json
NEWSERV_URL="http://127.0.0.1:8443"
if [ -f ".env" ]; then
    EXTRACTED_URL=$(grep -E '^\s*NEWSERV_API_URL\s*=' .env | cut -d '=' -f2- | tr -d '"' | tr -d "'" | tr -d '[:space:]')
    if [ -n "$EXTRACTED_URL" ]; then
        NEWSERV_URL="$EXTRACTED_URL"
    fi
fi

echo -e "Testing NewServ REST API at: ${BOLD}$NEWSERV_URL${NC} ..."

NEWSERV_REACHABLE=0
SUMMARY_OUTPUT=""
SERVER_OUTPUT=""
if command -v curl >/dev/null 2>&1; then
    SUMMARY_OUTPUT=$(curl -s -m 3 "$NEWSERV_URL/y/summary" 2>/dev/null || true)
    SERVER_OUTPUT=$(curl -s -m 3 "$NEWSERV_URL/y/server" 2>/dev/null || true)
elif command -v php >/dev/null 2>&1; then
    SUMMARY_OUTPUT=$(php -r "
        \$ctx = stream_context_create(['http' => ['timeout' => 3]]);
        echo @file_get_contents('$NEWSERV_URL/y/summary', false, \$ctx) ?: '';
    " 2>/dev/null || true)
    SERVER_OUTPUT=$(php -r "
        \$ctx = stream_context_create(['http' => ['timeout' => 3]]);
        echo @file_get_contents('$NEWSERV_URL/y/server', false, \$ctx) ?: '';
    " 2>/dev/null || true)
fi

if [ -n "$SUMMARY_OUTPUT" ] && [ "$SUMMARY_OUTPUT" != "" ]; then
    NEWSERV_REACHABLE=1
    echo -e "${GREEN}[✔] SUCCESS: NewServ API is ONLINE & RESPONDING!${NC}"
    
    # Try parsing server info
    if command -v php >/dev/null 2>&1; then
        php -r "
            \$data = json_decode('$SUMMARY_OUTPUT', true);
            if (is_array(\$data)) {
                \$clients = isset(\$data['num_clients']) ? \$data['num_clients'] : (isset(\$data['clients']) ? count(\$data['clients']) : 'N/A');
                \$uptime = \$data['uptime'] ?? \$data['server_uptime'] ?? 'active';
                echo '    - Active Clients: ' . \$clients . PHP_EOL;
                echo '    - Server Telemetry: Active and responding' . PHP_EOL;
            }
        " 2>/dev/null || true
    fi

    # Query ServerName from /y/server if available
    NEWSERV_SERVER_NAME=""
    if [ -n "$SERVER_OUTPUT" ]; then
        NEWSERV_SERVER_NAME=$(php -r "
            \$data = @json_decode('$SERVER_OUTPUT', true);
            if (is_array(\$data) && !empty(\$data['ServerName'])) {
                echo trim(\$data['ServerName']);
            }
        " 2>/dev/null || true)
    fi

    if [ -n "$NEWSERV_SERVER_NAME" ]; then
        echo -e "${GREEN}[✔] Auto-detected Server Name from NewServ API:${NC} ${BOLD}$NEWSERV_SERVER_NAME${NC}"
        CURRENT_SRV_NAME=$(grep -E '^\s*SERVER_NAME\s*=' .env 2>/dev/null | cut -d '=' -f2- | tr -d '"' | tr -d "'" | tr -d '[:space:]' || true)
        if [ "$CURRENT_SRV_NAME" = "PSOBB.IO" ] || [ -z "$CURRENT_SRV_NAME" ]; then
            if [ "$NEWSERV_SERVER_NAME" != "newserv" ] && [ "$NEWSERV_SERVER_NAME" != "PSOBB.IO" ]; then
                echo -e "    Syncing website server branding to: ${BOLD}$NEWSERV_SERVER_NAME${NC}"
                if grep -qE '^\s*SERVER_NAME\s*=' .env 2>/dev/null; then
                    sed -i.bak -E "s|^\s*SERVER_NAME\s*=.*|SERVER_NAME=\"$NEWSERV_SERVER_NAME\"|" .env && rm -f .env.bak
                fi
                php -r "
                    \$path = 'config/site.json';
                    if (file_exists(\$path)) {
                        \$d = @json_decode(file_get_contents(\$path), true) ?: [];
                        \$d['server_name'] = '$NEWSERV_SERVER_NAME';
                        file_put_contents(\$path, json_encode(\$d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                " 2>/dev/null || true
            fi
        fi
    fi
else
    echo -e "${YELLOW}[!] NOTICE: NewServ API is currently UNREACHABLE at $NEWSERV_URL${NC}"
    echo "    This is expected if your NewServ daemon is not running yet."
    echo "    To start NewServ:"
    echo "      1. Verify port in your newserv configuration (system/config.json)."
    echo "      2. Start newserv (e.g., ./newserv or systemctl start newserv)."
    echo "      3. Update NEWSERV_API_URL in .env if running on a different port or Docker host."
fi

# Interactive Configuration of Server Identity & Branding (if running interactively)
if [ -t 0 ] && [ "$NON_INTERACTIVE" -ne 1 ]; then
    echo ""
    echo -e "${BOLD}--> Configure Public Server Identity & Branding...${NC}"
    CURRENT_SRV_NAME=$(grep -E '^\s*SERVER_NAME\s*=' .env 2>/dev/null | cut -d '=' -f2- | tr -d '"' | tr -d "'" | tr -d '[:space:]' || true)
    CURRENT_SRV_NAME="${CURRENT_SRV_NAME:-PSOBB.IO}"
    read -r -p "    Enter Server Brand Name [$CURRENT_SRV_NAME]: " PROMPT_SRV_NAME
    CHOSEN_SRV_NAME="${PROMPT_SRV_NAME:-$CURRENT_SRV_NAME}"
    set_env_setting "SERVER_NAME" "$CHOSEN_SRV_NAME"
    set_site_json_setting "server_name" "$CHOSEN_SRV_NAME"
    echo -e "    ${GREEN}[✔] Server Brand Name:${NC} $CHOSEN_SRV_NAME"

    CURRENT_SRV_ADDR=$(grep -E '^\s*SERVER_ADDRESS\s*=' .env 2>/dev/null | cut -d '=' -f2- | tr -d '"' | tr -d "'" | tr -d '[:space:]' || true)
    CURRENT_SRV_ADDR="${CURRENT_SRV_ADDR:-psobb.io}"
    read -r -p "    Enter Server Domain / Host Address [$CURRENT_SRV_ADDR]: " PROMPT_SRV_ADDR
    CHOSEN_SRV_ADDR="${PROMPT_SRV_ADDR:-$CURRENT_SRV_ADDR}"
    set_env_setting "SERVER_ADDRESS" "$CHOSEN_SRV_ADDR"
    set_site_json_setting "server_address" "$CHOSEN_SRV_ADDR"
    echo -e "    ${GREEN}[✔] Server Domain / Address:${NC} $CHOSEN_SRV_ADDR"

    if [ "$NEWSERV_REACHABLE" -eq 0 ]; then
        read -r -p "    Enter NewServ REST API URL [$NEWSERV_URL]: " PROMPT_API_URL
        if [ -n "$PROMPT_API_URL" ] && [ "$PROMPT_API_URL" != "$NEWSERV_URL" ]; then
            set_env_setting "NEWSERV_API_URL" "$PROMPT_API_URL"
            NEWSERV_URL="$PROMPT_API_URL"
            echo -e "    ${GREEN}[✔] Updated NewServ API URL:${NC} $PROMPT_API_URL"
        fi
    fi
fi

# Configure NewServ Player Directory
CURRENT_PLAYERS_DIR="${NEWSERV_PLAYERS_DIR:-}"
if [ -z "$CURRENT_PLAYERS_DIR" ] && [ -f ".env" ]; then
    CURRENT_PLAYERS_DIR=$(grep -E '^\s*NEWSERV_PLAYERS_DIR\s*=' .env | cut -d '=' -f2- | tr -d '"' | tr -d "'" | tr -d '[:space:]')
fi
if [ -z "$CURRENT_PLAYERS_DIR" ] && [ -f "config/site.json" ]; then
    CURRENT_PLAYERS_DIR=$(php -r '$c=@json_decode(file_get_contents("config/site.json"), true); echo $c["newserv_players_dir"] ?? "";' 2>/dev/null || true)
fi
if [ -z "$CURRENT_PLAYERS_DIR" ]; then
    CURRENT_PLAYERS_DIR="/opt/newserv/system/players"
fi

echo ""
echo -e "${BOLD}--> Configure NewServ Player Directory (.psochar / .psobank files)...${NC}"
echo "    The website reads offline character slots, bank storage, and material usage"
echo "    from NewServ's player save directory (system/players/)."

PROMPTED_PLAYERS_DIR=""
if [ -t 0 ] && [ "$NON_INTERACTIVE" -ne 1 ]; then
    read -r -p "    Enter path to NewServ system/players directory [$CURRENT_PLAYERS_DIR]: " PROMPTED_PLAYERS_DIR
fi

PLAYERS_DIR="${PROMPTED_PLAYERS_DIR:-$CURRENT_PLAYERS_DIR}"
PLAYERS_DIR=$(echo "$PLAYERS_DIR" | tr '\\' '/')

# Save to .env and config/site.json using helper functions
set_env_setting "NEWSERV_PLAYERS_DIR" "$PLAYERS_DIR"
set_site_json_setting "newserv_players_dir" "$PLAYERS_DIR"
echo -e "${GREEN}[✔] Saved NewServ player directory configuration:${NC} $PLAYERS_DIR"

if [ -d "$PLAYERS_DIR" ]; then
    echo -e "${GREEN}[✔] Verified directory exists at:${NC} $PLAYERS_DIR"
    if [ "$IS_ROOT" -eq 1 ]; then
        echo "    Configuring group read-access for web server ($WEB_USER)..."
        chown -R root:"$WEB_GROUP" "$PLAYERS_DIR" 2>/dev/null || true
        chmod 2750 "$PLAYERS_DIR" 2>/dev/null || true
        find "$PLAYERS_DIR" -type f -exec chmod 640 {} + 2>/dev/null || true
        echo -e "${GREEN}[✔] Read-access configured on player profiles.${NC}"
    fi
else
    echo -e "${CYAN}[i] Notice: Directory does not exist yet at: $PLAYERS_DIR${NC}"
    echo "    (Configuration saved. Ensure NewServ creates it or update NEWSERV_PLAYERS_DIR in .env or the Web Admin Settings)"
fi

# ------------------------------------------------------------------------------
# 7. Administrator Account Status Check
# ------------------------------------------------------------------------------
echo ""
echo -e "${BOLD}--> [6/6] Checking Administrator Account Status...${NC}"

ADMIN_COUNT=0
if [ -f "db/website.db" ]; then
    ADMIN_COUNT=$(php -r "
        \$db = new SQLite3('db/website.db');
        \$res = \$db->query('SELECT count(*) as c FROM users WHERE is_admin = 1');
        \$row = \$res ? \$res->fetchArray(SQLITE3_ASSOC) : null;
        echo \$row ? \$row['c'] : 0;
    " 2>/dev/null || echo 0)
fi

if [ "$ADMIN_COUNT" -gt 0 ]; then
    ADMIN_NAMES=$(php -r "
        \$db = new SQLite3('db/website.db');
        \$res = \$db->query('SELECT username FROM users WHERE is_admin = 1 LIMIT 3');
        \$names = [];
        while (\$r = \$res->fetchArray(SQLITE3_ASSOC)) \$names[] = \$r['username'];
        echo implode(', ', \$names);
    " 2>/dev/null || echo "admin")
    echo -e "${GREEN}[✔] Administrator account(s) detected:${NC} $ADMIN_NAMES ($ADMIN_COUNT total)"
else
    echo -e "${YELLOW}[!] No administrator accounts detected in website.db.${NC}"
    echo "    You can promote an existing registered player to Admin at any time via:"
    echo -e "      ${BOLD}php promote_admin.php <username>${NC}"
fi

# ------------------------------------------------------------------------------
# 8. File & Directory Permission Enforcement
# ------------------------------------------------------------------------------
echo ""
echo -e "${BOLD}--> Enforcing File and Directory Permissions...${NC}"

if [ "$IS_ROOT" -eq 1 ]; then
    echo "Applying ownership ($WEB_USER:$WEB_GROUP)..."
    chown -R "$WEB_USER:$WEB_GROUP" .
fi

# Directory permissions
find . -type d -not -path '*/.*' -exec chmod 755 {} \;
# File permissions
find . -type f -not -path '*/.*' -exec chmod 644 {} \;

# Writable directories for web user
chmod -R 775 uploads db img/uploads logs scratch 2>/dev/null || true
if [ -f "db/website.db" ]; then
    chmod 664 db/website.db
fi
if [ -f "config/site.json" ]; then
    chmod 664 config/site.json
fi
if [ -f "config/theme.json" ]; then
    chmod 664 config/theme.json
fi
if [ -f "config/about.json" ]; then
    chmod 664 config/about.json
fi

# Keep scripts executable
chmod 755 setup.sh setup_permissions.sh 2>/dev/null || true
if [ -f "install-deck.sh" ]; then
    chmod 755 install-deck.sh
fi

echo -e "${GREEN}[✔] File and directory permissions successfully applied.${NC}"

# ------------------------------------------------------------------------------
# Summary Output
# ------------------------------------------------------------------------------
echo ""
echo -e "${CYAN}${BOLD}======================================================================"
echo "    SETUP & DIAGNOSTIC COMPLETE — SITE IS READY!                     "
echo "======================================================================${NC}"
echo ""
echo -e "  ${BOLD}Web Portal:${NC}          http://localhost/ (or your domain/IP)"
echo -e "  ${BOLD}Admin Control Panel:${NC} http://localhost/admin/site_settings.php"
echo -e "  ${BOLD}NewServ API Status:${NC}  $([ $NEWSERV_REACHABLE -eq 1 ] && echo -e "${GREEN}Connected ($NEWSERV_URL)${NC}" || echo -e "${YELLOW}Unreachable (check NewServ daemon)${NC}")"
echo -e "  ${BOLD}Database:${NC}            SQLite (all platform tables verified at db/website.db)"
echo ""
echo -e "  ${BOLD}Quick Start for Local Dev:${NC}"
echo -e "    ${CYAN}php -S 0.0.0.0:8000${NC}"
echo ""
echo -e "${GREEN}${BOLD}Everything required to run the portal has been established.${NC}"
echo ""
