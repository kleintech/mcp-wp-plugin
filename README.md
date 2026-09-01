# MCP WP Helper

Server-side companion plugin for [`mcp-wp`](https://github.com/instawp/mcp-wp). Adds the REST API exposure and capability glue that the MCP connector needs but that core WordPress and common plugins don't ship out of the box.

## Installation

1. Copy this directory to `wp-content/plugins/mcp-wp-helper/` on the target site.
2. Activate **MCP WP Helper** from the Plugins screen.

That's it — there are no settings.

## Modules

### Yoast SEO REST exposure

Yoast SEO stores its per-post fields (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, etc.) as post meta but does not set `show_in_rest`, so the standard `/wp/v2/posts` endpoint cannot read or write them. This module registers the common Yoast meta keys across every public post type with a per-post `edit_post` auth callback, so mcp-wp can update meta titles and descriptions in bulk via the standard REST surface. Because the check is per-post rather than the blanket `edit_posts`, a contributor still can't edit SEO on a post they don't own.

Exposed keys:

- `_yoast_wpseo_title`
- `_yoast_wpseo_metadesc`
- `_yoast_wpseo_focuskw`
- `_yoast_wpseo_canonical`
- `_yoast_wpseo_meta-robots-noindex`
- `_yoast_wpseo_meta-robots-nofollow`
- `_yoast_wpseo_opengraph-title` / `-description` / `-image`
- `_yoast_wpseo_twitter-title` / `-description` / `-image`

### Rank Math SEO REST exposure

The same problem, different plugin: Rank Math stores its per-post fields under `rank_math_*` post meta and likewise doesn't set `show_in_rest`, so on a Rank Math site the connector sees an effectively empty meta object. This module registers those keys across every public post type, with the same per-post `edit_post` auth callback.

Exposed keys:

- `rank_math_title`
- `rank_math_description`
- `rank_math_focus_keyword` — comma-separated; the first entry is the primary keyword
- `rank_math_canonical_url`
- `rank_math_pillar_content` — `'on'` when set, empty otherwise
- `rank_math_facebook_title` / `_description` / `_image`
- `rank_math_twitter_title` / `_description` / `_image`
- `rank_math_robots` — array of strings, e.g. `["index","follow"]`
- `rank_math_advanced_robots` — **object**, keyed by directive: `{"max-snippet":"-1","max-image-preview":"large"}`

Two notes worth knowing before you point a client at these:

- **`rank_math_advanced_robots` is a map, not a list.** Registering it as an array of strings makes WordPress reindex it on every write — including Rank Math's own metabox saves — which silently wipes the directives. It is registered as an object schema for that reason.
- **The `_image` keys hold URLs, and Rank Math keeps a paired `_image_id` attachment ID that this module does not expose.** Writing an image URL through REST leaves that pair inconsistent, so prefer setting social images in Rank Math's own UI.

## Adding a module

1. Create `includes/modules/class-{name}.php` implementing `McpWpHelper\Module`.
2. `require_once` it from `mcp-wp-helper.php` and call its `register()` in the `plugins_loaded` callback.

`tests/bootstrap.php` loads `mcp-wp-helper.php` itself, so there's nothing to duplicate there — and `tests/PluginWiringTest.php` fails if a module is defined but never wired up.

## Tests

```bash
composer install
composer test
```

Unit tests stub the WP functions the modules touch, so the suite runs without a WordPress test install or database.
