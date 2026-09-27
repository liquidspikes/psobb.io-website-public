# Configuration Directory (`config/`)

This directory houses **persistent, public, and presentation-level configurations** for the PSOBB website.

Unlike `.env` (which stores private infrastructure credentials and secrets), files in `config/` are safe to commit to version control as default server baselines and can be dynamically managed by administrators via the web interface.

---

## 🧭 Architectural Separation: `.env` vs `config/`

| Aspect | `.env` (Environment Secrets) | `config/` (Site & Presentation Config) |
| :--- | :--- | :--- |
| **Purpose** | Private API keys, database credentials, server endpoints, and secrets. | Theme colors, presets, community links, and public UI preferences. |
| **Security** | **Confidential.** NEVER commit `.env` to Git (`.gitignore`). | **Public / Safe.** Tracked in Git as default repository settings. |
| **Editing** | Edited manually on host server or injected via deployment CI/CD. | Edited via the **Admin Web UI** (`/admin/theme_manager.php`) or JSON. |
| **Examples** | `DISCORD_CLIENT_SECRET`, `BOT_API_SECRET`, `GEMINI_API_KEY`, `NEWSERV_API_URL` | `default_preset`, `custom_overrides`, `discord_server`, `allow_user_customization` |
| **Priority** | **Tier 1 (Highest)**: Environment variables override config files. | **Tier 2 (Default)**: Used as the active baseline for all players. |

---

## 🎨 Files in this Directory

### `theme.json`
Central configuration file for the site-wide theming engine and community links.

#### JSON Structure:
```json
{
    "default_preset": "classic",
    "allow_user_customization": true,
    "discord_server": "https://discord.gg/28s84HJXha",
    "discord_invite_url": "https://discord.gg/28s84HJXha",
    "custom_overrides": {
        "--pso-blue": "#00ffff",
        "--pso-dark": "#0a0a10",
        "--pso-panel": "rgba(0, 20, 40, 0.9)",
        "--pso-text": "#e0f0ff",
        "--pso-orange": "#ffaa00",
        "--pso-purple": "#9d4edd",
        "--pso-bg-gradient": "radial-gradient(circle at top center, #1a0b2e 0%, #050a14 60%, #000000 100%)"
    },
    "updated_at": "2026-09-26T12:00:00+00:00"
}
```

#### Field Explanations:
* **`default_preset`** *(string)*: The active baseline theme preset for the server. Options:
  * `classic` — *Pioneer 2 Classic* (Default Sega 2004 cyan & purple)
  * `cyberpunk` — *Cyberpunk Neon* (Electric cyan & hot magenta)
  * `forest` — *Ragol Forest* (Bioluminescent emerald & amber)
  * `darkfalz` — *Dark Falz Void* (Abyssal black & crimson warning)
  * `desert` — *Episode IV Crater* (Desert dunes, solar amber & burnt orange)
  * `matrix` — *Monochrome OLED* (Crisp silver on deep carbon black)
* **`allow_user_customization`** *(boolean)*: When `true`, displays a theme palette dropdown in the top navigation bar allowing individual players to preview or save their own theme preference locally in a cookie.
* **`discord_server`** / **`discord_invite_url`** *(string)*: The public Discord community invite URL rendered across all "Join Discord" and community buttons on the site.
* **`custom_overrides`** *(object)*: Map of CSS root variables that override the preset colors site-wide without editing stylesheets.
* **`updated_at`** *(string)*: ISO-8601 timestamp automatically refreshed whenever an administrator saves settings from the Theme Manager.

---

### `site.json`
Primary configuration file for public server identity, gameplay multipliers, client downloads, feature toggles, and portal module access control.

#### JSON Structure:
```json
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
    "client_raw_url": "/downloads/PSOBBIO-Linux_1.25.13.zip",
    "portal_modules": {
        "hub": "everyone",
        "characters": "everyone",
        "bank": "everyone",
        "guild": "everyone",
        "tekker": "everyone",
        "lfg": "everyone",
        "chat": "everyone",
        "settings": "everyone"
    },
    "updated_at": "2026-09-26T12:00:00+00:00"
}
```

#### Field Explanations & Examples:

| Parameter | Type | Default | Description & Concrete Examples |
| :--- | :--- | :--- | :--- |
| **`server_name`** | string | `"PSOBB.IO"` | The public brand name of your server displayed in titles, emails, and headers.<br>• *Examples:* `"PSOBB.IO"`, `"PSOBB.RU"`, `"Pioneer 2 Destiny"` |
| **`server_address`** | string | `"psobb.io"` | Server domain or IP address without protocol or slashes.<br>• *Examples:* `"psobb.io"`, `"psobb.ru"`, `"play.myserver.net"`, `"127.0.0.1:8000"` |
| **`server_tagline`** | string | *See above* | Descriptive slogan displayed on homepage hero banner and SEO meta tags.<br>• *Examples:* `"Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience."`, `"Возрождение легендарной PSOBB в русскоязычном сообществе."` |
| **`default_language`** | string | `"auto"` | Default interface language for visitors.<br>• *Options:* `"auto"` (browser-detected), `"en"` (English), `"jp"` (Japanese), `"ru"` (Russian) |
| **`hero_logo_url`** | string | `"/img/header_logo.png"` | Path or external URL for the header/hero logo image.<br>• *Examples:* `"/img/header_logo.png"`, `"/img/custom_logo.svg"` |
| **`exp_rate`** | string | `"1x"` | Displayed EXP multiplier badge.<br>• *Examples:* `"1x"`, `"2x"`, `"5x"`, `"Dynamic Weekend Boost"` |
| **`drop_rate`** | string | `"1x"` | Displayed Rare Drop multiplier badge.<br>• *Examples:* `"1x"`, `"2x"`, `"3x"` |
| **`meseta_rate`** | string | `"1x"` | Displayed Meseta rate multiplier badge.<br>• *Examples:* `"1x"`, `"2x"`, `"10x"` |
| **`discord_server`** | string | `"https://discord.gg/..."` | Public Discord community invite URL.<br>• *Examples:* `"https://discord.gg/28s84HJXha"`, `"https://discord.gg/your-invite-code"` |
| **`newserv_players_dir`** | string | `"/opt/newserv/system/players"` | Absolute host filesystem path to NewServ's `system/players/` directory.<br>• *Linux VPS:* `"/opt/newserv/system/players"`<br>• *Windows OpenServer:* `"C:/OSPanel/home/psobb.ru/newserv/system/players"`<br>• *Docker:* `"/var/newserv/system/players"` |
| **`enable_registration`** | boolean | `true` | When `false`, disables new player account creation.<br>• *Values:* `true`, `false` |
| **`enable_bounties`** | boolean | `true` | Enables or disables the Hunter's Guild Bounty Board.<br>• *Values:* `true`, `false` |
| **`enable_lfg`** | boolean | `true` | Enables or disables the Looking For Group (LFG) Lobby terminal.<br>• *Values:* `true`, `false` |
| **`enable_mods`** | boolean | `true` | Enables or disables the Community Mod Repository.<br>• *Values:* `true`, `false` |
| **`enable_quest_editor`** | boolean | `true` | Enables or disables the web-based Quest Script Editor.<br>• *Values:* `true`, `false` |
| **`enable_discord_oauth`** | boolean | `true` | Enables or disables Discord OAuth2 account linking.<br>• *Values:* `true`, `false` |
| **`client_windows_url`** | string | `"/downloads/..."` | Direct download link for Windows game client.<br>• *Examples:* `"/downloads/PSOBBIO-Setup_1.25.13b.exe"`, `"https://mega.nz/file/..."` |
| **`client_mac_url`** | string | `"/downloads/..."` | Download link for macOS DMG client.<br>• *Examples:* `"/downloads/PSOBBIO_125.13.dmg"` |
| **`client_raw_url`** | string | `"/downloads/..."` | Download link for Linux/Wine archive.<br>• *Examples:* `"/downloads/PSOBBIO-Linux_1.25.13.zip"` |
| **`portal_modules`** | object | *All "everyone"* | Access permission per player portal module (`hub`, `characters`, `bank`, `guild`, `tekker`, `lfg`, `chat`, `settings`).<br>• *Options per module:* `"everyone"`, `"admin_only"`, `"disabled"` |

---

## 🖥️ Live Administration via Web UI

Administrators do **not** need to manually edit `site.json` or `theme.json`:
1. **Site Settings & Server Configuration:**
   * Navigate to **Admin** $\rightarrow$ **Site Settings** (`/admin/site_settings.php`).
   * Configure server branding, rates, NewServ player files directory, client download links, and module visibility.
   * Changes write directly to `config/site.json` in real time.
2. **Visual Theme & Presets:**
   * Navigate to **Admin** $\rightarrow$ **Theme Manager** (`/admin/theme_manager.php`).
   * Select theme presets, adjust CSS colors, and edit the Discord invite link.
   * Changes write directly to `config/theme.json`.

