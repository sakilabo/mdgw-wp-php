# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-05-31

First public release.

### Added

- Standalone PHP web application that shares an entire WordPress site with AI
  as Markdown, without installing a plugin.
- Fetches posts from the outside via the WordPress REST API.
- Single index of every post so an AI can grasp the whole site at a glance.
- Per-post Markdown output converted from the post HTML.
- Configuration via `config.php` (see `config.sample.php`).

[Unreleased]: https://github.com/sakilabo/mdgw-wp-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/sakilabo/mdgw-wp-php/releases/tag/v1.0.0
