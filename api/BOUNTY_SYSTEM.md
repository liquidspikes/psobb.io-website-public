# Bounty System Rework

This implements the [Bounty System Rework](https://feedback.psobb.io/posts/12/bounty-system-rework) feedback post: bounty tiers, daily and weekly bounties, slots and earning caps, bounties that drop from bosses and rare enemies, Battle and Challenge Mode pools, expiry at the reset, a free reroll for team bounties, and rewards by tier whose stats stay hidden until the claim. On top of the post: **contracts**, big Ultra Rare and Mythic Rare jobs that never expire (full episode runs and rare-creature hunts).

The flow is in `api/bounty_system.php`, contracts in `api/bounty_contracts.php` and the rewards in `api/bounty_rewards.php`. The numbers are in `bounty_default_config()` and `bounty_reward_defaults()` and can be overridden in `config/bounties.json` (rewards under `reward_engine`); `config/bounties.example.json` shows the format. Setting `"enabled": false` brings back the original flow: up to 3 bounties, with a 10% chance of a new one each minute.

## At a glance

| | Active slots | How they are earned | Limit |
|---|---|---|---|
| Daily | 3 | Being logged in (10% chance a minute), plus one for each daily finished | 5 earned per day |
| Weekly | 3 | Being logged in (3% chance a minute), plus one for each weekly finished | 10 earned per week |
| Drop | 5, their own | Boss kills (5–18% chance by difficulty) and rare-enemy kills (10–30%) | At most 10 held (active + waiting); after that nothing drops |
| Battle Mode | 3 dailies (per account) | Playing Battle Mode (25% chance a minute), Normal/Solo play (10% chance a minute), plus one on completion | 3 per day per account |
| Challenge Mode | 3 weeklies (per account), any tier | Playing Challenge Mode (25% chance a minute), Normal/Solo play (3% chance a minute), plus one on completion | 3 per week per account |
| Contract | 3, their own (no waiting) | A quarter of Ultra Rare and Mythic Rare drops | 3 held; with 3 held, such a drop stays a normal weekly drop. Never expire |

- Standard, drop, and contract slots/caps are per character by default (`"limit_scope": "account"` shares all pools across an account). **Battle Mode and Challenge Mode (`pools.battle.scope`, `pools.challenge.scope`) are per account by default**, so any character on the account shares those slots, advances progress, and can claim rewards.
- A bounty earned while its slots are full waits under **Up next** on the bounty board. When a slot of its kind frees up, the oldest waiting bounty starts. The objective and the story are written at that point, so they are set against the hunter's level and stats on the day it starts.
- The caps count every bounty earned in the period, whatever happened to it afterwards. Abandoning bounties therefore cannot be used to earn more.
- Resets come at `reset.daily_hour` (default midnight). The weekly reset comes on `reset.weekly_day` (1 = Monday). Both use the server's time zone, or `reset.timezone`.
- At the reset, bounties that are waiting, active or being rerolled **expire**. Finished bounties that haven't been claimed never expire; they stay in the Guild Claim Center. Contracts never expire.

## Tiers

| Tier | Where it comes from | Reward (stats rolled on claim) |
|---|---|---|
| Common | Free, drop, Battle and Challenge bounties | 2–3 tools and consumables in stacks, picked by level, plus Meseta |
| Uncommon | Free, drop, Battle and Challenge bounties | 1–2 pieces of common equipment for the hunter's class, picked 10 levels ahead, with light stats |
| Rare | Free, drop, Battle and Challenge bounties | One rare from any Section ID's drop table in the Rare band (1/5,000 to 1/20,000 a kill server-wide, by effort), usable by the hunter's class, from the bounty's area |
| Ultra Rare | Drops in a party of 2 or more, and Challenge bounties | One rare from any Section ID's drop table rarer than the Rare band (1/20,000 a kill server-wide, by effort), for any class, from the bounty's area |
| Mythic Rare | Drops when playing alone, and Challenge full runs | One quest-reward item no enemy or box drops, for any class; 3% of the time an exchange-only item instead. A Challenge full run pays one of the rarest drops in the game. Always one exchange token |

Uncommon to Mythic Rare bounties can also pay an **exchange token** (below).

- Free bounties are Common 60%, Uncommon 30% and Rare 10%. **Battle and Challenge bounties both use `[15, 25, 35, 18, 7]` across Common / Uncommon / Rare / Ultra Rare / Mythic Rare** (60% Rare or higher), ignore character level (drawing from the full `str <= 200` item pool with full level-200 stat caps), and **always pay Photon Drops (100%)**. The drop odds depend on difficulty; see `drops.tier_weights`.
- Ultra and Mythic drops are weeklies. A Rare drop is a weekly half the time. The rest are dailies.
- Nothing drops in Battle or Challenge Mode games.
- **The reward is chosen when the bounty starts**, because it comes from the area the objective sends the hunter to. Waiting bounties show "Chosen when it starts". A reroll keeps the reward.
- The board shows the item and its Photon Drops and Meseta. Attributes, hit, slots and DEF/EVP bonuses roll once, when the player claims. The result is saved before the item is dropped, so a failed drop retries with the same item. The claim message tells the player what they got.

## Rewards

Rare, Ultra Rare and Mythic Rare items come from Blue Burst's own drop data, in `api/data/bounty_items.json` (see Data, below).

**Rarity, by effort.** A drop's chance per kill of one enemy says little on its own. An area full of Boomas makes a Booma drop easy to farm. Only 1 in 500 Hildebears is a Hildeblue, and a boss is met once per run of its area. So an item's rate is measured in the place a player would farm it, counting how many of each enemy and box that place has:

- A **place** is a single floor, or an area's full run with its boss (Forest 1 and 2 with the Dragon, Ruins 1 to 3 with Dark Falz, and so on).
- **Drops per run** = for each source in the place, how many of it the place has × its chance per kill (or per box).
  - A rare form counts on its base enemy's spawns, with its replacement chance (1/500; Kondrieu 1/10; Merikle and Mericus 1/5).
  - A boss counts once per run (Saint-Milion and Shambertin half each; Barba Ray's ten joints ten).
- **Rate** = drops per run ÷ enemies killed in the run, for the best place, difficulty and Section ID.
- The counts come from newserv's Blue Burst map files (`tools/bounty_data/maps.py`), averaged over each floor's online layouts.
- A few enemies only appear in quests (the Control Tower's Del Lily, Ill Gill and Epsilon, and random Mericarols). No free-play map has them, so they count as one enemy type among a typical area's dozen.

For example, Red Ring drops about 1 in 37 Dark Falz kills for Whitill. One Dark Falz comes per Ruins run of about 514 enemies, so its rate is about 1 in 18,800 kills. A Booma drop at 1 in 500 a kill, in Forest 1 where about half the enemies are Boomas, comes to about 1 in 1,000.

An item's **server-wide rate** is the average of its rate over the ten Section IDs, counting 0 where a Section ID never gets it. At about 500 kills an hour, 1 in 5,000 is one drop in ten hours of farming the best place.

| Band | Server-wide rate per kill | Bounty tier |
|---|---|---|
| Common rares | 1/5,000 or better | none: normal play is already the best source |
| Rare | 1/5,000 to 1/20,000 | Rare |
| Very rare | rarer than 1/20,000 | Ultra Rare |
| Never dropped | quest-reward items | Mythic Rare |
| Rarest in the game | rarer than 1/75,000 for every Section ID | Mythic Rare full runs only: Challenge Mode's and run contracts' |

An item that drops 1/5,000 or better for the hunter's **own** Section ID is left to normal play for them, whatever its band.

**The 10x rule.** Each item in a pool is at least 10 times as likely from one bounty of that tier as from one kill in the best place to farm it, for the hunter's own Section ID. The pool's chances are `p = 10 × rate + (1 − 10 × total rate) / items`, so every item keeps its 10x and the rest is shared evenly. If a pool's rates were too high to allow that, its commonest items would leave the pool; with the current data none do. `php tools/bounty_data/pool_report.php` checks every Section ID, class, level and area (about 230,000 item chances).

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

**Photon Drops and Photon Spheres** each roll on their own, so neither is a given. Drops can come with any tier; a Sphere only with Ultra Rare and Mythic Rare:

| Tier | Common | Uncommon | Rare | Ultra Rare | Mythic Rare |
|---|---|---|---|---|---|
| Chance of Photon Drops | 15% | 25% | 40% | 60% | 80% |
| How many (at level 200) | 1–2 | 1–5 | 3–12 | 10–40 | 20–60 |
| Chance of a Photon Sphere | none | none | none | 5% | 15% |

- The number of Drops falls to half at level 1, and Challenge bounties scale it with their stages' length (×0.5 to ×2). Drops come as one stack of at most 99.
- **Battle Mode and Challenge Mode always pay Photon Drops (`100%`)**, unaffected by character level: Battle Mode scales cleanly across tiers (`2–3` up to `18–22` PDs for 1–5 wins), while Challenge Mode scales monotonically with `stage_minutes × pace_multiplier` (`B = 1.0`, `A = 1.3`, `S = 1.65`).
- Full Challenge Mode runs (`Mythic Rare`) always guarantee **2 Photon Spheres** for Episode 1 (`Stages 1–9`, plus `79` PDs) and **1 Photon Sphere** for Episode 2 (`Stages 1–5`, plus `60` PDs); other bounties pay at most one Photon Sphere (worth 99 Photon Drops at Gallon's Shop).

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

**Exchange-only items.** A Mythic Rare bounty (not a Challenge full run) has a 3% chance (`mythic_exchange_chance`) of paying one of 83 exchange-only items instead of its usual item. Left out: the Item Ticket shop's and Dr. Osto's items (bounties pay their tokens), the tokens themselves, and combination results. `exchange_allow` can still add exchange items to the Mythic Rare pool outright.

**Never rewards.**

- Challenge Mode prizes: the S-rank weapons, Gods Shields Suzaku and Byakko, Yellow Ring, and Hunter, Ranger and Force Field.
- Combination results: Merges, combination Mags and weapons.
- Items that can't be obtained, and the Easter Egg (a seasonal drop).
- Seasonal quest tools and the Dirty Lifejacket.

Bounties started before this update keep their old reward and roll it with `roll_profiles`, under the same limits (2 attributes, 90, hit 50).

## Objectives

- **Dailies** use the existing objectives (bosses, speedruns, patrols, items, techniques and so on). Battle and Challenge objectives are kept for their own pools.
  - **The effort fits the tier** (`daily_objective_tier`, for free and dropped dailies). A Rare bounty is never an errand:

    | Tier | Objectives | Counts on |
    |---|---|---|
    | Common | Visit a floor, patrol it for 10 minutes, find an item, learn a technique, play for 1 hour | any difficulty |
    | Uncommon | Defeat a boss, clear a floor in a time, reach a level | one difficulty below the Rare one (none below Hard) |
    | Rare | Defeat a boss in a time, with a newer hunter, in a hardcore party or in a party of different classes | the difficulty weeklies use for the hunter's level: Hard from 20, Very Hard from 40 (Ultimate is for Mythic Rare weeklies) |

    An objective type the table doesn't list (one set back on in `excluded_goal_types`) is Common. The difficulty is saved in `missions.meta` (`min_difficulty`) when the bounty starts, the story is set on it, and the board adds it to the objective, for example "Defeat the Dragon (on Very Hard or higher)". Visits, patrols and floor times count only in a game of that difficulty or higher, and a boss kill only when its own game was.
  - A day or a week is no fair measure of some old objectives, so the rework never offers them (`excluded_goal_types`; set one to `false` to offer it again):
    - **Hold N Meseta.** A bank withdrawal meets it at once, and spending undoes it.
    - **Use N materials.** Most materials drop too rarely to find in a day (HP and Power Materials average about 1 in 1,300 and 1 in 860 a kill, Luck about 1 in 7,900). They are capped per character, and how to spend them is a build choice.
  - **Reach level N** (daily, up to 2 levels; weekly, up to 5) is offered only when the EXP still needed fits in a day's or a week's play, and asks for as many levels as fit. The EXP a hunter can earn is the hours of play (`level.hours`: 1 a day, 5 a week) × `level.kills_per_hour` (500) × the EXP an average enemy gives on the hardest difficulty the hunter can play × `level.exp_multiplier` (set it to newserv's `BBGlobalEXPMultiplier`). The EXP figures come from newserv's level table and battle params (`api/data/bounty_levels.json`; Ultimate: about 333 EXP an enemy). With the defaults, from the start of a level:
    - dailies ask for a level up to about level 115 (a day's play is about 167,000 EXP on Ultimate);
    - weeklies ask for one up to about level 169 (a week's play is about 833,000 EXP);
    - a hunter close to the next level can still get one.
  - **Patrol** ("survive and patrol the area for 10 minutes") counts the seconds actually spent on that floor. It used to count cron passes, which run about 10 seconds apart, so ten of them finished it in under two minutes. A gap between passes counts for at most a minute.
  - **Play time** is counted from when the bounty starts: `PLAY_HOURS` (`<seconds needed>:<play time at the start>`) asks for 1 hour of play (`play_hours`) instead of a total like "121 total hours". The board shows how much has been played.
- **Weeklies** use new, longer objectives:
  - `AREA_SWEEP` (`E@Difficulty`): visit every area of Episode E (Common to Rare).
  - `BOSS_HUNT` (`N@Difficulty`): defeat N bosses (3–5, ×1.2 Uncommon, ×1.5 Rare, ×2 Ultra Rare, ×2.5 Mythic Rare).
  - `ENEMY_HUNT` (`N@Difficulty`): defeat N enemies (150–300, ×1.5 Uncommon, ×2.5 Rare, ×4 Ultra Rare, ×6 Mythic Rare: at about 500 an hour, a Mythic Rare one is 2 to 3½ hours).
  - `RARE_HUNT` (`N@Difficulty`): defeat N rare enemies (Ultra Rare 1–2, Mythic Rare 2–3).
  - `LEVEL`: gain up to 5 levels (3–5 when they fit; see below; Common to Rare).

  Ultra Rare and Mythic Rare weeklies are hunts only (bosses, enemies, rare enemies): visiting every area, or a few levels that take minutes at a low level, is too light for them.

  Each counts only in Normal-mode or Solo games at that difficulty or higher. Weeklies never require Ultimate, except Mythic Rare ones. Progress is saved in `player_missions.progress` and shown on the board, for example "4 / 10 areas".
- **Battle Mode** bounties (`BATTLE_RULE_WINS`, target `quest:wins`, e.g. `503:2`) share 3 daily slots per account and ask for 1–5 wins (`Common` 1, `Uncommon` 2, `Rare` 3, `Ultra Rare` 4, `Mythic Rare` 5) under one of the eight rule sets, named on the board with its rules:

  > Win 2 Battle Mode matches of Battle 3: Warp Battle (Spaceship, everyone at Lv5 (+3 per death), start with nothing, no Mags, techniques up to Lv1, 10 lives, no Meseta, 10 of each slow/confuse/freeze trap, 10 min)

  The rules come from the battle quests' own scripts (`api/data/battle_rule_sets.json`). Blue Burst reports battle records when the player leaves the game, so a win is credited to the battle quest (501–508) the character was last seen in. Both Battle Mode and Challenge Mode bounties ignore character level, always reward Photon Drops (`100%`), and scale rewards monotonically with wins/duration/rank pace.
- **Challenge Mode** bounties (`CHALLENGE_RUN`, target `episode:first-last:limit_seconds:pace`) share 3 weekly slots per account and name stages and a pace:

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
- **Carries measure levels when each player joined the game.** "Carry a lower-level player (5+ levels lower)" and the Hardcore Carry (3 players 10+ levels lower) compare the levels the bounty holder and the other players had when they joined that game, not their levels when the boss falls. Carried players level up on the way to the boss, and used to drop out of range just before the kill. The cron keeps each player's level from the first pass it sees them in a game (`game_join_level` in its player state).
- **Reroll:** a bounty that needs a particular party (`MENTOR_BOSS`, `HARDCORE_MENTOR`, `DIVERSE_PARTY_BOSS`) can be rerolled once for free from the board (`api/reroll_bounty.php`). It keeps its tier, reward and slot. It gets a new objective and story the next time the character is online. The new objective is never another team objective, and it cannot be rerolled again.
- Gemini still writes every story through the lore-grounded prompt (`api/bounty_lore.php`). The prompt now also gives the tier and kind ("Rare (weekly)"), and for drops what the hunter defeated. Weekly hunts aren't tied to one area.
- A job that crosses a whole episode (a weekly visit to every area, a full-run contract) is told from the card of the episode's deepest area (`bounty_episode_setting()`: the Ruins, the Seabed, the Subterranean Desert), whose moment comes after every area the job crosses was found. The episode hub cards come earlier (Episode 4's is set before anyone knew of the Subterranean Desert, where a full run ends), so a story told from them could not name where the job goes. A run's guardians are named as people of that moment know them: Episode 1's as the Dragon beneath the Dome, the giant worm of the underground waterway, the Mines' main computer and whatever was let loose at the heart of the ruins, since its cards keep the names Vol Opt and Dark Falz hidden.

## Contracts

A quarter (`contracts.drop_share`) of dropped Ultra Rare and Mythic Rare bounties come as **contracts**: the biggest jobs the guild posts. They are tied to the episode they dropped in, and they never expire.

- A character holds up to 3 (`pools.contract.held_max`), in their own slots: a contract starts as soon as it drops. With 3 held, a contract roll stays a normal Ultra or Mythic weekly drop (and with 10 normal drops held, a contract can still drop). Abandoning one is free.
- Like other drops, Ultra Rare ones come in a party of 2 or more and Mythic Rare ones when playing alone. The objective is chosen when the contract starts, on the difficulty weeklies use for the hunter's level (Ultimate for Mythic Rare from level 80).

**Objectives** (`contracts.objectives`):

| Tier | Objective | Example |
|---|---|---|
| Ultra Rare | A full run with a mixed party: a Hunter, a Ranger and a Force in the game at every boss | Full Episode 2 run in one game on Very Hard or higher: every area and all 4 bosses, with a Hunter, a Ranger and a Force in the game at every boss |
| Ultra Rare | Hunt a rare creature | Defeat 3 Del Rappies in Episode 4 on Very Hard or higher |
| Mythic Rare | A hardcore carry: 3+ players 10+ levels below the holder (by the levels each joined the game at), all in the game at every boss | Full Episode 4 run in one game on Ultimate: every area and the boss, carrying 3 or more hunters 10+ levels below you |
| Mythic Rare | Against the clock: Episode 1 and 2 in 2 h 30 min, Episode 4 in 1 h 30 min (`run_minutes`), from entering the first area | Full Episode 1 run in one game on Ultimate: every area and all 4 bosses, within 2 h 30 min of entering the first area |
| Mythic Rare | Hunt a rarer creature | Defeat 3 Kondrieus in Episode 4 on Ultimate |

- **A full run** (`EPISODE_RUN`, target `EP:VARIANT:MINUTES@Difficulty`) is every field area of the episode and every one of its bosses, in one game: Episode 1's ten areas and four bosses, Episode 2's eleven and four, Episode 4's eight and its boss. Areas count when the holder is seen in them; bosses when the boss tracker records a kill in that game (it keeps the game's ID even if the holder left the arena first). Joining another game starts the run over.
  - The party requirement is checked at each boss kill, against everyone in the game at that moment. A boss that falls without it means that game can't count any more; the board says to start a new game.
  - A timed run that goes over its time can't count in that game either.
- **A hunt** (`RARE_TARGET`, target `TARGET:N:EP@Difficulty`) asks for a rare creature met in that episode. A kill by anyone in the holder's game counts, because only the player who lands the blow is credited with a rare kill. It needs newserv patch 0001; without it, contracts are runs only.
  - Ultra Rare targets: Love Rappy, Nar Lily, Pouilly Slime, Merissa AA, Del Rappy. Mythic Rare: Hildeblue, Al Rappy, Kondrieu, Pazuzu, Dorphon Eclair (`hunt_targets`). Only those met in the contract's episode are offered.
  - The count is set by effort: about 10 area runs' worth for Ultra Rare and 30 for Mythic Rare (`hunt_runs`), at least 1, at most 5. With newserv's default rare rates and the map counts, one area run meets on average:

    | Creature | Episode | One in about … runs | Count asked |
    |---|---|---|---|
    | Del Rappy | 4 | 4 (Crater) | 3 |
    | Nar Lily | 1 / 2 | 11 (Caves) / 8 (VR Temple) | 1 |
    | Pouilly Slime | 1 | 10 (Caves) | 1 |
    | Merissa AA | 4 | 8 (Desert) | 1 |
    | Love Rappy | 2 | 18 (VR Temple) | 1 |
    | Kondrieu | 4 | 10 (Desert, with its boss) | 3 |
    | Pazuzu | 4 | 25 (Crater) | 1 |
    | Al Rappy | 1 | 30 (Forest) | 1 |
    | Dorphon Eclair | 4 | 52 (Crater) | 1 |
    | Hildeblue | 1 / 2 | 104 (Forest) / 62 (VR Temple) | 1 |

**Rewards.** The holder gets the contract's own reward, chosen when it starts, with its tier's stat rolls, and Photon Drops and Meseta ×2 for a run, ×1.5 for a hunt (`reward_scale`):

- An Ultra Rare run pays from the episode's Ultra Rare items.
- A Mythic Rare run pays from the episode's items rarer than 1/75,000 a kill server-wide: 30 in Episode 1, 29 in Episode 2 and 20 in Episode 4. Challenge Mode's reserve is among them, so the reserve's Episode 4 items, which no Challenge run can pay (Solferino, for one), can now be won.
- A hunt pays from the creature's own rare drops (none of its tools or common gear, nothing normal play already finds at 1/5,000): for example Heaven Striker, Lame d'Argent or Daylight Scar from Kondrieu.
- None of them widen past the episode. A hunter for whom a contract's pool has nothing gets the tier's own pool in that episode.

**Shares.** Everyone else in the game when a contract is done gets a reward one tier lower (`share_tier`: Ultra Rare → Rare, Mythic Rare → Ultra Rare), built for their own level, class and Section ID from the episode's items. For a run, a player needs to have been in the game for at least half its bosses (Episode 1 and 2: 2 of 4; Episode 4: the boss). Shares wait in the Guild Claim Center (`missions.pool = 'share'`, `goal_type = 'CONTRACT_SHARE'`), and the player gets an in-game mail.

**Data.** `tools/bounty_data/build.py` writes the rare creatures to `api/data/bounty_areas.json` (`rare_enemies`): the names newserv reports them under, where they are met, how many an area run meets, and what they drop in each episode.

## On the site

- **Bounty board (`missions.php`).** Each card shows the bounty's tier, its kind, the time left before it expires (contracts: "Never expires") and, for weeklies and contracts, its progress, for example "This game: 7 / 10 areas, 2 / 4 bosses" or "2 / 3 Kondrieus". Team bounties get a reroll button; contracts get the LFG button.
  - A **Your Bounty Slots** panel shows, for each character, the active slots, how much of each cap has been earned, and the **Up next** list. Waiting bounties can be discarded from that list.
  - The public feed shows all of your own active bounties. Of other hunters' bounties it shows only those you can help with: team bounties (`MENTOR_BOSS`, `HARDCORE_MENTOR`, `DIVERSE_PARTY_BOSS`), Battle Mode, Challenge Mode and contracts, the 30 newest.
  - The slot panel has a Contracts cell (held of 3, never expire). The Claim Center marks a finished contract and a share.
- **JSON endpoints.** `api/my_bounties.php` and `api/my_bounties_all.php` return the new fields: `tier_label`, `kind`, `expires_in`, `progress_text`, `stats_hidden` and `can_reroll`. They also include waiting and rerolling bounties and a `slots` summary. `api/bot_api.php` adds `tier_label`, `kind` and `progress_text` to each mission.
- **Reward names.** `renderRewardString()` drops the `x1` that `item_map.json` puts on stackable items ("Telepipe x4", not "Telepipe X1 x4"). For the few codes whose `item_map.json` name is a bare number (030E15 showed as "3"), it uses newserv's `names-v4.json` instead.

## Kill and clear tracking: the newserv patches

Three small patches for fuzziqersoftware/newserv add fields to `/y/clients`:

1. `0001-Report-enemy-and-rare-enemy-kill-counts-in-y-clients.patch`: `EnemyKillCount`, `RareEnemyKillCount` and `LastRareEnemyKilled`. It counts the killing blow for each player, in `on_enemy_exp_request_bb`, using newserv's own rare-enemy test. `ENEMY_HUNT`, `RARE_HUNT` and rare-enemy drops need it.
2. `0002-Report-Challenge-Mode-stage-clears-in-y-clients.patch`: `ChallengeClearCount` and `LastChallengeClear` (`QuestNumber`, `StageNumber`, `Seconds`). It counts each stage clear when the stage's time is set (6x95), once per player. Challenge quests are numbered 881NN (Episode 1 stage NN) and 882NN (Episode 2).
3. `0003-Report-kill-counts-per-enemy-episode-difficulty-and-.patch`: `KillCounts`, each player's killing blows on this connection by episode, difficulty, game mode and enemy, as a list of `{Episode, Difficulty, Mode, Enemy, Name, Rare, Boss, Count}` (`Enemy` is newserv's type name, such as `BOOMA` or `DARK_FALZ_3`; `Name` is the in-game name, the Ultimate one on Ultimate). A boss counts once per fight, when its main form falls (Dragon, De Rol Le, Vol Opt's second form, Dark Falz's final form, Barba Ray, Gol Dragon, Gal Gryphon, Olga Flow's second form, Saint-Milion, Shambertin, Kondrieu). Its phases, joints, body parts, mines, monitors and spinners aren't counted. Saint-Milion, Shambertin and Kondrieu are nine body entities each and Barba Ray two, so newserv remembers which players' kills of each fight were counted. The three counters of patch 0001 follow the same rule, so one Kondrieu fight can't count as several.

### Kill counts and whole-game credit

- **Each character's own kills** (killing blows) are kept for good in `enemy_kill_counts`, one row per episode, difficulty, game mode and enemy (`api/enemy_kills.php`). The cron reads `KillCounts` each pass and records the difference from the last reading; a lower reading means the player reconnected, so the new reading is all new kills. Kills from before a character was first seen aren't counted.
- They show in the dashboard's character viewer, in a **Kill Counts** panel: per episode, one row per enemy with its kills on Normal, Hard, Very Hard and Ultimate (Normal and Solo games), Challenge Mode apart, and the total; bosses first. `api/kill_counts.php?character=NAME` returns the same.
- **Bounties give credit to the whole game.** A kill by anyone in a game counts for every player's active kill bounties there, and the board says so: weekly boss, enemy and rare-enemy hunts and contract hunts end with "(a kill by anyone in your game counts)".
  - `ENEMY_HUNT` and `RARE_HUNT` count every kill and rare kill in the player's game that pass, their own included (`KillCounts`, or the patch 0001 totals without it);
  - `BOSS_HUNT` counts a boss the tracker saw anyone in the player's game kill, even a player who stayed out of the arena; each fight counts once, however many players the tracker recorded;
  - contract hunts count anyone's kill in the game, and a run counts a boss anyone in its game killed.
- The daily boss objectives (`BOSS_ARENA`, timed bosses, carries, mixed parties) still need the player in the arena, and bounty drops still come from the player's own kills.

Without the patches the site still works:

- Weeklies don't offer the two kill objectives, contracts don't offer hunts, and only boss kills (from `cron_boss_tracker.php`) can drop bounties.
- Without patch 0003 there are no kill counts per enemy; enemy and rare hunts still credit the whole game from the patch 0001 totals.
- Challenge clears are read from changes to the stage record times (`ChallengeTimesEp1Online`, `ChallengeTimesEp2Online`). That misses replays slower than the record.

### Boss kills: arenas by episode

`cron_boss_tracker.php` counts a boss kill when a player gains EXP in a boss arena. It used to treat floors 9 and 11–15 as arenas in every room, but newserv's floor numbers repeat across episodes. So floor 9 (the Episode 4 arena) is also Ruins 2 in Episode 1 and the Seaside in Episode 2, and floor 11 (the Dragon) is also the lower Seabed in Episode 2. Killing ordinary enemies in those three areas recorded boss kills, and those fake kills rolled bounty drops and counted for `BOSS_HUNT`. In Episode 2, Olga Flow's arena is entered straight from the lower Seabed, which the tracker also took for an arena, so Olga Flow kills were usually missed.

The tracker now reads each room's episode from `/y/lobbies`. The arenas are 11–14 in Episode 1, 12–15 in Episode 2 and 9 in Episode 4 (`bounty_boss_arena_floors()` has the same list). A lobby has no arena, and a room the tracker hasn't seen falls back to the old list. Kill and floor events now carry `episode`. A kill on an "exit" (the player left the arena before the EXP showed) keeps the arena's own room. `BOSS_ARENA`, the carry objectives and `SPEEDRUN_BOSS` reject a kill from a room of another episode, and `ANY_DRAGON` takes floor 11 only from Episode 1 and floor 15 only from Episode 2.

newserv always lists all 14 online stages in those two lists (9 and 5). A cleared stage shows its record time in seconds; a stage never cleared shows 65535, which is how newserv decodes an empty record. `challenge_time_cleared()` treats 65535 (and 0) as not cleared. That matters for three things: which stages a Challenge bounty may ask for (up to one past the highest cleared), the clear tracking above, and the original "clear N Challenge stages" objective (`CHALLENGE_STAGES`, used when the rework is off). That objective now asks for one more than the stages cleared so far, says it is a total of the 14 stages, and isn't offered once all 14 are cleared. Before, it counted the 14 list entries rather than the cleared stages, so its target was always 15 and could never be met.

## Bounty states

`player_missions.status`:

| Status | Meaning |
|---|---|
| `queued` | Earned and waiting under Up next. Only its tier, kind and reward bases exist (`missions.goal_type = 'PENDING'`) |
| `in_progress` | Active |
| `rerolling` | Its reroll was requested. It keeps its slot and gets a new objective next pass |
| `ready_to_redeem` → `redeeming` → `completed` | As before. Stats roll at `redeeming` |
| `expired` | Not finished by its reset (contracts never expire) |
| `abandoned` | As before. Waiting bounties can be discarded too |

New columns, added by `api/db.php` on first load:

- `missions`: `tier`, `cadence`, `pool`, `source`, `reward_spec` (JSON: item bases and roll profiles, plus `rolled` after the claim), `meta`.
- `player_missions`: `period_key`, `expires_at`, `earned_at`, `activated_at`, `rerolled`, `progress`.

Bounties from before the rework have no cadence. They keep working as before: they never expire and don't use the new slots.

## Cron flow (`api/cron_missions.php`, each pass)

1. Expire anything past its reset.
2. Note who is in each game (with the levels they joined at), and what each game killed since the last pass (its players' new kills, and the boss kills the tracker recorded there), for whole-game credit and contracts.
3. For each online player:
   1. Work out kills, Challenge clears and Battle wins since the last pass.
   2. Evaluate active bounties. Weeklies go through `bounty_evaluate_weekly()` and contracts through `bounty_evaluate_contract()` (a finished contract pays the rest of the game their shares). A daily with a `min_difficulty` skips the pass when the game is easier, and sees only boss kills made in a game of that difficulty.
   3. Then `bounty_tick_for_client()`:
      - roll drops for new boss and rare-enemy kills;
      - earn bounties for completions, being logged in and Battle/Challenge play (each under its cap);
      - start at most one waiting or rerolling bounty: its objective, story (one Gemini call) and reward.
4. Kill counters are saved in the cron's player state file. Kills from before a character was first seen don't count.

## Data

`api/data/` is generated from newserv's Blue Burst tables and quests:

```
python3 tools/bounty_data/build.py --newserv /path/to/newserv
```

| File | What it holds |
|---|---|
| `bounty_items.json` | Every BB item: name, kind, stars, how it is obtained, rate per Section ID, best source, where it drops (lore setting and episode), level requirement per class, DEF/EVP ranges, exchanges, quest rewards, combinations |
| `bounty_areas.json` | Each lore setting, floor and Challenge stage: its drop setting and its enemies' class shares. The rare creatures for contract hunts (`rare_enemies`) |
| `bounty_levels.json` | The EXP needed for each level, and the EXP an average enemy gives on each difficulty (for level objectives) |
| `battle_rule_sets.json` | The eight Battle Mode rule sets, decoded from the quest scripts |
| `challenge_mode.json` | Challenge stages, rank limits and prizes, from the stage scripts |
| `quest_item_lists.json` | Items each BB quest can create |

Episode 2's rare Rag Rappy is the Love Rappy all year (newserv's `rare_type()`); the Saint, Egg and Hallo Rappies replace it only during their events. So Love Rappy drops count as normal drops, and only those three are seasonal. (Before, the Love Rappy was counted as seasonal too, which left out its drops: God/Luck and Angel/Luck are Love Rappy drops, and eight other items drop a little more often than the old data said.)

`tools/bounty_data/enemy_settings.json` maps enemies and boxes to lore settings, and `tools/bounty_data/area_classes.json` holds each area's enemy-class shares (from the BB spawn files; classes from community enemy databases, cross-checked against the Episode III card data).

## Testing

```
php api/test_bounty_system.php        # caps, queue, drops, tier rules, pools, objectives, expiry, reroll, rewards, contracts
php api/test_bounty_lore.php          # the lore-grounded prompt
php tools/bounty_data/pool_report.php # every reward pool: sizes and the 10x rule
```

`test_bounty_system.php` uses a throwaway database and answers Gemini prompts itself, so it needs no game server or API key. `GEMINI_API_BASE` in `.env` can point the Gemini call at a stub server when running the real cron against a test game server.

## Choices made where the post left room

- A cap counts bounties *earned* per reset. Bounties earned past the 3 + 3 slots wait under Up next instead of being lost.
- Drops have their own 5 slots and a 10-held limit, so they never block the free dailies and weeklies.
- Contracts (not in the post) have 3 slots of their own and no queue: a never-expiring job would hold a waiting slot for good. Only the holder gets the contract's promised reward; the rest of the game gets a share a tier lower, built for them.
- "Mythic only solo" covers both Solo mode and being alone in a Multi-mode room. The second gets the ×1.5 odds.
- The 10x rule compares one bounty of a tier with one kill in the best place to farm the item, for the hunter's own Section ID. Rares commoner than 1/5,000 a kill, server-wide or for the hunter's own Section ID, are left to normal play, where they already come often.
- Mythic Rare items don't drop, so they aren't tied to an area.
- Uncommon equipment still comes from the level-scaled reward tables (common items drop everywhere).
- A bounty's level, for strength and stat caps, is the hunter's level when it starts.
