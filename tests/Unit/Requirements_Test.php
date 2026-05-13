<?php
/**
 * Unit tests for WPContext\Requirements.
 *
 * Focuses on meets_wp_floor()'s pre-release-suffix handling — the bug
 * the WP 7.0-branch CI cell surfaced.
 *
 * @package WPContext\Tests
 */

declare(strict_types=1);

namespace WPContext\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPContext\Requirements;

if ( ! defined( 'WPCTX_REQUIRES_WP' ) ) {
	define( 'WPCTX_REQUIRES_WP', '7.0' );
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
			'released 7.0'                  => array( '7.0', true ),
			'released 7.0.1'                => array( '7.0.1', true ),
			'released 7.1'                  => array( '7.1', true ),
			'released 8.0'                  => array( '8.0', true ),
			'pre-release 7.0 alpha'         => array( '7.0-alpha-12345', true ),
			'pre-release 7.0 RC1'           => array( '7.0-RC1', true ),
			'pre-release 7.0 beta'          => array( '7.0-beta1', true ),
			'pre-release 7.0 with plus'     => array( '7.0+12345', true ),
			'released 6.9 (below floor)'    => array( '6.9', false ),
			'released 6.9.4 (below floor)'  => array( '6.9.4', false ),
			'released 6.0 (below floor)'    => array( '6.0', false ),
			'pre-release 6.9.5 alpha'       => array( '6.9.5-alpha', false ),
		);
	}
}
