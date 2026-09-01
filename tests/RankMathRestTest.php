<?php

declare( strict_types = 1 );

namespace McpWpHelper\Tests;

use McpWpHelper\Modules\Rank_Math_Rest;
use McpWpHelper\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class RankMathRestTest extends TestCase {

	/** Keys Rank Math stores as plain strings. */
	private const SCALAR_KEYS = [
		'rank_math_title',
		'rank_math_description',
		'rank_math_focus_keyword',
		'rank_math_canonical_url',
		'rank_math_pillar_content',
		'rank_math_facebook_title',
		'rank_math_facebook_description',
		'rank_math_facebook_image',
		'rank_math_twitter_use_facebook',
		'rank_math_twitter_title',
		'rank_math_twitter_description',
		'rank_math_twitter_image',
	];

	/** Keys Rank Math stores as a numerically indexed list of strings. */
	private const LIST_KEYS = [
		'rank_math_robots',
	];

	/** Keys Rank Math stores as a directive-keyed map. */
	private const OBJECT_KEYS = [
		'rank_math_advanced_robots',
	];

	protected function setUp(): void {
		WpStubs::reset();
	}

	public function test_register_hooks_register_meta_on_init_with_late_priority(): void {
		Rank_Math_Rest::register();

		$this->assertCount( 1, WpStubs::$actions );
		$action = WpStubs::$actions[0];
		$this->assertSame( 'init', $action['hook'] );
		$this->assertSame( 20, $action['priority'] );
		$this->assertSame( [ Rank_Math_Rest::class, 'register_meta' ], $action['callback'] );
	}

	public function test_register_meta_skips_attachment_post_type(): void {
		WpStubs::$post_types = [ 'post', 'page', 'attachment' ];

		Rank_Math_Rest::register_meta();

		$post_types = array_unique( array_column( WpStubs::$registered_meta, 'post_type' ) );
		sort( $post_types );
		$this->assertSame( [ 'page', 'post' ], $post_types );
	}

	/**
	 * Catches a key being dropped or misspelled in the META_KEYS map — a typo
	 * there means the connector silently sees no value for that field.
	 */
	public function test_register_meta_registers_every_meta_key_per_post_type(): void {
		WpStubs::$post_types = [ 'post' ];

		Rank_Math_Rest::register_meta();

		$keys = array_column( WpStubs::$registered_meta, 'meta_key' );
		sort( $keys );

		$expected = array_merge( self::SCALAR_KEYS, self::LIST_KEYS, self::OBJECT_KEYS );
		sort( $expected );

		$this->assertSame( $expected, $keys );
	}

	public function test_scalar_keys_use_expected_rest_args(): void {
		WpStubs::$post_types = [ 'post' ];

		Rank_Math_Rest::register_meta();

		foreach ( self::SCALAR_KEYS as $key ) {
			$args = $this->find_meta( 'post', $key )['args'];
			$this->assertSame( 'string', $args['type'], "type for {$key}" );
			$this->assertTrue( $args['single'], "single for {$key}" );
			$this->assertTrue( $args['show_in_rest'], "show_in_rest for {$key}" );
			$this->assertIsCallable( $args['sanitize_callback'], "sanitize_callback for {$key}" );
			$this->assertSame(
				[ Rank_Math_Rest::class, 'can_edit_post_meta' ],
				$args['auth_callback'],
				"auth_callback for {$key}"
			);
		}
	}

	/**
	 * The bug this catches: registering rank_math_robots as 'type' => 'string'
	 * (or with a bare show_in_rest => true) makes the array value fail to
	 * round-trip through the REST API without any error surfacing.
	 */
	public function test_list_keys_declare_an_array_items_schema(): void {
		WpStubs::$post_types = [ 'post' ];

		Rank_Math_Rest::register_meta();

		foreach ( self::LIST_KEYS as $key ) {
			$args = $this->find_meta( 'post', $key )['args'];
			$this->assertSame( 'array', $args['type'], "type for {$key}" );
			$this->assertTrue( $args['single'], "single for {$key}" );
			$this->assertSame(
				[
					'schema' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
				],
				$args['show_in_rest'],
				"show_in_rest schema for {$key}"
			);
			$this->assertSame(
				[ Rank_Math_Rest::class, 'sanitize_string_list' ],
				$args['sanitize_callback'],
				"sanitize_callback for {$key}"
			);
			$this->assertSame(
				[ Rank_Math_Rest::class, 'can_edit_post_meta' ],
				$args['auth_callback'],
				"auth_callback for {$key}"
			);
		}
	}

	/**
	 * The bug this catches: rank_math_advanced_robots is an ASSOCIATIVE array
	 * keyed by directive ('max-snippet' => '-1', ...), not a list. Registering
	 * it as 'type' => 'array' makes core run array_values() over it — on REST
	 * writes AND, via the sanitize_*_meta_* filter, on Rank Math's own metabox
	 * saves — permanently discarding the directive names.
	 */
	public function test_object_keys_declare_an_object_schema_not_an_array_one(): void {
		WpStubs::$post_types = [ 'post' ];

		Rank_Math_Rest::register_meta();

		foreach ( self::OBJECT_KEYS as $key ) {
			$args = $this->find_meta( 'post', $key )['args'];
			$this->assertSame( 'object', $args['type'], "type for {$key}" );
			$this->assertTrue( $args['single'], "single for {$key}" );

			$schema = $args['show_in_rest']['schema'];
			$this->assertSame( 'object', $schema['type'], "schema type for {$key}" );
			$this->assertArrayNotHasKey( 'items', $schema, "list schema leaked into {$key}" );

			// The three directives Rank Math renders must be documented...
			$this->assertSame(
				[ 'max-snippet', 'max-video-preview', 'max-image-preview' ],
				array_keys( $schema['properties'] ),
				"properties for {$key}"
			);
			// ...but unknown directives must still be permitted, not dropped,
			// so a future Rank Math directive isn't destroyed by this plugin.
			$this->assertArrayHasKey( 'additionalProperties', $schema, "additionalProperties for {$key}" );
			$this->assertNotFalse( $schema['additionalProperties'], "additionalProperties for {$key}" );

			$this->assertSame(
				[ Rank_Math_Rest::class, 'sanitize_string_map' ],
				$args['sanitize_callback'],
				"sanitize_callback for {$key}"
			);
			$this->assertSame(
				[ Rank_Math_Rest::class, 'can_edit_post_meta' ],
				$args['auth_callback'],
				"auth_callback for {$key}"
			);
		}
	}

	/**
	 * @dataProvider sanitizer_expectations
	 */
	public function test_keys_use_the_expected_sanitizer( string $key, string $sanitizer ): void {
		WpStubs::$post_types = [ 'post' ];
		Rank_Math_Rest::register_meta();

		$this->assertSame( $sanitizer, $this->find_meta( 'post', $key )['args']['sanitize_callback'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function sanitizer_expectations(): array {
		return [
			'title is text'                => [ 'rank_math_title', 'sanitize_text_field' ],
			'description is textarea'      => [ 'rank_math_description', 'sanitize_textarea_field' ],
			'focus keyword is text'        => [ 'rank_math_focus_keyword', 'sanitize_text_field' ],
			'canonical is url'             => [ 'rank_math_canonical_url', 'esc_url_raw' ],
			'facebook title is text'       => [ 'rank_math_facebook_title', 'sanitize_text_field' ],
			'facebook description is textarea' => [ 'rank_math_facebook_description', 'sanitize_textarea_field' ],
			'facebook image is url'        => [ 'rank_math_facebook_image', 'esc_url_raw' ],
			'twitter title is text'        => [ 'rank_math_twitter_title', 'sanitize_text_field' ],
			'twitter description is textarea' => [ 'rank_math_twitter_description', 'sanitize_textarea_field' ],
			'twitter image is url'         => [ 'rank_math_twitter_image', 'esc_url_raw' ],
		];
	}

	public function test_pillar_content_uses_its_own_sanitizer(): void {
		WpStubs::$post_types = [ 'post' ];
		Rank_Math_Rest::register_meta();

		$this->assertSame(
			[ Rank_Math_Rest::class, 'sanitize_pillar_content' ],
			$this->find_meta( 'post', 'rank_math_pillar_content' )['args']['sanitize_callback']
		);
	}

	public function test_twitter_use_facebook_uses_the_on_off_sanitizer(): void {
		WpStubs::$post_types = [ 'post' ];
		Rank_Math_Rest::register_meta();

		$this->assertSame(
			[ Rank_Math_Rest::class, 'sanitize_on_off' ],
			$this->find_meta( 'post', 'rank_math_twitter_use_facebook' )['args']['sanitize_callback']
		);
	}

	/**
	 * The bug this catches: treating twitter_use_facebook as a two-state
	 * checkbox like pillar_content. It is tri-state and its default is ON —
	 * Twitter::use_facebook() reads it with a default of true — so collapsing
	 * 'off' to '' silently re-enables the Facebook fallback and makes every
	 * rank_math_twitter_* write invisible on the rendered page.
	 *
	 * @dataProvider on_off_values
	 */
	public function test_sanitize_on_off_keeps_both_literals( $input, string $expected ): void {
		$this->assertSame( $expected, Rank_Math_Rest::sanitize_on_off( $input ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function on_off_values(): array {
		return [
			'on stays on'              => [ 'on', 'on' ],
			'off stays off'            => [ 'off', 'off' ],
			'empty means unset'        => [ '', '' ],
			// Options::normalize_data() recognises only the two literals, so
			// anything else must become '' rather than a value that looks set.
			'uppercase OFF is not off' => [ 'OFF', '' ],
			'string 1'                 => [ '1', '' ],
			'string 0'                 => [ '0', '' ],
			'bool true'                => [ true, '' ],
			'bool false'               => [ false, '' ],
			'null'                     => [ null, '' ],
			'array'                    => [ [ 'off' ], '' ],
		];
	}

	public function test_sanitize_pillar_content_keeps_on(): void {
		$this->assertSame( 'on', Rank_Math_Rest::sanitize_pillar_content( 'on' ) );
	}

	/**
	 * Catches truthy-but-wrong values ('1', true, 'yes') being stored verbatim:
	 * Rank Math only treats the literal 'on' as set, so anything else must
	 * normalise to empty rather than to a value that looks enabled but isn't.
	 *
	 * @dataProvider non_on_pillar_values
	 */
	public function test_sanitize_pillar_content_coerces_everything_else_to_empty( $input ): void {
		$this->assertSame( '', Rank_Math_Rest::sanitize_pillar_content( $input ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function non_on_pillar_values(): array {
		return [
			'empty string' => [ '' ],
			'off'          => [ 'off' ],
			'string 1'     => [ '1' ],
			'int 1'        => [ 1 ],
			'bool true'    => [ true ],
			'bool false'   => [ false ],
			'null'         => [ null ],
			'uppercase ON' => [ 'ON' ],
			'array'        => [ [ 'on' ] ],
		];
	}

	public function test_sanitize_string_list_sanitizes_each_member(): void {
		$this->assertSame(
			[ 'index', 'follow' ],
			Rank_Math_Rest::sanitize_string_list( [ ' index ', 'follow' ] )
		);
	}

	/**
	 * The bug: passing a bare string (or null) to an array sanitizer that
	 * foreach'd blindly would emit a PHP warning and, with
	 * convertWarningsToExceptions, take down the request.
	 *
	 * @dataProvider non_array_values
	 */
	public function test_sanitize_string_list_returns_empty_for_non_array_input( $input ): void {
		$this->assertSame( [], Rank_Math_Rest::sanitize_string_list( $input ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function non_array_values(): array {
		return [
			'bare string' => [ 'index' ],
			'null'        => [ null ],
			'int'         => [ 0 ],
			'bool'        => [ false ],
		];
	}

	public function test_sanitize_string_list_drops_non_scalar_members(): void {
		$this->assertSame(
			[ 'index' ],
			Rank_Math_Rest::sanitize_string_list( [ 'index', [ 'nested' ], null ] )
		);
	}

	public function test_sanitize_string_list_reindexes_a_sparse_list(): void {
		// rank_math_robots genuinely IS a numeric list — Rank Math appends with
		// $robots[] and reads it with in_array. The bug this catches: unsetting
		// a member elsewhere leaves a hole, and core's rest_is_array() rejects a
		// non-sequential array outright, so the whole field reads back as null.
		// Only list-shaped keys may use this sanitizer; see sanitize_string_map.
		$this->assertSame(
			[ 'index', 'follow' ],
			Rank_Math_Rest::sanitize_string_list( [ 0 => 'index', 2 => 'follow' ] )
		);
	}

	/**
	 * The bug this catches: reusing the list sanitizer for
	 * rank_math_advanced_robots. Rank Math's directives live in the KEYS, and
	 * its frontend does array_intersect_key against 'max-snippet' et al, so a
	 * reindex means every directive silently stops being emitted.
	 */
	public function test_sanitize_string_map_preserves_directive_keys(): void {
		$this->assertSame(
			[ 'max-snippet' => '-1', 'max-image-preview' => 'large' ],
			Rank_Math_Rest::sanitize_string_map(
				[ 'max-snippet' => '-1', 'max-image-preview' => 'large' ]
			)
		);
	}

	public function test_sanitize_string_map_keeps_false_meaning_disabled(): void {
		// Rank Math writes false (not '') for a directive the user disabled;
		// coercing it to '' would make ! empty() checks read it as unset.
		$this->assertSame(
			[ 'max-video-preview' => false ],
			Rank_Math_Rest::sanitize_string_map( [ 'max-video-preview' => false ] )
		);
	}

	public function test_sanitize_string_map_preserves_unknown_directives(): void {
		// A directive Rank Math adds in a future release must survive this
		// plugin rather than be silently stripped on the next save.
		$this->assertSame(
			[ 'max-future-thing' => 'yes' ],
			Rank_Math_Rest::sanitize_string_map( [ 'max-future-thing' => 'yes' ] )
		);
	}

	public function test_sanitize_string_map_drops_non_scalar_members(): void {
		$this->assertSame(
			[ 'max-snippet' => '-1' ],
			Rank_Math_Rest::sanitize_string_map(
				[ 'max-snippet' => '-1', 'nested' => [ 'a' ] ]
			)
		);
	}

	/**
	 * Same guard as the list sanitizer: a bare string must not reach foreach().
	 *
	 * @dataProvider non_array_values
	 */
	public function test_sanitize_string_map_returns_empty_for_non_array_input( $input ): void {
		$this->assertSame( [], Rank_Math_Rest::sanitize_string_map( $input ) );
	}

	public function test_sanitize_string_list_accepts_an_empty_array(): void {
		$this->assertSame( [], Rank_Math_Rest::sanitize_string_list( [] ) );
	}

	public function test_can_edit_post_meta_delegates_to_edit_post_capability(): void {
		WpStubs::$user_caps['7:edit_post:42'] = true;
		WpStubs::$user_caps['7:edit_post:43'] = false;

		$this->assertTrue( Rank_Math_Rest::can_edit_post_meta( true, 'rank_math_title', 42, 7 ) );
		$this->assertFalse( Rank_Math_Rest::can_edit_post_meta( true, 'rank_math_title', 43, 7 ) );
	}

	public function test_can_edit_post_meta_ignores_the_allowed_flag(): void {
		// Even if WP defaults $allowed to true, we must still enforce the per-post check.
		WpStubs::$user_caps['7:edit_post:99'] = false;
		$this->assertFalse( Rank_Math_Rest::can_edit_post_meta( true, 'rank_math_title', 99, 7 ) );
	}

	/**
	 * The bug this catches: answering with current_user_can() instead of
	 * user_can( $user_id, ... ). See the twin test in YoastRestTest.
	 */
	public function test_can_edit_post_meta_answers_about_the_user_core_asked_about(): void {
		WpStubs::$current_user_id             = 1;
		WpStubs::$user_caps['1:edit_post:42'] = true;
		WpStubs::$user_caps['5:edit_post:42'] = false;

		$this->assertFalse( Rank_Math_Rest::can_edit_post_meta( true, 'rank_math_title', 42, 5 ) );
	}

	/**
	 * @return array{post_type: string, meta_key: string, args: array<string, mixed>}
	 */
	private function find_meta( string $post_type, string $meta_key ): array {
		foreach ( WpStubs::$registered_meta as $entry ) {
			if ( $entry['post_type'] === $post_type && $entry['meta_key'] === $meta_key ) {
				return $entry;
			}
		}
		$this->fail( "No registered meta for {$post_type}/{$meta_key}" );
	}
}
