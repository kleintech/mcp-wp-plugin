<?php
/**
 * Test bootstrap.
 *
 * The plugin is thin enough that we don't need a real WordPress test install —
 * we stub the handful of WP functions the module touches and assert against
 * the recorded calls. Keeps `composer test` runnable in CI without a DB.
 */

declare( strict_types = 1 );

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "Run `composer install` before running the test suite.\n" );
	exit( 1 );
}
require_once $autoload;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/Support/WpStubs.php';
McpWpHelper\Tests\Support\WpStubs::install();

// Load the real plugin entry point rather than re-listing its requires here:
// a module wired into one file but not the other would otherwise go unnoticed.
require_once dirname( __DIR__ ) . '/mcp-wp-helper.php';

McpWpHelper\Tests\Support\WpStubs::$boot_actions = McpWpHelper\Tests\Support\WpStubs::$actions;
