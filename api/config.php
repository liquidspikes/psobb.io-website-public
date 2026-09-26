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

// Discord Server & Invite Configuration
// Configurable via environment variables ($DISCORD_SERVER, $DISCORD_INVITE_URL),
// centralized config/theme.json, or defaults to the community server
$siteConfigPath = __DIR__ . '/../config/theme.json';
$siteThemeConfig = file_exists($siteConfigPath) ? json_decode(@file_get_contents($siteConfigPath), true) : [];
$DISCORD_SERVER     = $_ENV['DISCORD_SERVER'] ?? $_SERVER['DISCORD_SERVER'] ?? (getenv('DISCORD_SERVER') ?: null)
                      ?? $_ENV['DISCORD_INVITE_URL'] ?? $_SERVER['DISCORD_INVITE_URL'] ?? (getenv('DISCORD_INVITE_URL') ?: null)
                      ?? $siteThemeConfig['discord_server'] ?? $siteThemeConfig['discord_invite_url'] ?? 'https://discord.gg/28s84HJXha';
$DISCORD_INVITE_URL = $DISCORD_SERVER;
$DISCORD_SERVER_ID  = $_ENV['DISCORD_SERVER_ID'] ?? $_SERVER['DISCORD_SERVER_ID'] ?? (getenv('DISCORD_SERVER_ID') ?: null) ?? $siteThemeConfig['discord_server_id'] ?? '';

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
 *
 * @param string $token The CSRF token extracted from the request headers or body.
 * @return void
 */
function verify_csrf_token($token) {
    $cleanToken = preg_replace('/[\r\n]/', '', trim((string)$token));
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $cleanToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token.']);
        exit;
    }
}

// Load Localization
require_once __DIR__ . '/lang.php';
?>
