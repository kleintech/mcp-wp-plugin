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
- `rank_math_twitter_use_facebook` — `'on'` / `'off'` / empty; **defaults to ON**, see below
- `rank_math_robots` — array of strings, e.g. `["index","follow"]`
- `rank_math_advanced_robots` — **object**, keyed by directive: `{"max-snippet":"-1","max-image-preview":"large"}`

Three notes worth knowing before you point a client at these:

- **`rank_math_advanced_robots` is a map, not a list.** Registering it as an array of strings makes WordPress reindex it on every write — including Rank Math's own metabox saves — which silently wipes the directives. It is registered as an object schema for that reason.
- **The `_image` keys hold URLs, and Rank Math keeps a paired `_image_id` attachment ID that this module does not expose.** Writing an image URL through REST leaves that pair inconsistent, so prefer setting social images in Rank Math's own UI.
- **Writing `rank_math_twitter_*` does nothing on a post that has never had `rank_math_twitter_use_facebook` set to `'off'`.** See below.

#### The Twitter fallback, and the warning a client should surface

Rank Math has a per-post "Use Facebook data for Twitter" switch. When it is on, `Twitter::use_facebook()` sets the tag prefix to `facebook` and the `rank_math_twitter_*` values are never rendered — they stay in the database, silently ignored.

**Its default is on.** `Twitter::use_facebook()` reads the meta with a default of `true`, and `Options::normalize_data()` maps `'on' => true` and `'off' => false`. So the key has three states:

| Stored value | Behaviour |
| --- | --- |
| *(no row — every untouched post)* | Facebook fields are rendered. Twitter fields ignored. |
| `'on'` | Same. |
| `'off'` | Twitter fields are rendered. |

The key is exposed read/write so a client can both detect this and fix it. But flipping it to `'off'` changes what the site renders on a page that was previously inheriting the Facebook text, so a client **should not flip it silently**. Recommended behaviour before writing any `rank_math_twitter_*` field:

> This post is set to use its Facebook social text for Twitter, which is Rank Math's default. Your Twitter title/description won't appear on the live page unless I also turn that off. Turn it off and use the Twitter values, or leave it and write the Facebook fields instead?

Reading it back is unambiguous: an empty value means "not set", which behaves the same as `'on'`.

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
