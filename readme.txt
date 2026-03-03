=== Redline ===
Contributors: jmt
Tags: content-guidelines, editorial, ai, notes, gutenberg
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later

AI-powered editorial review that checks post content against content guidelines and leaves Notes on flagged blocks.

== Description ==

Redline integrates WordPress block-level Notes (6.9+), the Content Guidelines plugin, and the WP AI Client to provide automated editorial review directly in the block editor.

**How it works:**

1. Open the "Redline" sidebar panel in the block editor
2. Click "Check Content" to run an automated review
3. The plugin saves your post, parses all content blocks, checks them against your configured content guidelines using both free lint checks and AI analysis
4. Notes are created inline on any blocks where issues are found
5. Results are displayed in the sidebar grouped by block with severity indicators

**Features:**

* Lint-first checking for vocabulary and readability (free, no AI cost)
* AI-powered review for tone, voice, and brand alignment
* Inline Notes on flagged blocks for easy review
* Bulk clear all checker-created notes
* Respects WordPress capabilities (edit_post + prompt_ai)

== Installation ==

1. Install and activate the [Content Guidelines](https://github.com/Jameswlepage/content-guidelines) plugin
2. Install and activate the [WP AI Client](https://github.com/WordPress/wp-ai-client) plugin (or use WordPress 7.0+)
3. Configure an AI provider in WP AI Client settings
4. Upload the `redline` folder to `/wp-content/plugins/`
5. Run `npm install && npm run build` in the plugin directory
6. Activate the plugin through the Plugins menu

== Changelog ==

= 1.0.0 =
* Initial release
