<?php
/**
 * Plugin Name: MCP WP Helper
 * Description: Server-side companion for mcp-wp. Exposes fields and capabilities that the MCP connector needs but core WordPress and common plugins do not register for the REST API.
 * Version:     0.1.0
 * Author:      Jon Klein
 * License:     MIT
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCP_WP_HELPER_VERSION', '0.1.0' );
define( 'MCP_WP_HELPER_DIR', plugin_dir_path( __FILE__ ) );

require_once MCP_WP_HELPER_DIR . 'includes/class-module.php';
require_once MCP_WP_HELPER_DIR . 'includes/modules/class-yoast-rest.php';

add_action( 'plugins_loaded', static function () {
	\McpWpHelper\Modules\Yoast_Rest::register();
} );
