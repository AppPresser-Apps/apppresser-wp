=== AppPresser WP ===
Contributors: apppresser, modemlooper
Tags: accessibility, cookies, gdpr, maintenance, social share, popups, comments, markdown
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A compilation of essential tools for running a WordPress site — accessibility, cookie consent, maintenance mode, social sharing, pop-ups, and more.

== Description ==

AppPresser WP bundles six powerful modules into one plugin, each accessible from the Settings menu. Toggle features on and off without touching code.

### Modules

* **Accessibility** — A floating accessibility button that lets visitors adjust font size, contrast, cursor size, line height, and more. Includes a Gutenberg block for custom placement.
* **Cookies** — GDPR-friendly cookie consent banner with customizable message, position, duration, and button labels. Built-in cookie scanner detects cookies from HTTP headers and scripts. Manage and display cookies with the `[cookie_policy]` shortcode.
* **Maintenance** — Put your site into maintenance mode with a custom title and message. Sends proper 503 headers so search engines know it's temporary.
* **Social Share** — Add social sharing buttons via the `[social_share]` shortcode. Supports Print, Email, Facebook, X (Twitter), LinkedIn, Bluesky, and Pinterest. Customize background and text colors per button, and drag-and-drop to reorder.
* **Pop Ups** — Build pop-ups with a TinyMCE WYSIWYG editor and media library. Trigger on page load or specific pages, with configurable dismissal timing.
* **Options** — Disable comments site-wide, disable application passwords, enable `.md` Markdown output for posts and pages (great for LLMs), and add a customizable header notification banner.

== Installation ==

1. Upload the `apppresser-wp` folder to `/wp-content/plugins/` or install via the WordPress plugin installer.
2. Activate the plugin through the **Plugins** screen.
3. Navigate to **Settings** and choose any of the AppPresser sub-pages to configure each module.

== Frequently Asked Questions ==

= Where do I find the settings? =

Each module has its own page under **Settings → Accessibility**, **Settings → Cookies**, **Settings → Maintenance**, **Settings → Social Share**, **Settings → Pop Ups**, and **Settings → Options**.

= How do I add social share buttons to a post? =

Use the `[social_share]` shortcode in any post, page, or widget. Enable or disable individual buttons and customize their colors under **Settings → Social Share**.

= How do I display the cookie policy? =

Use the `[cookie_policy]` shortcode. It displays all cookies detected by the scanner or added manually, organized by category.

= How do I place the accessibility button? =

By default, a floating button appears on the frontend. You can also use the **Accessibility Button** Gutenberg block to place it anywhere in your content. Hide the floating button under **Settings → Accessibility**.

= Does the maintenance mode affect SEO? =

No. The maintenance page sends a `503 Service Unavailable` header with a `Retry-After` directive, telling search engines the downtime is temporary.

= What are .md URLs? =

When enabled under **Settings → Options**, appending `.md` to any post or page URL (e.g. `/hello-world.md`) returns the content as Markdown. This is useful for feeding content to LLMs and AI tools.

== Changelog ==

= 1.0.0 =
* Initial release
* Accessibility module with floating button and Gutenberg block
* Cookie consent banner with scanner and `[cookie_policy]` shortcode
* Maintenance mode with 503 headers
* Social share buttons with color customization and drag-and-drop ordering
* Pop-up builder with TinyMCE editor
* Options: disable comments, disable application passwords, .md URLs, header banner

== Credits ==

Built by [AppPresser](https://apppresser.com).
