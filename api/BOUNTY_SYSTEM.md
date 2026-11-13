# Bounty System Rework

This implements the [Bounty System Rework](https://feedback.psobb.io/posts/12/bounty-system-rework) feedback post: bounty tiers, daily and weekly bounties, slots and earning caps, bounties that drop from bosses and rare enemies, Battle and Challenge Mode pools, expiry at the reset, a free reroll for team bounties, and rewards by tier whose stats stay hidden until the claim.

All of it is in `api/bounty_system.php`. The numbers are in `bounty_default_config()` and can be overridden in `config/bounties.json`; `config/bounties.example.json` shows the format. Setting `"enabled": false` brings back the original flow: up to 3 bounties, with a 10% chance of a new one each minute.

## At a glance

| | Active slots | How they are earned | Limit |
|---|---|---|---|
| Daily | 3 | Being logged in (10% chance a minute), plus one for each daily finished | 5 earned per day |
| Weekly | 3 | Being logged in (3% chance a minute), plus one for each weekly finished | 10 earned per week |
| Drop | 5, their own | Boss kills (5–18% chance by difficulty) and rare-enemy kills (10–30%) | At most 10 held (active + waiting); after that nothing drops |
| Battle Mode | 3 dailies | Playing Battle Mode (25% chance a minute) | 3 per day |
| Challenge Mode | 3 weeklies | Playing Challenge Mode (25% chance a minute) | 3 per week |

- Slots and caps are per character by default. Set `"limit_scope": "account"` to share them across an account's characters.
- A bounty earned while its slots are full waits under **Up next** on the bounty board. When a slot of its kind frees up, the oldest waiting bounty starts. The objective and the story are written at that point, so they are set against the hunter's level and stats on the day it starts.
- The caps count every bounty earned in the period, whatever happened to it afterwards. Abandoning bounties therefore cannot be used to earn more.
- Resets come at `reset.daily_hour` (default midnight). The weekly reset comes on `reset.weekly_day` (1 = Monday). Both use the server's time zone, or `reset.timezone`.
- At the reset, bounties that are waiting, active or being rerolled **expire**. Finished bounties that haven't been claimed never expire; they stay in the Guild Claim Center.

## Tiers

| Tier | Where it comes from | Reward (bases shown on the board; stats rolled on claim) |
|---|---|---|
| Common | Free and drop bounties | 2–3 tools and consumables in stacks, picked by level, plus Meseta |
| Uncommon | Free and drop bounties | 1–2 pieces of common equipment for the hunter's class, picked 10 levels ahead. Light stats: up to 2 attributes, small DEF/EVP bonuses, 0–1 slots |
| Rare | Free and drop bounties | One rare (weapon, armor, shield or unit), or common equipment with better attributes and a chance of hit %. Picked 10 levels ahead |
| Ultra Rare | Drops only, in a party of 2 or more (Multi mode) | A Mag cell, a Mag-evolution item or a combination item, plus rare equipment picked 50 levels ahead for **any** class. Strong stats |
| Mythic Rare | Drops only, when playing alone (Solo mode, or alone in a Multi-mode room, where the odds are ×1.5) | Parasitic Gene "Flow", a Photon Crystal or a Heaven Striker Coat, plus rare equipment picked 50 levels ahead for any class. The strongest stats |

- Free bounties are Common 60%, Uncommon 30% and Rare 10%. The drop odds depend on difficulty; see `drops.tier_weights`.
- Ultra and Mythic drops are weeklies. A Rare drop is a weekly half the time. The rest are dailies.
- Nothing drops in Battle or Challenge Mode games.
- Attribute and hit caps scale with the difficulty the bounty came from: Normal ×0.6, Hard ×0.8, Very Hard ×1.0, Ultimate ×1.2. Values move in steps of 5.
- The item bases are picked when the bounty is earned, and the board shows them. Attributes, hit, slots and DEF/EVP bonuses roll once, when the player claims. The result is saved before the item is dropped, so a failed drop retries with the same item. The claim message tells the player what they got.

## Objectives

- **Dailies** use the existing objectives (bosses, speedruns, patrols, items, techniques, materials and so on). Battle and Challenge objectives are kept for their own pools.
- **Weeklies** use new, longer objectives:
  - `AREA_SWEEP` (`E@Difficulty`): visit every area of Episode E.
  - `BOSS_HUNT` (`N@Difficulty`): defeat N bosses.
  - `ENEMY_HUNT` (`N@Difficulty`): defeat N enemies.
  - `RARE_HUNT` (`N@Difficulty`): defeat N rare enemies (Ultra and Mythic only).
  - `LEVEL`: gain 3–5 levels.

  Each counts only in Normal-mode or Solo games at that difficulty or higher. Weeklies never require Ultimate, except Mythic Rare ones. Progress is saved in `player_missions.progress` and shown on the board, for example "4 / 10 areas".
- **Battle Mode** bounties ask for 1–2 more Battle wins. **Challenge Mode** bounties ask for 2–4 more Challenge stages.
- **Reroll:** a bounty that needs a particular party (`MENTOR_BOSS`, `HARDCORE_MENTOR`, `DIVERSE_PARTY_BOSS`) can be rerolled once for free from the board (`api/reroll_bounty.php`). It keeps its tier, reward and slot. It gets a new objective and story the next time the character is online. The new objective is never another team objective, and it cannot be rerolled again.
- Gemini still writes every story through the lore-grounded prompt (`api/bounty_lore.php`). The prompt now also gives the tier and kind ("Rare (weekly)"), and for drops what the hunter defeated. Weekly hunts aren't tied to one area.

## On the site

- **Bounty board (`missions.php`).** Each card shows the bounty's tier, its kind, the time left before it expires and, for weeklies, its progress. Team bounties get a reroll button.
  - A **Your Bounty Slots** panel shows, for each character, the active slots, how much of each cap has been earned, and the **Up next** list. Waiting bounties can be discarded from that list.
  - The public feed shows all of your own active bounties and the 30 newest of everyone else's. A character can now hold up to 17 active bounties, so the feed is capped.
- **JSON endpoints.** `api/my_bounties.php` and `api/my_bounties_all.php` return the new fields: `tier_label`, `kind`, `expires_in`, `progress_text`, `stats_hidden` and `can_reroll`. They also include waiting and rerolling bounties and a `slots` summary. `api/bot_api.php` adds `tier_label`, `kind` and `progress_text` to each mission.
- **Reward names.** `renderRewardString()` drops the `x1` that `item_map.json` puts on stackable items ("Telepipe x4", not "Telepipe X1 x4"). For the few codes whose `item_map.json` name is a bare number (030E15 showed as "3"), it uses newserv's `names-v4.json` instead.

## Kill tracking: the newserv patch

`ENEMY_HUNT`, `RARE_HUNT` and rare-enemy drops need three fields that stock newserv doesn't report: `EnemyKillCount`, `RareEnemyKillCount` and `LastRareEnemyKilled` in `/y/clients`. The patch `0001-Report-enemy-and-rare-enemy-kill-counts-in-y-clients.patch` (for fuzziqersoftware/newserv) adds them. It counts the killing blow for each player, in `on_enemy_exp_request_bb`, using newserv's own rare-enemy test.

Without the patch the site still works. Weeklies just don't offer the two kill objectives, and only boss kills (from `cron_boss_tracker.php`) can drop bounties.

## Bounty states

`player_missions.status`:

| Status | Meaning |
|---|---|
| `queued` | Earned and waiting under Up next. Only its tier, kind and reward bases exist (`missions.goal_type = 'PENDING'`) |
| `in_progress` | Active |
| `rerolling` | Its reroll was requested. It keeps its slot and gets a new objective next pass |
| `ready_to_redeem` → `redeeming` → `completed` | As before. Stats roll at `redeeming` |
| `expired` | Not finished by its reset |
| `abandoned` | As before. Waiting bounties can be discarded too |

New columns, added by `api/db.php` on first load:

- `missions`: `tier`, `cadence`, `pool`, `source`, `reward_spec` (JSON: item bases and roll profiles, plus `rolled` after the claim), `meta`.
- `player_missions`: `period_key`, `expires_at`, `earned_at`, `activated_at`, `rerolled`, `progress`.

Bounties from before the rework have no cadence. They keep working as before: they never expire and don't use the new slots.

## Cron flow (`api/cron_missions.php`, each pass)

1. Expire anything past its reset.
2. For each online player:
   1. Work out kills since the last pass.
   2. Evaluate active bounties. Weeklies go through `bounty_evaluate_weekly()`.
   3. Then `bounty_tick_for_client()`:
      - roll drops for new boss and rare-enemy kills;
      - earn bounties for completions, being logged in and Battle/Challenge play (each under its cap);
      - start at most one waiting or rerolling bounty. Each start is one Gemini call.
3. Kill counters are saved in the cron's player state file. Kills from before a character was first seen don't count.

## Testing

```
php api/test_bounty_system.php   # caps, queue, drops, tier rules, pools, weeklies, expiry, reroll, reward rolls
php api/test_bounty_lore.php     # the lore-grounded prompt
```

`test_bounty_system.php` uses a throwaway database and answers Gemini prompts itself, so it needs no game server or API key. `GEMINI_API_BASE` in `.env` can point the Gemini call at a stub server when running the real cron against a test game server.

## Choices made where the post left room

- A cap counts bounties *earned* per reset. Bounties earned past the 3 + 3 slots wait under Up next instead of being lost.
- Drops have their own 5 slots and a 10-held limit, so they never block the free dailies and weeklies.
- "Mythic only solo" covers both Solo mode and being alone in a Multi-mode room. The second gets the ×1.5 odds.
- The "+10 / +50 levels" for equipment is applied to the level used to pick from the level-scaled reward tables. The item requirement data in `item_hex.txt` comes from several game versions and isn't reliable enough to read directly.
