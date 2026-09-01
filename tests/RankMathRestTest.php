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
		'rank_math_twitter_title',
		'rank_math_twitter_description',
		'rank_math_twitter_image',
	];

	/** Keys Rank Math stores as arrays. */
	private const ARRAY_KEYS = [
		'rank_math_robots',
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

		$expected = array_merge( self::SCALAR_KEYS, self::ARRAY_KEYS );
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
	public function test_array_keys_declare_an_array_items_schema(): void {
		WpStubs::$post_types = [ 'post' ];

		Rank_Math_Rest::register_meta();

		foreach ( self::ARRAY_KEYS as $key ) {
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

	public function test_sanitize_string_list_reindexes_keys(): void {
		// A sparse/assoc input must come back as a JSON array, not a JSON object;
		// preserving keys would make the REST response fail the array schema.
		$this->assertSame(
			[ 'index', 'follow' ],
			Rank_Math_Rest::sanitize_string_list( [ 3 => 'index', 'x' => 'follow' ] )
		);
	}

	public function test_sanitize_string_list_accepts_an_empty_array(): void {
		$this->assertSame( [], Rank_Math_Rest::sanitize_string_list( [] ) );
	}

	public function test_can_edit_post_meta_delegates_to_edit_post_capability(): void {
		WpStubs::$caps['edit_post:42'] = true;
		WpStubs::$caps['edit_post:43'] = false;

		$this->assertTrue( Rank_Math_Rest::can_edit_post_meta( true, 'rank_math_title', 42 ) );
		$this->assertFalse( Rank_Math_Rest::can_edit_post_meta( true, 'rank_math_title', 43 ) );
	}

	public function test_can_edit_post_meta_ignores_the_allowed_flag(): void {
		// Even if WP defaults $allowed to true, we must still enforce the per-post check.
		WpStubs::$caps['edit_post:99'] = false;
		$this->assertFalse( Rank_Math_Rest::can_edit_post_meta( true, 'rank_math_title', 99 ) );
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
