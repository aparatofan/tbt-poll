# TBT Poll — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task, not from a full-repository scan.
- The current release is specified in `docs/SPEC-0.1.0.md`. Follow it in order. Phase 0 is read-only and ends with a report; do not edit anything before Mariusz answers it.
- `docs/prototypes/*.html` are the approved designs. Where the spec and a prototype disagree, the spec wins; where the spec is silent, the prototype decides.
- Sibling code is the reference for patterns: `aparatofan/tbt-results` (assets, tokens, fonts, REST boot, Hub item, deploy), `aparatofan/learning-history` `inc/notes-activity.php` (reporting to Notes from PHP) and `aparatofan/tbt_notes` (`tbt_notes_record_activity()`).
- Do not change unrelated code, formatting, wording, or styling.

## Project basics

- WordPress 6.x plugin; PHP 8.0+. Pure PHP + vanilla JavaScript. No jQuery, build system, CDN, Composer, or npm dependency.
- Bootstrap: `tbt-poll.php`. Server-side: `includes/`. Assets: `assets/`. Standalone tests: `tests/`.
- Prefixes: `TBTPL_` (classes, constants), `tbtpl_` (functions, options, filters, meta `_tbtpl_`), `tbtpl-` (CSS), REST namespace `tbt-poll/v1`. **Never `tbtp`**: TBT Points owns it. Never use unprefixed `.tbt-*` class names; TBT Hub claims them. The public API functions `tbt_poll_*` are the one deliberate exception.
- `assets/css/tbt-tokens.css` is a vendored copy of Hub's tokens. Never edit a value in it. No stylesheet here defines a colour of its own.

## Architecture to preserve

- **A vote is final.** One per user per poll, enforced by the unique key `(poll_id, user_id, question)` on `{prefix}tbtpl_votes`, never only in the UI.
- **The user is the session, never the request.**
- **Poll owns one table and one meta key.** `_tbtpl_questions` (JSON) is a fixed data contract: new keys additive only, never renamed.
- **Once a poll has votes its shape is locked**: wording may change, the number of questions and answers may not.
- **Reporting to Notes and asking Points fail open.** The vote stands and the results show whatever Notes or Points does, including when either plugin is missing.
- **One renderer:** the server embeds the poll's data, `poll.js` draws every state from it.
- Lesson world: white page, whitespace, no card. Tablet and desktop only (min 768 px). Light only.
- `TBTPL_Format` is pure PHP and unit-tested. Change the tests with it.
- The public PHP API (`tbt_poll_parse`, `tbt_poll_create`, `tbt_poll_shortcode`) is used by TBT Lesson Builder. Keep its signatures.

## Security rules

- Every REST route has a real `permission_callback`; nonce `wp_rest`; no-cache headers.
- Polls are managed by administrators only (`manage_options`).
- `$wpdb->prepare()` for every query with a value.
- Escape all output. Never expose credentials or real configuration values.

## Validation

```bash
for f in tbt-poll.php includes/*.php; do php -l "$f"; done
node --check assets/js/poll.js
php tests/test-format.php
```

State clearly what still needs a browser or live-site check.

## Git and deployment

- **Never merge a PR and never push to `main`.** A merge to `main` deploys to the live site; that is Mariusz's decision. Stop at an open PR and report.
- Use a focused feature branch. Keep commits task-focused.
- Every release bumps `TBTPL_VERSION` and the plugin header version; the version is the cache buster. Touch `TBTPL_DB_VERSION` only for a real schema change.
- **Never rename `tbt-poll.php` or the `/tbt-poll/` server folder.** The FTP deploy uploads but never deletes.
- Markdown files, `.github/`, `docs/` and `tests/` are excluded from the upload.

## Context discipline

- Prefer targeted search and narrow reads over broad exploration.
- Summarise completion as: what changed, what was checked, and what still needs live-site verification.
