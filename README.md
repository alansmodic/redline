# Redline

AI-powered editorial review for the WordPress block editor. Redline checks post content against your [Content Guidelines](https://github.com/Jameswlepage/content-guidelines) using the [WP AI Client](https://github.com/WordPress/wp-ai-client), then leaves inline [Notes](https://make.wordpress.org/core/2024/12/03/block-level-notes-in-wordpress-6-9/) on blocks where issues are found.

## How It Works

Redline adds a sidebar panel to the Gutenberg editor. When you click **Check Content**, the plugin:

1. **Saves the post** so the server has the latest content
2. **Parses all blocks** server-side via `parse_blocks()`, filtering to content blocks (paragraphs, headings, lists, quotes, buttons, images)
3. **Runs free lint checks first** using the Content Guidelines plugin's `Lint_Checker` — catches vocabulary and readability issues at zero AI cost
4. **Sends a batched AI prompt** with all block contents + your merged guidelines via `wp_ai_client_prompt()`, requesting a structured JSON response of issues per block
5. **Merges lint + AI results** and creates WordPress Notes (`comment_type='note'`) on each flagged block
6. **Refreshes the editor** so Notes appear inline on blocks immediately

The sidebar displays a summary of all issues grouped by block, with severity indicators (error/warning/info) and source labels (Lint vs AI).

### Architecture

```
[Sidebar: Check Content]
        |
        v
JS: POST /wp-json/redline/v1/check  { post_id }
        |
        v
PHP: parse_blocks() → filter content blocks
PHP: wp_get_content_guidelines_for_post() → guidelines
PHP: Lint_Checker::check() per block (free)
PHP: wp_ai_client_prompt() → batched AI review → JSON
PHP: wp_insert_comment(type='note') on flagged blocks
PHP: Return results to JS
        |
        v
JS: Display results in sidebar
JS: Refresh editor to show inline Notes
```

## Requirements

| Dependency | Version | Purpose |
|---|---|---|
| **WordPress** | 6.9+ | Block-level Notes support |
| **[Content Guidelines](https://github.com/Jameswlepage/content-guidelines)** | latest | Provides editorial guidelines, vocabulary rules, and the `Lint_Checker` |
| **[WP AI Client](https://github.com/WordPress/wp-ai-client)** | latest (or WP 7.0+) | Provider-agnostic AI API (`wp_ai_client_prompt()`) |
| **PHP** | 8.1+ | Language features (union types, enums) |
| **Node.js** | 18+ | Build tooling via `@wordpress/scripts` |

### WordPress Capabilities

Users need both:
- `edit_post` — standard post editing permission
- `prompt_ai` — WP AI Client capability for AI access

## Installation

1. Install and activate the **Content Guidelines** plugin and configure your editorial guidelines (vocabulary rules, copy rules, etc.)

2. Install and activate the **WP AI Client** plugin and configure an AI provider (e.g., add your Anthropic or OpenAI API key in Settings)

3. Clone this repo into your plugins directory:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/jamesmodic/redline.git
   ```

4. Install dependencies and build:
   ```bash
   cd redline
   npm install
   npm run build
   ```

5. Activate **Redline** in the WordPress admin under Plugins

## Usage

1. Open any post or page in the block editor
2. Click the **Redline** sidebar panel (or find it under the three-dot menu → Plugins → Redline)
3. Click **Check Content**
4. Review results in the sidebar — each flagged block shows its issues with severity and guideline section references
5. Click on inline Notes in the editor to see detailed issues per block
6. Use **Clear All Notes** to bulk-remove all Redline-created notes when done

## Plugin Structure

```
redline/
├── redline.php                       # Main plugin file, bootstrap, dependency checks
├── includes/
│   ├── class-rest-controller.php     # REST API: POST /redline/v1/check, /redline/v1/clear
│   ├── class-block-checker.php       # Core: parse blocks, lint, AI prompt, merge results
│   └── class-note-creator.php        # Creates WP Notes on blocks, updates block metadata
├── src/
│   ├── index.js                      # registerPlugin() + PluginSidebar
│   ├── components/
│   │   ├── sidebar-panel.js          # Check button, results summary, clear notes
│   │   └── results-list.js           # Per-block issue display with severity badges
│   └── style.scss                    # Sidebar and results styling
├── package.json                      # @wordpress/scripts build config
└── readme.txt                        # WordPress.org plugin readme
```

## REST API

### `POST /wp-json/redline/v1/check`

Run a content guidelines check on a post.

**Parameters:**
- `post_id` (integer, required) — The post ID to check

**Response:**
```json
{
  "success": true,
  "results": [
    {
      "block_index": 2,
      "block_name": "core/paragraph",
      "excerpt": "Our team of experts will help you achieve...",
      "issues": [
        {
          "message": "Avoid 'team of experts' — use specific roles instead",
          "severity": "warning",
          "guideline_section": "Vocabulary / Readability",
          "source": "lint"
        },
        {
          "message": "Tone is overly promotional for an informational page",
          "severity": "warning",
          "guideline_section": "Brand Voice",
          "source": "ai"
        }
      ]
    }
  ],
  "notes_created": 1
}
```

### `POST /wp-json/redline/v1/clear`

Remove all Redline-created notes from a post.

**Parameters:**
- `post_id` (integer, required)

**Response:**
```json
{
  "success": true,
  "notes_cleared": 3
}
```

## Development

```bash
npm start    # Watch mode with hot reload
npm run build  # Production build
```

## License

GPL-2.0-or-later
