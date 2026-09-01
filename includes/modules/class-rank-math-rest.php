<?php
/**
 * Expose Rank Math SEO post meta to the REST API.
 *
 * Rank Math stores SEO data in post meta (e.g. rank_math_title) but does not
 * set show_in_rest, so the mcp-wp connector cannot read or update it via
 * the standard /wp/v2/posts endpoints. This module registers those keys
 * with per-post capability checks and per-key sanitizers.
 *
 * Note: unlike Yoast's keys, Rank Math's keys carry NO leading underscore, so
 * WordPress treats them as unprotected (public) meta. Writes are still gated by
 * auth_callback, but reads are not restricted the same way — anything with read
 * access to the post can see these values once show_in_rest is on.
 *
 * @package McpWpHelper
 */

namespace McpWpHelper\Modules;

use McpWpHelper\Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rank_Math_Rest implements Module {

	/**
	 * Map of Rank Math scalar meta key => sanitize callback. Callbacks are
	 * either a string (built-in WP sanitizer) or a [class, method] pair.
	 *
	 * rank_math_focus_keyword holds a comma-separated list; the first entry is
	 * the primary keyword.
	 */
	private const META_KEYS = [
		'rank_math_title'                => 'sanitize_text_field',
		'rank_math_description'          => 'sanitize_textarea_field',
		'rank_math_focus_keyword'        => 'sanitize_text_field',
		'rank_math_canonical_url'        => 'esc_url_raw',
		'rank_math_pillar_content'       => [ self::class, 'sanitize_pillar_content' ],
		'rank_math_facebook_title'       => 'sanitize_text_field',
		'rank_math_facebook_description' => 'sanitize_textarea_field',
		'rank_math_facebook_image'       => 'esc_url_raw',
		'rank_math_twitter_title'        => 'sanitize_text_field',
		'rank_math_twitter_description'  => 'sanitize_textarea_field',
		'rank_math_twitter_image'        => 'esc_url_raw',
	];

	/**
	 * Rank Math meta keys stored as arrays rather than strings. Registering
	 * these as 'type' => 'string' silently fails to round-trip through REST,
	 * so they need an explicit array schema with string items.
	 */
	private const ARRAY_META_KEYS = [
		'rank_math_robots',
		'rank_math_advanced_robots',
	];

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_meta' ], 20 );
	}

	public static function register_meta(): void {
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

			foreach ( self::ARRAY_META_KEYS as $key ) {
				register_post_meta( $post_type, $key, [
					'type'              => 'array',
					'single'            => true,
					'show_in_rest'      => [
						'schema' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
					'sanitize_callback' => [ self::class, 'sanitize_string_list' ],
					'auth_callback'     => [ self::class, 'can_edit_post_meta' ],
				] );
			}
		}
	}

	/**
	 * Rank Math's pillar-content flag is a checkbox stored as 'on' when set and
	 * absent/empty otherwise. Anything else gets coerced to an empty string.
	 */
	public static function sanitize_pillar_content( $value ): string {
		return 'on' === $value ? 'on' : '';
	}

	/**
	 * Sanitize an array-typed key to a list of strings. Non-array input (a bare
	 * string from a sloppy client, null, etc.) yields an empty list rather than
	 * a PHP error; non-scalar members are dropped.
	 *
	 * @return array<int, string>
	 */
	public static function sanitize_string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$out[] = sanitize_text_field( $item );
			}
		}

		return $out;
	}

	/**
	 * Per-post capability check. register_post_meta passes
	 * ( $allowed, $meta_key, $post_id, $user_id, $cap, $caps ) — gate on the
	 * specific post so contributors can't edit SEO for posts they don't own.
	 */
	public static function can_edit_post_meta( bool $allowed, string $meta_key, int $post_id ): bool {
		return current_user_can( 'edit_post', $post_id );
	}
}
