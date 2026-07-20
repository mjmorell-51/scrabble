# Scrabble Games (scrabble.elenmorell.com)

A small collection of fun, Scrabble-related web games. No frontend framework, no build
step — plain HTML/CSS/JS backed by PHP API endpoints.

## Stack

- **Frontend**: static HTML + vanilla JS + one shared stylesheet (`assets/style.css`).
- **Backend**: PHP 8.3 (mod_php under Apache 2). No Node/npm on the remote host — don't
  introduce a JS build step or npm dependency.
- **Dictionary**: NWL 2023 word list, one lowercase word per line, ~196,601 words.

## Deploy

`git push` to `main`, then the remote pulls directly into the Apache **DocumentRoot**
(`/var/www/scrabble/public_html`, see `.claude/deploy.json`). There is no build/bundle
step — whatever is committed to the repo becomes web-servable as-is. The `/gpm` skill
does add/commit/push + remote `git pull` in one shot.

Because the whole repo lands in the webroot, anything that shouldn't be directly
downloadable needs to be blocked at the Apache layer, not just left out of links.

## Protecting the dictionary

The word list lives at `.data/nwl2023.txt` (dot-prefixed directory), not at the repo
root. The vhost already has:

```apache
<DirectoryMatch "/\."> Require all denied </DirectoryMatch>
```

which blocks HTTP access to any dot-prefixed path segment (this is also what protects
`.git`). That rule only applies to incoming HTTP requests, not to a PHP script reading
the file off local disk — so `api/*.php` scripts read
`dirname(__DIR__) . '/.data/nwl2023.txt'` directly and it works, while
`GET /.data/nwl2023.txt` returns 403. Verified live. If the dictionary or any future
private data file ever moves, it must stay under a dot-prefixed directory or gain
equivalent protection.

The word-definitions file `.data/definitions.tsv` lives under the same `.data/` dir and
gets the same treatment (read by `api/define.php` off disk, 403 over HTTP). It's not
secret — it's Wiktionary text (CC BY-SA), so serving it would be fine license-wise —
but keeping it out of direct HTTP download is consistent and avoids handing out the
whole 13MB scrape as one file.

## Word definitions

`api/define.php?word=X` returns `{word, definition}` (definition `null` if the word has
no entry). Definitions come from `.data/definitions.tsv`, a sorted `word<TAB>definition`
file (~189k of the 196,601 NWL words, ~96% coverage, 13MB) built from the English
Wiktionary via **Wiktextract** (kaikki.org's `kaikki.org-dictionary-English.jsonl`,
CC BY-SA — attribution shown in each page footer). The file is sorted identically to
`nwl2023.txt` (plain ASCII order over lowercase keys) and looked up by **byte-offset
binary search** (`lookupDefinition()`, the classic `look`(1) seek-and-align approach) so
no multi-MB load happens per request — ~0.05ms/lookup. Keys are pure `[a-z]`; definition
text is UTF-8 after the tab.

To regenerate (e.g. new NWL list, or to tune gloss selection): run
`.tools/build-definitions.py` (usage in its header) against a freshly downloaded
Wiktextract English JSONL. It keeps only NWL words, takes the LAST gloss in
Wiktextract's `senses[].glosses` hierarchy (the leaf is the real definition, not the
top-level category), deprioritizes cross-reference glosses ("Initialism of…",
"Alternative form of…", etc.) so a genuine definition leads, and writes a sorted TSV.
The script lives under `.tools/` (dot-dir, so it's not HTTP-served). Frontend rendering
is shared via `assets/define.js` (`Definitions.show(container, word)` — fetch + cache +
race-guard).

**Caveat**: PHP's built-in dev server (`php -S`) does *not* enforce this — a local
`/.data/...` request will return 200 there. That's expected; only the real Apache vhost
enforces the block, so any "is this file protected" check has to happen against
production, not the local dev server.

## Scoring rules used across features

Standard English Scrabble tile distribution (100 tiles) and point values — both are
duplicated as PHP consts in each API file that needs them (currently only
`api/bestword.php`; `api/lookup.php` doesn't need scoring). Blank tiles score 0 points
regardless of what letter they represent. A "bingo" (using all 7 rack tiles in one
word) adds +50 to the simple point sum. There's no board/multipliers yet — that only
matters once "Best Play" exists.

## File map

- `index.html` — home page, 4 feature cards (Look Up a Word, Quizzes, Best Word, Best
  Play), all active and linking out. The `.card.disabled`/`.badge.soon` "Coming soon"
  styling still exists in the CSS for future use, but no card currently uses it.
- `assets/style.css` — shared stylesheet. Scrabble-tile visual language (`.tile`/`.pts`)
  is reused everywhere: logo, home cards, rack tiles, result tiles. Keep new features
  visually consistent with this instead of inventing new components.
- `lookup/index.html` + `assets/lookup.js` + `api/lookup.php` — "Look Up a Word":
  validates a single word against the dictionary.
- `quiz/index.html` + `assets/quiz.js` + `api/quiz.php` — "Quizzes": pick a word length
  (m, 2–8) and a number of questions (n, 5–20) from two dropdowns, then take an
  n-question "Is this a word?" drill. `api/quiz.php?action=new&letters=m&count=n` returns
  `{questions:[{tiles,isWord}, ...]}` — a roughly even mix of real m-letter NWL words and
  plausible fakes (a real word with one letter mutated so it's no longer in the dict).
  Answers ride along and the client grades locally (self-quiz, no server session), one
  question at a time with per-question feedback, then a final score. The whole page is
  three `[hidden]`-toggled screens (setup / play / done) in one HTML file.
- `best-word/index.html` + `assets/bestword.js` + `api/bestword.php` — "Best Word":
  deals a 7-tile rack (random, or "guaranteed bingo" by picking a real dictionary word
  that fits the standard tile counts and shuffling its letters), lets the user type a
  guess and see its score/rank against every valid word the rack can form, or just ask
  for a hint (the single best word).
- `best-play/index.html` + `assets/bestplay.js` + `api/bestplay.php` — "Best Play":
  full 15×15 board with premium squares, seeded with 4 real words. `api/bestplay.php`
  actions: `new` (`mode=random|bingoable`, deals board+rack), `score` (POST, scores a
  submitted play + reports rank/best), `best` (POST, returns the single best play's exact
  tile placements). The move generator (`legalMoves`) is reused for scoring, seed-board
  generation, and bingoable-rack finding. `bestplay.php` can be `require`d with
  `BESTPLAY_LIB_ONLY` defined to load its functions without running the request dispatch
  (for CLI test/timing harnesses).

## Conventions / gotchas

- API endpoints are simple `?action=...` GET endpoints returning JSON, following the
  pattern in `api/bestword.php` (`respond()` helper, `parseRackParam()`, etc.). Keep new
  endpoints consistent with this rather than introducing routing/framework machinery.
- Rack tiles must stay in a fixed-column CSS grid (`grid-template-columns: repeat(7, ...)`
  with `aspect-ratio: 1/1` tiles), not a flex-wrap row — a fixed-width flex row of 7
  tiles overflows on narrow mobile portrait viewports and wraps ugly. This was an actual
  bug found on a real phone; don't regress it.
- Any new page should mobile-test in **portrait**, not just landscape/desktop —
  portrait is the narrowest realistic viewport and the one most likely to break first.
- Always test new PHP endpoints locally (`php -S 127.0.0.1:PORT`) before deploying, but
  remember the dev server can't confirm dot-path protection (see above) — do that check
  against the live site after deploy.
- `assets/style.css` is linked from every page with a `?v=N` cache-busting query string
  (no `Cache-Control`/`ETag` invalidation otherwise, so browsers can silently keep
  serving a stale copy after a deploy). Bump `N` in all HTML files (currently five)
  whenever `style.css` changes.
- The UA `[hidden]` rule is only `display:none` at UA-stylesheet strength, so any author
  rule that sets `display` on the same element (equal specificity, author wins) will keep
  a `hidden` element visible. Whenever a `.class` sets `display:` AND its element also
  gets toggled via the `hidden` attribute (e.g. `.bw-actions`, `.lookup-form`,
  `.quiz-setup`), add an explicit `.class[hidden] { display: none }`. This has bitten
  this project three times.

## Feature status

1. **Look Up a Word** — done.
2. **Quizzes** — done. Two-dropdown setup (word length + question count), then an
   n-question "Is this a word?" drill with per-question feedback and a final score.
3. **Best Word** — done (random/guaranteed-bingo modes, hint, guess-and-rank).
4. **Best Play** — done. Given a seeded 4-word board + a 7-tile rack, the user picks a
   start square + direction and plays a word (typed, or tile-by-tile then "Done"), then
   sees their score/rank vs. every legal play and can "Show Best". Two board modes:
   "Random Board" (leftover bag tiles) and "Bingoable Board" (rack guaranteed to have at
   least one 7-tile bingo somewhere on the board — found by testing 7-letter dictionary
   words against the board via `legalMoves`, otherwise still random-feeling). Move
   generation is anchor-square + binary-search prefix pruning over the sorted dictionary
   (no GADDAG); fast enough (well under 100ms typical, ~0.3s median for bingoable-board
   generation).
