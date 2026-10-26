# Table of Contents

A dependency-free WordPress plugin that builds a table of contents from a post's headings — good for long guides, SEO (jump links), and readers who skim.

## Features

- Auto-inserts a Contents box before the first heading, or place it manually with `[toc]`
- Unique anchor IDs for every heading — duplicate headings get `-2`, `-3` suffixes instead of broken duplicate IDs, and author-set IDs are respected
- Configurable heading levels (H2/H3/H4), box title, and minimum heading count (short posts get no box)
- Works on the rendered content only: nothing is written into the post itself, so deactivating leaves your content untouched
- Accessible `<nav>` markup with an `aria-label`, plus minimal styling that themes can override

## Settings

**Settings → Table of Contents**: heading levels, minimum headings, box title, auto-insert on/off.

## Install

Upload the plugin folder to `wp-content/plugins/` and activate it.

## Uninstall

Removes the single `wtoc_settings` option. Anchor IDs were only added at render time, so posts are unchanged.

## License

MIT
