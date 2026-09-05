<?php
/**
 * Minimal WordPress function stubs for unit testing.
 *
 * Records each invocation so tests can assert on it. Call ::reset() in
 * setUp() to clear recorded state and configure return values.
 */

declare( strict_types = 1 );

namespace McpWpHelper\Tests\Support;

final class WpStubs {

	/** @var array<int, array{hook: string, callback: mixed, priority: int, accepted_args: int}> */
	public static array $actions = [];

	/** @var array<int, array{post_type: string, meta_key: string, args: array<string, mixed>}> */
	public static array $registered_meta = [];

	/** @var array<string, bool> Map of "{user_id}:{cap}:{object_id}" => allowed */
	public static array $user_caps = [];

	/** @var int User that current_user_can() answers for */
	public static int $current_user_id = 0;

	/** @var array<int, string> Post types returned by get_post_types stub */
	public static array $post_types = [ 'post', 'page', 'attachment' ];

	/** @var array<string, array<int, array<int, callable>>> Hook => priority => callbacks, in insertion order */
	public static array $filters = [];

	/**
	 * Force a module's detection filter to a fixed answer.
	 *
	 * Detection reads real constants/classes, which cannot be undefined once
	 * set — so tests drive it through the filter instead of defining
	 * WPSEO_VERSION and poisoning every later test in the process.
	 */
	public static function set_seo_plugin_active( string $filter, bool $active ): void {
		add_filter( $filter, static function () use ( $active ): bool {
			return $active;
		} );
	}

	/**
	 * Snapshot of the hooks the real plugin file registered at load time.
	 * Taken once by the bootstrap and deliberately NOT cleared by reset(),
	 * so tests can assert on the plugin's own wiring.
	 *
	 * @var array<int, array{hook: string, callback: mixed, priority: int, accepted_args: int}>
	 */
	public static array $boot_actions = [];

	public static function install(): void {
		if ( function_exists( 'add_action' ) ) {
			return;
		}

		require __DIR__ . '/wp-functions.php';
	}

	public static function reset(): void {
		self::$actions         = [];
		self::$registered_meta = [];
		self::$user_caps       = [];
		self::$current_user_id = 0;
		self::$post_types      = [ 'post', 'page', 'attachment' ];
		self::$filters         = [];
	}
}
