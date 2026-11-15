# SiteShifter

**Shift WordPress sites safely.**

SiteShifter moves a WordPress site from any host (localhost, GoDaddy, Hostinger, cPanel/hPanel, VPS) to a new one, including domain changes, and checks everything that could make the move fail *before* it starts.

> Status: early development. Step 1 (core engine, pre-flight checks, CLI and standalone installer) is in progress.

## What it does

- **Pre-flight report** before anything is touched: PHP version and extensions, memory, timeouts, upload limits, disk space, database version and collation compatibility, permissions, cache/firewall files with hard-coded paths, DNS/SSL for the new domain, and a list of domain-bound licences (Elementor Pro, WP Rocket, ACF Pro, WPML, Gravity Forms, …). Every item is `block`, `warn` or `ok`.
- **Serialization-safe domain replacement** across *all* site tables: plain, `www`, protocol-relative, JSON-escaped (Elementor, block editor) and URL-encoded URLs, plus server paths. Serialized PHP data is rewritten by a parser (objects are never instantiated) and string lengths are recalculated.
- **Verification scan** after the move: every remaining occurrence of the old domain is reported by table and column, and row counts are compared between source and destination.
- **Standalone `installer.php`**: drop it in the new site's root with either a SiteShifter package or a hand-made SQL dump + zip (e.g. from cPanel), open it in a browser, enter the new database and URL, and it does the rest.

## Packages (planned)

| Package | Status |
|---|---|
| Core library (PHP ≥ 7.4, no runtime dependencies) | in progress |
| WP-CLI commands | in progress |
| `installer.php` | in progress |
| WordPress plugin (dashboard UI) | planned |
| Direct transfer (site-to-site, SFTP/SSH) | planned |

## Development

```bash
composer install
composer test
```

## License

GPL-2.0-or-later, the same as WordPress.
