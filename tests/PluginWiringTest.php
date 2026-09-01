<?php

declare( strict_types = 1 );

namespace McpWpHelper\Tests;

use McpWpHelper\Modules\Rank_Math_Rest;
use McpWpHelper\Modules\Yoast_Rest;
use McpWpHelper\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

/**
 * Guards mcp-wp-helper.php itself.
 *
 * The bug this catches: a module gets its own file and its own test, but the
 * require_once or the ::register() call in the plugin entry point is forgotten.
 * Every module test still passes while the plugin does nothing on a real site
 * (or fatals on an undefined class at plugins_loaded).
 */
final class PluginWiringTest extends TestCase {

	protected function setUp(): void {
		WpStubs::reset();
	}

	public function test_plugin_file_hooks_a_single_plugins_loaded_callback(): void {
		$hooks = array_column( WpStubs::$boot_actions, 'hook' );
		$this->assertSame( [ 'plugins_loaded' ], $hooks );
	}

	public function test_plugins_loaded_callback_registers_every_module(): void {
		$callback = WpStubs::$boot_actions[0]['callback'];
		$this->assertIsCallable( $callback );

		$callback();

		$hooks = [];
		foreach ( WpStubs::$actions as $action ) {
			$this->assertSame( 'init', $action['hook'] );
			$hooks[] = $action['callback'];
		}

		$this->assertContains( [ Yoast_Rest::class, 'register_meta' ], $hooks );
		$this->assertContains( [ Rank_Math_Rest::class, 'register_meta' ], $hooks );
		$this->assertCount( 2, $hooks );
	}

	public function test_version_constant_matches_the_plugin_header(): void {
		// The release workflow rewrites both; a bump that touches only one
		// ships a plugin whose header and constant disagree.
		$source = file_get_contents( dirname( __DIR__ ) . '/mcp-wp-helper.php' );
		$this->assertIsString( $source );

		$this->assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)$/m', $source, $header ) );

		$this->assertSame( $header[1], MCP_WP_HELPER_VERSION );
	}
}
