# TBT Poll 0.1.0 — A lean poll for lessons

Every Man and Society and Personal Development lesson ends its video section with a poll: three questions, four answers each. Today that is YOP Poll, which carries far more than TBT needs and knows nothing about TBT students. TBT Poll replaces it for new lessons: a student answers all three questions, presses **VOTE**, and sees how everyone voted. The vote is recorded in the Notes activity log, so it shows in **Results** and earns **Points**.

The design is `docs/prototypes/poll.html` (approved 15 Nov 2026). Where this spec and the prototype disagree, the spec wins; where the spec is silent, the prototype decides. The prototype's category switch, “Vote again (demo)” link and dark-mode colours are prototype-only: the live site is light only.

TBT Lesson Builder (`aparatofan/tbt-lesson-builder`, next project) will create polls through the PHP API in Task 3. Build that API exactly as specified even though nothing calls it yet.

## Decisions (locked)

| Topic | Decision |
|---|---|
| Who votes | Logged-in users only. Visitors who aren’t logged in see the results and a **Log in to vote** button. |
| How many votes | One per user per poll, **final**. No changing, no second ballot. |
| Ballot | All questions answered, one **VOTE** button. The button stays disabled until every question has an answer. |
| After voting | Results: per answer a bar and a percentage; the total number of voters; the student’s own answer marked **✓ Your vote**. Percentages of each question add up to exactly 100 (largest-remainder rounding). |
| Points line | Right after voting only: “Thanks for voting. You earned N points.” when Points reports N > 0 for this vote, otherwise “Thanks for voting.” Never shown on a later visit. |
| Coming back | A user who already voted gets the results straight away. |
| Editing | Admins may fix typos in the question and answer text at any time. Once a poll has votes, the number of questions and the number of answers per question are locked: a save that changes either is refused with a notice. |
| Shape | 1–5 questions, 2–6 answers each (letters A–F). Lessons use 3 × 4. |
| Colour | The question number badge takes the lesson’s domain colour from the shortcode’s `domain` attribute: `gi` → `--tbt-gi`, `pd` → `--tbt-pd`, `le` → `--tbt-le`; missing or unknown → `--tbt-blue`. Bars, focus and the selected answer are TBT Blue (Style Book: category colours identify, blue interacts). |
| Who manages polls | Administrators (`manage_options`) only, in wp-admin under the TBT Hub menu. |

## Source of the activity row

TBT Poll writes nothing to Notes’ table directly. After a vote is stored it calls **`tbt_notes_record_activity()`** (Notes 1.22.0+), exactly as Learning History does in `inc/notes-activity.php`:

```php
tbt_notes_record_activity( array(
    'user_id'      => $user_id,              // the session user, never the request
    'tool'         => 'poll',
    'object_ref'   => (string) $poll_id,
    'object_title' => html_entity_decode( get_the_title( $poll_id ), ENT_QUOTES, 'UTF-8' ),
    'post_id'      => $lesson_post_id,       // see Task 5; 0 when unknown
) );
```

- No `score`: Results shows “✓ Done”.
- Fails open: if the function is missing, returns a `WP_Error`, or throws, the vote still stands and the student still sees the results. Log anything other than `tbt_notes_no_class` with `error_log()`, as Learning History does.
- The call returns the activity row ID (int). Pass it to Points (Task 5) to read what the vote earned.

## Scope

| 0.1.0 | Later |
|---|---|
| New plugin: bootstrap, deploy workflow, `CLAUDE.md` (already in the repo), Hub item | Moving old YOP polls across |
| Poll post type and admin screen | Results breakdown per poll in wp-admin |
| Shortcode `[tbt_poll id="" domain=""]` with three states (vote, results, logged out) | Phone layout (not wanted: tablet and desktop only) |
| Vote route, votes table | |
| PHP API for Lesson Builder | |
| Results label via `tbtr_tool_labels` | |
| Points line, reading Points 0.1.3’s helper when present | |

Points 0.1.3 (separate spec, `aparatofan/tbt-points/docs/SPEC-0.1.3.md`) adds the **Poll** rule and the helper this plugin reads. Poll must work with or without it.

## Files

```
tbt-poll.php
includes/class-tbtpl-db.php          votes table: install, upgrade, reads, writes
includes/class-tbtpl-format.php      pure helpers (parse, validate, percentages) — unit-tested
includes/class-tbtpl-polls.php       post type, meta, PHP API
includes/class-tbtpl-admin.php       meta box, list columns, save rules
includes/class-tbtpl-rest.php        vote route
includes/class-tbtpl-shortcode.php   [tbt_poll] and its assets
assets/css/tbt-tokens.css            vendored copy of Hub's tokens (copy TBT Results', byte-identical)
assets/css/fonts.css, assets/fonts/  copy TBT Results' approach and files
assets/css/poll.css
assets/js/poll.js
tests/test-format.php
docs/prototypes/poll.html            the approved prototype (Mariusz adds it)
.github/workflows/deploy.yml         copy TBT Results'; secrets FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_SERVER_DIR (Mariusz has added them)
```

Prefixes: classes and constants `TBTPL_`, functions, options and meta `tbtpl_` / `_tbtpl_`, CSS classes `tbtpl-`, REST namespace `tbt-poll/v1`, text domain `tbt-poll`. **Not** `tbtp`: TBT Points owns it. Never use unprefixed `.tbt-*` class names (Hub claims them). The public PHP API functions in Task 3 are the one exception: they are named `tbt_poll_*` so the builder reads naturally.

---

## Phase 0 — read-only inventory (stop and report)

The repo has no plugin code yet. Read the sibling code instead.

1. **Notes.** In `aparatofan/tbt_notes` (main): confirm `tbt_notes_record_activity()` exists, its argument keys, that it returns the inserted row ID or a `WP_Error`, that `tbt_notes_no_class` is the code for a teacher or admin in no class, and that `tbt_notes_activity_recorded` fires synchronously inside the call.
2. **Learning History** (`aparatofan/learning-history`, `inc/notes-activity.php`): the reporting pattern to copy. Report anything that does not transfer.
3. **TBT Results as the pattern** (`aparatofan/tbt-results`): bootstrap and `plugins_loaded` wiring, how it registers and enqueues tokens and fonts, how a page boots its JS (localized data, `wp_rest` nonce, REST base), the Hub item (`tbt_hub_items`), the deploy workflow, and the `tbtr_tool_labels` filter. Poll copies these; report anything that does not transfer.
4. **Points** (`aparatofan/tbt-points`): confirm `TBTP_VERSION`, and whether `tbtp_points_for_activity()` exists yet (it arrives in 0.1.3). Poll must check with `function_exists()`.
5. **Hub menu.** Confirm `TBT_HUB_SLUG` and how another plugin puts a post type under the Hub menu (`show_in_menu`). If none does, report how TBT Comprehension adds its submenu and propose the closest equivalent.
6. **Domain tokens.** Confirm `--tbt-gi`, `--tbt-pd`, `--tbt-le` and `--tbt-blue` exist in the vendored tokens file.

**Hard stop** if anything here contradicts the spec. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — Bootstrap (`tbt-poll.php`)

Plugin header `Version: 0.1.0`; `TBTPL_VERSION` `0.1.0`; `TBTPL_DB_VERSION` `1`; `TBTPL_DIR`, `TBTPL_URL`. Activation installs the table (Task 2). `plugins_loaded` runs `TBTPL_DB::maybe_upgrade()` and wires the classes, as Results does.

- Hub item through `tbt_hub_items`: title **TBT Poll**, description “Polls for lessons, with live results”, capability `manage_options`.
- `tbtr_tool_labels` filter adds `'poll' => 'Poll'`, so Results names the tool even before it learns it.
- No fatal when Hub, Notes, Results or Points is missing.

## Task 2 — Votes table (`class-tbtpl-db.php`)

```sql
CREATE TABLE {prefix}tbtpl_votes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  poll_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  question tinyint(3) unsigned NOT NULL,
  answer tinyint(3) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY poll_user_question (poll_id,user_id,question),
  KEY poll_question_answer (poll_id,question,answer)
)
```

- One row per answered question. `question` and `answer` are 0-based indexes. `created_at` is UTC.
- The unique key is what makes a vote final: a second ballot fails on insert.
- Reads: `counts( $poll_id )` → `[question][answer] => n`; `voters( $poll_id )` → `COUNT(DISTINCT user_id)`; `ballot( $poll_id, $user_id )` → the user’s answers as `[question => answer]`, or `null` when they have not voted; `has_votes( $poll_id )`.
- Write: `insert_ballot( $poll_id, $user_id, array $answers )` inserts all rows in one transaction (`START TRANSACTION` / `COMMIT` / `ROLLBACK`); on a duplicate-key error it rolls back and returns a `WP_Error` with code `tbtpl_already_voted`.
- `delete_for_poll( $poll_id )`, called from `before_delete_post` when a poll is **permanently** deleted. Trashing keeps the votes.
- `$wpdb->prepare()` for every query with a value.

## Task 3 — Polls and the PHP API (`class-tbtpl-polls.php`, `class-tbtpl-format.php`)

**Post type** `tbt_poll`: not public, no front-end URL (`public` false, `publicly_queryable` false, `show_ui` true, `show_in_rest` false), supports `title` only, capability type mapped so only `manage_options` users can create, edit or delete. Title = the lesson title; the builder sets it.

**Meta** `_tbtpl_questions`: a JSON string, `[{"q":"…","a":["…","…","…","…"]}, …]`. This key is a fixed data contract (new keys additive only, never renamed).

**Pure helpers in `TBTPL_Format`** (no WordPress calls, unit-tested):

- `parse( string $text ): array` — the paste format Mariusz already uses in Word and that the builder will send:

  ```
  After watching this video, which insight about stress do you find most surprising?
  A. That believing stress is harmful can be more dangerous than the stress itself.
  B. …
  C. …
  D. …
  Which of Kelly McGonigal’s ideas do you think would help you the most?
  A. …
  ```

  A line that starts with a letter A–F followed by `.` or `)` is an answer to the question above it; any other non-empty line starts a new question. Surrounding `**` on a line is removed (Word bold). Blank lines are ignored. Returns the `[{q, a[]}]` structure.
- `validate( array $questions ): true|array` — returns `true` or a list of plain-English problems: “Add at least one question.”, “A poll can have at most 5 questions.”, “Question 2 needs at least 2 answers.”, “Question 2 can have at most 6 answers.”, “An answer comes before the first question.”, “Question 3 has no text.”
- `same_shape( array $a, array $b ): bool` — same number of questions and the same number of answers in each.
- `to_text( array $questions ): string` — the reverse of `parse()`, for the admin textarea.
- `percentages( array $counts ): int[]` — largest-remainder rounding so the result sums to 100; all zeros when there are no votes.

**Public PHP API** (in `tbt-poll.php`, wrapping the class; this is what Lesson Builder will call):

```php
tbt_poll_parse( string $text ): array|WP_Error          // parse + validate; WP_Error code tbtpl_invalid, message = first problem
tbt_poll_create( string $title, array $questions ): int|WP_Error   // published tbt_poll post, returns its ID
tbt_poll_shortcode( int $poll_id, string $domain = '' ): string     // '[tbt_poll id="123" domain="gi"]'
```

`tbt_poll_create()` validates again, sanitises every string with `sanitize_text_field()`, and checks `current_user_can( 'manage_options' )`.

## Task 4 — Admin screen (`class-tbtpl-admin.php`)

Under the TBT Hub menu as **Polls** (or as Phase 0 item 5 decides).

- **List columns:** Title, Questions (e.g. “3 × 4”), Votes (number of voters), Shortcode (`[tbt_poll id="123"]`, selectable text), Date.
- **Edit screen:** title plus one meta box **Questions** with a textarea in the paste format (`to_text()` of the saved meta), a one-line format reminder, and, when the poll has votes, the line “This poll has N votes. You can correct the wording, but not add, remove or move questions or answers.”
- **Saving:** nonce and `manage_options` check; `parse()` + `validate()`. On problems: keep the old meta and show the first problem as an error notice. When the poll has votes and `same_shape()` is false: keep the old meta and show “This poll already has votes, so its questions and answers can’t be added, removed or moved. Only the wording can change.” Otherwise save.
- Admin styling: plain wp-admin. No custom design work.

## Task 5 — Vote route (`class-tbtpl-rest.php`)

`POST /wp-json/tbt-poll/v1/polls/(?P<id>\d+)/vote`

- `permission_callback`: logged in (cookie auth, `wp_rest` nonce, as every TBT route).
- Body: `{ "answers": [1, 0, 3], "post_id": 4567 }`.
- Checks, in order: the poll exists, is a `tbt_poll` and is published (404 otherwise); `answers` is an array with exactly one integer per question, each in range (400 `tbtpl_bad_ballot`); the user has not voted (409 `tbtpl_already_voted`, with the current results in the body so the page can show them).
- `post_id`: informational only, for the activity row. Keep it only if it is a published post; otherwise 0.
- Store the ballot (Task 2), then report to Notes (see “Source of the activity row”). The user is always `get_current_user_id()`.
- Points: if `function_exists( 'tbtp_points_for_activity' )` and Notes returned an ID, `$points = (int) tbtp_points_for_activity( $activity_id )`; otherwise `null`. Wrapped in `try/catch`; any failure means `null`.
- Response 200, the same shape the shortcode embeds (Task 6), plus `points` (int or null).
- No-cache headers on the response.

## Task 6 — Shortcode and front end (`class-tbtpl-shortcode.php`, `poll.js`, `poll.css`)

`[tbt_poll id="123" domain="gi"]`

- Unknown or unpublished poll: for admins a one-line notice “Poll 123 not found.”; for everyone else nothing.
- The server works out the state and embeds the data as JSON in a `data-` attribute on the root element (escaped with `esc_attr( wp_json_encode() )`); `poll.js` renders every state from that data, so there is one renderer:

  ```json
  {
    "id": 123, "post_id": 4567, "state": "vote" | "results" | "guest",
    "questions": [{"q": "…", "a": ["…","…","…","…"]}],
    "counts": [[9,5,6,1],[6,8,3,4],[5,7,6,3]],
    "percent": [[43,24,28,5], …],
    "voters": 21,
    "mine": [1,1,2] | null,
    "login_url": "…",
    "rest": "…/wp-json/tbt-poll/v1/", "nonce": "…"
  }
  ```

  `counts`, `percent` and `voters` are sent in every state; `percent` comes from `TBTPL_Format::percentages()`. `mine` only for a user who has voted.
- Assets are enqueued only on a page that renders the shortcode, versioned with `TBTPL_VERSION`.

**States** (match the prototype):

1. **vote** — each question is a `fieldset` with a `legend` (domain-coloured rounded-square number + question in Roboto Slab), answers as radio cards (letter circle + text in Roboto Slab). Under the questions: **VOTE** (uppercase, Roboto 700, tracking 0.04em, 12px radius, TBT Blue, the only uppercase button in the view) and a muted hint: “Answer all three questions to vote” / “Answer one more question to vote” / “Answer 2 more questions to vote” (use the real question count). On success the page switches to results without a reload; a 409 shows the returned results; any other error shows “Your vote wasn’t saved. Please try again.” under the button and keeps the selection.
2. **results** — per answer: letter, text, percentage, and a 12px bar (pale blue track and fill; the user’s own answer solid TBT Blue with the **✓ Your vote** pill). Bars grow from 0 on render (skip the animation under `prefers-reduced-motion`). Below: “1 student has voted” / “N students have voted”; with no voters: “No votes yet”. Right after voting only, the green line from the decisions table.
3. **guest** — the results view without “Your vote”, and a **Log in to vote** button (sentence case, secondary style) linking to `login_url`, which is `wp_login_url()` back to the current page.

- `aria-live="polite"` on the root so the switch to results is announced.
- Lesson world: white page, whitespace for structure, no card around the poll. Question gap 48px, answer gap 10px, as the prototype.
- Tablet and desktop only (min 768px); nothing to design for phones.
- CSS uses only Hub tokens; no colour of its own.

## Task 7 — Tests (`tests/test-format.php`)

Pure PHP, no WordPress:

- `parse()`: the Stress lesson text from the prototype gives 3 × 4; `**bold**` lines; `A)` as well as `A.`; lowercase `a.`; blank lines; Windows line endings; answer before any question.
- `validate()`: every message listed in Task 3.
- `same_shape()`: equal; one fewer answer; one more question; reworded only (true).
- `to_text( parse( $text ) )` round-trips.
- `percentages()`: `[9,5,6,1]` sums to 100; `[1,1,1]` sums to 100; all zero; one answer with every vote.

## Validation

```bash
for f in tbt-poll.php includes/*.php; do php -l "$f"; done
node --check assets/js/poll.js
php tests/test-format.php
```

State what still needs a live check.

---

## Acceptance checklist (live site, after merge and deploy)

Mariusz creates a test poll in wp-admin → Polls by pasting the Stress lesson’s three questions, and puts `[tbt_poll id="…" domain="gi"]` on a draft lesson.

- [ ] Polls list shows the poll as “3 × 4”, 0 votes, with its shortcode.
- [ ] Pasting a question with three answers, or an answer before any question, is refused with the right notice and the old text stays.
- [ ] As a student: VOTE is disabled until all three questions are answered; the hint counts down.
- [ ] VOTE switches to results without a reload: the student’s answers are solid blue with ✓ Your vote, each question’s percentages add up to 100, and “N students have voted” is right.
- [ ] Reloading the page shows the results straight away, without the thanks line.
- [ ] A second vote is impossible (the form never appears again; a replayed request gets 409).
- [ ] The vote appears in **Results** as tool **Poll**, “✓ Done”, with the poll’s title.
- [ ] With Points 0.1.3 live and the Poll rule set to 3: the thanks line says “You earned 3 points.” and My points shows the row. Without Points 0.1.3: “Thanks for voting.” and nothing breaks.
- [ ] Logged out: results and **Log in to vote**; logging in returns to the lesson and shows the form.
- [ ] `domain="pd"` makes the number badges green; no `domain` makes them blue.
- [ ] With votes: correcting a typo saves; removing an answer is refused with the “already has votes” notice.
- [ ] Deleting the poll permanently removes its votes; trashing it keeps them.
- [ ] Hard refresh shows `?ver=0.1.0` on the poll assets.
