<?php
/**
 * Expose Yoast SEO post meta to the REST API.
 *
 * Yoast stores SEO data in post meta (e.g. _yoast_wpseo_title) but does not
 * set show_in_rest, so the mcp-wp connector cannot read or update it via
 * the standard /wp/v2/posts endpoints. This module registers those keys
 * with per-post capability checks and per-key sanitizers.
 *
 * @package McpWpHelper
 */

namespace McpWpHelper\Modules;

use McpWpHelper\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Yoast_Rest implements Module {

	/**
	 * Map of Yoast meta key => sanitize callback. Callbacks are either a
	 * string (built-in WP sanitizer) or a [class, method] pair.
	 */
	private const META_KEYS = [
		'_yoast_wpseo_title'                => 'sanitize_text_field',
		'_yoast_wpseo_metadesc'             => 'sanitize_textarea_field',
		'_yoast_wpseo_focuskw'              => 'sanitize_text_field',
		'_yoast_wpseo_canonical'            => 'esc_url_raw',
		'_yoast_wpseo_meta-robots-noindex'  => [ self::class, 'sanitize_robots' ],
		'_yoast_wpseo_meta-robots-nofollow' => [ self::class, 'sanitize_robots' ],
		'_yoast_wpseo_opengraph-title'      => 'sanitize_text_field',
		'_yoast_wpseo_opengraph-description' => 'sanitize_textarea_field',
		'_yoast_wpseo_opengraph-image'      => 'esc_url_raw',
		'_yoast_wpseo_twitter-title'        => 'sanitize_text_field',
		'_yoast_wpseo_twitter-description'  => 'sanitize_textarea_field',
		'_yoast_wpseo_twitter-image'        => 'esc_url_raw',
	];

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_meta' ], 20 );
	}

	/**
	 * Is Yoast SEO installed and loaded on this site?
	 *
	 * Registering these keys on a site without Yoast is not merely noise: the
	 * REST API then accepts writes to _yoast_wpseo_* that return 200, land in
	 * postmeta, and are never read by anything. A silent no-op write is
	 * indistinguishable from a successful one.
	 *
	 * Checked at init:20 rather than at plugins_loaded so the answer accounts
	 * for anything that loads Yoast late. The filter is an escape hatch for a
	 * false negative (a fork, or a bundle that ships Yoast's meta without its
	 * constants) — without it, a missed detection silently removes fields that
	 * a working site depends on.
	 */
	public static function is_active(): bool {
		$detected = defined( 'WPSEO_VERSION' )
			|| defined( 'WPSEO_FILE' )
			|| class_exists( 'WPSEO_Options' );

		return (bool) apply_filters( 'mcp_wp_helper_yoast_active', $detected );
	}

	public static function register_meta(): void {
		if ( ! self::is_active() ) {
			return;
		}

		$post_types = array_diff(
			get_post_types( [ 'public' => true ], 'names' ),
			[ 'attachment' ]
		);

		foreach ( $post_types as $post_type ) {
			foreach ( self::META_KEYS as $key => $sanitizer ) {
				register_post_meta( $post_type, $key, [
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $sanitizer,
					'auth_callback'     => [ self::class, 'can_edit_post_meta' ],
				] );
			}
		}
	}

	/**
	 * Yoast's robots flags are documented as '0' | '1' | '2'. Anything else
	 * gets coerced to '0' (Yoast default).
	 */
	public static function sanitize_robots( $value ): string {
		return in_array( (string) $value, [ '0', '1', '2' ], true ) ? (string) $value : '0';
	}

	/**
	 * Per-post capability check. register_post_meta passes
	 * ( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ) — gate on the
	 * specific post so contributors can't edit SEO for posts they don't own.
	 *
	 * Answer about $user_id, not the current user: the same filter fires under
	 * user_can()/author_can(), where core is asking about somebody else. Using
	 * current_user_can() there returns the logged-in admin's answer to a
	 * question about a contributor.
	 */
	public static function can_edit_post_meta( bool $allowed, string $meta_key, int $post_id, int $user_id = 0 ): bool {
		return user_can( $user_id, 'edit_post', $post_id );
	}
}
