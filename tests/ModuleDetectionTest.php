<?php

declare( strict_types = 1 );

namespace McpWpHelper\Tests;

use McpWpHelper\Modules\Rank_Math_Rest;
use McpWpHelper\Modules\Yoast_Rest;
use McpWpHelper\Tests\Support\WpStubs;
use PHPUnit\Framework\TestCase;

/**
 * Guards the per-module SEO-plugin detection gate.
 *
 * The bug this catches: a module registers its meta keys on every site
 * regardless of whether its SEO plugin is installed. Observed on
 * makingilmhome.com (Rank Math, no Yoast), where every post carried the full
 * set of empty _yoast_wpseo_* keys. That is not just noise — the REST API then
 * accepts a write to _yoast_wpseo_title, returns 200, stores it in postmeta,
 * and nothing ever reads it. A write that reports success and has no effect is
 * indistinguishable from one that worked.
 */
final class ModuleDetectionTest extends TestCase {

	private const YOAST_FILTER     = 'mcp_wp_helper_yoast_active';
	private const RANK_MATH_FILTER = 'mcp_wp_helper_rank_math_active';

	protected function setUp(): void {
		WpStubs::reset();
	}

	/**
	 * Neither plugin is loaded in the test process and no filter overrides the
	 * answer, so detection must say no. Guards against a gate that is wired up
	 * but hardcoded true — which would pass every other test in this file.
	 */
	public function test_detection_is_false_when_neither_plugin_is_present(): void {
		$this->assertFalse( Yoast_Rest::is_active() );
		$this->assertFalse( Rank_Math_Rest::is_active() );
	}

	/**
	 * The bug this catches: a typo in a detection identifier. Every other test
	 * here drives detection through the filter, so corrupting all six real
	 * constant/class names leaves the whole suite green while shipping a module
	 * that registers nothing on a live Yoast or Rank Math site — silently
	 * removing fields that worked in 0.2.0, which is this change's own worst
	 * failure mode. These two exercise the real identifiers.
	 *
	 * Separate processes because a constant cannot be undefined once set, and
	 * defining it here would leak into every later test in the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_yoast_detection_is_true_when_its_real_constant_is_defined(): void {
		define( 'WPSEO_VERSION', '22.0' );

		$this->assertTrue( Yoast_Rest::is_active() );
		$this->assertFalse( Rank_Math_Rest::is_active() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rank_math_detection_is_true_when_its_real_constant_is_defined(): void {
		define( 'RANK_MATH_VERSION', '1.0.277' );

		$this->assertTrue( Rank_Math_Rest::is_active() );
		$this->assertFalse( Yoast_Rest::is_active() );
	}

	public function test_yoast_registers_nothing_when_yoast_is_absent(): void {
		Yoast_Rest::register_meta();

		$this->assertSame( [], WpStubs::$registered_meta );
	}

	public function test_rank_math_registers_nothing_when_rank_math_is_absent(): void {
		Rank_Math_Rest::register_meta();

		$this->assertSame( [], WpStubs::$registered_meta );
	}

	/**
	 * The gate must skip registration, not skip the hook. register() runs at
	 * plugins_loaded, before a late-loading SEO plugin has necessarily defined
	 * its constants, so gating there would read the answer too early.
	 */
	public function test_register_still_hooks_init_when_the_plugin_is_absent(): void {
		Yoast_Rest::register();
		Rank_Math_Rest::register();

		$hooks = array_column( WpStubs::$actions, 'hook' );
		$this->assertSame( [ 'init', 'init' ], $hooks );
	}

	public function test_detected_module_registers_its_keys(): void {
		WpStubs::$post_types = [ 'post' ];
		WpStubs::set_seo_plugin_active( self::RANK_MATH_FILTER, true );

		Rank_Math_Rest::register_meta();

		$keys = array_column( WpStubs::$registered_meta, 'meta_key' );
		$this->assertContains( 'rank_math_title', $keys );
	}

	/**
	 * The bug this catches: an exclusive gate ("Rank Math is here, so skip
	 * Yoast"). tabethastable.com runs Rank Math with Yoast still installed, so
	 * an either/or gate would silently drop the _yoast_wpseo_* keys that site
	 * already depends on. Detection is per-module and independent.
	 */
	public function test_both_modules_register_when_both_plugins_are_present(): void {
		WpStubs::$post_types = [ 'post' ];
		WpStubs::set_seo_plugin_active( self::YOAST_FILTER, true );
		WpStubs::set_seo_plugin_active( self::RANK_MATH_FILTER, true );

		Yoast_Rest::register_meta();
		Rank_Math_Rest::register_meta();

		$keys = array_column( WpStubs::$registered_meta, 'meta_key' );
		$this->assertContains( '_yoast_wpseo_title', $keys );
		$this->assertContains( 'rank_math_title', $keys );
	}

	/**
	 * The bug this catches: one shared detection flag behind two filter names.
	 * Enabling Yoast must not drag Rank Math's keys onto a Yoast-only site.
	 */
	public function test_enabling_one_module_does_not_enable_the_other(): void {
		WpStubs::$post_types = [ 'post' ];
		WpStubs::set_seo_plugin_active( self::YOAST_FILTER, true );

		Yoast_Rest::register_meta();
		Rank_Math_Rest::register_meta();

		$keys = array_column( WpStubs::$registered_meta, 'meta_key' );
		$this->assertContains( '_yoast_wpseo_title', $keys );
		$this->assertNotContains( 'rank_math_title', $keys );
	}

	/**
	 * The escape hatch has to work in the disabling direction too — a site that
	 * has Yoast installed but manages its SEO meta elsewhere needs a way to
	 * turn the module off without deactivating this plugin.
	 *
	 * Established in two phases on purpose. Detection is already false in this
	 * process, so asserting only that nothing registers would pass even if the
	 * gate ignored the filter entirely. Phase one proves these exact conditions
	 * DO register; phase two changes nothing but the filter's answer.
	 */
	public function test_filter_can_disable_a_module_that_would_otherwise_register(): void {
		WpStubs::$post_types = [ 'post' ];
		WpStubs::set_seo_plugin_active( self::YOAST_FILTER, true );

		Yoast_Rest::register_meta();
		$this->assertNotSame( [], WpStubs::$registered_meta );

		WpStubs::reset();
		WpStubs::$post_types = [ 'post' ];
		WpStubs::set_seo_plugin_active( self::YOAST_FILTER, false );

		Yoast_Rest::register_meta();
		$this->assertSame( [], WpStubs::$registered_meta );
	}
}
