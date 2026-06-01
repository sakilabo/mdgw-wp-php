# MD Gateway for WordPress

English | [日本語](README.ja.md)

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4)
![License](https://img.shields.io/badge/license-UPL--1.0-blue)
[![Tests](https://github.com/sakilabo/mdgw-wp-php/actions/workflows/tests.yml/badge.svg)](https://github.com/sakilabo/mdgw-wp-php/actions/workflows/tests.yml)

A small PHP web application that shares an entire WordPress site with AI as Markdown — without installing a plugin. It serves a single index of every post so an AI can grasp the whole site at a glance, then read each post as Markdown.

`mdgw` = **m**ark**d**own **g**ate**w**ay.

It is not a plugin.

## Why it exists

Plenty of tools already turn WordPress content into Markdown for AI: appending `.md` to a post URL, content negotiation via `Accept: text/markdown`, generating an `llms.txt`, and so on. Almost all of them are **WordPress plugins that expose individual pages to AI**, and they share the same two limits:

- They require installing a plugin into WordPress.
- They make it hard to convey the *whole picture* of a site to an AI.

## What it does

MD Gateway lets you **show a site to AI without changing WordPress's settings or behavior**.

- **It's an external application.** It uses only the standard WP REST API, so it works with sites where you can't (or don't want to) install a plugin — including sites you don't own. It also helps with servers that block AI crawlers, or Japanese (IDN) domains that some AI fetchers fail to reach.
- **AI can discover pages.** The top page (`/`) is a complete index of every post, so the whole site is visible at a glance. Each linked post is lightweight Markdown that doesn't bloat the AI's context. The structure is easy for an AI to crawl and discover.
- **You control what AI sees.** Exclusion rules in `config.php` hide specific post types or posts. Your site's public scope (for humans and SEO) stays untouched; you adjust only what is handed to AI.

In short: rather than *teaching* or *feeding* content to an AI, MD Gateway *shares* a whole site and lets the AI explore it.

## Requirements

- PHP 8.2+
- PHP extensions: `curl`, `dom`, `intl`, `libxml`, `mbstring`
- [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) (via Composer)
- Apache (uses `.htaccess` / mod_rewrite)

## Setup

```sh
git clone https://github.com/sakilabo/mdgw-wp-php.git mdgate
cd mdgate
composer install
cp config.sample.php config.php   # edit config.php for your environment
```

Place it under a public directory (e.g. `https://example.com/mdgate/`).
`config.php` is not committed (it's in `.gitignore`).

## Configuration (config.php)

| Key | Description |
| --- | --- |
| `wp_site` | URL of the target WordPress site (e.g. `https://example.com`). IDN may be given in Unicode or punycode. |
| `wp_addr` | Address to connect to. When set, the host is pinned to this address instead of being resolved over DNS, and SSL verification is skipped (the address need not match the certificate). E.g. `127.0.0.1` for a WordPress instance on the same server. Leave empty for normal DNS resolution. |
| `posts_per_page` | Posts fetched per page, per post type (1-100). Defaults to 100. |
| `max_pages_per_type` | Max pages fetched per post type. Defaults to 2. |
| `timeout` | Connection timeout for the REST API, in seconds. Defaults to 15. |
| `concurrency` | Maximum number of REST API requests fetched in parallel. Defaults to 4 (clamped to 1–8). |
| `timezone` | Timezone for displaying dates. Accepts an IANA name, abbreviation, or offset (e.g. `Asia/Tokyo`, `JST`, `+0900`). Defaults to the server's timezone. |
| `font_family` | Font for the listing page (CSS `font-family`), as an array of font names. Defaults to `sans-serif`. |
| `exclude_type_slugs` | Post type slugs to omit from the listing. |
| `exclude_type_names` | Post type names to omit from the listing. |
| `exclude_titles` | Post titles to omit from the listing. |
| `exclude_ids` | Post IDs to omit from the listing. |
| `show_date` | Date shown after each title in the listing: `'full'` (date and time), `'date-only'`, or `'none'` (hidden, default). |
| `show_api_endpoint` | Whether to output the REST API endpoint URL in the Markdown front matter. `true` to include it, `false` to omit (default). |
| `form_handling` | How to handle `<form>` in post content. `'keep'` wraps the form in `<form>` / `</form>` tags (default); `'remove'` drops its contents and emits a self-closing `<form />` marker. |

Each `exclude_*` entry is either a delimited regular expression (e.g. `/^wp_/`) or an exact-match string (e.g. `attachment`). For a partial match, use an unanchored regex (e.g. `/news/`). `exclude_ids` is the exception: WordPress post IDs are always positive integers, so specify them as exact-match integers (e.g. `[12, 34]`). See [config.sample.php](config.sample.php) for details.

## Usage

- `/` — list of post types and posts
- `/<rest_base>/<id>` — show a post as Markdown (e.g. `/posts/123`)
- `/page?url=<post URL>` — resolve a post from its URL and show it as Markdown (same-site URLs only)
- Append `?raw` to output the original HTML without Markdown conversion

Each post is served as Markdown with `Content-Type: text/plain`.

## Limitations

- Works only with WordPress sites that expose the public WP REST API. Sites that disable it are not supported.
- One deployment targets one site (fixed in `config.php`). It is not a general-purpose proxy for arbitrary URLs.
- The Markdown output is read-only; MD Gateway cannot write to WordPress.

## License

[UPL-1.0](LICENSE)

## Author

[Sakilabo Corporation Ltd.](https://sakilabo.jp)
