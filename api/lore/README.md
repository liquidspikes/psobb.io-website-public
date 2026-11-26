# Bounty lore cards

These files ground the AI bounty generator (`api/cron_missions.php`), admin mission generator (`bounty_build_admin_mission_prompt()`), and community event / milestone broadcasts (`api/cron_community.php`) in Phantasy Star Online's own story across Episodes I, II and IV.

The generator always picks the mechanical objective in PHP (which boss, which area, how much Meseta...). Each objective picks a **setting card**: the area the job happens in, at its moment in the story. A **quest giver** is then picked from that card's cast (`bounty_pick_giver($setting_id, $avoidNames)` avoids repeating active givers). Gemini receives the card, the giver, the shared writing rules (banning generic training/bureaucracy clichés such as `Testing ...`, `Combat Readiness ...`, `... Assessment`, `... Evaluation`), recent non-fallback titles (`bounty_clean_recent_titles()`), and two or three real Guild jobs from the same place as style examples, writing only the title and description.

| File | Contents |
|---|---|
| `world.json` | Canon summary (A.U.W. 3076–3086), shared writing rules, and the hub setting per episode |
| `ep1.json` | `ep1_pioneer2` (the city, 3084–85), `ep1_forest`, `ep1_caves`, `ep1_mines`, `ep1_ruins` |
| `ep2.json` | `ep2_lab` (3085), `ep2_vr_temple`, `ep2_vr_spaceship`, `ep2_gal_da_val`, `ep2_seabed` |
| `ep4.json` | `ep4_pioneer2` (3086), `ep4_crater`, `ep4_desert` |

## How objectives map to settings

| Objective | Setting |
|---|---|
| Boss bounties (`BOSS_ARENA`, `MENTOR_BOSS`, `HARDCORE_MENTOR`, `DIVERSE_PARTY_BOSS`, `SPEEDRUN_BOSS`) | The boss's area across all 9 bosses in Ep 1, 2, and 4, from `bounty_boss_table()` in `api/bounty_lore.php` |
| `EXPLORATION`, `PATROL`, `SPEEDRUN_FLOOR` (Episodes I, II, and IV floors) | The area containing `(episode, floor)` via `bounty_setting_for_floor($episode, $floor)` (`parse_floor_target()` / `make_floor_target()`) |
| `ENEMY_KILL` (`RARE_RAPPIES` / `ANY_RARE_ENEMY` seasonal/community events) | `ep1_forest` with a cross-episode `location_override` spanning Forest, Lab VR Temple, Crater, and Subterranean Desert |
| Multi-boss community operations (`EP1_BOSS_RUSH`, `EP2_BOSS_RUSH`, `DIGITAL_BLASPHEMY`, `DRACONIC_DOMINION`, `CATACLYSMIC_CORE`, `ALL_BOSSES`, `ANY_DRAGON`) | Matching episode deepest/hub card with a fleet-wide `location_override` |
| Episode-spanning weeklies & contracts (`AREA_SWEEP`, `EPISODE_RUN`, `RARE_TARGET`) | Deepest episode setting via `bounty_episode_setting($episode)` (`ep1_ruins`, `ep2_seabed`, `ep4_desert`) or drop area |
| Everything else (Meseta, levels, materials, techniques, items, playtime, battle, challenge) | Balanced episode hub via `bounty_pick_hub_setting($seed, $activeSettings)` (prefers the least-used hub across active cards: `ep1_pioneer2`, `ep2_lab`, or `ep4_pioneer2`) |

Boss and floor bounties store their episode in `goal_target` (`13@2` is Olga Flow, `13@1` Vol Opt, `9@4` Saint-Milion; speedruns add seconds like `14@1_600` or `5@2_300`), because Episodes I, II, and IV reuse floor IDs. `parse_boss_target()` and `parse_floor_target()` in `api/functions.php` decode them; older targets without `@` fall back to keywords in their text (`bounty_lore_setting_for_objective()` also checks episode keywords when backfilling).

## Card format

Each episode file has `settings`, `cast`, `never_givers` and `examples`. A setting card:

- `name`, `floors`, `boss` (`floor`, `name`, `ultimate_name`), `era`: where and when.
- `summary`: two to four sentences on what is going on there at that moment.
- `known_facts`: what people aboard Pioneer 2 know **at that moment**. Characters may use these.
- `open_mysteries`: rumours and questions a job may hint at but never answer.
- `hidden_truths`: later revelations. Gemini is told never to state or hint at them, only never to contradict them. This is what keeps a Forest job in 3084 from mentioning Dark Falz.
- `creatures`, `landmarks`, `factions`: the only creatures, places and groups a job may use.
- `job_hooks`: kinds of small job that fit, modelled on the real Guild and government jobs there.
- `cast`: ids of quest givers who could plausibly post a job there then (ids from the same file's `cast`).
- `avoid`: specific mistakes to steer clear of.

A cast entry has `name`, `role`, `voice`, `available` (when and where, including deaths and departures), `ties` and `never`. Cast ids are per episode, because the same person can stand in a different place in a different year.

## Editing, testing & fallbacks

- Keep strings to one sentence. The whole card goes into every prompt for that setting.
- Facts must come from the games. When adding a fact, keep the timeline discipline: if people learn it later, it belongs in `hidden_truths`.
- Preview prompts: `php api/preview_bounty_prompt.php --goal=BOSS_ARENA --setting=ep2_seabed --level=60` (`--floor`, `--giver`, `--lang=jp`, and `--seed` also work).
- Validate cards: `php api/test_bounty_lore.php` checks JSON validity, setting/cast completeness, target parsing, and prompt sizing (`--prompt` prints samples).
- Historical missions missing lore metadata can be updated offline with `php api/backfill_bounty_lore.php`.
- **Fallback auto-upgrade:** If a lore file is invalid (`[LORE]` log) or all Gemini/Gemma models hit rate-limit cooldowns, `api/bounty_generator.php` emits a deterministic `"Guild Posting: ..."` fallback story (`meta.lore_fallback = 1`). Once a model recovers from cooldown, `cron_missions.php` (`bounty_upgrade_fallback_stories()`) automatically replaces that placeholder with a full AI lore story.
