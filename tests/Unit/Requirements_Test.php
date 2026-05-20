<?php
/**
 * Unit tests for WPContext\Requirements.
 *
 * Focuses on meets_wp_floor()'s pre-release-suffix handling — pre-GA builds
 * of a floor-matching WP version must count as meeting the floor (otherwise
 * CI cells running the dev branch fail every integration test).
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPContext\Requirements;

if ( ! defined( 'WPCTX_REQUIRES_WP' ) ) {
	define( 'WPCTX_REQUIRES_WP', '6.9' );
}

final class Requirements_Test extends TestCase {

	/**
	 * @dataProvider wp_versions
	 */
	public function test_meets_wp_floor( string $version, bool $expected ): void {
		self::assertSame( $expected, Requirements::meets_wp_floor( $version ) );
	}

	public function wp_versions(): array {
		return array(
			'released 6.9 (at floor)'       => array( '6.9', true ),
			'released 6.9.4'                => array( '6.9.4', true ),
			'released 7.0'                  => array( '7.0', true ),
			'released 8.0'                  => array( '8.0', true ),
			'pre-release 6.9 alpha'         => array( '6.9-alpha-12345', true ),
			'pre-release 6.9 RC1'           => array( '6.9-RC1', true ),
			'pre-release 6.9 beta'          => array( '6.9-beta1', true ),
			'pre-release 6.9 with plus'     => array( '6.9+12345', true ),
			'released 6.8 (below floor)'    => array( '6.8', false ),
			'released 6.8.3 (below floor)'  => array( '6.8.3', false ),
			'released 6.0 (below floor)'    => array( '6.0', false ),
			'released 5.9 (below floor)'    => array( '5.9', false ),
			'pre-release 6.8.9 alpha'       => array( '6.8.9-alpha', false ),
		);
	}
}
