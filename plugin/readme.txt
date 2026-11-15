=== SiteShifter ===
Contributors: serhandemirel
Tags: migration, backup, move site, change domain, search replace
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shift WordPress sites safely: pre-flight checks, resumable export and import, serialization-safe address changes. By Serhan Demirel.

== Description ==

SiteShifter moves a WordPress site to another host or address and checks everything that could make the move fail before it starts.

* Export a package (files and database) in small resumable steps with a live percentage. Choose what goes in: everything, structure only, settings only, content only, or a custom selection of content types.
* Size check before the export: it measures uploads, plugins, themes and the database, checks free disk space and suggests copying a very large uploads folder by hand.
* Packages screen: list, download and delete packages, with optional automatic clean-up.
* Import with a dry run first. Replace mode makes the site exactly the package using staging tables and a single quick switch, and keeps the old data so the import can be undone. Merge mode keeps what is on the site, adds what is new and lets you decide conflicts (themes, plugins, media, settings, users, posts); IDs are translated and links that cannot be translated are listed, never guessed.
* Change address: serialization-safe search and replace (also Elementor, ACF, WPML values), with a backup and undo.
* Clear error messages with a stable code and the fix (permissions, disk space, open_basedir, database rights, time and memory limits).
* Turkish and English interface; other languages can be added with a .po file.

Multisite is detected and the plugin stays off there in this version. Package encryption and direct site-to-site transfer are planned.

By [Serhan Demirel](https://serhandemirel.com).

== Installation ==

1. Download siteshifter.zip from the GitHub releases page.
2. In WordPress go to Plugins > Add New > Upload Plugin, choose the zip and activate it.
3. Open SiteShifter in the admin menu.

== Frequently Asked Questions ==

= Does an import delete my site? =

Replace mode keeps the previous database tables and files until you delete the backup, so you can undo it. Merge mode never deletes anything.

= Is my package safe to share? =

No. A package contains your database (including password hashes) and wp-config.php. Keep it private and delete it after use.

== Changelog ==

= 0.2.0 =
* Selective export, size check, packages screen, error codes with fixes.
* Chunked upload, dry run, Replace and Merge import with undo.
* Turkish translation.

= 0.1.0 =
* Plugin skeleton.
