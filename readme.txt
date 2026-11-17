=== Research GR Migrator ===
Contributors: researchgr
Tags: migration, backup, restore, wp-toolkit
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Restores a FastComet / cPanel WP Toolkit backup onto this WordPress site and prepares the research.gr cutover.

== Description ==

Research GR Migrator reads a WP Toolkit archive:

* `files/` — the WordPress tree, including `.htaccess.wpt-backup`
* `sqldump.sql` — the database
* `meta.json` — usually `{"dbPrefix":"wp_","login":null,"password":null}`

It copies themes, plugins and uploads, imports the database in small steps, and rewrites URLs without breaking serialized PHP data. It does not replace `wp-config.php`, so the Strato database credentials stay in place.

The screen is in English. Safe mode keeps wp-admin reachable if a restored plugin or theme is incompatible.

== Installation ==

1. Build `researchgr-migrator.zip` with the GitHub Action in `.github/workflows/build-zip.yml`, or zip the plugin folder yourself.
2. In WordPress, go to Plugins → Add New → Upload Plugin.
3. Activate Research GR Migrator.
4. Open RG Migrator in the admin menu.

== Frequently Asked Questions ==

= Does this work with All-in-One WP Migration .wpress files? =

No. It is built for the WP Toolkit `.tar.gz` layout used by FastComet.

= Will I be locked out? =

The plugin keeps the administrator who starts the restore, with the same password hash and session. Safe mode loads only this plugin until you exit it.

= Should the backup go into GitHub? =

No. The archive contains the live database. Keep it on the server inbox or on your computer.

== Changelog ==

= 1.0.0 =
* Initial release for the research.gr FastComet → Strato move.
