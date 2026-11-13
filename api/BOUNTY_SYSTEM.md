# Bounty System Rework

This implements the [Bounty System Rework](https://feedback.psobb.io/posts/12/bounty-system-rework) feedback post: bounty tiers, daily and weekly bounties, slots and earning caps, bounties that drop from bosses and rare enemies, Battle and Challenge Mode pools, expiry at the reset, a free reroll for team bounties, and rewards by tier whose stats stay hidden until the claim.

The flow is in `api/bounty_system.php` and the rewards in `api/bounty_rewards.php`. The numbers are in `bounty_default_config()` and `bounty_reward_defaults()` and can be overridden in `config/bounties.json` (rewards under `reward_engine`); `config/bounties.example.json` shows the format. Setting `"enabled": false` brings back the original flow: up to 3 bounties, with a 10% chance of a new one each minute.

## At a glance

| | Active slots | How they are earned | Limit |
|---|---|---|---|
| Daily | 3 | Being logged in (10% chance a minute), plus one for each daily finished | 5 earned per day |
| Weekly | 3 | Being logged in (3% chance a minute), plus one for each weekly finished | 10 earned per week |
| Drop | 5, their own | Boss kills (5–18% chance by difficulty) and rare-enemy kills (10–30%) | At most 10 held (active + waiting); after that nothing drops |
| Battle Mode | 3 dailies | Playing Battle Mode (25% chance a minute) | 3 per day |
| Challenge Mode | 3 weeklies, any tier | Playing Challenge Mode (25% chance a minute) | 3 per week |

- Slots and caps are per character by default. Set `"limit_scope": "account"` to share them across an account's characters.
- A bounty earned while its slots are full waits under **Up next** on the bounty board. When a slot of its kind frees up, the oldest waiting bounty starts. The objective and the story are written at that point, so they are set against the hunter's level and stats on the day it starts.
- The caps count every bounty earned in the period, whatever happened to it afterwards. Abandoning bounties therefore cannot be used to earn more.
- Resets come at `reset.daily_hour` (default midnight). The weekly reset comes on `reset.weekly_day` (1 = Monday). Both use the server's time zone, or `reset.timezone`.
- At the reset, bounties that are waiting, active or being rerolled **expire**. Finished bounties that haven't been claimed never expire; they stay in the Guild Claim Center.

## Tiers

| Tier | Where it comes from | Reward (stats rolled on claim) |
|---|---|---|
| Common | Free, drop, Battle and Challenge bounties | 2–3 tools and consumables in stacks, picked by level, plus a few Photon Drops and Meseta |
| Uncommon | Free, drop, Battle and Challenge bounties | 1–2 pieces of common equipment for the hunter's class, picked 10 levels ahead, with light stats |
| Rare | Free, drop, Battle and Challenge bounties | One rare that drops 1/500 to 1/5000 a kill for the hunter's Section ID, usable by their class, from the bounty's area |
| Ultra Rare | Drops in a party of 2 or more, and Challenge bounties | One rare that drops rarer than 1/5000 a kill for the hunter's Section ID (or never for it), for any class, from the bounty's area |
| Mythic Rare | Drops when playing alone, and Challenge full runs | One quest-reward item no enemy or box drops, for any class; 3% of the time an exchange-only item instead. A Challenge full run pays one of the rarest drops in the game. Always one Photon Sphere and one exchange token |

Uncommon to Mythic Rare bounties can also pay an **exchange token** (below).

- Free bounties are Common 60%, Uncommon 30% and Rare 10%. Challenge bounties are Common 45%, Uncommon 30%, Rare 17%, Ultra Rare 6% and Mythic Rare 2%. The drop odds depend on difficulty; see `drops.tier_weights`.
- Ultra and Mythic drops are weeklies. A Rare drop is a weekly half the time. The rest are dailies.
- Nothing drops in Battle or Challenge Mode games.
- **The reward is chosen when the bounty starts**, because it comes from the area the objective sends the hunter to. Waiting bounties show "Chosen when it starts". A reroll keeps the reward.
- The board shows the item and its Photon Drops and Meseta. Attributes, hit, slots and DEF/EVP bonuses roll once, when the player claims. The result is saved before the item is dropped, so a failed drop retries with the same item. The claim message tells the player what they got.

## Rewards

Rare, Ultra Rare and Mythic Rare items come from Blue Burst's own drop data, in `api/data/bounty_items.json` (see Data, below).

**Rarity, per Section ID.** An item's rate is its best chance per kill (or per box) for the hunter's own Section ID, over every enemy, box, area and difficulty that drops it. Rare enemies count with their spawn chance (1/500; Kondrieu 1/10). Final bosses always drop.

| Band | Rate per kill | Bounty tier |
|---|---|---|
| Common rares | 1/500 or better | none: normal play is already the best source |
| Rare | 1/500 to 1/5000 | Rare |
| Very rare | rarer than 1/5000, or not for this Section ID | Ultra Rare |
| Never dropped | quest-reward items | Mythic Rare |
| Rarest in the game | rarer than 1/5000 for every Section ID (35 items) | Mythic Rare Challenge full runs only |

**The 10x rule.** Each item in a pool is at least 10 times as likely from one bounty of that tier as from one kill of its best enemy for that Section ID. The pool's chances are `p = 10 × rate + (1 − 10 × total rate) / items`, so every item keeps its 10x and the rest is shared evenly. If a pool's rates were too high to allow that, its commonest items would leave the pool; with the current data none do. `php tools/bounty_data/pool_report.php` checks every Section ID, class, level and area (about 380,000 item chances).

**Area.** Items must drop in the bounty's area:

- an objective in a place (a floor, a boss) uses that place's lore setting;
- a drop whose objective isn't tied to a place uses the area it dropped in;
- a Challenge bounty uses its stages' areas;
- anything else uses its episode.

A pool smaller than 6 items widens: first to lower strength classes, then to the whole episode, then anywhere. The spec records how far it widened (`pool`).

**Strength class.** The item's level requirement for the hunter's class (for units, mags and tools, a level from its stars). It must be no higher than the hunter's level plus a head start (Rare +10, Ultra Rare +30, Mythic Rare +50), and at most one class (`1–20, 21–40, 41–60, 61–80, 81–100, 101–130, 131–160, 161–200`) below that. Rare items must be usable by the hunter's class; Ultra and Mythic Rare may be for any class.

**Weapon stats.**

- At most 2 attributes, plus hit.
- The first attribute is drawn from the area's enemies, by their share of Native, A.Beast, Machine and Dark enemies there (`api/data/bounty_areas.json`). Boss objectives weigh in the boss's own class.
- The second attribute comes from the area's other enemy classes. Where the area has only one class (each Episode 1 area), it comes from the rest of the episode.
- Caps at level 200: Uncommon 30, Rare 50, Ultra Rare 70, Mythic Rare 90. They scale down to 40% at level 1.
- Hit: Rare 25% chance (up to 25), Ultra Rare 45% (up to 40), Mythic Rare 65% (up to 50).
- Attributes never pass 90 and hit never passes 50. Values move in steps of 5.
- A Challenge full-run weapon always has 2 attributes of 60–90 and hit 30–50, at any level.

**Armor and shields.** DEF and EVP bonuses come from the item's own bonus range, scaled by tier and level. Armor slots: Uncommon 0–1, Rare 1–3, Ultra Rare 2–4, Mythic Rare 3–4.

**Photon Drops** are paid like a currency, as items:

| Tier | Common | Uncommon | Rare | Ultra Rare | Mythic Rare |
|---|---|---|---|---|---|
| Photon Drops (at level 200) | 0–2 | 1–5 | 4–15 | 20–60 | 99–150 |

- They scale down to half at level 1 (except Mythic Rare). Challenge bounties scale with their stages' length (×0.5 to ×2).
- 99 Photon Drops make one Photon Sphere, the Gallon's Shop exchange rate. A bounty pays at most one Photon Sphere, and the rest (up to 99) as one stack of Photon Drops.

**Exchange tokens.** Exchange-only items stay with their exchanges. Bounties pay what the exchanges take instead, on top of the reward:

| Tier | Chance of a token |
|---|---|
| Common | none |
| Uncommon | 20% |
| Rare | 35% |
| Ultra Rare | 60% |
| Mythic Rare | always |

| Token | Exchange | How likely | How many (Uncommon / Rare / Ultra / Mythic) |
|---|---|---|---|
| Enemy part, from the bounty's area or episode | Dr. Osto's Research, the Unsealed Door | 30 | 1 / 1 / 1–2 / 1–2 |
| Item Ticket | Item Present (Item Ticket shop) | 25 | 1 / 1 / 1 / 1–2 |
| Secret Ticket | Good Luck! lottery | 15 | 1 / 1 / 1–2 / 1–2 |
| Photon Ticket | Gallon's Plan (10, 15 or 20 Tickets) | 15 | 1 / 1–2 / 2–4 / 3–6 |
| Photon Crystal | Black Paper's deals, Dr. Montague | 10 | none / 1 / 1 / 1–2 |
| Weapons Badge | AOL CUP | 10 | Bronze / Bronze or Silver / Silver or Gold / Gold |

- The enemy parts are the 19 that Dr. Osto trades for weapons. An Episode 4 bounty, whose areas have none, pays another token.
- Photon Tickets, Secret Tickets and Photon Crystals stack (to 99) and arrive as one stack.
- Tokens are extras, outside the 10x rule. The commonest enemy parts (Dragon's Claw drops 1 in 23) already come faster from kills.

**Exchange-only items.** A Mythic Rare bounty (not a Challenge full run) has a 3% chance (`mythic_exchange_chance`) of paying one of 84 exchange-only items instead of its usual item. Left out: the Item Ticket shop's and Dr. Osto's items (bounties pay their tokens), the tokens themselves, and combination results. `exchange_allow` can still add exchange items to the Mythic Rare pool outright.

**Never rewards.**

- Challenge Mode prizes: the S-rank weapons, Gods Shields Suzaku and Byakko, Yellow Ring, and Hunter, Ranger and Force Field.
- Combination results: Merges, combination Mags and weapons.
- Items that can't be obtained, and the Easter Egg (a seasonal drop).
- Seasonal quest tools and the Dirty Lifejacket.

Bounties started before this update keep their old reward and roll it with `roll_profiles`, under the same limits (2 attributes, 90, hit 50).

## Objectives

- **Dailies** use the existing objectives (bosses, speedruns, patrols, items, techniques, materials and so on). Battle and Challenge objectives are kept for their own pools.
- **Weeklies** use new, longer objectives:
  - `AREA_SWEEP` (`E@Difficulty`): visit every area of Episode E.
  - `BOSS_HUNT` (`N@Difficulty`): defeat N bosses.
  - `ENEMY_HUNT` (`N@Difficulty`): defeat N enemies.
  - `RARE_HUNT` (`N@Difficulty`): defeat N rare enemies (Ultra and Mythic only).
  - `LEVEL`: gain 3–5 levels.

  Each counts only in Normal-mode or Solo games at that difficulty or higher. Weeklies never require Ultimate, except Mythic Rare ones. Progress is saved in `player_missions.progress` and shown on the board, for example "4 / 10 areas".
- **Battle Mode** bounties (`BATTLE_RULE_WINS`, target `quest:wins`, e.g. `503:2`) ask for 1–3 wins (Common, Uncommon, Rare) under one of the eight rule sets, named on the board with its rules:

  > Win 2 Battle Mode matches of Battle 3: Warp Battle (Spaceship, everyone at Lv5 (+3 per death), start with nothing, no Mags, techniques up to Lv1, 10 lives, no Meseta, 10 of each slow/confuse/freeze trap, 10 min)

  The rules come from the battle quests' own scripts (`api/data/battle_rule_sets.json`). Blue Burst reports battle records when the player leaves the game, so a win is credited to the battle quest (501–508) the character was last seen in.
- **Challenge Mode** bounties (`CHALLENGE_RUN`, target `episode:first-last:limit_seconds:pace`) name stages and a pace:

  | Tier | Objective | Example |
  |---|---|---|
  | Common | one stage, any time (B) | Clear Episode 1 Challenge Mode Stage 4 |
  | Uncommon | one stage at A pace | … Stage 6 in under 1 h 33 min (A pace) |
  | Rare | one stage at S pace | … Stage 6 in under 50 min (S pace) |
  | Ultra Rare | 2–3 stages at S pace or 3–4 at A pace, on their combined time | … Stages 3–5 with a combined time under 3 h 54 min (A pace) |
  | Mythic Rare | a full run, at S-rank pace | Complete an Episode 1 Challenge Mode run (Stages 1–9) with a combined time under 7 h (S rank) |

  - Each stage's **S pace** is its share of the S-rank time. They add up to the S-rank limits (7 h and 5 h): Ep1 13, 47, 40, 45, 40, 50, 60, 55, 70 min; Ep2 65, 55, 45, 85, 50 min.
  - **A pace** is S pace × A/S, using the limits in newserv's stage scripts: Ep1 13 h / 7 h, Ep2 7 h / 5 h. Some wikis give 9 h for Episode 2 A rank; the script's 7 h is what the game checks on this server.
  - **B rank** has no real limit (99 h), so B is any clear.
  - Several stages are judged on their combined time, as the game judges rank. The best time per stage since the bounty started counts, so a faster replay helps.
  - The stage offered is one the character can enter: up to their highest cleared stage + 1 (stages unlock in order). Full runs start at Stage 1.
  - All stages are played on Normal with fixed levels and gear, so the objective has no difficulty.
- **Reroll:** a bounty that needs a particular party (`MENTOR_BOSS`, `HARDCORE_MENTOR`, `DIVERSE_PARTY_BOSS`) can be rerolled once for free from the board (`api/reroll_bounty.php`). It keeps its tier, reward and slot. It gets a new objective and story the next time the character is online. The new objective is never another team objective, and it cannot be rerolled again.
- Gemini still writes every story through the lore-grounded prompt (`api/bounty_lore.php`). The prompt now also gives the tier and kind ("Rare (weekly)"), and for drops what the hunter defeated. Weekly hunts aren't tied to one area.

## On the site

- **Bounty board (`missions.php`).** Each card shows the bounty's tier, its kind, the time left before it expires and, for weeklies, its progress. Team bounties get a reroll button.
  - A **Your Bounty Slots** panel shows, for each character, the active slots, how much of each cap has been earned, and the **Up next** list. Waiting bounties can be discarded from that list.
  - The public feed shows all of your own active bounties and the 30 newest of everyone else's. A character can now hold up to 17 active bounties, so the feed is capped.
- **JSON endpoints.** `api/my_bounties.php` and `api/my_bounties_all.php` return the new fields: `tier_label`, `kind`, `expires_in`, `progress_text`, `stats_hidden` and `can_reroll`. They also include waiting and rerolling bounties and a `slots` summary. `api/bot_api.php` adds `tier_label`, `kind` and `progress_text` to each mission.
- **Reward names.** `renderRewardString()` drops the `x1` that `item_map.json` puts on stackable items ("Telepipe x4", not "Telepipe X1 x4"). For the few codes whose `item_map.json` name is a bare number (030E15 showed as "3"), it uses newserv's `names-v4.json` instead.

## Kill and clear tracking: the newserv patches

Two small patches for fuzziqersoftware/newserv add fields to `/y/clients`:

1. `0001-Report-enemy-and-rare-enemy-kill-counts-in-y-clients.patch`: `EnemyKillCount`, `RareEnemyKillCount` and `LastRareEnemyKilled`. It counts the killing blow for each player, in `on_enemy_exp_request_bb`, using newserv's own rare-enemy test. `ENEMY_HUNT`, `RARE_HUNT` and rare-enemy drops need it.
2. `0002-Report-Challenge-Mode-stage-clears-in-y-clients.patch`: `ChallengeClearCount` and `LastChallengeClear` (`QuestNumber`, `StageNumber`, `Seconds`). It counts each stage clear when the stage's time is set (6x95), once per player. Challenge quests are numbered 881NN (Episode 1 stage NN) and 882NN (Episode 2).

Without the patches the site still works:

- Weeklies don't offer the two kill objectives, and only boss kills (from `cron_boss_tracker.php`) can drop bounties.
- Challenge clears are read from changes to the stage record times (`ChallengeTimesEp1Online`, `ChallengeTimesEp2Online`). That misses replays slower than the record.

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
   1. Work out kills, Challenge clears and Battle wins since the last pass.
   2. Evaluate active bounties. Weeklies go through `bounty_evaluate_weekly()`.
   3. Then `bounty_tick_for_client()`:
      - roll drops for new boss and rare-enemy kills;
      - earn bounties for completions, being logged in and Battle/Challenge play (each under its cap);
      - start at most one waiting or rerolling bounty: its objective, story (one Gemini call) and reward.
3. Kill counters are saved in the cron's player state file. Kills from before a character was first seen don't count.

## Data

`api/data/` is generated from newserv's Blue Burst tables and quests:

```
python3 tools/bounty_data/build.py --newserv /path/to/newserv
```

| File | What it holds |
|---|---|
| `bounty_items.json` | Every BB item: name, kind, stars, how it is obtained, rate per Section ID, best source, where it drops (lore setting and episode), level requirement per class, DEF/EVP ranges, exchanges, quest rewards, combinations |
| `bounty_areas.json` | Each lore setting, floor and Challenge stage: its drop setting and its enemies' class shares |
| `battle_rule_sets.json` | The eight Battle Mode rule sets, decoded from the quest scripts |
| `challenge_mode.json` | Challenge stages, rank limits and prizes, from the stage scripts |
| `quest_item_lists.json` | Items each BB quest can create |

`tools/bounty_data/enemy_settings.json` maps enemies and boxes to lore settings, and `tools/bounty_data/area_classes.json` holds each area's enemy-class shares (from the BB spawn files; classes from community enemy databases, cross-checked against the Episode III card data).

## Testing

```
php api/test_bounty_system.php        # caps, queue, drops, tier rules, pools, objectives, expiry, reroll, rewards
php api/test_bounty_lore.php          # the lore-grounded prompt
php tools/bounty_data/pool_report.php # every reward pool: sizes and the 10x rule
```

`test_bounty_system.php` uses a throwaway database and answers Gemini prompts itself, so it needs no game server or API key. `GEMINI_API_BASE` in `.env` can point the Gemini call at a stub server when running the real cron against a test game server.

## Choices made where the post left room

- A cap counts bounties *earned* per reset. Bounties earned past the 3 + 3 slots wait under Up next instead of being lost.
- Drops have their own 5 slots and a 10-held limit, so they never block the free dailies and weeklies.
- "Mythic only solo" covers both Solo mode and being alone in a Multi-mode room. The second gets the ×1.5 odds.
- The 10x rule compares one bounty of a tier with one kill of the item's best source, for the hunter's Section ID. Rares commoner than 1/500 a kill are left to normal play, where they already come often.
- Mythic Rare items don't drop, so they aren't tied to an area.
- Uncommon equipment still comes from the level-scaled reward tables (common items drop everywhere).
- A bounty's level, for strength and stat caps, is the hunter's level when it starts.
