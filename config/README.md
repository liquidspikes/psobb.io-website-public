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

## 🖥️ Live Administration via Web UI

Administrators do **not** need to edit `config/theme.json` manually:
1. Log in with an admin account.
2. Navigate to **Admin** $\rightarrow$ **Theme Manager** (`/admin/theme_manager.php`).
3. Select presets, adjust individual colors using live color pickers, and edit the Discord invite link.
4. Click **"Preview on This Browser Only"** to test risk-free, or **"Save as Global Server Default"** to write changes directly to `config/theme.json`.
