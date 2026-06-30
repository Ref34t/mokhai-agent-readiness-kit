<?php
/**
 * Integration tests for the `ai-readiness-kit/llms-txt-regenerate` ability
 * (#21 / AgDR-0044).
 *
 * @package Mokhai\Tests
 */

declare(strict_types=1);

namespace Mokhai\Tests\Integration\Abilities;

use WP_UnitTestCase;
use Mokhai\Abilities\Llms_Txt_Ability;
use Mokhai\LlmsTxt\Service;

final class Llms_Txt_Ability_Test extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! \function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version.' );
		}

		\wp_get_abilities();
	}

	protected function tearDown(): void {
		\wp_clear_scheduled_hook( Service::REGEN_ACTION );
		\wp_clear_scheduled_hook( Service::DAILY_REGEN_ACTION );
		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	private function execute( array $input = array() ) {
		return \wp_get_ability( Llms_Txt_Ability::ID )->execute( $input );
	}

	public function test_admin_regenerates_and_returns_body(): void {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $this->execute();

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'content', $result );
		self::assertArrayHasKey( 'bytes', $result );
		self::assertIsString( $result['content'] );
		self::assertSame( \strlen( $result['content'] ), $result['bytes'] );
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
