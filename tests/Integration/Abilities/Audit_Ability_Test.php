<?php
/**
 * Integration tests for the `ai-readiness-kit/audit-run` ability
 * (#21 / AgDR-0044).
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Abilities;

use WP_UnitTestCase;
use Mokhai\Abilities\Audit_Ability;
use Mokhai\Context_Score\Service;
use Mokhai\Markdown_Views\Schema as Markdown_Views_Schema;

final class Audit_Ability_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! \function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version.' );
		}

		// Signal_Collector reads the Markdown Views cache table.
		Markdown_Views_Schema::create();
		Service::invalidate();
		\wp_get_abilities();
	}

	protected function tearDown(): void {
		Service::invalidate();
		\wp_clear_scheduled_hook( Service::RECOMPUTE_ACTION );
		\wp_clear_scheduled_hook( Service::DAILY_RECOMPUTE_ACTION );
		\wp_set_current_user( 0 );
		Markdown_Views_Schema::drop();

		parent::tearDown();
	}

	private function execute( array $input = array() ) {
		return \wp_get_ability( Audit_Ability::ID )->execute( $input );
	}

	public function test_admin_gets_breakdown(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $this->execute();

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'overall', $result );
		self::assertArrayHasKey( 'sub_scores', $result );
		self::assertIsInt( $result['overall'] );
		self::assertGreaterThanOrEqual( 0, $result['overall'] );
		self::assertLessThanOrEqual( 100, $result['overall'] );
	}

	public function test_subscriber_is_denied(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->execute();

		self::assertWPError( $result );
		self::assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_extra_input_is_rejected(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $this->execute( array( 'surprise' => true ) );

		self::assertWPError( $result );
		self::assertSame( 'ability_invalid_input', $result->get_error_code() );
	}
}
