<?php

declare( strict_types = 1 );

namespace McpWpHelper\Tests;

use McpWpHelper\Modules\Yoast_Rest;
use McpWpHelper\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

final class YoastRestTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();

		// Yoast is not loaded in the unit-test process, so detection is
		// false by default. These tests are about what gets registered once the
		// module is enabled; the gate itself is covered by its own tests below.
		WpStubs::set_seo_plugin_active( 'mcp_wp_helper_yoast_active', true );
	}

	public function test_register_hooks_register_meta_on_init_with_late_priority(): void {
		Yoast_Rest::register();

		$this->assertCount( 1, WpStubs::$actions );
		$action = WpStubs::$actions[0];
		$this->assertSame( 'init', $action['hook'] );
		$this->assertSame( 20, $action['priority'] );
		$this->assertSame( [ Yoast_Rest::class, 'register_meta' ], $action['callback'] );
	}

	public function test_register_meta_skips_attachment_post_type(): void {
		WpStubs::$post_types = [ 'post', 'page', 'attachment' ];

		Yoast_Rest::register_meta();

		$post_types = array_unique( array_column( WpStubs::$registered_meta, 'post_type' ) );
		sort( $post_types );
		$this->assertSame( [ 'page', 'post' ], $post_types );
	}

	public function test_register_meta_registers_every_meta_key_per_post_type(): void {
		WpStubs::$post_types = [ 'post' ];

		Yoast_Rest::register_meta();

		$keys = array_column( WpStubs::$registered_meta, 'meta_key' );
		$this->assertContains( '_yoast_wpseo_title', $keys );
		$this->assertContains( '_yoast_wpseo_metadesc', $keys );
		$this->assertContains( '_yoast_wpseo_focuskw', $keys );
		$this->assertContains( '_yoast_wpseo_canonical', $keys );
		$this->assertContains( '_yoast_wpseo_meta-robots-noindex', $keys );
		$this->assertContains( '_yoast_wpseo_meta-robots-nofollow', $keys );
		$this->assertContains( '_yoast_wpseo_opengraph-title', $keys );
		$this->assertContains( '_yoast_wpseo_opengraph-description', $keys );
		$this->assertContains( '_yoast_wpseo_opengraph-image', $keys );
		$this->assertContains( '_yoast_wpseo_twitter-title', $keys );
		$this->assertContains( '_yoast_wpseo_twitter-description', $keys );
		$this->assertContains( '_yoast_wpseo_twitter-image', $keys );
	}

	public function test_registered_meta_uses_expected_rest_args(): void {
		WpStubs::$post_types = [ 'post' ];

		Yoast_Rest::register_meta();

		// Every assertion below lives inside the loop, so an empty list would
		// make this test vacuous rather than failing.
		$this->assertNotEmpty( WpStubs::$registered_meta );

		foreach ( WpStubs::$registered_meta as $entry ) {
			$args = $entry['args'];
			$this->assertSame( 'string', $args['type'], "type for {$entry['meta_key']}" );
			$this->assertTrue( $args['single'], "single for {$entry['meta_key']}" );
			$this->assertTrue( $args['show_in_rest'], "show_in_rest for {$entry['meta_key']}" );
			$this->assertIsCallable( $args['sanitize_callback'], "sanitize_callback for {$entry['meta_key']}" );
			$this->assertSame(
				[ Yoast_Rest::class, 'can_edit_post_meta' ],
				$args['auth_callback'],
				"auth_callback for {$entry['meta_key']}"
			);
		}
	}

	public function test_canonical_uses_url_sanitizer(): void {
		WpStubs::$post_types = [ 'post' ];
		Yoast_Rest::register_meta();

		$canonical = $this->find_meta( 'post', '_yoast_wpseo_canonical' );
		$this->assertSame( 'esc_url_raw', $canonical['args']['sanitize_callback'] );
	}

	public function test_metadesc_uses_textarea_sanitizer(): void {
		WpStubs::$post_types = [ 'post' ];
		Yoast_Rest::register_meta();

		$metadesc = $this->find_meta( 'post', '_yoast_wpseo_metadesc' );
		$this->assertSame( 'sanitize_textarea_field', $metadesc['args']['sanitize_callback'] );
	}

	public function test_robots_keys_use_robots_sanitizer(): void {
		WpStubs::$post_types = [ 'post' ];
		Yoast_Rest::register_meta();

		foreach ( [ '_yoast_wpseo_meta-robots-noindex', '_yoast_wpseo_meta-robots-nofollow' ] as $key ) {
			$entry = $this->find_meta( 'post', $key );
			$this->assertSame(
				[ Yoast_Rest::class, 'sanitize_robots' ],
				$entry['args']['sanitize_callback'],
				"sanitize_callback for {$key}"
			);
		}
	}

	/**
	 * @dataProvider valid_robots_values
	 */
	public function test_sanitize_robots_passes_through_valid_values( $input, string $expected ): void {
		$this->assertSame( $expected, Yoast_Rest::sanitize_robots( $input ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function valid_robots_values(): array {
		return [
			'string 0'  => [ '0', '0' ],
			'string 1'  => [ '1', '1' ],
			'string 2'  => [ '2', '2' ],
			'int 1 coerces to string' => [ 1, '1' ],
			'int 2 coerces to string' => [ 2, '2' ],
		];
	}

	/**
	 * @dataProvider invalid_robots_values
	 */
	public function test_sanitize_robots_coerces_invalid_values_to_zero( $input ): void {
		$this->assertSame( '0', Yoast_Rest::sanitize_robots( $input ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalid_robots_values(): array {
		return [
			'empty string' => [ '' ],
			'out of range' => [ '3' ],
			'negative'     => [ '-1' ],
			'arbitrary'    => [ 'noindex' ],
			'int 3'        => [ 3 ],
			'bool false'   => [ false ],
			'null'         => [ null ],
		];
	}

	public function test_can_edit_post_meta_delegates_to_edit_post_capability(): void {
		WpStubs::$user_caps['7:edit_post:42'] = true;
		WpStubs::$user_caps['7:edit_post:43'] = false;

		$this->assertTrue( Yoast_Rest::can_edit_post_meta( true, '_yoast_wpseo_title', 42, 7 ) );
		$this->assertFalse( Yoast_Rest::can_edit_post_meta( true, '_yoast_wpseo_title', 43, 7 ) );
	}

	public function test_can_edit_post_meta_ignores_the_allowed_flag(): void {
		// Even if WP hands us $allowed = true, we must still enforce the per-post check.
		WpStubs::$user_caps['7:edit_post:99'] = false;
		$this->assertFalse( Yoast_Rest::can_edit_post_meta( true, '_yoast_wpseo_title', 99, 7 ) );
	}

	/**
	 * The bug this catches: `return $allowed && user_can( ... )`, which reads
	 * like a conservative "honour what core already decided" edit and would in
	 * fact deny every write. Core computes $allowed as
	 * ! is_protected_meta( $meta_key ) (capabilities.php:475), and every key
	 * either module registers is protected — Yoast's by its leading underscore,
	 * Rank Math's by its own is_protected_meta filter — so on a real site
	 * $allowed arrives FALSE for all of them. Deny-everything is the realistic
	 * regression here; the allowed=true case above cannot catch it.
	 */
	public function test_can_edit_post_meta_grants_access_despite_allowed_being_false(): void {
		WpStubs::$user_caps['7:edit_post:99'] = true;
		$this->assertTrue( Yoast_Rest::can_edit_post_meta( false, '_yoast_wpseo_title', 99, 7 ) );
	}

	/**
	 * The bug this catches: answering with current_user_can() instead of
	 * user_can( $user_id, ... ). Core fires this same filter from
	 * user_can()/author_can(), where it is asking about somebody other than the
	 * logged-in user — so an admin browsing the screen would grant edit rights
	 * on behalf of a contributor who has none.
	 */
	public function test_can_edit_post_meta_answers_about_the_user_core_asked_about(): void {
		WpStubs::$current_user_id             = 1;   // an admin, viewing.
		WpStubs::$user_caps['1:edit_post:42'] = true;
		WpStubs::$user_caps['5:edit_post:42'] = false; // the contributor being asked about.

		$this->assertFalse( Yoast_Rest::can_edit_post_meta( true, '_yoast_wpseo_title', 42, 5 ) );

		// ...and the converse, so this cannot pass merely by returning false:
		// current user denied, the user core asked about allowed.
		WpStubs::$current_user_id             = 5;
		WpStubs::$user_caps['5:edit_post:77'] = false;
		WpStubs::$user_caps['7:edit_post:77'] = true;

		$this->assertTrue( Yoast_Rest::can_edit_post_meta( true, '_yoast_wpseo_title', 77, 7 ) );
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
