<?php
/**
 * Base contract for MCP WP Helper modules.
 *
 * Each module is a self-contained piece of glue (REST exposure, capability tweak,
 * meta registration, etc.) that mcp-wp needs but core/3rd-party plugins don't ship.
 *
 * @package McpWpHelper
 */

namespace McpWpHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Module {
	/**
	 * Wire the module's hooks. Called once on plugins_loaded.
	 */
	public static function register(): void;
}
