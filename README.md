# psobb.io

The community website and web-services layer for **psobb.io**, a Phantasy Star
Online: Blue Burst (PSOBB) private server. The site provides a modular Player
Portal (`includes/portal/modules/`), character & shared bank inspection, daily
and streak rewards, AI-grounded "bounty" missions, Community Events, a Tekker
Store/Challenge weapon claim flow, drop tables (`drops.php`), Looking for Group
(LFG), a client Mod repository, Special Deliveries, a browser Quest Editor with
quest upload support, and Discord OAuth linking — all backed by a live
[NewServ](https://github.com/fuzziqersoftware/newserv) game-server instance and
consumed by [`psobb-discord-bot`](../psobb-discord-bot).

---

## Architecture

```
                       ┌────────────────────────┐
   Browser  ◀────────▶ │  Frontend PHP pages     │   index.php, login.php,
   (vanilla JS)        │  + modular portal / CSS │   missions.php, drops.php, …
                       └───────────┬────────────┘
                                   │ fetch() (session cookie + X-CSRF-Token)
                       ┌───────────▼────────────┐
                       │  PHP API  (/api/*.php)  │   endpoints + shared libraries
                       └─────┬───────────┬───────┘
               SQLite (db.php)│          │ HTTP REST
                       ┌─────▼─────┐ ┌───▼──────────────┐
                       │ website.db│ │ NewServ game srv │  /y/clients, /y/accounts,
                       │ (WAL, 25  │ │ (C++ emulator)   │  /y/lobbies, /y/shell-exec …
                       │  tables)  │ └──────────────────┘
                       └───────────┘
                                   ▲
   Discord bot ────────────────────┘  Bearer-token APIs
   (psobb-discord-bot)                (api/bot_api.php & api/bot_tekker_db.php)

   Cron daemons (cron_*.php) + Google Gemini / Gemma  →  activate/evaluate bounties,
   auto-upgrade fallback stories, drive community events, track boss kills, DM streaks.
```

| Layer | Tech | Notes |
|-------|------|-------|
| Frontend | HTML, vanilla JS (`js/pso_utils.js`, `js/main.js`), CSS (glassmorphism) | Server-rendered PHP pages + modular portal (`includes/portal/`) |
| Backend | Vanilla PHP 8 (no framework) | Endpoints and shared libs (`api/pso_constants.php`, `api/newserv_client.php`, `api/bounty_*.php`, `api/enemy_kills.php`) |
| Database | SQLite — `db/website.db` | WAL mode, 25 tables, `PRAGMA user_version` schema v2 (`api/db.php`) |
| Game server | **NewServ** (C++ PSOBB emulator) | Reached over HTTP at `NEWSERV_API_URL` (`/y/*` routes); account list is credential source of truth |
| AI | Google Gemini / Gemma | Lore-grounded bounty & community-event generation (`api/bounty_generator.php`, `api/cron_community.php`) |
| Discord | `psobb-discord-bot` (Node.js) | Calls `api/bot_api.php` and `api/bot_tekker_db.php` with a Bearer token |

### Source of truth

Player **credentials live in NewServ**, not in SQLite. `api/login.php` validates
the username/password against NewServ's `/y/accounts`, then mirrors a minimal
record into the SQLite `users` table (creating it on first login — "self-healing").
The SQLite row stores website-specific data: `discord_id`, `is_admin`, language,
mission progress, streak/daily state, and notification preferences.

---

## Authentication model

There are two separate auth schemes:

1. **Web users — session + CSRF.**
   - `api/login.php` populates `$_SESSION['user'] = ['username', 'account_id', …]`.
   - Sessions are stored in SQLite via `SQLiteSessionHandler` (`api/config.php`),
     30-day lifetime, `HttpOnly` cookie.
   - State-changing endpoints are `POST` / `DELETE` and validate `$_SESSION['csrf_token']`
     via `verify_csrf_token()`, reading the **`X-CSRF-Token` HTTP header first**, then
     `$_POST['csrf_token']` (and JSON endpoints like `admin_save_settings.php` also
     check `csrf_token` in the decoded JSON payload).

2. **Bot / automation — Bearer token.** (`api/bot_api.php`, `api/bot_tekker_db.php`, `telemetry_ingest.php`,
   `wiki_ingest.php`)
   - `Authorization: Bearer <token>`.
   - Verified two ways: a legacy shared secret (`BOT_API_SECRET`) **or** a
     bcrypt-hashed, revocable, expiring token from the `bot_tokens` table
     (managed via `admin_bot_tokens.php`).

Admin-only endpoints additionally check that the logged-in user is an
administrator before acting.

See [`api/API.md`](api/API.md) for the full endpoint reference.

---

## Configuration Architecture

The platform separates **private host secrets** from **public presentation & gameplay config**:

### 1. Host Secrets & Infrastructure (`.env`)
Host credentials and server-local settings live in `.env` (copied from `.env.example`, excluded from Git via `.gitignore`, and loaded by `loadEnv()` in `api/config.php`).

| Variable | Type | Purpose |
|----------|------|---------|
| `NEWSERV_API_URL` | Infrastructure | Base URL of the NewServ REST API (default `http://127.0.0.1:8443`) |
| `NEWSERV_PLAYERS_DIR` | Infrastructure | Path to NewServ `system/players/` save files (`.psochar`/`.psobank`) |
| `NEWSERV_COMMAND_PREFIX` | Game Config | In-game GM/chat command prefix (default `$`) |
| `DB_PATH` | Optional Override | Override SQLite database path (default `db/website.db`) |
| `DISCORD_CLIENT_ID` / `DISCORD_CLIENT_SECRET` / `DISCORD_REDIRECT_URI` | OAuth2 | Discord account-linking credentials and callback URL |
| `BOT_API_SECRET` | Secret | Shared Bearer secret for bot API endpoints |
| `BOT_TOKEN` | Secret Token | Discord bot token (used by `cron_streak_alert.php` to DM users) |
| `DISCORD_SERVER` / `DISCORD_SERVER_ID` | Community | Community Discord invite link override and guild ID |
| `GEMINI_API_KEY` | API Key | Google Gemini key for procedural bounty & community-event generation |
| `GEMINI_MODEL` | Model ID | Primary model ID (see `.env.example` for default; automatic fallback chain in `api/bounty_generator.php`) |
| `GEMINI_API_BASE` | Optional Override | Custom Gemini endpoint URL (used for local stub/test runs) |
| `BREVO_API_KEY` / `SMTP_FROM` | Email | Transactional email API key and outgoing sender address |
| `SERVER_NAME` / `SERVER_ADDRESS` / `SERVER_TAGLINE` | Optional Override | Env-level overrides for `config/site.json` |
| `EXP_RATE` / `DROP_RATE` / `MESETA_RATE` | Optional Override | Env-level overrides for displayed multipliers |
| `ENABLE_*` | Feature Flags | Optional env overrides (`ENABLE_REGISTRATION`, `ENABLE_BOUNTIES`, `ENABLE_LFG`, `ENABLE_MODS`, `ENABLE_QUEST_EDITOR`, `ENABLE_DISCORD_OAUTH`) |

### 2. Site, Theme, About & Bounty Config (`config/`)
- **`config/theme.json`**: Site-wide theme presets (`classic`, `cyberpunk`, `forest`, `darkfalz`, `desert`, `matrix`, `seabed`, `mines`), CSS variable overrides, and Discord links (managed via `/admin/theme_manager.php`).
- **`config/site.json`** (`config/site.example.json`): Public server branding, multipliers, client download URLs/visibility flags, feature toggles, and per-module portal permissions (`hub`, `characters`, `bank`, `guild`, `tekker`, `lfg`, `chat`, `settings`), managed via `/admin/site_settings.php`.
- **`config/about.json`**: Editable About page hero text and staff/crew roster cards (managed via `/admin/site_settings.php`).
- **`config/bounties.json`** + gitignored **`config/bounties.local.json`** (`config/bounties.example.json`): Bounty System Rework master switch, pools, caps, 11:00 AM ET resets, cron pass budgets, Battle/Challenge settings, contracts, and reward-engine tuning.

See [`config/README.md`](config/README.md) for the full configuration schema and field reference.

---

## Directory layout

```
psobb.io-website/
├── *.php                 Frontend pages (index, login, missions, drops, lfg, mods, tekker, …)
├── api/                  Backend API endpoints and libraries (see api/API.md)
│   ├── bot_api.php        Discord-bot REST API (Bearer auth)
│   ├── bot_tekker_db.php  Tekker Challenge DB storage backend (Bearer auth)
│   ├── config.php         Env/site config, sessions, CSRF, NewServ command wrapper
│   ├── db.php             SQLite WAL connection + version-gated migrations (v2, 25 tables)
│   ├── pso_constants.php  Canonical BB classes, Section IDs, techniques, Ep 1/2/4 maps, ItemData helpers
│   ├── newserv_client.php Centralized NewServ REST HTTP client helpers
│   ├── functions.php      Shared helpers (mission text, item rendering, reward tables)
│   ├── bounty_*.php       Bounty Rework engine, contracts, rewards, lore selector, Gemini client
│   ├── data/              BB drop/level/area/Battle/Challenge JSON tables generated from newserv
│   ├── lore/              Episode I/II/IV story-setting cards and style examples (see api/lore/README.md)
│   ├── cron_*.php         Background daemons (missions, community, boss tracker, streak alerts)
│   ├── character_viewer.php Save-file parser (.psochar/.psobank) for portal & bot profiles
│   └── lang.php           Localization strings + __()
├── config/               Theme, site, about, and bounty baseline configs (see config/README.md)
├── db/
│   ├── init_db.php        Base schema bootstrap → db/website.db (runs migrations)
│   └── website.db         SQLite database (not in VCS)
├── includes/
│   └── portal/            Modular Player Portal registry, modules/, modals.php, and custom_modules/
├── admin/                Admin dashboard & settings pages
├── css/  js/  img/       Static assets (including js/pso_utils.js & js/main.js)
├── quest-editor/         Browser-based quest editor
├── tests/                Integration & regression test suites (CLI PHP)
├── tools/bounty_data/    BB item/map/quest data extractors & pool verification report
├── DEVELOPER_GUIDE.md    High-level architecture narrative
└── README.md             This file
```

---

## Automated Setup (Linux & Windows)

The portal includes automated setup scripts that initialize runtime directories (`db/`, `config/`, `uploads/`, `logs/`), baseline configurations (`.env`, `site.json`, `theme.json`, `about.json`, `bounties.json`), bootstrap the SQLite database (25 verified schemas, schema v2), test NewServ game server connectivity, and configure permissions.

### Linux / macOS
```bash
chmod +x setup.sh
./setup.sh                  # Local development

# Production VPS deployments (configures web user ownership):
sudo ./setup.sh
```

### Windows
Double-click `setup.bat` in File Explorer, run `setup.bat`, or use PowerShell:
```powershell
.\setup.ps1
# Automatically launch the local dev server upon completion:
.\setup.ps1 -StartServer -Port 8000
```

---

## Local Development, Linting & Testing

The site targets **PHP 8** with the `sqlite3`, `mbstring`, `iconv`, `openssl`,
and `json` extensions.

1. Run automated setup above (or `php db/init_db.php` manually).
2. Start the built-in development server:
   ```bash
   php -S 0.0.0.0:8000
   ```
3. Open `http://localhost:8000/` in your browser.

> Without a reachable NewServ instance, endpoints that read live game state
> (`/y/clients`, `/y/accounts`, …) degrade gracefully via `api/newserv_client.php`
> and fall back to empty/offline data rather than erroring.

### Linting & Automated Tests

```bash
php -l api/bot_api.php                       # syntax-check any PHP file
node --check js/main.js                      # syntax-check frontend JS

php api/test_bounty_system.php               # caps, queues, Battle/Challenge, contracts, rewards, rerolls
php api/test_bounty_lore.php                 # lore setting cards & prompt formatting
php tests/test_battle_mode_bounty_tracking.php
php tests/test_community_rappy_event.php
php tests/test_portal_bounty_display.php
php tests/test_sqlite_wal_snapshot_locks.php
php tools/bounty_data/pool_report.php        # verifies all ~230k 10x-rule reward pools
```

---

## Discord Bot Integration (`bot_api.php` & `bot_tekker_db.php`)

[`psobb-discord-bot`](../psobb-discord-bot) consumes two Bearer-authenticated endpoints:

- **`api/bot_api.php?action=…`**:
  | Action | Method | Purpose |
  |--------|--------|---------|
  | `link` | POST | Link a website username to a Discord ID |
  | `get_player` | GET | Full player profile: all character slots (live + save-file), bank, completed bounty count, missions |
  | `get_online_players` | GET | Currently-online clients whose accounts are linked to a Discord ID (drives role sync) |
  | `get_linked_players` | GET | Every account linked to a Discord ID, online or not (drives `!sync all`) |
  | `get_lfg` | GET | Recent LFG listings from the last 2 hours (supports `since_id` cursor) |
  | `get_parties` | GET | Live multiplayer game rosters with linked Discord IDs (drives dynamic party voice rooms) |
  | `get_events` | GET | Active community events |
- **`api/bot_tekker_db.php`** (`POST` JSON `{ "op": … }`): Persists Discord Tekker Challenge active drops, player guesses/telemetry, active users, claimed tokens, and settings in SQLite `tekker_*` tables.

See [`api/API.md`](api/API.md) for full request/response details.

---

## Background jobs (cron)

| Script | Cadence | Role |
|--------|---------|------|
| `api/cron_missions.php` | 1 min loop (55s window, ~10s ticks) | Evaluates active bounties/contracts/Battle/Challenge progress, drains queued offline/login starters within `max_activations_per_pass`, auto-upgrades fallback stories once AI recovers, and records per-character `enemy_kill_counts` |
| `api/cron_community.php` | 1 min | Drives server-wide community events (incl. `ENEMY_KILL` / `RARE_RAPPIES`, boss rushes, party-wide credit) and AI milestone broadcasts |
| `api/cron_boss_tracker.php` | Continuous / 5s loop | Polls NewServ `/y/clients` & `/y/lobbies` for episode-aware boss kills and floor clear events |
| `api/cron_streak_alert.php` | Daily (`18:00` / 11:00 PM server alert window) | DMs linked Discord players (via `BOT_TOKEN`) before a login streak expires |
