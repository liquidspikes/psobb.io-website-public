# PSOBB API Reference

Backend HTTP API for the website. Every endpoint is a standalone PHP
file under `/api/` and responds with `Content-Type: application/json` (a few
return binary/image data — noted where relevant).

- [Conventions](#conventions)
- [Authentication & sessions](#authentication--sessions)
- [Discord bot API (`bot_api.php` & `bot_tekker_db.php`)](#discord-bot-api-botapiphp--bot_tekker_dbphp)
- [Account & auth endpoints](#account--auth-endpoints)
- [Discord linking](#discord-linking)
- [Characters, bank & game actions](#characters-bank--game-actions)
- [Missions & community events](#missions--community-events)
- [Tekker drops & special deliveries](#tekker-drops--special-deliveries)
- [Daily logins & streaks](#daily-logins--streaks)
- [Looking-for-group (LFG)](#looking-for-group-lfg)
- [Mods & unlocks](#mods--unlocks)
- [Quests, UI, theme & telemetry](#quests-ui-theme--telemetry)
- [Info & leaderboards](#info--leaderboards)
- [Notification preferences](#notification-preferences)
- [Admin endpoints](#admin-endpoints)
- [Automation ingest endpoints](#automation-ingest-endpoints)
- [NewServ game-server interface](#newserv-game-server-interface)
- [Database schema](#database-schema)
- [Cron daemons](#cron-daemons)

---

## Conventions

**Base path:** `/api/` (or `https://<server-address>/api/`)

**Auth types** (see [Authentication](#authentication--sessions)):

| Tag | Meaning |
|-----|---------|
| 🔓 Public | No auth |
| 🔑 Session | Requires a logged-in web session (`$_SESSION['user']`) |
| 🛡️ Session + CSRF | Logged-in **and** a valid `csrf_token` (state-changing `POST`/`DELETE`) |
| 👑 Admin | Logged-in admin user (`$_SESSION['user']['is_admin']`) |
| 🤖 Bearer | `Authorization: Bearer <token>` (bot/automation) |

**CSRF & request bodies.** `verify_csrf_token()` in `api/config.php` checks the
**`X-CSRF-Token` HTTP header first**, then falls back to `$_POST['csrf_token']`
(some JSON admin endpoints like `admin_save_settings.php` also read `csrf_token`
from the decoded JSON body). Payloads are typically JSON on `php://input` (or
`multipart/form-data` for file uploads). Read endpoints take query-string
parameters.

**Error envelope.** Errors are JSON. Most endpoints use one of:

```json
{ "error": "Human-readable message" }
{ "success": false, "error": "Human-readable message" }
```

Common status codes: `200` (incl. handled errors with `{"error":…}`), `403`
(bad/missing CSRF or unauthorized), `500` (server fault — `bot_api.php` returns a
structured `{"error":"PHP fatal"|"PHP exception", "message", "file", "line"}`).

---

## Authentication & sessions

- **Login** validates credentials against NewServ's account list, not SQLite, then
  stores `$_SESSION['user'] = ['username', 'account_id', …]`.
- Sessions persist in the SQLite `sessions` table (`SQLiteSessionHandler` in
  `api/config.php`), 30-day `HttpOnly` cookie.
- A 32-byte `csrf_token` is generated per session; clients echo it back on every
  mutation. `verify_csrf_token()` returns `403` on mismatch.
- **Bot/automation** auth (`require_bot_auth()`): a Bearer token matched against
  either `BOT_API_SECRET` or a bcrypt hash in the `bot_tokens` table (revocable,
  optional expiry).

---

## Discord bot API (`bot_api.php` & `bot_tekker_db.php`)

**Auth: 🤖 Bearer** for all bot endpoints.

```
GET/POST /api/bot_api.php?action=<action>&<params>
POST     /api/bot_tekker_db.php
Authorization: Bearer <BOT_API_SECRET or bot_tokens token>
```

If unauthorized → `403 {"error":"Unauthorized"}`.
Fatals/exceptions are serialized (see [error envelope](#conventions)) so the bot
never receives an opaque empty `500`.

### `bot_api.php?action=link` — link a website account to a Discord ID
`POST` · form/body params:

| Param | Required | Description |
|-------|----------|-------------|
| `username` | yes | Website username |
| `discord_id` | yes | Discord user ID to attach |

Response:
```json
{ "success": true }                                  // linked
{ "error": "User not found or already linked" }      // no row updated
{ "error": "Missing data" }                          // missing param
```

### `bot_api.php?action=get_player` — full player profile
`GET` · params: `discord_id` (required).

Looks up the `users` row by `discord_id`. If none → `{"error":"Not linked"}`.
Otherwise resolves the Blue Burst username, overlays **live** client data from
NewServ `/y/clients` (when online) on top of **save-file** data parsed from
`player_<user>_<slot>.psochar` slots, and merges website stats.

Response (abridged):
```json
{
  "website_username": "player1",
  "account_id": 1234,
  "language": "en",
  "is_online": true,
  "account": { "guild_card": 1234, "is_shared_bank_enabled": false },
  "shared_bank": { "meseta": 0, "item_count": 0 },
  "characters": [
    {
      "slot": 0, "exists": true,
      "name": "Hunter", "class": "HUmar", "level": 142,
      "section_id": "Viridia", "experience": 80100000,
      "play_time_hours": 51.2, "is_online": true,
      "stats": { "ATP": 700, "DFP": 350, "MST": 50, "ATA": 200,
                 "EVP": 400, "LCK": 100, "HP": 1200, "Meseta": 999999 },
      "mats": { "HP": 0, "TP": 0, "Power": 250, "Mind": 0,
                "Evade": 0, "Def": 0, "Luck": 0 },
      "inventory": [ { "name": "Red Saber +9", "equipped": true, "attrs": [...] } ],
      "bank_meseta": 12345,
      "quest_progress": { "Normal": { "Forest": true, … }, "Hard": { … }, … }
    },
    { "slot": 1, "exists": false }
  ],
  "website_stats": {
    "total_login_days": 37,
    "completed_bounties": 14,
    "missions": [
      {
        "title": "...", "goal_type": "LEVEL", "goal_target": "50",
        "character_name": "Hunter",
        "status": "in_progress", "friendly_objective": "Reach Level 50",
        "tier_label": "Common", "kind": "Daily", "progress_text": "…"
      }
    ]
  }
}
```

Account-wide pools (Battle / Challenge) normalize `character_name` to `"Account Wide"`.
Empty slots are returned as `{ "slot": N, "exists": false }`; a slot whose file
cannot be parsed adds `"error": "parse_failed"`.

### `bot_api.php?action=get_online_players` — linked players currently online
`GET` · no params.

Fetches NewServ `/y/clients`, joins against linked `users.discord_id`, and
returns only online clients that belong to a linked account. Returns `[]` when
NewServ is unreachable or nobody linked is online.

```json
[ { "account_id": 1234, "discord_id": "335974112046350341", "name": "Hunter" } ]
```

Drives the bot's role-sync "Path A" (mirror in-game class/level/Section ID into Discord roles).

### `bot_api.php?action=get_linked_players` — all linked accounts
`GET` · no params.

Returns every `users` row that has a non-empty `discord_id`, regardless of
online status. Drives the bot's `!sync all` command.

```json
[ { "account_id": 1234, "discord_id": "335974112046350341", "username": "player1" } ]
```

### `bot_api.php?action=get_lfg` — recent Looking-For-Group posts
`GET` · optional param: `since_id` (only return posts with `id` greater than this).

Returns LFG posts from the **last 2 hours**, newest-id last, with bounty enrichment,
`game_mode` parsed from the room prefix, and the poster's `discord_id`. Text is
returned raw (not HTML-escaped) for Discord, alongside `latest_id`.

### `bot_api.php?action=get_parties` — live multiplayer instances
`GET` · no params.

Joins NewServ `/y/lobbies` (`IsGame`) → `ClientIDs` → `/y/clients`, resolving each
player's linked `discord_id` from `users` for dynamic party voice channels. Returns
`{"success":false,"parties":[]}` if NewServ is unreachable.

### `bot_api.php?action=get_events` — active community events
`GET` · no params. Returns active rows from `community_events` with rendered
objective/reward text.

### `bot_tekker_db.php` — Tekker Challenge persistence backend
`POST` · 🤖 Bearer · JSON body `{ "op": "<operation>", ... }`:

Provides SQLite storage (`tekker_*` tables) for the Discord bot's Tekker Challenge:
active drops, guesses, cooldown/player state, telemetry counters, claimed tokens,
active user tracking, and channel/drop settings.

---

## Account & auth endpoints

| Endpoint | Method | Auth | Params | Purpose |
|----------|--------|------|--------|---------|
| `login.php` | POST | 🔓 | JSON `username`, `password` | Validate vs NewServ accounts, start session, self-heal `users` row |
| `register.php` | POST | 🔓 | JSON `username`, `password`, `email` | Create website + NewServ account |
| `delete_account.php` | POST | 🛡️ | `csrf_token`, JSON `password` | Delete the logged-in user's account |
| `change_password.php` | POST | 🛡️ | `csrf_token`, password fields | Change password (synced to NewServ) |
| `forgot_password.php` | POST | 🔓 | email | Email a reset token |
| `reset_password.php` | POST | 🔓 | token, new password | Complete a password reset |
| `get_account_email.php` | GET | 🔑 | — | Get user's current recovery email & legacy/pending status |
| `set_account_email.php` | POST | 🛡️ | `csrf_token`, JSON `email` | Send confirmation link to verify and link recovery email |
| `captcha.php` | GET | 🔓 | — | Render a CAPTCHA image into the session |

---

## Discord linking

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `discord_auth.php` | GET | 🔑 | Begin Discord OAuth2 (sets `$_SESSION['discord_state']`, redirects) |
| `discord_callback.php` | GET | 🔑 | OAuth2 callback; writes `users.discord_id` |
| `discord_unlink.php` | GET/POST | 🔑 | Clear `users.discord_id` and redirect to `login.php` |

---

## Characters, bank & game actions

| Endpoint | Method | Auth | Params | Purpose |
|----------|--------|------|--------|---------|
| `character_viewer.php` | GET | 🔑 | `slot` | Parse and return a character + bank from save files |
| `kill_counts.php` | GET | 🔑 | `character` | Character's lifetime kills by episode, difficulty, mode, and enemy |
| `account_data.php` | GET | 🔑 | — | Account-level dashboard data |
| `download_character.php` | GET | 🔑 | `slot` | Download character save data as a ZIP |
| `switch_character.php` | POST | 🛡️ | `csrf_token`, JSON `slot` | Set the active character slot |
| `change_section_id.php` | POST | 🛡️ | `csrf_token`, JSON `character_name`, `new_section_id` | Change a character's Section ID |
| `bank_swap.php` | POST | 🛡️ | `csrf_token`, JSON `target_bank_index` | Switch between shared and character banks in-game |
| `mag_feed.php` | POST | 🛡️ | `csrf_token`, JSON `mag_item_id`, `feed_item_id` | Feed an item to a Mag |
| `mag_inventory.php` | GET | 🔑 | — | List Mags / feedable items |
| `reset_materials.php` | POST | 🛡️ | `csrf_token` | Recalibrate / reset stat materials |
| `send_chat_message.php` | POST | 🛡️ | `csrf_token`, JSON `character_name`, `message` | Send an in-game chat message (web → game) |
| `get_display_name.php` | GET | 🔑 | — | Get the leaderboard alias |
| `set_display_name.php` | POST | 🛡️ | `csrf_token`, JSON `display_name` | Set the leaderboard alias |

These actions reach NewServ over `/y/*` routes (`api/newserv_client.php` and `newserv_shell_exec()`).

---

## Missions & community events

| Endpoint | Method | Auth | Params | Purpose |
|----------|--------|------|--------|---------|
| `my_bounties.php` | GET | 🔑 | — | Active/waiting/claimable bounties + `slots` summary + rework metadata + community events (also triggers `bounty_ensure_account_login_starters()`) |
| `my_bounties_all.php` | GET | 🔑 | — | Portal endpoint returning the user's bounties with `"Account Wide"` normalization + `slots` summary |
| `reroll_bounty.php` | POST | 🛡️ | `csrf_token`, JSON `player_mission_id` | Request the one-time free reroll for a team bounty (`MENTOR_BOSS`, `HARDCORE_MENTOR`, `DIVERSE_PARTY_BOSS`) |
| `redeem_bounty.php` | POST | 🛡️ | `csrf_token`, JSON `player_mission_id` | Claim a completed bounty's reward |
| `abandon_bounty.php` | POST | 🛡️ | `csrf_token`, JSON `player_mission_id` | Abandon an active or discard a queued bounty |
| `bounty_check.php` | GET | 🔑 | — | Lightweight change-detection poll for the UI |
| `get_events.php` | GET | 🔓 | `status` | List community events (CORS `*`) |
| `redeem_community.php` | POST | 🛡️ | `csrf_token`, JSON `event_id` | Claim a completed community-event reward |
| `manage_events.php` (root) | CLI | — | `--rotate`, etc. | CLI-only event rotation/management script (returns 403 over HTTP; includes `api/event_roster.php`) |

---

## Tekker drops & special deliveries

| Endpoint | Method | Auth | Params | Purpose |
|----------|--------|------|--------|---------|
| `claim_tekker_drop.php` | GET / POST | 🔑 / 🛡️ | `POST` JSON `token_ids` | List unclaimed Discord Tekker tokens (`GET`) or forge/drop claimed custom weapons in-game (`POST`) |
| `redeem_special_delivery.php` | GET / POST | 🔑 / 🛡️ | `POST` JSON `delivery_id` | List pending admin-issued item deliveries (`GET`) or claim an item in-game (`POST`) |

---

## Daily logins & streaks

| Endpoint | Method | Auth | Params | Purpose |
|----------|--------|------|--------|---------|
| `claim_daily.php` | POST | 🛡️ | `csrf_token` | Claim the daily roulette reward |
| `get_streak.php` | GET | 🔑 | — | Current login-streak status and next milestone |
| `claim_streak.php` | POST | 🛡️ | `csrf_token`, JSON `milestone` | Claim a streak milestone reward |

---

## Looking-for-group (LFG)

| Endpoint | Method | Auth | Params | Purpose |
|----------|--------|------|--------|---------|
| `lfg_games.php` | GET | 🔑 | — | Unified registry of active games/lobbies |
| `lfg_requests.php` | GET / POST / DELETE | 🔑 / 🛡️ | `POST` JSON party details; `DELETE` `?id=` | List active LFG listings (`GET`, last 2h), create a listing (`POST`), or remove own listing (`DELETE`) |
| `lfg_join.php` | POST | 🛡️ | `csrf_token`, JSON `lobby_id` | Browser-to-game join warp |
| `lfg_leave.php` | POST | 🛡️ | `csrf_token` | Leave the current group |
| `get_lobby_feed.php` | GET | 🔑 | — | Live lobby feed |

---

## Mods & unlocks

| Endpoint | Method | Auth | Params | Purpose |
|----------|--------|------|--------|---------|
| `mods.php` | GET | 🔓 | — | Full approved client mods catalog with rating aggregates (CORS `*`) |
| `get_mods.php` | GET | 🔓 | `category` | List approved mods filtered by category (CORS `*`) |
| `submit_mod.php` | POST | 🛡️ | `csrf_token`, mod metadata + file upload | Submit a mod for admin review |
| `rate_mod.php` | POST | 🛡️ | `csrf_token`, JSON `mod_id`, `rating` | Rate a mod (1–5) |
| `get_unlocks.php` | GET | 🔑 | — | Unlockable content list & character claim state |
| `claim_unlock.php` | POST | 🛡️ | `csrf_token`, JSON `unlock_id`, `slot` | Claim an unlockable milestone reward |

---

## Quests, UI, theme & telemetry

| Endpoint | Method | Auth | Params | Purpose |
|----------|--------|------|--------|---------|
| `upload_quest.php` | POST | 🔑 | multipart `.qst` (+ `.json` metadata) | Upload custom quests under the player's quest upload directory |
| `set_theme.php` | GET / POST | 🔓 / 👑 | `action=set_user_theme` (public); `action=save_global_theme` (Admin + CSRF) | Switch per-user cookie theme preset or update global `config/theme.json` |
| `manifest.php` | GET | 🔓 | — | Dynamic PWA `application/manifest+json` using site branding |
| `log_client_error.php` | POST | 🔓 | JSON error report | Rate-limited frontend JS exception logger (`logs/player_exceptions.jsonl`) |

---

## Info & leaderboards

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `summary.php` | GET | 🔓 | Server summary (population, status) from NewServ `/y/summary` |
| `server.php` | GET | 🔓 | Server status & live rates |
| `get_drops.php` | GET | 🔓 | Drop-table data (consumed by website & bot drops cache) |
| `team_info.php` | GET | 🔑 | Team/guild roster & info |
| `set_lang.php` | GET | 🔓 | Set `psobb_lang` cookie and redirect safely (also persists `users.language` when logged in) |

*(Note: `api/event_roster.php` is a PHP include defining `$roster` for `manage_events.php`, not an HTTP endpoint.)*

---

## Notification preferences

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `toggle_system_mail.php` | POST | 🛡️ | Toggle `users.receive_system_mail` |
| `toggle_discord_streak.php` | POST | 🛡️ | Toggle `users.receive_discord_streak_msg` |

---

## Admin endpoints

All require 👑 admin (`$_SESSION['user']['is_admin']`); state-changing requests also validate CSRF.

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `admin_get_accounts.php` | GET | List website & NewServ accounts |
| `admin_get_players.php` | GET | List players via NewServ |
| `admin_get_claimed_characters.php` | GET | Claimed-character report |
| `admin_reset_claim.php` | POST | Reset a character milestone claim |
| `admin_save_settings.php` | POST | Save `config/site.json`, `config/about.json`, `config/bounties.local.json`, and sync Discord invite to `config/theme.json` |
| `admin_upload_image.php` | POST | Upload branding/hero logo image (`img/`) |
| `admin_special_delivery.php` | POST | List, create, or revoke admin Special Deliveries (`special_deliveries`) |
| `admin_item_search.php` | GET | Autocomplete item catalog for admin delivery tools |
| `admin_user_search.php` | GET | Autocomplete users for admin tools |
| `admin_delete_account.php` | POST | Delete an account |
| `admin_update_email.php` | POST | Modify/assign recovery email for any account |
| `admin_change_*` / `change_password.php` | POST | Admin credential changes |
| `admin_bot_tokens.php` | GET/POST | Create / list / revoke `bot_tokens` for the bot API |
| `admin_exec.php` | POST | Run a shell/`/y/shell-exec` command (admin only) |
| `admin_test_mail.php` | POST | Send a test email |
| `promote_admin.php` (root) | — | Promote a user to admin |

---

## Automation ingest endpoints

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `telemetry_ingest.php` | POST | 🤖 Bearer | Receive telemetry payloads |
| `wiki_ingest.php` | POST | 🤖 Bearer | Ingest wiki/RAG documentation |

---

## NewServ game-server interface

The PHP layer communicates with NewServ over HTTP (`$NEWSERV_API_URL`) via `api/newserv_client.php` (`newserv_http_get_json()`, `newserv_fetch_clients()`, `newserv_fetch_lobbies()`, `newserv_fetch_summary()`) and `newserv_shell_exec()` (`api/config.php`, encoding UTF-8 strings cleanly for phosg `\u00XX`). All HTTP reads degrade gracefully when NewServ is unreachable and normalize Shift-JIS/control characters cleanly.

| Route | Used for |
|-------|----------|
| `/y/clients` | Live connected clients (`AccountID`, `Name`, `BBCharacterIndex`, `LobbyID`, position, live stats/inventory, kill & Challenge clear telemetry) |
| `/y/accounts` | All accounts + BB licenses (credential validation, BB-username resolution) |
| `/y/account/` | Single-account operations |
| `/y/lobbies` | Active lobbies/games (`IsGame`, `Episode`, `Difficulty`, `ClientIDs` for LFG & episode-aware boss tracking) |
| `/y/summary` | Server population/status summary |
| `/y/server` | Server status |
| `/y/config` | Server configuration |
| `/y/shell-exec` | Execute an in-game/server command (chat, warps, item drops, admin ops) |

Character save data is read from `get_newserv_players_dir()` (`NEWSERV_PLAYERS_DIR` / `config/site.json`, defaulting to `/opt/newserv/system/players/`): `player_<user>_<slot>.psochar` and `shared_bank_<user>.psobank`, parsed by `api/character_viewer.php`.

---

## Database schema

SQLite at `get_db_path()` (default `db/website.db`) in **WAL mode** (`busyTimeout = 30000ms`), initialized by `db/init_db.php` and migrated atomically by `api/db.php` (`PRAGMA user_version` / `PSO_DB_SCHEMA_VERSION = 2` — 25 verified tables). Callers always finalize/close read cursors before writes to prevent `SQLITE_BUSY_SNAPSHOT`.

| Table | Purpose |
|-------|---------|
| `users` | Website accounts: `username`, `email`, `account_id` (NewServ), `discord_id`, `is_admin`, `language`, notification prefs |
| `sessions` | Server-side session storage (`id`, `data`, `last_accessed`) |
| `bot_tokens` | Hashed bot API tokens (`token_hash`, `revoked`, `expires_at`, `last_used_at`) |
| `daily_logins` | Unique play-days per account (streak source) |
| `daily_rewards` | Daily reward claim records |
| `streak_claims` | Claimed streak milestones per cycle |
| `missions` | Mission catalog (`goal_type`, `goal_target`, `reward_item_string`, `tier`, `cadence`, `pool`, `source`, `reward_spec`, `meta`) |
| `player_missions` | Per-player mission state & progress (`period_key`, `expires_at`, `earned_at`, `activated_at`, `rerolled`, `progress`) |
| `community_events` | Server-wide events (`target_amount`, `current_progress`, `status`, milestone announcements) |
| `community_event_participants` | Per-player community-event participation |
| `mods` | Submitted client mods (`status`: pending/approved) |
| `mod_ratings` | Per-account mod ratings (1–5) |
| `lfg_requests` | Looking-for-group requests |
| `password_resets` | Password-reset tokens |
| `email_confirmations` | Pending and verified email confirmation tokens |
| `rewards_claimed` | Per-character level-milestone reward claims |
| `special_deliveries` | Admin-issued direct player item deliveries (`recipient_id`, `item_string`, `status`) |
| `enemy_kill_counts` | Lifetime per-character kills by `episode`, `difficulty`, `mode`, and `enemy` |
| `tekker_active_drops` | Active `#tekker-challenge` weapon drops for the Discord bot |
| `tekker_player_state` | Per-Discord-user Tekker Challenge guess cooldown/state |
| `tekker_telemetry` | Tekker Challenge telemetry metrics |
| `tekker_tokens` | Claimable Discord Tekker forging tokens |
| `tekker_active_users` | Active Discord Tekker participants |
| `tekker_settings` | Discord Tekker configuration & idempotent migration state flags |
| `tekker_claim_log` | Audit log of claimed Discord Tekker tokens |

---

## Cron daemons

Run out-of-band (not HTTP-routed); they read live NewServ state and write to SQLite.

| Script | Role |
|--------|------|
| `cron_missions.php` | Runs a 55s/10s loop: expires past-reset bounties, tracks daily/weekly/contract/Battle/Challenge progress, drains queued offline starters within `max_activations_per_pass`, auto-upgrades fallback stories once AI recovers, and updates `enemy_kill_counts` |
| `cron_community.php` | Drives community events (incl. `ENEMY_KILL` `RARE_RAPPIES`, boss rushes, party-wide credit) and AI milestones |
| `cron_boss_tracker.php` | Polls NewServ `/y/clients` & `/y/lobbies` every 5s for episode-aware boss kills and floor clear events |
| `cron_streak_alert.php` | DMs linked Discord players (via `BOT_TOKEN`) before a login streak expires |

---

*See [`../DEVELOPER_GUIDE.md`](../DEVELOPER_GUIDE.md) for the high-level architecture narrative and [`../../psobb-discord-bot`](../../psobb-discord-bot) for the Discord bot consumer.*
