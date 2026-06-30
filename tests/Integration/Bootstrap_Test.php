<?php
/**
 * Integration smoke test — verifies Mokhai loads correctly inside the
 * WP test instance booted by wp-phpunit / wp-env.
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration;

use WP_UnitTestCase;

final class Bootstrap_Test extends WP_UnitTestCase {

	public function test_plugin_constants_are_defined(): void {
		self::assertTrue( defined( 'MOKHAI_VERSION' ), 'MOKHAI_VERSION should be defined' );
		self::assertTrue( defined( 'MOKHAI_FILE' ), 'MOKHAI_FILE should be defined' );
		self::assertTrue( defined( 'MOKHAI_DIR' ), 'MOKHAI_DIR should be defined' );
		self::assertTrue( defined( 'MOKHAI_REQUIRES_PHP' ), 'MOKHAI_REQUIRES_PHP should be defined' );
		self::assertTrue( defined( 'MOKHAI_REQUIRES_WP' ), 'MOKHAI_REQUIRES_WP should be defined' );
	}

	public function test_plugin_classes_autoload(): void {
		self::assertTrue( class_exists( '\Mokhai\Main' ) );
		self::assertTrue( class_exists( '\Mokhai\Requirements' ) );
		self::assertTrue( class_exists( '\Mokhai\Ai\Client_Wrapper' ) );
		self::assertTrue( class_exists( '\Mokhai\Ai\Result' ) );
		self::assertTrue( interface_exists( '\Mokhai\Ai\Provider' ) );
	}

	public function test_global_helper_is_available(): void {
		self::assertTrue( function_exists( 'agentready_has_ai_client' ) );
	}

	public function test_main_singleton_returns_consistent_instance(): void {
		$first  = \Mokhai\Main::get_instance();
		$second = \Mokhai\Main::get_instance();
		self::assertSame( $first, $second );
	}
}
