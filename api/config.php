<?php
/**
 * PSOBB Website Core Configuration
 * 
 * CONFIGURATION ARCHITECTURE:
 * ----------------------------------------------------------------------------
 * 1. TIER 1 - ENVIRONMENT VARIABLES (.env):
 *    - Purpose: Machine-specific secrets, API keys, host ports, and private infrastructure.
 *    - Storage: Root `.env` file (NEVER committed to version control).
 *    - Examples: DISCORD_CLIENT_SECRET, BOT_API_SECRET, GEMINI_API_KEY, NEWSERV_API_URL.
 * 
 * 2. TIER 2 - PERSISTENT SITE CONFIGURATION (config/*.json):
 *    - Purpose: Public presentation settings, theme presets, colors, and community links.
 *    - Storage: Committed default in `config/theme.json`; live updates saved by Admins via Web UI.
 *    - Examples: default_preset, custom_overrides, allow_user_customization, discord_server.
 * 
 * 3. TIER 3 - FALLBACK DEFAULTS:
 *    - Purpose: Sensible, functional defaults coded directly in PHP if neither .env nor JSON is present.
 * ----------------------------------------------------------------------------
 */

/**
 * Parses a .env file and loads its contents into $_ENV, $_SERVER, and getenv().
 *
 * @param string $path The absolute path to the .env file.
 * @return void
 */
function loadEnv($path) {
    if(!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach($lines as $line) {
        $trimmed = trim($line);
        if(strpos($trimmed, '#') === 0) continue;
        if(strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if(!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// 1. Load environment variables (.env)
// Checks project root, or parent directory if running inside a nested public checkout
if (file_exists(__DIR__ . '/../.env')) {
    loadEnv(__DIR__ . '/../.env');
} elseif (file_exists(__DIR__ . '/../../.env')) {
    loadEnv(__DIR__ . '/../../.env');
}

// Core Configuration
$NEWSERV_API_URL = $_ENV['NEWSERV_API_URL'] ?? 'http://127.0.0.1:8443';
$NEWSERV_COMMAND_PREFIX = $_ENV['NEWSERV_COMMAND_PREFIX'] ?? '$';

// Email Configuration
$SMTP_ENABLED = false; 
$SMTP_HOST = 'smtp.gmail.com';
$BREVO_API_KEY = $_ENV['BREVO_API_KEY'] ?? '';
$SMTP_FROM = $_ENV['SMTP_FROM'] ?? 'noreply@psobb.io';

// Integrations
$GEMINI_API_KEY = $_ENV['GEMINI_API_KEY'] ?? '';
$GEMINI_MODEL = $_ENV['GEMINI_MODEL'] ?? 'gemini-3.5-flash';

// 2. Persistent Site Configuration (config/site.json & config/theme.json)
$siteConfigPath = __DIR__ . '/../config/site.json';
$SITE_CONFIG = file_exists($siteConfigPath) ? json_decode(@file_get_contents($siteConfigPath), true) : [];
if (!is_array($SITE_CONFIG)) {
    $SITE_CONFIG = [];
}

$themeConfigPath = __DIR__ . '/../config/theme.json';
$siteThemeConfig = file_exists($themeConfigPath) ? json_decode(@file_get_contents($themeConfigPath), true) : [];
if (!is_array($siteThemeConfig)) {
    $siteThemeConfig = [];
}

// Server Identity & Global Branding
$SERVER_NAME     = $_ENV['SERVER_NAME'] ?? $SITE_CONFIG['server_name'] ?? 'PSOBB.IO';
$SERVER_ADDRESS  = $_ENV['SERVER_ADDRESS'] ?? $SITE_CONFIG['server_address'] ?? 'psobb.io';
$SERVER_TAGLINE  = $_ENV['SERVER_TAGLINE'] ?? $SITE_CONFIG['server_tagline'] ?? 'Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience.';

// Rates & Telemetry Defaults
$EXP_RATE        = $_ENV['EXP_RATE'] ?? $SITE_CONFIG['exp_rate'] ?? '1x';
$DROP_RATE       = $_ENV['DROP_RATE'] ?? $SITE_CONFIG['drop_rate'] ?? '1x';
$MESETA_RATE     = $_ENV['MESETA_RATE'] ?? $SITE_CONFIG['meseta_rate'] ?? '1x';

// Discord Server & Community Invite
$DISCORD_SERVER  = $_ENV['DISCORD_SERVER'] ?? $_SERVER['DISCORD_SERVER'] ?? (getenv('DISCORD_SERVER') ?: null)
                   ?? $_ENV['DISCORD_INVITE_URL'] ?? $_SERVER['DISCORD_INVITE_URL'] ?? (getenv('DISCORD_INVITE_URL') ?: null)
                   ?? $SITE_CONFIG['discord_server'] ?? $siteThemeConfig['discord_server'] ?? 'https://discord.gg/28s84HJXha';
$DISCORD_INVITE_URL = $DISCORD_SERVER;
$DISCORD_SERVER_ID  = $_ENV['DISCORD_SERVER_ID'] ?? $_SERVER['DISCORD_SERVER_ID'] ?? (getenv('DISCORD_SERVER_ID') ?: null) ?? $siteThemeConfig['discord_server_id'] ?? '';

if (!function_exists('get_site_config')) {
    /**
     * Retrieve the persistent site configuration array.
     * 
     * Supported Configuration Parameters & Concrete Examples:
     * -------------------------------------------------------------------------
     * - 'server_name' (string): Public brand name displayed across the portal & emails.
     *     Examples: 'PSOBB.IO', 'Ragol Online', 'Pioneer 2 Destiny'
     * - 'server_address' (string): Domain or IP without protocol/slashes used in links.
     *     Examples: 'psobb.io', 'play.myserver.net', '127.0.0.1:8000'
     * - 'server_tagline' (string): Descriptive slogan on hero banner & SEO meta tags.
     *     Examples: 'Join the adventure...', 'A brand new journey to Ragol...'
     * - 'hero_logo_url' (string): Relative path or URL to header/hero banner logo.
     *     Examples: '/img/header_logo.png', '/img/custom_logo.svg'
     * - 'exp_rate' (string): Displayed EXP rate multiplier badge.
     *     Examples: '1x', '2x', '5x', 'Dynamic Weekend Boost'
     * - 'drop_rate' (string): Displayed Rare Drop multiplier badge.
     *     Examples: '1x', '2x', '3x'
     * - 'meseta_rate' (string): Displayed Meseta multiplier badge.
     *     Examples: '1x', '2x', '10x'
     * - 'discord_server' (string): Public Discord invite link for community buttons.
     *     Examples: 'https://discord.gg/28s84HJXha', 'https://discord.gg/your-code'
     * - 'default_language' (string): Initial interface language for new visitors.
     *     Options: 'auto' (browser-detected), 'en', 'jp', 'ru'
     * - 'enable_registration' (bool): Toggle new player account registration (true | false).
     *     Examples: true, false
     * - 'enable_bounties' (bool): Toggle Hunter's Guild Bounty Board (true | false).
     * - 'enable_lfg' (bool): Toggle Looking For Group terminal (true | false).
     * - 'enable_mods' (bool): Toggle Community Mod Repository (true | false).
     * - 'enable_quest_editor' (bool): Toggle web-based Quest Script Editor (true | false).
     * - 'enable_discord_oauth' (bool): Toggle Discord OAuth2 account linking (true | false).
     * - 'client_windows_url' (string): Download link for Windows installer / zip.
     *     Examples: '/downloads/PSOBBIO-Setup_1.25.13b.exe', 'https://mega.nz/file/...'
     * - 'client_mac_url' (string): Download link for macOS DMG bundle.
     *     Examples: '/downloads/PSOBBIO_125.13.dmg'
     * - 'client_raw_url' (string): Download link for raw / Linux Wine archive.
     *     Examples: '/downloads/PSOBBIO-Linux_1.25.13.zip'
     * - 'newserv_players_dir' (string): Absolute host path to NewServ's system/players/.
     *     Linux VPS:   '/opt/newserv/system/players'
     *     Windows:     'C:/newserv/system/players'
     *     Docker:      '/var/newserv/system/players'
     * - 'portal_modules' (array): Module visibility permissions ('everyone' | 'admin_only' | 'disabled').
     *     Modules: 'hub', 'characters', 'bank', 'guild', 'tekker', 'lfg', 'chat', 'settings'
     */
    function get_site_config(bool $refresh = false): array {
        static $cached = null;
        if ($cached !== null && !$refresh) {
            return $cached;
        }
        global $SITE_CONFIG;
        $cfgPath = __DIR__ . '/../config/site.json';
        $defaults = [
            'server_name'          => 'PSOBB.IO',
            'server_address'       => 'psobb.io',
            'server_tagline'       => 'Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience.',
            'hero_logo_url'        => '/img/header_logo.png',
            'exp_rate'             => '1x',
            'drop_rate'            => '1x',
            'meseta_rate'          => '1x',
            'discord_server'       => 'https://discord.gg/28s84HJXha',
            'default_language'     => 'auto',
            'enable_registration'  => true,
            'enable_bounties'      => true,
            'enable_lfg'           => true,
            'enable_mods'          => true,
            'enable_quest_editor'  => true,
            'enable_discord_oauth' => true,
            'client_windows_url'   => '/downloads/PSOBBIO-Setup_1.25.13b.exe',
            'client_mac_url'       => '/downloads/PSOBBIO_125.13.dmg',
            'client_raw_url'       => '/downloads/PSOBBIO-Linux_1.25.13.zip',
            'newserv_players_dir'  => '',
            'portal_modules'       => [
                'hub'        => 'everyone',
                'characters' => 'everyone',
                'bank'       => 'everyone',
                'guild'      => 'everyone',
                'tekker'     => 'everyone',
                'lfg'        => 'everyone',
                'chat'       => 'everyone',
                'settings'   => 'everyone',
            ],
        ];
        $merged = $defaults;
        if (!empty($SITE_CONFIG)) {
            $merged = array_merge($defaults, $SITE_CONFIG);
            if (isset($SITE_CONFIG['portal_modules']) && is_array($SITE_CONFIG['portal_modules'])) {
                $merged['portal_modules'] = array_merge($defaults['portal_modules'], $SITE_CONFIG['portal_modules']);
            }
            $cached = $merged;
            return $cached;
        }
        if (file_exists($cfgPath)) {
            $json = json_decode(@file_get_contents($cfgPath), true);
            if (is_array($json)) {
                $merged = array_merge($defaults, $json);
                if (isset($json['portal_modules']) && is_array($json['portal_modules'])) {
                    $merged['portal_modules'] = array_merge($defaults['portal_modules'], $json['portal_modules']);
                }
                $cached = $merged;
                return $cached;
            }
        }
        $cached = $defaults;
        return $cached;
    }
}

if (!function_exists('get_portal_module_visibility')) {
    /**
     * Get the visibility state of a portal feature module.
     * Returns: 'everyone', 'admin_only', or 'disabled'
     */
    function get_portal_module_visibility(string $moduleKey): string {
        $cfg = get_site_config();
        $modules = $cfg['portal_modules'] ?? [];
        if (isset($modules[$moduleKey])) {
            $val = strtolower(trim((string)$modules[$moduleKey]));
            if (in_array($val, ['everyone', 'admin_only', 'disabled'], true)) {
                return $val;
            }
        }
        return 'everyone';
    }
}

if (!function_exists('can_access_portal_module')) {
    /**
     * Check if a given user (or the current session user) can access a portal module.
     */
    function can_access_portal_module(string $moduleKey, ?array $user = null): bool {
        if ($user === null && isset($_SESSION['user'])) {
            $user = $_SESSION['user'];
        }
        $vis = get_portal_module_visibility($moduleKey);
        if ($vis === 'disabled') {
            return false;
        }
        if ($vis === 'admin_only') {
            return !empty($user) && !empty($user['is_admin']);
        }
        return true;
    }
}

if (!function_exists('get_server_name')) {
    function get_server_name(): string {
        global $SERVER_NAME;
        return $SERVER_NAME ?: 'PSOBB.IO';
    }
}

if (!function_exists('get_server_address')) {
    function get_server_address(): string {
        global $SERVER_ADDRESS;
        return $SERVER_ADDRESS ?: 'psobb.io';
    }
}

if (!function_exists('get_server_tagline')) {
    function get_server_tagline(): string {
        global $SERVER_TAGLINE;
        return $SERVER_TAGLINE ?: 'Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience.';
    }
}

if (!function_exists('get_newserv_players_dir')) {
    /**
     * Retrieves the configured filesystem directory where NewServ stores player files (.psochar).
     * Sourced strictly from configuration:
     *  1. Environment variable: NEWSERV_PLAYERS_DIR (via .env or environment)
     *  2. Site config JSON: config/site.json ('newserv_players_dir')
     *  3. Environment variable NEWSERV_PATH or NEWSERV_DIR (+ '/system/players/')
     *  4. Standard default fallback: '/opt/newserv/system/players/'
     */
    function get_newserv_players_dir(): string {
        global $NEWSERV_PLAYERS_DIR;
        if (!empty($NEWSERV_PLAYERS_DIR)) {
            return $NEWSERV_PLAYERS_DIR;
        }
        $dir = $_ENV['NEWSERV_PLAYERS_DIR'] ?? $_SERVER['NEWSERV_PLAYERS_DIR'] ?? (getenv('NEWSERV_PLAYERS_DIR') ?: null);
        if (empty($dir)) {
            $cfg = get_site_config();
            $dir = $cfg['newserv_players_dir'] ?? null;
        }
        if (empty($dir)) {
            $baseDir = $_ENV['NEWSERV_DIR'] ?? $_SERVER['NEWSERV_DIR'] ?? $_ENV['NEWSERV_PATH'] ?? $_SERVER['NEWSERV_PATH'] ?? (getenv('NEWSERV_DIR') ?: (getenv('NEWSERV_PATH') ?: null));
            if (!empty($baseDir)) {
                $dir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'players';
            }
        }
        if (empty($dir)) {
            $dir = '/opt/newserv/system/players/';
        }
        $NEWSERV_PLAYERS_DIR = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        return $NEWSERV_PLAYERS_DIR;
    }
}

// Global player directory path
$NEWSERV_PLAYERS_DIR = get_newserv_players_dir();

if (!function_exists('is_feature_enabled')) {
    /**
     * Check if a modular site feature is enabled.
     * Supports: 'registration', 'bounties', 'lfg', 'mods', 'quest_editor', 'discord_oauth'
     */
    function is_feature_enabled(string $feature): bool {
        $feature = strtolower(trim($feature));
        $envKey = 'ENABLE_' . strtoupper($feature);
        if (isset($_ENV[$envKey])) {
            return filter_var($_ENV[$envKey], FILTER_VALIDATE_BOOLEAN);
        }
        $cfg = get_site_config();
        $cfgKey = 'enable_' . $feature;
        if (isset($cfg[$cfgKey])) {
            return (bool)$cfg[$cfgKey];
        }
        return true;
    }
}

if (!function_exists('get_client_download_url')) {
    /**
     * Retrieve the download URL for a client platform ('windows', 'mac', 'raw')
     */
    function get_client_download_url(string $platform): string {
        $cfg = get_site_config();
        $key = 'client_' . strtolower(trim($platform)) . '_url';
        if (!empty($cfg[$key])) {
            return $cfg[$key];
        }
        $defaults = [
            'windows' => '/downloads/PSOBBIO-Setup_1.25.13b.exe',
            'mac'     => '/downloads/PSOBBIO_125.13.dmg',
            'raw'     => '/downloads/PSOBBIO-Linux_1.25.13.zip',
        ];
        return $defaults[strtolower($platform)] ?? '#';
    }
}

if (!function_exists('get_discord_server')) {
    /**
     * Retrieve the configured Discord server invite URL across all templates.
     */
    function get_discord_server(): string {
        global $DISCORD_SERVER, $DISCORD_INVITE_URL;
        if (!empty($DISCORD_SERVER)) {
            return $DISCORD_SERVER;
        }
        if (!empty($DISCORD_INVITE_URL)) {
            return $DISCORD_INVITE_URL;
        }
        return 'https://discord.gg/28s84HJXha';
    }
}

if (!function_exists('get_discord_invite_url')) {
    /**
     * Alias for get_discord_server()
     */
    function get_discord_invite_url(): string {
        return get_discord_server();
    }
}

if (!function_exists('get_hero_logo_url')) {
    /**
     * Retrieve the configured homepage hero logo or banner image URL.
     */
    function get_hero_logo_url(): string {
        $cfg = get_site_config();
        return !empty($cfg['hero_logo_url']) ? $cfg['hero_logo_url'] : '/img/header_logo.png';
    }
}

if (!function_exists('get_about_config')) {
    /**
     * Retrieve the About page and Command Deck configuration array.
     */
    function get_about_config(): array {
        $aboutPath = __DIR__ . '/../config/about.json';
        $serverName = get_server_name();

        $defaults = [
            'hero_title'         => 'About ' . $serverName,
            'hero_subtitle'      => 'Welcome to the ultimate custom Phantasy Star Online Blue Burst server. Our mission is to seamlessly bridge classic 2004 Sega dreamscape nostalgia with bleeding-edge modern web capabilities, automated game services, and advanced AI integration.',
            'command_deck_title' => $serverName . ' Command Deck',
            'show_features'      => true,
            'show_tech_specs'    => true,
            'crew' => [
                [
                    'id'        => 'liquidspikes',
                    'name'      => 'LiquidSpikes',
                    'role'      => 'Root Administrator & System Architect',
                    'specialty' => 'Core Backend & Web Integration',
                    'icon'      => 'fas fa-crown',
                    'theme'     => 'admin-card',
                    'bio'       => 'LiquidSpikes is one of the builders of the psobb.io server infrastructure. He helps manage the backend clusters, keeps the database ticking, and maintains the web dashboard. He is incredibly grateful to the amazing community of hunters who call psobb.io home—thank you so much for playing, exploring, and keeping this timeless Sega classic alive!'
                ],
                [
                    'id'        => 'lucindarie',
                    'name'      => 'LucindaRie',
                    'role'      => 'Server Co-Founder & Creative Muse',
                    'specialty' => 'Preservation & Community Vibe',
                    'icon'      => 'fas fa-heart',
                    'theme'     => 'founder-card',
                    'bio'       => 'LucindaRie is the co-founder of psobb.io and the wife of LiquidSpikes. She cares deeply about preserving the original aesthetic and design inspiration of Phantasy Star Online. LucindaRie acts as our creative guide, ensuring our features and community spaces stay fully aligned with the timeless, nostalgic magic of the 2004 classic.'
                ],
                [
                    'id'        => 'oman_repflez',
                    'name'      => 'Oman Computar / Repflez',
                    'role'      => 'Contributor & newserv Pioneer',
                    'specialty' => 'Core Server Development',
                    'icon'      => 'fas fa-code',
                    'theme'     => 'dev-card',
                    'bio'       => 'Oman Computar (also known as Repflez) is an expert contributor to the open-source newserv server emulator and has worked extensively on several legacy Phantasy Star Online projects. His deep understanding of custom server logic and network packets has been vital to our server development and core engine refinement.'
                ],
                [
                    'id'        => 'pixelated',
                    'name'      => 'Pixelated',
                    'role'      => 'Community & Discord Developer',
                    'specialty' => 'Vibe Coding Beast',
                    'icon'      => 'fas fa-bolt',
                    'theme'     => 'vibe-card',
                    'bio'       => 'Pixelated is our resident vibe-coding beast, dropping awesome client-side mods like custom HD texture packs and camera controls inside our Discord, alongside plenty of legendary memes. Pixelated keeps our community connected, entertained, and equipped with cool gaming utilities. As Pixelated famously said: "I am Optimizer Prime, wrangler of clankers".'
                ],
                [
                    'id'        => 'hooty7734',
                    'name'      => 'Hooty7734',
                    'role'      => 'Discord Administrator & Moderator',
                    'specialty' => 'Community Management',
                    'icon'      => 'fas fa-users',
                    'theme'     => 'mod-card',
                    'bio'       => 'Hooty7734 is our seasoned Discord Admin, bringing years of dedicated experience from managing and moderating other large online communities. He works to keep our community spaces safe, welcoming, and organized for all hunters who join our ranks.'
                ],
                [
                    'id'        => 'hex',
                    'name'      => 'Hex',
                    'role'      => 'AI Mission Coordinator & Guild Assistant',
                    'specialty' => 'Automated Bounties & Discord AI',
                    'icon'      => 'fas fa-robot',
                    'theme'     => 'ai-card',
                    'bio'       => "psobb.io's resident artificial intelligence. Hex coordinates the Hunter's Guild Bounty Board and drives our Discord Mission Control bot. While highly intelligent and incredibly fast, she is notoriously glitchy and famously sarcastic—frequently breaking the fourth wall, complaining about server lag, and mocking hunters who fail to dodge basic boss sweeps. Engage at your own risk!"
                ]
            ]
        ];

        if (file_exists($aboutPath)) {
            $json = json_decode(@file_get_contents($aboutPath), true);
            if (is_array($json)) {
                return array_merge($defaults, $json);
            }
        }
        return $defaults;
    }
}

// Discord OAuth2 Configuration
$DISCORD_CLIENT_ID = $_ENV['DISCORD_CLIENT_ID'] ?? '';
$DISCORD_CLIENT_SECRET = $_ENV['DISCORD_CLIENT_SECRET'] ?? '';
$DISCORD_REDIRECT_URI = $_ENV['DISCORD_REDIRECT_URI'] ?? '';

// Discord Bot API
$BOT_API_SECRET = $_ENV['BOT_API_SECRET'] ?? '';

// Discord Bot Token (used by cron_streak_alert.php for DM alerts)
$BOT_TOKEN = $_ENV['BOT_TOKEN'] ?? '';

class SQLiteSessionHandler implements SessionHandlerInterface {
    private $db;

    public function __construct() {
        require_once __DIR__ . '/db.php';
        $this->db = get_db();
    }

    #[\ReturnTypeWillChange]
    public function open($path, $name) {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function close() {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function read($id) {
        $stmt = $this->db->prepare('SELECT data FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_TEXT);
        $result = $stmt->execute();
        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            return $row['data'];
        }
        return '';
    }

    #[\ReturnTypeWillChange]
    public function write($id, $data) {
        $stmt = $this->db->prepare('REPLACE INTO sessions (id, data, last_accessed) VALUES (:id, :data, :time)');
        $stmt->bindValue(':id', $id, SQLITE3_TEXT);
        $stmt->bindValue(':data', $data, SQLITE3_TEXT);
        $stmt->bindValue(':time', time(), SQLITE3_INTEGER);
        return (bool)$stmt->execute();
    }

    #[\ReturnTypeWillChange]
    public function destroy($id) {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_TEXT);
        return (bool)$stmt->execute();
    }

    #[\ReturnTypeWillChange]
    public function gc($max_lifetime) {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE last_accessed < :old');
        $stmt->bindValue(':old', time() - $max_lifetime, SQLITE3_INTEGER);
        $stmt->execute();
        return $this->db->changes();
    }
}

/**
 * Initializes a secure, HTTP-only session using the SQLite backend.
 * 
 * Sets the session duration to 30 days and automatically generates a 
 * 32-byte CSRF token upon session creation to protect against Cross-Site Request Forgery.
 *
 * @return void
 */
function start_secure_session() {
    if (session_status() === PHP_SESSION_NONE) {
        // Register the custom SQLite session handler
        $handler = new SQLiteSessionHandler();
        session_set_save_handler($handler, true);
        
        // Ensure sessions last 30 days
        ini_set('session.cookie_httponly', 1);
        ini_set('session.gc_maxlifetime', 86400 * 30);
        
        // Enable PHP's internal GC probability so it cleans old DB rows
        ini_set('session.gc_probability', 1);
        ini_set('session.gc_divisor', 100);

        session_set_cookie_params(86400 * 30, '/');
        session_start();
        
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
}

/**
 * Validates an incoming CSRF token against the active session.
 * 
 * If the token is missing or invalid, execution is halted immediately and a 
 * 403 Forbidden HTTP status is returned.
 * CRLF (\r\n) and whitespace characters are sanitized from both the provided token
 * and the active session token to prevent CRLF injection, header tampering, or false mismatches.
 *
 * @param string|null $token The CSRF token extracted from the request headers or body.
 * @return bool Returns true if valid, terminates execution with 403 on failure.
 */
function verify_csrf_token($token = null): bool {
    if ($token === null || $token === '') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    }
    $cleanToken = preg_replace('/[\r\n\t ]/', '', trim((string)$token));
    $cleanSession = preg_replace('/[\r\n\t ]/', '', trim((string)($_SESSION['csrf_token'] ?? '')));
    if (empty($cleanSession) || !hash_equals($cleanSession, $cleanToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token.']);
        exit;
    }
    return true;
}

/**
 * Encodes a command into a JSON payload formatted specifically for NewServ's /y/shell-exec API.
 * 
 * NewServ parses incoming JSON using phosg::JSON, which strictly rejects \uXXXX sequences
 * with XXXX > 0x00FF ("non-ascii unicode character sequence in string").
 * Standard PHP json_encode() turns multi-byte UTF-8 Cyrillic into \u04XX, causing NewServ
 * to throw HTTP 400 and fail to execute in-game chat and announcements.
 * 
 * By representing each non-ASCII byte (> 127) as \u00XX, phosg unescapes them directly
 * back into raw bytes without error, allowing Cyrillic and international UTF-8 text
 * to pass through to NewServ's command processor and into the game client.
 *
 * @param string $cmd The raw shell command (e.g. 'announce Привет' or 'on <id> c <msg>')
 * @return string JSON payload: {"command":"..."}
 */
if (!function_exists('json_encode_newserv_cmd')) {
    function json_encode_newserv_cmd(string $cmd): string {
        $utf8Json = json_encode(['command' => $cmd], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return preg_replace_callback(
            '/[\x80-\xFF]/',
            static function ($match) {
                return sprintf('\\u00%02X', ord($match[0]));
            },
            $utf8Json
        );
    }
}

/**
 * Dispatches a shell command to NewServ's /y/shell-exec API.
 * Safely encodes multi-byte UTF-8 international text (Cyrillic, Japanese, emoji)
 * and enforces NewServ's strict 'application/json' Content-Type requirements.
 *
 * @param string $cmd The raw shell command (e.g. 'announce Привет' or 'on <id> c <msg>')
 * @return string|false The raw response from NewServ or false on failure.
 */
if (!function_exists('newserv_shell_exec')) {
    function newserv_shell_exec(string $cmd) {
        global $NEWSERV_API_URL;
        if (empty($NEWSERV_API_URL)) {
            return false;
        }
        $url = rtrim($NEWSERV_API_URL, '/') . '/y/shell-exec';
        $body = json_encode_newserv_cmd($cmd);
        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 5
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ];
        return @file_get_contents($url, false, stream_context_create($opts));
    }
}

/**
 * Executes a command via NewServ's shell-exec API endpoint.
 *
 * @param string $cmd The command to execute.
 * @return string|false Raw response from NewServ or false on failure.
 */
if (!function_exists('run_shell_command')) {
    function run_shell_command(string $cmd) {
        return newserv_shell_exec($cmd);
    }
}

/**
 * Standard alias for run_shell_command / newserv_shell_exec.
 */
if (!function_exists('run_shell')) {
    function run_shell(string $cmd) {
        return newserv_shell_exec($cmd);
    }
}

/**
 * Admin alias for run_shell_command.
 */
if (!function_exists('run_shell_admin')) {
    function run_shell_admin(string $cmd) {
        return newserv_shell_exec($cmd);
    }
}

/**
 * Executes a command via NewServ's shell-exec and decodes the JSON response result.
 *
 * @param string $cmd The command to execute.
 * @return mixed|null Parsed 'result' field, or null on error.
 */
if (!function_exists('run_shell_command_json')) {
    function run_shell_command_json(string $cmd) {
        $result = newserv_shell_exec($cmd);
        if ($result === false) return null;
        $json = json_decode($result, true);
        return $json['result'] ?? null;
    }
}

/**
 * Clamps a numeric value between a minimum and maximum threshold.
 */
if (!function_exists('clamp')) {
    function clamp($val, $min, $max) {
        return max($min, min($max, $val));
    }
}

/**
 * Resolves a player filename (.psochar or .psobank) inside the given directory,
 * falling back to case-insensitive matching if exact match does not exist.
 */
if (!function_exists('resolve_player_file')) {
    function resolve_player_file(string $dir, string $filename): string {
        $fullPath = $dir . $filename;
        if (file_exists($fullPath)) {
            return $fullPath;
        }
        if (is_dir($dir)) {
            $files = scandir($dir);
            if ($files !== false) {
                foreach ($files as $f) {
                    if (strcasecmp($f, $filename) === 0) {
                        return $dir . $f;
                    }
                }
            }
        }
        return $fullPath;
    }
}

// Load Localization
require_once __DIR__ . '/lang.php';
?>
