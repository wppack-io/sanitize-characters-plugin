# WPPack Sanitize Characters

[![CI](https://img.shields.io/github/actions/workflow/status/wppack-io/sanitize-characters-plugin/ci.yml?branch=1.x)](https://github.com/wppack-io/sanitize-characters-plugin/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.7%2B-21759B.svg)](https://wordpress.org)

[日本語版 README](README.ja.md)

Sanitizes invisible and control characters out of content on its way into
WordPress and out of search terms on their way into a query. No settings
screen, no options in the database.

## The problem

Word processors and note-taking tools (Google Docs, Notion, …) inject
zero-width characters into text as soft line-break hints; PDFs and terminals
leak control characters and odd separators. Pasted into WordPress they
survive invisibly inside titles, body text and search boxes. Search then
fails on strings that look identical on screen: a title containing
`Broad​way` (with a hidden U+200B) never matches a typed `Broadway`, and a
pasted search term with a trailing zero-width space matches nothing — in the
admin search, in ACF relationship fields and in the front-end `?s=` search
alike. As a bonus, MySQL's utf8mb4 collations treat these characters as
ignorable, which makes the corruption invisible to SQL too.

## What gets sanitized

| Characters | Treatment |
|---|---|
| C0 controls except TAB/LF/CR, DEL, C1 controls (U+0000–U+001F, U+007F–U+009F) | removed |
| U+00AD SOFT HYPHEN | removed |
| Zero-width: U+200B, U+2060, U+FEFF (BOM) | removed |
| Bidirectional controls: U+061C, U+200E/U+200F, U+202A–U+202E, U+2066–U+2069 (trojan-source vectors) | removed |
| U+2028 LINE SEPARATOR, U+2029 PARAGRAPH SEPARATOR | rewritten to `\n` |

Deliberately kept: TAB/LF/CR, U+200C ZWNJ and U+200D ZWJ (they glue emoji
sequences — 👨‍👩‍👧 — and shape several scripts), U+00A0 NBSP, variation
selectors. Sites that rely on bidi control characters for mixed RTL text
should not use this plugin.

## What it does

**On save**

- `wp_insert_post_data` — sanitizes post title, content and excerpt on every
  save path (editor, REST, XML-RPC, WP-CLI).
- `acf/update_value` — sanitizes ACF field values (arrays recursively).
  Registered unconditionally; without ACF it simply never fires.

**On search**

- `pre_get_posts` — normalises the `s` query var of every `WP_Query`: admin
  searches, ACF relationship lookups and the front-end search.

**Existing data**

- `wp sanitize-characters` — scans `wp_posts` (title, content, excerpt;
  revisions excluded) and `wp_postmeta`, dry-run by default; pass `--apply`
  to persist. Posts are updated in place: `post_modified` stays untouched
  and no revision is created. Serialized meta is unserialized and rebuilt;
  object values are skipped. Revisions are excluded on purpose — restoring
  one goes through `wp_insert_post_data`, where the save filter sanitizes
  again.

## WP-CLI usage

```
# List affected rows without changing anything (dry-run is the default).
$ wp sanitize-characters
post 42 [post] post_title,post_content: Example post title
meta 137 (post 42, subtitle)
Success: Would clean 2 posts and 1 meta rows (dry-run; pass --apply to persist).

# Persist the changes.
$ wp sanitize-characters --apply
Success: Cleaned 2 posts and 1 meta rows.

# Verify: a second run finds nothing.
$ wp sanitize-characters
Success: Would clean 0 posts and 0 meta rows (dry-run; pass --apply to persist).
```

One line is printed per affected row — `post <ID> [<type>] <columns>: <title>`
for posts, `meta <meta_id> (post <ID>, <key>)` for post meta. Meta rows whose
value is a serialized object are reported and skipped. `wp help
sanitize-characters` shows the full synopsis. Running it periodically is
harmless: the save-time filters keep new content clean, so the command only
finds rows written while the plugin was inactive.

## Background and related work

- WordPress core removes invisible characters from **slugs only** when
  building permalinks ([Trac #47912](https://core.trac.wordpress.org/ticket/47912),
  [Trac #42951](https://core.trac.wordpress.org/ticket/42951)); titles, body
  text and search terms are untouched — the gap this plugin fills.
- [invisible-characters.com](https://invisible-characters.com/) — a
  reference list of the characters involved.
- [Insert Special Characters](https://wordpress.org/plugins/insert-special-characters/)
  serves the opposite purpose (inserting such characters deliberately).

## Requirements

PHP 8.2+, WordPress 6.7+.

## License

MIT
