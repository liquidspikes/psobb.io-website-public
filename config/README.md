# Configuration Directory (`config/`)

This directory houses **persistent, public, and presentation-level configurations** for the PSOBB website.

Unlike `.env` (which stores private infrastructure credentials and secrets), files in `config/` are safe to commit to version control as default server baselines and can be dynamically managed by administrators via the web interface.

---

## 🧭 Architectural Separation: `.env` vs `config/`

| Aspect | `.env` (Environment Secrets) | `config/` (Site & Presentation Config) |
| :--- | :--- | :--- |
| **Purpose** | Private API keys, bot tokens, database path override, server URLs. | Theme colors/presets, server branding, crew cards, feature flags, bounty tuning. |
| **Security** | **Confidential.** NEVER commit `.env` to Git (`.gitignore`). | Tracked baselines (`theme.json`, `site.json`, `about.json`, `bounties.json`) + gitignored runtime `bounties.local.json`. |
| **Editing** | Edited on host server (from `.env.example`). | Edited via **Admin Site Settings** (`/admin/site_settings.php`), **Theme Manager** (`/admin/theme_manager.php`), or JSON. |
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
  * `seabed` — *Seabed Research Lab* (Deep oceanic teal & phosphor cyan)
  * `mines` — *Mines & Machine* (Industrial steel, warning amber & neon plasma)
  * `matrix` — *Monochrome OLED* (Crisp silver on deep carbon black)
* **`allow_user_customization`** *(boolean)*: When `true`, displays a theme palette dropdown in the top navigation bar allowing individual players to preview or save their own theme preference locally in a cookie.
* **`discord_server`** / **`discord_invite_url`** *(string)*: The public Discord community invite URL rendered across all "Join Discord" and community buttons on the site.
* **`custom_overrides`** *(object)*: Map of CSS root variables that override the preset colors site-wide without editing stylesheets.
* **`updated_at`** *(string)*: ISO-8601 timestamp automatically refreshed whenever an administrator saves settings from the Theme Manager.

---

### `site.json` *(template: `site.example.json`)*
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
| **`server_name`** | string | `"PSOBB.IO"` | The public brand name of your server displayed in titles, emails, and headers.<br>• *Examples:* `"PSOBB.IO"`, `"Ragol Online"`, `"Pioneer 2 Destiny"` |
| **`app_name`** | string | `"PSOBB.IO"` | Short PWA / Companion App name (`api/manifest.php`, `get_app_name()`). |
| **`server_address`** | string | `"psobb.io"` | Server domain or IP address without protocol or slashes.<br>• *Examples:* `"psobb.io"`, `"play.myserver.net"`, `"127.0.0.1:8000"` |
| **`server_tagline`** | string | *See above* | Descriptive slogan displayed on homepage hero banner and SEO meta tags.<br>• *Examples:* `"Join the adventure in the ultimate private Phantasy Star Online BlueBurst server experience."`, `"The classic Phantasy Star Online experience reborn."` |
| **`default_language`** | string | `"auto"` | Default interface language for visitors.<br>• *Options:* `"auto"` (browser-detected), `"en"` (English), `"jp"` (Japanese), `"ru"` (Russian) |
| **`hero_logo_url`** | string | `"/img/header_logo.png"` | Path or external URL for the header/hero logo image.<br>• *Examples:* `"/img/header_logo.png"`, `"/img/custom_logo.svg"` |
| **`exp_rate`** | string | `"1x"` | Displayed EXP multiplier badge.<br>• *Examples:* `"1x"`, `"2x"`, `"5x"`, `"Dynamic Weekend Boost"` |
| **`drop_rate`** | string | `"1x"` | Displayed Rare Drop multiplier badge.<br>• *Examples:* `"1x"`, `"2x"`, `"3x"` |
| **`meseta_rate`** | string | `"1x"` | Displayed Meseta rate multiplier badge.<br>• *Examples:* `"1x"`, `"2x"`, `"10x"` |
| **`discord_server`** | string | `"https://discord.gg/..."` | Public Discord community invite URL.<br>• *Examples:* `"https://discord.gg/28s84HJXha"`, `"https://discord.gg/your-invite-code"` |
| **`newserv_players_dir`** | string | `"/opt/newserv/system/players"` | Absolute host filesystem path to NewServ's `system/players/` directory.<br>• *Linux VPS:* `"/opt/newserv/system/players"`<br>• *Windows:* `"C:/newserv/system/players"`<br>• *Docker:* `"/var/newserv/system/players"` |
| **`enable_registration`** | boolean | `true` | When `false`, disables new player account creation.<br>• *Values:* `true`, `false` |
| **`enable_bounties`** | boolean | `true` | Enables or disables the Hunter's Guild Bounty Board.<br>• *Values:* `true`, `false` |
| **`enable_lfg`** | boolean | `true` | Enables or disables the Looking For Group (LFG) Lobby terminal.<br>• *Values:* `true`, `false` |
| **`enable_mods`** | boolean | `true` | Enables or disables the Community Mod Repository.<br>• *Values:* `true`, `false` |
| **`enable_quest_editor`** | boolean | `true` | Enables or disables the web-based Quest Script Editor.<br>• *Values:* `true`, `false` |
| **`enable_discord_oauth`** | boolean | `true` | Enables or disables Discord OAuth2 account linking.<br>• *Values:* `true`, `false` |
| **`show_download_windows`** | boolean | `true` | Show/hide the Windows installer card on `downloads.php`. |
| **`show_download_mac`** | boolean | `true` | Show/hide the macOS client card on `downloads.php`. |
| **`show_download_linux`** | boolean | `true` | Show/hide the Linux guide/download card on `downloads.php`. |
| **`show_download_steam_deck`** | boolean | `true` | Show/hide the Steam Deck setup card/script (`install-deck.sh`) on `downloads.php`. |
| **`show_download_raw`** | boolean | `true` | Show/hide the raw archive download link on `downloads.php`. |
| **`client_windows_url`** | string | `"/downloads/..."` | Direct download link for Windows game client.<br>• *Examples:* `"/downloads/PSOBBIO-Setup_1.25.13b.exe"`, `"https://mega.nz/file/..."` |
| **`client_mac_url`** | string | `"/downloads/..."` | Download link for macOS DMG client.<br>• *Examples:* `"/downloads/PSOBBIO_125.13.dmg"` |
| **`client_raw_url`** | string | `"/downloads/..."` | Download link for Linux/Wine archive.<br>• *Examples:* `"/downloads/PSOBBIO-Linux_1.25.13.zip"` |
| **`portal_modules`** | object | *All "everyone"* | Access permission per player portal module (`hub`, `characters`, `bank`, `guild`, `tekker`, `lfg`, `chat`, `settings`).<br>• *Options per module:* `"everyone"`, `"admin_only"`, `"disabled"` |

---

### `about.json`
Configures `about.php` hero copy, section toggles, and contributor/crew cards (initialized by `setup.*` and editable from `/admin/site_settings.php`).
* **`hero_title`**, **`hero_subtitle`**, **`command_deck_title`** *(string)*: Formatted via `get_server_name()` `%s` placeholders.
* **`show_features`**, **`show_tech_specs`** *(boolean)*: Show/hide feature overview and tech-stack sections.
* **`crew`** *(array)*: Staff/contributor card objects (`id`, `name`, `role`, `specialty`, `icon`, `theme`, `bio`).

---

### `bounties.json` & `bounties.local.json` *(template: `bounties.example.json`)*
Configuration for the **Bounty System Rework** (`api/bounty_system.php`, see `api/BOUNTY_SYSTEM.md` and `config/bounties.example.json`).

* **Precedence (`bounty_config()`):** `bounty_default_config()` → `config/bounties.json` (Git-tracked default) → **`config/bounties.local.json`** (gitignored live admin overrides saved via `/admin/site_settings.php` → `api/admin_save_settings.php`).
* **`"enabled"`** *(boolean, default `false` in tracked baseline)*: Dark-launch master switch. When `false`, `api/cron_missions.php` keeps the legacy bounty flow untouched.
* **`"legacy_grace_days"`** *(integer, default `30`)*: When `"enabled"` becomes `true`, unfinished pre-rework `in_progress` bounties (`missions.cadence IS NULL`) receive a one-time `now + 30d` expiry window (without consuming any new Daily/Weekly slots), while completed `ready_to_redeem` rewards remain claimable indefinitely.
* **Key sections**: Override `"reset"` (`daily_hour: 11`, `weekly_day: 1`, `timezone: "America/New_York"`), `"pools"` (incl. `"battle"` and `"challenge"` `"scope": "account"`), `"earn"` (`starter_on_login`, `max_activations_per_pass: 2`, `max_lore_upgrades_per_pass: 2`), `"challenge.full_run_spheres"` (`{"1": 2, "2": 1}`), `"drops"`, `"contracts"`, `"battle"`, or `"reward_engine"`.

---

## 🖥️ Live Administration via Web UI

Administrators do **not** need to manually edit JSON config files on disk:
1. **Site Settings & Server Configuration:**
   * Navigate to **Admin** $\rightarrow$ **Site Settings** (`/admin/site_settings.php`).
   * Configure server branding, rates, NewServ player files directory, client download links/toggles, module visibility, `about.json` copy/crew, and `bounties.local.json` tuning.
   * Changes write to `config/site.json`, `config/about.json`, and `config/bounties.local.json`, and sync `discord_server` into `config/theme.json` via `api/admin_save_settings.php`.
2. **Visual Theme & Presets:**
   * Navigate to **Admin** $\rightarrow$ **Theme Manager** (`/admin/theme_manager.php`).
   * Select theme presets, adjust CSS colors, and edit the Discord invite link.
   * Changes write directly to `config/theme.json`.

