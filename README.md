# MCP WP Helper

Server-side companion plugin for [`mcp-wp`](https://github.com/instawp/mcp-wp). Adds the REST API exposure and capability glue that the MCP connector needs but that core WordPress and common plugins don't ship out of the box.

## Installation

1. Copy this directory to `wp-content/plugins/mcp-wp-helper/` on the target site.
2. Activate **MCP WP Helper** from the Plugins screen.

That's it — there are no settings.

## Modules

### Yoast SEO REST exposure

Yoast SEO stores its per-post fields (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, etc.) as post meta but does not set `show_in_rest`, so the standard `/wp/v2/posts` endpoint cannot read or write them. This module registers the common Yoast meta keys across every public post type with an `edit_posts` auth callback, so mcp-wp can update meta titles and descriptions in bulk via the standard REST surface.

Exposed keys:

- `_yoast_wpseo_title`
- `_yoast_wpseo_metadesc`
- `_yoast_wpseo_focuskw`
- `_yoast_wpseo_canonical`
- `_yoast_wpseo_meta-robots-noindex`
- `_yoast_wpseo_meta-robots-nofollow`
- `_yoast_wpseo_opengraph-title` / `-description` / `-image`
- `_yoast_wpseo_twitter-title` / `-description` / `-image`

## Adding a module

1. Create `includes/modules/class-{name}.php` implementing `McpWpHelper\Module`.
2. `require_once` it from `mcp-wp-helper.php` and call its `register()` in the `plugins_loaded` callback.

## Tests

```bash
composer install
composer test
```

Unit tests stub the WP functions the modules touch, so the suite runs without a WordPress test install or database.
