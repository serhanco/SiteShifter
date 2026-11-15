# SiteShifter

**Shift WordPress sites safely.** — by [Serhan Demirel](https://serhandemirel.com)

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

## Usage

### Move a site with a package

```bash
# on the old server (or locally)
bin/siteshifter export --path=/var/www/old-site --out=site.zip

# on the new server: upload site.zip and dist/installer.php into the new folder,
# open https://new-domain.com/installer.php and follow the three steps.
# or from the command line:
bin/siteshifter install --dir=/var/www/new-site --zip=site.zip --url=https://new-domain.com \
    --db-name=new_db --db-user=new_user --db-pass=secret
```

### Move a site from a hand-made export

Export the database from cPanel / phpMyAdmin (`.sql`) and zip `public_html` or just `wp-content`. Upload both with `installer.php` into the new folder and open the installer. If the zip has no WordPress core files, they are downloaded from wordpress.org (or pass `--core-zip`).

### Only replace the address

```bash
bin/siteshifter replace --path=/var/www/site --from=http://old.test --to=https://new.com
```

With WP-CLI: `wp --require=/path/to/siteshifter/wp-cli.php siteshifter export|replace|preflight`.

### https, proxies and www

If the new address is `https://`, every old `http://` / `https://` / `www` / protocol-relative / JSON-escaped variant of the old address becomes that https address, also when the domain stays the same. For sites behind Cloudflare or any other proxy or CDN, a small block is added to `wp-config.php` so WordPress trusts the proxy's scheme header (`X-Forwarded-Proto`, `CF-Visitor`, ...). Switch it off with `--no-proxy-https` (CLI) or the Advanced checkbox (installer). The pre-flight report warns about https→http, www / non-www differences, a missing DNS record or certificate for the other spelling, and Cloudflare SSL mode (use Full or Full (strict)).

### What happens on the new server

`backup` (existing tables renamed, wp-config.php saved) → `extract` → `core` → `config` → `import` → `replace` → `finalize` (caches with old URLs cleared, server-specific drop-ins disabled, `.user.ini`/`.htaccess` paths fixed) → `verify` (leftover scan, row counts, licence to-do list). Every step is resumable, so short PHP time limits are not a problem. If a step fails, the previous tables and wp-config.php can be restored.

## Development

```bash
composer install
composer test                       # unit + integration (needs MySQL/MariaDB: SS_DB_HOST, SS_DB_USER, SS_DB_PASS)
php bin/build-installer.php         # writes dist/installer.php
WP_CORE=/path/to/wordpress tests/e2e/run.sh   # real WordPress, both modes
```

## License

GPL-2.0-or-later, the same as WordPress.

SiteShifter is made by [Serhan Demirel](https://serhandemirel.com).
