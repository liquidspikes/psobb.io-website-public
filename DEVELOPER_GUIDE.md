# Phantasy Star Online: Blue Burst (psobb.io) Developer Guide

Welcome to the **psobb.io Web Application Ecosystem**! This document serves as the architectural guide for the PHP web portal, SQLite database layer, cron daemons, and integration with the underlying `NewServ` C++ game server emulator.

---

## 1. High-Level Architecture Overview

The website and game server run side-by-side so players can register, manage characters and banks, claim login streaks, track AI-grounded Guild Bounties and Community Events, browse drop tables, submit mods, upload quests, forge Tekker rewards, and find parties.

### 1.1 Core Components
* **Frontend Pages & Modular Player Portal:** Vanilla HTML, Glassmorphism CSS (`css/`), and Vanilla JavaScript (`js/pso_utils.js`, `js/main.js`, plus service worker `sw.js`). Authenticated portal views are structured cleanly as pluggable modules under `includes/portal/modules/` and `includes/portal/custom_modules/`.
* **Backend Layer:** Standalone Vanilla PHP 8 endpoints under `api/`, backed by shared core libraries:
  * `api/config.php` — 3-tier config loader, `SQLiteSessionHandler`, CSRF checks, Discord Bearer auth (`require_bot_auth()`), NewServ command runner (`newserv_shell_exec()`).
  * `api/db.php` — SQLite WAL connection factory (`get_db()`) and `PRAGMA user_version`-gated schema migrations (`PSO_DB_SCHEMA_VERSION = 2`, 25 verified tables).
  * `api/pso_constants.php` — Canonical Blue Burst classes (`PSO_CHAR_CLASSES`), Section IDs (`PSO_SECTION_IDS`), techniques (`PSO_TECHNIQUES`), Episode I/II/IV floor name maps (`PSO_EPISODE_FLOORS`), unsealable item IDs, 20-byte `ItemData` helpers, and `get_db_path()`.
  * `api/newserv_client.php` — Unified NewServ REST HTTP client (`newserv_http_get_json()`, `newserv_fetch_clients()`, `newserv_fetch_lobbies()`, `newserv_fetch_summary()`, UTF-8/Shift-JIS sanitization, Zero-EXP loading guard `newserv_is_client_loading()`).
  * `api/bounty_*.php` — Modular Bounty System Rework (`bounty_system.php`, `bounty_contracts.php`, `bounty_rewards.php`, `bounty_lore.php`, `bounty_generator.php`).
  * `api/enemy_kills.php` — Lifetime per-character enemy/boss kill tracking persisted in `enemy_kill_counts`.
* **Database:** SQLite at `db/website.db` (overridable via `DB_PATH`).
* **Game Server (`NewServ`):** Custom C++ PSOBB emulator exposing `/y/*` REST endpoints on `NEWSERV_API_URL` plus save files (`.psochar` / `.psobank`) on disk (`NEWSERV_PLAYERS_DIR`).
* **AI Engine:** Google Gemini / Gemma via REST (`api/bounty_generator.php`, `api/cron_community.php`).

### 1.2 Three-Tier Configuration Precedence
The application layers settings in strict priority order:
1. **Tier 1 (Highest) — `.env` / Environment Variables:** Secrets (`GEMINI_API_KEY`, `DISCORD_CLIENT_SECRET`, `BOT_API_SECRET`, `BOT_TOKEN`, `BREVO_API_KEY`), host endpoints (`NEWSERV_API_URL`, `NEWSERV_PLAYERS_DIR`, `NEWSERV_COMMAND_PREFIX`), test overrides (`DB_PATH`, `GEMINI_API_BASE`), `GEMINI_MODEL` (see `.env.example` for default), and optional `ENABLE_*` / identity overrides.
2. **Tier 2 — `config/*.json` + `config/bounties.local.json`:** Version-controlled baselines (`site.json`, `theme.json`, `about.json`, `bounties.json`) plus gitignored `config/bounties.local.json` written by `/admin/site_settings.php`.
3. **Tier 3 — PHP Defaults:** Built-in fallbacks inside `api/config.php` and `bounty_default_config()`.

See [`config/README.md`](config/README.md) for schemas and fields.

---

## 2. Authentication, Sessions, CSRF & SQLite Concurrency

### 2.1 NewServ Credential Source of Truth & Sessions
* **NewServ owns account credentials.** `api/login.php` authenticates username/password against NewServ (`/y/accounts`) and "self-heals" or syncs a row into SQLite `users`.
* `api/register.php` creates the account on NewServ and mirrors it in SQLite. Password changes (`api/change_password.php`) and token-based email resets (`api/forgot_password.php` / `api/reset_password.php`) propagate updates back to NewServ. Email linking uses signed tokens via `api/set_account_email.php` + `confirm_email.php`.
* Web sessions use `SQLiteSessionHandler` (`api/config.php`) backed by the `sessions` table with a 30-day `HttpOnly` cookie and `credentials: 'same-origin'` fetches.
* `.htaccess` enforces HTTPS and redirects `www.` to the apex host for unified cookie origins.

### 2.2 CSRF & Authorization Tiers
* `verify_csrf_token()` checks **`HTTP_X_CSRF_TOKEN` first** and falls back to `$_POST['csrf_token']` (JSON endpoints such as `api/admin_save_settings.php` also check `csrf_token` in the decoded JSON payload).
* **Discord OAuth (`api/discord_auth.php`, `api/discord_callback.php`, `api/discord_unlink.php`):** Available to all logged-in players so they can link their Discord identity (`users.discord_id`) for bot role sync, streak alerts, LFG @mentions, and Tekker drops. Discord OAuth does **not** grant admin access.
* **Admin Privileges:** Controlled strictly by `users.is_admin = 1` in SQLite (promoted via CLI `php promote_admin.php <username>`).
* **Bot / Automation Bearer Auth:** `require_bot_auth()` checks `Authorization: Bearer <token>` against `BOT_API_SECRET` or bcrypt hashes in `bot_tokens` (managed via the Admin Bot Tokens manager).

### 2.3 SQLite WAL Mode & Snapshot-Lock Discipline
`get_db()` enables `journal_mode = WAL`, `synchronous = NORMAL`, and `busyTimeout(30000)`. To prevent `SQLITE_BUSY_SNAPSHOT` ("database is locked") errors that bypass `busyTimeout` under concurrent cron/web load:
* Always call `$res->finalize()` and `$stmt->close()` on every read query/cursor **before** executing a write (`INSERT`/`UPDATE`/`DELETE`) or `BEGIN IMMEDIATE` on the same connection.
* Keep transactions short and wrap multi-step claims (`redeem_bounty.php`, `redeem_community.php`, `claim_daily.php`, `claim_streak.php`, `lfg_requests.php`) cleanly.
* Verified by `php tests/test_sqlite_wal_snapshot_locks.php`.

---

## 3. Modular Player Portal (`includes/portal/`)

Authenticated dashboard views (`login.php` when logged in) are driven by `includes/portal/modules.php` and `get_site_config()['portal_modules']`:
* **Built-in modules (`includes/portal/modules/`):**
  1. `hub` (order `10`, `#tab-hub`) — Hunter summary, live server stats, companion PWA install card.
  2. `characters` (`20`, `#tab-banks`) — Live/save-file character inspect, Section ID, equipped gear, backpack, and lifetime kill counts (`api/kill_counts.php`).
  3. `bank` (`30`, `#tab-bank`) — Character & shared bank viewer and live bank swap (`api/bank_swap.php`).
  4. `guild` (`40`, `#tab-guild`) — Daily rewards, login streak milestones, and personal bounty board.
  5. `tekker` (`50`, `#tab-tekker`) — Discord Tekker tokens & multi-token custom weapon claim UI (`api/claim_tekker_drop.php`).
  6. `lfg` (`60`, `#tab-lfg`) — LFG board & one-click room warp (`api/lfg_*.php`).
  7. `chat` (`70`, `#tab-chat`) — Web-to-game Ragol chat (`api/send_chat_message.php`).
  8. `settings` (`80`, `#tab-settings`) — Password, recovery email, alias, notification toggles, Discord link, account deletion.
  * Plus `modals.php` (shared modals across portal tabs).
* **Visibility:** Each module can be set to `everyone`, `admin_only`, or `disabled` in `/admin/site_settings.php`.
* **Extensibility:** Drop `*.php` files calling `register_portal_module()` into `includes/portal/custom_modules/` (see `includes/portal/custom_modules/README.md`).

---

## 4. The Bounty System Rework (`api/bounty_*.php`)

When `bounty_rework_enabled()` is `true` (via `config/bounties.json` + `config/bounties.local.json`), the server runs the full reworked Hunter's Guild Bounty System (full specification in [`api/BOUNTY_SYSTEM.md`](api/BOUNTY_SYSTEM.md)):

### 4.1 Pools, Resets & Login Starters
* **Standard (`3` daily / `3` weekly active, `5` daily / `10` weekly earn caps, per character):** Free rolls on login/play plus completion replacements and **Up next** queuing (`queued` / `PENDING` rows).
* **Drop (`5` active / `10` held, per character):** Earned from boss kills and rare enemy kills.
* **Contract (`api/bounty_contracts.php`, `3` held, per character, never expire):** 25% of Ultra Rare / Mythic Rare drops become full Episode runs (`EPISODE_RUN`) or rare-creature hunts (`RARE_TARGET`), paying contract shares (`CONTRACT_SHARE`) to teammates.
* **Battle (`3` daily, per account) & Challenge (`3` weekly, per account):** Account-wide (`pools.battle.scope = "account"`, `pools.challenge.scope = "account"`), level-independent, 100% Photon Drop guaranteed (`2` Photon Spheres guaranteed on Ep 1 full Challenge runs, `1` on Ep 2). Battle Mode normalizes NewServ's quest indices `88001–88008` ↔ rule-set IDs `501–508` via `bounty_normalize_battle_quest()` and tracks wins per `BBCharacterIndex` slot.
* **11:00 AM ET Resets & Login Starters:** Resets run at `reset.daily_hour = 11` / `reset.weekly_day = 1` in `America/New_York`. Schema v2 (`api/db.php`) runs the idempotent `migration_extend_open_dailies_11am_est_v1` +24h open-daily extension once. `bounty_ensure_account_login_starters()` queues full starter sets on first login after each reset, drained asynchronously by `cron_missions.php`.

### 4.2 Lore Grounding, Gemini Resilience & Fallback Auto-Upgrade (`api/bounty_lore.php`, `api/bounty_generator.php`)
* **Era-Locked Lore Cards (`api/lore/`):** `world.json`, `ep1.json`, `ep2.json`, and `ep4.json` provide known facts, open mysteries, hidden truths, era-appropriate cast givers, and style examples.
* **Ep I / II / IV Objective Targets:** Daily floor goals (`EXPLORATION`, `PATROL`, `SPEEDRUN_FLOOR`) and boss goals (`BOSS_ARENA`, `SPEEDRUN_BOSS`, `MENTOR_BOSS`, `HARDCORE_MENTOR`, `DIVERSE_PARTY_BOSS`) support explicit episodes (`floor@ep`, `floor@ep_seconds`, parsed by `parse_floor_target()` and `parse_boss_target()` across all 9 bosses in Ep 1, Ep 2, and Ep 4).
* **Lore Variety & Model Pacing:** `bounty_pick_hub_setting()` and `bounty_pick_giver()` spread hubs and avoid repeat givers across active cards. `cron_missions.php` enforces shared `earn.max_activations_per_pass` (`2`) and `earn.max_lore_upgrades_per_pass` (`2`) budgets within its 55s/10s loop. `bounty_gemini_json()` tries the configured `$GEMINI_MODEL` primary Gemma 4 model followed by `gemini-2.5-flash-lite` → `gemini-2.5-flash`, caching 429 cooldowns in `db/.gemini_model_cooldowns.json`; when all models are cooling down, deterministic `"Guild Posting: ..."` fallbacks (`meta.lore_fallback = 1`) are stored and automatically upgraded by `bounty_upgrade_fallback_stories()` once a model recovers.

### 4.3 Drop-Data Rewards & In-Game Item Dispatch (`api/bounty_rewards.php`, `api/functions.php`)
* **Drop-Data Pools (`api/data/bounty_*.json`):** Built from NewServ's Blue Burst tables (`python3 tools/bounty_data/build.py`), applying tier effort bands and the 10x odds rule (`php tools/bounty_data/pool_report.php`).
* **Stats & Disguise:** Attributes, hit, armor slots, and DEF/EVP bonuses stay hidden and roll once upon redemption (`api/redeem_bounty.php`). Untekked rewards use `? Special BaseWeapon` (or verified hex strings) rendered as `???? Weapon` in `missions.php` (`js/pso_utils.js` / `js/main.js`) and dispatched via `newserv_shell_exec()` (`on <accId> cc $NEWSERV_COMMAND_PREFIX...` — configurable prefix defaulting to `$`) and NewServ's `ItemNameIndex` handler.
* **Legacy Mode Fallback:** Setting `"enabled": false` in `config/bounties.json` / `bounties.local.json` reverts to the pre-rework bounty loop (`bounty_legacy_reward()`). Unfinished pre-rework `in_progress` bounties receive a one-time `legacy_grace_days` (30d) expiry stamp when rework is enabled.

---

## 5. Community Events, Guild Systems & Game Features

* **Community Events (`api/cron_community.php`, `api/event_roster.php`, `manage_events.php`):** Supports boss-rush goals (`EP1_BOSS_RUSH`, `EP2_BOSS_RUSH`, `ALL_BOSSES`, etc.) and `ENEMY_KILL` hunts (`RARE_RAPPIES` covering all 7 rare Rappy variants, `ANY_RARE_ENEMY`, or specific enemies) with deduplicated co-op party credit, difficulty bonus points, AI milestone broadcasts, optional homepage event banner (`<!-- AUTO-MISSION-START/END -->` in `index.php`), and event HUD on `missions.php`.
* **Daily Logins & Streaks (`api/claim_daily.php`, `api/get_streak.php`, `api/claim_streak.php`):** Tracks unique play days in `daily_logins`, grants daily lootboxes + milestone streak rewards, and alerts linked Discord users via `api/cron_streak_alert.php`.
* **Tekker Store & Challenge (`api/bot_tekker_db.php`, `api/claim_tekker_drop.php`):** Bridges Discord `#tekker-challenge` weapon guessing tokens into SQLite (`tekker_*` tables) so linked players can claim and forge custom-attribute weapons directly in-game.
* **Looking for Group (`lfg.php`, `api/lfg_games.php`, `api/lfg_requests.php`, `api/lfg_join.php`, `api/lfg_leave.php`):** Live NewServ game registry + 2-hour LFG listings + browser-to-game room warps + Discord bot feed (`bot_api.php?action=get_lfg` & `get_parties`).
* **Drop Tables (`drops.php`, `api/get_drops.php`):** Searchable BB drop charts across episodes, difficulties, Section IDs, enemies, and boxes (also cached by the Discord bot).
* **Mods & Unlocks (`mods.php`, `api/mods.php`, `api/get_mods.php`, `api/submit_mod.php`, `api/rate_mod.php`, `unlocks.php`, `api/get_unlocks.php`, `api/claim_unlock.php`):** Community client mod gallery + moderation + star ratings, and character unlockables/milestones (`js/unlocks.js`).
* **Quest Editor & Uploads (`quest-editor/`, `api/upload_quest.php`):** Browser `.qst` assembler/disassembler and uploader (`quests/uploads/<user>/`).
* **Mag Feeder (`magfeeder.php`, `js/magfeeder.js`, `api/mag_inventory.php`, `api/mag_feed.php`):** Remote Mag feeding via live backpack inspection and shell-exec consumption.
* **Special Deliveries (`api/admin_special_delivery.php`, `api/redeem_special_delivery.php`):** Admin-queued item grants claimable by players in-game.
* **Kill Counts (`api/enemy_kills.php`, `api/kill_counts.php`):** Persistent per-character kill ledger by episode, difficulty, mode, and enemy.
* **Error Telemetry (`api/exception_logger.php`, `api/log_client_error.php`, `admin/exceptions.php`):** Structured PHP + browser JS error logging (`logs/player_exceptions.jsonl`).

---

## 6. Administration Control Panel (`admin/`)

All `/admin/*.php` views and `/api/admin_*.php` endpoints require `users.is_admin = 1`:
* **`/admin/dashboard.php`** — Live NewServ status, connected clients, account/claim tools (`api/admin_get_accounts.php`, `api/admin_get_claimed_characters.php`, `api/admin_reset_claim.php`, `api/admin_update_email.php`, `api/admin_delete_account.php`, `api/admin_test_mail.php`).
* **`/admin/site_settings.php`** — Edits `config/site.json`, `config/about.json`, and `config/bounties.local.json` (`api/admin_save_settings.php`) + logo upload (`api/admin_upload_image.php`).
* **`/admin/theme_manager.php`** — Theme presets, CSS variable overrides, user theme customization (`api/set_theme.php`).
* **`/admin/mission_manager.php`** — Admin bounty & community event controls.
* **`/admin/mods.php`** — Review/approve/reject submitted client mods.
* **`/admin/special_deliveries.php`** — Queue/revoke custom item deliveries (`api/admin_special_delivery.php`, `api/admin_item_search.php`, `api/admin_user_search.php`).
* **`/admin/` Bot Tokens Manager** — Issue, inspect expiry/usage, and revoke bcrypt-hashed bot API Bearer tokens (`bot_tokens`).
* **`/admin/telemetry.php` & `/admin/exceptions.php`** — Server telemetry & PHP/JS exception viewer.
* **`/admin/console_window.php` & `/admin/manual_window.php`** — Remote GM command execution (`api/admin_exec.php`) and live manual tools.

---

## 7. Automated Testing

All regression suites run standalone against temporary SQLite databases (with stubbed Gemini responses where applicable — no live game server or API key needed):

```bash
php api/test_bounty_system.php                 # Bounty rework rules, caps, queues, Battle/Challenge, contracts, rewards
php api/test_bounty_lore.php                   # Lore card integrity, floor/boss target parsing, prompt generation
php tests/test_battle_mode_bounty_tracking.php # Battle Mode 88001-88008 <-> 501-508 normalization & per-slot win deltas
php tests/test_community_rappy_event.php       # RARE_RAPPIES matching, party-wide credit, difficulty bonus points
php tests/test_portal_bounty_display.php       # Board rendering helpers, "Account Wide" normalization, Pending slots
php tests/test_sqlite_wal_snapshot_locks.php   # Concurrent WAL read-before-write cursor finalization regression
php tools/bounty_data/pool_report.php          # Validates reward pool coverage & 10x drop odds (~230k combinations)
```
