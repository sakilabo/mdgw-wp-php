# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-06-01

### Added

- Each post now shows its parent in the index, making the page hierarchy visible.

## [1.2.1] - 2026-06-01

### Changed

- Index fetch errors now name the post type slug that failed.

## [1.2.0] - 2026-06-01

### Added

- Taxonomy terms in the index: each post in the listing now shows its category and custom-taxonomy terms as `[term]` labels, so an AI can grasp the site's structure from the index alone.

## [1.1.0] - 2026-06-01

### Added

- Lean index variant for known AI user agents: the listing detects a handful of known AI user agents and serves them a stripped-down page (no CSS/JS) with each post's Markdown URL and permalink as plain text, so fetchers that read URLs from body text but cannot follow links can reach them.
- `noindex, nofollow` on the index page to keep the gateway out of search indexes.
- Provisional HTML output mode: append `?html` to serve a slimmed-down HTML version of a post instead of Markdown, for AI clients (such as Gemini) that handle HTML better than Markdown.

## [1.0.0] - 2026-05-31

First public release.

### Added

- Standalone PHP web application that shares an entire WordPress site with AI as Markdown, without installing a plugin.
- Fetches posts from the outside via the WordPress REST API.
- Single index of every post so an AI can grasp the whole site at a glance.
- Per-post Markdown output converted from the post HTML.
- Configuration via `config.php` (see `config.sample.php`).

[1.3.0]: https://github.com/sakilabo/mdgw-wp-php/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/sakilabo/mdgw-wp-php/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/sakilabo/mdgw-wp-php/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/sakilabo/mdgw-wp-php/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/sakilabo/mdgw-wp-php/releases/tag/v1.0.0
