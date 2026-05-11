# Memex

Turns WordPress into a note-taking app: bi-directional links, automatic backlinks, daily notes, tags, reminders, and one-click import from Obsidian, Notion, Evernote, and Roam Research.

Notes live as a custom post type, so you keep WordPress's editor (Gutenberg), revisions, search, media library, and permissions — but with a dedicated note-taking UI mounted at `/memex/`.

## Highlights

- **Wiki-style links.** Type `[[` in Gutenberg to open the link picker; it suggests existing notes and creates new ones inline. Links are stored as plain `<a href>` HTML — no custom shortcode at rest.
- **Automatic backlinks.** Every note has a backlinks panel showing what links to it. Forward links are tracked too.
- **Daily notes.** `/memex/daily` opens today's note (creates it on demand). Quick-capture appends a timestamped paragraph from anywhere in the app.
- **Tags.** A `memex_tag` taxonomy with a per-tag listing at `/memex/tag/{slug}`.
- **Reminders.** A Gutenberg block (`memex/reminder`) attached to a note. The plugin schedules a 5-minute cron and emails you when one is due. List view at `/memex/reminders`.
- **Graph, orphans, broken links.** Built-in views for navigating the link structure of your notes.
- **Importers.** Obsidian / generic Markdown, Notion (HTML/Markdown export), Evernote (`.enex`), and Roam Research (JSON). `[[Wiki-Links]]` from sources are converted to `<a href>` on import; missing targets become stub notes so links resolve.
- **Dark mode toggle** in the masterbar.
- **Login required.** The app and notes are private by default — `memex_note` is registered as `public => false`.

## Routes

| URL                                | What it does                                      |
| ---------------------------------- | ------------------------------------------------- |
| `/memex/`                          | All notes                                         |
| `/memex/note/{slug}`               | View a note                                       |
| `/memex/edit/{slug}`               | Open the note in the WP editor                    |
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
| `/memex/quick-capture`             | One-line append to today's daily note             |

## Requirements

- WordPress with a logged-in user
- PHP 7.4+
- [`akirk/wp-app`](https://github.com/akirk/wp-app) ^1.2 (installed via Composer)

## Install

```bash
cd wp-content/plugins/memex
composer install
```

Activate **Memex** in WordPress. The activator registers the CPT, schedules the reminder cron, and flushes rewrites.

## Importing

`/memex/import` accepts:

- **Markdown / Obsidian** — `.md`, `.markdown`, `.txt`, or a `.zip` of a vault
- **Notion** — the HTML or Markdown `.zip` export
- **Evernote** — `.enex`
- **Roam Research** — `.json`

Auto-detect sniffs file extension and content; you can also force a specific importer.

## Storage model

- Notes: `memex_note` CPT, hierarchical, with `title`, `editor`, `excerpt`, `revisions`, `author`, `page-attributes`, `custom-fields`.
- Tags: `memex_tag` taxonomy.
- Forward links: `_memex_links_to` post meta — one row per target post ID. Backlinks come from a single `meta_query` on this key.
- Daily-note marker: `_memex_daily` post meta (`YYYY-MM-DD`).
- Stub flag: `_memex_stub` (1 if the note was auto-created by an unresolved link).
- Reminders: `memex_reminder` CPT — `post_status` is `publish` while pending, `private` once done; due time in `_memex_due_at` (UTC).

The link layer is HTML-only at rest. Gutenberg's `[[` shortcut opens the (extended) link picker, the picker writes `<a href="/memex/note/{slug}">…</a>`, and on save the plugin extracts hrefs and rewrites the `_memex_links_to` rows. Display-time, internal anchors get a `.memex-link` class (and `.memex-link-stub` if the target is a stub) so they pick up app styling.

## Development notes

- Boots on `init:5` so CPTs and routes register before WP's `init:10` and the textdomain is available (WP 6.7+).
- Routes, masterbar menu, and access control are provided by `WpApp`. See its [README](https://github.com/akirk/wp-app/blob/main/README.md) for routing and template details.
- Templates live in `templates/`; assets (CSS/JS, including the editor extensions for the `[[` link picker and the reminder block) live in `assets/`.

## License

GPL-2.0-or-later
