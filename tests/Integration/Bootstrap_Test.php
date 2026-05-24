<?php
/**
 * Integration smoke test — verifies AI Readiness Kit loads correctly inside the
 * WP test instance booted by wp-phpunit / wp-env.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Integration;

use WP_UnitTestCase;

final class Bootstrap_Test extends WP_UnitTestCase {

	public function test_plugin_constants_are_defined(): void {
		self::assertTrue( defined( 'WPCTX_VERSION' ), 'WPCTX_VERSION should be defined' );
		self::assertTrue( defined( 'WPCTX_FILE' ), 'WPCTX_FILE should be defined' );
		self::assertTrue( defined( 'WPCTX_DIR' ), 'WPCTX_DIR should be defined' );
		self::assertTrue( defined( 'WPCTX_REQUIRES_PHP' ), 'WPCTX_REQUIRES_PHP should be defined' );
		self::assertTrue( defined( 'WPCTX_REQUIRES_WP' ), 'WPCTX_REQUIRES_WP should be defined' );
	}

	public function test_plugin_classes_autoload(): void {
		self::assertTrue( class_exists( '\WPContext\Main' ) );
		self::assertTrue( class_exists( '\WPContext\Requirements' ) );
		self::assertTrue( class_exists( '\WPContext\Ai\Client_Wrapper' ) );
		self::assertTrue( class_exists( '\WPContext\Ai\Result' ) );
		self::assertTrue( interface_exists( '\WPContext\Ai\Provider' ) );
	}

	public function test_global_helper_is_available(): void {
		self::assertTrue( function_exists( 'agentready_has_ai_client' ) );
	}

	public function test_main_singleton_returns_consistent_instance(): void {
		$first  = \WPContext\Main::get_instance();
		$second = \WPContext\Main::get_instance();
		self::assertSame( $first, $second );
	}
}
