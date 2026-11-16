# Bounty lore cards

These files ground the AI bounty generator (`api/cron_missions.php`) in Phantasy Star Online's own story, so each new bounty is an original job that still fits the world of Episodes I, II and IV.

The generator always picks the mechanical objective in PHP (which boss, which area, how much Meseta...). Each objective now also picks a **setting card**: the area the job happens in, at its moment in the story. A **quest giver** is then picked from that card's cast. Gemini receives the card, the giver, the shared writing rules and two or three real Guild jobs from the same place as style examples, and writes only the title and description.

| File | Contents |
|---|---|
| `world.json` | Canon summary (A.U.W. 3076–3086), the writing rules for every bounty, and the hub setting per episode |
| `ep1.json` | `ep1_pioneer2` (the city, 3084–85), `ep1_forest`, `ep1_caves`, `ep1_mines`, `ep1_ruins` |
| `ep2.json` | `ep2_lab` (3085), `ep2_vr_temple`, `ep2_vr_spaceship`, `ep2_gal_da_val`, `ep2_seabed` |
| `ep4.json` | `ep4_pioneer2` (3086), `ep4_crater`, `ep4_desert` |

## How objectives map to settings

| Objective | Setting |
|---|---|
| Boss bounties (`BOSS_ARENA`, `MENTOR_BOSS`, `HARDCORE_MENTOR`, `DIVERSE_PARTY_BOSS`, `SPEEDRUN_BOSS`) | The boss's area, from `bounty_boss_table()` in `api/bounty_lore.php` |
| `EXPLORATION`, `PATROL`, `SPEEDRUN_FLOOR` (Episode I floors) | The area containing the floor |
| Everything else (Meseta, levels, materials, techniques, items, playtime, battle, challenge) | A random episode hub: Pioneer 2 in 3084–85, the Lab in 3085, or Pioneer 2 in 3086 |

Boss bounties store their episode in `goal_target` (`13@2` is Olga Flow, `13@1` Vol Opt; speedruns add the time, `14@1_600`), because Episodes I and II reuse floor IDs 12–14. `parse_boss_target()` in `api/functions.php` reads it. Older bounties without `@` still fall back to keywords in their text.

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

## Editing

- Keep strings to one sentence. The whole card goes into every prompt for that setting.
- Facts must come from the games. The cards were written from *The Ragol Chronicles*, a timeline retelling of the game scripts. When adding a fact, keep the time discipline: if people learn it later, it belongs in `hidden_truths`.
- To see exactly what Gemini receives, run `php api/preview_bounty_prompt.php --goal=BOSS_ARENA --setting=ep2_seabed --level=60` (any goal type; `--floor`, `--giver`, `--lang=jp` and `--seed` also work) and paste the output into Gemini.
- After editing, run `php api/test_bounty_lore.php`. It checks that every file parses, every setting the generator can choose exists and has a cast, and every cast id resolves. `--prompt` prints a full sample prompt for each setting.
- If a file is missing or invalid, that episode's bounties fall back to the old generic prompt and the cron logs a `[LORE]` line, so a bad edit never stops bounty generation.
