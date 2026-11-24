=== Local Media Browser ===
Contributors: ivanvsanz
Tags: media, local files, acf, file browser, uploads
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Browse and select files directly from local server folders inside the WordPress Media Modal and ACF without duplicating them into wp-content/uploads.

== Description ==

**Local Media Browser** adds a **Local Files / Archivos locales** tab to the native WordPress Media Modal and Advanced Custom Fields (ACF) selectors.

Key features:
* **No file duplication:** Files stay in their original folders on your server. WordPress only registers an attachment reference when you explicitly confirm your selection.
* **Safe deletion:** Deleting an attachment in WordPress will never delete the original file on disk.
* **Configurable base folder:** Define any absolute server path as your default base directory and list relative or absolute allowed folders.
* **Server agnostic:** Works on any web server (Nginx, Apache, LiteSpeed, etc.) without touching `.htaccess` or server configuration files.
* **Built-in ES/EN interface:** Includes an integrated language selector (Automatic, Español, English).
* **Security hardened:** Strict extension allowlist, path traversal and symlink escape protection, hidden file filtering, REST API schema validation, and ETag/Cache-Control headers for out-of-root delivery.

== Installation ==

1. Upload `local-media-browser.zip` via **Plugins > Add New > Upload Plugin** in WordPress.
2. Activate the plugin.
3. Go to **Media > Local Media** to configure your default base folder, allowed folders, and preferred language.

== Changelog ==
