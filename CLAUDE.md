# CLAUDE.md

This file provides guidance for Claude Code when working in this repository.

Project overview and installation live in [README.md](README.md). This file is
Claude-specific navigation + session hygiene, nothing duplicated from there.

## Non-negotiables (failing these loses trust; do not skip)

1. **Tests must pass before every commit.** `vendor/bin/phpunit` — the suite
   boots a real WordPress and needs the test database up
   (`docker compose up -d mysql-test`, MySQL on `127.0.0.1:3313`).
2. **PHPStan must be clean (level 8).** `vendor/bin/phpstan analyse` shows
   `[OK] No errors`. Don't lower the level.
3. **`1.x` is the only long-lived branch.** All work goes there; PRs target
   `1.x`.
4. **Never skip hooks or signing.** No `--no-verify`, no `--no-gpg-sign`.
5. **Destructive ops require explicit user confirmation.** `git push --force`,
   `git reset --hard`, `rm -rf` — call it out and wait.
6. **Don't auto-push after every commit.** Batch locally; push only on
   explicit instruction.
7. **The keep-list is sacred.** TAB/LF/CR, U+200C/U+200D (emoji ZWJ
   sequences, script shaping), U+00A0 NBSP and variation selectors must
   never be added to `SanitizeCharactersPlugin::PATTERN`. The remove-set is
   controls (C0 minus TAB/LF/CR, DEL, C1), SOFT HYPHEN, zero-width and bidi
   controls; U+2028/U+2029 are rewritten to `\n`, not removed. Keep
   PATTERN, `targetCharacters()` and both READMEs in sync.
8. **The cleaner never deletes and never touches timestamps.** Direct UPDATE
   on affected columns only: no `wp_update_post`, no revisions, no
   `post_modified` changes. Revisions are excluded (restore re-enters the
   save filter).

## Architecture

A single WordPress plugin (`wppack/sanitize-characters-plugin`, entry point
`wppack-sanitize-characters.php`) with three classes under
`WPPack\Plugin\SanitizeCharactersPlugin`:

- `src/SanitizeCharactersPlugin.php` — `boot()` registers the runtime
  filters: `wp_insert_post_data` (title/content/excerpt),
  `acf/update_value` (stripDeep — strings and arrays recursively; registered
  unconditionally, fires only when ACF saves), `pre_get_posts` (the `s`
  query var), plus the WP-CLI command when `WP_CLI` is defined. Also owns
  the public helpers `strip()` / `stripDeep()`, the `PATTERN` /
  `LINE_SEPARATOR_PATTERN` constants and `targetCharacters()` (the byte
  list DatabaseCleaner detects with).
- `src/DatabaseCleaner.php` — cleanup of already-stored rows in `wp_posts` /
  `wp_postmeta`. Detection uses `LIKE BINARY` + `UNHEX()` per target character: utf8mb4 collations treat
  zero-width characters as ignorable (weight 0), so a plain LIKE matches
  every row. Serialized meta is `maybe_unserialize`d, stripped, rebuilt;
  objects are skipped. Takes a `callable $log` so the CLI can stream lines;
  returns `{posts, metas, skipped}` counts.
- `src/CleanCommand.php` — thin WP-CLI wrapper (`wp sanitize-characters`,
  dry-run by default, `--apply` to persist). All logic lives in
  DatabaseCleaner so tests exercise it without WP-CLI.

Conventions: `declare(strict_types=1)`, PER-CS 2.0, final classes, hooks
registered from static methods, English comments explaining *why*.

## Testing

- The suite boots a real WordPress via wp-phpunit; the plugin is loaded at
  `muplugins_loaded` in `tests/bootstrap.php`, so the filters are exercised
  through real `wp_insert_post()` / `WP_Query` calls.
- `tests/TestCase.php` snapshots/restores `$wp_filter` and key globals, and
  wipes posts/postmeta between tests. wp-phpunit's `WP_UnitTestCase` is not
  used (incompatible with PHPUnit 11). `insertRawPost()` writes straight to
  the DB past the save filter — that is how "pre-existing dirty data" is
  simulated; `createPost()` goes through the filter on purpose.
- New stripping behavior gets both a positive test and a pass-through test
  (emoji ZWJ sequences, object meta, revisions) proving what must survive.

## Toolchain Quick Reference

| Task | Command |
|------|---------|
| Databases | `docker compose up -d` (dev on `127.0.0.1:3314` persistent, test on `127.0.0.1:3313` tmpfs) |
| Install deps | `composer install` |
| Run tests | `vendor/bin/phpunit` |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` |
| Code style check | `vendor/bin/php-cs-fixer fix --dry-run --diff` |
| Code style fix | `vendor/bin/php-cs-fixer fix` |
| Dev server (browser testing) | `bin/dev-server` → http://localhost:8082 (admin / password); override port with `SANITIZE_CHARACTERS_DEV_PORT` |
| Reset dev site to fresh state | `bin/dev-reset` (also bootstraps first-time setup; seeds a clean post plus a dirty post/meta injected past the filter for the CLI to find) |

Ports are offset from disable-comments' (3310/3311) so the plugins' databases
can run side by side (3312 is taken by another local project).

## Browser testing (dev server)

Modeled on tidy-admin/wppack: wp-cli as a dev dependency, `wp-cli.yml`
(`path: web/wp`, `server.docroot: web`), MySQL via `compose.yaml`.
`web/` is gitignored; `bin/dev-reset` is the canonical source of the dev-only
files it generates there (`web/wp-config.php`, `web/index.php`, and the
plugin dev stub — regenerated only when missing).

The plugin stub is a **real file**, never a symlink to the repo root:
`wp_register_plugin_realpath()` would map the plugin dir to the repo root —
an ancestor of `web/` — corrupting `plugin_basename()`/`plugins_url()` for
every plugin under `web/wp-content/plugins/`.

To verify in the browser: `bin/dev-reset`, search for `Dirty` in the admin
post list (the seeded dirty title matches only after cleaning), run
`vendor/bin/wp sanitize-characters --apply`, search again.

## Release procedure

- **Bump the `Version:` header in `wppack-sanitize-characters.php` to match
  the tag BEFORE tagging.** `wp plugin list` reads the header, not the
  composer version — v1.0.1 shipped with a `1.0.0` header because this was
  skipped; don't repeat it.
- Tags are `vX.Y.Z` (this repo's existing convention). Packagist picks new
  tags up automatically via the GitHub integration — no manual submission.

## Git commit discipline

- **One commit = one logical change.** Never sweep in unrelated changes;
  stage related files explicitly — no `git add -A`.
- **Conventional Commits**: `<type>(<scope>): <subject>` — feat / fix /
  refactor / style / docs / test / chore / revert; subject imperative,
  ≤ ~70 chars; multi-line bodies via HEREDOC.
- Commit at logical boundaries on your own judgment; never `git push`
  without an explicit instruction.

## Session Hygiene

- **Documentation sync check on every change**: `README.md` and
  `README.ja.md` must stay in sync — update both or neither.
- Edit → test → PHPStan → commit. Never claim done with red tests.
- Discovered a bug along the way? Note it in the commit message or a
  follow-up; don't expand scope silently.
