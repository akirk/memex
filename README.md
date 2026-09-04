# Memex

- Contributors: akirk
- Tags: notes, note-taking, backlinks, wiki, markdown
- Requires at least: 6.0
- Requires PHP: 7.4
- Tested up to: 7.1
- Stable tag: 0.1.0
- License: GPL-2.0-or-later
- License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WordPress into a note-taking app: wiki-style links between notes, automatic backlinks, daily notes, tags, reminders and Markdown import.

## Description

Memex turns WordPress into a note-taking app. Notes live as a custom post type, so you keep WordPress revisions, search, the media library and permissions — but authoring happens in a dedicated note-taking UI mounted at `/memex/`, not in wp-admin.

Notes are private by default: the note post type is registered as non-public and every screen of the app requires a logged-in user with the right capability.

[Try Memex in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/memex/main/blueprint.json) · [Try it with demo data](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/memex/main/demo.json)

[Try it in OpenStation](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/memex/main/blueprint-openstation.json) — the same app opened in desktop mode with the [OpenStation](https://github.com/WordPress/openstation) plugin.

### Highlights

- **Wiki-style links.** Type `[[` in the Memex editor to link notes. Matching note titles are suggested inline, and missing targets become stub notes.
- **Automatic backlinks.** Every note has a backlinks panel showing what links to it. Forward links are tracked too.
- **Daily notes.** `/memex/daily` opens today's note (creates it on demand). Quick-capture appends a timestamped paragraph from anywhere in the app.
- **Tags.** A note tag taxonomy with a per-tag listing at `/memex/tag/{slug}`.
- **Reminders.** Create reminders at `/memex/reminders`; the plugin schedules a 5-minute cron and emails you when one is due.
- **Graph, orphans, broken links.** Built-in views for navigating the link structure of your notes.
- **Importers.** Bring notes in from generic Markdown vaults, Notion (HTML/Markdown export), Evernote (`.enex`), Roam Research (JSON) and Thinkery (XML/JSON). `[[Wiki-Links]]` from those sources stay editable; missing targets become stub notes so links resolve.
- **Export.** Download all notes as a ZIP of Markdown files with YAML frontmatter — it opens as a Markdown vault and re-imports into Memex. Single notes download as `.md` from their page.
- **Login required.** The app and the notes it stores are private by default.

### Routes

| URL                                | What it does                                      |
| ---------------------------------- | ------------------------------------------------- |
| `/memex/`                          | All notes                                         |
| `/memex/note/{slug}`               | View a note                                       |
| `/memex/edit/{slug}`               | Edit the note in Memex                            |
| `/memex/new`                       | Create a note                                     |
| `/memex/daily` · `/memex/daily/{date}` | Daily note for today (or a given date)        |
| `/memex/search`                    | Full-text search                                  |
| `/memex/graph`                     | Link graph                                        |
| `/memex/backlinks/{slug}`          | What links to this note                           |
| `/memex/tag/{slug}`                | Notes tagged with `{slug}`                        |
| `/memex/orphans`                   | Notes with no inbound or outbound links           |
| `/memex/broken`                    | Stub notes referenced by links but never written  |
| `/memex/reminders`                 | Pending and past reminders                        |
| `/memex/import`                    | Upload an export from another tool                |
| `/memex/export`                    | Download all notes as a Markdown ZIP              |
| `/memex/quick-capture`             | One-line append to today's daily note             |

### Storage model

- Notes: a hierarchical `memex_note` custom post type with `title`, `editor`, `excerpt`, `revisions`, `author`, `page-attributes` and `custom-fields` support.
- Tags: a `memex_tag` taxonomy.
- Forward links: `_memex_links_to` post meta — one row per target post ID. Backlinks come from a single `meta_query` on this key.
- Daily-note marker: `_memex_daily` post meta (`YYYY-MM-DD`).
- Stub flag: `_memex_stub` (1 if the note was auto-created by an unresolved link).
- Reminders: a `memex_reminder` post type — `post_status` is `publish` while pending, `private` once done; the due time lives in `_memex_due_at` (UTC).

The link layer recognizes `[[Note Title]]`, `[[Note Title|display text]]` and internal HTML anchors. On save, the plugin extracts those targets and rewrites the `_memex_links_to` rows. At display time, wiki links and internal anchors get a `.memex-link` class (and `.memex-link-stub` if the target is a stub) so they pick up app styling.

No custom database tables are created. Removing the plugin leaves a plain WordPress behind.

### Development notes

- Memex boots on `init:5` so post types and routes register before WordPress's `init:10` and the text domain is available (WP 6.7+).
- Routes, the masterbar menu and access control are provided by [`akirk/wp-app`](https://github.com/akirk/wp-app). See its [README](https://github.com/akirk/wp-app/blob/main/README.md) for routing and template details.
- Templates live in `templates/`; assets (CSS/JS, including the in-app `[[` autocomplete) live in `assets/`.

Development happens [on GitHub](https://github.com/akirk/memex). Pull requests welcome.

## Installation

1. Upload the `memex` directory to `/wp-content/plugins/`, or install the plugin through the WordPress plugin installer.
2. Activate **Memex** through the 'Plugins' menu in WordPress. Activation registers the note post type, schedules the reminder cron and flushes the rewrite rules.
3. Open `/memex/` while logged in.

When working from a Git checkout instead of a release ZIP, install the Composer dependencies first:

```bash
cd wp-content/plugins/memex
composer install
```

## Frequently Asked Questions

### Does this create custom database tables?

No. Notes are a custom post type, tags are a taxonomy, and links are stored in post meta. Everything is standard WordPress.

### Are my notes public?

No. The note post type is registered as non-public and every route of the app requires a logged-in user who may edit posts, so notes are not part of the site's front end, feeds or search results.

### Which formats can I import?

`/memex/import` accepts:

- **Markdown** — `.md`, `.markdown`, `.txt`, or a `.zip` of a folder of Markdown files
- **Notion** — the HTML or Markdown `.zip` export
- **Evernote** — `.enex`
- **Roam Research** — `.json`
- **Thinkery** — the `.xml` or `.json` export

Auto-detect sniffs the file extension and content; you can also force a specific importer.

### What happens with a large export?

Large files are imported in chunks: the upload creates a job, and the page then calls the server repeatedly, each call doing about five seconds of work and reporting progress. Closing the tab or hitting a PHP timeout leaves the job resumable — reopen `/memex/import` to resume or discard it. Abandoned jobs are swept daily.

### How do I get my notes back out?

`/memex/export` downloads a ZIP with one `.md` file per note (stubs are skipped). Each file starts with YAML frontmatter — `title`, `created`, `updated`, plus `tags`, `aliases`, `daily` and `status` when set — followed by the note body in the same Markdown the in-app editor uses, so links appear as `[[Note Title]]`. Child notes are placed in a folder named after their parent. The archive can be imported back through the Markdown importer.

### What happens to a link whose note does not exist yet?

It creates a stub note, so the link resolves and the missing note shows up under `/memex/broken` until you write it.

## Screenshots

1. A note in the Memex app, with the note tree, the note body and its backlinks.

## Changelog

### 0.1.0

- First release: notes as a custom post type with wiki-style `[[links]]`, automatic backlinks, daily notes, quick capture, tags, reminders, search, a link graph, orphan and broken-link views, chunked importers for Markdown, Notion, Evernote, Roam Research and Thinkery exports, and Markdown ZIP export.
