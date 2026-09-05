<?php
/**
 * Expose Rank Math SEO post meta to the REST API.
 *
 * Rank Math stores SEO data in post meta (e.g. rank_math_title) but does not
 * set show_in_rest, so the mcp-wp connector cannot read or update it via
 * the standard /wp/v2/posts endpoints. This module registers those keys
 * with per-post capability checks and per-key sanitizers.
 *
 * Note on visibility: unlike Yoast's keys, Rank Math's keys carry NO leading
 * underscore. Rank Math papers over that by filtering is_protected_meta to true
 * for every rank_math_* key (its includes/class-common.php), but that filter is
 * not consulted by WP_REST_Meta_Fields — so once show_in_rest is on, reads are
 * NOT restricted the way Yoast's underscore-prefixed keys are. Writes are still
 * gated by auth_callback; reads are visible to anyone who can read the post.
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
	 * the primary keyword. Rank Math joins with a bare ',' and splits without
	 * trimming, so clients should not pad the separator with spaces.
	 *
	 * The *_image keys hold URLs. Rank Math also keeps a paired
	 * rank_math_{network}_image_id attachment ID that this module deliberately
	 * does not expose — writing only the URL leaves the pair inconsistent, so
	 * social images are better set through Rank Math's own UI.
	 *
	 * rank_math_twitter_use_facebook is a TRI-STATE and its default is ON:
	 * Twitter::use_facebook() reads it with a default of true, and
	 * Options::normalize_data() maps 'on' => true and 'off' => false. So an
	 * ABSENT row means the Facebook fields are rendered in place of the Twitter
	 * ones — which is the state every post starts in. Writing
	 * rank_math_twitter_* has no visible effect until this key is set to 'off'.
	 * Exposed read/write so a client can detect that and decide; see the README
	 * for the warning a client should surface before flipping it.
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
		'rank_math_twitter_use_facebook' => [ self::class, 'sanitize_on_off' ],
		'rank_math_twitter_title'        => 'sanitize_text_field',
		'rank_math_twitter_description'  => 'sanitize_textarea_field',
		'rank_math_twitter_image'        => 'esc_url_raw',
	];

	/**
	 * Rank Math meta keys stored as a flat, numerically indexed list of strings
	 * (e.g. [ 'index', 'follow' ]). Registering these as 'type' => 'string'
	 * silently fails to round-trip through REST, so they need an explicit array
	 * schema with string items.
	 */
	private const LIST_META_KEYS = [
		'rank_math_robots',
	];

	/**
	 * Rank Math meta keys stored as an ASSOCIATIVE array keyed by directive —
	 * [ 'max-snippet' => '-1', 'max-video-preview' => false, ... ].
	 *
	 * These must NOT be registered as 'type' => 'array'. WordPress normalises
	 * array-typed values with array_values() (rest_sanitize_array), and because
	 * register_post_meta's sanitize_callback is hooked to
	 * sanitize_{$object_type}_meta_{$key}, that runs on EVERY update_post_meta
	 * call — including Rank Math's own metabox saves. The keys would be
	 * discarded and the directives silently lost site-wide.
	 */
	private const OBJECT_META_KEYS = [
		'rank_math_advanced_robots',
	];

	/**
	 * Advanced-robots directives Rank Math itself renders. Declared explicitly
	 * so the REST schema documents them; unknown keys are still allowed through
	 * rather than destroyed, in case Rank Math adds more.
	 */
	private const ADVANCED_ROBOTS_DIRECTIVES = [
		'max-snippet',
		'max-video-preview',
		'max-image-preview',
	];

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_meta' ], 20 );
	}

	/**
	 * Is Rank Math installed and loaded on this site?
	 *
	 * Registering these keys on a site without Rank Math is not merely noise:
	 * the REST API then accepts writes to rank_math_* that return 200, land in
	 * postmeta, and are never read by anything. A silent no-op write is
	 * indistinguishable from a successful one.
	 *
	 * Checked at init:20 rather than at plugins_loaded so the answer accounts
	 * for anything that loads Rank Math late. The filter is an escape hatch for
	 * a false negative (a fork, or a bundle that ships Rank Math's meta without
	 * its constants) — without it, a missed detection silently removes fields
	 * that a working site depends on.
	 *
	 * Detection is per-module and independent of Yoast: a site mid-migration
	 * can legitimately run both, and both modules should register there.
	 *
	 * Note this answers "loaded", not "operating": Rank Math defines its
	 * constants before checking its own PHP/WP requirements, so a copy that
	 * self-aborts on an unmet requirement still reads as present here.
	 */
	public static function is_active(): bool {
		$detected = defined( 'RANK_MATH_VERSION' )
			|| defined( 'RANK_MATH_FILE' )
			|| class_exists( 'RankMath' );

		return (bool) apply_filters( 'mcp_wp_helper_rank_math_active', $detected );
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

			foreach ( self::LIST_META_KEYS as $key ) {
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

			foreach ( self::OBJECT_META_KEYS as $key ) {
				register_post_meta( $post_type, $key, [
					'type'              => 'object',
					'single'            => true,
					'show_in_rest'      => [
						'schema' => [
							'type'                 => 'object',
							'properties'           => self::advanced_robots_properties(),
							'additionalProperties' => [ 'type' => [ 'string', 'integer', 'boolean' ] ],
						],
					],
					'sanitize_callback' => [ self::class, 'sanitize_string_map' ],
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
	 * REST schema for the advanced-robots directives Rank Math renders. Each is
	 * either a value ('-1', 'large') or false when the directive is disabled.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function advanced_robots_properties(): array {
		$properties = [];
		foreach ( self::ADVANCED_ROBOTS_DIRECTIVES as $directive ) {
			$properties[ $directive ] = [ 'type' => [ 'string', 'integer', 'boolean' ] ];
		}

		return $properties;
	}

	/**
	 * Rank Math's tri-state checkbox storage: 'on', 'off', or no row at all.
	 *
	 * Two different Rank Math readers disagree on the accepted spelling, so
	 * this canonicalises rather than whitelisting one of them:
	 *   - Options::normalize_data() (class-options.php:53-58) honours
	 *     'on'/'true' => on, 'off'/'false' => off, and '0'/'1' via intval.
	 *   - The editor (class-screen.php:312) treats ONLY the exact string 'off'
	 *     as off, and everything else — including 'false' — as on.
	 * A stored 'false' therefore renders as off but displays as on. Mapping the
	 * synonyms onto 'on'/'off' preserves the author's intent while keeping the
	 * stored value in the set both readers agree about.
	 *
	 * Anything genuinely unrecognised becomes '' — no row, which falls back to
	 * the key's own default (ON), the same as an untouched post.
	 */
	public static function sanitize_on_off( $value ): string {
		if ( in_array( $value, [ 'on', 'true', '1' ], true ) ) {
			return 'on';
		}

		if ( in_array( $value, [ 'off', 'false', '0' ], true ) ) {
			return 'off';
		}

		return '';
	}

	/**
	 * Sanitize an object-typed key, PRESERVING its keys. Non-array input yields
	 * an empty map rather than a PHP error; non-scalar members are dropped; a
	 * literal false is kept as-is because Rank Math uses it to mean "disabled".
	 *
	 * @return array<string, string|false>
	 */
	public static function sanitize_string_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( $value as $key => $item ) {
			if ( false === $item ) {
				$out[ (string) $key ] = false;
			} elseif ( is_scalar( $item ) ) {
				$out[ (string) $key ] = sanitize_text_field( $item );
			}
		}

		return $out;
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
