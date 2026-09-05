<?php
/**
 * Global WP function stubs. Loaded once via WpStubs::install().
 */

declare( strict_types = 1 );

use McpWpHelper\Tests\Support\WpStubs;

function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	WpStubs::$actions[] = [
		'hook'          => $hook,
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	];
	return true;
}

/**
 * Buckets by priority and appends within a bucket, which is how real WordPress
 * stores filters (a priority-keyed array it ksorts). Sorting a flat list with
 * usort instead would scramble same-priority callbacks on PHP 7.4, where sort
 * is not stable — and this plugin supports 7.4.
 */
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	WpStubs::$filters[ $hook ][ $priority ][] = $callback;
	return true;
}

/**
 * @param mixed $value
 * @return mixed
 */
function apply_filters( string $hook, $value, ...$args ) {
	$buckets = WpStubs::$filters[ $hook ] ?? [];
	ksort( $buckets );

	foreach ( $buckets as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}
	}

	return $value;
}

function plugin_dir_path( string $file ): string {
	return rtrim( dirname( $file ), '/' ) . '/';
}

function register_post_meta( string $post_type, string $meta_key, array $args ): bool {
	WpStubs::$registered_meta[] = [
		'post_type' => $post_type,
		'meta_key'  => $meta_key,
		'args'      => $args,
	];
	return true;
}

/**
 * @return array<int, string>
 */
function get_post_types( array $args = [], string $output = 'names' ): array {
	// Tests can override WpStubs::$post_types directly.
	return WpStubs::$post_types;
}

function user_can( $user, string $capability, ...$args ): bool {
	$key = (int) $user . ':' . $capability . ':' . ( $args[0] ?? '' );
	return WpStubs::$user_caps[ $key ] ?? false;
}

function current_user_can( string $capability, ...$args ): bool {
	// Deliberately delegates, so a module that asks about "the current user"
	// when core asked about a *specific* user gives a visibly different answer.
	return user_can( WpStubs::$current_user_id, $capability, ...$args );
}

function sanitize_text_field( $value ): string {
	return is_scalar( $value ) ? trim( (string) $value ) : '';
}

function sanitize_textarea_field( $value ): string {
	return is_scalar( $value ) ? (string) $value : '';
}

function esc_url_raw( $value ): string {
	return is_scalar( $value ) ? (string) $value : '';
}
