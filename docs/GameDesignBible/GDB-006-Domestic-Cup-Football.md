# GOAL: Legacy
# Domestic Cup Football

Document ID: GDB-006
Title: Phase 2 Domestic Cup Football
Status: Active Phase 2 scope
Supersedes: the Phase 1 domestic-cup deferral for this feature only

## Player experience

Phase 2 adds one generic domestic knockout cup for each Big-5 nation already
present in the world: England, Spain, Germany, Italy, and France. The cups use
the existing first- and second-tier Clubs as their participant pool. They add
knockout context to the League career without adding European or international
football.

A Club's nearest scheduled fixture remains the calendar authority. League and
Cup Matches therefore coexist in the same Career Continue loop. The controlled
Player can appear in a Cup Match, be eliminated, advance through later rounds,
reach the final, or win the competition while the League Season continues.

## Format

Each cup is a deterministic single-elimination competition. Fields that are
not a power of two use a deterministic preliminary round and byes. The draw,
home side, and pairing order are derived from the world seed, competition,
Season, and round; database iteration order is never a draw input.

The V1 resolution rule is regulation football, deterministic extra time when
the regulation score is level, and a compact deterministic penalty shootout
when extra time is also level. Shootout goals are presentation facts only and
do not enter Player goals or Match statistics.

## Consequences and history

Cup Match facts use the canonical Match engine. Controlled-Player Matches keep
full evidence, ratings, form, development, and Matchday presentation. NPC-only
Cup Matches use World fidelity and retain only the compact facts already needed
by standings, discipline, development, News, and historical results.

Controlled Player statistics distinguish the all-competition Season line from
available Cup evidence. Meaningful first appearances, advancement, pressure,
and elimination situations may enter the existing bounded career-event and
News presentation layers. Cup Season state retains participants, rounds,
results, advancement, winner, and runner-up.

Cup membership is a competition relationship, not a second squad or career
engine. Promotion, relegation, salary, lifestyle, training, form, and
development continue to use their existing owners.

## Boundaries

This milestone does not add League cups, super cups, Europe, international
football, prize-money systems, Club finances, a trophy-room system, or new
Match actions. The competition model is deliberately small so a future
European knockout format can reuse the ownership boundary without being
claimed as implemented here.

---

END OF DOCUMENT
