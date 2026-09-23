# CB-014 — Football Social Narrative

P2-010 content is selective football context, not a social feed. Existing
Career Event content remains the primary event catalogue; the new bounded
definitions cover breakout attention, public scrutiny, supporter response,
manager trust/repair, mentorship follow-through, position competition,
international recognition, Club-favorite recognition, and rivalry context.

Headlines use football language: breakout performance, questions after a poor
run, supporter backing, a new Club chapter, or a meaningful rivalry. Choices
are team-focused, confident, reserved, ambitious, or protective of teammates.
They do not model morality, free text, romance, followers, or journalist NPCs.

Landmark stories are Newsworthy and may enter Career History. Routine NPC
results remain compact world football. Cup, European, and international facts
feed the same public layer; no competition-specific social engine exists.
National teams remain generic and unlicensed. International context is
sporting only and does not implement national politics or a national social
graph.

## P2-015 PULSE writing boundary

PULSE is public reaction, not a second report or event catalogue. ECHO routes
canonical facts into a small, deterministic actor voice: aggregate supporters,
Club, football media, competition, national team, valid teammate, valid rival,
or the controlled Player. A post must contain evidence for its kind: a goal
reaction needs a canonical goal, an award reaction needs an earned award, and a
transfer reaction needs a completed transfer or recorded request. Quiet,
unused-substitute, and not-selected states do not receive invented Match
drama.

News states what happened; Career History remembers the durable landmark; Pulse
shows bounded public response. Curated Player responses are optional and always
include silence. Templates use isolated deterministic selection, never free
text, LLM calls, network calls, or random filler. Injury and return language
uses only recorded availability facts and never speculates about diagnosis or
recovery timetable.

## P3-001 Pulse voice and repetition policy

Pulse reactions may use bounded fictional voice archetypes: supportive fan,
reactionary fan, rival fan, tactical fan, casual fan, meme account, old-school
fan, optimistic fan, pessimistic fan, and neutral viewer. These are presentation
styles, not persistent NPC profiles. Plain football language must remain part
of the mix alongside restrained internet language, occasional slang, varied
capitalization, punctuation, sentence length, and emoji use.

Reaction families describe semantic shapes such as a result, callback, tactical
detail, reluctant praise, or measured fact. A bounded recent Pulse window
avoids exact text and immediate family, opening, distinctive slang, and emoji
reuse. Selection is deterministic from the existing source/event identity and
does not consume gameplay RNG. Canonical result, performance, discipline,
availability, transfer, achievement, and Career context controls tone; Pulse
does not invent scores, statistics, history, reasons, or supporter identity.

Persisted Pulse prose is immutable on reload and legacy saves are not migrated
or rewritten. Echo still routes importance and event kind, while Pulse alone
selects presentation wording within the existing controlled-player feed.

## P3-002 conversational writing policy

Pulse may place a short reply or quote reaction beside an existing post. The
reply must respond to its actual parent through agreement, disagreement,
reluctant credit, defence, football banter, or a local callback. Replies are
usually shorter and more conversational than roots, but retain their selected
voice archetype. Rival or Club perspective is used only when the actor context
already supplies it. A quote reaction references the parent structurally and
adds a take rather than copying the parent text.

Threads are intentionally small and may be absent. Maximum depth is two;
cross-event social memory, invented callbacks, persistent fan identities, and
social-network mechanics remain out of scope. Match result, discipline,
transfer, achievement, and other canonical context still controls the tone.

## P3-003 global football writing

New Pulse posts may use a bounded recurring fictional identity. Personal voice
and football culture are separate: the identity keeps its name, handle, voice,
culture, and optional Club allegiance across events. English remains primary;
culture is expressed through football vocabulary, supporter framing, sentence
rhythm, durable references, and occasional short native expressions. No fake
accents, stereotype writing, or automatic translation is permitted.

Club/competition country, Player nationality, international context, rivalry,
transfer, and loan context may weight the bounded identity pool. A
`GLOBAL_FOOTBALL` fallback is used when no supported profile is available.
Home-country pride is reserved for meaningful evidence-backed events, not
routine appearances. The pool is not a social graph and does not model
followers or popularity.

An identity may recall only its own recent persisted Pulse posts. Memory is
bounded, deterministic, and evidence-backed; it never reconstructs discarded
Career history or invents an earlier opinion. Existing P3-001/P3-002 posts are
not rewritten. Recent identity, culture, local-expression, and cultural-reference
reuse is suppressed where suitable alternatives exist; Pulse rendering never
repairs authors or generates memory.

## P3-004 situational football culture

Culture profiles are selected by their actual identity context. Italian
references remain Italian, Spanish/Latin references remain within their
supported profiles, Brazilian references remain Brazilian, and unsupported
countries use readable global football English rather than borrowing a nearby
country's vocabulary. Native expressions are optional; most cultural writing
may contain none and should still differ through football terms, supporter
framing, humour, and what the identity notices.

Situational families may use only canonical flags already supplied by Echo,
Match story, or movement context, including rivalry, Player of the Match,
result-in-defeat, and cross-border transfer. These families participate in the
existing anti-repeat selection. Memory callbacks use the classified meaning of
an identity's actual prior post (support, criticism, doubt, defence, or rival
banter); they do not quote, truncate, or expose stored prose. This refinement
adds no social memory scan, persistence system, or runtime text generation.
