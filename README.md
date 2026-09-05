# MCP WP Helper

Server-side companion plugin for [`mcp-wp`](https://github.com/instawp/mcp-wp). Adds the REST API exposure and capability glue that the MCP connector needs but that core WordPress and common plugins don't ship out of the box.

## Installation

1. Copy this directory to `wp-content/plugins/mcp-wp-helper/` on the target site.
2. Activate **MCP WP Helper** from the Plugins screen.

That's it — there are no settings.

## Modules

Each module only registers its meta keys when the plugin it supports is actually
present. Detection runs at `init` priority 20 — late enough to see a plugin that
loads on `plugins_loaded` — and is independent per module, so a site running both
Yoast and Rank Math (a migration in progress, say) gets both sets of keys.

This matters beyond tidiness. A key registered for an absent plugin still accepts
writes: the REST API returns `200`, the value lands in `postmeta`, and nothing
ever reads it. A write that reports success but has no effect is worse than one
that fails.

| Module | Detected by | Filter |
| --- | --- | --- |
| Yoast SEO | `WPSEO_VERSION`, `WPSEO_FILE`, or `WPSEO_Options` | `mcp_wp_helper_yoast_active` |
| Rank Math | `RANK_MATH_VERSION`, `RANK_MATH_FILE`, or `RankMath` | `mcp_wp_helper_rank_math_active` |

Each filter receives the detected boolean and can force it either way — to cover a
fork or bundle that ships the meta without the constants, or to switch a module off
on a site that manages those fields elsewhere. Add it before `init` priority 20 (a
mu-plugin, a plugin body, or `functions.php` all qualify); added from a later hook
it silently does nothing, because the gate has already been evaluated:

```php
add_filter( 'mcp_wp_helper_yoast_active', '__return_false' );
```

Detection answers "installed and loaded", not "operating" — a plugin that defines
its constants and then aborts on an unmet PHP or WordPress requirement still reads
as present.

**Gating removes reads, not just writes.** `register_post_meta` is what puts a key
in the REST `meta` object at all, so on a site where the plugin is gone the keys
disappear from the response rather than merely rejecting writes. That matters for
leftovers: a site migrated from Yoast to Rank Math still has `_yoast_wpseo_*` rows
in `postmeta`, and once the Yoast module gates off, those rows are invisible and
un-clearable through the REST API. They are inert either way — nothing renders
them — but if you want to read or delete them, force the module on for as long as
the cleanup takes:

```php
add_filter( 'mcp_wp_helper_yoast_active', '__return_true' );
```

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
- **Writing the `_image` keys has no rendered effect.** They hold URLs, but Rank Math's frontend picks the social image from the paired `_image_id` attachment ID only (`includes/opengraph/class-image.php:409`) — it never reads the URL. That key is not exposed here, so social images have to be set in Rank Math's own UI.
- **Writing `rank_math_twitter_*` does nothing on a post that has never had `rank_math_twitter_use_facebook` set to `'off'`.** See below.

#### The Twitter fallback, and the warning a client should surface

Rank Math has a per-post "Use Facebook data for Twitter" switch. When it is on, `Twitter::use_facebook()` sets the tag prefix to `facebook` and the `rank_math_twitter_*` values are never rendered — they stay in the database, silently ignored.

**Its default is on.** `Twitter::use_facebook()` reads the meta with a default of `true`, and `Options::normalize_data()` maps `'on' => true` and `'off' => false`. So the key has three states:

| Stored value | Behaviour |
| --- | --- |
| *(no row — every untouched post)* | Facebook fields are rendered. Twitter fields ignored. |
| `'on'` | Same. |
| `'off'` | Twitter fields are rendered. |

(`'true'` / `'false'` / `'1'` / `'0'` are also honoured by Rank Math's frontend; this plugin canonicalises them to `'on'` / `'off'` on write, which is the only spelling Rank Math's own editor agrees with.)

The key is exposed read/write so a client can both detect this and fix it. But flipping it to `'off'` changes what the site renders on a page that was previously inheriting the Facebook text, so a client **should not flip it silently**. Recommended behaviour before writing any `rank_math_twitter_*` field:

> This post is set to use its Facebook social text for Twitter, which is Rank Math's default. Your Twitter title/description won't appear on the live page unless I also turn that off. Turn it off and use the Twitter values, or leave it and write the Facebook fields instead?

Reading it back is unambiguous: an empty value means "not set", which behaves the same as `'on'`.

## Adding a module

1. Create `includes/modules/class-{name}.php` implementing `McpWpHelper\Module`.
2. `require_once` it from `mcp-wp-helper.php` and call its `register()` in the `plugins_loaded` callback.
3. Gate it on detecting whatever it supports, the way the SEO modules do — hook
   `init` unconditionally, then bail early from the registration callback when the
   target plugin isn't there. Registering keys speculatively is how you end up with
   writes that silently do nothing.

`tests/bootstrap.php` loads `mcp-wp-helper.php` itself, so there's nothing to duplicate there — and `tests/PluginWiringTest.php` fails if a module is defined but never wired up.

## Tests

```bash
composer install
composer test
```

Unit tests stub the WP functions the modules touch, so the suite runs without a WordPress test install or database.
